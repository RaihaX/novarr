<?php

namespace App\Services\Requests;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Ranking a manga request's shortlist: which source should the series be
 * added from. Pure (no I/O): every input is passed in.
 *
 * Best first, in two parts:
 *   - the top tier: every candidate whose chapter count is within the band
 *     of the BEST count — max(tie chapters, tie percent of the best count),
 *     config novarr.requests.rank_tie_chapters / rank_tie_percent — ordered
 *     by most recently updated (unknown last), then fewest gaps, then
 *     preferred source order, so a source a handful of chapters ahead but
 *     long stale does not beat one updated yesterday;
 *   - everyone else (compare()): most chapters available in the preferred
 *     language(s), then most recently updated, fewest gaps, source order.
 * The tier is measured against the best count, never pairwise, so the
 * order is well defined. Candidates whose evaluation failed (`error` set)
 * go last, in source order. Equal candidates keep their incoming order.
 */
final class CandidateRanking
{
    public const TIE_CHAPTERS = 3;

    public const TIE_PERCENT = 2.0;

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
     * Within the top tier: most recently updated, then fewest gaps, then
     * source order (the chapter count is no longer compared).
     */
    public static function compareInTier(array $a, array $b): int
    {
        return (self::updated($b) <=> self::updated($a))
            ?: (self::gapCount($a) <=> self::gapCount($b))
            ?: (self::order($a) <=> self::order($b));
    }

    /** The best chapter count among candidates that were checked; null when none was. */
    public static function bestCount(array $candidates): ?int
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (empty($candidate['error'])) {
                $best = max($best ?? 0, self::chapterCount($candidate));
            }
        }

        return $best;
    }

    /** How many chapters below the best count still make the top tier: max($tieChapters, $tiePercent % of $best). */
    public static function band(int $best, int $tieChapters = self::TIE_CHAPTERS, float $tiePercent = self::TIE_PERCENT): float
    {
        return max((float) max(0, $tieChapters), $best * max(0.0, $tiePercent) / 100);
    }

    /** Is a checked candidate in the top tier of a shortlist whose best count is $best? */
    public static function inTier(array $candidate, ?int $best, int $tieChapters = self::TIE_CHAPTERS, float $tiePercent = self::TIE_PERCENT): bool
    {
        return $best !== null && empty($candidate['error'])
            && ($best - self::chapterCount($candidate)) <= self::band($best, $tieChapters, $tiePercent);
    }

    /**
     * The candidates best first — the top tier (compareInTier()), then the
     * rest (compare()), then failed ones — each given its `order` from
     * $orderOf (source key => position; unknown keys after every known one)
     * and `top_tier` (bool). Stable.
     *
     * @param  array<int, array>  $candidates
     * @param  array<string, int>  $orderOf
     * @return array<int, array>
     */
    public static function rank(array $candidates, array $orderOf, int $tieChapters = self::TIE_CHAPTERS, float $tiePercent = self::TIE_PERCENT): array
    {
        $best = self::bestCount($candidates);
        $indexed = [];
        foreach (array_values($candidates) as $i => $candidate) {
            $candidate['order'] = $orderOf[(string) ($candidate['source'] ?? '')] ?? (PHP_INT_MAX >> 1);
            $candidate['top_tier'] = self::inTier($candidate, $best, $tieChapters, $tiePercent);
            $indexed[] = [$i, $candidate];
        }
        usort($indexed, function (array $x, array $y) {
            [$a, $b] = [$x[1], $y[1]];
            if ($a['top_tier'] !== $b['top_tier']) {
                return $a['top_tier'] ? -1 : 1;
            }

            return ($a['top_tier'] ? self::compareInTier($a, $b) : self::compare($a, $b)) ?: ($x[0] <=> $y[0]);
        });

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

    /**
     * The reason lines of a ranked shortlist (rank()'s output, best first),
     * one per candidate: reason(), plus — where the top tier changed the
     * order from "most chapters first" — why. The first candidate, when a
     * tier member has more chapters: "… — 1 fewer than Comet Reader but
     * more recent". A tier member with more chapters than the first: "… —
     * 1 more than Atlas Scans, but updated less recently".
     *
     * @param  array<int, array>  $ranked
     * @param  string[]  $preferredLanguages
     * @return string[]
     */
    public static function reasons(array $ranked, array $preferredLanguages, CarbonInterface $now): array
    {
        $ranked = array_values($ranked);
        $first = $ranked[0] ?? null;
        $reasons = [];
        foreach ($ranked as $i => $candidate) {
            $line = self::reason($candidate, $preferredLanguages, $now);
            if ($first !== null && !empty($first['top_tier']) && !empty($candidate['top_tier']) && empty($candidate['error'])) {
                if ($i === 0) {
                    // The tier member with the most chapters (the first of them in rank order).
                    $most = null;
                    foreach ($ranked as $other) {
                        if (!empty($other['top_tier']) && self::chapterCount($other) > self::chapterCount($most ?? $candidate)) {
                            $most = $other;
                        }
                    }
                    if ($most !== null) {
                        $line .= ' — ' . (self::chapterCount($most) - self::chapterCount($candidate)) . ' fewer than '
                            . self::nameOf($most) . ' but ' . self::winsBy($candidate, $most);
                    }
                } elseif (self::chapterCount($candidate) > self::chapterCount($first)) {
                    $line .= ' — ' . (self::chapterCount($candidate) - self::chapterCount($first)) . ' more than '
                        . self::nameOf($first) . ', but ' . self::losesBy($candidate, $first);
                }
            }
            $reasons[] = $line;
        }

        return $reasons;
    }

    /** Why $winner ranks before $other in the tier: "more recent" / "fewer gaps" / "a preferred source". */
    private static function winsBy(array $winner, array $other): string
    {
        if (self::updated($winner) !== self::updated($other)) {
            return self::updated($other) === 0 ? 'its update date is known' : 'more recent';
        }
        if (self::gapCount($winner) !== self::gapCount($other)) {
            return 'fewer gaps';
        }

        return 'a preferred source';
    }

    /** Why $loser ranks after $winner in the tier. */
    private static function losesBy(array $loser, array $winner): string
    {
        if (self::updated($loser) !== self::updated($winner)) {
            return self::updated($loser) === 0 ? 'its update date is unknown' : 'updated less recently';
        }
        if (self::gapCount($loser) !== self::gapCount($winner)) {
            return 'more gaps';
        }

        return 'a less preferred source';
    }

    private static function nameOf(array $candidate): string
    {
        $name = trim((string) ($candidate['source_name'] ?? ''));

        return $name !== '' ? $name : (trim((string) ($candidate['source'] ?? '')) ?: 'another source');
    }

    private static function chapterCount(array $candidate): int
    {
        return max(0, (int) ($candidate['chapters_available'] ?? 0));
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
