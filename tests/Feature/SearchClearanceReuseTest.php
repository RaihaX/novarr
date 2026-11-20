<?php

namespace Tests\Feature;

use App\Scraping\Fetcher;
use App\Sources\EmpireNovelSource;
use App\Sources\NovelFullSource;
use App\Sources\RoyalRoadSource;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Discover / reader-request searches of the Cloudflare-protected novel
 * sources: Empire Novel and NovelFull reuse the host's shared clearance
 * (Fetcher::page(): plain with the stored cf_clearance, a FlareSolverr solve
 * only without one or when refused); Royal Road's search tries a plain
 * request with the app's browser headers first. Fixtures only, no network.
 */
class SearchClearanceReuseTest extends TestCase
{
    private const EN = 'https://www.empirenovel.com/search-live?q=shadow+slave';
    private const NF = 'https://novelfull.com/search?keyword=shadow+slave';
    private const RR = 'https://www.royalroad.com/fictions/search?title=mother+of+learning';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['novarr.clearance_minutes' => 25]);
    }

    /**
     * A FakeFetcher whose plain() answers with $plain (a body, or an int
     * HTTP status for a refusal) instead of the route, recording the call
     * and the cookies it carried; html() serves the routes.
     */
    private function fetcher(array $routes, string|int|null $plain = null): FakeFetcher
    {
        $fake = new class($routes) extends FakeFetcher {
            public string|int|null $plainAnswer = null;

            public array $plainCookies = [];

            public function plain(string $url, array $headers = [], array $cookies = [], &$status = null): ?string
            {
                if ($this->plainAnswer === null) {
                    $this->plainCookies[] = $cookies;

                    return parent::plain($url, $headers, $cookies, $status);
                }
                $this->calls[] = ['method' => 'plain', 'url' => $url];
                $this->plainCookies[] = $cookies;
                if (is_int($this->plainAnswer)) {
                    $status = $this->plainAnswer;

                    return null;
                }
                $status = 200;

                return $this->plainAnswer;
            }
        };
        $fake->plainAnswer = $plain;
        $this->app->instance(Fetcher::class, $fake);

        return $fake;
    }

    private function clearanceFor(string $url): void
    {
        app(Fetcher::class)->rememberClearance($url, ['cf_clearance' => 'stored-cf'], 'StoredAgent/1.0');
    }

    // ---- Empire Novel -------------------------------------------------------------

    public function test_empire_novel_search_with_a_stored_clearance_is_one_plain_request(): void
    {
        $fetcher = $this->fetcher([self::EN => 'empirenovel/search-live-shadow.html']);
        $this->clearanceFor(self::EN);

        $items = (new EmpireNovelSource())->discover('search', 'shadow slave');

        $this->assertSame('Shadow Slave', $items[0]['name']);
        $this->assertSame([self::EN], $fetcher->urls('plain'));
        $this->assertSame([], $fetcher->urls('html'), 'no FlareSolverr solve');
        $this->assertSame([['cf_clearance' => 'stored-cf']], $fetcher->plainCookies);
    }

    public function test_empire_novel_search_without_a_clearance_or_refused_solves(): void
    {
        // No clearance: straight to FlareSolverr, as before.
        $fetcher = $this->fetcher([self::EN => 'empirenovel/search-live-shadow.html']);
        $this->assertSame('Shadow Slave', (new EmpireNovelSource())->discover('search', 'shadow slave')[0]['name']);
        $this->assertSame([], $fetcher->urls('plain'));
        $this->assertSame([self::EN], $fetcher->urls('html'));

        // Refused (403): the clearance is dropped and FlareSolverr answers.
        Cache::flush();
        $fetcher = $this->fetcher([self::EN => 'empirenovel/search-live-shadow.html'], 403);
        $this->clearanceFor(self::EN);
        $this->assertSame('Shadow Slave', (new EmpireNovelSource())->discover('search', 'shadow slave')[0]['name']);
        $this->assertSame([self::EN], $fetcher->urls('plain'));
        $this->assertSame([self::EN], $fetcher->urls('html'));
        $this->assertNull(app(Fetcher::class)->clearance(self::EN));

        // A plain 200 that is not the JSON list is not trusted.
        Cache::flush();
        $fetcher = $this->fetcher([self::EN => 'empirenovel/search-live-shadow.html'], '<html><body>Welcome</body></html>');
        $this->clearanceFor(self::EN);
        $this->assertSame('Shadow Slave', (new EmpireNovelSource())->discover('search', 'shadow slave')[0]['name']);
        $this->assertSame([self::EN], $fetcher->urls('html'));
    }

    public function test_empire_novel_search_failure_is_still_null(): void
    {
        $this->fetcher([self::EN => FakeFetcher::fail('flaresolverr_error', null)], 503);
        $this->clearanceFor(self::EN);

        $this->assertNull((new EmpireNovelSource())->discover('search', 'shadow slave'));
    }

    // ---- NovelFull ----------------------------------------------------------------

    public function test_novelfull_search_with_a_stored_clearance_is_one_plain_request(): void
    {
        $fetcher = $this->fetcher([self::NF => 'novelfull/search-shadow-slave.html']);
        $this->clearanceFor(self::NF);

        $items = (new NovelFullSource())->discover('search', 'shadow slave');

        $this->assertNotEmpty($items);
        $this->assertSame([self::NF], $fetcher->urls('plain'));
        $this->assertSame([], $fetcher->urls('html'));
    }

    public function test_novelfull_search_refused_plain_solves_and_failure_is_null(): void
    {
        $fetcher = $this->fetcher([self::NF => 'novelfull/search-shadow-slave.html'], '<html><head><title>Just a moment...</title></head></html>');
        $this->clearanceFor(self::NF);
        $this->assertNotEmpty((new NovelFullSource())->discover('search', 'shadow slave'));
        $this->assertSame([self::NF], $fetcher->urls('html'), 'a challenge page falls back to FlareSolverr');

        Cache::flush();
        $this->fetcher([self::NF => FakeFetcher::fail('flaresolverr_error', null)]);
        $this->assertNull((new NovelFullSource())->discover('search', 'shadow slave'));
    }

    // ---- Royal Road ---------------------------------------------------------------

    public function test_royal_road_search_falls_back_when_plain_is_refused_or_challenged(): void
    {
        foreach ([403, '<html><head><title>Just a moment...</title></head><body>cf-challenge-running</body></html>'] as $plain) {
            Cache::flush();
            $fetcher = $this->fetcher([self::RR => 'royalroad/search-mother-of-learning.html'], $plain);

            $items = (new RoyalRoadSource())->discover('search', 'mother of learning');

            $this->assertCount(20, $items);
            $this->assertSame([self::RR], $fetcher->urls('plain'));
            $this->assertSame([self::RR], $fetcher->urls('html'), 'no clearance stored: the FlareSolverr path');
            $this->assertSame([[]], $fetcher->plainCookies, 'the first plain request carries no cookie');
        }
    }

    public function test_royal_road_lists_other_than_search_keep_the_clearance_path(): void
    {
        $fetcher = $this->fetcher([RoyalRoadSource::BASE . '/fictions/weekly-popular' => 'royalroad/weekly-popular.html']);

        $this->assertCount(20, (new RoyalRoadSource())->discover('popular'));
        $this->assertSame([], $fetcher->urls('plain'));
        $this->assertSame([RoyalRoadSource::BASE . '/fictions/weekly-popular'], $fetcher->urls('html'));
    }
}
