<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use App\Services\ReadState;
use App\Support\CurrentReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * chapter_reads.bulk: chapters marked read in bulk count in the all-time
 * stats but not as daily activity; single reads still do. The migration's
 * backfill flags groups of 20+ of one reader's reads sharing one second.
 */
class BulkReadStatsTest extends TestCase
{
    use RefreshDatabase;

    private function novel(string $name = 'Bulk'): Novel
    {
        return Novel::create(['name' => $name, 'author' => 'Someone', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);
    }

    /** @return NovelChapter[] */
    private function chapters(Novel $novel, int $count, int $from = 1): array
    {
        $out = [];
        for ($n = $from; $n < $from + $count; $n++) {
            $chapter = NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
                'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/c-{$n}",
            ]);
            $chapter->forceFill(['status' => 1])->save();
            $out[] = $chapter;
        }

        return $out;
    }

    private function bulkOf(NovelChapter $chapter): bool
    {
        return (bool) $this->readStateOf($chapter)?->bulk;
    }

    public function test_bulk_mark_counts_all_time_but_not_today_or_streak(): void
    {
        $novel = $this->novel();
        $this->chapters($novel, 30);

        $this->postJson(route('chapters.bulk_read'), ['read' => true, 'scope' => 'all', 'novel_id' => $novel->id])
            ->assertOk();

        $this->get(route('stats.index'))
            ->assertOk()
            ->assertViewHas('s', fn($s) => $s['headline']['chapters_read'] === 30
                && $s['headline']['chapters_today'] === 0
                && $s['headline']['chapters_week'] === 0
                && $s['headline']['current_streak'] === 0
                && $s['range_totals']['chapters'] === 0
                && $s['top_series'] === [])
            ->assertSee('Chapters marked read in bulk count in the totals but not as activity.');
    }

    public function test_single_reads_still_count_as_activity(): void
    {
        $novel = $this->novel();
        [$opened, $toggled, $finished, $bulk] = $this->chapters($novel, 4);
        $readState = app(ReadState::class);

        $readState->markMany(NovelChapter::whereKey($bulk->id), true, now());
        $readState->markOpened($opened);
        $readState->setRead($toggled, true, now());
        $readState->recordProgress($finished, 100, true, now());

        $this->assertTrue($this->bulkOf($bulk));
        $this->assertFalse($this->bulkOf($opened));
        $this->assertFalse($this->bulkOf($toggled));
        $this->assertFalse($this->bulkOf($finished));

        $this->get(route('stats.index'))
            ->assertOk()
            ->assertViewHas('s', fn($s) => $s['headline']['chapters_read'] === 4
                && $s['headline']['chapters_today'] === 3
                && $s['headline']['current_streak'] === 1);
    }

    public function test_re_marking_a_bulk_row_after_unread_is_a_single_read(): void
    {
        $novel = $this->novel();
        [$a, $b, $c] = $this->chapters($novel, 3);
        $readState = app(ReadState::class);
        $all = fn() => NovelChapter::where('novel_id', $novel->id);

        $readState->markMany($all(), true, now());
        $readState->markMany($all(), false, now());
        $this->assertFalse($this->bulkOf($a), 'unread clears the flag');

        $readState->markMany($all(), true, now());
        $readState->setRead($a, false, now());
        $readState->setRead($a, true, now());
        $this->assertFalse($this->bulkOf($a));

        // Opening / finishing a chapter unread after a bulk mark.
        $readState->setRead($b, false, now());
        $readState->markOpened($b);
        $this->assertFalse($this->bulkOf($b));

        // An explicit "read" on an already bulk-read chapter keeps its date and flag.
        $readState->setRead($c, true, now());
        $this->assertTrue($this->bulkOf($c));
    }

    public function test_backfill_flags_groups_of_twenty_in_one_second_only(): void
    {
        $novel = $this->novel();
        $twenty = $this->chapters($novel, 20, 1);
        $nineteen = $this->chapters($novel, 19, 100);
        [$single] = $this->chapters($novel, 1, 200);
        $owner = CurrentReader::ownerId();

        $at20 = now()->subDays(3)->startOfSecond();
        $at19 = now()->subDays(2)->startOfSecond();
        foreach ($twenty as $chapter) {
            $this->markRead($chapter, null, $at20);
        }
        foreach ($nineteen as $chapter) {
            $this->markRead($chapter, null, $at19);
        }
        $this->markRead($single, null, $at20->copy()->addSecond());
        DB::table('chapter_reads')->update(['bulk' => false]);

        $migration = require database_path('migrations/2026_11_22_000001_add_bulk_to_chapter_reads_table.php');
        $migration->up();
        $migration->up(); // re-runnable

        $flagged = fn(array $chapters) => DB::table('chapter_reads')
            ->where('user_id', $owner)
            ->whereIn('novel_chapter_id', array_map(fn($c) => $c->id, $chapters))
            ->where('bulk', true)
            ->count();

        $this->assertSame(20, $flagged($twenty));
        $this->assertSame(0, $flagged($nineteen));
        $this->assertSame(0, $flagged([$single]));
    }
}
