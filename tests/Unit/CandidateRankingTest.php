<?php

namespace Tests\Unit;

use App\Services\Requests\CandidateRanking;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The manga shortlist ranking (App\Services\Requests\CandidateRanking):
 * a top tier near the best chapter count ordered by recency, then
 * chapters in the preferred language, then recency, then gaps, then the
 * preferred source order; failed evaluations last. Pure.
 */
class CandidateRankingTest extends TestCase
{
    private function candidate(string $source, ?int $chapters, ?string $updated = null, ?int $gaps = 0, ?string $error = null): array
    {
        return ['source' => $source, 'chapters_available' => $chapters, 'updated_at' => $updated, 'gaps' => $gaps, 'error' => $error];
    }

    private function sources(array $ranked): array
    {
        return array_column($ranked, 'source');
    }

    // ---- The top tier -----------------------------------------------------------------

    public function test_a_stale_source_a_chapter_ahead_loses_to_one_updated_yesterday(): void
    {
        $ranked = CandidateRanking::rank([
            $this->candidate('comet', 196, '2026-10-10T00:00:00Z', 4),
            $this->candidate('atlas', 195, '2026-11-18T00:00:00Z', 0),
            $this->candidate('nimbus', 180, '2026-11-17T00:00:00Z', 0),
        ], ['comet' => 0, 'atlas' => 1, 'nimbus' => 2]);

        $this->assertSame(['atlas', 'comet', 'nimbus'], $this->sources($ranked));
        $this->assertSame([true, true, false], array_column($ranked, 'top_tier'));
    }

    public function test_far_more_chapters_still_win_over_recency(): void
    {
        // 196 / 4 gaps / 40 days beats 180 / no gaps / 2 days: 16 is outside max(3, 3.92).
        $ranked = CandidateRanking::rank([
            $this->candidate('fresh', 180, '2026-11-17T00:00:00Z', 0),
            $this->candidate('stale', 196, '2026-10-10T00:00:00Z', 4),
        ], ['fresh' => 0, 'stale' => 1]);

        $this->assertSame(['stale', 'fresh'], $this->sources($ranked));
    }

    public function test_the_band_edges_with_the_three_chapter_floor(): void
    {
        $newer = '2026-11-18T00:00:00Z';
        $older = '2026-01-01T00:00:00Z';
        $this->assertSame(3.0, CandidateRanking::band(100));

        // Exactly 3 below the best of 100: in the tier, so the newer one wins.
        $this->assertSame(['on-band', 'best'], $this->sources(CandidateRanking::rank([
            $this->candidate('best', 100, $older), $this->candidate('on-band', 97, $newer),
        ], [])));
        // 4 below: outside, chapters decide.
        $this->assertSame(['best', 'outside'], $this->sources(CandidateRanking::rank([
            $this->candidate('best', 100, $older), $this->candidate('outside', 96, $newer),
        ], [])));
    }

    public function test_the_band_edges_with_the_percentage(): void
    {
        $newer = '2026-11-18T00:00:00Z';
        $older = '2026-01-01T00:00:00Z';
        // 2 % of 500 = 10.
        $this->assertSame(10.0, CandidateRanking::band(500));
        $this->assertSame(['on-band', 'best'], $this->sources(CandidateRanking::rank([
            $this->candidate('best', 500, $older), $this->candidate('on-band', 490, $newer),
        ], [])));
        $this->assertSame(['best', 'outside'], $this->sources(CandidateRanking::rank([
            $this->candidate('best', 500, $older), $this->candidate('outside', 489, $newer),
        ], [])));
        // 2 % of 196 = 3.92: 192 is just outside.
        $this->assertSame(['best', 'outside'], $this->sources(CandidateRanking::rank([
            $this->candidate('best', 196, $older), $this->candidate('outside', 192, $newer),
        ], [])));
        // Configurable: a wider floor and percentage.
        $this->assertSame(['on-band', 'best'], $this->sources(CandidateRanking::rank([
            $this->candidate('best', 500, $older), $this->candidate('on-band', 475, $newer),
        ], [], 3, 5.0)));
        $this->assertSame(['on-band', 'best'], $this->sources(CandidateRanking::rank([
            $this->candidate('best', 20, $older), $this->candidate('on-band', 10, $newer),
        ], [], 10, 0.0)));
    }

    public function test_the_tier_is_measured_from_the_best_count_not_pairwise(): void
    {
        $newest = '2026-11-18T00:00:00Z';
        // 100 / 98 / 96: 98 is within 3 of 100, 96 is within 3 of 98 but not of 100.
        $ranked = CandidateRanking::rank([
            $this->candidate('a100', 100, '2026-01-01T00:00:00Z'),
            $this->candidate('b98', 98, '2026-06-01T00:00:00Z'),
            $this->candidate('c96', 96, $newest),
        ], ['a100' => 0, 'b98' => 1, 'c96' => 2]);

        $this->assertSame(['b98', 'a100', 'c96'], $this->sources($ranked));
        // The same whatever the incoming order.
        $reversed = CandidateRanking::rank(array_reverse([
            $this->candidate('a100', 100, '2026-01-01T00:00:00Z'),
            $this->candidate('b98', 98, '2026-06-01T00:00:00Z'),
            $this->candidate('c96', 96, $newest),
        ]), ['a100' => 0, 'b98' => 1, 'c96' => 2]);
        $this->assertSame(['b98', 'a100', 'c96'], $this->sources($reversed));
    }

    public function test_unknown_update_dates_go_last_within_the_tier(): void
    {
        $ranked = CandidateRanking::rank([
            $this->candidate('unknown-more', 101, null, 0),
            $this->candidate('known', 100, '2025-01-01T00:00:00Z', 9),
            $this->candidate('unknown', 100, null, 0),
        ], ['unknown-more' => 0, 'known' => 1, 'unknown' => 2]);

        $this->assertSame(['known', 'unknown-more', 'unknown'], $this->sources($ranked));
    }

    public function test_all_equal_candidates_fall_back_to_gaps_then_source_order(): void
    {
        $same = '2026-11-01T00:00:00Z';
        $ranked = CandidateRanking::rank([
            $this->candidate('c', 50, $same, 0),
            $this->candidate('b', 50, $same, 0),
            $this->candidate('a', 50, $same, 1),
        ], ['b' => 0, 'c' => 1, 'a' => 2]);

        $this->assertSame(['b', 'c', 'a'], $this->sources($ranked));
        $this->assertSame([true, true, true], array_column($ranked, 'top_tier'));

        // Nothing checked: no tier; failed ones last in source order.
        $failed = CandidateRanking::rank([
            $this->candidate('y', null, null, null, 'boom'),
            $this->candidate('x', null, null, null, 'boom'),
        ], ['x' => 0, 'y' => 1]);
        $this->assertSame(['x', 'y'], $this->sources($failed));
        $this->assertSame([false, false], array_column($failed, 'top_tier'));
        $this->assertNull(CandidateRanking::bestCount($failed));
    }

    public function test_the_reason_lines_say_why_the_first_one_won(): void
    {
        $now = Carbon::parse('2026-11-19T12:00:00Z');
        $c = fn(string $source, string $name, int $count, ?string $updated, int $gaps = 0) => ['source' => $source, 'source_name' => $name,
            'chapters_available' => $count, 'updated_at' => $updated, 'gaps' => $gaps, 'error' => null];
        $ranked = CandidateRanking::rank([
            $c('comet', 'Comet Reader', 196, '2026-10-10T00:00:00Z', 4),
            $c('atlas', 'Atlas Scans', 195, '2026-11-18T06:00:00Z'),
            $c('nimbus', 'Nimbus Manga', 120, '2026-11-19T06:00:00Z'),
            ['source' => 'drift', 'source_name' => 'Drift', 'error' => 'the source did not answer'],
        ], ['comet' => 0, 'atlas' => 1, 'nimbus' => 2, 'drift' => 3]);

        $this->assertSame([
            '195 English chapters, no gaps, updated yesterday — 1 fewer than Comet Reader but more recent',
            '196 English chapters, 4 gaps, updated 40 days ago — 1 more than Atlas Scans, but updated less recently',
            '120 English chapters, no gaps, updated today',
            'Could not be checked: the source did not answer.',
        ], CandidateRanking::reasons($ranked, ['en'], $now));

        // Same date: gaps decide; the reason says so.
        $byGaps = CandidateRanking::rank([
            $c('comet', 'Comet Reader', 101, '2026-11-18T06:00:00Z', 2),
            $c('atlas', 'Atlas Scans', 100, '2026-11-18T06:00:00Z', 0),
        ], []);
        $this->assertSame([
            '100 English chapters, no gaps, updated yesterday — 1 fewer than Comet Reader but fewer gaps',
            '101 English chapters, 2 gaps, updated yesterday — 1 more than Atlas Scans, but more gaps',
        ], CandidateRanking::reasons($byGaps, ['en'], $now));

        // No reordering by the tier: the plain lines, as before.
        $plain = CandidateRanking::rank([$c('a', 'A', 200, '2026-10-10T00:00:00Z'), $c('b', 'B', 4, '2026-11-16T00:00:00Z', 1)], []);
        $this->assertSame([
            '200 English chapters, no gaps, updated 40 days ago',
            '4 English chapters, 1 gap, updated 3 days ago',
        ], CandidateRanking::reasons($plain, ['en'], $now));
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
