<?php

namespace App\Books;

use App\Services\DuplicateSeries;

/**
 * Series for books that carry none (no Calibre series, no series in the
 * ePub, nothing set by the owner), guessed from titles and authors. Pure:
 * books in, assignments out; App\Books\BookSeries reads and writes the rows.
 *
 * The rules, in order. Every key is "same author + same title stem": books
 * by different authors (normalised: case, accents, punctuation and the order
 * of several names ignored) are never grouped.
 *
 *  1. Joins an explicit series. A book whose stem (title without its volume
 *     numbering, see parseTitle()) or colon prefix ("Mistborn" of "Mistborn:
 *     Secret History") is the name of a series another book of the same
 *     author already has (from Calibre, the ePub or the owner) joins it.
 *
 *  2. Numbered volumes. Books with the same stem form a series when at least
 *     two share it and at least one of them is numbered ("Shadow Slave
 *     Vol. 3", "Desire: A LitRPG Adventure (Volume 2)", "The Academy Part 2",
 *     "Book Two", "#3", a trailing " 3"). The bare title ("The Academy")
 *     joins its numbered volumes. Two books with the same title and no
 *     number are copies, not a series.
 *
 *  3. A shared prefix before ":" ("Reborn: Demon Core", "Reborn: Knight's
 *     Code"), for books that have an author: at least two books, one of
 *     which may be the bare prefix ("Reborn"). A subtitle that starts with
 *     "A" / "An" describes the book ("Desire: A LitRPG Adventure") and is
 *     not an entry of a series.
 *
 * Nothing else groups books: titles that only share words ("Throne of
 * Secrets", "Throne of the Fallen") stay apart, as do short stems (under 3
 * letters once normalised).
 *
 * The series is named after the stem / prefix as written (of its
 * lowest-numbered book), or after the explicit series it joins; the book's
 * number is the one in its title (Vol. 3 → 3, "Book Two" → 2, "(Volume 1)"
 * → 1), else none (such books list by title after the numbered ones' nulls,
 * as the database orders them).
 */
final class SeriesInference
{
    /** Shortest normalised stem or prefix that may name a series. */
    private const MIN_KEY = 3;

    /** Longest colon prefix taken as a series name (a long one is a title). */
    private const MAX_PREFIX = 60;

    /** Who may be overwritten: nothing set, or set by an earlier inference. */
    public const ELIGIBLE = [null, '', BookSeries::INFERRED];

    private const WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
        'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
        'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
        'nineteen' => 19, 'twenty' => 20,
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6,
        'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
    ];

    /**
     * @param  array<int, array{id: int, name: string, author: ?string, series?: ?string, series_index?: ?float, series_source?: ?string}>  $books
     * @return array<int, array{series: ?string, series_index: ?float}>  every eligible book (series_source
     *         null or 'inferred') => what it should be; series null = no series
     */
    public static function infer(array $books): array
    {
        usort($books, fn($a, $b) => [mb_strtolower((string) $a['name']), $a['id']] <=> [mb_strtolower((string) $b['name']), $b['id']]);

        // Explicit series (Calibre, the ePub, the owner): anchors by author + name.
        $anchors = [];
        $eligible = [];
        foreach ($books as $book) {
            $source = $book['series_source'] ?? null;
            if (!in_array($source, self::ELIGIBLE, true)) {
                $series = trim((string) ($book['series'] ?? ''));
                if ($series !== '' && ($key = self::key($book['author'] ?? null, $series)) !== null) {
                    $anchors[$key] ??= $series;
                }
                continue;
            }
            $parsed = self::parseTitle((string) $book['name']);
            $eligible[(int) $book['id']] = $parsed + [
                'stem_key' => self::key($book['author'] ?? null, $parsed['base']),
                'prefix' => $prefix = self::prefix($parsed['base']),
                // Rule 3 needs an author: "Untitled: …" by nobody is no series.
                'prefix_key' => $prefix !== null && self::authorKey($book['author'] ?? null) !== '' ? self::key($book['author'] ?? null, $prefix) : null,
            ];
        }

        $out = array_fill_keys(array_keys($eligible), ['series' => null, 'series_index' => null]);
        $assign = function (int $id, string $series) use (&$out, $eligible) {
            $out[$id] = ['series' => mb_substr($series, 0, 255), 'series_index' => $eligible[$id]['number']];
        };

        // Rule 1 (stem) and rule 2.
        $byStem = [];
        foreach ($eligible as $id => $e) {
            if ($e['stem_key'] === null) {
                continue;
            }
            if (isset($anchors[$e['stem_key']])) {
                $assign($id, $anchors[$e['stem_key']]);
                continue;
            }
            $byStem[$e['stem_key']][] = $id;
        }
        foreach ($byStem as $key => $ids) {
            $numbered = array_filter($ids, fn($id) => $eligible[$id]['numbered']);
            if (count($ids) < 2 || $numbered === []) {
                continue;
            }
            // Named after the lowest-numbered volume's stem, as written.
            usort($numbered, fn($a, $b) => ($eligible[$a]['number'] ?? PHP_FLOAT_MAX) <=> ($eligible[$b]['number'] ?? PHP_FLOAT_MAX));
            $name = $eligible[$numbered[0]]['base'];
            $anchors[$key] = $name;
            foreach ($ids as $id) {
                $assign($id, $name);
            }
        }

        // Rule 1 (prefix) and rule 3, for the books still without one.
        $byPrefix = [];
        foreach ($eligible as $id => $e) {
            if ($out[$id]['series'] !== null || $e['prefix_key'] === null) {
                continue;
            }
            if (isset($anchors[$e['prefix_key']])) {
                $assign($id, $anchors[$e['prefix_key']]);
                continue;
            }
            $byPrefix[$e['prefix_key']][] = $id;
        }
        // The bare prefix as a book of its own ("Reborn") joins its sequels.
        $bareByStem = [];
        foreach ($eligible as $id => $e) {
            if ($out[$id]['series'] === null && $e['prefix'] === null && $e['stem_key'] !== null) {
                $bareByStem[$e['stem_key']][] = $id;
            }
        }
        foreach ($byPrefix as $key => $ids) {
            $members = array_values(array_unique([...($bareByStem[$key] ?? []), ...$ids]));
            if (count($members) < 2) {
                continue;
            }
            $name = $eligible[$ids[0]]['prefix'];
            foreach ($members as $id) {
                $assign($id, $name);
            }
        }

        return $out;
    }

    /**
     * A title's stem and number: "Shadow Slave Vol. 3" → base "Shadow
     * Slave", number 3; "Desire: A LitRPG Adventure (Volume 2)" → "Desire:
     * A LitRPG Adventure", 2; "Book Two" → "", 2; "Hunter Chapters 1-200" →
     * "Hunter", numbered without a number (a chapter range is no volume).
     *
     * @return array{base: string, number: ?float, numbered: bool}
     */
    public static function parseTitle(string $title): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $roman = '(?=[ivx])x{0,3}(?:ix|iv|v?i{0,3})';
        $words = implode('|', array_keys(self::WORDS));
        $sep = '\s*[\(\[,:\-–—]*\s*';

        // "Chapters 1-200": the stem, but no volume number.
        if (preg_match('/^(.*?)' . $sep . '\b(?:ch(?:apters?)?\.?|c\.)\s*\d+\s*(?:-|–|to)\s*\d+.*$/iu', $title, $m)) {
            return ['base' => self::trimBase($m[1]), 'number' => null, 'numbered' => true];
        }
        // "Vol. 3", "Volume III", "Book Two", "Part 2", "(Volume 1)", "Tome 4: …".
        if (preg_match('/^(.*?)' . $sep . '\b(?:vol(?:ume)?|book|bk|part|pt|tome)\.?\s*(\d+(?:\.\d+)?|' . $roman . '|' . $words . ')\b.*$/iu', $title, $m)) {
            $number = self::number($m[2]);
            if ($number !== null) {
                return ['base' => self::trimBase($m[1]), 'number' => $number, 'numbered' => true];
            }
        }
        // "Title #3", "Title 3" (one to three digits: "Blade Runner 2049" is a title).
        if (preg_match('/^(.*?)\s*(?:#\s*(\d+(?:\.\d+)?)|\s(\d{1,3}(?:\.\d+)?))\s*[\)\]]?$/u', $title, $m) && trim($m[1]) !== '') {
            return ['base' => self::trimBase($m[1]), 'number' => (float) (($m[2] ?? '') !== '' ? $m[2] : $m[3]), 'numbered' => true];
        }

        return ['base' => $title, 'number' => null, 'numbered' => false];
    }

    /** "3", "2.5", "III", "Two" → a number, or null. */
    private static function number(string $raw): ?float
    {
        $raw = mb_strtolower(trim($raw));
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        if (isset(self::WORDS[$raw])) {
            return (float) self::WORDS[$raw];
        }
        if (preg_match('/^x{0,3}(?:ix|iv|v?i{0,3})$/', $raw) && $raw !== '') {
            $values = ['i' => 1, 'v' => 5, 'x' => 10];
            $total = 0;
            $chars = str_split($raw);
            foreach ($chars as $i => $c) {
                $v = $values[$c];
                $total += isset($chars[$i + 1]) && $values[$chars[$i + 1]] > $v ? -$v : $v;
            }

            return $total > 0 ? (float) $total : null;
        }

        return null;
    }

    private static function trimBase(string $base): string
    {
        return trim($base, " \t\n\r\0\x0B-–—:,;([");
    }

    /**
     * The part of a stem before ": " when the rest names an entry, not a
     * description: "Reborn: Demon Core" → "Reborn"; "Desire: A LitRPG
     * Adventure" → null.
     */
    private static function prefix(string $base): ?string
    {
        if (!preg_match('/^([^:]+?)\s*:\s+(.+)$/u', $base, $m)) {
            return null;
        }
        $prefix = trim($m[1]);
        if (preg_match('/^(?:a|an)\s/iu', trim($m[2])) || mb_strlen($prefix) > self::MAX_PREFIX) {
            return null;
        }

        return $prefix;
    }

    /** "author|stem", or null when the stem is too short to name a series. */
    private static function key(?string $author, string $title): ?string
    {
        $title = DuplicateSeries::normalize($title);
        if (mb_strlen($title) < self::MIN_KEY) {
            return null;
        }

        return self::authorKey($author) . '|' . $title;
    }

    /** "Smith, Jane & Ann Lee" and "Ann Lee, Jane Smith"-like lists agree; case and accents ignored. */
    public static function authorKey(?string $author): string
    {
        $names = preg_split('/\s*(?:,|&|;|\band\b)\s*/iu', (string) $author) ?: [];
        $names = array_filter(array_map(fn($n) => DuplicateSeries::normalize($n), $names), fn($n) => $n !== '');
        sort($names);

        return implode('+', $names);
    }
}
