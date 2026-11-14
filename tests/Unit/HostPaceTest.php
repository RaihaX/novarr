<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use App\Scraping\HostPace;
use App\Scraping\SourceUnavailable;
use App\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pure arithmetic of the adaptive per-host pace, the parked-chapter
 * re-check backoff, the sweep order and the source-unavailable summary.
 */
class HostPaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testWaitRangeIsPaceToOneAndAHalfPace(): void
    {
        $this->assertSame([30, 45], HostPace::waitRange(30));
        $this->assertSame([26, 38], HostPace::waitRange(25.5));
        // Never below the pace, and never an empty range.
        $this->assertSame([3, 3], HostPace::waitRange(2.1));
        $this->assertSame([1, 1], HostPace::waitRange(1));
    }

    public function testTenCleanRequestsSpeedUpByFifteenPercent(): void
    {
        $state = HostPace::initialState(30);
        for ($i = 1; $i <= 9; $i++) {
            $state = HostPace::afterClean($state, 15);
            $this->assertSame(30.0, $state['pace'], "no change after {$i} clean");
        }
        $state = HostPace::afterClean($state, 15);
        $this->assertEqualsWithDelta(25.5, $state['pace'], 1e-9);
        $this->assertSame(0, $state['clean']);

        // Further speed-ups stop at the floor.
        for ($i = 0; $i < 100; $i++) {
            $state = HostPace::afterClean($state, 15);
        }
        $this->assertSame(15.0, $state['pace']);
    }

    public function testASpeedUpNeverRaisesAPaceAlreadyUnderTheFloor(): void
    {
        $state = HostPace::initialState(5);
        for ($i = 0; $i < 10; $i++) {
            $state = HostPace::afterClean($state, 15);
        }
        $this->assertSame(5.0, $state['pace']);
    }

    public function testRateLimitDoublesThePaceAndLearnsAFloor(): void
    {
        $now = 1_000_000;
        $state = ['pace' => 20.0, 'clean' => 7] + HostPace::initialState(30);

        $state = HostPace::afterThrottle($state, 'rate_limited', 30, $now);

        $this->assertSame(40.0, $state['pace']);
        $this->assertSame(30.0, $state['floor']);
        $this->assertSame($now + 7 * 86400, $state['floor_until']);
        $this->assertSame(0, $state['clean']);
        $this->assertSame('rate_limited', $state['last_slowdown_reason']);
        $this->assertSame($now, $state['last_slowdown_at']);

        // A second throttle keeps the higher floor and doubles again.
        $state = HostPace::afterThrottle($state, 'cloudflare', 30, $now + 60);
        $this->assertSame(80.0, $state['pace']);
        $this->assertSame(60.0, $state['floor']);
        $this->assertSame('cloudflare', $state['last_slowdown_reason']);
    }

    public function testThrottleRespectsMinDelayAndTheCap(): void
    {
        $low = HostPace::afterThrottle(['pace' => 5.0] + HostPace::initialState(30), 'rate_limited', 30, 0);
        $this->assertSame(30.0, $low['pace'], 'at least scrape_min_delay');

        $high = HostPace::afterThrottle(['pace' => 250.0] + HostPace::initialState(30), 'rate_limited', 30, 0);
        $this->assertSame(300.0, $high['pace'], 'capped at 300');
    }

    public function testAnExpiredLearnedFloorIsReplacedNotKept(): void
    {
        $state = ['pace' => 20.0, 'floor' => 200.0, 'floor_until' => 100] + HostPace::initialState(30);
        $state = HostPace::afterThrottle($state, 'rate_limited', 30, 200);
        $this->assertSame(30.0, $state['floor']);
    }

    public function testFetchFailedSlowsDownWithoutAFloor(): void
    {
        $state = HostPace::afterFetchFailed(['pace' => 30.0, 'clean' => 5] + HostPace::initialState(30), 50);
        $this->assertSame(45.0, $state['pace']);
        $this->assertSame(0, $state['clean']);
        $this->assertNull($state['floor']);

        $capped = HostPace::afterFetchFailed(['pace' => 290.0] + HostPace::initialState(30), 50);
        $this->assertSame(300.0, $capped['pace']);
    }

    public function testEffectiveFloor(): void
    {
        $this->assertSame(15.0, HostPace::effectiveFloor(15, null, null, 100));
        $this->assertSame(45.0, HostPace::effectiveFloor(15, 45.0, 200, 100), 'learned floor in force');
        $this->assertSame(15.0, HostPace::effectiveFloor(15, 45.0, 100, 100), 'expired');
        $this->assertSame(15.0, HostPace::effectiveFloor(15, 10.0, 200, 100), 'never below the fastest delay');
        $this->assertSame(3.0, HostPace::effectiveFloor(1, null, null, 100), 'fastest delay is at least 3');
    }

    public function testCleanMeansSuccessOrAContentAnswer(): void
    {
        $this->assertTrue(HostPace::isClean(null));
        $this->assertTrue(HostPace::isClean('not_found'));
        $this->assertTrue(HostPace::isClean('no_content'));
        $this->assertTrue(HostPace::isClean('short_content'));
        $this->assertFalse(HostPace::isClean('rate_limited'));
        $this->assertFalse(HostPace::isClean('cloudflare'));
        $this->assertFalse(HostPace::isClean('fetch_failed'));
        $this->assertFalse(HostPace::isClean('exception'));
    }

    public function testDelayDrawsFromThePaceRangeWhenAdaptive(): void
    {
        Setting::put('scrape_min_delay', 30);
        Setting::put('scrape_max_delay', 90);
        $calls = [];
        $pace = new HostPace(function (int $lo, int $hi) use (&$calls) {
            $calls[] = [$lo, $hi];
            return $hi;
        });

        $this->assertSame(45, $pace->delay('a.test'));
        $this->assertSame([[30, 45]], $calls);
    }

    public function testAdaptiveOffIsThePlainMinMaxDelayAndKeepsNoState(): void
    {
        Setting::put('scrape_adaptive', '0');
        Setting::put('scrape_min_delay', 30);
        Setting::put('scrape_max_delay', 90);
        $calls = [];
        $pace = new HostPace(function (int $lo, int $hi) use (&$calls) {
            $calls[] = [$lo, $hi];
            return $lo;
        });

        $pace->record('a.test', 'rate_limited');
        for ($i = 0; $i < 20; $i++) {
            $pace->record('a.test', null);
        }
        $pace->delay('a.test');

        $this->assertSame([[30, 90]], $calls);
        $this->assertSame([], $pace->overview());

        // The real default (rand) stays inside the range too.
        $real = new HostPace();
        for ($i = 0; $i < 50; $i++) {
            $d = $real->delay('a.test');
            $this->assertGreaterThanOrEqual(30, $d);
            $this->assertLessThanOrEqual(90, $d);
        }
    }

    public function testRecordPersistsStateAndOverviewReportsIt(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        Setting::put('scrape_min_delay', 20);
        $pace = new HostPace(fn(int $lo, int $hi) => $lo);

        $pace->record('A.Test', 'rate_limited');

        $state = $pace->state('a.test');
        $this->assertSame(40.0, $state['pace']);
        $this->assertSame(30.0, $state['floor']);

        $row = $pace->overview()[0];
        $this->assertSame('a.test', $row['host']);
        $this->assertSame([40, 60], $row['range']);
        $this->assertSame(30.0, $row['fastest']);
        $this->assertSame(30.0, $row['floor']);
        $this->assertTrue($row['floor_until']->equalTo(now()->addDays(7)));
        $this->assertSame('rate_limited', $row['last_slowdown_reason']);

        // Clean requests speed up, but not below the learned floor.
        for ($i = 0; $i < 50; $i++) {
            $pace->record('a.test', 'not_found');
        }
        $this->assertSame(30.0, $pace->state('a.test')['pace']);

        // Once the floor expires the fastest delay applies.
        Carbon::setTestNow(now()->addDays(8));
        for ($i = 0; $i < 50; $i++) {
            $pace->record('a.test', null);
        }
        $this->assertSame(15.0, $pace->state('a.test')['pace']);
    }

    public function testRecheckBackoffDoublesFromSixHoursCappedAtSevenDays(): void
    {
        $h = 3600;
        $this->assertSame(6 * $h, SourceUnavailable::recheckSeconds(1));
        $this->assertSame(12 * $h, SourceUnavailable::recheckSeconds(2));
        $this->assertSame(24 * $h, SourceUnavailable::recheckSeconds(3));
        $this->assertSame(48 * $h, SourceUnavailable::recheckSeconds(4));
        $this->assertSame(96 * $h, SourceUnavailable::recheckSeconds(5));
        $this->assertSame(168 * $h, SourceUnavailable::recheckSeconds(6));
        $this->assertSame(168 * $h, SourceUnavailable::recheckSeconds(500));
        $this->assertSame(6 * $h, SourceUnavailable::recheckSeconds(0));
    }

    public function testOrderSweep(): void
    {
        // Incoming order (oldest download first): 1..5.
        $fresh = [2 => true, 3 => true, 5 => true];
        $lastRead = [3 => 100, 5 => 200, 4 => 300];

        // Fresh first (5 read latest, then 3, then unread 2), then retries
        // (4 read, then 1), ties keep the incoming order.
        $this->assertSame([5, 3, 2, 4, 1], ChapterScraper::orderSweep([1, 2, 3, 4, 5], $fresh, $lastRead));
        $this->assertSame([1, 2, 3], ChapterScraper::orderSweep([1, 2, 3], [], []));
    }

    public function testContentCircuitSummaryIsPlainAndTakesPrecedence(): void
    {
        $message = ChapterScraper::summarizeScrapeIssue([
            'needs_review' => 3,
            'content_parked' => 1800,
            'content_host' => 'www.novelping.com',
            'content_recheck_at' => '2026-11-14 18:00:00',
        ]);

        $this->assertSame(
            '1800 chapters are listed on novelping.com but not available there (not found or empty). '
            . 'Novarr checks again on 14 Nov 2026 at 18:00.',
            $message
        );
        $this->assertStringStartsWith(
            '1 chapter is listed on the source',
            ChapterScraper::sourceUnavailableMessage(1, null, Carbon::parse('2026-11-14 18:00:00'))
        );
    }

    public function testReasonWords(): void
    {
        $this->assertSame('not found or empty', SourceUnavailable::reasonWords(['not_found' => 2, 'no_content' => 1]));
        $this->assertSame('not found', SourceUnavailable::reasonWords(['not_found' => 2]));
        $this->assertSame('only a stub', SourceUnavailable::reasonWords(['short_content' => 1]));
        $this->assertSame('not found or empty', SourceUnavailable::reasonWords([]));
    }
}
