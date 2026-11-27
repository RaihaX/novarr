<?php

namespace App\Downloads;

use App\DownloadBlock;
use App\DownloadGrab;

/**
 * The decision profile for automatic grabs (config novarr.downloads; the
 * Downloads page's Settings override auto_search / auto_grab /
 * auto_approve / min_seeders / prefer_protocol / searches_per_day through
 * app_settings "downloads.*").
 *
 * confident() — a ranked result (ReleaseRanking::score()) is grabbed with
 * nobody looking only when ALL of these hold:
 *
 *   - every significant word of the wanted title is in the release name
 *     (title_match 1.0) AND a word of the author's name is (author_match);
 *     a wanted item without an author is never grabbed alone;
 *   - its format is allowed for the kind (ebook: epub, azw3, mobi — never
 *     pdf; audiobook: m4b, m4a, mp3, or no format named);
 *   - its size is known and plausible (ReleaseRanking::SIZES);
 *   - nothing is wrong with it (no ranking warning: sample, other
 *     language, abridged, unusual size, no seeders…) and none of
 *     must_not_contain is in its name;
 *   - a torrent has at least min_seeders (2); a usenet post is not older
 *     than max_age_days (when set);
 *   - its score is at least min_score (80); not blocklisted; its client is
 *     set up.
 *
 * pick() — of the confident results, the preferred protocol's best (usenet
 * first by default), then the other protocol's; within one protocol by
 * format order, then score.
 */
final class DownloadProfile
{
    public static function setting(string $key): mixed
    {
        $saved = setting('downloads.' . $key);

        return $saved !== null ? $saved : config('novarr.downloads.' . $key);
    }

    public static function autoSearch(): bool
    {
        return Downloads::enabled() && filter_var(self::setting('auto_search'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function autoGrab(): bool
    {
        return filter_var(self::setting('auto_grab'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function autoApprove(): bool
    {
        return filter_var(self::setting('auto_approve'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function minSeeders(): int
    {
        return max(0, (int) self::setting('min_seeders'));
    }

    public static function preferProtocol(): string
    {
        return self::setting('prefer_protocol') === DownloadGrab::TORRENT ? DownloadGrab::TORRENT : DownloadGrab::USENET;
    }

    public static function searchesPerDay(): int
    {
        return max(0, (int) self::setting('searches_per_day'));
    }

    /** @return list<string> */
    public static function formats(string $kind): array
    {
        return array_values((array) config('novarr.downloads.formats.' . $kind, []));
    }

    /** Why this result is not grabbed alone, or null when it may be. */
    public static function refusal(array $result, string $kind, array $wanted, ?array $blocklist = null): ?string
    {
        if (trim((string) ($wanted['author'] ?? '')) === '') {
            return 'the wanted book has no author to compare';
        }
        if ((float) ($result['title_match'] ?? 0) < 1.0) {
            return 'the title is not an exact match';
        }
        if (!($result['author_match'] ?? false)) {
            return 'the author is not in its name';
        }
        $format = $result['format'] ?? null;
        if ($format === null ? !config('novarr.downloads.allow_unknown_format.' . $kind, false) : !in_array($format, self::formats($kind), true)) {
            return $format === null ? 'its name does not say the format' : "{$format} is not an allowed format";
        }
        $size = (int) ($result['size'] ?? 0);
        [$min, $max] = ReleaseRanking::SIZES[$kind] ?? ReleaseRanking::SIZES[DownloadGrab::KIND_EBOOK];
        if ($size <= 0 || $size < $min || $size > $max) {
            return 'its size is unknown or unusual';
        }
        if (($result['warnings'] ?? []) !== []) {
            return implode(', ', (array) $result['warnings']);
        }
        $words = ReleaseRanking::words((string) ($result['title'] ?? ''));
        $titleWords = ReleaseRanking::words((string) ($wanted['title'] ?? ''));
        foreach ((array) config('novarr.downloads.must_not_contain', []) as $bad) {
            if (in_array(mb_strtolower($bad), $words, true) && !in_array(mb_strtolower($bad), $titleWords, true)) {
                return "its name has “{$bad}”";
            }
        }
        if (($result['protocol'] ?? null) === DownloadGrab::TORRENT) {
            if ((int) ($result['seeders'] ?? 0) < self::minSeeders()) {
                return 'fewer than ' . self::minSeeders() . ' seeders';
            }
        } else {
            $maxAge = (int) config('novarr.downloads.max_age_days', 0);
            if ($maxAge > 0 && (int) ($result['age_days'] ?? 0) > $maxAge) {
                return "older than {$maxAge} days";
            }
        }
        if ((float) ($result['score'] ?? 0) < (float) config('novarr.downloads.min_score', 80)) {
            return 'its score is too low';
        }
        if (Downloads::clientFor((string) ($result['protocol'] ?? '')) === null) {
            return 'its download client is not set up';
        }
        if (DownloadBlock::blocks($result, $blocklist)) {
            return 'blocklisted';
        }

        return null;
    }

    public static function confident(array $result, string $kind, array $wanted, ?array $blocklist = null): bool
    {
        return self::refusal($result, $kind, $wanted, $blocklist) === null;
    }

    /**
     * The confident results, in the order they would be grabbed.
     *
     * @return list<array>
     */
    public static function picks(array $results, string $kind, array $wanted): array
    {
        $blocklist = DownloadBlock::query()->get(['guid', 'title', 'indexer'])->all();
        $ok = array_values(array_filter($results, fn($r) => self::confident($r, $kind, $wanted, $blocklist)));
        $prefer = self::preferProtocol();
        $formats = array_flip(self::formats($kind));
        usort($ok, function ($a, $b) use ($prefer, $formats) {
            $pa = ($a['protocol'] ?? null) === $prefer ? 0 : 1;
            $pb = ($b['protocol'] ?? null) === $prefer ? 0 : 1;
            $fa = $formats[$a['format'] ?? ''] ?? count($formats);
            $fb = $formats[$b['format'] ?? ''] ?? count($formats);

            return [$pa, $fa, -$a['score']] <=> [$pb, $fb, -$b['score']];
        });

        return $ok;
    }
}
