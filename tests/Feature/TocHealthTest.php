<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Services\NovelHealth;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * TOC health tracking (audit F26/A13): per-run health on the novel, the
 * needs-attention branch, last-seen stamping and parking of pending rows
 * the source no longer lists.
 */
class TocHealthTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://example.test/novel/';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function novel(array $attributes = []): Novel
    {
        $group = new Group();
        $group->label = 'Example';
        $group->url = 'https://example.test';
        $group->save();

        $novel = Novel::create([
            'name' => 'Test Novel',
            'status' => 0,
            'group_id' => $group->id,
            'no_of_chapters' => 0,
            'translator_url' => 'https://example.test/novel',
        ]);
        $novel->forceFill($attributes)->save();

        return $novel->fresh();
    }

    private function scraper(): NovelScraper
    {
        $command = new NovelScraper();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        return $command;
    }

    /** @return array<int, array> chapters $from..$to */
    private function chapters(int $from, int $to): array
    {
        return array_map(fn($n) => [
            'chapter' => $n,
            'label' => "Chapter {$n}",
            'url' => self::BASE . "chapter-{$n}",
        ], range($from, $to));
    }

    public function testHealthyRunRecordsCountAndResetsFailures(): void
    {
        $novel = $this->novel(['toc_failures' => 3, 'last_toc_issue' => 'TOC returned 0 entries', 'last_toc_count' => 100]);

        $this->assertTrue($this->scraper()->recordTocHealth($novel, 98));

        $novel->refresh();
        $this->assertNotNull($novel->last_toc_at);
        $this->assertSame(98, $novel->last_toc_count);
        $this->assertSame(0, $novel->toc_failures);
        $this->assertNull($novel->last_toc_issue, 'a 2% drop is within tolerance');
    }

    public function testEmptyTocIsAFailureAndKeepsTheLastCount(): void
    {
        $novel = $this->novel(['last_toc_count' => 100]);

        $this->assertFalse($this->scraper()->recordTocHealth($novel, 0));
        $this->assertFalse($this->scraper()->recordTocHealth($novel, 0));

        $novel->refresh();
        $this->assertSame(2, $novel->toc_failures);
        $this->assertSame('TOC returned 0 entries', $novel->last_toc_issue);
        $this->assertSame(100, $novel->last_toc_count);
        $this->assertNotNull($novel->last_toc_at);
    }

    public function testShrinkOverFivePercentIsAFailure(): void
    {
        $novel = $this->novel(['last_toc_count' => 100]);

        $this->assertFalse($this->scraper()->recordTocHealth($novel, 90));
        $novel->refresh();
        $this->assertSame(1, $novel->toc_failures);
        $this->assertSame('TOC shrank from 100 to 90', $novel->last_toc_issue);

        // The baseline stays at the last healthy count: a persistent shrink keeps failing.
        $this->assertFalse($this->scraper()->recordTocHealth($novel, 90));
        $this->assertSame(2, $novel->fresh()->toc_failures);

        // Recovery resets.
        $this->assertTrue($this->scraper()->recordTocHealth($novel, 101));
        $novel->refresh();
        $this->assertSame(0, $novel->toc_failures);
        $this->assertSame(101, $novel->last_toc_count);
    }

    public function testPartialRunIsAFailure(): void
    {
        $novel = $this->novel(['last_toc_count' => 40]);

        $this->assertFalse($this->scraper()->recordTocHealth($novel, 43, true));

        $novel->refresh();
        $this->assertSame(1, $novel->toc_failures);
        $this->assertStringContainsString('partial', $novel->last_toc_issue);
        $this->assertSame(40, $novel->last_toc_count);
    }

    public function testSourceCountAboveTocIsAnIssueButNotAFailure(): void
    {
        $novel = $this->novel(['no_of_chapters' => 843, 'toc_failures' => 1]);

        $this->assertTrue($this->scraper()->recordTocHealth($novel, 103));

        $novel->refresh();
        $this->assertSame('source lists 843 chapters but TOC has 103', $novel->last_toc_issue);
        $this->assertSame(0, $novel->toc_failures);
        $this->assertSame(103, $novel->last_toc_count);

        // Within 10%: no issue.
        $novel->forceFill(['no_of_chapters' => 110])->save();
        $this->scraper()->recordTocHealth($novel, 103);
        $this->assertNull($novel->fresh()->last_toc_issue);
    }

    public function testNeedsAttentionListsRepeatedTocFailures(): void
    {
        $failing = $this->novel(['name' => 'Broken TOC', 'toc_failures' => 2, 'last_toc_issue' => 'TOC shrank from 100 to 10']);
        $this->novel(['name' => 'One Blip', 'toc_failures' => 1, 'last_toc_issue' => 'TOC returned 0 entries']);
        $this->novel(['name' => 'Snoozed', 'toc_failures' => 5, 'attention_ignored_until' => Carbon::now()->addDays(3)]);
        $this->novel(['name' => 'Paused', 'toc_failures' => 5, 'paused_at' => Carbon::now()]);
        $this->novel(['name' => 'Done', 'toc_failures' => 5, 'status' => 1]);

        $rows = (new NovelHealth())->needingAttention();

        $this->assertSame([[
            'id' => $failing->id,
            'name' => 'Broken TOC',
            'reason' => 'TOC shrank from 100 to 10',
            'url' => 'https://example.test/novel',
        ]], $rows, 'no pending chapters needed; snoozed / paused / finished novels left out');
    }

    public function testSyncStampsRowsSeenInTheToc(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(1, 3));
        $this->assertSame(3, NovelChapter::where('last_seen_in_toc_at', '2026-10-01 10:00:00')->count(), 'new rows stamped');

        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->scraper()->syncTableOfContents($novel, $this->chapters(2, 4));

        $seen = NovelChapter::orderBy('id')->pluck('last_seen_in_toc_at', 'url')
            ->map(fn($v) => (string) $v)->all();
        $this->assertSame([
            self::BASE . 'chapter-1' => '2026-10-01 10:00:00', // no longer listed
            self::BASE . 'chapter-2' => '2026-10-02 10:00:00',
            self::BASE . 'chapter-3' => '2026-10-02 10:00:00',
            self::BASE . 'chapter-4' => '2026-10-02 10:00:00',
        ], $seen);
    }

    public function testPendingRowsMissingForThreeDaysAreParked(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(1, 4));
        NovelChapter::where('url', self::BASE . 'chapter-2')->update(['status' => 1]); // downloaded

        // Chapters 1 and 2 vanish from the source; runs continue for 4 days.
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->scraper()->syncTableOfContents($novel, $this->chapters(3, 4));
        $this->assertSame(0, $this->scraper()->markStaleRows($novel), 'only 2 days missing');

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->scraper()->syncTableOfContents($novel, $this->chapters(3, 4));
        $this->assertSame(1, $this->scraper()->markStaleRows($novel));

        $parked = NovelChapter::where('url', self::BASE . 'chapter-1')->first();
        $this->assertSame(NovelScraper::SOURCE_MISSING, $parked->last_failure_reason);
        $this->assertSame('2026-10-08 10:00:00', (string) $parked->next_attempt_at);
        $this->assertNull(NovelChapter::where('url', self::BASE . 'chapter-2')->value('last_failure_reason'), 'downloaded rows untouched');
        $this->assertNull(NovelChapter::where('url', self::BASE . 'chapter-3')->value('last_failure_reason'));

        // Not due for the sweep any more.
        $this->assertSame(0, NovelChapter::where('id', $parked->id)->due()->count());

        // The source lists it again: released back to the sweep.
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->scraper()->syncTableOfContents($novel, $this->chapters(1, 4));
        $parked->refresh();
        $this->assertNull($parked->last_failure_reason);
        $this->assertNull($parked->next_attempt_at);
    }

    public function testRowsNeverStampedAreNotParked(): void
    {
        $novel = $this->novel();
        NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'book' => 0, 'label' => 'Chapter 1', 'url' => self::BASE . 'manual']);

        $this->assertSame(0, $this->scraper()->markStaleRows($novel));
    }

    /**
     * End to end through novel:toc: an Empire Novel walk that stops early
     * is recorded as partial, and an unhealthy run parks nothing.
     */
    public function testPartialEmpireNovelRunIsRecordedAndParksNothing(): void
    {
        $url = 'https://www.empirenovel.com/novel/taming-the-villainesses';
        // Page 12's pagination served as page 1 → walk 2..11; page 3+ fail.
        app()->instance(Fetcher::class, new FakeFetcher([
            $url . '?page=1' => 'empirenovel/toc-taming-the-villainesses-page-12.html',
            $url . '?page=2' => 'empirenovel/toc-taming-the-villainesses-page-2.html',
        ], [
            $url . '?page=' => FakeFetcher::fail('http_4xx', 403),
        ]));

        $novel = $this->novel(['translator_url' => $url, 'last_toc_count' => 40]);
        Group::whereKey($novel->group_id)->update(['url' => 'https://www.empirenovel.com']);

        $stale = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 9999, 'book' => 0, 'label' => 'Chapter 9999', 'url' => $url . '/999999']);
        NovelChapter::whereKey($stale->id)->update(['last_seen_in_toc_at' => Carbon::now()->subDays(10)]);

        $this->artisan('novel:toc', ['novel' => $novel->id])->assertExitCode(0);

        $novel->refresh();
        $this->assertSame(1, $novel->toc_failures);
        $this->assertStringContainsString('partial', $novel->last_toc_issue);
        $this->assertSame(40, $novel->last_toc_count);
        $this->assertSame(43, NovelChapter::where('novel_id', $novel->id)->whereNotNull('last_seen_in_toc_at')
            ->where('last_seen_in_toc_at', '>', Carbon::now()->subMinute())->count());
        $this->assertNull($stale->fresh()->last_failure_reason, 'unhealthy run parks nothing');
    }
}
