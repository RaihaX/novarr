<?php

namespace App\Services\Requests;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Ranking a manga request's shortlist: which source should the series be
 * added from. Pure (no I/O): every input is passed in.
 *
 * Best first by:
 *   1. most chapters available in the preferred language(s);
 *   2. most recently updated (unknown last);
 *   3. fewest gaps in the chapter numbering;
 *   4. preferred source order (sourceOrder()).
 * Candidates whose evaluation failed (`error` set) go last, in source order.
 * Equal candidates keep their incoming order.
 */
final class CandidateRanking
{
    /**
     * Missing whole chapters between the first and the highest chapter:
     * the integers in [floor(min), floor(max)] that no chapter number
     * floors to. "12.5" counts as chapter 12; unnumbered / non-numeric
     * entries are ignored. 0 for fewer than two distinct chapters.
     *
     * @param  array<int, string|int|float>  $numbers
     */
    public static function gaps(array $numbers): int
    {
        $present = [];
        foreach ($numbers as $number) {
            if (is_numeric($number) && (float) $number >= 0) {
                $present[(int) floor((float) $number)] = true;
            }
        }
        if (count($present) < 2) {
            return 0;
        }
        $keys = array_keys($present);
        $span = max($keys) - min($keys) + 1;

        return max(0, $span - count($present));
    }

    /**
     * The highest chapter number, without padding ("212", "212.5"); null
     * when there is no numbered chapter.
     *
     * @param  array<int, string|int|float>  $numbers
     */
    public static function highest(array $numbers): ?string
    {
        $max = null;
        foreach ($numbers as $number) {
            if (is_numeric($number) && (float) $number >= 0 && ($max === null || (float) $number > $max)) {
                $max = (float) $number;
            }
        }
        if ($max === null) {
            return null;
        }
        $text = rtrim(rtrim(number_format($max, 4, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * <0 when $a ranks before $b. Reads `error`, `chapters_available`,
     * `updated_at` (ISO-8601 or null), `gaps` and `order` (source order,
     * lower first); missing values count as worst.
     */
    public static function compare(array $a, array $b): int
    {
        $failedA = !empty($a['error']);
        $failedB = !empty($b['error']);
        if ($failedA !== $failedB) {
            return $failedA ? 1 : -1;
        }
        if ($failedA) {
            return self::order($a) <=> self::order($b);
        }

        return ((int) ($b['chapters_available'] ?? 0) <=> (int) ($a['chapters_available'] ?? 0))
            ?: (self::updated($b) <=> self::updated($a))
            ?: (self::gapCount($a) <=> self::gapCount($b))
            ?: (self::order($a) <=> self::order($b));
    }

    /**
     * The candidates best first (compare()), each given its `order` from
     * $orderOf (source key => position; unknown keys after every known one).
     * Stable.
     *
     * @param  array<int, array>  $candidates
     * @param  array<string, int>  $orderOf
     * @return array<int, array>
     */
    public static function rank(array $candidates, array $orderOf): array
    {
        $indexed = [];
        foreach (array_values($candidates) as $i => $candidate) {
            $candidate['order'] = $orderOf[(string) ($candidate['source'] ?? '')] ?? (PHP_INT_MAX >> 1);
            $indexed[] = [$i, $candidate];
        }
        usort($indexed, fn(array $x, array $y) => self::compare($x[1], $y[1]) ?: ($x[0] <=> $y[0]));

        return array_map(fn(array $pair) => $pair[1], $indexed);
    }

    /**
     * Source key => position: the configured order first — an exact key
     * ("suwayomi:123"), or a base key ("suwayomi") that stands for each of
     * its entries in their Discover order — then every other key in
     * Discover order.
     *
     * @param  string[]  $configured  config novarr.requests.source_order
     * @param  string[]  $discoverKeys  the medium's source keys in Discover order
     * @return array<string, int>
     */
    public static function sourceOrder(array $configured, array $discoverKeys): array
    {
        $ordered = [];
        foreach ($configured as $wanted) {
            $wanted = trim((string) $wanted);
            foreach ($discoverKeys as $key) {
                if ($key === $wanted || str_starts_with($key, $wanted . ':')) {
                    $ordered[$key] ??= count($ordered);
                }
            }
        }
        foreach ($discoverKeys as $key) {
            $ordered[$key] ??= count($ordered);
        }

        return $ordered;
    }

    /**
     * One plain line on why a candidate ranks where it does:
     * "212 English chapters, no gaps, updated 3 days ago".
     *
     * @param  string[]  $preferredLanguages
     */
    public static function reason(array $candidate, array $preferredLanguages, CarbonInterface $now): string
    {
        if (!empty($candidate['error'])) {
            return 'Could not be checked: ' . rtrim((string) $candidate['error'], '.') . '.';
        }

        $count = (int) ($candidate['chapters_available'] ?? 0);
        $language = count($preferredLanguages) === 1 ? self::languageName((string) $preferredLanguages[0]) . ' ' : '';
        $parts = [$count . ' ' . $language . ($count === 1 ? 'chapter' : 'chapters')];
        if ($count === 0 && !empty($candidate['languages']) && empty($candidate['in_preferred_language'])) {
            $parts[0] .= ' (only ' . implode(', ', array_map(fn($l) => self::languageName((string) $l), (array) $candidate['languages'])) . ')';
        }

        $gaps = self::gapCount($candidate);
        if ($count > 0) {
            $parts[] = $gaps === 0 ? 'no gaps' : ($gaps === 1 ? '1 gap' : "{$gaps} gaps");
        }
        $parts[] = self::updatedPhrase($candidate['updated_at'] ?? null, $now);

        return implode(', ', $parts);
    }

    /** "updated today" / "updated 3 days ago" / "updated 2 months ago" / "update date unknown". */
    public static function updatedPhrase(?string $updatedAt, CarbonInterface $now): string
    {
        if ($updatedAt === null || $updatedAt === '') {
            return 'update date unknown';
        }
        try {
            $at = Carbon::parse($updatedAt);
        } catch (\Throwable) {
            return 'update date unknown';
        }
        $days = (int) floor(max(0, $at->diffInSeconds($now, false)) / 86400);

        return match (true) {
            $days === 0 => 'updated today',
            $days === 1 => 'updated yesterday',
            $days < 60 => "updated {$days} days ago",
            $days < 730 => 'updated ' . intdiv($days, 30) . ' months ago',
            default => 'updated ' . intdiv($days, 365) . ' years ago',
        };
    }

    /** "English" for "en" (intl when available), else the code upper-cased. */
    public static function languageName(string $code): string
    {
        $code = strtolower(trim($code));
        $known = ['en' => 'English', 'es' => 'Spanish', 'es-la' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'pt-br' => 'Portuguese', 'ja' => 'Japanese', 'ko' => 'Korean',
            'zh' => 'Chinese', 'ru' => 'Russian', 'id' => 'Indonesian', 'vi' => 'Vietnamese', 'th' => 'Thai',
            'pl' => 'Polish', 'tr' => 'Turkish', 'ar' => 'Arabic'];
        if (isset($known[$code])) {
            return $known[$code];
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($code, 'en');
            if (is_string($name) && $name !== '' && strtolower($name) !== $code) {
                return $name;
            }
        }

        return strtoupper($code);
    }

    private static function order(array $candidate): int
    {
        return (int) ($candidate['order'] ?? PHP_INT_MAX >> 1);
    }

    private static function gapCount(array $candidate): int
    {
        return isset($candidate['gaps']) ? (int) $candidate['gaps'] : PHP_INT_MAX >> 1;
    }

    /** Seconds since the epoch, 0 when unknown (sorts last). */
    private static function updated(array $candidate): int
    {
        $value = $candidate['updated_at'] ?? null;
        if (!is_string($value) || $value === '') {
            return 0;
        }
        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
