<?php

namespace Tests\Feature;

use App\Jobs\RunNovelCommand;
use App\Mail\RequestAvailable;
use App\Mail\RequestCreated;
use App\Mail\RequestDeclined;
use App\NovelChapter;
use App\NovelRequest;
use App\NovelRequestFollower;
use App\RequestNotification;
use App\Scraping\Fetcher;
use App\Services\Requests\RequestNotifier;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeFetcher;
use Tests\Support\FakeMangaA;
use Tests\Support\FakeMangaB;
use Tests\Support\FakeRequestSource;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * "Me too": a reader asking for a work someone else already asked for joins
 * that request (App\NovelRequestFollower) — the POST contract (200 joined,
 * 201 new, 409s), the request object's follower fields, leaving, handing a
 * cancelled request to the oldest follower, followers in the feed and the
 * mails, clean-up, and that old clients still get every field they had.
 */
class RequestFollowersTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private User $owner;

    private User $sam;

    private User $kim;

    private User $ana;

    private string $ownerToken;

    private string $samToken;

    private string $kimToken;

    private string $anaToken;

    /** @var object{messages: string[]} */
    private object $webhook;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FakeRequestSource::register();
        $this->app->instance(Fetcher::class, new FakeFetcher());
        Bus::fake([RunNovelCommand::class, \App\Jobs\FinishNovelCheck::class]);
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->webhook = new \stdClass();
        $this->webhook->messages = [];
        $hook = $this->webhook;
        $this->app->instance(RequestNotifier::class, new class($hook) extends RequestNotifier {
            public function __construct(private object $hook) {}

            protected function webhook(string $message): void
            {
                $this->hook->messages[] = $message;
            }
        });

        $this->owner = $this->enableSignIn();
        $this->owner->forceFill(['email' => 'owner@example.test'])->save();
        $this->sam = $this->makeReader();
        $this->sam->forceFill(['email' => 'sam@example.test'])->save();
        $this->kim = $this->makeReader('kim', 'Kim');
        $this->kim->forceFill(['email' => 'kim@example.test'])->save();
        $this->ana = $this->makeReader('ana', 'Ana');
        $this->ana->forceFill(['email' => 'ana@example.test'])->save();
        $this->ownerToken = $this->tokenFor($this->owner);
        $this->samToken = $this->tokenFor($this->sam);
        $this->kimToken = $this->tokenFor($this->kim);
        $this->anaToken = $this->tokenFor($this->ana);

        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo']];
        FakeRequestSource::$search[FakeMangaB::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-b.test/solo', 'chapters' => 200]];
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => ['1', '2'], 'updated_at' => now()->subDays(3)->toIso8601String()];
        FakeRequestSource::$stats['https://manga-b.test/solo'] = ['numbers' => array_map('strval', range(1, 200)), 'updated_at' => now()->subDays(40)->toIso8601String()];
    }

    protected function tearDown(): void
    {
        FakeRequestSource::reset();
        parent::tearDown();
    }

    /** $token's finished manga search for "solo": [search id, the Solo Leveling result]. */
    private function search(string $token): array
    {
        $id = $this->asToken($token)->postJson('/api/v1/requests/searches', ['q' => 'solo', 'medium' => 'manga'])->assertStatus(202)->json('search.id');
        $search = $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->assertOk()->json('search');

        return [$id, $search['results'][0]];
    }

    private function requestFromSearch(string $token, array $extra = [])
    {
        [$id, $result] = $this->search($token);

        return $this->asToken($token)->postJson('/api/v1/requests', ['search_id' => $id, 'key' => $result['key']] + $extra);
    }

    /** Sam asks for Solo Leveling (pending); its id. */
    private function samRequests(): int
    {
        return $this->requestFromSearch($this->samToken, ['note' => 'Sam wants it'])->assertStatus(201)->json('request.id');
    }

    // ---- Joining ----------------------------------------------------------------

    public function test_asking_for_a_work_someone_else_asked_for_joins_their_request(): void
    {
        $id = $this->samRequests();
        $this->webhook->messages = [];
        Mail::fake();
        $feedBefore = RequestNotification::count();

        // Kim's search marks it as someone else's; a new client offers "Me too".
        [, $result] = $this->search($this->kimToken);
        $this->assertSame(['status' => 'pending', 'by_me' => false], $result['requested']);

        $joined = $this->requestFromSearch($this->kimToken, ['note' => 'Kim too'])->assertOk();
        $joined->assertJsonPath('joined', true)
            ->assertJsonPath('request.id', $id)
            ->assertJsonPath('request.mine', 'follower')
            ->assertJsonPath('request.also_wanted_by', 1)
            ->assertJsonPath('request.can_cancel', true)
            ->assertJsonPath('request.followers', null)
            ->assertJsonPath('request.requested_by.id', $this->sam->id);
        $this->assertSame(['request', 'joined'], array_keys($joined->json()));

        // One request; the follower's note is ignored; nobody is told.
        $this->assertSame(1, NovelRequest::count());
        $this->assertSame('Sam wants it', NovelRequest::find($id)->note);
        $this->assertSame([$this->kim->id], NovelRequestFollower::where('novel_request_id', $id)->pluck('user_id')->map(fn($v) => (int) $v)->all());
        $this->assertSame([], $this->webhook->messages);
        Mail::assertNothingSent();
        $this->assertSame($feedBefore, RequestNotification::count());

        // Kim's search now reads "requested by you".
        [, $again] = $this->search($this->kimToken);
        $this->assertSame(['status' => 'pending', 'by_me' => true], $again['requested']);

        // A free-text ask for the same work (another spelling) joins too: Ana.
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo  Leveling!', 'medium' => 'manga', 'link' => 'https://x.example/solo', 'note' => 'Ana'])
            ->assertOk()->assertJsonPath('joined', true)->assertJsonPath('request.id', $id)->assertJsonPath('request.also_wanted_by', 2);
        $this->assertNull(NovelRequest::find($id)->link);
        // Another medium is another work.
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'novel'])
            ->assertStatus(201)->assertJsonPath('joined', false);
    }

    public function test_joining_is_idempotent_and_does_not_count_against_the_pending_cap(): void
    {
        $id = $this->samRequests();
        foreach (range(1, 5) as $n) {
            $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => "Kim series {$n}", 'medium' => 'novel'])->assertStatus(201);
        }
        $this->travel(61)->seconds(); // making requests is throttled per reader

        // At the cap of 5 pending, Kim can still join, twice.
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])
            ->assertOk()->assertJsonPath('joined', true)->assertJsonPath('request.id', $id);
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])
            ->assertOk()->assertJsonPath('joined', true)->assertJsonPath('request.also_wanted_by', 1);
        $this->assertSame(1, NovelRequestFollower::count());
        // A new request is still refused.
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Kim series 6', 'medium' => 'novel'])
            ->assertStatus(429)->assertJsonPath('code', 'pending_limit');
        // Kim's list says 5 of 5 used: the joined one is not Kim's own.
        $list = $this->asToken($this->kimToken)->getJson('/api/v1/requests')->assertOk()->json('requests');
        $this->assertCount(6, $list);
        $this->assertSame(1, collect($list)->where('mine', 'follower')->count());
    }

    public function test_the_requester_is_refused_and_an_available_work_is_in_the_library(): void
    {
        $id = $this->samRequests();
        $this->asToken($this->samToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])
            ->assertStatus(409)->assertJsonPath('code', 'already_requested')->assertJsonPath('by_me', true)
            ->assertJsonPath('request_id', $id)->assertJsonPath('status', 'pending');

        // Approved and not yet available: still joinable.
        $novelId = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")->assertOk()->json('novel_id');
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])
            ->assertOk()->assertJsonPath('joined', true)->assertJsonPath('request.status', 'approved');

        // Available: in the library, for everyone (the library match, or the request itself).
        NovelRequest::whereKey($id)->update(['available_at' => now()]);
        \App\Novel::whereKey($novelId)->update(['name' => 'Renamed After Approval', 'translator_url' => 'https://elsewhere.test/x']);
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])
            ->assertStatus(409)->assertJsonPath('code', 'in_library')->assertJsonPath('novel_id', $novelId);
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])
            ->assertStatus(409)->assertJsonPath('code', 'in_library');
        $this->assertSame(1, NovelRequestFollower::count());
    }

    // ---- Seeing, leaving, cancelling --------------------------------------------------

    public function test_followers_list_and_read_the_request_and_the_admin_sees_who(): void
    {
        $id = $this->samRequests();
        $this->travel(1)->seconds();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();
        $this->travel(1)->seconds();
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();

        $kims = $this->asToken($this->kimToken)->getJson('/api/v1/requests?scope=mine')->assertOk();
        $this->assertSame([$id], array_column($kims->json('requests'), 'id'));
        $kims->assertJsonPath('requests.0.mine', 'follower')->assertJsonPath('requests.0.also_wanted_by', 2)->assertJsonPath('requests.0.followers', null);
        $this->asToken($this->kimToken)->getJson('/api/v1/requests?status=pending')->assertJsonPath('requests.0.id', $id);
        $this->assertSame([], $this->asToken($this->kimToken)->getJson('/api/v1/requests?status=declined')->json('requests'));
        $this->asToken($this->kimToken)->getJson("/api/v1/requests/{$id}")->assertOk()->assertJsonPath('request.mine', 'follower');
        // Someone who neither asked nor joined still cannot read it.
        $this->asToken($this->tokenFor($this->makeReader('lee', 'Lee')))->getJson("/api/v1/requests/{$id}")->assertNotFound();

        $this->asToken($this->samToken)->getJson("/api/v1/requests/{$id}")
            ->assertJsonPath('request.mine', 'requester')->assertJsonPath('request.also_wanted_by', 2);

        // The admin view: names, oldest first.
        $admin = $this->asToken($this->ownerToken)->getJson('/api/v1/requests?scope=all&status=pending')->assertOk();
        $admin->assertJsonPath('requests.0.followers', [['id' => $this->kim->id, 'name' => 'Kim'], ['id' => $this->ana->id, 'name' => 'Ana']])
            ->assertJsonPath('requests.0.also_wanted_by', 2)
            ->assertJsonPath('requests.0.mine', null)
            ->assertJsonPath('requests.0.requested_by', ['id' => $this->sam->id, 'name' => 'Sam']);
    }

    public function test_a_follower_leaves_only_their_follow(): void
    {
        $id = $this->samRequests();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();

        $this->asToken($this->kimToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();

        $this->assertNotNull(NovelRequest::find($id));
        $this->assertSame([$this->ana->id], NovelRequestFollower::pluck('user_id')->map(fn($v) => (int) $v)->all());
        $this->assertSame([], $this->asToken($this->kimToken)->getJson('/api/v1/requests')->json('requests'));
        $this->asToken($this->kimToken)->deleteJson("/api/v1/requests/{$id}")->assertNotFound();

        // Approved: Ana may still leave; available: no longer.
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")->assertOk();
        $this->asToken($this->anaToken)->getJson("/api/v1/requests/{$id}")->assertJsonPath('request.can_cancel', true);
        $this->asToken($this->samToken)->getJson("/api/v1/requests/{$id}")->assertJsonPath('request.can_cancel', false);
        NovelRequest::whereKey($id)->update(['available_at' => now()]);
        $this->asToken($this->anaToken)->getJson("/api/v1/requests/{$id}")->assertJsonPath('request.can_cancel', false);
        $this->asToken($this->anaToken)->deleteJson("/api/v1/requests/{$id}")->assertStatus(409)->assertJsonPath('code', 'not_pending');
        NovelRequest::whereKey($id)->update(['available_at' => null]);
        $this->asToken($this->anaToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();
        $this->assertSame(0, NovelRequestFollower::count());
        $this->assertNotNull(NovelRequest::find($id));
    }

    public function test_the_requester_cancelling_hands_the_request_to_the_oldest_follower(): void
    {
        $id = $this->samRequests();
        $this->travel(1)->seconds();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();
        $this->travel(1)->seconds();
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();

        $this->asToken($this->samToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();

        $request = NovelRequest::find($id);
        $this->assertNotNull($request, 'others still want it');
        $this->assertSame($this->kim->id, (int) $request->user_id);
        $this->assertTrue($request->isPending());
        $this->assertSame([$this->ana->id], NovelRequestFollower::where('novel_request_id', $id)->pluck('user_id')->map(fn($v) => (int) $v)->all());
        $this->asToken($this->kimToken)->getJson("/api/v1/requests/{$id}")
            ->assertJsonPath('request.mine', 'requester')->assertJsonPath('request.also_wanted_by', 1)->assertJsonPath('request.requested_by.name', 'Kim');
        $this->asToken($this->samToken)->getJson("/api/v1/requests/{$id}")->assertNotFound();
        // Sam may ask again: joins Kim's request now.
        $this->travel(61)->seconds();
        $this->asToken($this->samToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk()->assertJsonPath('joined', true);

        // Cancelled by the last requester with nobody left: deleted.
        $this->asToken($this->kimToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();
        $this->asToken($this->anaToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();
        $this->assertSame($this->sam->id, (int) NovelRequest::find($id)->user_id);
        $this->asToken($this->samToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();
        $this->assertNull(NovelRequest::find($id));
        $this->assertSame(0, NovelRequestFollower::count());
        $this->assertSame(0, \App\Services\Requests\Requests::pendingCount());
    }

    public function test_the_admin_deleting_a_request_removes_its_followers(): void
    {
        $id = $this->samRequests();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();

        $this->asToken($this->ownerToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();

        $this->assertNull(NovelRequest::find($id));
        $this->assertSame(0, NovelRequestFollower::count());
    }

    // ---- Notifications -----------------------------------------------------------------

    public function test_followers_hear_every_decision_by_feed_and_mail(): void
    {
        $id = $this->samRequests();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();
        // The admin joining someone's request is not told of their own decision.
        $this->asToken($this->ownerToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk()->assertJsonPath('joined', true);
        Mail::assertSent(RequestCreated::class, 1); // Sam's request only

        $novelId = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")->assertOk()->json('novel_id');
        foreach ([$this->sam, $this->kim] as $user) {
            $this->assertSame(1, RequestNotification::where('user_id', $user->id)->where('kind', 'request_approved')->where('novel_request_id', $id)->count());
        }
        $this->assertSame(0, RequestNotification::where('user_id', $this->owner->id)->count());

        // Both readers' feeds carry it.
        $this->assertSame(['request_approved'], array_column($this->asToken($this->kimToken)->getJson('/api/v1/notifications')->json('items'), 'kind'));

        $chapter = NovelChapter::create(['novel_id' => $novelId, 'chapter' => 1, 'book' => 0, 'label' => 'Chapter 1', 'url' => 'https://manga-b.test/solo/1']);
        $chapter->forceFill(['status' => 1, 'download_date' => now()])->save();
        $this->artisan('requests:availability')->assertSuccessful();

        Mail::assertSent(RequestAvailable::class, 3);
        foreach (['sam@example.test', 'kim@example.test', 'owner@example.test'] as $to) {
            Mail::assertSent(RequestAvailable::class, fn(RequestAvailable $m) => $m->hasTo($to) && str_ends_with($m->buttonUrl, "/novels/{$novelId}"));
        }
        foreach ([$this->sam, $this->kim, $this->owner] as $user) {
            $this->assertSame(1, RequestNotification::where('user_id', $user->id)->where('kind', 'request_available')->count());
        }
        Mail::assertSent(RequestAvailable::class, fn(RequestAvailable $m) => $m->hasTo('kim@example.test') && str_contains($m->render(), 'Kim'));
    }

    public function test_followers_hear_a_decline_by_feed_and_mail(): void
    {
        $id = $this->samRequests();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();
        $this->ana->forceFill(['email' => null])->save(); // no address: the feed only

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/decline", ['reason' => 'Not licensed.'])->assertOk()
            ->assertJsonPath('request.followers', [['id' => $this->kim->id, 'name' => 'Kim'], ['id' => $this->ana->id, 'name' => 'Ana']]);

        Mail::assertSent(RequestDeclined::class, 2);
        Mail::assertSent(RequestDeclined::class, fn(RequestDeclined $m) => $m->hasTo('kim@example.test') && $m->quote === 'Not licensed.');
        Mail::assertSent(RequestDeclined::class, fn(RequestDeclined $m) => $m->hasTo('sam@example.test'));
        foreach ([$this->sam, $this->kim, $this->ana] as $user) {
            $item = $this->asToken($this->tokenFor($user))->getJson('/api/v1/notifications')->assertOk()->json('items.0');
            $this->assertSame('request_declined', $item['kind']);
            $this->assertSame($id, $item['request_id']);
            $this->assertSame('Solo Leveling was declined: Not licensed.', $item['body']);
        }
        // Declined: still in the followers' lists, no longer leavable.
        $this->asToken($this->kimToken)->getJson('/api/v1/requests')
            ->assertJsonPath('requests.0.status', 'declined')->assertJsonPath('requests.0.can_cancel', false);
    }

    // ---- Clean-up and old clients ---------------------------------------------------------

    public function test_deleting_a_follower_or_the_requester_cleans_up(): void
    {
        $id = $this->samRequests();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();
        $this->asToken($this->anaToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();

        $this->kim->delete();
        $this->assertSame([$this->ana->id], NovelRequestFollower::pluck('user_id')->map(fn($v) => (int) $v)->all());

        $this->sam->delete();
        $this->assertNull(NovelRequest::find($id));
        $this->assertSame(0, NovelRequestFollower::count());

        // A request deleted through the model takes its followers too.
        $other = NovelRequest::create(['user_id' => $this->ana->id, 'medium' => 'novel', 'title' => 'X', 'normalized_title' => 'x', 'status' => 'pending']);
        NovelRequestFollower::create(['novel_request_id' => $other->id, 'user_id' => $this->owner->id, 'created_at' => now()]);
        $other->delete();
        $this->assertSame(0, NovelRequestFollower::count());
    }

    public function test_old_clients_still_get_every_field_they_had(): void
    {
        $id = $this->samRequests();
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertOk();

        $old = ['id', 'title', 'author', 'cover_url', 'description', 'medium', 'note', 'link', 'status', 'decline_reason',
            'requested_by', 'novel_id', 'created_at', 'decided_at', 'available_at', 'candidates', 'candidates_ranked_at', 'can_decide', 'can_cancel'];
        foreach ([$this->samToken, $this->kimToken, $this->ownerToken] as $token) {
            $request = $this->asToken($token)->getJson("/api/v1/requests/{$id}")->assertOk()->json('request');
            $this->assertSame($old, array_slice(array_keys($request), 0, count($old)));
            $this->assertSame(['also_wanted_by', 'mine', 'followers', 'review_url'], array_slice(array_keys($request), count($old)));
        }
        $list = $this->asToken($this->kimToken)->getJson('/api/v1/requests')->assertOk()->json();
        $this->assertSame(['requests', 'page', 'has_more', 'can_decide', 'pending_limit'], array_keys($list));

        // The search result keeps its shape; requested keeps {status, by_me}.
        [, $result] = $this->search($this->anaToken);
        $this->assertSame(['key', 'title', 'author', 'cover_url', 'description', 'medium', 'sources', 'in_library', 'novel_id', 'requested'], array_keys($result));
        $this->assertSame(['status', 'by_me'], array_keys($result['requested']));
    }
}
