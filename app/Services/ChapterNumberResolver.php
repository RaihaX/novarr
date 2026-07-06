<?php

namespace App\Services;

use App\NovelChapter;

/**
 * Resolves chapter numbers for rows the TOC parser couldn't number (chapter 0)
 * by process of elimination: extract every plausible number from the label and
 * URL, then pick the one the novel's existing sequence is missing.
 *
 * Example: "Chapter 1501 Section 1502 Transforming Soft" carries both 1501 and
 * 1502 — if the novel already has 1502 but not 1501, the answer is 1501.
 */
class ChapterNumberResolver
{
    /**
     * Extract candidate chapter numbers, grouped by confidence.
     * - label: digits following a chapter-ish token (tolerates source typos:
     *   Chaper, Chaptet, Chhapter, Captulo/Capítulo, Ch.)
     * - url: digits following chapter/ch tokens in the URL slug
     * - weak: any other standalone number in the label or slug
     */
    public static function candidates(?string $label, ?string $url): array
    {
        $label = trim((string) $label);
        $fromLabel = [];
        $fromUrl = [];
        $weak = [];

        if (preg_match_all('/\b(?:ch{1,2}ap\w*|ch\.|cap[ií]?tulo)\s*[-–:]?\s*(\d{1,5})/iu', $label, $m)) {
            foreach ($m[1] as $n) {
                $fromLabel[] = (int) $n;
            }
        }

        if ($url) {
            $slug = strtolower(basename(parse_url($url, PHP_URL_PATH) ?: ''));
            if (preg_match_all('/(?:^|-)(?:chapter|chap|ch)-(\d{1,5})/', $slug, $m)) {
                foreach ($m[1] as $n) {
                    $fromUrl[] = (int) $n;
                }
            }
            if (preg_match_all('/(?:^|-)(\d{1,5})(?=-|\.|$)/', $slug, $m)) {
                foreach ($m[1] as $n) {
                    $weak[] = (int) $n;
                }
            }
        }

        if (preg_match_all('/\d{1,5}/', $label, $m)) {
            foreach ($m[0] as $n) {
                $weak[] = (int) $n;
            }
        }

        $positive = fn($n) => $n > 0;
        $fromLabel = array_values(array_unique(array_filter($fromLabel, $positive)));
        $fromUrl = array_values(array_unique(array_filter($fromUrl, $positive)));
        $strong = array_values(array_unique(array_merge($fromLabel, $fromUrl)));
        $weak = array_values(array_diff(array_unique(array_filter($weak, $positive)), $strong));

        return ['label' => $fromLabel, 'url' => $fromUrl, 'strong' => $strong, 'weak' => $weak];
    }

    /**
     * Resolve a chapter number for the row, or null when nothing fits
     * unambiguously. Returns [number, reason, book].
     */
    public static function resolve(NovelChapter $row): ?array
    {
        // Book can come from the label when the parser missed it
        // ("Volume 6 Chapter 43 ..." with book still 0).
        $book = (int) $row->book;
        if ($book === 0 && preg_match('/\bvol(?:ume)?\.?\s*(\d+)/i', (string) $row->label, $m)) {
            $book = (int) $m[1];
        }

        $existing = self::existingNumbers($row, $book);
        if ($existing->isEmpty()) {
            return null;
        }

        $taken = array_flip($existing->unique()->all());
        $max = $existing->max();
        // A candidate fits if nobody uses it and it doesn't overshoot the
        // sequence by more than one (max+1 = the next chapter, e.g. afterwords).
        $fits = fn($n) => !isset($taken[$n]) && $n <= $max + 1;

        $cands = self::candidates($row->label, $row->url);

        $freeStrong = array_values(array_filter($cands['strong'], $fits));
        if (count($freeStrong) === 1) {
            return [$freeStrong[0], 'elimination', $book];
        }
        if (count($freeStrong) > 1) {
            // Several possibilities — trust the URL slug if it narrows to one.
            $freeUrl = array_values(array_intersect($cands['url'], $freeStrong));
            if (count($freeUrl) === 1) {
                return [$freeUrl[0], 'url slug', $book];
            }
            return null;
        }

        if (empty($cands['strong'])) {
            $freeWeak = array_values(array_filter($cands['weak'], $fits));
            if (count($freeWeak) === 1) {
                return [$freeWeak[0], 'elimination (weak)', $book];
            }
            // End-matter with no number belongs after the last chapter.
            if (empty($cands['weak']) && preg_match('/afterword|epilogue|postscript/i', (string) $row->label)) {
                return [$max + 1, 'end-matter', $book];
            }
        }

        return null;
    }

    /**
     * Whole-number chapters already used by the novel (same book when the
     * novel is book-scoped), excluding the row itself.
     */
    public static function existingNumbers(NovelChapter $row, ?int $book = null)
    {
        $book = $book ?? (int) $row->book;

        return NovelChapter::where('novel_id', $row->novel_id)
            ->where('id', '!=', $row->id)
            ->where('blacklist', 0)
            ->when($book > 0, fn($q) => $q->where('book', $book))
            ->pluck('chapter')
            ->map(fn($n) => (int) floor((float) $n))
            ->filter(fn($n) => $n > 0);
    }

    /**
     * Resolve and persist numbers for a novel's unnumbered chapters.
     * Only applies unambiguous resolutions; returns [fixedCount, unresolvedCount].
     */
    public static function fixUnnumbered(int $novelId, ?callable $report = null): array
    {
        $rows = NovelChapter::where('novel_id', $novelId)
            ->where('blacklist', 0)
            ->where('chapter', '<=', 0)
            ->get();

        $fixed = 0;
        $unresolved = 0;

        foreach ($rows as $row) {
            $result = self::resolve($row);
            if ($result) {
                [$number, $reason, $book] = $result;
                $row->chapter = $number;
                if ($book > 0) {
                    $row->book = $book;
                }
                $row->label = trim((string) $row->label);
                $row->save();
                $fixed++;
                if ($report) {
                    $report("Fixed chapter {$number} ({$reason}): {$row->label}");
                }
            } else {
                $unresolved++;
                if ($report) {
                    $report("Unresolved: id={$row->id} \"{$row->label}\"");
                }
            }
        }

        return [$fixed, $unresolved];
    }
}
