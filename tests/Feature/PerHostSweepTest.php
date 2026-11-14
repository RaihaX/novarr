<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Setting;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

/**
 * The scheduled novel:chapter sweep with per-host politeness, the shared
 * per-novel lock, host-wide failures and error isolation. The network fetch
 * and the clock are faked by a ChapterScraper subclass: pause() advances the
 * fake clock instead of sleeping, so every request time is exact.
 */
class PerHostSweepTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    /** @var ChapterScraper&object{now: float, fetches: array, pauses: array, responder: ?\Closure, fetchSeconds: float} */
    private $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 12:00:00');

        // A fixed id well away from the real sources: several code paths key
        // on group ids 1, 3 and 6, and on MySQL/MariaDB auto-increment ids
        // keep climbing across tests, so one test would otherwise land on 6.
        $this->group = new Group();
        $this->group->id = 9001;
        $this->group->label = 'Example';
        $this->group->url = 'https://group.test';
        $this->group->save();

        $this->scraper = new class extends ChapterScraper {
            public float $now = 1_000_000.0;
            public array $fetches = [];
            public array $pauses = [];
            public ?\Closure $responder = null;
            public float $fetchSeconds = 0.0;

            protected function generateChapterDescription($chapter, ?string &$failureReason = null)
            {
                $this->fetches[] = [
                    'host' => parse_url(chapterSourceUrl($chapter), PHP_URL_HOST),
                    'at' => $this->now,
                    'id' => $chapter->id,
                    'novel' => $chapter->novel_id,
                ];
                $this->now += $this->fetchSeconds;

                return $this->responder ? ($this->responder)($chapter, $failureReason) : PerHostSweepTest::fullChapter();
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

    private function delays(int $min, int $max): void
    {
        Setting::put('scrape_min_delay', $min);
        Setting::put('scrape_max_delay', $max);
    }

    /** A novel with $count pending chapters on $host; $lastDownload orders the sweep. */
    private function novel(string $name, string $host, int $count, ?string $lastDownload = null): Novel
    {
        $novel = Novel::create(['name' => $name, 'status' => 0, 'group_id' => $this->group->id, 'no_of_chapters' => 100]);

        if ($lastDownload) {
            $done = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1000, 'label' => 'Chapter 1000', 'url' => "https://{$host}/{$novel->id}/done"]);
            $done->status = 1;
            $done->download_date = $lastDownload;
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

    private function fetchesOn(string $host): array
    {
        return array_values(array_filter($this->scraper->fetches, fn($f) => $f['host'] === $host));
    }

    private function assertHostGapsAtLeast(int $min): void
    {
        $byHost = [];
        foreach ($this->scraper->fetches as $fetch) {
            $byHost[$fetch['host']][] = $fetch['at'];
        }

        foreach ($byHost as $host => $times) {
            for ($i = 1; $i < count($times); $i++) {
                $this->assertGreaterThanOrEqual(
                    $min,
                    $times[$i] - $times[$i - 1],
                    "{$host} saw two requests " . ($times[$i] - $times[$i - 1]) . "s apart"
                );
            }
        }
    }

    public function testHostsAreInterleavedAndEachHostKeepsTheMinimumDelay(): void
    {
        $this->delays(30, 30);
        $a = $this->novel('Alpha', 'a.test', 3);
        $b = $this->novel('Beta', 'b.test', 3);
        $this->scraper->fetchSeconds = 5;

        Artisan::call('novel:chapter');

        $this->assertCount(6, $this->scraper->fetches);
        $this->assertHostGapsAtLeast(30);

        // Hosts alternate instead of finishing Alpha first.
        $hosts = array_column($this->scraper->fetches, 'host');
        $this->assertSame(['a.test', 'b.test', 'a.test', 'b.test', 'a.test', 'b.test'], $hosts);

        // The whole run took ~2 delays, not the ~5 a global delay costs.
        $elapsed = end($this->scraper->fetches)['at'] - $this->scraper->fetches[0]['at'];
        $this->assertLessThan(5 * 30, $elapsed);

        foreach ([$a, $b] as $novel) {
            $this->assertSame(0, NovelChapter::where('novel_id', $novel->id)->where('status', 0)->count());
        }
    }

    public function testRandomDelaysNeverBringTwoRequestsToAHostCloserThanTheMinimum(): void
    {
        $this->delays(10, 50);
        foreach (['a.test', 'b.test', 'c.test'] as $i => $host) {
            $this->novel("Novel {$i}", $host, 6);
            $this->novel("Novel {$i}b", $host, 4);
        }
        // Failures in between: the failure pause must not shorten a host's gap.
        $this->scraper->responder = function ($chapter, &$reason) {
            if ($chapter->chapter % 3 === 0) {
                $reason = 'no_content';
                return '';
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');

        $this->assertCount(30, $this->scraper->fetches);
        $this->assertHostGapsAtLeast(10);
        // It only ever sleeps until the earliest host is ready.
        foreach ($this->scraper->pauses as $pause) {
            $this->assertLessThanOrEqual(50, $pause);
        }
    }

    public function testNovelsOnOneHostKeepOldestDownloadFirstOrder(): void
    {
        $this->delays(1, 1);
        $recent = $this->novel('Recent', 'a.test', 2, now()->subHour()->toDateTimeString());
        $old = $this->novel('Old', 'a.test', 2, now()->subMonth()->toDateTimeString());
        $never = $this->novel('Never', 'a.test', 2);

        Artisan::call('novel:chapter');

        $order = array_values(array_unique(array_column($this->scraper->fetches, 'novel')));
        $this->assertSame([$never->id, $old->id, $recent->id], $order);
        // One novel at a time per host: its chapters are not interleaved.
        $this->assertSame(
            [$never->id, $never->id, $old->id, $old->id, $recent->id, $recent->id],
            array_column($this->scraper->fetches, 'novel')
        );
    }

    public function testPerNovelCapStillAppliesInTheSweep(): void
    {
        $this->delays(1, 1);
        Setting::put('max_chapters_per_novel_per_run', 2);
        $novel = $this->novel('Backlog', 'a.test', 5);
        $this->novel('Other', 'b.test', 1);

        Artisan::call('novel:chapter');

        $this->assertCount(2, array_filter($this->scraper->fetches, fn($f) => $f['novel'] === $novel->id));
        $this->assertSame(3, NovelChapter::where('novel_id', $novel->id)->where('status', 0)->count());
    }

    public function testDeadlineStopsTheSweepMidNovel(): void
    {
        $this->delays(30, 30);
        Setting::put('max_run_minutes', 1);
        $first = $this->novel('First', 'a.test', 3);
        $second = $this->novel('Second', 'a.test', 1);
        // 40 s fetch + 30 s delay: the second chapter would start after the 60 s limit.
        $this->scraper->fetchSeconds = 40;

        Artisan::call('novel:chapter');
        $output = Artisan::output();

        $this->assertCount(1, $this->scraper->fetches);
        $this->assertStringContainsString('Run time limit reached mid-novel', $output);
        $this->assertStringContainsString('Run time limit of 1 minutes reached', $output);
        // Untouched chapters keep their place for the next run.
        $this->assertSame(2, NovelChapter::where('novel_id', $first->id)->where('status', 0)->whereNull('last_attempt_at')->count());
        $this->assertSame(1, NovelChapter::where('novel_id', $second->id)->whereNull('last_attempt_at')->count());
        $this->assertSame(0, (int) $first->fresh()->scrape_failures);
    }

    public function testDeadlineStopsTheSweepBetweenNovels(): void
    {
        $this->delays(1, 1);
        Setting::put('max_run_minutes', 1);
        $first = $this->novel('First', 'a.test', 1);
        $second = $this->novel('Second', 'b.test', 1, now()->subDay()->toDateTimeString());
        $this->scraper->fetchSeconds = 70;

        Artisan::call('novel:chapter');
        $output = Artisan::output();

        $this->assertSame([$first->id], array_column($this->scraper->fetches, 'novel'));
        $this->assertStringNotContainsString('mid-novel', $output);
        $this->assertStringContainsString('Run time limit of 1 minutes reached', $output);
        $this->assertSame(1, NovelChapter::where('novel_id', $second->id)->where('status', 0)->whereNull('last_attempt_at')->count());
        $this->assertSame(0, NovelChapter::where('novel_id', $first->id)->where('status', 0)->count());
    }

    public function testCircuitBreakerStopsOneNovelWhileOtherHostsContinue(): void
    {
        $this->delays(1, 1);
        $blocked = $this->novel('Blocked', 'a.test', 6);
        $fine = $this->novel('Fine', 'b.test', 3);
        // fetch_failed, not cloudflare: a Cloudflare challenge cools the host
        // at once, which now ends the novel after one fetch.
        $this->scraper->responder = function ($chapter, &$reason) {
            if (str_contains(chapterSourceUrl($chapter), 'a.test')) {
                $reason = 'fetch_failed';
                return '';
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');

        $this->assertCount(3, $this->fetchesOn('a.test'), 'three blocking failures, then the breaker');
        $this->assertCount(3, $this->fetchesOn('b.test'));
        $this->assertSame(0, NovelChapter::where('novel_id', $fine->id)->where('status', 0)->count());
        $this->assertSame(1, (int) $blocked->fresh()->scrape_failures);
        $this->assertTrue(ChapterScraper::hostCoolingDown('a.test'));
    }

    public function testThreeFetchFailuresOnAHostAcrossNovelsCoolItDown(): void
    {
        $this->delays(1, 1);
        $this->novel('One', 'a.test', 2);
        $this->novel('Two', 'a.test', 2);
        $third = $this->novel('Three', 'a.test', 2);
        $this->scraper->responder = function ($chapter, &$reason) {
            $reason = 'fetch_failed';
            return '';
        };

        Artisan::call('novel:chapter');

        // One: 2 failures, Two: 1 more → host cooldown; Two's 2nd chapter is
        // still tried (cooldown is checked at novel start), Three is skipped.
        $this->assertTrue(ChapterScraper::hostCoolingDown('a.test'));
        $this->assertNotContains($third->id, array_column($this->scraper->fetches, 'novel'));
        $this->assertStringContainsString('cooling down', Artisan::output());
        $this->assertSame(0, (int) $third->fresh()->scrape_failures);
    }

    public function testHostFetchFailuresCountAcrossRunsAndASuccessResetsThem(): void
    {
        $this->delays(1, 1);
        $key = ChapterScraper::hostFailuresKey('a.test');
        $this->scraper->responder = function ($chapter, &$reason) {
            $reason = 'fetch_failed';
            return '';
        };

        $this->novel('One', 'a.test', 2);
        Artisan::call('novel:chapter');
        $this->assertSame(2, (int) Cache::get($key));
        $this->assertFalse(ChapterScraper::hostCoolingDown('a.test'));

        // A success on the host resets the streak.
        $this->scraper->responder = null;
        $this->novel('Two', 'a.test', 1);
        Artisan::call('novel:chapter');
        $this->assertNull(Cache::get($key));

        // Next run: three more in a row → cooldown.
        $this->scraper->responder = function ($chapter, &$reason) {
            $reason = 'fetch_failed';
            return '';
        };
        $this->novel('Three', 'a.test', 3);
        Artisan::call('novel:chapter');
        $this->assertTrue(ChapterScraper::hostCoolingDown('a.test'));
    }

    public function testRateLimitUsesRetryAfterAsTheHostCooldown(): void
    {
        $this->delays(1, 1);
        $this->novel('Limited', 'a.test', 1);

        // A real 429 through Fetcher::plain() records the host's Retry-After.
        $fetcher = new class extends \App\Scraping\Fetcher {
            protected function buildClient(array $options): \Symfony\Contracts\HttpClient\HttpClientInterface
            {
                return new MockHttpClient(new MockResponse('slow down', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]));
            }
        };
        $this->scraper->responder = function ($chapter, &$reason) use ($fetcher) {
            $status = null;
            $fetcher->plain(chapterSourceUrl($chapter), [], [], $status);
            $reason = $status === 429 ? 'rate_limited' : 'fetch_failed';
            return '';
        };

        Artisan::call('novel:chapter');

        $chapter = NovelChapter::where('label', 'Chapter 1')->first();
        $this->assertSame('rate_limited', $chapter->last_failure_reason);
        // Site-wide failure: flat backoff, attempts stay at 1.
        $this->assertSame(1, (int) $chapter->attempts);
        $this->assertEquals(now()->addSeconds(ChapterScraper::BLOCKING_BACKOFF_SECONDS), Carbon::parse($chapter->next_attempt_at));

        $this->assertTrue(ChapterScraper::hostCoolingDown('a.test'));
        Carbon::setTestNow(now()->addSeconds(119));
        $this->assertTrue(ChapterScraper::hostCoolingDown('a.test'));
        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertFalse(ChapterScraper::hostCoolingDown('a.test'));
    }

    public function testRateLimitWithoutRetryAfterUsesTheNormalCooldown(): void
    {
        $this->delays(1, 1);
        $novel = $this->novel('Limited', 'a.test', 1);
        $this->scraper->responder = function ($chapter, &$reason) {
            $reason = 'rate_limited';
            return '';
        };

        Artisan::call('novel:chapter');

        Carbon::setTestNow(now()->addMinutes(ChapterScraper::HOST_COOLDOWN_MINUTES)->subSecond());
        $this->assertTrue(ChapterScraper::hostCoolingDown('a.test'));
        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertFalse(ChapterScraper::hostCoolingDown('a.test'));
        $this->assertSame('the source site is rate-limiting fetches (HTTP 429/503)', $novel->fresh()->last_scrape_issue);
    }

    public function testAnErrorInOneNovelDoesNotStopTheSweep(): void
    {
        $this->delays(1, 1);
        $broken = $this->novel('Broken', 'a.test', 2);
        $fine = $this->novel('Fine', 'a.test', 1, now()->subDay()->toDateTimeString());
        $this->scraper->responder = function ($chapter, &$reason) use ($broken) {
            if ($chapter->novel_id === $broken->id) {
                throw new \TypeError('parser blew up');
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');

        $this->assertStringContainsString('parser blew up', Artisan::output());
        $this->assertSame(0, NovelChapter::where('novel_id', $fine->id)->where('status', 0)->count());

        // Only the chapter being worked is charged, and it backs off.
        $failed = NovelChapter::where('novel_id', $broken->id)->where('chapter', 1)->first();
        $this->assertSame('exception', $failed->last_failure_reason);
        $this->assertTrue($failed->next_attempt_at->isFuture());
        $this->assertNull(NovelChapter::where('novel_id', $broken->id)->where('chapter', 2)->first()->last_attempt_at);
        $this->assertSame(1, (int) $broken->fresh()->scrape_failures);

        // Its lock was released.
        $lock = ChapterScraper::novelLock($broken->id);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function testAnErrorInAnExplicitRunIsRecordedAndReleasesTheLock(): void
    {
        $this->delays(1, 1);
        $novel = $this->novel('Broken', 'a.test', 1);
        $this->scraper->responder = fn() => throw new \RuntimeException('boom');

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame('exception', NovelChapter::where('novel_id', $novel->id)->first()->last_failure_reason);
        $lock = ChapterScraper::novelLock($novel->id);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function testNovelLockedElsewhereIsSkipped(): void
    {
        $this->delays(1, 1);
        $locked = $this->novel('Locked', 'a.test', 1);
        $free = $this->novel('Free', 'a.test', 1, now()->subDay()->toDateTimeString());

        $lock = ChapterScraper::novelLock($locked->id);
        $this->assertTrue($lock->get());
        try {
            Artisan::call('novel:chapter');
            $this->assertStringContainsString('Skipping Locked: another scrape of this novel is running', Artisan::output());

            Artisan::call('novel:chapter', ['novel' => $locked->id]);
            $this->assertStringContainsString('another scrape of this novel is running', Artisan::output());
        } finally {
            $lock->release();
        }

        $this->assertSame([$free->id], array_column($this->scraper->fetches, 'novel'));
        $this->assertSame(0, (int) $locked->fresh()->scrape_failures);

        // Released by the run that held it: the free novel's lock is free again.
        $again = ChapterScraper::novelLock($free->id);
        $this->assertTrue($again->get());
        $again->release();
    }

    public function testNovelLockHelper(): void
    {
        $lock = ChapterScraper::novelLock(42);
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Lock::class, $lock);
        $this->assertTrue($lock->get());
        $this->assertFalse(ChapterScraper::novelLock(42)->get(), 'same key: a second taker is refused');
        $this->assertTrue(ChapterScraper::novelLock(43)->get(), 'per novel');
        $lock->release();
        $this->assertTrue(ChapterScraper::novelLock(42)->get());
    }

    /** A UI job runs the same command: it takes the novel lock, but its own overlap lock is a different key. */
    public function testQueuedJobTakesTheNovelLockWithoutBlockingItself(): void
    {
        $this->delays(1, 1);
        $novel = $this->novel('Queued', 'a.test', 1);

        \App\Jobs\RunNovelCommand::dispatchSync('novel:chapter', ['novel' => $novel->id], 'job-lock');

        $this->assertTrue(Cache::get('command_result_job-lock')['success']);
        $this->assertSame([$novel->id], array_column($this->scraper->fetches, 'novel'));

        // And while the scheduler holds the novel lock, the job skips it.
        $lock = ChapterScraper::novelLock($novel->id);
        $lock->get();
        NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 2, 'label' => 'Chapter 2', 'url' => "https://a.test/{$novel->id}/chapter-2"]);
        try {
            \App\Jobs\RunNovelCommand::dispatchSync('novel:chapter', ['novel' => $novel->id], 'job-lock-2');
        } finally {
            $lock->release();
        }
        $this->assertStringContainsString('another scrape of this novel is running', Cache::get('command_result_job-lock-2')['output']);
        $this->assertCount(1, $this->scraper->fetches);
    }

    public function testExplicitRunKeepsTheSequentialDelay(): void
    {
        $this->delays(30, 30);
        $novel = $this->novel('Explicit', 'a.test', 3);

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame([30, 30, 30], $this->scraper->pauses);
        $this->assertCount(3, $this->scraper->fetches);
    }

    /** An error thrown after the fetch still counts as a request to that host. */
    public function testAnErrorAfterAFetchStillSpacesTheNextRequestToThatHost(): void
    {
        $this->delays(30, 30);
        $a = $this->novel('A', 'a.test', 2);
        $this->novel('B', 'a.test', 1, now()->subDay()->toDateTimeString());
        $this->scraper->responder = function ($chapter, &$reason) use ($a) {
            if ($chapter->novel_id === $a->id && (int) $chapter->chapter === 2) {
                throw new \RuntimeException('save failed after fetch');
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');

        $this->assertCount(3, $this->scraper->fetches);
        $this->assertHostGapsAtLeast(30);
    }

    /** A rate limit mid-novel ends that novel for the run, and no other novel on the host starts. */
    public function testRateLimitMidNovelStopsFetchingFromTheCoolingHost(): void
    {
        $this->delays(30, 30);
        $limited = $this->novel('Limited', 'a.test', 3);
        $other = $this->novel('Other', 'a.test', 1, now()->subDay()->toDateTimeString());
        $elsewhere = $this->novel('Elsewhere', 'b.test', 2);
        $this->scraper->responder = function ($chapter, &$reason) use ($limited) {
            if ($chapter->novel_id === $limited->id) {
                $reason = 'rate_limited';
                return '';
            }
            return self::fullChapter();
        };

        Artisan::call('novel:chapter');

        $this->assertCount(1, $this->fetchesOn('a.test'), 'no request to a.test during its cooldown');
        $this->assertCount(2, $this->fetchesOn('b.test'), 'other hosts carry on');
        $this->assertStringContainsString('Skipping Other', Artisan::output());
        $this->assertNull(NovelChapter::where('novel_id', $limited->id)->where('chapter', 2)->first()->last_attempt_at);

        // Ended like any novel: health recorded, lock released.
        $this->assertSame(1, (int) $limited->fresh()->scrape_failures);
        $this->assertSame(0, (int) $other->fresh()->scrape_failures);
        $lock = ChapterScraper::novelLock($limited->id);
        $this->assertTrue($lock->get());
        $lock->release();
        $this->assertSame(0, NovelChapter::where('novel_id', $elsewhere->id)->where('status', 0)->count());
    }

    /** The explicit run already honours a host cooldown at its start; it honours one set mid-run too. */
    public function testExplicitRunStopsWhenItsHostStartsCoolingDown(): void
    {
        $this->delays(1, 1);
        $novel = $this->novel('Limited', 'a.test', 3);
        $this->scraper->responder = function ($chapter, &$reason) {
            $reason = 'rate_limited';
            return '';
        };

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertCount(1, $this->scraper->fetches);
        $this->assertSame(1, (int) $novel->fresh()->scrape_failures);
        $lock = ChapterScraper::novelLock($novel->id);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    /** A long explicit run renews the novel lock before its TTL runs out. */
    public function testExplicitRunRenewsTheNovelLockPastHalfItsTtl(): void
    {
        $this->delays(1, 1);
        $novel = $this->novel('Long', 'a.test', 3);
        $heldByRun = [];
        $this->scraper->responder = function ($chapter) use (&$heldByRun, $novel) {
            // Is the lock still held for this chapter? (A probe that wins
            // it gives it straight back.)
            $probe = Cache::lock("novel-scrape:{$novel->id}", 10);
            $won = $probe->get();
            if ($won) {
                $probe->release();
            }
            $heldByRun[] = !$won;

            // Each chapter takes 6000 s of both the run's clock and the
            // cache's: two chapters already outlast the 3-hour TTL.
            $this->scraper->now += 6000;
            Carbon::setTestNow(now()->addSeconds(6000));
            return self::fullChapter();
        };

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertCount(3, $this->scraper->fetches);
        $this->assertSame([true, true, true], $heldByRun, 'the lock never lapsed during the run');
        $lock = ChapterScraper::novelLock($novel->id);
        $this->assertTrue($lock->get(), 'released at the end');
        $lock->release();
    }

    /** Someone else took the lock after it lapsed: the explicit run stops instead of fetching alongside. */
    public function testExplicitRunStopsWhenTheLockCannotBeRenewed(): void
    {
        $this->delays(1, 1);
        $novel = $this->novel('Long', 'a.test', 3);
        $other = null;
        $this->scraper->responder = function () use (&$other, $novel) {
            if ($other === null) {
                // The run's lock lapsed and the sweep took it.
                Cache::lock("novel-scrape:{$novel->id}")->forceRelease();
                $other = ChapterScraper::novelLock($novel->id);
                $this->assertTrue($other->get());
            }
            $this->scraper->now += 6000;
            return self::fullChapter();
        };

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertCount(1, $this->scraper->fetches);
        $this->assertStringContainsString('lock', Artisan::output());
        $this->assertFalse(ChapterScraper::novelLock($novel->id)->get(), "the other holder's lock was left alone");
        $this->assertSame(0, (int) $novel->fresh()->scrape_failures);
        $other->release();
    }
}
