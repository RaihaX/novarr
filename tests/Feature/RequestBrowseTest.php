<?php

namespace Tests\Feature;

use App\Jobs\FindRequestCandidates;
use App\Novel;
use App\NovelRequest;
use App\Scraping\Fetcher;
use App\Services\Discover;
use App\Services\Requests\CandidateEvaluator;
use App\Services\Requests\RequestBrowse;
use App\Services\Requests\RequestSearch;
use App\Setting;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeFetcher;
use Tests\Support\FakeMangaA;
use Tests\Support\FakeMangaB;
use Tests\Support\FakeMangaC;
use Tests\Support\FakeNovelB;
use Tests\Support\FakePagedManga;
use Tests\Support\FakeRequestSource;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * GET /api/v1/requests/browse — the Requests page's Discover shelf
 * (App\Services\Requests\RequestBrowse): the lists, the chosen source,
 * paging, caching, failures; requesting from a list (POST /api/v1/requests
 * {list, page, key}); FindRequestCandidates filling the shortlist from the
 * other sources; the warm command. Fake sources, no network.
 */
class RequestBrowseTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private User $owner;

    private User $reader;

    private string $ownerToken;

    private string $readerToken;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FakeRequestSource::register();
        $this->app->instance(Fetcher::class, new FakeFetcher());
        $this->owner = $this->enableSignIn();
        $this->reader = $this->makeReader();
        $this->ownerToken = $this->tokenFor($this->owner);
        $this->readerToken = $this->tokenFor($this->reader);
    }

    protected function tearDown(): void
    {
        FakeRequestSource::reset();
        parent::tearDown();
    }

    private const SOLO = ['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo', 'cover' => 'https://img.example/solo.jpg', 'author' => 'Chugong', 'description' => '<p>Hunters.</p>', 'chapters' => 200, 'status' => 'Completed', 'alt_titles' => ['Na Honjaman Level Up', 'Only I Level Up']];

    /** Fake A offers popular and top: Solo Leveling, a requested work and one in the library. */
    private function chartOnA(): void
    {
        FakeRequestSource::$lists[FakeMangaA::class] = ['popular', 'top'];
        FakeRequestSource::$browse[FakeMangaA::class] = [
            'popular' => [1 => [self::SOLO, ['name' => 'Omniscient Reader', 'url' => 'https://manga-a.test/orv'], ['name' => 'Tower of God', 'url' => 'https://manga-a.test/tog']]],
            'top' => [1 => [['name' => 'Berserk', 'url' => 'https://manga-a.test/berserk']]],
        ];
    }

    private function browse(array $query = [], ?string $token = null)
    {
        return $this->asToken($token ?? $this->readerToken)->getJson('/api/v1/requests/browse' . ($query ? '?' . http_build_query($query) : ''));
    }

    private function browseCalls(): array
    {
        return array_values(array_map(fn($c) => $c['source'] . ' ' . $c['arg'], array_filter(FakeRequestSource::$calls, fn($c) => $c['kind'] === 'browse')));
    }

    private static function key(string $title): string
    {
        return RequestSearch::groupKey('manga', Discover::normalizeName($title));
    }

    public function test_the_lists_and_results_carry_the_request_search_shape_and_live_flags(): void
    {
        $this->chartOnA();
        $tower = Novel::create(['name' => 'Tower of God', 'group_id' => 1, 'translator_url' => 'https://manga-a.test/tog', 'medium' => Novel::MEDIUM_MANGA]);
        NovelRequest::create(['user_id' => $this->owner->id, 'medium' => 'manga', 'title' => 'Omniscient Reader', 'normalized_title' => 'omniscient reader', 'status' => 'pending']);

        $response = $this->browse()->assertOk();
        $this->assertSame(['medium', 'lists', 'list', 'source_name', 'page', 'has_more', 'results'], array_keys($response->json()));
        $this->assertSame('manga', $response->json('medium'));
        $this->assertSame([['id' => 'popular', 'name' => 'Popular'], ['id' => 'top', 'name' => 'Top rated']], $response->json('lists'));
        $this->assertSame('popular', $response->json('list'));
        $this->assertSame('Fake A (manga)', $response->json('source_name'));
        $this->assertSame(1, $response->json('page'));
        $this->assertFalse($response->json('has_more'));

        $results = $response->json('results');
        // A series already in the library is left out of the shelf.
        $this->assertSame(['Solo Leveling', 'Omniscient Reader'], array_column($results, 'title'));
        $this->assertSame(['key', 'title', 'author', 'cover_url', 'description', 'medium', 'sources', 'in_library', 'novel_id', 'requested'], array_keys($results[0]));
        $this->assertSame(self::key('Solo Leveling'), $results[0]['key']);
        $this->assertSame('Chugong', $results[0]['author']);
        $this->assertSame('Hunters.', $results[0]['description']);
        $this->assertSame('https://img.example/solo.jpg', $results[0]['cover_url']);
        $this->assertSame([['source' => 'fakemanga_a', 'source_name' => 'Fake A (manga)', 'chapters' => 200, 'status' => 'Completed']], $results[0]['sources']);
        $this->assertFalse($results[0]['in_library']);
        $this->assertNull($results[0]['requested']);
        $this->assertSame(['status' => 'pending', 'by_me' => false], $results[1]['requested']);
        // Once it leaves the library it is back, without a new source call.
        $tower->delete();
        $this->assertSame(['Solo Leveling', 'Omniscient Reader', 'Tower of God'], array_column($this->browse()->assertOk()->json('results'), 'title'));

        // Another list; one the shelf doesn't offer is refused.
        $this->assertSame(['Berserk'], array_column($this->browse(['list' => 'top'])->assertOk()->json('results'), 'title'));
        $this->browse(['list' => 'trending'])->assertStatus(422)->assertJsonValidationErrors('list');
        $this->browse(['list' => 'search'])->assertStatus(422);
        $this->browse(['medium' => 'audiobook'])->assertStatus(422)->assertJsonValidationErrors('medium');
        $this->browse(['page' => RequestBrowse::MAX_PAGE + 1])->assertStatus(422)->assertJsonValidationErrors('page');
    }

    public function test_the_source_is_the_first_in_search_order_that_offers_a_list(): void
    {
        // A offers search only: skipped. C before B by source_order.
        config(['novarr.requests.source_order' => ['fakemanga_c', 'fakemanga_b', 'fakemanga_a']]);
        FakeRequestSource::$lists[FakeMangaB::class] = ['trending'];
        FakeRequestSource::$lists[FakeMangaC::class] = ['top', 'popular'];
        FakeRequestSource::$browse[FakeMangaB::class]['trending'][1] = [['name' => 'From B', 'url' => 'https://manga-b.test/b']];
        FakeRequestSource::$browse[FakeMangaC::class]['popular'][1] = [['name' => 'From C', 'url' => 'https://manga-c.test/c']];

        $c = $this->browse()->assertOk();
        $this->assertSame('Fake C (manga)', $c->json('source_name'));
        $this->assertSame(['popular', 'top'], array_column($c->json('lists'), 'id'), 'LISTS order, not the source\'s');
        $this->assertSame(['From C'], array_column($c->json('results'), 'title'));

        // Hidden from Discover: the next one.
        Setting::put(Discover::HIDDEN_SETTING, json_encode(['fakemanga_c']));
        $b = $this->browse()->assertOk();
        $this->assertSame('Fake B (manga)', $b->json('source_name'));
        $this->assertSame([['id' => 'trending', 'name' => 'New & popular']], $b->json('lists'));
        $this->assertSame(['From B'], array_column($b->json('results'), 'title'));
    }

    public function test_a_paged_source_pages_up_to_max_page_and_a_plain_one_has_page_one_only(): void
    {
        FakeRequestSource::$lists[FakePagedManga::class] = ['popular'];
        FakeRequestSource::$browse[FakePagedManga::class]['popular'] = [
            1 => ['items' => [['name' => 'One', 'url' => 'https://paged-manga.test/1']], 'has_more' => true],
            2 => ['items' => [['name' => 'Two', 'url' => 'https://paged-manga.test/2']], 'has_more' => false],
            RequestBrowse::MAX_PAGE => ['items' => [['name' => 'Ten', 'url' => 'https://paged-manga.test/10']], 'has_more' => true],
        ];

        $this->browse()->assertOk()->assertJsonPath('has_more', true)->assertJsonPath('results.0.title', 'One');
        $this->browse(['page' => 2])->assertOk()->assertJsonPath('page', 2)->assertJsonPath('has_more', false)->assertJsonPath('results.0.title', 'Two');
        // The last page a reader may open has no "more", whatever the source says.
        $this->browse(['page' => RequestBrowse::MAX_PAGE])->assertOk()->assertJsonPath('has_more', false)->assertJsonPath('results.0.title', 'Ten');
        $this->assertSame(['fakepaged_manga popular:1', 'fakepaged_manga popular:2', 'fakepaged_manga popular:' . RequestBrowse::MAX_PAGE], $this->browseCalls());

        // A plain source: page 1 through discover(), nothing after it (and no call).
        FakeRequestSource::reset();
        $this->chartOnA();
        $this->browse()->assertOk()->assertJsonPath('has_more', false);
        $this->browse(['page' => 2])->assertOk()->assertJsonPath('results', [])->assertJsonPath('has_more', false);
        $this->assertSame(['fakemanga_a popular:1'], $this->browseCalls());
    }

    public function test_pages_are_cached_and_refresh_replaces_the_cache_only_when_it_succeeds(): void
    {
        $this->chartOnA();
        $this->browse()->assertOk();
        $this->browse()->assertOk();
        $this->browse([], $this->ownerToken)->assertOk();
        $this->assertSame(['fakemanga_a popular:1'], $this->browseCalls(), 'one source call for everyone');

        $browse = app(RequestBrowse::class);
        FakeRequestSource::$browse[FakeMangaA::class]['popular'][1] = [['name' => 'Fresh One', 'url' => 'https://manga-a.test/fresh']];
        $this->assertTrue($browse->refresh('manga', 'popular'));
        $this->assertSame(['Fresh One'], array_column($this->browse()->json('results'), 'title'));

        // A failed refresh keeps the cached page.
        FakeRequestSource::$browse[FakeMangaA::class]['popular'][1] = null;
        $this->assertFalse($browse->refresh('manga', 'popular'));
        $this->assertSame(['Fresh One'], array_column($this->browse()->json('results'), 'title'));
        $this->assertFalse($browse->refresh('manga', 'trending'), 'a list the source does not offer');
        $this->assertCount(3, $this->browseCalls());

        // The cache lasts browse_cache_minutes.
        $this->travel(RequestBrowse::cacheMinutes() + 1)->minutes();
        FakeRequestSource::$browse[FakeMangaA::class]['popular'][1] = [self::SOLO];
        $this->assertSame(['Solo Leveling'], array_column($this->browse()->json('results'), 'title'));
    }

    public function test_an_unreachable_source_is_a_502_and_is_not_cached(): void
    {
        $this->chartOnA();
        FakeRequestSource::$browse[FakeMangaA::class]['popular'][1] = null;
        $this->browse()->assertStatus(502)->assertExactJson(['message' => "Couldn't load this list right now. Try again later.", 'code' => 'browse_unavailable']);

        FakeRequestSource::$browse[FakeMangaA::class]['popular'][1] = fn() => throw new \RuntimeException('boom');
        $this->browse()->assertStatus(502);

        FakeRequestSource::$browse[FakeMangaA::class]['popular'][1] = [self::SOLO];
        $this->browse()->assertOk()->assertJsonPath('results.0.title', 'Solo Leveling');
        $this->assertCount(3, $this->browseCalls());
    }

    public function test_a_page_another_reader_is_loading_answers_busy(): void
    {
        // page() waits up to LOCK_WAIT_SECONDS for another reader's fetch, then gives up.
        $this->chartOnA();
        $this->app->instance(RequestBrowse::class, new class(app(RequestSearch::class), app(Discover::class)) extends RequestBrowse {
            public function page(string $medium, string $list, int $page): ?array
            {
                throw new \Illuminate\Contracts\Cache\LockTimeoutException();
            }
        });

        $this->browse()->assertStatus(503)->assertHeader('Retry-After', '5')
            ->assertExactJson(['message' => 'This list is being loaded. Try again in a moment.', 'code' => 'browse_busy']);
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => self::key('Solo Leveling')])
            ->assertStatus(503)->assertJsonPath('code', 'busy');
        $this->assertSame(0, NovelRequest::count());
    }

    public function test_the_shelf_is_empty_when_browse_is_off_or_no_source_has_lists_and_404_when_requests_are_off(): void
    {
        $empty = ['medium' => 'manga', 'lists' => [], 'list' => null, 'source_name' => null, 'page' => 1, 'has_more' => false, 'results' => []];
        // No source offers a list.
        $this->browse()->assertOk()->assertExactJson($empty);

        $this->chartOnA();
        config(['novarr.requests.browse' => false]);
        $this->browse(['list' => 'popular', 'page' => 3])->assertOk()->assertExactJson($empty);
        $this->assertSame([], $this->browseCalls());

        config(['novarr.requests.browse' => true, 'novarr.requests.enabled' => false]);
        $this->browse()->assertNotFound()->assertJsonPath('code', 'requests_disabled');
    }

    public function test_a_reader_may_browse_a_guest_may_not_and_readers_are_throttled(): void
    {
        $this->chartOnA();
        $this->asNobody()->getJson('/api/v1/requests/browse')->assertStatus(401);
        $this->browse()->assertOk();

        for ($i = 1; $i < 30; $i++) {
            $this->browse()->assertOk();
        }
        $this->browse()->assertStatus(429)->assertJsonPath('code', 'throttled');
        // The admin is not limited.
        $this->browse([], $this->ownerToken)->assertOk();
    }

    public function test_requesting_from_a_list_copies_the_candidate_from_the_server_page(): void
    {
        Bus::fake([FindRequestCandidates::class]);
        $this->chartOnA();
        $this->browse()->assertOk();

        $response = $this->asToken($this->readerToken)->postJson('/api/v1/requests', [
            'list' => 'popular',
            'key' => self::key('Solo Leveling'),
            'note' => 'Please',
            // Ignored: the candidate comes from the server's page.
            'title' => 'Evil', 'url' => 'https://evil.test/x', 'candidates' => [['source' => 'x', 'url' => 'https://evil.test/y']],
        ])->assertCreated();
        $this->assertFalse($response->json('joined'));

        $request = NovelRequest::findOrFail($response->json('request.id'));
        $this->assertSame('Solo Leveling', $request->title);
        $this->assertSame('manga', $request->medium);
        $this->assertSame('Please', $request->note);
        $this->assertSame('Chugong', $request->author);
        $this->assertNull($request->link);
        $this->assertSame([[
            'source' => 'fakemanga_a', 'source_name' => 'Fake A (manga)', 'url' => 'https://manga-a.test/solo', 'title' => 'Solo Leveling',
            'cover_url' => 'https://img.example/solo.jpg', 'chapters' => 200, 'status' => 'Completed',
        ]], $request->candidateList());
        // The shortlist is filled from the other sources after the response (the sync queue).
        Bus::assertDispatchedAfterResponse(FindRequestCandidates::class, fn(FindRequestCandidates $job) => $job->requestId === (int) $request->id
            && $job->altTitles === ['Na Honjaman Level Up', 'Only I Level Up']);

        // Now it is requested, in the list too.
        $this->assertSame(['status' => 'pending', 'by_me' => true], $this->browse()->json('results.0.requested'));
        // Asking again: already requested.
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => self::key('Solo Leveling')])
            ->assertStatus(409)->assertJsonPath('code', 'already_requested');
    }

    public function test_on_a_real_queue_the_shortlist_job_goes_to_the_commands_queue(): void
    {
        Bus::fake([FindRequestCandidates::class]);
        config(['queue.default' => 'database']);
        $this->chartOnA();
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'page' => 1, 'key' => self::key('Solo Leveling')])->assertCreated();

        Bus::assertDispatched(FindRequestCandidates::class, fn(FindRequestCandidates $job) => $job->queue === 'commands');
        Bus::assertNotDispatchedAfterResponse(FindRequestCandidates::class);
    }

    public function test_me_too_in_library_and_unknown_keys_from_a_list(): void
    {
        Bus::fake([FindRequestCandidates::class]);
        $this->chartOnA();
        Novel::create(['name' => 'Tower of God', 'group_id' => 1, 'translator_url' => 'https://manga-a.test/tog', 'medium' => Novel::MEDIUM_MANGA]);
        $kim = $this->tokenFor($this->makeReader('kim', 'Kim'));

        $first = $this->asToken($kim)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => self::key('Omniscient Reader')])->assertCreated();
        $joined = $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => self::key('Omniscient Reader')])->assertOk();
        $this->assertTrue($joined->json('joined'));
        $this->assertSame($first->json('request.id'), $joined->json('request.id'));
        // Joining asks nothing of the sources.
        Bus::assertDispatchedAfterResponseTimes(FindRequestCandidates::class, 1);

        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => self::key('Tower of God')])
            ->assertStatus(409)->assertJsonPath('code', 'in_library');
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => 'ffffffffffffffff'])
            ->assertStatus(410)->assertExactJson(['message' => 'This list has changed. Reload it and try again.', 'code' => 'browse_expired']);
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'top', 'key' => self::key('Solo Leveling')])
            ->assertStatus(410);
        $this->travel(61)->seconds(); // past the reader-actions throttle (6 a minute)
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'page' => RequestBrowse::MAX_PAGE + 1, 'key' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('page');
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'bogus', 'key' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('list');
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular'])
            ->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_a_key_is_found_on_another_cached_page_of_the_list(): void
    {
        Bus::fake([FindRequestCandidates::class]);
        FakeRequestSource::$lists[FakePagedManga::class] = ['popular'];
        FakeRequestSource::$browse[FakePagedManga::class]['popular'] = [
            1 => ['items' => [['name' => 'One', 'url' => 'https://paged-manga.test/1']], 'has_more' => true],
            2 => ['items' => [['name' => 'Two', 'url' => 'https://paged-manga.test/2']], 'has_more' => true],
            3 => ['items' => [['name' => 'Three', 'url' => 'https://paged-manga.test/3']], 'has_more' => false],
        ];
        $this->browse(['page' => 2])->assertOk();

        // Sent with page 1 (the list moved since): page 1 is read, then the cached page 2.
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'page' => 1, 'key' => self::key('Two')])
            ->assertCreated()->assertJsonPath('request.title', 'Two');
        // Page 3 was never loaded: it is not fetched to look.
        $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'page' => 1, 'key' => self::key('Three')])
            ->assertStatus(410);
        $this->assertSame(['fakepaged_manga popular:2', 'fakepaged_manga popular:1'], $this->browseCalls());
    }

    /** Solo Leveling requested from A's chart; B and C answer the title search. */
    private function requestSoloFromTheChart(): NovelRequest
    {
        Bus::fake([FindRequestCandidates::class]);
        $this->chartOnA();
        $id = $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => self::key('Solo Leveling')])
            ->assertCreated()->json('request.id');

        return NovelRequest::findOrFail($id);
    }

    public function test_the_shortlist_job_adds_the_other_sources_hits_after_the_original_then_ranks(): void
    {
        $request = $this->requestSoloFromTheChart();
        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo-again']];
        FakeRequestSource::$search[FakeMangaB::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-b.test/solo', 'chapters' => 201]];
        FakeRequestSource::$search[FakeMangaC::class] = [['name' => 'Solo Leveling: Ragnarok', 'url' => 'https://manga-c.test/ragnarok']];
        $ranked = [];
        $this->app->instance(CandidateEvaluator::class, new class($ranked) extends CandidateEvaluator {
            public function __construct(public array &$ranked) {}

            public function rank(NovelRequest $request): NovelRequest
            {
                $this->ranked[] = array_column($request->candidateList(), 'url');

                return $request;
            }
        });

        (new FindRequestCandidates((int) $request->id, ['Na Honjaman Level Up']))->handle(app(RequestSearch::class), app(Discover::class), app(CandidateEvaluator::class));

        // The original stays first; A is not added twice; Ragnarok is another work.
        $this->assertSame(['https://manga-a.test/solo', 'https://manga-b.test/solo'], array_column($request->fresh()->candidateList(), 'url'));
        $this->assertSame([['https://manga-a.test/solo', 'https://manga-b.test/solo']], $ranked);
        $this->assertSame('Fake B (manga)', $request->fresh()->candidateList()[1]['source_name']);
        $this->assertSame(201, $request->fresh()->candidateList()[1]['chapters']);

        // The lookup is remembered as a request search is (a reader's search then costs nothing).
        $this->assertNotNull(Cache::get(RequestSearch::cacheKey('manga', 'Solo Leveling')));
    }

    public function test_the_shortlist_job_matches_an_alt_title_respects_the_cap_and_ranks_for_real(): void
    {
        config(['novarr.requests.max_candidates' => 2]);
        $request = $this->requestSoloFromTheChart();
        // B knows it by another title only (its alt titles make it relevant to the query).
        FakeRequestSource::$search[FakeMangaB::class] = [['name' => 'Na Honjaman Level Up', 'url' => 'https://manga-b.test/nahonjaman', 'alt_titles' => ['Solo Leveling']]];
        FakeRequestSource::$search[FakeMangaC::class] = [['name' => 'Na Honjaman Level Up', 'url' => 'https://manga-c.test/nahonjaman', 'alt_titles' => ['Solo Leveling']]];
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => ['1', '2']];
        FakeRequestSource::$stats['https://manga-b.test/nahonjaman'] = ['numbers' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']];

        (new FindRequestCandidates((int) $request->id, ['Na Honjaman Level Up', 'Only I Level Up']))->handle(app(RequestSearch::class), app(Discover::class), app(CandidateEvaluator::class));

        $request->refresh();
        // Capped at 2: C left out. Ranked: B's ten chapters first.
        $this->assertSame(['https://manga-b.test/nahonjaman', 'https://manga-a.test/solo'], array_column($request->candidateList(), 'url'));
        $this->assertNotNull($request->candidates_ranked_at);
    }

    public function test_the_shortlist_job_still_ranks_when_the_lookup_fails_and_skips_a_decided_request(): void
    {
        $request = $this->requestSoloFromTheChart();
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => ['1']];
        $searches = new class extends RequestSearch {
            public function collect(string $medium, string $query, Discover $discover): array
            {
                throw new \RuntimeException('search blew up');
            }
        };

        (new FindRequestCandidates((int) $request->id))->handle($searches, app(Discover::class), app(CandidateEvaluator::class));
        $this->assertCount(1, $request->fresh()->candidateList());
        $this->assertNotNull($request->fresh()->candidates_ranked_at);

        $request->refresh()->forceFill(['status' => NovelRequest::STATUS_DECLINED, 'candidates_ranked_at' => null])->save();
        FakeRequestSource::$calls = [];
        (new FindRequestCandidates((int) $request->id))->handle(app(RequestSearch::class), app(Discover::class), app(CandidateEvaluator::class));
        $this->assertSame([], FakeRequestSource::$calls);
        $this->assertNull($request->fresh()->candidates_ranked_at);
    }

    public function test_on_the_sync_queue_the_whole_flow_runs_after_the_post(): void
    {
        $this->chartOnA();
        FakeRequestSource::$search[FakeMangaB::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-b.test/solo']];
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => ['1']];
        FakeRequestSource::$stats['https://manga-b.test/solo'] = ['numbers' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']];

        $id = $this->asToken($this->readerToken)->postJson('/api/v1/requests', ['list' => 'popular', 'key' => self::key('Solo Leveling')])
            ->assertCreated()->json('request.id');

        $request = NovelRequest::findOrFail($id);
        $this->assertSame(['https://manga-b.test/solo', 'https://manga-a.test/solo'], array_column($request->candidateList(), 'url'));
        $this->assertNotNull($request->candidates_ranked_at);
    }

    public function test_the_warm_command_refreshes_page_one_of_every_list_and_is_scheduled(): void
    {
        $this->chartOnA();
        $this->artisan('requests:browse-warm')
            ->expectsOutput('manga popular: refreshed.')
            ->expectsOutput('manga top: refreshed.')
            ->assertSuccessful();
        $this->assertSame(['fakemanga_a popular:1', 'fakemanga_a top:1'], $this->browseCalls());
        // Readers then read the cache.
        $this->browse()->assertOk();
        $this->browse(['list' => 'top'])->assertOk();
        $this->assertCount(2, $this->browseCalls());

        FakeRequestSource::$browse[FakeMangaA::class]['top'][1] = null;
        $this->artisan('requests:browse-warm')
            ->expectsOutput('manga top: could not be fetched; the cached page stays.')
            ->assertSuccessful();
        $this->browse(['list' => 'top'])->assertOk()->assertJsonPath('results.0.title', 'Berserk');

        // Off: nothing asked.
        FakeRequestSource::$calls = [];
        config(['novarr.requests.browse' => false]);
        $this->artisan('requests:browse-warm')->assertSuccessful();
        config(['novarr.requests.browse' => true, 'novarr.requests.enabled' => false]);
        $this->artisan('requests:browse-warm')->assertSuccessful();
        $this->assertSame([], $this->browseCalls());

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn($e) => str_contains((string) $e->command, 'requests:browse-warm'));
        $this->assertNotNull($event);
        $this->assertSame('0 */3 * * *', $event->expression);
    }

    public function test_the_warm_command_refreshes_only_the_books_when_no_series_source_offers_a_list(): void
    {
        $this->fakeOpenLibrary();
        $this->artisan('requests:browse-warm')
            ->expectsOutput('book popular: refreshed.')
            ->expectsOutput('book trending: refreshed.')
            ->expectsOutput('book classics: refreshed.')
            ->doesntExpectOutput('No source offers a Discover shelf list: nothing to refresh.')
            ->assertSuccessful();
        $this->assertSame([], $this->browseCalls());
    }

    // ---- Books (Open Library through the book catalogue) ----------------------

    private const TRENDING = ['works' => [
        ['key' => '/works/OL1W', 'title' => 'Project Hail Mary', 'author_name' => ['Andy Weir'], 'cover_i' => 101, 'first_publish_year' => 2021],
        ['key' => '/works/OL2W', 'title' => 'Dune', 'author_name' => ['Frank Herbert'], 'first_publish_year' => 1965],
        ['key' => '/works/OL3W', 'title' => 'Dune', 'author_name' => ['Someone Else']],
        ['key' => '/books/OL9M', 'title' => 'Not a work'],
        ['key' => '/works/OL4W', 'title' => '   '],
    ]];

    private function fakeOpenLibrary(array $weekly = self::TRENDING): void
    {
        Http::fake([
            'openlibrary.org/trending/weekly.json*' => Http::response($weekly),
            'openlibrary.org/trending/daily.json*' => Http::response(['works' => [['key' => '/works/OL5W', 'title' => 'Today Book', 'author_name' => ['A. Writer']]]]),
            'openlibrary.org/subjects/classics.json*' => Http::response(['work_count' => 30, 'works' => [
                ['key' => '/works/OL6W', 'title' => 'Pride and Prejudice', 'authors' => [['name' => 'Jane Austen']], 'cover_id' => 202, 'first_publish_year' => 1813],
            ]]),
        ]);
    }

    private static function bookKey(string $title, string $author): string
    {
        return RequestSearch::groupKey('book', Discover::normalizeName($title) . '|' . Discover::normalizeName($author));
    }

    public function test_books_browse_lists_open_library_charts_with_cached_pages(): void
    {
        $this->fakeOpenLibrary();
        Novel::create(['name' => 'Project Hail Mary', 'author' => 'Andy Weir', 'group_id' => 1, 'translator_url' => 'upload://phm', 'medium' => Novel::MEDIUM_BOOK]);

        $response = $this->browse(['medium' => 'book'])->assertOk();
        $this->assertSame('book', $response->json('medium'));
        $this->assertSame([
            ['id' => 'popular', 'name' => 'Popular this week'],
            ['id' => 'trending', 'name' => 'Trending today'],
            ['id' => 'classics', 'name' => 'Classics'],
        ], $response->json('lists'));
        $this->assertSame('popular', $response->json('list'));
        $this->assertSame('Book catalogues', $response->json('source_name'));
        $this->assertFalse($response->json('has_more'), 'fewer works than a page');
        // In the library: left out. The same title by another author is another book. No key or title: dropped.
        $results = $response->json('results');
        $this->assertSame([['Dune', 'Frank Herbert'], ['Dune', 'Someone Else']], array_map(fn($r) => [$r['title'], $r['author']], $results));
        $this->assertSame(self::bookKey('Dune', 'Frank Herbert'), $results[0]['key']);
        $this->assertSame([['source' => 'book-catalogue', 'source_name' => 'Book catalogues', 'chapters' => null, 'status' => '1965']], $results[0]['sources']);

        // The subject list (its own shape), with paging from work_count.
        $classics = $this->browse(['medium' => 'book', 'list' => 'classics'])->assertOk();
        $this->assertSame('Pride and Prejudice', $classics->json('results.0.title'));
        $this->assertSame('Jane Austen', $classics->json('results.0.author'));
        $this->assertStringContainsString('202', (string) $classics->json('results.0.cover_url'));
        $this->assertTrue($classics->json('has_more'));
        Http::assertSent(fn($r) => str_contains($r->url(), 'subjects/classics.json') && $r['limit'] == 24 && $r['offset'] == 0);

        // Cached: asked again, Open Library is not.
        $sent = count(Http::recorded());
        $this->browse(['medium' => 'book'])->assertOk();
        $this->assertCount($sent, Http::recorded());

        // A list the books don't offer.
        $this->browse(['medium' => 'book', 'list' => 'top'])->assertStatus(422);
    }

    public function test_books_browse_answers_502_when_open_library_fails(): void
    {
        Http::fake(['openlibrary.org/*' => Http::response('down', 500)]);
        $this->browse(['medium' => 'book'])->assertStatus(502)->assertJsonPath('code', 'browse_unavailable');
    }

    public function test_requesting_a_book_from_the_shelf_makes_a_wanted_book_request(): void
    {
        Bus::fake([FindRequestCandidates::class]);
        $this->fakeOpenLibrary();
        $this->browse(['medium' => 'book'])->assertOk();

        $response = $this->asToken($this->readerToken)->postJson('/api/v1/requests', [
            'medium' => 'book', 'list' => 'popular', 'key' => self::bookKey('Project Hail Mary', 'Andy Weir'), 'note' => 'please',
        ])->assertCreated();
        $this->assertTrue($response->json('request.wanted'));

        $request = NovelRequest::findOrFail($response->json('request.id'));
        $this->assertSame('book', $request->medium);
        $this->assertSame('Project Hail Mary', $request->title);
        $this->assertSame('Andy Weir', $request->author);
        $this->assertSame('please', $request->note);
        $this->assertTrue($request->isWanted());
        $this->assertSame([[
            'source' => 'book-catalogue', 'source_name' => 'Book catalogues', 'url' => 'https://openlibrary.org/works/OL1W',
            'title' => 'Project Hail Mary', 'cover_url' => 'https://covers.openlibrary.org/b/id/101-M.jpg', 'chapters' => null, 'status' => '2021',
        ]], $request->candidateList());
        // One source for books: no shortlist job.
        Bus::assertNotDispatched(FindRequestCandidates::class);

        // Now shown as requested on the shelf.
        $this->browse(['medium' => 'book'])->assertOk()->assertJsonPath('results.0.requested.by_me', true);
    }

    // ---- Novels -----------------------------------------------------------------

    public function test_novels_browse_uses_the_first_novel_source_with_a_chart_and_requests_from_it(): void
    {
        Bus::fake([FindRequestCandidates::class]);
        FakeRequestSource::$lists[FakeNovelB::class] = ['popular', 'new'];
        FakeRequestSource::$browse[FakeNovelB::class] = [
            'popular' => [1 => [['name' => 'Lord of the Mysteries', 'url' => 'https://novel-b.test/lotm', 'author' => 'Cuttlefish']]],
            'new' => [1 => [['name' => 'Fresh Novel', 'url' => 'https://novel-b.test/fresh']]],
        ];

        $response = $this->browse(['medium' => 'novel'])->assertOk();
        $this->assertSame([['id' => 'popular', 'name' => 'Popular'], ['id' => 'new', 'name' => 'New']], $response->json('lists'));
        $this->assertSame(['Lord of the Mysteries'], array_column($response->json('results'), 'title'));
        $this->assertSame(['Fresh Novel'], array_column($this->browse(['medium' => 'novel', 'list' => 'new'])->assertOk()->json('results'), 'title'));
        // The manga shelf is its own (no manga source offers a list here).
        $this->assertSame([], $this->browse(['medium' => 'manga'])->assertOk()->json('lists'));

        $id = $this->asToken($this->readerToken)->postJson('/api/v1/requests', [
            'medium' => 'novel', 'list' => 'popular', 'key' => RequestSearch::groupKey('novel', Discover::normalizeName('Lord of the Mysteries')),
        ])->assertCreated()->json('request.id');
        $request = NovelRequest::findOrFail($id);
        $this->assertSame('novel', $request->medium);
        $this->assertSame(['https://novel-b.test/lotm'], array_column($request->candidateList(), 'url'));
        Bus::assertDispatched(FindRequestCandidates::class);
    }
}
