<?php

namespace Tests\Feature;

use App\Console\Commands\UpdateMetadata;
use App\Novel;
use App\Scraping\Fetcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * NovelUpdates lookups: the 7-day negative cache in getMetadata(), no
 * direct-GET fallback after a 4xx, and novel:metadata's default scope and
 * pacing.
 */
class NovelUpdatesLookupTest extends TestCase
{
    use RefreshDatabase;

    private const NU = 'https://www.novelupdates.com/';
    private const SEARCH = 'https://www.novelupdates.com/wp-admin/admin-ajax.php';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.novelarrow_host' => 'novelping.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function novel(string $name, array $attributes = []): Novel
    {
        $novel = Novel::create(['name' => $name, 'author' => 'Some Author', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);
        $novel->forceFill($attributes)->saveQuietly();

        return $novel->fresh();
    }

    /**
     * NovelUpdates misses (404 series page, an empty search result), the
     * Novel Arrow API fails, and raw client GETs (cover downloads) answer 404.
     */
    private function fakeMisses(array $routes = []): FakeFetcher
    {
        $fake = new class($routes + [self::SEARCH => '<div class="search_noresults">No results</div>'], [
            self::NU . 'series/' => FakeFetcher::fail('http_404', 404),
            'https://novelping.com/api-web/' => FakeFetcher::fail('http_404', 404),
        ]) extends FakeFetcher {
            public function client(): HttpClientInterface
            {
                return new MockHttpClient(function (string $method, string $url) {
                    $this->calls[] = ['method' => 'client', 'url' => $url];
                    return new MockResponse('', ['http_code' => 404]);
                });
            }
        };
        app()->instance(Fetcher::class, $fake);

        return $fake;
    }

    private function nuCalls(FakeFetcher $fake): array
    {
        return array_values(array_filter($fake->urls(), fn($u) => str_starts_with($u, self::NU)));
    }

    // ---- negative cache --------------------------------------------------------

    public function testLookupStampsCheckedAt(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $fake = $this->fakeMisses();
        $novel = $this->novel('Unknown Novel');

        $metadata = getMetadata($novel);

        $this->assertSame('', $metadata['description']);
        $this->assertSame([self::NU . 'series/unknown-novel/', self::SEARCH], $this->nuCalls($fake));
        $this->assertSame('2026-11-14 10:00:00', (string) $novel->fresh()->novelupdates_checked_at);
    }

    public function testRecentMissSkipsNovelUpdatesEntirely(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $fake = $this->fakeMisses();
        $novel = $this->novel('Unknown Novel', ['novelupdates_checked_at' => '2026-11-10 10:00:00']);

        $metadata = getMetadata($novel);

        $this->assertSame([], $fake->calls, 'no slug guess, no search');
        $this->assertSame(emptyNovelUpdatesMetadata(), $metadata);
        $this->assertSame('2026-11-10 10:00:00', (string) $novel->fresh()->novelupdates_checked_at);
    }

    public function testMissOlderThanSevenDaysIsLookedUpAgain(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $fake = $this->fakeMisses();
        $novel = $this->novel('Unknown Novel', ['novelupdates_checked_at' => '2026-11-07 09:00:00']);

        getMetadata($novel);

        $this->assertCount(2, $this->nuCalls($fake));
        $this->assertSame('2026-11-14 10:00:00', (string) $novel->fresh()->novelupdates_checked_at);
    }

    public function testSavedUrlIgnoresTheNegativeCache(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $url = 'https://www.novelupdates.com/series/lord-of-the-mysteries/';
        $fake = $this->fakeMisses([$url => 'novelupdates/series-page.html']);
        $novel = $this->novel('Lord of the Mysteries', [
            'novelupdates_url' => $url, 'novelupdates_match_score' => 1.0, 'novelupdates_checked_at' => '2026-11-13 10:00:00',
        ]);

        $metadata = getMetadata($novel);

        $this->assertSame([$url], $this->nuCalls($fake));
        $this->assertSame('Lord of the Mysteries', $metadata['title']);
    }

    // ---- no direct GET after a 4xx -----------------------------------------------

    public function testNoDirectGetAfterA4xx(): void
    {
        $url = self::NU . 'series/missing/';
        foreach ([FakeFetcher::fail('http_404', 404), FakeFetcher::fail('http_4xx', 403)] as $failure) {
            $fake = new FakeFetcher([$url => $failure]);
            app()->instance(Fetcher::class, $fake);

            $this->assertSame(emptyNovelUpdatesMetadata(), fetchNovelUpdatesMetadata($url));
            $this->assertSame([], $fake->urls('client'), 'no direct GET');
            $this->assertSame([], $fake->unexpected);
        }
    }

    public function testDirectGetStillFollowsANon4xxFailure(): void
    {
        $url = self::NU . 'series/flaky/';
        $fake = new FakeFetcher([$url => FakeFetcher::fail('flaresolverr_error', null)]);
        app()->instance(Fetcher::class, $fake);

        fetchNovelUpdatesMetadata($url);

        $this->assertSame([$url], $fake->urls('client'), 'direct GET attempted');
    }

    // ---- novel:metadata ----------------------------------------------------------

    private function bindRecordingCommand(): object
    {
        $recorder = new \ArrayObject();
        $this->app->bind(UpdateMetadata::class, fn() => new class($recorder) extends UpdateMetadata {
            public function __construct(private \ArrayObject $recorder)
            {
                parent::__construct();
            }

            protected function pause(int $seconds): void
            {
                $this->recorder->append($seconds);
            }
        });

        return $recorder;
    }

    public function testDefaultsToActiveNovelsAndPausesBetweenThem(): void
    {
        $this->fakeMisses();
        $pauses = $this->bindRecordingCommand();
        $this->novel('Active One');
        $this->novel('Active Two');
        $this->novel('Finished', ['status' => 1]);

        $this->artisan('novel:metadata')
            ->expectsOutput('Active One')
            ->expectsOutput('Active Two')
            ->doesntExpectOutput('Finished')
            ->assertExitCode(0);

        $this->assertSame([UpdateMetadata::DELAY_SECONDS], $pauses->getArrayCopy(), 'one pause between two novels');
    }

    public function testAllIncludesCompletedNovels(): void
    {
        $this->fakeMisses();
        $pauses = $this->bindRecordingCommand();
        $this->novel('Active One');
        $this->novel('Finished', ['status' => 1]);

        $this->artisan('novel:metadata', ['--all' => true])
            ->expectsOutput('Active One')
            ->expectsOutput('Finished')
            ->assertExitCode(0);

        $this->assertCount(1, $pauses);
    }

    public function testExplicitNovelRunsEvenWhenCompletedAndNeverPauses(): void
    {
        $this->fakeMisses();
        $pauses = $this->bindRecordingCommand();
        $novel = $this->novel('Finished', ['status' => 1]);

        $this->artisan('novel:metadata', ['novel' => $novel->id])->expectsOutput('Finished')->assertExitCode(0);

        $this->assertCount(0, $pauses);
    }

    public function testForcedNovelUpdatesUrlClearsTheNegativeCache(): void
    {
        $url = 'https://www.novelupdates.com/series/lord-of-the-mysteries/';
        $this->fakeMisses([$url => 'novelupdates/series-page.html']);
        $this->bindRecordingCommand();
        $novel = $this->novel('Lord of the Mysteries', ['novelupdates_checked_at' => now()]);

        $this->artisan('novel:metadata', ['novel' => $novel->id, '--novelupdates-url' => $url])->assertExitCode(0);

        $fresh = $novel->fresh();
        $this->assertSame($url, $fresh->novelupdates_url);
        $this->assertNull($fresh->novelupdates_checked_at);
    }

    public function testNewAuthorFromTheSourceKeepsTheNegativeCache(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->fakeMisses([
            'https://novelping.com/api-web/novels/unknown-novel' => json_encode(['item' => ['novelInfo' => [
                'novel_desc' => 'A story.', 'novel_author' => 'New Author', 'totalChapter' => 10,
            ]]]),
        ]);
        $this->bindRecordingCommand();
        $novel = $this->novel('Unknown Novel', ['novelupdates_checked_at' => '2026-11-12 10:00:00']);

        $this->artisan('novel:metadata', ['novel' => $novel->id])->assertExitCode(0);

        // The author alternates between NovelUpdates' and the source's on a
        // weak slug match: clearing the stamp on every change made the
        // weekly lookup run every other run. Only a human edit clears it.
        $fresh = $novel->fresh();
        $this->assertSame('New Author', $fresh->author);
        $this->assertSame('2026-11-12 10:00:00', (string) $fresh->novelupdates_checked_at);
    }

    public static function failedLookups(): array
    {
        $page = 'novelupdates/series-page.html';
        return [
            'FlareSolverr error, direct GET fails' => [FakeFetcher::fail('flaresolverr_error', null), ''],
            'slug rate-limited' => [FakeFetcher::fail('rate_limited', 429), ''],
            'slug Cloudflare challenge' => ['<html><head><title>Just a moment...</title></head></html>', ''],
            'slug 404, search failed' => [FakeFetcher::fail('http_404', 404), FakeFetcher::fail('http_5xx', 503)],
            'slug 404, search Cloudflare challenge' => [FakeFetcher::fail('http_404', 404), '<title>Just a moment...</title>'],
            'slug page fetched, search failed' => [$page, FakeFetcher::fail('flaresolverr_error', null)],
        ];
    }

    /** A lookup that ended in a fetch failure is no answer: it must not start the 7-day negative cache. */
    #[\PHPUnit\Framework\Attributes\DataProvider('failedLookups')]
    public function testFailedLookupLeavesTheStampUnset(array|string $slug, array|string $search): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->fakeMisses([self::NU . 'series/unknown-novel/' => $slug, self::SEARCH => $search]);
        $novel = $this->novel('Unknown Novel');

        getMetadata($novel);

        $this->assertNull($novel->fresh()->novelupdates_checked_at);
    }

    /** A slug page that was fetched and scored too low, plus an empty search: a real "no match". */
    public function testScoredMissIsStamped(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->fakeMisses([self::NU . 'series/unknown-novel/' => 'novelupdates/series-page.html']);
        $novel = $this->novel('Unknown Novel');

        getMetadata($novel);

        $fresh = $novel->fresh();
        $this->assertNull($fresh->novelupdates_url);
        $this->assertSame('2026-11-14 10:00:00', (string) $fresh->novelupdates_checked_at);
    }
}
