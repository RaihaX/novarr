<?php

namespace App\Support;

/**
 * The arithmetic behind the Stats page and its charts, kept pure (no
 * database, no clock) so it can be tested on its own: streaks, heatmap
 * shades, "nice" axis ticks, donut arcs and bar geometry, compact numbers
 * and durations. App\Services\ReaderStats builds the figures with it;
 * resources/views/stats draws the SVG charts with it.
 */
final class StatsMath
{
    /** Shades of the activity heatmap above "nothing" (levels 1..4). */
    public const HEATMAP_LEVELS = 4;

    /**
     * Current and best streak of consecutive active days. $dates are
     * Y-m-d strings (any order, duplicates allowed); $today is Y-m-d. The
     * current streak ends today, or yesterday when today has nothing yet
     * (a streak is still alive until the day is over).
     *
     * @param  string[]  $dates
     * @return array{current:int, best:int}
     */
    public static function streaks(array $dates, string $today): array
    {
        $set = [];
        foreach ($dates as $d) {
            $set[$d] = true;
        }
        $sorted = array_keys($set);
        sort($sorted);

        $best = 0;
        $run = 0;
        $prev = null;
        foreach ($sorted as $d) {
            $run = ($prev !== null && self::addDays($prev, 1) === $d) ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $d;
        }

        $current = 0;
        $cursor = isset($set[$today]) ? $today : self::addDays($today, -1);
        while (isset($set[$cursor])) {
            $current++;
            $cursor = self::addDays($cursor, -1);
        }

        return ['current' => $current, 'best' => max($best, $current)];
    }

    /** Y-m-d plus $days (calendar days, no timezone involved). */
    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Heatmap shade 0..HEATMAP_LEVELS of $value against the busiest day ($max). */
    public static function level(float $value, float $max): int
    {
        if ($value <= 0 || $max <= 0) {
            return 0;
        }

        return (int) max(1, min(self::HEATMAP_LEVELS, ceil($value / $max * self::HEATMAP_LEVELS)));
    }

    /**
     * Integer axis ticks from $max down to 0 on a 1/2/5 × 10^k step, at
     * most $bands bands; the top tick is at least 1.
     *
     * @return int[]
     */
    public static function ticks(float $max, int $bands = 4): array
    {
        $step = 1;
        if ($max > $bands) {
            $raw = $max / $bands;
            $mag = 10 ** floor(log10($raw));
            foreach ([1, 2, 5, 10] as $m) {
                if ($m * $mag >= $raw) {
                    $step = (int) ($m * $mag);
                    break;
                }
            }
        }
        $top = max(1, (int) (ceil($max / $step) * $step));

        return range($top, 0, -$step);
    }

    /**
     * Donut arcs for $values (label => value) on a circle of $radius
     * centred on ($cx, $cy): SVG path data per non-zero value, with its
     * share. A single non-zero value is a full ring (two half arcs).
     *
     * @param  array<string, float>  $values
     * @return list<array{key:string, share:float, path:string}>
     */
    public static function donut(array $values, float $cx, float $cy, float $radius): array
    {
        $total = array_sum(array_map(fn($v) => max(0, $v), $values));
        if ($total <= 0) {
            return [];
        }
        $arcs = [];
        $angle = -M_PI / 2; // 12 o'clock
        foreach ($values as $key => $value) {
            if ($value <= 0) {
                continue;
            }
            $share = $value / $total;
            $sweep = $share * 2 * M_PI;
            if ($share >= 0.9999) {
                $path = sprintf(
                    'M %.3f %.3f A %.3f %.3f 0 1 1 %.3f %.3f A %.3f %.3f 0 1 1 %.3f %.3f',
                    $cx, $cy - $radius, $radius, $radius, $cx, $cy + $radius, $radius, $radius, $cx, $cy - $radius
                );
            } else {
                $x1 = $cx + $radius * cos($angle);
                $y1 = $cy + $radius * sin($angle);
                $x2 = $cx + $radius * cos($angle + $sweep);
                $y2 = $cy + $radius * sin($angle + $sweep);
                $path = sprintf('M %.3f %.3f A %.3f %.3f 0 %d 1 %.3f %.3f', $x1, $y1, $radius, $radius, $sweep > M_PI ? 1 : 0, $x2, $y2);
            }
            $arcs[] = ['key' => (string) $key, 'share' => $share, 'path' => $path];
            $angle += $sweep;
        }

        return $arcs;
    }

    /** 950, 1.2K, 34K, 1.2M (one decimal under 10 of a unit). */
    public static function compact(float $n): string
    {
        $abs = abs($n);
        foreach ([[1_000_000_000, 'B'], [1_000_000, 'M'], [1_000, 'K']] as [$unit, $suffix]) {
            if ($abs >= $unit) {
                $v = $n / $unit;
                $text = abs($v) < 10 ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') : number_format($v, 0, '.', '');

                return $text . $suffix;
            }
        }

        return number_format($n, 0, '.', ',');
    }

    /** "0 min", "45 min", "3 h 20 min", "120 h" (from seconds; minutes rounded down). */
    public static function duration(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours >= 100 || $rest === 0) {
            return number_format($hours) . ' h';
        }

        return $hours . ' h ' . $rest . ' min';
    }

    /** Hours with one decimal (3 h 20 min = 3.3). */
    public static function hours(int $seconds): float
    {
        return round(max(0, $seconds) / 3600, 1);
    }
}
