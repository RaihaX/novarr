<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\NovelChapter;
use App\Novel;

/**
 * Tidy chapter labels into "Chapter N - Title" form.
 *
 * Safety rules (see audit F1/F2/F3):
 *  - The stored chapter number is authoritative. It is never floored and is
 *    only replaced when it is <= 0 (unknown) or --renumber is passed.
 *  - The URL is only a fallback source for a missing number, never an override.
 *  - Duplicate removal is opt-in (--dedupe) and scoped to (novel, book, chapter)
 *    among non-blacklisted rows.
 *  - Spam removal only touches parenthesised or trailing whole phrases.
 */
class NormalizeChapterLabels extends Command
{
    protected $signature = 'novel:normalize_labels {novel=0}
                            {--dry-run : Preview changes without saving}
                            {--renumber : Re-derive chapter numbers from the label/URL even when a number is already stored}
                            {--dedupe : Soft-delete duplicate rows sharing novel + book + chapter number}';

    protected $description = 'Normalize chapter labels (and fill in missing chapter numbers) for proper sorting.';

    /**
     * Parenthesised / bracketed promo suffixes: "(Please Subscribe)",
     * "[Seeking Chase Reading]", or a truncated "(Please Continue Read".
     * Requires the opening bracket so ordinary words are never matched.
     */
    private const SPAM_BRACKETED = '/\s*[\(\[]\s*(?:please|seeking|request|subscri\w*|chase\s+reading|must-read|pseudo)\b[^)\]]*[\)\]]?\s*$/i';

    /**
     * Unbracketed promo phrases, only when they are the whole trailing phrase
     * (optionally with a stray closing bracket left behind by truncation).
     * Deliberately an explicit phrase list: a lone trailing "Subscribe" or
     * "Please" could be a real title word.
     */
    private const SPAM_TRAILING = '/\s*\b(?:please\s+continue\s+reading|please\s+chase\s+reading|seeking\s+(?:for\s+|to\s+)?chase\s+reading|request\s+for\s+chase\s+reading|chase\s+reading(?:\s+requested)?|please\s+subscribe|subscribe\s+please|seeking\s+subscriptions?|subscriptions?\s+(?:wanted|requested))\s*[\)\]]?\s*$/i';

    public function handle()
    {
        $novelId = (int) $this->argument('novel');
        $dryRun = (bool) $this->option('dry-run');
        $renumber = (bool) $this->option('renumber');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be saved');
        }

        $query = NovelChapter::query();

        if ($novelId !== 0) {
            $novel = Novel::find($novelId);
            if (!$novel) {
                $this->error("Novel {$novelId} not found.");
                return 1;
            }
            $query->where('novel_id', $novelId);
            $this->info("Processing novel: {$novel->name}");
        } else {
            $this->info("Processing all novels");
        }

        $chapters = $query->orderBy('novel_id')->ordered()->get();
        $updatedCount = 0;

        foreach ($chapters as $chapter) {
            $originalLabel = (string) $chapter->label;
            $originalChapterNum = (float) $chapter->chapter;

            $extractedChapterNum = self::extractChapterNumber($originalLabel, $originalChapterNum, $chapter->url, $renumber);
            $normalizedLabel = self::normalizeLabel($originalLabel, $extractedChapterNum);

            $labelChanged = $originalLabel !== $normalizedLabel;
            $chapterNumChanged = abs($originalChapterNum - $extractedChapterNum) > 1e-9;

            if (!$labelChanged && !$chapterNumChanged) {
                continue;
            }

            $updatedCount++;

            if ($dryRun) {
                $this->line("Chapter {$originalChapterNum}:");
                $this->line("  Label FROM: {$originalLabel}");
                $this->info("  Label TO:   {$normalizedLabel}");
                if ($chapterNumChanged) {
                    $this->warn("  Chapter# FROM: {$originalChapterNum} -> TO: {$extractedChapterNum}");
                }
                $this->line('');
                continue;
            }

            $chapter->label = $normalizedLabel;
            if ($chapterNumChanged) {
                $chapter->chapter = $extractedChapterNum;
                $chapter->syncStructuredNumber();
            }
            $chapter->save();
            $chapterInfo = $chapterNumChanged
                ? "Chapter {$originalChapterNum}->{$extractedChapterNum}"
                : "Chapter {$extractedChapterNum}";
            $this->info("Updated: {$chapterInfo} - {$normalizedLabel}");
        }

        $this->info("Total chapters " . ($dryRun ? "to update" : "updated") . ": {$updatedCount}");

        if ($this->option('dedupe')) {
            $this->line('');
            $this->removeDuplicateChapters($novelId, $dryRun);
        }

        return 0;
    }

    /**
     * Soft-delete duplicate rows (same novel + book + chapter number) among
     * live, non-blacklisted chapters. Only runs with --dedupe.
     */
    private function removeDuplicateChapters(int $novelId, bool $dryRun): void
    {
        $this->info('Checking for duplicate chapters...');

        // SoftDeletes scope already excludes deleted_at IS NOT NULL.
        $query = NovelChapter::query()
            ->select('novel_id', 'book', 'chapter')
            ->selectRaw('COUNT(*) as dup_count')
            ->where('blacklist', 0)
            ->groupBy('novel_id', 'book', 'chapter')
            ->havingRaw('COUNT(*) > 1');

        if ($novelId !== 0) {
            $query->where('novel_id', $novelId);
        }

        $duplicates = $query->get();

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate chapters found.');
            return;
        }

        $this->warn("Found {$duplicates->count()} chapter numbers with duplicates.");
        $deletedCount = 0;

        foreach ($duplicates as $dup) {
            $rows = NovelChapter::where('novel_id', $dup->novel_id)
                ->where('chapter', $dup->chapter)
                ->where('blacklist', 0)
                ->when($dup->book === null,
                    fn($q) => $q->whereNull('book'),
                    fn($q) => $q->where('book', $dup->book))
                ->get();

            [$keepId, $deleteIds] = self::planDuplicateRemoval($rows->map(fn($c) => [
                'id' => $c->id,
                'status' => (int) $c->status,
                'url' => $c->url,
            ])->all());

            $keep = $rows->firstWhere('id', $keepId);

            foreach ($rows->whereIn('id', $deleteIds) as $chapter) {
                $deletedCount++;
                $statusText = $chapter->status ? 'Downloaded' : 'Pending';
                $urlNote = $chapter->url !== $keep->url ? ' [different URL]' : '';
                if ($dryRun) {
                    $this->line("Would delete: Chapter {$chapter->chapter} - {$chapter->label} ({$statusText}){$urlNote}");
                    $this->info("  Keeping: #{$keep->id} {$keep->label} (" . ($keep->status ? 'Downloaded' : 'Pending') . ")");
                } else {
                    $chapter->delete(); // Soft delete
                    $this->warn("Deleted duplicate: Chapter {$chapter->chapter} - {$chapter->label}{$urlNote}");
                }
            }
        }

        $this->info("Total duplicate chapters " . ($dryRun ? "to delete" : "deleted") . ": {$deletedCount}");
    }

    /**
     * Pick which of a group of duplicate rows to keep: a downloaded row
     * (status=1) beats a pending one, then the lowest id wins.
     *
     * @param  array<int, array{id:int, status:int|bool, url:?string}>  $rows
     * @return array{0: int|null, 1: int[]}  [keep id, ids to delete]
     */
    public static function planDuplicateRemoval(array $rows): array
    {
        if (empty($rows)) {
            return [null, []];
        }

        usort($rows, function ($a, $b) {
            $byStatus = (int) (bool) $b['status'] <=> (int) (bool) $a['status'];
            return $byStatus !== 0 ? $byStatus : $a['id'] <=> $b['id'];
        });

        $keep = array_shift($rows);

        return [$keep['id'], array_map(fn($r) => $r['id'], $rows)];
    }

    /**
     * Decide the chapter number for a row.
     *
     * The stored number is returned untouched (decimals included) unless it is
     * <= 0 or $renumber is set. Only then is a number derived: from the label
     * ("Chapter 12"), else from the URL ("chapter-12"), plus a part suffix
     * from the label. If nothing can be derived the stored value is kept.
     *
     * Part encoding is Helpers' encodeChapterPart(): parts 1–9 -> .1–.9,
     * 10–18 -> .91–.99, higher clamped to .99.
     */
    public static function extractChapterNumber(string $label, $storedChapterNum, ?string $url = null, bool $renumber = false): float
    {
        $stored = (float) $storedChapterNum;

        if ($stored > 0 && !$renumber) {
            return $stored;
        }

        $base = null;
        if (preg_match('/^\s*Chapter\s*(\d+(?:\.\d+)?)/i', $label, $m)) {
            $base = (float) $m[1];
        }
        if (($base === null || $base <= 0) && $url && preg_match('/chapter-(\d+)/i', $url, $m)) {
            $base = (float) $m[1];
        }

        if ($base === null || $base <= 0) {
            return $stored;
        }

        // A label number that already carries a decimal is used as-is.
        if (floor($base) != $base) {
            return $base;
        }

        $part = self::partNumber($label);

        return $part > 0
            ? (float) encodeChapterPart((string) (int) $base, $part)
            : $base;
    }

    /**
     * Part number from a label's trailing part marker, or 0. Recognised forms
     * only: "Title_2", "Title (Part 2)", "Title Part 2", "Title (2)". A digit
     * glued to a word ("season2", "wizard2") is NOT a part marker.
     */
    public static function partNumber(string $label): int
    {
        $label = trim($label);

        if (preg_match('/_(\d+)$/', $label, $m)
            || preg_match('/\(\s*Part\s*(\d+)\s*\)$/i', $label, $m)
            || preg_match('/\bPart\s+(\d+)$/i', $label, $m)
            || preg_match('/\(\s*(\d+)\s*\)$/', $label, $m)) {
            return max(0, (int) $m[1]);
        }

        return 0;
    }

    /**
     * Decimal offset for a label's part marker, encoded exactly like
     * encodeChapterPart(): part 2 -> 0.2, part 10 -> 0.91, part 12 -> 0.93.
     */
    public static function partSuffix(string $label): float
    {
        return (float) encodeChapterPart('0', self::partNumber($label));
    }

    /**
     * Normalize a chapter label.
     */
    public static function normalizeLabel(string $label, $chapterNumber): string
    {
        $cleaned = self::removeSpamPhrases($label);
        $cleaned = self::reformatChapterLabel($cleaned, $chapterNumber);
        return self::cleanupWhitespace($cleaned);
    }

    /**
     * Remove promo/spam suffixes. Only parenthesised/bracketed suffixes or an
     * explicit trailing phrase are removed — words inside a title
     * ("The Subscriber Returns", "Chase Reading Kings") are left alone.
     */
    public static function removeSpamPhrases(string $label): string
    {
        // Repeat: labels sometimes stack several suffixes.
        for ($i = 0; $i < 5; $i++) {
            $before = $label;
            $label = preg_replace(self::SPAM_BRACKETED, '', $label);
            $label = preg_replace(self::SPAM_TRAILING, '', $label);
            if ($label === $before) {
                break;
            }
        }

        return $label;
    }

    /**
     * Reformat to "Chapter X - Title". X is the label's own number (a label
     * number of 0 is replaced by the chapter number); repeated copies of that
     * number at the start of the title are dropped:
     *  - "Chapter 1 - 1 1 Title"    -> "Chapter 1 - Title"
     *  - "Chapter321 321 Title_2"   -> "Chapter 321 - Title (Part 2)"
     *  - "Chapter 100 - 94 Title"   -> "Chapter 100 - 94 Title" (94 may be a real title number)
     *  - "Chapter 5 - 1000 Years"   -> "Chapter 5 - 1000 Years" (not the chapter number)
     */
    private static function reformatChapterLabel(string $label, $chapterNumber): string
    {
        // "Chapter 5 - Title" (dash separator) or the glued "Chapter321 191 Title"
        // form. Other shapes ("Chapter 5: Title") are left as they are.
        if (preg_match('/^Chapter\s*(\d+(?:\.\d+)?)\s*-\s*(.+)$/i', $label, $m)
            || preg_match('/^Chapter(\d+)\s+(\d+\s+.+)$/i', $label, $m)) {
            $num = $m[1];
            if ((float) $num <= 0 && (float) $chapterNumber > 0) {
                $num = self::formatNumber((float) $chapterNumber);
            }
            $title = self::cleanTitle($m[2], $num);
            return $title === '' ? "Chapter {$num}" : "Chapter {$num} - {$title}";
        }

        return self::cleanTitle($label, self::formatNumber((float) $chapterNumber));
    }

    /** "12" for 12.0, "12.5" for 12.5. */
    private static function formatNumber(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
    }

    /**
     * Clean title text: part suffixes, spam, duplicated leading chapter
     * number and unbalanced parentheses.
     */
    private static function cleanTitle(string $title, ?string $chapterNumber = null): string
    {
        $title = trim($title);

        // Extract a "_2" part suffix before spam removal ("(Please Subscribe)_2").
        $partNumber = null;
        if (preg_match('/\s*_(\d+)$/', $title, $matches)) {
            $partNumber = $matches[1];
            $title = preg_replace('/\s*_\d+$/', '', $title);
        }

        $title = self::removeSpamPhrases($title);

        // Drop leading numbers only when they repeat the chapter number.
        if ($chapterNumber !== null && $chapterNumber !== '') {
            $quoted = preg_quote($chapterNumber, '/');
            while (preg_match('/^' . $quoted . '\s+(?=\S)/', $title)) {
                $title = preg_replace('/^' . $quoted . '\s+/', '', $title, 1);
            }
        }

        // Remove a trailing unclosed parenthetical, keep balanced ones.
        $title = preg_replace('/\s*\([^)]*$/', '', $title);

        // Orphan closing paren with no matching open paren.
        if (preg_match('/\)$/', $title) && substr_count($title, '(') < substr_count($title, ')')) {
            $title = preg_replace('/\s*\)$/', '', $title);
        }

        $title = trim($title, " \t\n\r\0\x0B-_.");

        if ($partNumber !== null) {
            $title .= " (Part " . $partNumber . ")";
        }

        return $title;
    }

    private static function cleanupWhitespace(string $label): string
    {
        $label = preg_replace('/\s+/', ' ', $label);
        $label = preg_replace('/\s+([,.\)])/', '$1', $label);
        // Separator dashes only — compound words ("Quasi-Knight") untouched.
        $label = preg_replace('/\s+-\s+/', ' - ', $label);
        $label = preg_replace('/\s*-\s*$/', '', $label);

        return trim($label);
    }
}
