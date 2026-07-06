<?php

namespace App\Console\Commands;

use App\Novel;
use App\NovelChapter;
use App\Services\ChapterNumberResolver;
use Illuminate\Console\Command;

class FixChapterNumbers extends Command
{
    protected $signature = 'novel:fix_chapters {novel=0} {--dry-run : Preview changes without saving}';
    protected $description = 'Resolve chapters with missing chapter numbers by process of elimination against the novel sequence.';

    public function handle(): int
    {
        $novelId = (int) $this->argument('novel');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be saved');
        }

        $novels = Novel::whereHas('chapters', fn($q) => $q->where('chapter', '<=', 0)->where('blacklist', 0))
            ->when($novelId != 0, fn($q) => $q->where('id', $novelId))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($novels->isEmpty()) {
            $this->info('No chapters with missing numbers found.');
            return self::SUCCESS;
        }

        $totalFixed = $totalDeleted = $totalManual = 0;

        foreach ($novels as $novel) {
            $this->info("Processing: [{$novel->id}] {$novel->name}");

            $rows = NovelChapter::where('novel_id', $novel->id)
                ->where('blacklist', 0)
                ->where('chapter', '<=', 0)
                ->get();

            foreach ($rows as $row) {
                $result = ChapterNumberResolver::resolve($row);

                if ($result) {
                    [$number, $reason, $book] = $result;
                    $bookNote = $book > 0 && $book != $row->book ? " book => {$book}" : '';
                    $this->line("  Fix: id={$row->id} chapter => {$number}{$bookNote} ({$reason}) \"" . trim((string) $row->label) . "\"");
                    if (!$dryRun) {
                        $row->chapter = $number;
                        if ($book > 0) {
                            $row->book = $book;
                        }
                        $row->label = trim((string) $row->label);
                        $row->save();
                    }
                    $totalFixed++;
                    continue;
                }

                // No free candidate — the numbers this label carries may all be
                // taken, which means this row duplicates an existing chapter.
                $handled = $this->handleDuplicate($row, $dryRun);
                if ($handled) {
                    $totalDeleted++;
                } else {
                    $this->warn("  Manual review: id={$row->id} \"" . trim((string) $row->label) . "\" url={$row->url}");
                    $totalManual++;
                }
            }
        }

        $this->line('');
        $this->info("Fixed: {$totalFixed}, duplicates removed: {$totalDeleted}, needing manual review: {$totalManual}");

        return self::SUCCESS;
    }

    /**
     * When every candidate number is already taken, this row duplicates an
     * existing chapter. Keep whichever copy is downloaded; report when both are.
     */
    private function handleDuplicate(NovelChapter $row, bool $dryRun): bool
    {
        // Same URL scraped twice — typically the site changed the label between
        // scrapes (e.g. added a "PAID" prefix), the re-parse yielded chapter 0
        // and NovelScraper created a second row for the same page.
        if ($row->url) {
            $sameUrl = NovelChapter::where('novel_id', $row->novel_id)
                ->where('id', '!=', $row->id)
                ->where('blacklist', 0)
                ->where('url', $row->url)
                ->where('chapter', '>', 0)
                ->first();

            if ($sameUrl) {
                if ($row->status == 1 && $sameUrl->status == 0) {
                    // The unnumbered copy holds the content — take over the number.
                    $this->line("  Dup(url): id={$row->id} takes chapter {$sameUrl->chapter}; deleting empty duplicate id={$sameUrl->id}");
                    if (!$dryRun) {
                        $row->chapter = $sameUrl->chapter;
                        $row->book = $sameUrl->book;
                        $row->label = trim((string) $row->label);
                        $sameUrl->delete();
                        $row->save();
                    }
                } else {
                    $this->line("  Dup(url): deleting id={$row->id}, same page already tracked as chapter {$sameUrl->chapter} (id={$sameUrl->id})");
                    if (!$dryRun) {
                        $row->delete();
                    }
                }
                return true;
            }
        }

        $cands = ChapterNumberResolver::candidates($row->label, $row->url);
        if (empty($cands['strong'])) {
            return false;
        }

        $number = $cands['url'][0] ?? $cands['strong'][0];

        $existing = NovelChapter::where('novel_id', $row->novel_id)
            ->where('id', '!=', $row->id)
            ->where('blacklist', 0)
            ->whereRaw('FLOOR(chapter) = ?', [$number])
            ->when((int) $row->book > 0, fn($q) => $q->where('book', (int) $row->book))
            ->first();

        if (!$existing) {
            return false;
        }

        if ($row->status == 1 && $existing->status == 0) {
            // This copy has content, the numbered one doesn't — swap them.
            $this->line("  Dup: id={$row->id} takes chapter {$number}; deleting empty duplicate id={$existing->id}");
            if (!$dryRun) {
                $existing->delete();
                $row->chapter = $number;
                $row->label = trim((string) $row->label);
                $row->save();
            }
            return true;
        }

        if ($row->status == 0 && $existing->status == 1) {
            $this->line("  Dup: deleting empty id={$row->id}, chapter {$number} already downloaded as id={$existing->id}");
            if (!$dryRun) {
                $row->delete();
            }
            return true;
        }

        return false;
    }
}
