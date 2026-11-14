<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Scraping\HostPace;
use App\Setting;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The sweep's adaptive host pace, the blocking-only failure pause, the
 * content circuit with group parking and canary re-checks, the pending
 * chapter order and the sweep's novel order. Network and clock are faked as
 * in PerHostSweepTest; HostPace draws the low end of its range.
 */
class AdaptiveSweepTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    private $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 12:00:00');

        // Fixed high id: code paths key on group ids 1, 3 and 6.
        $this->group = new Group();
        $this->group->id = 9002;
        $this->group->label = 'Example';
        $this->group->url = 'https://group.test';
        $this->group->save();

        $this->app->instance(HostPace::class, new HostPace(fn(int $lo, int $hi) => $lo));

        $this->scraper = new class extends ChapterScraper {
            public float $now = 1_000_000.0;
            public array $fetches = [];
            public array $pauses = [];
            public ?\Closure $responder = null;

            protected function generateChapterDescription($chapter, ?string &$failureReason = null)
            {
                $this->fetches[] = [
                    'host' => parse_url(chapterSourceUrl($chapter), PHP_URL_HOST),
                    'at' => $this->now,
                    'id' => $chapter->id,
                    'chapter' => (int) $chapter->chapter,
                    'novel' => $chapter->novel_id,
                ];

                return $this->responder ? ($this->responder)($chapter, $failureReason) : AdaptiveSweepTest::fullChapter();
            }

            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
                $this->now += $seconds;
            }

            protected function clock(): float
            {
                return $this->now;
            }
        };
        $this->app[Kernel::class]->registerCommand($this->scraper);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function fullChapter(): string
    {
        return '<p>' . str_repeat('word ', 400) . '</p>';
    }

    private static function notFound(): \Closure
    {
        return function ($chapter, &$reason) {
            $reason = 'not_found';
            return '';
        };
    }

    private function fixedDelays(int $min, int $max): void
    {
        Setting::put('scrape_adaptive', '0');
        Setting::put('scrape_min_delay', $min);
        Setting::put('scrape_max_delay', $max);
    }

    /** A novel with $count pending chapters on $host. */
    private function novel(string $name, string $host, int $count, ?string $lastDownload = null, ?string $readAt = null): Novel
    {
        $novel = Novel::create(['name' => $name, 'status' => 0, 'group_id' => $this->group->id, 'no_of_chapters' => 100]);

        if ($lastDownload) {
            $done = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1000, 'label' => 'Chapter 1000', 'url' => "https://{$host}/{$novel->id}/done"]);
            $done->status = 1;
            $done->download_date = $lastDownload;
            $done->read_at = $readAt;
            $done->save();
        }

        for ($n = 1; $n <= $count; $n++) {
            NovelChapter::create([
                'novel_id' => $novel->id,
                'chapter' => $n,
                'label' => "Chapter {$n}",
                'url' => "https://{$host}/{$novel->id}/chapter-{$n}",
            ]);
        }

        return $novel;
    }

    /** Mark chapters as previously failed (due now unless $nextAttempt says otherwise). */
    private function failed(Novel $novel, array $numbers, string $reason = 'not_found', int $attempts = 1, ?string $nextAttempt = null, ?string $lastAttempt = null): void
    {
        NovelChapter::where('novel_id', $novel->id)->whereIn('chapter', $numbers)->update([
            'attempts' => $attempts,
            'last_failure_reason' => $reason,
            'next_attempt_at' => $nextAttempt ?? now()->subMinute()->toDateTimeString(),
            'last_attempt_at' => $lastAttempt ?? now()->subDay()->toDateTimeString(),
        ]);
    }

    private function chapter(Novel $novel, int $number): NovelChapter
    {
        return NovelChapter::where('novel_id', $novel->id)->where('chapter', $number)->first();
    }

    // ---------------------------------------------------------------
    // 1. Adaptive pace
    // ---------------------------------------------------------------

    public function testExplicitRunSpeedsUpAfterTenCleanRequests(): void
    {
        Setting::put('scrape_min_delay', 30);
        Setting::put('scrape_max_delay', 90);
        $novel = $this->novel('Fast', 'a.test', 12);

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        // 30 s while the first nine are clean; the 10th clean answer brings
        // the pace to 30 x 0.85 = 25.5 (wait range 26-38).
        $this->assertSame(array_merge(array_fill(0, 9, 30), [26, 26, 26]), $this->scraper->pauses);
    }

    public function testSweepPaceStopsAtTheFastestDelay(): void
    {
        Setting::put('scrape_min_delay', 20);
        Setting::put('scrape_fastest_delay', 18);
        Setting::put('max_chapters_per_novel_per_run', 0);
        $this->novel('Long', 'a.test', 40);

        Artisan::call('novel:chapter');

        $this->assertCount(40, $this->scraper->fetches);
        $this->assertSame(18.0, app(HostPace::class)->state('a.test')['pace']);
        $times = array_column($this->scraper->fetches, 'at');
        $this->assertSame(18.0, $times[39] - $times[38]);
    }

    public function testRateLimitBacksOffAndLearnsAFloor(): void
    {
        Setting::put('scrape_min_delay', 30);
        $this->novel('Limited', 'a.test', 2);
        $this->scraper->responder = function ($chapter, &$reason) {
            $reason = 'rate_limited';
            return '';
        };

        Artisan::call('novel:chapter');

        $state = app(HostPace::class)->state('a.test');
        $this->assertSame(60.0, $state['pace']);
        $this->assertSame(45.0, $state['floor']);
        $this->assertSame(now()->addDays(7)->getTimestamp(), $state['floor_until']);
        $this->assertSame('rate_limited', $state['last_slowdown_reason']);
        // The existing host cooldown still applies alongside.
        $this->assertTrue(ChapterScraper::hostCoolingDown('a.test'));
    }

    public function testContentAnswersCountAsCleanForThePace(): void
    {
        Setting::put('scrape_min_delay', 30);
        $novel = $this->novel('Mixed', 'a.test', 12);
        // Every third chapter is a 404: never three in a row.
        $this->scraper->responder = function ($chapter, &$reason) {
            if ((int) $chapter->chapter % 3 === 0) {
                $reason = 'not_found';
                return '';
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertCount(12, $this->scraper->fetches);
        $this->assertEqualsWithDelta(25.5, app(HostPace::class)->state('a.test')['pace'], 1e-9);
    }

    // ---------------------------------------------------------------
    // 2. Failure pause only after blocking failures
    // ---------------------------------------------------------------

    public function testAContentFailureDoesNotDelayOtherHosts(): void
    {
        $this->fixedDelays(20, 20);
        $this->novel('Dead', 'a.test', 1);
        $this->novel('Fine', 'b.test', 1, now()->subDay()->toDateTimeString());
        $this->scraper->responder = function ($chapter, &$reason) {
            if (str_contains(chapterSourceUrl($chapter), 'a.test')) {
                $reason = 'not_found';
                return '';
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');

        $this->assertSame([1_000_000.0, 1_000_000.0], array_column($this->scraper->fetches, 'at'));
    }

    public function testABlockingFailureStillPausesOtherHosts(): void
    {
        $this->fixedDelays(20, 20);
        $this->novel('Broken', 'a.test', 1);
        $this->novel('Fine', 'b.test', 1, now()->subDay()->toDateTimeString());
        $this->scraper->responder = function ($chapter, &$reason) {
            if (str_contains(chapterSourceUrl($chapter), 'a.test')) {
                $reason = 'fetch_failed';
                return '';
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');

        // min(20, FAILURE_DELAY_CAP) = 10 s.
        $this->assertSame([1_000_000.0, 1_000_010.0], array_column($this->scraper->fetches, 'at'));
    }

    // ---------------------------------------------------------------
    // 3./4. Content circuit and group parking
    // ---------------------------------------------------------------

    public function testThreeContentFailuresEndTheNovelWithoutCoolingTheHost(): void
    {
        $this->fixedDelays(1, 1);
        $dead = $this->novel('Dead', 'a.test', 6);
        $other = $this->novel('Other', 'a.test', 2, now()->subDay()->toDateTimeString());
        $this->scraper->responder = function ($chapter, &$reason) use ($dead) {
            if ($chapter->novel_id === $dead->id) {
                $reason = (int) $chapter->chapter === 2 ? 'no_content' : 'not_found';
                return '';
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');
        $output = Artisan::output();

        $deadFetches = array_filter($this->scraper->fetches, fn($f) => $f['novel'] === $dead->id);
        $this->assertCount(3, $deadFetches);
        $this->assertSame(0, NovelChapter::where('novel_id', $other->id)->where('status', 0)->count(), 'the host keeps serving other novels');
        $this->assertFalse(ChapterScraper::hostCoolingDown('a.test'));
        $this->assertNull(Cache::get(ChapterScraper::hostFailuresKey('a.test')));
        $this->assertStringContainsString('not available on the source', $output);

        $dead->refresh();
        $this->assertSame(1, $dead->source_fail_streak);
        $this->assertTrue($dead->source_recheck_at->equalTo(now()->addHours(6)));
        // Not a failed run: no streak toward the "failed 3 runs" alert.
        $this->assertSame(0, $dead->scrape_failures);
        $this->assertSame(
            '3 chapters are listed on a.test but not available there (not found or empty). Novarr checks again on 14 Nov 2026 at 18:00.',
            $dead->last_scrape_issue
        );
        $this->assertTrue(Cache::has("scrape:source-unavailable-notified:{$dead->id}"));

        // Chapters 1-3 parked; 4-6 untried and untouched.
        foreach ([1, 2, 3] as $n) {
            $this->assertTrue($this->chapter($dead, $n)->next_attempt_at->equalTo(now()->addHours(6)), "chapter {$n}");
            $this->assertSame(1, (int) $this->chapter($dead, $n)->attempts);
        }
        foreach ([4, 5, 6] as $n) {
            $this->assertNull($this->chapter($dead, $n)->next_attempt_at);
            $this->assertNull($this->chapter($dead, $n)->last_attempt_at);
        }
    }

    public function testBlockingFailuresBetweenContentFailuresResetTheContentCount(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Flaky', 'a.test', 6);
        $reasons = [1 => 'not_found', 2 => 'not_found', 3 => 'fetch_failed', 4 => 'not_found', 5 => 'not_found', 6 => 'not_found'];
        $this->scraper->responder = function ($chapter, &$reason) use ($reasons) {
            $reason = $reasons[(int) $chapter->chapter];
            return '';
        };

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        // 1, 2 (content), 3 (blocking: resets), 4, 5, 6 (content: trips at 6).
        $this->assertCount(6, $this->scraper->fetches);
        $this->assertSame(1, $novel->fresh()->source_fail_streak);
    }

    public function testParkingIsOneBulkUpdateOfTheContentFailedGroup(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Group', 'a.test', 9);
        // 1-3 fresh (tried first); 4-5 due content retries; 6 a content
        // failure already parked further out; 7 blacklisted; 8 a blocking
        // failure; 9 a stub.
        $this->failed($novel, [4, 5], 'not_found', 4);
        $this->failed($novel, [6], 'no_content', 2, now()->addDays(10)->toDateTimeString());
        $this->failed($novel, [7], 'not_found', 2);
        NovelChapter::where('novel_id', $novel->id)->where('chapter', 7)->update(['blacklist' => 1]);
        $this->failed($novel, [8], 'fetch_failed', 1, now()->addMinutes(5)->toDateTimeString());
        $this->failed($novel, [9], 'short_content', 3, now()->addMinutes(5)->toDateTimeString());
        $this->scraper->responder = self::notFound();

        Artisan::call('novel:chapter');

        $this->assertSame([1, 2, 3], array_column($this->scraper->fetches, 'chapter'), 'fresh chapters first');
        $recheck = now()->addHours(6);
        foreach ([1, 2, 3, 4, 5, 9] as $n) {
            $this->assertTrue($this->chapter($novel, $n)->next_attempt_at->equalTo($recheck), "chapter {$n} parked");
        }
        $this->assertSame(4, (int) $this->chapter($novel, 4)->attempts, 'attempts not bumped');
        $this->assertSame(3, (int) $this->chapter($novel, 9)->attempts);
        $this->assertTrue($this->chapter($novel, 6)->next_attempt_at->equalTo(now()->addDays(10)), 'a later retry is never shortened');
        $this->assertFalse($this->chapter($novel, 7)->next_attempt_at->equalTo($recheck), 'blacklisted untouched');
        $this->assertTrue($this->chapter($novel, 8)->next_attempt_at->equalTo(now()->addMinutes(5)), 'blocking failure untouched');
        $this->assertStringStartsWith('7 chapters are listed on a.test', $novel->fresh()->last_scrape_issue);
    }

    public function testCanaryReCheckParksAgainForLonger(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Dead', 'a.test', 5);
        $this->failed($novel, [1, 2, 3, 4, 5], 'not_found', 2);
        $this->scraper->responder = self::notFound();

        Artisan::call('novel:chapter');
        $this->assertCount(3, $this->scraper->fetches);

        // Nothing is due until the re-check.
        Artisan::call('novel:chapter');
        $this->assertCount(3, $this->scraper->fetches);

        Carbon::setTestNow(now()->addHours(6)->addSecond());
        Artisan::call('novel:chapter');

        $this->assertCount(6, $this->scraper->fetches, 'the canary costs at most three requests');
        $novel->refresh();
        $this->assertSame(2, $novel->source_fail_streak);
        $this->assertTrue($novel->source_recheck_at->equalTo(now()->addHours(12)));
        $this->assertSame(0, NovelChapter::where('novel_id', $novel->id)->where('next_attempt_at', '<', now()->addHours(12))->count());
        $this->assertSame(0, $novel->scrape_failures);
    }

    public function testASuccessReleasesTheParkedChapters(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Back', 'a.test', 5);
        $this->failed($novel, [1, 2, 3, 4, 5], 'not_found', 2);
        $this->scraper->responder = self::notFound();
        Artisan::call('novel:chapter');
        $this->assertSame(1, $novel->fresh()->source_fail_streak);

        // At the re-check the first canary chapter is back.
        Carbon::setTestNow(now()->addHours(6)->addSecond());
        Setting::put('max_chapters_per_novel_per_run', 1);
        $this->scraper->responder = null;
        Artisan::call('novel:chapter');

        $novel->refresh();
        $this->assertSame(0, $novel->source_fail_streak);
        $this->assertNull($novel->source_recheck_at);
        $this->assertNull($novel->last_scrape_issue);
        $this->assertSame(1, NovelChapter::where('novel_id', $novel->id)->where('status', 1)->count());
        $this->assertSame(4, NovelChapter::where('novel_id', $novel->id)->where('status', 0)->whereNull('next_attempt_at')->count(), 'released: due now');
    }

    public function testAFreshChapterDownloadingLeavesTheDeadOnesParked(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Mixed', 'a.test', 6);
        $this->failed($novel, [1, 2, 3, 4, 5], 'not_found', 2);
        $this->scraper->responder = self::notFound();
        Setting::put('max_chapters_per_novel_per_run', 3);
        Artisan::call('novel:chapter');
        // Chapter 6 was fresh: it went first and failed too, then two dead ones.
        $this->assertSame(1, $novel->fresh()->source_fail_streak);

        // A new chapter appears and downloads while the rest stay parked.
        $new = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 7, 'book' => 0, 'label' => 'Chapter 7', 'url' => 'https://a.test/mixed/chapter-7']);
        $this->scraper->responder = null;
        Artisan::call('novel:chapter');

        $this->assertSame(1, (int) $new->fresh()->status);
        $this->assertSame(1, $novel->fresh()->source_fail_streak, 'still parked');
        $this->assertSame(0, NovelChapter::where('novel_id', $novel->id)->where('status', 0)->whereNull('next_attempt_at')->count());
    }

    public function testExplicitRunIgnoresParkingAndAlsoTrips(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Dead', 'a.test', 5);
        $this->failed($novel, [1, 2, 3, 4, 5], 'not_found', 2);
        $this->scraper->responder = self::notFound();

        Artisan::call('novel:chapter', ['novel' => $novel->id]);
        $this->assertCount(3, $this->scraper->fetches, 'the explicit run is cut short too');
        $this->assertSame(1, $novel->fresh()->source_fail_streak);
        $this->assertSame([], array_slice($this->scraper->pauses, 2), 'no pause once the circuit opened');

        // The Download button fetches parked chapters anyway.
        $this->scraper->responder = null;
        Artisan::call('novel:chapter', ['novel' => $novel->id]);
        $this->assertSame(0, NovelChapter::where('novel_id', $novel->id)->where('status', 0)->count());
        $this->assertSame(0, $novel->fresh()->source_fail_streak);
    }

    public function testSingleChapterDownloadIsUnaffected(): void
    {
        $novel = $this->novel('One', 'a.test', 3);
        $this->scraper->responder = self::notFound();

        foreach ([1, 2, 3] as $n) {
            Artisan::call('novel:chapter', ['--chapter' => $this->chapter($novel, $n)->id]);
        }

        $this->assertSame(0, (int) $novel->fresh()->source_fail_streak);
    }

    // ---------------------------------------------------------------
    // Fetch order and sweep order
    // ---------------------------------------------------------------

    public function testNeverFailedChaptersAreFetchedFirstInChapterOrder(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Order', 'a.test', 6);
        $this->failed($novel, [1], 'not_found', 2, null, now()->subHours(1)->toDateTimeString());
        $this->failed($novel, [2], 'not_found', 2, null, now()->subHours(3)->toDateTimeString());
        $this->failed($novel, [3], 'not_found', 2, null, now()->subHours(2)->toDateTimeString());
        // A never-failed chapter that was stamped but not finished still
        // counts as fresh.
        NovelChapter::where('novel_id', $novel->id)->where('chapter', 6)->update(['last_attempt_at' => now()->subMinute()]);

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame([4, 5, 6, 2, 3, 1], array_column($this->scraper->fetches, 'chapter'));
    }

    public function testSweepOrderPutsFreshThenRecentlyReadNovelsFirst(): void
    {
        $this->fixedDelays(1, 1);
        // Old order (oldest download first): Retry, Stale, Unread, Week, Hour.
        $retry = $this->novel('Retry', 'a.test', 1);
        $this->failed($retry, [1]);
        $stale = $this->novel('Stale', 'a.test', 1, now()->subMonths(2)->toDateTimeString(), now()->subDays(40)->toDateTimeString());
        $unread = $this->novel('Unread', 'a.test', 1, now()->subMonth()->toDateTimeString());
        $week = $this->novel('Week', 'a.test', 1, now()->subWeek()->toDateTimeString(), now()->subDay()->toDateTimeString());
        $hour = $this->novel('Hour', 'a.test', 1, now()->subHour()->toDateTimeString(), now()->subHours(2)->toDateTimeString());
        // A reading retry-only novel stays behind every fresh novel.
        $readRetry = $this->novel('ReadRetry', 'a.test', 1, now()->subYear()->toDateTimeString(), now()->subMinute()->toDateTimeString());
        $this->failed($readRetry, [1]);

        Artisan::call('novel:chapter');

        $this->assertSame(
            [$hour->id, $week->id, $stale->id, $unread->id, $readRetry->id, $retry->id],
            array_column($this->scraper->fetches, 'novel')
        );
    }

    // ---------------------------------------------------------------
    // Surfacing
    // ---------------------------------------------------------------

    public function testHealthPageShowsPaceAndParkedNovels(): void
    {
        Setting::put('scrape_min_delay', 30);
        $novel = $this->novel('Dead Novel', 'a.test', 3);
        $this->scraper->responder = self::notFound();
        Artisan::call('novel:chapter');

        $this->get('/health')
            ->assertOk()
            ->assertSee('Download pace')
            ->assertSee('a.test')
            ->assertSee('30–45 s')
            ->assertSee('Not available on the source')
            ->assertSee('Dead Novel')
            ->assertSee(route('novels.show', $novel->id), false)
            ->assertSee('14 Nov 2026 18:00');
    }

    public function testParkedChaptersAreNotReportedAsStalled(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Dead', 'a.test', 3, now()->subMonth()->toDateTimeString());
        $this->scraper->responder = self::notFound();
        Artisan::call('novel:chapter');

        $ids = array_column(app(\App\Services\NovelHealth::class)->needingAttention(), 'id');
        $this->assertNotContains($novel->id, $ids);
    }

    public function testSourceUnavailablePartial(): void
    {
        $this->fixedDelays(1, 1);
        $novel = $this->novel('Dead', 'a.test', 3);
        $fine = $this->novel('Fine', 'a.test', 1);
        $this->assertSame('', trim(view('partials.source-unavailable', ['novel' => $novel])->render()));

        $this->scraper->responder = function ($chapter, &$reason) use ($novel) {
            if ($chapter->novel_id !== $novel->id) {
                return self::fullChapter();
            }
            $reason = 'not_found';
            return '';
        };
        Artisan::call('novel:chapter');

        $html = view('partials.source-unavailable', ['novel' => $novel->fresh()])->render();
        $this->assertStringContainsString('3 chapters are', $html);
        $this->assertStringContainsString('(not found)', $html);
        $this->assertStringContainsString('14 Nov 2026 at 18:00', $html);
        $this->assertSame('', trim(view('partials.source-unavailable', ['novel' => $fine->fresh()])->render()));
    }

    public function testSettingsSaveTheAdaptiveFields(): void
    {
        $base = ['scrape_min_delay' => '30', 'scrape_max_delay' => '90'];

        $this->post('/settings', $base + ['scrape_fastest_delay' => '2', 'scrape_adaptive' => '1'])
            ->assertSessionHasErrors('scrape_fastest_delay');

        $this->post('/settings', $base + ['scrape_fastest_delay' => '3', 'scrape_adaptive' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('3', (string) setting('scrape_fastest_delay'));
        $this->assertTrue(HostPace::adaptive());

        $this->post('/settings', $base + ['scrape_fastest_delay' => '20'])->assertSessionHasNoErrors();
        $this->assertFalse(HostPace::adaptive());

        $this->get('/settings')->assertOk()->assertSee('Fastest delay (seconds)')->assertSee('Adaptive download pace');
    }
}
