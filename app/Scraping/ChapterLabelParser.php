<?php

namespace App\Scraping;

use App\Console\Commands\ChapterScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parses a TOC label (+ URL) into a ChapterLabel: volume, number, part,
 * kind, title and an ordering key — so the chapter number no longer has to
 * double as identity, sort key and part encoding (audit A4).
 *
 * This is the ONLY label parser: generateTocChapterInfo() in Helpers.php
 * delegates here and derives its legacy [label, book, url, chapter] array
 * from the DTO (`chapter` = ChapterLabel::legacyChapter()), so the TOC rows
 * and the structured columns can never disagree on a label.
 */
final class ChapterLabelParser
{
    /** Chapter-ish token anywhere in a label (typos, "Ch.", Capítulo). */
    private const TOKEN_ANYWHERE = '/\b(?:ch{1,2}ap\w*|ch\.|cap[ií]?tulo)\s+(\d+(?:\.\d+)?)/iu';

    /**
     * A teaser *marker* ("[Teaser] …", "Teaser: …", "… Teaser", "… [Teaser]")
     * — such TOC entries are dropped. A title that merely contains the word
     * ("Chapter 50: The Teaser Trap") is not a teaser.
     */
    public static function isTeaser(string $label): bool
    {
        $display = self::display($label);

        return preg_match('/^\s*\[?teaser\b/iu', $display) === 1
            || preg_match('/\bteaser\]?\s*$/iu', $display) === 1;
    }

    public static function parse(string $label, ?string $url = null, ?int $volumeHint = null): ChapterLabel
    {
        $url = (string) $url;
        $display = self::display($label);
        $parse = self::fold($display);

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $lastSegment = strtolower(basename(rtrim($path, '/')));
        $lastSegment = preg_replace('/\.(html?|php)$/', '', $lastSegment);

        // Volume: a LEADING label prefix, else the URL path, else the hint.
        $volume = parseVolumePrefix($parse) ?? parseVolumeFromUrlPath($path) ?? ($volumeHint ?? 0);

        // Number: the FIRST number after a chapter token (or leading).
        $numStr = null;
        $afterNumber = null; // label text after the number (for parts)
        $sideChapter = false;
        if (preg_match('/^chapter\s*(\d+(?:\.\d+)?)/i', $parse, $cm)
            || preg_match('/^ch[aphter]{3,6}\s*(\d+(?:\.\d+)?)/i', $parse, $cm)
            || preg_match('/^(\d+(?:\.\d+)?)/', $parse, $cm)) {
            $numStr = $cm[1];
            $afterNumber = substr($parse, strlen($cm[0]));
        } elseif (preg_match('/^side\s*(?:chapter|story)\b/i', $parse)) {
            // Own numbering ("Side chapter 3") — unknown for the main sequence.
            $sideChapter = true;
        } elseif (preg_match(self::TOKEN_ANYWHERE, $parse, $cm, PREG_OFFSET_CAPTURE)) {
            $numStr = $cm[1][0];
            $afterNumber = substr($parse, $cm[0][1] + strlen($cm[0][0]));
        } elseif (
            !preg_match('/\b(?:ch{1,2}ap\w*|ch\.|cap[ií]?tulo)/iu', $parse)
            && !preg_match('/\d/', $parse)
            && preg_match('/(?:^|[-\/])ch(?:ap(?:ter)?)?-(\d+)/i', $path, $um)
        ) {
            // URL slug fallback — only when the label has no token and no digits.
            $numStr = $um[1];
        }

        [$part, $partRule] = ($afterNumber !== null && !str_contains($numStr, '.'))
            ? self::part($parse, $afterNumber, $numStr, $path, $lastSegment)
            : [0, null];

        $number = $numStr !== null ? (float) $numStr : null;
        $title = self::title($display, $numStr, $partRule);
        $kind = self::kind($number, $display, $title, $sideChapter);
        $sortKey = $number !== null ? round($number + $part / 1000, 4) : null;

        return new ChapterLabel(
            volume: (int) $volume,
            number: $number,
            part: $part,
            kind: $kind,
            title: $title,
            sourceLabel: mb_substr($display, 0, 255),
            sortKey: $sortKey,
        );
    }

    /**
     * Structured columns for a row, reconciled with its legacy `chapter`
     * value (which stays authoritative for the existing number):
     *  - `chapter` is 0 (unknown) → the parsed number is kept, sort_key NULL;
     *  - the parse agrees with `chapter` → the parse wins (number, part);
     *  - no parsed number but `chapter` is set (resolver / sequential
     *    fallback / manual fix) → number = chapter as-is, part 0: the part
     *    encoder only ever ran on a parsed number, so a fraction here is a
     *    real position (the resolver's p+0.5 extra);
     *  - they disagree → number = floor(chapter) with the part decoded from
     *    the .1–.99 encodeChapterPart() fraction (decodeLegacy()), `agrees`
     *    false.
     *
     * @return array{number: ?float, part: int, sort_key: ?float, agrees: bool}
     */
    public static function reconcile(ChapterLabel $parsed, float $legacy): array
    {
        if (abs($legacy) < 1e-9) {
            // Legacy 0 = no position yet: such rows read in TOC (id) order
            // ahead of the numbered ones, so the key stays NULL even for a
            // real "Chapter 0" (its number is still recorded).
            return [
                'number' => $parsed->number,
                'part' => $parsed->part,
                'sort_key' => null,
                'agrees' => $parsed->number === null || abs($parsed->legacyChapter()) < 1e-9,
            ];
        }

        if ($parsed->number !== null && abs($parsed->legacyChapter() - $legacy) < 0.005) {
            return [
                'number' => $parsed->number,
                'part' => $parsed->part,
                'sort_key' => $parsed->sortKey,
                'agrees' => true,
            ];
        }

        if ($parsed->number === null) {
            // Nothing parseable, so the value never came from the part
            // encoder (it needs a parsed number): it is the resolver's
            // (max+1, or p+0.5 for an extra between p and p+1), a manual fix
            // or the sequential fallback — a plain (possibly decimal) number.
            return ['number' => $legacy, 'part' => 0, 'sort_key' => round($legacy, 4), 'agrees' => true];
        }

        [$number, $part] = self::decodeLegacy($legacy);

        return [
            'number' => $number,
            'part' => $part,
            'sort_key' => round($number + $part / 1000, 4),
            'agrees' => false,
        ];
    }

    /**
     * Split a legacy `chapter` value into [number, part]: .1–.9 → parts 1–9,
     * .91–.99 → parts 10–18 (encodeChapterPart()), any other fraction is a
     * genuine decimal chapter.
     *
     * @return array{0: float, 1: int}
     */
    public static function decodeLegacy(float $legacy): array
    {
        $whole = floor($legacy);
        $hundredths = (int) round(($legacy - $whole) * 100);

        if ($hundredths === 0 || $legacy < 0) {
            return [$legacy, 0];
        }
        if ($hundredths % 10 === 0) {
            return [$whole, intdiv($hundredths, 10)];
        }
        if ($hundredths >= 91) {
            return [$whole, $hundredths - 81];
        }

        return [$legacy, 0];
    }

    /**
     * Fill number / part / title / source_label / sort_key for existing
     * novel_chapters rows (chunks of 1000, soft-deleted rows included).
     *
     * Ordering guarantee: for every (novel, book) the order by
     * (sort_key, id) equals the legacy order by (chapter, id). Where the
     * parsed keys would reorder a book (a decimal 12.5 next to an encoded
     * part 12.7 — the very ambiguity the new columns remove) that book
     * falls back to sort_key = chapter, which preserves the order exactly;
     * the next TOC sync then rewrites keys from the parser.
     *
     * @return array{rows: int, fallback_books: int}
     */
    public static function backfill(?int $novelId = null, int $chunk = 1000): array
    {
        $rows = 0;
        $base = fn() => DB::table('novel_chapters')
            ->when($novelId !== null, fn($q) => $q->where('novel_id', $novelId));

        $base()->select(['id', 'label', 'url', 'book', 'chapter'])
            ->chunkById($chunk, function ($batch) use (&$rows) {
                DB::transaction(function () use ($batch, &$rows) {
                    foreach ($batch as $row) {
                        DB::table('novel_chapters')->where('id', $row->id)
                            ->update(self::columnsFor((string) $row->label, $row->url, (int) $row->book, (float) $row->chapter));
                        $rows++;
                    }
                });
            });

        // Verify the order per (novel, book) and fall back where it moved.
        $fallback = 0;
        $novelIds = $base()->distinct()->orderBy('novel_id')->pluck('novel_id');
        foreach ($novelIds as $id) {
            $byBook = DB::table('novel_chapters')->where('novel_id', $id)
                ->get(['id', 'book', 'chapter', 'sort_key'])
                ->groupBy(fn($r) => (int) $r->book);

            foreach ($byBook as $book => $group) {
                if (self::keysPreserveOrder($group->all())) {
                    continue;
                }
                DB::table('novel_chapters')->where('novel_id', $id)->where('book', $book)
                    ->update(['sort_key' => DB::raw('chapter')]);
                $fallback++;
            }
        }

        if ($fallback > 0) {
            Log::info("Chapter structure backfill: {$fallback} (novel, book) group(s) kept sort_key = chapter to preserve their order");
        }

        return ['rows' => $rows, 'fallback_books' => $fallback];
    }

    /**
     * The structured column values for one row (shared by the backfill and
     * NovelScraper's TOC sync).
     */
    public static function columnsFor(string $label, ?string $url, int $book, float $legacy): array
    {
        $parsed = self::parse($label, $url, $book);
        $structured = self::reconcile($parsed, $legacy);

        return [
            'number' => $structured['number'],
            'part' => $structured['part'],
            'title' => $parsed->title !== null ? mb_substr($parsed->title, 0, 255) : null,
            'source_label' => $parsed->sourceLabel !== '' ? $parsed->sourceLabel : null,
            'sort_key' => $structured['sort_key'],
        ];
    }

    /**
     * Does ordering by (sort_key NULLS FIRST, id) give the same sequence as
     * ordering by (chapter, id)? Rows: objects with id, chapter, sort_key.
     */
    public static function keysPreserveOrder(array $rows): bool
    {
        $legacy = $rows;
        usort($legacy, fn($a, $b) => [(float) $a->chapter, $a->id] <=> [(float) $b->chapter, $b->id]);

        $keyed = $rows;
        usort($keyed, fn($a, $b) => [
            $a->sort_key === null ? 0 : 1, (float) $a->sort_key, $a->id,
        ] <=> [
            $b->sort_key === null ? 0 : 1, (float) $b->sort_key, $b->id,
        ]);

        return array_column($legacy, 'id') === array_column($keyed, 'id');
    }

    // ---------------------------------------------------------------------

    /** Original text, zero-width chars dropped, whitespace collapsed. */
    private static function display(string $label): string
    {
        $display = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $label);
        if ($display === null) {
            $display = mb_convert_encoding($label, 'UTF-8', 'UTF-8');
            $display = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $display) ?? $display;
        }

        return trim(preg_replace('/\s+/u', ' ', $display) ?? $display);
    }

    /** ASCII-folded copy used for number parsing (same as the legacy parser). */
    private static function fold(string $display): string
    {
        $parse = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $display) ?? $display;
        $parse = str_replace('(', ' (', $parse);
        $parse = preg_replace('/[^A-Za-z0-9 _\.\-\+\&\(\)]/', '', $parse);

        return trim(preg_replace('/ +/', ' ', $parse));
    }

    /**
     * Split / part suffix after a whole number. First match wins.
     *
     * @return array{0: int, 1: ?string} [part, rule that matched]
     */
    private static function part(string $parse, string $afterNumber, string $whole, string $path, string $lastSegment): array
    {
        if (preg_match('/^\s*\((\d{1,2})\)/', $afterNumber, $pm)) {
            return [(int) $pm[1], 'paren-lead'];
        }
        if (preg_match('/\((\d{1,2})\)\s*$/', $afterNumber, $pm)) {
            return [(int) $pm[1], 'paren-tail'];
        }
        if (preg_match('/\((\d{1,2})\)\s*-\s*[A-Z]\s*-/', $afterNumber, $pm)) {
            return [(int) $pm[1], 'paren-letter'];
        }
        if (preg_match('/\bpart\s*(\d{1,2})\b/i', $afterNumber, $pm)) {
            return [(int) $pm[1], 'part'];
        }
        if (
            preg_match('/^chapter\s*\d+\s*-\s*(\d)\b(?!\d)/i', $parse, $pm)
            && preg_match('/-' . $pm[1] . '$/', $lastSegment)
        ) {
            // "Chapter 12-2" only when the URL agrees (".../chapter-12-2").
            return [(int) $pm[1], 'dash'];
        }
        if (
            preg_match('/^chapter\s*\d+\s*([A-H])(?:\s*-|\s*$)/i', $parse, $pm)
            && (
                !preg_match('/(?:^|[-\/_])ch(?:ap(?:ter)?)?-?\d/i', $path)
                || preg_match('/(?:^|\D)' . preg_quote($whole, '/') . '-?' . strtolower($pm[1]) . '(?:-|$)/', $lastSegment)
            )
        ) {
            // "Chapter 164A" / "164 B - …" (A=1…H=8) when the URL has no
            // chapter number or carries the letter too.
            return [ord(strtoupper($pm[1])) - ord('A') + 1, 'letter'];
        }
        if (preg_match('/_(\d{1,2})$/', $parse, $pm)) {
            return [(int) $pm[1], 'underscore'];
        }

        return [0, null];
    }

    /**
     * The label minus volume prefix, chapter token + number and part marker;
     * original punctuation and script kept. Null when nothing is left.
     */
    private static function title(string $display, ?string $numStr, ?string $partRule): ?string
    {
        $t = preg_replace('/^\s*(?:vol(?:ume)?|book)\.?\s*\d+\s*[:\-–—.,]?\s*/iu', '', $display) ?? $display;

        if ($numStr !== null) {
            $num = preg_quote($numStr, '/');
            $token = '(?:chapter|ch[aphter]{3,6}|ch{1,2}ap\w*|ch\.?|cap[ií]?tulo)';
            $stripped = preg_replace('/^.*?\b' . $token . '\s*[.#:]?\s*' . $num . '(?![\d.]\d)/iu', '', $t, 1, $hits);
            if (!$hits) {
                $stripped = preg_replace('/^\s*#?' . $num . '(?![\d.]\d)/u', '', $t, 1, $hits);
            }
            if ($hits && $stripped !== null) {
                $t = $stripped;
            }
        }

        $t = match ($partRule) {
            'paren-lead' => preg_replace('/^\s*\(\s*\d{1,2}\s*\)/u', '', $t),
            'paren-tail' => preg_replace('/\(\s*\d{1,2}\s*\)\s*$/u', '', $t),
            'paren-letter' => preg_replace('/\(\s*\d{1,2}\s*\)\s*[-–—]\s*[A-Z]\s*[-–—]/u', ' – ', $t, 1),
            'part' => preg_replace('/\(?\s*\bpart\s*\d{1,2}\b\s*\)?/iu', ' ', $t, 1),
            'dash' => preg_replace('/^\s*[-–—]\s*\d(?!\d)/u', '', $t),
            'letter' => preg_replace('/^\s*[A-Ha-h](?=\s*[-–—:]|\s*$)/u', '', $t),
            'underscore' => preg_replace('/_\d{1,2}\s*$/u', '', $t),
            default => $t,
        } ?? $t;

        $t = preg_replace('/^[\s:\-–—.,|~#]+|[\s:\-–—,|~]+$/u', '', $t) ?? $t;
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);

        return $t === '' ? null : $t;
    }

    /**
     * Label family, from ChapterScraper's special/note label rules. A
     * numbered chapter only counts as special when its TITLE leads with the
     * special word ("Chapter 100 - Epilogue"), not when it merely mentions it.
     */
    private static function kind(?float $number, string $display, ?string $title, bool $sideChapter): string
    {
        if ($number === null) {
            if ($display !== '' && ChapterScraper::isNoteLabel($display)) {
                return self::noteKind($display);
            }
            if ($display !== '' && ChapterScraper::isStructuralSpecialLabel($display)) {
                return self::structuralKind($display, false) ?? 'extra';
            }

            return $sideChapter ? 'side' : ChapterLabel::KIND_UNKNOWN;
        }

        if ($title !== null && ChapterScraper::isNoteLabel($title)) {
            return self::noteKind($title);
        }
        if ($title !== null && ChapterScraper::isStructuralSpecialLabel($title)
            && ($kind = self::structuralKind($title, true)) !== null) {
            return $kind;
        }
        if ($number == 0.0 && ChapterScraper::isStructuralSpecialLabel($display)) {
            return 'prologue'; // "Chapter 0"
        }

        return ChapterLabel::KIND_CHAPTER;
    }

    private static function noteKind(string $text): string
    {
        return preg_match('/\b(?:afterwords?|postscripts?)\b/iu', $text) ? 'afterword' : 'notice';
    }

    /**
     * Sub-family of a label isStructuralSpecialLabel() already accepted.
     * $anchored: the word must lead the text and stand alone.
     */
    private static function structuralKind(string $text, bool $anchored): ?string
    {
        // Anchored: the word leads the text and stands alone ("Epilogue",
        // "Epilogue: Home", "Extra 3") — "The Extra Mile" is a title.
        $lead = $anchored ? '^\W*(?:the\s+)?' : '\b';
        $tail = $anchored ? '(?=\s*$|\s*[:\-–—(\[]|\s+\d)' : '\b';
        $families = [
            'prologue' => 'prologue|prelude|foreword|preface',
            'epilogue' => 'epilogue|postface',
            'interlude' => 'interlude|intermission',
            'side' => 'side[\s-]?stor(?:y|ies)',
            'extra' => 'extras?|specials?|bonus|omake|character[\s-]+\w+|glossary',
        ];
        foreach ($families as $kind => $words) {
            if (preg_match('/' . $lead . '(?:' . $words . ')' . $tail . '/iu', $text)) {
                return $kind;
            }
        }

        return $anchored ? null : 'extra';
    }
}
