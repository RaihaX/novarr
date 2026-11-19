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
            ['name' => 'Other Title', 'url' => 'https://manga-a.test/other', 'cover' => 'https://img.example/other.jpg'],
        ];
        FakeRequestSource::$search[FakeMangaB::class] = [
            ['name' => 'Solo Leveling!', 'url' => 'https://manga-b.test/solo', 'chapters' => 200, 'status' => 'Completed'],
            ['name' => 'Third One', 'url' => 'https://manga-b.test/third'],
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
        $inLibrary = Novel::create(['name' => 'Other Title', 'group_id' => 1, 'translator_url' => 'https://manga-a.test/other', 'medium' => Novel::MEDIUM_MANGA]);
        NovelRequest::create(['user_id' => $this->owner()->id, 'medium' => 'manga', 'title' => 'Third One', 'normalized_title' => Discover::normalizeName('Third One'), 'status' => 'pending']);

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
        $this->assertSame(['Solo Leveling', 'Other Title', 'Third One'], array_column($search['results'], 'title'));
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

        $this->assertTrue($results['Other Title']['in_library']);
        $this->assertSame($inLibrary->id, $results['Other Title']['novel_id']);
        // A cover the browser may load directly stays as it is.
        $this->assertSame('https://img.example/other.jpg', $results['Other Title']['cover_url']);
        $this->assertSame(['status' => 'pending', 'by_me' => false], $results['Third One']['requested']);

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
        $ids['dan'] = $this->startSearch($tokens['dan'])->assertStatus(202)->json('search.id');
        $this->startSearch($tokens['ana'])->assertStatus(429)->assertJsonPath('code', 'search_busy');

        // A job that never ends: its slot expires on its own (no leak).
        $this->travel(RequestSearch::slotSeconds() + 1)->seconds();
        $this->startSearch($tokens['ana'])->assertStatus(202);
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

        $first = $this->startSearch($token)->assertStatus(202)->json('search.id');
        $this->startSearch($token)->assertStatus(429)->assertJsonPath('code', 'search_running')->assertHeader('Retry-After');
        $finish($first);

        $second = $this->startSearch($token)->assertStatus(202)->json('search.id');
        $finish($second);

        $limited = $this->startSearch($token)->assertStatus(429)->assertJsonPath('code', 'search_rate_limited');
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertStringStartsWith('Too many searches.', $limited->json('message'));

        // Another account is not limited by this one.
        $this->startSearch($this->tokenFor($this->makeReader('kim', 'Kim')))->assertStatus(202);

        // The window passes.
        $this->travel(11)->minutes();
        $this->startSearch($token)->assertStatus(202);
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
