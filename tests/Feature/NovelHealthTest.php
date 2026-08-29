<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Needs Attention" list shared by the dashboard and the daily email.
 */
class NovelHealthTest extends TestCase
{
    use RefreshDatabase;

    /** A novel with one pending, never-downloaded chapter. */
    private function novelWithPendingChapter(string $name, Carbon $createdAt, int $failures = 0, ?string $issue = null): Novel
    {
        $novel = Novel::create([
            'name' => $name,
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
        ]);

        $novel->created_at = $createdAt;
        $novel->scrape_failures = $failures;
        $novel->last_scrape_issue = $issue;
        $novel->save();

        NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => 1,
            'book' => 0,
            'label' => 'Chapter 1',
            'url' => 'https://example.test/novel/chapter-1',
        ]);

        return $novel;
    }

    /** @return array<string, string> name => reason */
    private function reasons(): array
    {
        $rows = (new NovelHealth())->needingAttention();

        return array_combine(
            array_column($rows, 'name'),
            array_column($rows, 'reason')
        );
    }

    /** A novel added yesterday is just queued behind the backlog, not stalled. */
    public function testNewNovelWithinGracePeriodIsNotFlagged()
    {
        $this->novelWithPendingChapter('Brand New', Carbon::now()->subDay());

        $this->assertSame([], $this->reasons());
    }

    /** Past the grace period, a novel that never downloaded anything is stalled. */
    public function testNovelOlderThanGracePeriodIsFlagged()
    {
        $this->novelWithPendingChapter('Old And Stuck', Carbon::now()->subDays(10));

        $this->assertStringContainsString(
            'no successful download since ever',
            $this->reasons()['Old And Stuck'] ?? ''
        );
    }

    /** A brand-new novel whose scrape has already failed is not given the grace period. */
    public function testNewNovelWithFailedRunsIsStillFlagged()
    {
        $this->novelWithPendingChapter('New But Failing', Carbon::now()->subDay(), 1);

        $this->assertArrayHasKey('New But Failing', $this->reasons());
    }

    /** The recorded cause replaces the old generic guess. */
    public function testFailingNovelReportsRecordedIssue()
    {
        $this->novelWithPendingChapter(
            'Dead Source',
            Carbon::now()->subDays(30),
            4,
            'chapter pages load but contain no chapter text'
        );

        $this->assertSame(
            '4 consecutive scrape runs failed — chapter pages load but contain no chapter text',
            $this->reasons()['Dead Source'] ?? ''
        );
    }

    /**
     * The failure counter goes stale: once every pending chapter is resolved
     * there is nothing left failing, so the novel must drop off the list.
     */
    public function testFailingNovelWithNoPendingChaptersIsNotFlagged()
    {
        $novel = $this->novelWithPendingChapter('Caught Up', Carbon::now()->subDays(30), 5);

        NovelChapter::where('novel_id', $novel->id)->update([
            'status' => 1,
            'download_date' => Carbon::now(),
        ]);

        $this->assertSame([], $this->reasons());
    }

    /** Without a recorded cause the wording falls back to the old sentence. */
    public function testFailingNovelWithoutIssueFallsBack()
    {
        $this->novelWithPendingChapter('Unknown Cause', Carbon::now()->subDays(30), 3);

        $this->assertSame(
            '3 consecutive scrape runs failed — the source site may have changed',
            $this->reasons()['Unknown Cause'] ?? ''
        );
    }
}
