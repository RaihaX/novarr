<?php

namespace Tests\Unit;

use App\Services\Requests\CandidateRanking;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The manga shortlist ranking (App\Services\Requests\CandidateRanking):
 * chapters in the preferred language, then recency, then gaps, then the
 * preferred source order; failed evaluations last. Pure.
 */
class CandidateRankingTest extends TestCase
{
    private function candidate(string $source, ?int $chapters, ?string $updated = null, ?int $gaps = 0, ?string $error = null): array
    {
        return ['source' => $source, 'chapters_available' => $chapters, 'updated_at' => $updated, 'gaps' => $gaps, 'error' => $error];
    }

    public function test_gaps_count_missing_whole_chapters_between_the_first_and_the_highest(): void
    {
        $this->assertSame(0, CandidateRanking::gaps([]));
        $this->assertSame(0, CandidateRanking::gaps(['7']));
        $this->assertSame(0, CandidateRanking::gaps(['1', '2', '3']));
        $this->assertSame(1, CandidateRanking::gaps(['1', '2', '4']));
        $this->assertSame(3, CandidateRanking::gaps(['1', '5']));
        // Half chapters count as their whole chapter; duplicates once.
        $this->assertSame(0, CandidateRanking::gaps(['1', '1.5', '2', '2', '3']));
        $this->assertSame(0, CandidateRanking::gaps(['1', '2.5', '3.1']));
        $this->assertSame(1, CandidateRanking::gaps(['1', '3.5']));
        // Starting late is not a gap; the range starts at the first chapter.
        $this->assertSame(0, CandidateRanking::gaps(['100', '101', '102']));
        // A prologue at 0 joins the range.
        $this->assertSame(0, CandidateRanking::gaps(['0', '1', '2']));
        $this->assertSame(1, CandidateRanking::gaps(['0', '2']));
        // Junk and negatives are ignored.
        $this->assertSame(0, CandidateRanking::gaps(['x', '-1', '4', '5']));
        // A huge highest number is counted without walking the range.
        $this->assertSame(999998, CandidateRanking::gaps(['1', '1000000']));
    }

    public function test_highest_chapter(): void
    {
        $this->assertNull(CandidateRanking::highest([]));
        $this->assertNull(CandidateRanking::highest(['x']));
        $this->assertSame('212', CandidateRanking::highest(['1', '212', '200.5']));
        $this->assertSame('212.5', CandidateRanking::highest(['212.5', '3']));
        $this->assertSame('0', CandidateRanking::highest(['0']));
    }

    public function test_most_chapters_in_the_preferred_language_wins_first(): void
    {
        $ranked = CandidateRanking::rank([
            $this->candidate('a', 50, '2026-11-18T00:00:00Z', 0),
            $this->candidate('b', 212, '2025-01-01T00:00:00Z', 9),
        ], ['a' => 0, 'b' => 1]);

        $this->assertSame(['b', 'a'], array_column($ranked, 'source'));
    }

    public function test_then_the_most_recently_updated_before_fewer_gaps(): void
    {
        $ranked = CandidateRanking::rank([
            $this->candidate('old-no-gaps', 100, '2026-01-01T00:00:00Z', 0),
            $this->candidate('new-gaps', 100, '2026-11-01T00:00:00Z', 5),
            $this->candidate('unknown-date', 100, null, 0),
        ], ['old-no-gaps' => 0, 'new-gaps' => 1, 'unknown-date' => 2]);

        $this->assertSame(['new-gaps', 'old-no-gaps', 'unknown-date'], array_column($ranked, 'source'));
    }

    public function test_then_fewer_gaps_then_the_preferred_source_order(): void
    {
        $same = '2026-11-01T00:00:00Z';
        $ranked = CandidateRanking::rank([
            $this->candidate('suwayomi:2', 100, $same, 3),
            $this->candidate('suwayomi:1', 100, $same, 0),
            $this->candidate('mangadex', 100, $same, 0),
        ], ['mangadex' => 0, 'suwayomi:1' => 1, 'suwayomi:2' => 2]);

        $this->assertSame(['mangadex', 'suwayomi:1', 'suwayomi:2'], array_column($ranked, 'source'));
        $this->assertSame([0, 1, 2], array_column($ranked, 'order'));
    }

    public function test_failed_evaluations_go_last_in_source_order(): void
    {
        $ranked = CandidateRanking::rank([
            $this->candidate('b', null, null, null, 'timeout'),
            $this->candidate('c', 0, null, 0),
            $this->candidate('a', null, null, null, 'the source did not answer'),
        ], ['a' => 0, 'b' => 1, 'c' => 2]);

        $this->assertSame(['c', 'a', 'b'], array_column($ranked, 'source'));
        $this->assertLessThan(0, CandidateRanking::compare($this->candidate('x', 0), $this->candidate('y', 999, null, 0, 'boom')));
    }

    public function test_equal_candidates_keep_their_order_and_unknown_sources_go_after_known_ones(): void
    {
        $ranked = CandidateRanking::rank([
            $this->candidate('z', 10),
            $this->candidate('y', 10),
            $this->candidate('a', 10),
        ], ['a' => 0]);

        $this->assertSame(['a', 'z', 'y'], array_column($ranked, 'source'));
    }

    public function test_source_order_puts_configured_keys_first_and_a_base_key_covers_its_entries(): void
    {
        $discover = ['mangadex', 'suwayomi:20', 'suwayomi:10', 'other'];

        $this->assertSame(['mangadex' => 0, 'suwayomi:20' => 1, 'suwayomi:10' => 2, 'other' => 3], CandidateRanking::sourceOrder(['mangadex'], $discover));
        $this->assertSame(['suwayomi:20' => 0, 'suwayomi:10' => 1, 'mangadex' => 2, 'other' => 3], CandidateRanking::sourceOrder(['suwayomi'], $discover));
        $this->assertSame(['suwayomi:10' => 0, 'mangadex' => 1, 'suwayomi:20' => 2, 'other' => 3], CandidateRanking::sourceOrder(['suwayomi:10', 'mangadex', 'missing'], $discover));
        $this->assertSame(['mangadex' => 0, 'suwayomi:20' => 1, 'suwayomi:10' => 2, 'other' => 3], CandidateRanking::sourceOrder([], $discover));
    }

    public function test_the_reason_line(): void
    {
        $now = Carbon::parse('2026-11-19T12:00:00Z');
        $c = fn(array $over) => array_merge(['chapters_available' => 212, 'gaps' => 0, 'updated_at' => '2026-11-16T09:00:00Z', 'error' => null], $over);

        $this->assertSame('212 English chapters, no gaps, updated 3 days ago', CandidateRanking::reason($c([]), ['en'], $now));
        $this->assertSame('1 English chapter, 1 gap, updated today', CandidateRanking::reason($c(['chapters_available' => 1, 'gaps' => 1, 'updated_at' => '2026-11-19T01:00:00Z']), ['en'], $now));
        $this->assertSame('40 chapters, 4 gaps, updated yesterday', CandidateRanking::reason($c(['chapters_available' => 40, 'gaps' => 4, 'updated_at' => '2026-11-18T06:00:00Z']), ['en', 'fr'], $now));
        $this->assertSame('212 English chapters, no gaps, updated 5 months ago', CandidateRanking::reason($c(['updated_at' => '2026-06-15T00:00:00Z']), ['en'], $now));
        $this->assertSame('212 English chapters, no gaps, update date unknown', CandidateRanking::reason($c(['updated_at' => null]), ['en'], $now));
        $this->assertSame('0 English chapters (only French), updated 3 days ago', CandidateRanking::reason($c(['chapters_available' => 0, 'languages' => ['fr'], 'in_preferred_language' => false]), ['en'], $now));
        $this->assertSame('Could not be checked: the source did not answer.', CandidateRanking::reason(['error' => 'the source did not answer'], ['en'], $now));
    }

    public function test_language_names(): void
    {
        $this->assertSame('English', CandidateRanking::languageName('EN'));
        $this->assertSame('Portuguese', CandidateRanking::languageName('pt-br'));
        $this->assertSame('XX', CandidateRanking::languageName('xx'));
    }
}
