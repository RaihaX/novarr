<?php

namespace Tests\Feature;

use App\Jobs\RunRequestSearch;
use App\Novel;
use App\NovelRequest;
use App\Scraping\Fetcher;
use App\Services\Discover;
use App\Services\Requests\RequestSearch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeFetcher;
use Tests\Support\FakeMangaA;
use Tests\Support\FakeMangaB;
use Tests\Support\FakeMangaC;
use Tests\Support\FakeNovelA;
use Tests\Support\FakeRequestSource;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * POST/GET /api/v1/requests/searches: a reader's search across every source
 * of a medium (App\Services\Requests\RequestSearch), its limits and slots,
 * grouping into works, and the covers handed to readers. Sources are fakes
 * (Tests\Support\FakeRequestSource); nothing reaches the network.
 */
class RequestSearchTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FakeRequestSource::register();
        $this->app->instance(Fetcher::class, new FakeFetcher());
    }

    protected function tearDown(): void
    {
        FakeRequestSource::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mangaHits(): void
    {
        FakeRequestSource::$search[FakeMangaA::class] = [
            ['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo', 'cover' => 'https://uploads.mangadex.org/covers/solo.jpg', 'author' => 'Chugong', 'description' => '<p>Hunters.</p>'],
            ['name' => 'Solo Other Title', 'url' => 'https://manga-a.test/other', 'cover' => 'https://img.example/other.jpg'],
        ];
        FakeRequestSource::$search[FakeMangaB::class] = [
            ['name' => 'Solo Leveling!', 'url' => 'https://manga-b.test/solo', 'chapters' => 200, 'status' => 'Completed'],
            ['name' => 'Solo Third One', 'url' => 'https://manga-b.test/third'],
        ];
        FakeRequestSource::$search[FakeMangaC::class] = null; // the source is down
        FakeRequestSource::$search[FakeNovelA::class] = [['name' => 'Solo Leveling', 'url' => 'https://novel-a.test/solo']];
    }

    /** POST a search as the bearer of $token; returns the response. */
    private function startSearch(string $token, string $q = 'solo', string $medium = 'manga')
    {
        return $this->asToken($token)->postJson('/api/v1/requests/searches', ['q' => $q, 'medium' => $medium]);
    }

    public function test_a_search_asks_every_source_of_the_medium_and_groups_hits_into_works(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $token = $this->tokenFor($reader);
        $this->mangaHits();
        $inLibrary = Novel::create(['name' => 'Solo Other Title', 'group_id' => 1, 'translator_url' => 'https://manga-a.test/other', 'medium' => Novel::MEDIUM_MANGA]);
        NovelRequest::create(['user_id' => $this->owner()->id, 'medium' => 'manga', 'title' => 'Solo Third One', 'normalized_title' => Discover::normalizeName('Solo Third One'), 'status' => 'pending']);

        $posted = $this->startSearch($token)->assertStatus(202)->json('search');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $posted['id']);

        // The sync job ran after the response.
        $search = $this->asToken($token)->getJson("/api/v1/requests/searches/{$posted['id']}")->assertOk()->json('search');
        $this->assertSame(['id', 'status', 'message', 'query', 'medium', 'results', 'skipped_sources'], array_keys($search));
        $this->assertSame('done', $search['status']);
        $this->assertSame([['source' => 'fakemanga_c', 'source_name' => 'Fake C (manga)', 'reason' => 'failed']], $search['skipped_sources']);

        // Only manga sources were asked, each with the query string.
        $this->assertSame(['fakemanga_a', 'fakemanga_b', 'fakemanga_c'], array_column(FakeRequestSource::$calls, 'source'));
        $this->assertSame(['solo', 'solo', 'solo'], array_column(FakeRequestSource::$calls, 'arg'));

        $results = collect($search['results'])->keyBy('title');
        $this->assertSame(['Solo Leveling', 'Solo Other Title', 'Solo Third One'], array_column($search['results'], 'title'));
        $solo = $results['Solo Leveling'];
        $this->assertSame(['key', 'title', 'author', 'cover_url', 'description', 'medium', 'sources', 'in_library', 'novel_id', 'requested'], array_keys($solo));
        $this->assertSame([
            ['source' => 'fakemanga_a', 'source_name' => 'Fake A (manga)', 'chapters' => null, 'status' => null],
            ['source' => 'fakemanga_b', 'source_name' => 'Fake B (manga)', 'chapters' => 200, 'status' => 'Completed'],
        ], $solo['sources']);
        $this->assertSame('Chugong', $solo['author']);
        $this->assertSame('Hunters.', $solo['description']);
        $this->assertFalse($solo['in_library']);
        $this->assertNull($solo['requested']);
        $this->assertSame('manga', $solo['medium']);
        $this->assertArrayNotHasKey('url', $solo['sources'][0]);

        $this->assertTrue($results['Solo Other Title']['in_library']);
        $this->assertSame($inLibrary->id, $results['Solo Other Title']['novel_id']);
        // A cover the browser may load directly stays as it is.
        $this->assertSame('https://img.example/other.jpg', $results['Solo Other Title']['cover_url']);
        $this->assertSame(['status' => 'pending', 'by_me' => false], $results['Solo Third One']['requested']);

        // The same work keeps its key across searches.
        $this->assertSame(RequestSearch::groupKey('manga', 'solo leveling'), $solo['key']);
    }

    public function test_proxied_covers_are_signed_for_the_reader_and_the_route_is_not_an_open_proxy(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $token = $this->tokenFor($reader);
        $this->mangaHits();

        $id = $this->startSearch($token)->json('search.id');
        $cover = $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->json('search.results.0.cover_url');
        $this->assertStringStartsWith(url('/requests/cover') . '?', $cover);
        parse_str((string) parse_url($cover, PHP_URL_QUERY), $query);
        $this->assertSame('https://uploads.mangadex.org/covers/solo.jpg', $query['u']);
        $this->assertSame((string) $reader->id, (string) $query['reader']);
        $this->assertArrayHasKey('signature', $query);

        // The signed URL gets past sign-in with no other credentials (an <img>):
        // the controller then fetches from the allow-listed host only — none here.
        config(['novarr.discover_cover_proxy_hosts' => []]);
        $this->asNobody()->get($cover)->assertNotFound();

        // Tampering with the cover URL breaks the signature.
        $this->asNobody()->get(str_replace('solo.jpg', 'other.jpg', $cover))->assertStatus(401);
        // A signed-in reader without a signature gets nothing either: no unsigned proxy.
        config(['novarr.discover_cover_proxy_hosts' => ['uploads.mangadex.org']]);
        $this->asToken($token)->get('/requests/cover?u=' . urlencode('https://uploads.mangadex.org/covers/solo.jpg'))->assertNotFound();
        // Discover's own cover route stays the admin's.
        $this->asToken($token)->getJson('/discover/cover?u=' . urlencode('https://uploads.mangadex.org/covers/solo.jpg'))->assertStatus(403);
    }

    public function test_a_cover_signed_for_a_reader_is_served(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $this->actAsReader($reader);
        $cover = 'https://uploads.mangadex.org/covers/solo.jpg';
        $url = \App\Http\Controllers\RequestCoverController::url($cover);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Cache::put('discover_cover_v1_' . md5($cover), ['mime' => 'image/png', 'data' => base64_encode($png)], 60);

        $this->asNobody()->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_with_sign_in_off_covers_are_still_signed(): void
    {
        $url = \App\Http\Controllers\RequestCoverController::url('https://uploads.mangadex.org/covers/solo.jpg');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('reader=', $url);
        config(['novarr.discover_cover_proxy_hosts' => []]);
        $this->get($url)->assertNotFound();
        $this->get('/requests/cover?u=x')->assertNotFound();
    }

    public function test_only_the_requester_or_the_admin_can_read_a_search(): void
    {
        $owner = $this->enableSignIn();
        $this->mangaHits();
        $id = $this->startSearch($this->tokenFor($this->makeReader()))->json('search.id');

        $this->asToken($this->tokenFor($this->makeReader('kim', 'Kim')))->getJson("/api/v1/requests/searches/{$id}")
            ->assertNotFound()->assertJsonPath('code', 'search_expired');
        $this->asToken($this->tokenFor($owner))->getJson("/api/v1/requests/searches/{$id}")->assertOk()->assertJsonPath('search.status', 'done');
        $this->asToken($this->tokenFor($owner))->getJson('/api/v1/requests/searches/' . str_repeat('a', 32))->assertNotFound();
    }

    public function test_the_query_is_validated(): void
    {
        $this->enableSignIn();
        $token = $this->tokenFor($this->makeReader());

        $this->startSearch($token, str_repeat('x', 101))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->startSearch($token, 'solo', 'comic')->assertStatus(422)->assertJsonValidationErrors('medium');
        $this->startSearch($token, '   ')->assertStatus(422);
        $this->assertSame([], FakeRequestSource::$calls);
    }

    public function test_at_most_three_searches_run_at_once_across_all_accounts_and_slots_free_themselves(): void
    {
        $this->enableSignIn();
        Bus::fake([RunRequestSearch::class]); // the searches stay running
        $tokens = [];
        foreach (['ana', 'ben', 'cat', 'dan'] as $name) {
            $tokens[$name] = $this->tokenFor($this->makeReader($name, ucfirst($name)));
        }

        $ids = [];
        foreach (['ana', 'ben', 'cat'] as $name) {
            $ids[$name] = $this->startSearch($tokens[$name])->assertStatus(202)->json('search.id');
        }
        $busy = $this->startSearch($tokens['dan'])->assertStatus(429);
        $busy->assertExactJson(['message' => 'Search is busy right now. Try again in a moment.', 'code' => 'search_busy']);
        $this->assertSame((string) RequestSearch::BUSY_RETRY_SECONDS, $busy->headers->get('Retry-After'));

        // A finished search frees its slot.
        $this->mangaHits();
        (new RunRequestSearch($ids['ana']))->handle(app(RequestSearch::class), app(Discover::class));
        $this->assertSame('done', app(RequestSearch::class)->find($ids['ana'])['status']);
        $ids['dan'] = $this->startSearch($tokens['dan'], 'dan')->assertStatus(202)->json('search.id');
        $this->startSearch($tokens['ana'], 'ana again')->assertStatus(429)->assertJsonPath('code', 'search_busy');

        // A job that never ends: its slot expires on its own (no leak).
        $this->travel(RequestSearch::slotSeconds() + 1)->seconds();
        $this->startSearch($tokens['ana'], 'ana again')->assertStatus(202);
        // The abandoned search reads as failed.
        $this->asToken($tokens['ben'])->getJson("/api/v1/requests/searches/{$ids['ben']}")
            ->assertOk()->assertJsonPath('search.status', 'failed')->assertJsonPath('search.message', RequestSearch::TOO_SLOW);
    }

    public function test_a_job_that_throws_frees_its_slots(): void
    {
        $this->enableSignIn();
        config(['novarr.requests.max_concurrent_searches' => 1]);
        Bus::fake([RunRequestSearch::class]);
        $token = $this->tokenFor($this->makeReader());
        $other = $this->tokenFor($this->makeReader('kim', 'Kim'));

        $id = $this->startSearch($token)->json('search.id');
        FakeRequestSource::$search[FakeMangaA::class] = fn() => throw new \RuntimeException('boom');
        $searches = new class extends RequestSearch {
            public function run(string $id, Discover $discover): void
            {
                throw new \RuntimeException('worker blew up');
            }
        };
        (new RunRequestSearch($id))->handle($searches, app(Discover::class));

        $this->assertSame('failed', app(RequestSearch::class)->find($id)['status']);
        $this->startSearch($other)->assertStatus(202);
    }

    public function test_one_running_search_per_account_and_a_rate_limit(): void
    {
        $this->enableSignIn();
        config(['novarr.requests.searches_per_window' => 2]);
        Bus::fake([RunRequestSearch::class]);
        $token = $this->tokenFor($this->makeReader());
        $finish = fn(string $id) => (new RunRequestSearch($id))->handle(app(RequestSearch::class), app(Discover::class));

        $first = $this->startSearch($token, 'one')->assertStatus(202)->json('search.id');
        $this->startSearch($token, 'two')->assertStatus(429)->assertJsonPath('code', 'search_running')->assertHeader('Retry-After');
        $finish($first);

        $second = $this->startSearch($token, 'two')->assertStatus(202)->json('search.id');
        $finish($second);

        $limited = $this->startSearch($token, 'three')->assertStatus(429)->assertJsonPath('code', 'search_rate_limited');
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertStringStartsWith('Too many searches.', $limited->json('message'));

        // Another account is not limited by this one.
        $this->startSearch($this->tokenFor($this->makeReader('kim', 'Kim')))->assertStatus(202);

        // The window passes.
        $this->travel(11)->minutes();
        $this->startSearch($token, 'three')->assertStatus(202);
    }

    public function test_sources_out_of_time_are_skipped_and_the_search_still_succeeds(): void
    {
        $this->enableSignIn();
        config(['novarr.requests.search_budget_seconds' => 10]);
        $token = $this->tokenFor($this->makeReader());
        FakeRequestSource::$search[FakeMangaA::class] = function () {
            Carbon::setTestNow(now()->addSeconds(30)); // a slow source

            return [['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo']];
        };

        $id = $this->startSearch($token)->json('search.id');
        $search = $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->json('search');

        $this->assertSame('done', $search['status']);
        $this->assertSame(['Solo Leveling'], array_column($search['results'], 'title'));
        $this->assertSame([
            ['source' => 'fakemanga_b', 'source_name' => 'Fake B (manga)', 'reason' => 'timeout'],
            ['source' => 'fakemanga_c', 'source_name' => 'Fake C (manga)', 'reason' => 'timeout'],
        ], $search['skipped_sources']);
        $this->assertSame(['fakemanga_a'], array_column(FakeRequestSource::$calls, 'source'));
    }

    public function test_sources_are_capped_preferred_ones_first(): void
    {
        config(['novarr.requests.max_sources_per_search' => 2, 'novarr.requests.source_order' => ['fakemanga_c']]);

        $keys = array_keys(app(RequestSearch::class)->sources(app(Discover::class), 'manga'));

        $this->assertSame(['fakemanga_c', 'fakemanga_a'], $keys);

        // The name preference places what source_order does not.
        config(['novarr.requests.max_sources_per_search' => 3, 'novarr.requests.source_name_preference' => ['Fake B']]);
        $this->assertSame(['fakemanga_c', 'fakemanga_b', 'fakemanga_a'], array_keys(app(RequestSearch::class)->sources(app(Discover::class), 'manga')));
    }

    public function test_hits_that_do_not_resemble_the_query_are_dropped_before_grouping(): void
    {
        $this->enableSignIn();
        $token = $this->tokenFor($this->makeReader());
        // A source answering with loosely related titles (as Royal Road does).
        FakeRequestSource::$search[FakeMangaA::class] = [
            ['name' => 'The Wandering Inn', 'url' => 'https://manga-a.test/inn'],
            ['name' => 'Mother of Learning: Side Stories', 'url' => 'https://manga-a.test/side'],
            ['name' => 'Mother of Learning', 'url' => 'https://manga-a.test/mol'],
            ['name' => 'Beware of Chicken', 'url' => 'https://manga-a.test/chicken'],
            ['name' => 'Kaa-san no Gakushuu', 'alt_titles' => ['Mother of Learning (fan title)'], 'url' => 'https://manga-a.test/alt'],
            ['name' => 'Learning Magic', 'url' => 'https://manga-a.test/magic'],
        ];
        FakeRequestSource::$search[FakeMangaB::class] = [['name' => 'Super Supportive', 'url' => 'https://manga-b.test/ss']];

        $id = $this->startSearch($token, 'mother of learning')->json('search.id');
        $search = $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->json('search');

        // Exact title first, then (same source count) titles starting with
        // the query, then the rest in search order.
        $this->assertSame(['Mother of Learning', 'Mother of Learning: Side Stories', 'Kaa-san no Gakushuu'], array_column($search['results'], 'title'));
        $this->assertSame([], $search['skipped_sources'], 'a source whose hits were all dropped still answered');
        $this->assertSame(['id', 'status', 'message', 'query', 'medium', 'results', 'skipped_sources'], array_keys($search), 'no new field');

        // A query in an unspaced script is not filtered.
        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Battle Through the Heavens', 'url' => 'https://manga-a.test/btth']];
        $id = $this->startSearch($this->tokenFor($this->makeReader('kim', 'Kim')), '斗破苍穹')->json('search.id');
        $this->assertSame(['Battle Through the Heavens', 'Super Supportive'],
            array_column(app(RequestSearch::class)->find($id)['groups'], 'title'));
    }

    // ---- Remembered results -----------------------------------------------------

    public function test_a_repeat_search_answers_from_the_cache_with_no_slot_and_no_rate_limit_hit(): void
    {
        $this->enableSignIn();
        config(['novarr.requests.max_concurrent_searches' => 1, 'novarr.requests.searches_per_window' => 1]);
        $ana = $this->makeReader('ana', 'Ana');
        $ben = $this->makeReader('ben', 'Ben');
        $anaToken = $this->tokenFor($ana);
        $benToken = $this->tokenFor($ben);
        $catToken = $this->tokenFor($this->makeReader('cat', 'Cat'));
        $this->mangaHits();
        FakeRequestSource::$search[FakeMangaC::class] = [['name' => 'Solo Gamma', 'url' => 'https://manga-c.test/gamma']]; // no skipped source

        $first = $this->startSearch($anaToken)->assertStatus(202)->json('search.id');
        $this->assertSame('done', app(RequestSearch::class)->find($first)['status']);
        $askedOnce = count(FakeRequestSource::$calls);
        NovelRequest::create(['user_id' => $ana->id, 'medium' => 'manga', 'title' => 'Solo Leveling', 'normalized_title' => 'solo leveling', 'status' => 'pending']);

        // The only slot is taken by a search that keeps running.
        Bus::fake([RunRequestSearch::class]);
        $this->startSearch($catToken, 'something else')->assertStatus(202)->assertJsonPath('search.status', 'running');
        $this->startSearch($this->tokenFor($this->makeReader('dan', 'Dan')), 'another')->assertStatus(429)->assertJsonPath('code', 'search_busy');

        // Ben's search for the same thing (another spelling) is done at once from the cache.
        $cached = $this->startSearch($benToken, '  SOLO ')->assertStatus(202)->json('search');
        $this->assertSame('done', $cached['status']);
        $this->assertSame('SOLO', $cached['query'], 'the reader\'s own query is kept');
        $this->assertSame(['Solo Leveling', 'Solo Other Title', 'Solo Third One', 'Solo Gamma'], array_column($cached['results'], 'title'));
        $this->assertSame($askedOnce, count(FakeRequestSource::$calls), 'no source was asked again');
        Bus::assertNotDispatched(RunRequestSearch::class, fn(RunRequestSearch $job) => $job->searchId === $cached['id']);
        $this->assertNull(app(RequestSearch::class)->find($cached['id'])['slot']);

        // Per-viewer fields are worked out for Ben, not copied from Ana's search.
        $solo = $this->asToken($benToken)->getJson("/api/v1/requests/searches/{$cached['id']}")->assertOk()->json('search.results.0');
        $this->assertSame(['status' => 'pending', 'by_me' => false], $solo['requested']);
        parse_str((string) parse_url($solo['cover_url'], PHP_URL_QUERY), $cover);
        $this->assertSame((string) $ben->id, (string) $cover['reader']);
        $this->assertSame(['status' => 'pending', 'by_me' => true],
            $this->asToken($anaToken)->getJson("/api/v1/requests/searches/{$first}")->json('search.results.0.requested'));

        // Ana used her one search of the window: the same query is still answered, a new one is not.
        $this->startSearch($anaToken, 'Solo')->assertStatus(202)->assertJsonPath('search.status', 'done');
        $this->startSearch($anaToken, 'brand new')->assertStatus(429)->assertJsonPath('code', 'search_rate_limited');
        // The cached search can be requested from like any other.
        $this->asToken($benToken)->postJson('/api/v1/requests', ['search_id' => $cached['id'], 'key' => $solo['key']])
            ->assertOk()->assertJsonPath('joined', true);

        // Ten minutes on, the sources are asked again.
        $this->travel(11)->minutes();
        $this->startSearch($benToken, 'solo')->assertStatus(202)->assertJsonPath('search.status', 'running');
    }

    public function test_a_search_that_skipped_a_source_is_remembered_for_two_minutes_only(): void
    {
        $this->enableSignIn();
        $token = $this->tokenFor($this->makeReader());
        $other = $this->tokenFor($this->makeReader('kim', 'Kim'));
        $this->mangaHits(); // Fake C fails

        $this->startSearch($token)->assertStatus(202);
        $asked = count(FakeRequestSource::$calls);

        $this->travel(90)->seconds();
        $again = $this->startSearch($other)->assertStatus(202)->json('search');
        $this->assertSame('done', $again['status']);
        $this->assertSame([['source' => 'fakemanga_c', 'source_name' => 'Fake C (manga)', 'reason' => 'failed']], $again['skipped_sources']);
        $this->assertSame($asked, count(FakeRequestSource::$calls));

        // Past two minutes the flaky source is tried again.
        $this->travel(60)->seconds();
        FakeRequestSource::$search[FakeMangaC::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-c.test/solo']];
        $retried = $this->startSearch($other)->assertStatus(202)->json('search.id');
        $this->assertGreaterThan($asked, count(FakeRequestSource::$calls));
        $search = $this->asToken($other)->getJson("/api/v1/requests/searches/{$retried}")->json('search');
        $this->assertSame([], $search['skipped_sources']);
        $this->assertCount(3, $search['results'][0]['sources']);
    }

    public function test_results_are_per_medium_and_not_remembered_when_the_cache_is_off(): void
    {
        $this->enableSignIn();
        $token = $this->tokenFor($this->makeReader());
        $this->mangaHits();
        FakeRequestSource::$search[FakeMangaC::class] = [];

        $this->startSearch($token, 'solo', 'manga');
        $this->startSearch($token, 'solo', 'novel')->assertStatus(202);
        $this->assertSame(['fakemanga_a', 'fakemanga_b', 'fakemanga_c', 'fakenovel_a', 'fakenovel_b'], array_column(FakeRequestSource::$calls, 'source'));
        $this->assertNotSame(RequestSearch::cacheKey('manga', 'solo'), RequestSearch::cacheKey('novel', 'solo'));
        $this->assertSame(RequestSearch::cacheKey('manga', 'Solo  Leveling'), RequestSearch::cacheKey('manga', ' solo leveling'));

        config(['novarr.requests.search_cache_minutes' => 0]);
        FakeRequestSource::$calls = [];
        $this->startSearch($this->tokenFor($this->makeReader('kim', 'Kim')), 'fresh');
        $this->startSearch($this->tokenFor($this->makeReader('lee', 'Lee')), 'fresh');
        $this->assertCount(6, FakeRequestSource::$calls);
    }

    public function test_with_sign_in_off_the_owner_searches(): void
    {
        $this->mangaHits();

        $id = $this->postJson('/api/v1/requests/searches', ['q' => 'solo', 'medium' => 'novel'])->assertStatus(202)->json('search.id');

        $this->getJson("/api/v1/requests/searches/{$id}")->assertOk()
            ->assertJsonPath('search.status', 'done')
            ->assertJsonPath('search.results.0.sources.0.source', 'fakenovel_a');
    }
}
