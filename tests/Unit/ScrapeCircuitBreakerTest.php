<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Failure back-off for the chapter scraper (audit F18): circuit breaker,
 * post-failure delay and the per-host Cloudflare cooldown.
 */
class ScrapeCircuitBreakerTest extends TestCase
{
    public function testOnlyCloudflareAndFetchFailuresAreBlocking()
    {
        $this->assertTrue(ChapterScraper::isBlockingFailure('cloudflare'));
        $this->assertTrue(ChapterScraper::isBlockingFailure('fetch_failed'));
        $this->assertFalse(ChapterScraper::isBlockingFailure('no_content'));
        $this->assertFalse(ChapterScraper::isBlockingFailure('short_content'));
        $this->assertFalse(ChapterScraper::isBlockingFailure(null));
    }

    public function testCircuitOpensAfterThreeConsecutiveBlockingFailures()
    {
        $this->assertFalse(ChapterScraper::circuitOpen(0));
        $this->assertFalse(ChapterScraper::circuitOpen(2));
        $this->assertTrue(ChapterScraper::circuitOpen(3));
        $this->assertTrue(ChapterScraper::circuitOpen(4));
        $this->assertTrue(ChapterScraper::circuitOpen(1, 1));
    }

    public function testFailureDelayIsTheMinimumDelayCappedAtTen()
    {
        $this->assertSame(10, ChapterScraper::failureDelaySeconds(30));
        $this->assertSame(5, ChapterScraper::failureDelaySeconds(5));
        $this->assertSame(0, ChapterScraper::failureDelaySeconds(0));
        $this->assertSame(0, ChapterScraper::failureDelaySeconds(-3));
    }

    public function testHostCooldownKeyAndLookup()
    {
        $this->assertSame('scrape:host-cooldown:novelfull.com', ChapterScraper::hostCooldownKey('NovelFull.com'));

        $this->assertFalse(ChapterScraper::hostCoolingDown('novelfull.com'));
        Cache::put(ChapterScraper::hostCooldownKey('novelfull.com'), true, now()->addMinutes(15));
        $this->assertTrue(ChapterScraper::hostCoolingDown('novelfull.com'));
        $this->assertTrue(ChapterScraper::hostCoolingDown('NOVELFULL.COM'));
        $this->assertFalse(ChapterScraper::hostCoolingDown('example.test'));
    }

    /** Untried chapters after the breaker trips are not a failure cause. */
    public function testCircuitOpenCountIsIgnoredBySummary()
    {
        $this->assertNull(ChapterScraper::summarizeScrapeIssue(['circuit_open' => 20]));
        $this->assertSame(
            'the source site is blocking fetches with a Cloudflare challenge',
            ChapterScraper::summarizeScrapeIssue(['cloudflare' => 3, 'circuit_open' => 20])
        );
    }
}
