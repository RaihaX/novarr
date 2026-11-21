<?php

namespace Tests\Feature;

use App\Jobs\RankRequestCandidates;
use App\Jobs\RunNovelCommand;
use App\NovelRequest;
use App\Scraping\Fetcher;
use App\Services\Requests\Requests;
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
 * The reader-requests web page (GET /requests, RequestsPageController): the
 * frame the page JS fills from the API, its navigation entries (desktop nav,
 * the phone tab bar's More sheet, the command palette), the admin's pending
 * badge, and the ranking job's queue.
 */
class RequestsPageTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC_HOST = 'read.example.com';

    private User $owner;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        FakeRequestSource::register();
        $this->app->instance(Fetcher::class, new FakeFetcher());
        Bus::fake([RunNovelCommand::class, \App\Jobs\FinishNovelCheck::class]);
        $this->owner = $this->enableSignIn();
        $this->reader = $this->makeReader();
    }

    protected function tearDown(): void
    {
        FakeRequestSource::reset();
        parent::tearDown();
    }

    private function pending(User $user, string $title, string $medium = 'novel'): NovelRequest
    {
        return NovelRequest::create([
            'user_id' => $user->id, 'medium' => $medium, 'title' => $title,
            'normalized_title' => mb_strtolower($title), 'status' => NovelRequest::STATUS_PENDING, 'candidates' => [],
        ]);
    }

    public function test_the_page_renders_for_a_reader_without_admin_parts(): void
    {
        $this->pending($this->owner, 'Someone Else');

        $page = $this->asSignedIn($this->reader)->get('/requests');

        $page->assertOk()
            ->assertSee('data-requests-page', false)
            ->assertSee('Find something to request')
            ->assertSee('My requests')
            ->assertSee('id="reqFreeDialog"', false)
            // The review area stays hidden until the API answers can_decide.
            ->assertSee('id="reqReview" hidden', false)
            ->assertDontSee('id="reqAway"', false)
            // Nav entry for everyone, no pending badge for a reader.
            ->assertSee('id="navRequests"', false)
            ->assertDontSee('data-requests-badge', false);
        $this->assertStringContainsString('"title":"Requests"', $page->getContent());
    }

    public function test_the_page_renders_for_the_admin_with_the_pending_badge_everywhere(): void
    {
        $this->pending($this->reader, 'One');
        $this->pending($this->reader, 'Two');

        $page = $this->asSignedIn($this->owner)->get('/requests');

        $page->assertOk()->assertSee('data-requests-page', false)->assertDontSee('id="reqAway"', false);
        $html = $page->getContent();
        // Desktop nav badge, the More sheet's meta and the palette entry.
        $this->assertMatchesRegularExpression('/id="navRequests"[^>]*><span>Requests<\/span><span class="nav-count[^"]*" data-requests-badge>.*?2.*?pending/s', $html);
        $this->assertStringContainsString('2 pending</span>', $html);
        $this->assertStringContainsString('"title":"Requests"', $html);
        $this->assertStringContainsString('"meta":"2 pending"', $html);
        // The source counts for "Searching N sources…" (two fake manga sources).
        $this->assertStringContainsString('"manga":3', $html);
    }

    public function test_every_page_carries_the_nav_entry_and_the_badge_only_for_the_admin(): void
    {
        $this->pending($this->reader, 'Waiting');

        $admin = $this->asSignedIn($this->owner)->get('/');
        $admin->assertOk()->assertSee('id="navRequests"', false)->assertSee('data-requests-badge', false);

        $reader = $this->asSignedIn($this->reader)->get('/');
        $reader->assertOk()->assertSee('id="navRequests"', false)->assertDontSee('data-requests-badge', false);
        $this->assertStringContainsString(route('requests.index'), $reader->getContent());
    }

    public function test_with_the_feature_off_the_page_404s_and_no_entry_is_shown(): void
    {
        config(['novarr.requests.enabled' => false]);

        $this->asSignedIn($this->reader)->get('/requests')->assertNotFound();
        $this->asSignedIn($this->owner)->get('/requests')->assertNotFound();

        $home = $this->asSignedIn($this->owner)->get('/');
        $home->assertOk()->assertDontSee('id="navRequests"', false);
        $this->assertStringNotContainsString('"title":"Requests"', $home->getContent());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->asNobody()->get('/requests')->assertRedirect('/login');
    }

    public function test_on_the_public_address_a_reader_gets_the_page_and_the_admin_gets_the_explanation(): void
    {
        config(['novarr.public_hosts' => [self::PUBLIC_HOST], 'novarr.public_url' => null]);
        $this->pending($this->reader, 'Waiting');
        $public = 'http://' . self::PUBLIC_HOST . '/requests';
        $tunnel = ['REMOTE_ADDR' => '192.168.1.50'];

        $this->asSignedIn($this->reader, 'http://' . self::PUBLIC_HOST, fn($t) => $t->withServerVariables($tunnel)->withHeaders(['X-Forwarded-Proto' => 'https']))->get($public)
            ->assertOk()->assertSee('data-requests-page', false)->assertDontSee('id="reqAway"', false);

        $this->asSignedIn($this->owner, 'http://' . self::PUBLIC_HOST, fn($t) => $t->withServerVariables($tunnel)->withHeaders(['X-Forwarded-Proto' => 'https']))->get($public)
            ->assertOk()
            ->assertSee('id="reqAway"', false)
            ->assertSee('only available on your home network')
            // No admin badge where the admin cannot decide.
            ->assertDontSee('data-requests-badge', false);
    }

    public function test_with_sign_in_off_the_owner_gets_the_page(): void
    {
        \App\Support\AuthMode::store(false);

        $this->asNobody()->get('/requests')->assertOk()->assertSee('data-requests-page', false)->assertSee('id="navRequests"', false);
    }

    public function test_the_pending_count_is_cached_and_forgotten_on_every_change(): void
    {
        $readerToken = $this->tokenFor($this->reader);
        $ownerToken = $this->tokenFor($this->owner);
        $this->assertSame(0, Requests::pendingCount());

        $id = $this->asToken($readerToken)->postJson('/api/v1/requests', ['title' => 'Counted', 'medium' => 'novel'])->assertStatus(201)->json('request.id');
        $this->assertSame(1, Requests::pendingCount());
        $second = $this->asToken($readerToken)->postJson('/api/v1/requests', ['title' => 'Counted Too', 'medium' => 'novel'])->assertStatus(201)->json('request.id');
        $this->assertSame(2, Requests::pendingCount());

        // A change behind the class's back is not seen until it expires: proof it is cached.
        NovelRequest::whereKey($second)->update(['status' => NovelRequest::STATUS_DECLINED]);
        $this->assertSame(2, Requests::pendingCount());
        NovelRequest::whereKey($second)->update(['status' => NovelRequest::STATUS_PENDING]);

        $this->asToken($ownerToken)->postJson("/api/v1/requests/{$id}/decline")->assertOk();
        $this->assertSame(1, Requests::pendingCount());
        $this->asToken($readerToken)->deleteJson("/api/v1/requests/{$second}")->assertNoContent();
        $this->assertSame(0, Requests::pendingCount());

        $third = $this->asToken($readerToken)->postJson('/api/v1/requests', ['title' => 'Approved One', 'medium' => 'novel'])->assertStatus(201)->json('request.id');
        $this->assertSame(1, Requests::pendingCount());
        $this->asToken($ownerToken)->postJson("/api/v1/requests/{$third}/approve", ['url' => 'https://manga-a.test/approved'])->assertOk();
        $this->assertSame(0, Requests::pendingCount());
    }

    public function test_ranking_is_queued_on_the_commands_queue_not_after_the_response(): void
    {
        Bus::fake([RankRequestCandidates::class, RunNovelCommand::class, \App\Jobs\FinishNovelCheck::class]);
        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo']];
        FakeRequestSource::$search[FakeMangaB::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-b.test/solo']];
        $token = $this->tokenFor($this->reader);

        $searchId = $this->asToken($token)->postJson('/api/v1/requests/searches', ['q' => 'solo', 'medium' => 'manga'])->assertStatus(202)->json('search.id');
        $key = $this->asToken($token)->getJson("/api/v1/requests/searches/{$searchId}")->assertOk()->json('search.results.0.key');
        $id = $this->asToken($token)->postJson('/api/v1/requests', ['search_id' => $searchId, 'key' => $key])->assertStatus(201)->json('request.id');

        Bus::assertDispatched(RankRequestCandidates::class, fn(RankRequestCandidates $job) => $job->requestId === $id && $job->queue === 'commands');
        Bus::assertDispatchedTimes(RankRequestCandidates::class, 1);
        $this->assertNull(NovelRequest::find($id)->candidates_ranked_at, 'faked: nothing ranked in the request');

        // The admin's rerank goes the same way.
        $this->asSignedIn($this->owner)->postJson("/api/v1/requests/{$id}/rerank")->assertStatus(202);
        Bus::assertDispatchedTimes(RankRequestCandidates::class, 2);
        Bus::assertDispatched(RankRequestCandidates::class, fn(RankRequestCandidates $job) => $job->queue === 'commands');
    }

    public function test_a_ranking_that_cannot_be_queued_never_fails_the_request(): void
    {
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));
        $request = $this->pending($this->reader, 'Unqueued', 'manga');

        app(Requests::class)->queueRanking($request);

        $this->assertTrue($request->fresh()->isPending());
    }
}
