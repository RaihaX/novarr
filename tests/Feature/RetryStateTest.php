<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Per-chapter retry state in novel:chapter (audit A7/F27). The network fetch
 * is stubbed by registering a ChapterScraper subclass over the real command.
 */
class RetryStateTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    /** @var ChapterScraper&object{fetched: array, responder: \Closure} */
    private $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 12:00:00');

        $this->group = new Group();
        $this->group->label = 'Example';
        $this->group->url = 'https://example.test';
        $this->group->save();

        $this->scraper = new class extends ChapterScraper {
            public array $fetched = [];
            public ?\Closure $responder = null;

            protected function generateChapterDescription($chapter, ?string &$failureReason = null)
            {
                $this->fetched[] = $chapter->id;
                return ($this->responder)($chapter, $failureReason);
            }

            protected function pause(int $seconds): void
            {
                // no waiting in tests
            }
        };
        $this->app[Kernel::class]->registerCommand($this->scraper);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function respondWith(string $text, ?string $reason = null): void
    {
        $this->scraper->responder = function ($chapter, &$failureReason) use ($text, $reason) {
            $failureReason = $reason;
            return $text;
        };
    }

    private static function fullChapter(): string
    {
        return '<p>' . str_repeat('word ', 400) . '</p>';
    }

    private function novelWith(array $chapters): Novel
    {
        $novel = Novel::create(['name' => 'Retry', 'status' => 0, 'group_id' => $this->group->id, 'no_of_chapters' => 10]);

        foreach ($chapters as $n => $state) {
            $chapter = NovelChapter::create([
                'novel_id' => $novel->id,
                'chapter' => $n,
                'label' => "Chapter {$n}",
                'url' => "https://example.test/retry/chapter-{$n}",
            ]);
            if ($state) {
                $chapter->forceFill($state)->saveQuietly();
            }
        }

        return $novel;
    }

    private function chapter(Novel $novel, int $n): NovelChapter
    {
        return NovelChapter::where('novel_id', $novel->id)->where('chapter', $n)->firstOrFail();
    }

    public function testFailureIncrementsAttemptsAndSchedulesBackoff(): void
    {
        $novel = $this->novelWith([1 => ['attempts' => 2, 'next_attempt_at' => now()->subMinute()]]);
        $this->respondWith('<p>too short</p>');

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $chapter = $this->chapter($novel, 1);
        $this->assertSame(3, (int) $chapter->attempts);
        $this->assertSame('short_content', $chapter->last_failure_reason);
        $this->assertEquals(now()->addMinutes(40), Carbon::parse($chapter->next_attempt_at));
    }

    public function testTransientFailureIsFlatAndNeverPassesOneAttempt(): void
    {
        $novel = $this->novelWith([1 => null, 2 => ['attempts' => 1]]);
        $this->respondWith('', 'fetch_failed');

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        foreach ([1, 2] as $n) {
            $chapter = $this->chapter($novel, $n);
            $this->assertSame(1, (int) $chapter->attempts, "chapter {$n}");
            $this->assertSame('fetch_failed', $chapter->last_failure_reason);
            $this->assertEquals(now()->addMinutes(30), Carbon::parse($chapter->next_attempt_at));
        }
    }

    public function testSuccessResetsRetryState(): void
    {
        $novel = $this->novelWith([1 => [
            'attempts' => 5,
            'next_attempt_at' => now()->subMinute(),
            'last_failure_reason' => 'no_content',
        ]]);
        $this->respondWith(self::fullChapter());

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $chapter = $this->chapter($novel, 1);
        $this->assertTrue((bool) $chapter->status);
        $this->assertSame(0, (int) $chapter->attempts);
        $this->assertNull($chapter->next_attempt_at);
        $this->assertNull($chapter->last_failure_reason);
    }

    public function testOnlyDueChaptersAreFetched(): void
    {
        $novel = $this->novelWith([
            1 => ['attempts' => 1, 'next_attempt_at' => now()->addHour()],   // backing off
            2 => ['attempts' => 1, 'next_attempt_at' => now()->subMinute()], // due again
            3 => null,                                                       // never failed
        ]);
        $this->respondWith(self::fullChapter());

        // The scheduled sweep honours the backoff schedule.
        Artisan::call('novel:chapter');

        $ids = [$this->chapter($novel, 2)->id, $this->chapter($novel, 3)->id];
        sort($ids);
        $fetched = $this->scraper->fetched;
        sort($fetched);
        $this->assertSame($ids, $fetched);
        $this->assertFalse((bool) $this->chapter($novel, 1)->status);
    }

    /** The novel page's Download button is the user asking now: no schedule. */
    public function testExplicitNovelRunIgnoresTheBackoffSchedule(): void
    {
        $novel = $this->novelWith([
            1 => ['attempts' => 1, 'next_attempt_at' => now()->addHour()],
            2 => null,
        ]);
        $this->respondWith(self::fullChapter());

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $ids = [$this->chapter($novel, 1)->id, $this->chapter($novel, 2)->id];
        sort($ids);
        $fetched = $this->scraper->fetched;
        sort($fetched);
        $this->assertSame($ids, $fetched);
    }

    public function testSweepSkipsNovelWithNothingDue(): void
    {
        $novel = $this->novelWith([1 => ['attempts' => 3, 'next_attempt_at' => now()->addHour()]]);
        $this->respondWith(self::fullChapter());

        Artisan::call('novel:chapter');

        $this->assertSame([], $this->scraper->fetched);
        $this->assertStringNotContainsString('Processing: Retry', Artisan::output());
        $this->assertSame(0, (int) $novel->fresh()->scrape_failures);
    }

    public function testSingleChapterIgnoresTheSchedule(): void
    {
        $novel = $this->novelWith([1 => ['attempts' => 4, 'next_attempt_at' => now()->addDay()]]);
        $this->respondWith(self::fullChapter());

        Artisan::call('novel:chapter', ['--chapter' => $this->chapter($novel, 1)->id]);

        $chapter = $this->chapter($novel, 1);
        $this->assertTrue((bool) $chapter->status);
        $this->assertSame(0, (int) $chapter->attempts);
    }

    public function testNeedsReviewChaptersDoNotFlagTheNovel(): void
    {
        // 7 failures so far: this run's failure makes it 8 → needs review.
        $novel = $this->novelWith([1 => ['attempts' => 7, 'next_attempt_at' => now()->subMinute()]]);
        $novel->forceFill(['scrape_failures' => 2, 'last_scrape_issue' => 'old issue'])->saveQuietly();
        $this->respondWith('<p>too short</p>');

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $chapter = $this->chapter($novel, 1);
        $this->assertSame(8, (int) $chapter->attempts);
        // Still scheduled (3-day cap reached after enough doublings).
        $this->assertEquals(now()->addSeconds(ChapterScraper::backoffSeconds(8, 'short_content')), Carbon::parse($chapter->next_attempt_at));
        $this->assertSame(0, (int) $novel->fresh()->scrape_failures);
        $this->assertNull($novel->fresh()->last_scrape_issue);
        $this->assertStringContainsString('needs review', Artisan::output());
    }

    public function testOrdinaryFailuresStillFlagTheNovelAlongsideNeedsReview(): void
    {
        $novel = $this->novelWith([
            1 => ['attempts' => 9, 'next_attempt_at' => now()->subMinute()],
            2 => ['attempts' => 1, 'next_attempt_at' => now()->subMinute()],
        ]);
        $this->respondWith('<p>too short</p>');

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $fresh = $novel->fresh();
        $this->assertSame(1, (int) $fresh->scrape_failures);
        // The summary counts only the ordinary failure.
        $this->assertStringStartsWith('chapter pages only contain 2 words', $fresh->last_scrape_issue);
    }
}
