<?php

namespace Tests\Feature;

use App\Jobs\RunNovelCommand;
use App\Mail\RequestAvailable;
use App\Mail\RequestCreated;
use App\Mail\RequestDeclined;
use App\Novel;
use App\NovelChapter;
use App\NovelRequest;
use App\RequestNotification;
use App\Scraping\Fetcher;
use App\Services\Discover;
use App\Services\NovelCheck;
use App\Services\Requests\RequestFeed;
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
 * Reader requests end to end (sign-in on unless said otherwise): making one
 * from a search record or as free text, the refusals, cancelling, the
 * admin's approve / decline / rerank, the notifications (webhook, mail,
 * the phone feed) and the availability command. Fake sources, no network.
 */
class ReaderRequestsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private User $owner;

    private User $reader;

    private string $ownerToken;

    private string $readerToken;

    /** @var object{messages: string[]} */
    private object $webhook;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FakeRequestSource::register();
        $this->app->instance(Fetcher::class, new FakeFetcher());
        // RunNovelCommand only: searches (after the response) and ranking (the
        // `commands` queue, sync in tests) still run within the request.
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
        $this->reader = $this->makeReader();
        $this->reader->forceFill(['email' => 'sam@example.test'])->save();
        $this->ownerToken = $this->tokenFor($this->owner);
        $this->readerToken = $this->tokenFor($this->reader);

        FakeRequestSource::$search[FakeMangaA::class] = [
            ['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo', 'cover' => 'https://img.example/solo.jpg'],
        ];
        FakeRequestSource::$search[FakeMangaB::class] = [
            ['name' => 'Solo Leveling', 'url' => 'https://manga-b.test/solo', 'chapters' => 200],
        ];
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => ['1', '2', '3', '5'], 'updated_at' => now()->subDays(3)->toIso8601String()];
        FakeRequestSource::$stats['https://manga-b.test/solo'] = ['numbers' => array_map('strval', range(1, 200)), 'updated_at' => now()->subDays(40)->toIso8601String()];
    }

    protected function tearDown(): void
    {
        FakeRequestSource::reset();
        parent::tearDown();
    }

    /** A finished manga search for "solo" by the reader: [search id, key of Solo Leveling]. */
    private function search(?string $token = null): array
    {
        $token ??= $this->readerToken;
        $id = $this->asToken($token)->postJson('/api/v1/requests/searches', ['q' => 'solo', 'medium' => 'manga'])->assertStatus(202)->json('search.id');
        $search = $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->assertOk()->json('search');
        $this->assertSame('done', $search['status']);

        return [$id, $search['results'][0]['key']];
    }

    private function requestSolo(?string $token = null, array $extra = [])
    {
        $token ??= $this->readerToken;
        [$id, $key] = $this->search($token);

        return $this->asToken($token)->postJson('/api/v1/requests', ['search_id' => $id, 'key' => $key] + $extra);
    }

    private function freeText(string $title, ?string $token = null, string $medium = 'novel')
    {
        return $this->asToken($token ?? $this->readerToken)->postJson('/api/v1/requests', ['title' => $title, 'medium' => $medium]);
    }

    // ---- Making a request ------------------------------------------------------

    public function test_a_request_from_a_search_copies_the_servers_candidates_and_ignores_client_urls(): void
    {
        $response = $this->requestSolo(null, [
            'note' => 'Please!',
            'title' => 'Hacked',
            'url' => 'https://evil.example/x',
            'candidates' => [['source' => 'fakemanga_a', 'url' => 'https://evil.example/y']],
            'medium' => 'novel',
        ])->assertStatus(201);

        $request = NovelRequest::findOrFail($response->json('request.id'));
        $this->assertSame('Solo Leveling', $request->title);
        $this->assertSame('manga', $request->medium);
        $this->assertSame('Please!', $request->note);
        $this->assertNull($request->link);
        $this->assertSame('solo leveling', $request->normalized_title);
        $this->assertSame(['https://manga-b.test/solo', 'https://manga-a.test/solo'], array_column($request->candidates, 'url'));
        $this->assertStringNotContainsString('evil.example', json_encode($request->getAttributes()));

        // The reader's view: just the source names (ranked: B has 200 English chapters).
        $this->assertSame(['Fake B (manga)', 'Fake A (manga)'], $response->json('request.candidates'));
        $this->assertSame('pending', $response->json('request.status'));
        $this->assertFalse($response->json('request.can_decide'));
        $this->assertTrue($response->json('request.can_cancel'));
        // Every field an old client reads, in the same order; the "Me too" fields after them.
        $this->assertSame(['id', 'title', 'author', 'cover_url', 'description', 'medium', 'note', 'link', 'status', 'decline_reason',
            'requested_by', 'novel_id', 'created_at', 'decided_at', 'available_at', 'candidates', 'candidates_ranked_at', 'can_decide', 'can_cancel',
            'also_wanted_by', 'mine', 'followers', 'review_url', 'wanted'],
            array_keys($response->json('request')));
        $this->assertFalse($response->json('joined'));
        $this->assertSame(0, $response->json('request.also_wanted_by'));
        $this->assertSame('requester', $response->json('request.mine'));
        $this->assertNull($response->json('request.followers'), 'names of other readers are for the admin view only');

        // The ranking job ran after the response: evaluated sequentially, best first.
        $request->refresh();
        $this->assertNotNull($request->candidates_ranked_at);
        $this->assertSame(200, $request->candidates[0]['chapters_available']);
        $this->assertSame(0, $request->candidates[0]['gaps']);
        $this->assertSame('200 English chapters, no gaps, updated 40 days ago', $request->candidates[0]['reason']);
        $this->assertSame('4 English chapters, 1 gap, updated 3 days ago', $request->candidates[1]['reason']);
        $this->assertSame([], array_values(array_filter(FakeRequestSource::$calls, fn($c) => $c['kind'] === 'stats' && str_contains($c['arg'], 'evil'))));

        // The admin view has the shortlist.
        $admin = $this->asToken($this->ownerToken)->getJson("/api/v1/requests/{$request->id}")->assertOk()->json('request');
        $this->assertSame(['index', 'source', 'source_name', 'url', 'title', 'chapters', 'status', 'languages', 'chapters_available',
            'highest_chapter', 'gaps', 'updated_at', 'error', 'reason'], array_keys($admin['candidates'][0]));
        $this->assertSame('200', $admin['candidates'][0]['highest_chapter']);
        $this->assertSame(['id' => $this->reader->id, 'name' => 'Sam'], $admin['requested_by']);
        $this->assertTrue($admin['can_decide']);
    }

    public function test_an_expired_or_unknown_search_is_refused(): void
    {
        [$id, $key] = $this->search();

        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['search_id' => $id, 'key' => 'nope'])
            ->assertStatus(422)->assertJsonPath('code', 'unknown_result');
        // Someone else's search reads as gone.
        $this->asToken($this->tokenFor($this->makeReader('kim', 'Kim')))->postJson('/api/v1/requests', ['search_id' => $id, 'key' => $key])
            ->assertStatus(410)->assertJsonPath('code', 'search_expired');

        $this->travel(31)->minutes();
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['search_id' => $id, 'key' => $key])
            ->assertStatus(410)->assertExactJson(['message' => 'This search has expired. Search again.', 'code' => 'search_expired']);
        $this->assertSame(0, NovelRequest::count());
    }

    public function test_a_work_already_in_the_library_is_refused(): void
    {
        [$id, $key] = $this->search();
        $novel = Novel::create(['name' => 'Solo Leveling (Official)', 'group_id' => 1, 'translator_url' => 'https://other.example/solo', 'medium' => Novel::MEDIUM_MANGA]);

        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['search_id' => $id, 'key' => $key])
            ->assertStatus(409)->assertJsonPath('code', 'in_library')->assertJsonPath('novel_id', $novel->id);
        $this->freeText('Solo Leveling', null, 'manga')->assertStatus(409)->assertJsonPath('novel_id', $novel->id);
        // The other medium is another work.
        $this->freeText('Solo Leveling', null, 'novel')->assertStatus(201);
    }

    public function test_a_work_already_requested_is_refused_for_the_requester_and_joined_by_someone_else(): void
    {
        $mine = $this->requestSolo()->assertStatus(201)->json('request.id');

        $again = $this->requestSolo()->assertStatus(409);
        $again->assertJsonPath('code', 'already_requested')->assertJsonPath('by_me', true)->assertJsonPath('request_id', $mine)->assertJsonPath('status', 'pending');

        // The search marks it for someone else (an old client shows "Requested").
        $kim = $this->tokenFor($this->makeReader('kim', 'Kim'));
        [$id] = $this->search($kim);
        $this->asToken($kim)->getJson("/api/v1/requests/searches/{$id}")
            ->assertJsonPath('search.results.0.requested', ['status' => 'pending', 'by_me' => false]);

        // Asking anyway joins it ("Me too"; RequestFollowersTest has the rest).
        $theirs = $this->freeText('Solo Leveling!', $kim, 'manga')->assertOk();
        $theirs->assertJsonPath('joined', true)->assertJsonPath('request.id', $mine)->assertJsonPath('request.mine', 'follower');
        $this->assertSame(1, NovelRequest::count());

        // A declined request does not block a new one.
        NovelRequest::whereKey($mine)->update(['status' => 'declined']);
        $this->travel(61)->seconds();
        $this->freeText('Solo Leveling', $kim, 'manga')->assertStatus(201)->assertJsonPath('joined', false);
    }

    public function test_a_free_text_request_keeps_plain_text_and_never_fetches_the_link(): void
    {
        $response = $this->asToken($this->readerToken)->postJson('/api/v1/requests', [
            'title' => '  The Obscure One ', 'medium' => 'novel', 'link' => 'https://somewhere.example/obscure', 'note' => 'Translated by X',
        ])->assertStatus(201);

        $response->assertJsonPath('request.title', 'The Obscure One')
            ->assertJsonPath('request.link', 'https://somewhere.example/obscure')
            ->assertJsonPath('request.note', 'Translated by X')
            ->assertJsonPath('request.candidates', [])
            ->assertJsonPath('request.cover_url', null);
        $this->assertSame([], FakeRequestSource::$calls);
        $this->assertSame([], $this->app->make(Fetcher::class)->calls);

        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['title' => 'X', 'medium' => 'novel', 'link' => 'javascript:alert(1)'])
            ->assertStatus(422)->assertJsonValidationErrors('link');
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['title' => 'X', 'medium' => 'novel', 'note' => str_repeat('n', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['title' => 'X', 'medium' => 'comic'])
            ->assertStatus(422)->assertJsonValidationErrors('medium');
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['title' => '!!!', 'medium' => 'novel'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_title');
    }

    public function test_a_reader_may_have_five_pending_requests_and_the_admin_is_not_limited(): void
    {
        foreach (range(1, 5) as $n) {
            $this->freeText("Series {$n}")->assertStatus(201);
        }
        $this->freeText('Series 6')->assertStatus(429)->assertJsonPath('code', 'pending_limit')->assertJsonPath('limit', 5);

        // A decided request frees a place (a minute on: making requests is throttled per reader).
        NovelRequest::where('title', 'Series 1')->update(['status' => 'declined']);
        $this->travel(61)->seconds();
        $this->freeText('Series 6')->assertStatus(201);

        foreach (range(1, 7) as $n) {
            $this->freeText("Owner series {$n}", $this->ownerToken)->assertStatus(201);
        }
    }

    // ---- Listing and cancelling -------------------------------------------------

    public function test_readers_list_their_own_requests_and_the_admin_lists_all(): void
    {
        $this->freeText('Mine One')->assertStatus(201);
        $this->travel(1)->seconds();
        $this->freeText('Mine Two')->assertStatus(201);
        $kim = $this->tokenFor($this->makeReader('kim', 'Kim'));
        $this->freeText('Theirs', $kim)->assertStatus(201);

        $mine = $this->asToken($this->readerToken)->getJson('/api/v1/requests')->assertOk();
        $this->assertSame(['Mine Two', 'Mine One'], array_column($mine->json('requests'), 'title'));
        $mine->assertJsonPath('can_decide', false)->assertJsonPath('pending_limit', 5)->assertJsonPath('has_more', false);
        $this->asToken($this->readerToken)->getJson('/api/v1/requests?scope=all')->assertStatus(403);

        $all = $this->asToken($this->ownerToken)->getJson('/api/v1/requests?scope=all')->assertOk();
        $this->assertSame(['Theirs', 'Mine Two', 'Mine One'], array_column($all->json('requests'), 'title'));
        $this->assertSame('Kim', $all->json('requests.0.requested_by.name'));
        $all->assertJsonPath('can_decide', true)->assertJsonPath('pending_limit', null);
        $this->assertSame([], $this->asToken($this->ownerToken)->getJson('/api/v1/requests')->json('requests'));

        NovelRequest::where('title', 'Theirs')->update(['status' => 'approved', 'available_at' => now()]);
        $this->assertSame(['Theirs'], array_column($this->asToken($this->ownerToken)->getJson('/api/v1/requests?scope=all&status=available')->json('requests'), 'title'));
        $this->assertSame([], $this->asToken($this->ownerToken)->getJson('/api/v1/requests?scope=all&status=approved')->json('requests'));
        $this->assertSame(['Mine Two', 'Mine One'], array_column($this->asToken($this->ownerToken)->getJson('/api/v1/requests?scope=all&status=pending')->json('requests'), 'title'));

        // Someone else's request is not found for a reader.
        $theirs = NovelRequest::where('title', 'Theirs')->value('id');
        $this->asToken($this->readerToken)->getJson("/api/v1/requests/{$theirs}")->assertNotFound();
    }

    public function test_cancel_rules(): void
    {
        $mine = $this->freeText('Mine')->json('request.id');
        $kim = $this->tokenFor($this->makeReader('kim', 'Kim'));
        $theirs = $this->freeText('Theirs', $kim)->json('request.id');

        $this->asToken($this->readerToken)->deleteJson("/api/v1/requests/{$theirs}")->assertNotFound();
        $this->asToken($this->readerToken)->deleteJson("/api/v1/requests/{$mine}")->assertNoContent();
        $this->assertNull(NovelRequest::find($mine));
        $this->assertSame(0, RequestNotification::where('novel_request_id', $mine)->count());

        NovelRequest::whereKey($theirs)->update(['status' => 'approved']);
        $this->asToken($kim)->deleteJson("/api/v1/requests/{$theirs}")->assertStatus(409)->assertJsonPath('code', 'not_pending');
        $this->asToken($this->ownerToken)->deleteJson("/api/v1/requests/{$theirs}")->assertNoContent();
        $this->assertNull(NovelRequest::find($theirs));
    }

    // ---- Approving -------------------------------------------------------------

    public function test_approving_adds_the_best_candidate_through_the_shared_add_path_and_starts_downloading(): void
    {
        $id = $this->requestSolo()->json('request.id');

        $response = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")->assertOk();
        $response->assertJsonPath('created', true)->assertJsonPath('request.status', 'approved');

        $novel = Novel::findOrFail($response->json('novel_id'));
        $this->assertSame('https://manga-b.test/solo', $novel->translator_url); // ranked first
        $this->assertSame('Solo Leveling', $novel->name);
        $this->assertSame(Novel::MEDIUM_MANGA, $novel->medium);
        $this->assertSame(1, (int) $novel->group_id);

        $request = NovelRequest::findOrFail($id);
        $this->assertSame($novel->id, $request->novel_id);
        $this->assertSame($this->owner->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);

        // Metadata queued (AddSeries) and the chapter check started (NovelCheck).
        Bus::assertDispatched(RunNovelCommand::class, fn(RunNovelCommand $job) => $job->artisanCommand === 'novel:metadata');
        $this->assertSame('running', Cache::get(NovelCheck::key($novel->id))['status'] ?? null);

        // The requester hears it in the feed; no email for "approved".
        $this->assertSame(1, RequestNotification::where('user_id', $this->reader->id)->where('kind', 'request_approved')->count());
        Mail::assertNotSent(RequestDeclined::class);
        Mail::assertNotSent(RequestAvailable::class);

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'not_pending');
    }

    public function test_approving_a_chosen_candidate(): void
    {
        $id = $this->requestSolo()->json('request.id');

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve", ['candidate' => 1])->assertOk();
        $this->assertSame('https://manga-a.test/solo', NovelRequest::find($id)->novel->translator_url);

        $other = $this->freeText('Another', null, 'manga')->json('request.id');
        NovelRequest::whereKey($other)->update(['candidates' => json_encode([['source' => 'fakemanga_a', 'url' => 'https://manga-a.test/another', 'title' => 'Another']])]);
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$other}/approve", ['candidate' => 3])
            ->assertStatus(422)->assertJsonPath('code', 'unknown_candidate');
    }

    public function test_a_duplicate_title_needs_force(): void
    {
        $id = $this->requestSolo()->json('request.id');
        $existing = Novel::create(['name' => 'Solo Leveling', 'group_id' => 1, 'translator_url' => 'https://manga-c.test/solo', 'medium' => Novel::MEDIUM_MANGA]);

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'duplicate')->assertJsonPath('duplicate.id', $existing->id);
        $this->assertTrue(NovelRequest::find($id)->isPending());

        $forced = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve", ['force' => true])->assertOk();
        $this->assertNotSame($existing->id, $forced->json('novel_id'));
        $this->assertSame(2, Novel::where('name', 'Solo Leveling')->count());
    }

    public function test_the_same_source_page_already_in_the_library_is_linked_not_added_again(): void
    {
        $id = $this->requestSolo()->json('request.id');
        $existing = Novel::create(['name' => 'Solo Leveling Renamed', 'group_id' => 1, 'translator_url' => 'https://manga-b.test/solo', 'medium' => Novel::MEDIUM_MANGA]);

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('novel_id', $existing->id);
    }

    public function test_a_free_text_request_is_approved_with_a_url_or_linked_to_a_series(): void
    {
        $id = $this->freeText('Obscure Manga', null, 'manga')->json('request.id');

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'needs_source');
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve", ['url' => 'not a url'])
            ->assertStatus(422)->assertJsonValidationErrors('url');

        $approved = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve", ['url' => 'https://manga-a.test/obscure'])->assertOk();
        $novel = Novel::findOrFail($approved->json('novel_id'));
        $this->assertSame('Obscure Manga', $novel->name);
        $this->assertSame(Novel::MEDIUM_MANGA, $novel->medium);

        $second = $this->freeText('Added By Hand')->json('request.id');
        $byHand = Novel::create(['name' => 'Added by hand (WN)', 'group_id' => 1, 'translator_url' => 'https://novel-a.test/hand']);
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$second}/approve", ['novel_id' => 999999])
            ->assertStatus(422)->assertJsonPath('code', 'unknown_novel');
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$second}/approve", ['novel_id' => $byHand->id])
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('request.novel_id', $byHand->id);
    }

    public function test_readers_cannot_decide(): void
    {
        $id = $this->freeText('Mine')->json('request.id');

        foreach (['approve', 'decline', 'rerank'] as $action) {
            $this->asToken($this->readerToken)->postJson("/api/v1/requests/{$id}/{$action}")
                ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        }
        $this->assertTrue(NovelRequest::find($id)->isPending());
    }

    // ---- Declining and reranking ---------------------------------------------------

    public function test_declining_tells_the_requester_by_feed_and_email(): void
    {
        $id = $this->freeText('Unwanted')->json('request.id');

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/decline", ['reason' => 'Not licensed in English.'])
            ->assertOk()->assertJsonPath('request.status', 'declined')->assertJsonPath('request.decline_reason', 'Not licensed in English.');

        Mail::assertSent(RequestDeclined::class, fn(RequestDeclined $m) => $m->hasTo('sam@example.test') && $m->quote === 'Not licensed in English.');
        $this->assertSame(1, RequestNotification::where('user_id', $this->reader->id)->where('kind', 'request_declined')->count());
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/decline")->assertStatus(409);
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/decline", ['reason' => str_repeat('r', 501)])->assertStatus(422);

        // Rendered, the mail carries the title and the reason.
        $html = (new RequestDeclined('Sam', 'Unwanted', 'novel', null, 'Not licensed in English.', 'https://n.test/requests'))->render();
        $this->assertStringContainsString('Unwanted', $html);
        $this->assertStringContainsString('Not licensed in English.', $html);
        $created = new RequestCreated('Sam', 'Wanted', 'manga', 'https://img.example/c.jpg', 'Please', 'https://n.test/requests');
        $this->assertStringContainsString('https://img.example/c.jpg', $created->render());
        $this->assertSame('Sam requested Wanted', $created->build()->subject);
        $available = new RequestAvailable('Sam', 'Wanted', 'manga', null, 'https://n.test/novels/3');
        $this->assertStringContainsString('https://n.test/novels/3', $available->render());
        $this->assertStringContainsString('Start reading', $available->render());
    }

    public function test_rerank_rebuilds_a_manga_shortlist(): void
    {
        $id = $this->requestSolo()->json('request.id');
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => array_map('strval', range(1, 300)), 'updated_at' => now()->toIso8601String()];

        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/rerank")->assertStatus(202);

        $this->assertSame('https://manga-a.test/solo', NovelRequest::find($id)->candidates[0]['url']);
        $novel = $this->freeText('Text one')->json('request.id');
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$novel}/rerank")->assertStatus(422)->assertJsonPath('code', 'nothing_to_rank');
    }

    public function test_a_source_a_chapter_behind_but_fresher_is_ranked_first_and_says_why(): void
    {
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => array_map('strval', range(1, 199)), 'updated_at' => now()->subDay()->subHour()->toIso8601String()];

        $id = $this->requestSolo()->json('request.id');

        $candidates = NovelRequest::find($id)->candidates;
        $this->assertSame(['https://manga-a.test/solo', 'https://manga-b.test/solo'], array_column($candidates, 'url'));
        $this->assertSame('199 English chapters, no gaps, updated yesterday — 1 fewer than Fake B (manga) but more recent', $candidates[0]['reason']);
        $this->assertSame('200 English chapters, no gaps, updated 40 days ago — 1 more than Fake A (manga), but updated less recently', $candidates[1]['reason']);
        $this->assertArrayNotHasKey('top_tier', $candidates[0]);

        // A narrower tier (config): chapters decide again.
        config(['novarr.requests.rank_tie_chapters' => 0, 'novarr.requests.rank_tie_percent' => 0]);
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/rerank")->assertStatus(202);
        $this->assertSame('https://manga-b.test/solo', NovelRequest::find($id)->candidates[0]['url']);
    }

    public function test_a_candidate_whose_source_fails_ranks_last_with_an_error(): void
    {
        FakeRequestSource::$stats['https://manga-b.test/solo'] = null;
        $id = $this->requestSolo()->json('request.id');

        $candidates = NovelRequest::find($id)->candidates;
        $this->assertSame('https://manga-a.test/solo', $candidates[0]['url']);
        $this->assertSame('the source did not answer', $candidates[1]['error']);
        $this->assertSame('Could not be checked: the source did not answer.', $candidates[1]['reason']);
    }

    // ---- Notifications ---------------------------------------------------------

    public function test_a_new_request_tells_the_owner_by_webhook_email_and_feed(): void
    {
        $this->freeText('Wanted Series', null, 'manga')->assertStatus(201);

        $this->assertSame(['Sam requested Wanted Series (manga)'], $this->webhook->messages);
        Mail::assertSent(RequestCreated::class, fn(RequestCreated $m) => $m->hasTo('owner@example.test')
            && $m->buttonUrl === rtrim(config('app.url'), '/') . '/requests');
        $this->assertSame(1, RequestNotification::whereNull('user_id')->where('kind', 'request_new')->count());

        // The admin's own requests tell nobody.
        $this->freeText('Owner wants', $this->ownerToken)->assertStatus(201);
        $this->assertCount(1, $this->webhook->messages);
        Mail::assertSentCount(1);
    }

    public function test_no_email_when_mail_is_not_set_up_and_a_failing_notification_never_fails_the_request(): void
    {
        config(['mail.default' => 'log']);
        $this->freeText('Quiet')->assertStatus(201);
        Mail::assertNothingSent();

        config(['mail.default' => 'smtp']);
        $this->app->instance(RequestNotifier::class, new class extends RequestNotifier {
            protected function webhook(string $message): void
            {
                throw new \RuntimeException('webhook down');
            }
        });
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->freeText('Loud')->assertStatus(201);
        $this->assertSame(1, NovelRequest::where('title', 'Loud')->count());
    }

    public function test_the_notification_feed_per_role_and_its_cursor(): void
    {
        // An item from two days ago is not backlog for a first call.
        RequestNotification::create(['user_id' => $this->reader->id, 'kind' => 'request_declined', 'title' => 'Old', 'body' => 'Old one', 'created_at' => now()->subDays(2)]);

        $id = $this->freeText('Feed Series')->json('request.id');
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/decline", ['reason' => 'No'])->assertOk();

        $reader = $this->asToken($this->readerToken)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(['cursor', 'has_more', 'items'], array_keys($reader->json()));
        $this->assertSame(['request_declined'], array_column($reader->json('items'), 'kind'));
        $item = $reader->json('items.0');
        $this->assertSame(['id', 'kind', 'title', 'body', 'request_id', 'novel_id', 'at'], array_keys($item));
        $this->assertSame($id, $item['request_id']);
        $this->assertSame('Feed Series was declined: No', $item['body']);

        $admin = $this->asToken($this->ownerToken)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(['request_new'], array_column($admin->json('items'), 'kind'));
        $this->assertSame('Sam requested Feed Series (novel).', $admin->json('items.0.body'));

        // The cursor: nothing new → nothing, the same cursor back.
        $cursor = $reader->json('cursor');
        $again = $this->asToken($this->readerToken)->getJson('/api/v1/notifications?since=' . urlencode($cursor))->assertOk();
        $this->assertSame([], $again->json('items'));
        $this->assertSame($cursor, $again->json('cursor'));

        // Something new → only that.
        $second = $this->freeText('Feed Two')->json('request.id');
        $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$second}/approve", ['url' => 'https://novel-a.test/feed-two'])->assertOk();
        $newer = $this->asToken($this->readerToken)->getJson('/api/v1/notifications?since=' . urlencode($cursor))->assertOk();
        $this->assertSame(['request_approved'], array_column($newer->json('items'), 'kind'));
        $this->assertNotSame($cursor, $newer->json('cursor'));
        $this->assertSame([], $this->asToken($this->readerToken)->getJson('/api/v1/notifications?since=' . urlencode($newer->json('cursor')))->json('items'));

        // A cursor this server did not issue counts as none (the last 24 hours).
        $this->assertCount(2, $this->asToken($this->readerToken)->getJson('/api/v1/notifications?since=garbage')->json('items'));
        // A first call with nothing to show still gives a cursor past everything so far.
        $kim = $this->tokenFor($this->makeReader('kim', 'Kim'));
        $empty = $this->asToken($kim)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame([], $empty->json('items'));
        $this->assertSame(RequestFeed::encode((int) RequestNotification::max('id')), $empty->json('cursor'));
    }

    public function test_the_availability_command_tells_the_requester_once(): void
    {
        $id = $this->freeText('Ready Soon')->json('request.id');
        $approved = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve", ['url' => 'https://novel-a.test/ready'])->assertOk();
        $novelId = $approved->json('novel_id');

        // No downloaded chapter yet.
        $this->artisan('requests:availability')->assertSuccessful();
        $this->assertNull(NovelRequest::find($id)->available_at);

        $chapter = NovelChapter::create(['novel_id' => $novelId, 'chapter' => 1, 'book' => 0, 'label' => 'Chapter 1', 'url' => 'https://novel-a.test/ready/1']);
        $chapter->forceFill(['status' => 1, 'download_date' => now()])->save();

        $this->artisan('requests:availability')->assertSuccessful();
        $this->artisan('requests:availability')->assertSuccessful();

        $request = NovelRequest::find($id);
        $this->assertNotNull($request->available_at);
        $this->assertNotNull($request->requester_notified_at);
        $this->assertSame('available', $request->displayStatus());
        Mail::assertSent(RequestAvailable::class, 1);
        Mail::assertSent(RequestAvailable::class, fn(RequestAvailable $m) => $m->hasTo('sam@example.test') && str_ends_with($m->buttonUrl, "/novels/{$novelId}"));
        $this->assertSame(1, RequestNotification::where('user_id', $this->reader->id)->where('kind', 'request_available')->count());
        $this->asToken($this->readerToken)->getJson("/api/v1/requests/{$id}")->assertJsonPath('request.status', 'available');
    }

    public function test_the_availability_command_is_scheduled_every_ten_minutes(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn($e) => str_contains((string) $e->command, 'requests:availability'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
    }

    public function test_deleting_the_series_unlinks_the_request_and_deleting_the_reader_removes_theirs(): void
    {
        $id = $this->freeText('Linked')->json('request.id');
        $novelId = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve", ['url' => 'https://novel-a.test/linked'])->json('novel_id');

        Novel::find($novelId)->delete();
        $this->assertNull(NovelRequest::find($id)->novel_id);

        $this->reader->delete();
        $this->assertNull(NovelRequest::find($id));
        $this->assertSame(0, RequestNotification::where('user_id', $this->reader->id)->count());
    }

    public function test_the_feature_can_be_turned_off(): void
    {
        config(['novarr.requests.enabled' => false]);

        $this->asToken($this->readerToken)->getJson('/api/v1/requests')->assertNotFound()->assertJsonPath('code', 'requests_disabled');
        $this->asToken($this->readerToken)->postJson('/api/v1/requests/searches', ['q' => 'x', 'medium' => 'novel'])->assertNotFound();
        $this->getJson('/api/v1/meta')->assertJsonPath('requests', false);
    }

    public function test_with_sign_in_off_the_owner_requests_and_decides(): void
    {
        \App\Support\AuthMode::store(false);
        $this->asNobody();

        $id = $this->postJson('/api/v1/requests', ['title' => 'Owner Only', 'medium' => 'novel'])->assertStatus(201)->json('request.id');
        $this->getJson('/api/v1/requests')->assertOk()->assertJsonPath('requests.0.can_decide', true);
        $this->postJson("/api/v1/requests/{$id}/decline")->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('items', []);
        $this->assertSame([], $this->webhook->messages);
    }
}
