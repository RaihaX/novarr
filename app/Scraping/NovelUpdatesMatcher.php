<?php

namespace App\Scraping;

use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Decides whether a NovelUpdates series is the same novel as a local one.
 *
 * The old lookup took the first search hit (or a name-slug guess) on faith,
 * so "Shadow Slave" could end up with another series' status/chapter count
 * and be auto-completed against it. Candidates are now scored on title /
 * associated-name similarity (plus a small author bonus) and only accepted
 * above a threshold. Pure — no network, no DB.
 *
 * A candidate is ['title' => string, 'url' => string, 'associated' => string[], 'author' => ?string].
 */
class NovelUpdatesMatcher
{
    /** Minimum score for an automatic match (and for trusting completion). */
    public const THRESHOLD = 0.85;

    /** Multiplier when only one title carries a sequel marker (II, 2, Season 2…). */
    public const SEQUEL_PENALTY = 0.6;

    /** Bonus when the candidate's author matches the local author. */
    public const AUTHOR_BONUS = 0.1;

    /** Words dropped anywhere in a title. */
    private const ARTICLES = ['the', 'a', 'an'];

    /** Trailing words dropped from a title ("... (WN)", "... Novel"). */
    private const SUFFIXES = ['novel', 'webnovel', 'wn', 'ln'];

    /**
     * The trailing sequel/season marker of a title, normalised ("ii" and
     * "2" both become "2"), or "" when there is none.
     */
    public static function sequelMarker(string $title): string
    {
        $t = strtolower(trim($title));
        $t = preg_replace('/[\s\-:,.()\[\]]+$/u', '', $t) ?? $t;
        if (preg_match('/(?:^|\s)(?:season|part|book|vol(?:ume)?|arc)\s*(\d{1,2})$/u', $t, $m)) {
            return (string) (int) $m[1];
        }
        if (preg_match('/(?:^|\s)(ii|iii|iv|v|vi|vii|viii|ix|x|\d{1,2})$/u', $t, $m)) {
            $roman = ['ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10];
            return (string) ($roman[$m[1]] ?? (int) $m[1]);
        }
        return '';
    }

    /**
     * Comparable form of a title: ASCII, lowercase, no punctuation, no
     * articles, no "novel"/"webnovel"/"wn"/"ln" suffixes, single spaces.
     * Falls back to the plain lowercase form if stripping leaves nothing.
     */
    public static function normalizeTitle(string $title): string
    {
        $text = strtolower(Str::ascii($title));
        $text = str_replace('&', ' and ', $text);
        // Apostrophes join ("king's" -> "kings"); other punctuation splits.
        $text = str_replace(["'", '`'], '', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;
        $words = array_values(array_filter(explode(' ', $text), 'strlen'));
        $plain = implode(' ', $words);

        $words = array_values(array_filter($words, fn($w) => !in_array($w, self::ARTICLES, true)));
        while ($words && in_array(end($words), self::SUFFIXES, true)) {
            $dropped = array_pop($words);
            // "web novel" as two words is a suffix too.
            if ($dropped === 'novel' && $words && end($words) === 'web') {
                array_pop($words);
            }
        }

        $normalized = implode(' ', $words);

        return $normalized !== '' ? $normalized : $plain;
    }

    /** Comparable form of a person's name: ASCII, lowercase, alphanumerics only. */
    public static function normalizeName(?string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii((string) $name))) ?? '';
    }

    /**
     * Similarity of two titles, 0..1: the better of similar_text's ratio and
     * 1 − normalised Levenshtein distance, on normalised titles.
     */
    public static function similarity(string $a, string $b): float
    {
        $a = self::normalizeTitle($a);
        $b = self::normalizeTitle($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);
        $lev = 1 - levenshtein($a, $b) / max(strlen($a), strlen($b));

        return max($percent / 100, $lev, 0.0);
    }

    /**
     * Score a candidate against the local novel: best similarity over its
     * title and associated names, +AUTHOR_BONUS when the authors match,
     * clamped to 0..1.
     */
    public static function score(string $localName, ?string $localAuthor, array $candidate): float
    {
        $names = array_merge(
            [(string) ($candidate['title'] ?? '')],
            array_map('strval', (array) ($candidate['associated'] ?? []))
        );

        $best = 0.0;
        foreach ($names as $name) {
            if (trim($name) !== '') {
                $best = max($best, self::similarity($localName, $name));
            }
        }

        // "Reverend Insanity" vs "Reverend Insanity II" is 0.92 on letters
        // alone. A sequel marker on one side but not the other means a
        // different series, so pull the score well under the threshold.
        if (self::sequelMarker($localName) !== self::sequelMarker((string) ($candidate['title'] ?? ''))) {
            $best *= self::SEQUEL_PENALTY;
        }

        $local = self::normalizeName($localAuthor);
        if ($local !== '' && $local === self::normalizeName($candidate['author'] ?? null)) {
            $best += self::AUTHOR_BONUS;
        }

        return round(max(0.0, min(1.0, $best)), 3);
    }

    /**
     * All candidates with a 'score' key, best first (stable for ties).
     */
    public static function rank(array $candidates, string $name, ?string $author): array
    {
        $ranked = [];
        foreach (array_values($candidates) as $i => $candidate) {
            $candidate['score'] = self::score($name, $author, $candidate);
            $ranked[] = [$candidate, $i];
        }

        usort($ranked, fn($x, $y) => [$y[0]['score'], $x[1]] <=> [$x[0]['score'], $y[1]]);

        return array_map(fn($pair) => $pair[0], $ranked);
    }

    /**
     * The effective match threshold: the novelupdates_match_threshold
     * setting when set and sane (0.5-1.0), else THRESHOLD. setting()
     * swallows DB/app errors, so this stays safe outside a booted app.
     */
    public static function threshold(): float
    {
        $value = function_exists('setting') ? setting('novelupdates_match_threshold') : null;

        if (!is_numeric($value)) {
            return self::THRESHOLD;
        }

        $value = (float) $value;

        return ($value >= 0.5 && $value <= 1.0) ? $value : self::THRESHOLD;
    }

    /**
     * The best candidate (with its 'score') when it clears $threshold
     * (default: threshold()), else null.
     */
    public static function pick(array $candidates, string $name, ?string $author, ?float $threshold = null): ?array
    {
        $threshold ??= self::threshold();
        $best = self::rank($candidates, $name, $author)[0] ?? null;

        return $best !== null && $best['score'] >= $threshold ? $best : null;
    }

    /**
     * Every series result in NovelUpdates' live-search HTML, in page order,
     * de-duplicated: [['title' => …, 'url' => …, 'associated' => [], 'author' => null], …].
     */
    public static function parseSearchResults(string $html): array
    {
        $results = [];
        if (trim($html) === '') {
            return $results;
        }

        try {
            (new Crawler('<div>' . $html . '</div>'))
                ->filter('a[href*="novelupdates.com/series/"]')
                ->each(function (Crawler $node) use (&$results) {
                    if (!preg_match('#^(https?://(?:www\.)?novelupdates\.com/series/[a-z0-9-]+)/?#i', (string) $node->attr('href'), $m)) {
                        return;
                    }
                    $url = rtrim($m[1], '/') . '/';
                    if (isset($results[$url])) {
                        return;
                    }
                    $title = trim(preg_replace('/\s+/u', ' ', $node->text('')) ?? '');
                    if ($title === '') {
                        $title = trim((string) ($node->attr('title') ?? ''));
                    }
                    $results[$url] = ['title' => $title, 'url' => $url, 'associated' => [], 'author' => null];
                });
        } catch (\Throwable $e) {
            // Malformed markup: fall back to bare links below.
        }

        if (!$results && preg_match_all('#href="(https://www\.novelupdates\.com/series/[a-z0-9-]+)/?"#i', $html, $all)) {
            foreach ($all[1] as $href) {
                $url = rtrim($href, '/') . '/';
                $results[$url] ??= ['title' => '', 'url' => $url, 'associated' => [], 'author' => null];
            }
        }

        return array_values($results);
    }
}
