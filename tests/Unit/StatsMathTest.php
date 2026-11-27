<?php

namespace Tests\Unit;

use App\Support\StatsMath;
use PHPUnit\Framework\TestCase;

/** The pure arithmetic behind Stats 2.0 (App\Support\StatsMath). */
class StatsMathTest extends TestCase
{
    public function test_streaks_end_today_or_yesterday_and_keep_the_best(): void
    {
        $dates = ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-25', '2026-11-26', '2026-11-26'];
        $this->assertSame(['current' => 2, 'best' => 4], StatsMath::streaks($dates, '2026-11-27'));
        $this->assertSame(['current' => 3, 'best' => 4], StatsMath::streaks([...$dates, '2026-11-27'], '2026-11-27'));
        // A gap of a day ends it.
        $this->assertSame(['current' => 0, 'best' => 4], StatsMath::streaks($dates, '2026-11-28'));
        $this->assertSame(['current' => 0, 'best' => 0], StatsMath::streaks([], '2026-11-27'));
        // Across a month and a year boundary.
        $this->assertSame(['current' => 3, 'best' => 3], StatsMath::streaks(['2025-12-31', '2026-01-01', '2025-12-30'], '2026-01-01'));
        $this->assertSame('2024-03-01', StatsMath::addDays('2024-02-28', 2));
    }

    public function test_heatmap_levels(): void
    {
        $this->assertSame(0, StatsMath::level(0, 100));
        $this->assertSame(1, StatsMath::level(1, 100));
        $this->assertSame(1, StatsMath::level(25, 100));
        $this->assertSame(2, StatsMath::level(26, 100));
        $this->assertSame(4, StatsMath::level(100, 100));
        $this->assertSame(4, StatsMath::level(500, 100));
        $this->assertSame(0, StatsMath::level(5, 0));
    }

    public function test_ticks_use_a_nice_step(): void
    {
        $this->assertSame([1, 0], StatsMath::ticks(0));
        $this->assertSame([3, 2, 1, 0], StatsMath::ticks(3));
        $this->assertSame([8, 6, 4, 2, 0], StatsMath::ticks(7));
        $this->assertSame([40, 30, 20, 10, 0], StatsMath::ticks(37));
        $this->assertSame([1000, 500, 0], StatsMath::ticks(901));
    }

    public function test_donut_arcs_cover_the_circle(): void
    {
        $this->assertSame([], StatsMath::donut(['a' => 0, 'b' => 0], 80, 80, 50));
        $arcs = StatsMath::donut(['novel' => 3, 'manga' => 0, 'audiobook' => 1], 80, 80, 50);
        $this->assertSame(['novel', 'audiobook'], array_column($arcs, 'key'));
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($arcs, 'share')), 1e-9);
        // 75%: starts at 12 o'clock, ends at 9 o'clock, the large arc.
        $this->assertSame('M 80.000 30.000 A 50.000 50.000 0 1 1 30.000 80.000', $arcs[0]['path']);
        $this->assertStringContainsString(' 0 0 1 80.000 30.000', $arcs[1]['path']);
        // One medium only: a full ring.
        $full = StatsMath::donut(['book' => 5], 80, 80, 50);
        $this->assertCount(1, $full);
        $this->assertSame(2, substr_count($full[0]['path'], ' A '));
    }

    public function test_compact_numbers_durations_and_hours(): void
    {
        $this->assertSame('950', StatsMath::compact(950));
        $this->assertSame('1.2K', StatsMath::compact(1234));
        $this->assertSame('1K', StatsMath::compact(1000));
        $this->assertSame('34K', StatsMath::compact(34_400));
        $this->assertSame('2.5M', StatsMath::compact(2_500_000));
        $this->assertSame('12M', StatsMath::compact(12_300_000));

        $this->assertSame('0 min', StatsMath::duration(59));
        $this->assertSame('45 min', StatsMath::duration(45 * 60 + 30));
        $this->assertSame('1 h', StatsMath::duration(3600));
        $this->assertSame('3 h 20 min', StatsMath::duration(3 * 3600 + 20 * 60));
        $this->assertSame('120 h', StatsMath::duration(120 * 3600 + 59 * 60));
        $this->assertSame(3.3, StatsMath::hours(3 * 3600 + 20 * 60));
        $this->assertSame(0.0, StatsMath::hours(-5));
    }
}
