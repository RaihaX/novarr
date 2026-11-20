<?php

namespace App\Services\Requests;

/**
 * Does a search hit resemble the reader's query? Some sources (Royal Road)
 * answer almost any query with twenty loosely related titles; RequestSearch
 * drops those before grouping. Pure.
 *
 * Both strings are normalised (normalize()): lower case, accents stripped,
 * punctuation to spaces, spaces collapsed. A hit is kept when its title — or
 * any alternative title the item carries ('alt_titles') — resembles the
 * query (resembles()):
 *   - every significant query word (not a stop word, longer than one
 *     letter) is a word of the title or the start of one ("solo lev" →
 *     "Solo Leveling"), or
 *   - the two are near-equal (similar_text ≥ SIMILAR_PERCENT), or
 *   - the title is wholly contained in the query, as whole words
 *     ("Solo Leveling" for "solo leveling manhwa").
 *
 * Nothing is filtered (filters() is false) for a query of one or two
 * characters, a query with no significant word, or a query in a script
 * written without spaces between words (Han, Hiragana, Katakana, Hangul,
 * Thai…): there a word test means nothing, so those hits pass unfiltered.
 * Cyrillic, Greek and other spaced scripts are filtered like Latin.
 */
class SearchRelevance
{
    /** Query words that never decide a match. */
    public const STOP_WORDS = ['a', 'an', 'the', 'of', 'and', 'to', 'in'];

    /** similar_text() percentage at which two normalised strings count as near-equal. */
    public const SIMILAR_PERCENT = 80.0;

    /** Scripts written without spaces between words: no word test, no filtering. */
    private const UNSPACED_SCRIPTS = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}\p{Tibetan}]/u';

    /** Lower case, accents stripped, punctuation → space, single spaces. "Re:Zéro!" → "re zero". */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                // Combining marks go: "é" → "e", "й" → "и" (the same on both sides).
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }
        $text = mb_strtolower($text);
        // An apostrophe joins its word ("Hero's" → "heros"), as Discover::normalizeName() does.
        $text = str_replace(["'", "\u{2019}", "\u{2018}"], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /** The query words that decide a match: no stop words, no one-letter words. */
    public static function significantWords(string $normalizedQuery): array
    {
        $words = $normalizedQuery === '' ? [] : explode(' ', $normalizedQuery);

        return array_values(array_unique(array_filter($words,
            fn(string $w) => mb_strlen($w) > 1 && !in_array($w, self::STOP_WORDS, true))));
    }

    /** Whether this query filters anything at all (see the class doc). */
    public static function filters(string $query): bool
    {
        $normalized = self::normalize($query);

        return mb_strlen($normalized) > 2
            && !preg_match(self::UNSPACED_SCRIPTS, $normalized)
            && self::significantWords($normalized) !== [];
    }

    /** Whether one title resembles the query (no filters() check). */
    public static function resembles(string $title, string $query): bool
    {
        $t = self::normalize($title);
        $q = self::normalize($query);
        if ($t === '' || $q === '') {
            return false;
        }
        if ($t === $q) {
            return true;
        }

        // Every significant query word is a title word or the start of one.
        $titleWords = explode(' ', $t);
        $all = true;
        foreach (self::significantWords($q) as $word) {
            $found = false;
            foreach ($titleWords as $titleWord) {
                if (str_starts_with($titleWord, $word)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $all = false;
                break;
            }
        }
        if ($all) {
            return true;
        }

        // Near-equal (a typo, "levelling" for "leveling").
        similar_text($t, $q, $percent);
        if ($percent >= self::SIMILAR_PERCENT) {
            return true;
        }

        // The title wholly inside the query, as whole words.
        return str_contains(' ' . $q . ' ', ' ' . $t . ' ');
    }

    /**
     * Whether a raw Discover item (['name' => …, 'alt_titles' => […]])
     * should be kept for the query: always when the query filters nothing.
     */
    public static function keeps(array $item, string $query): bool
    {
        if (!self::filters($query)) {
            return true;
        }
        $titles = array_merge([(string) ($item['name'] ?? '')], array_map('strval', array_filter((array) ($item['alt_titles'] ?? []), 'is_scalar')));
        foreach ($titles as $title) {
            if (self::resembles($title, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The items that resemble the query, in their order (list keys reset).
     *
     * @param  array<int, array>  $items
     * @return array<int, array>
     */
    public static function filter(array $items, string $query): array
    {
        if (!self::filters($query)) {
            return array_values($items);
        }

        return array_values(array_filter($items, fn($item) => is_array($item) && self::keeps($item, $query)));
    }
}
