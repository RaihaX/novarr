<?php

namespace App\Console\Commands;

use App\Books\WebNovelCheck;
use App\Novel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * books:webnovel-check — move books that are web novels to Novels
 * (App\Books\WebNovelCheck has the rules).
 *
 * Each run checks up to --limit books that were never checked, oldest
 * first, one NovelUpdates lookup at a time with a pause in between
 * (novarr.books.webnovel_check_delay seconds: NovelUpdates is behind
 * Cloudflare and rate limited). Every book that got an answer is stamped
 * webnovel_checked_at, so it is looked up once; a failed lookup is not, and
 * is tried again next run. After webnovel_check_max_failures failures in a
 * row the run stops: NovelUpdates or FlareSolverr is down, and asking again
 * only hammers it.
 *
 *   --id=*      only these books (checked again even when checked before)
 *   --dry-run   say what would move (book title → NovelUpdates title, URL,
 *               score), write nothing
 *   --undo=ID   put a converted series back in Books, exactly as it was
 */
class BookWebNovelCheck extends Command
{
    protected $signature = 'books:webnovel-check
        {--limit=200 : Books checked in this run}
        {--dry-run : Show what would move to Novels; change nothing}
        {--id=* : Only these book IDs (checked again if they were checked before)}
        {--undo= : Put this converted series back in Books}';

    protected $description = 'Move books that are web novels on NovelUpdates to Novels, with the NovelUpdates metadata';

    public function handle(WebNovelCheck $check): int
    {
        if (($undo = $this->option('undo')) !== null && $undo !== '') {
            return $this->undo($check, (int) $undo);
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        $query = $ids !== []
            ? Novel::books()->whereIn('id', $ids)->orderBy('id')
            : WebNovelCheck::pending();
        $books = $query->limit($limit)->get();

        if ($books->isEmpty()) {
            $this->info($ids !== [] ? 'None of those IDs is a book.' : 'No books left to check.');
            return self::SUCCESS;
        }

        $delay = max(0.0, (float) config('novarr.books.webnovel_check_delay', 3));
        $maxFailures = max(1, (int) config('novarr.books.webnovel_check_max_failures', 5));
        $counts = ['checked' => 0, WebNovelCheck::CONVERTED => 0, WebNovelCheck::NOT_WEB_NOVEL => 0, WebNovelCheck::DUPLICATE => 0, WebNovelCheck::FAILED => 0];
        $failuresInARow = 0;

        foreach ($books as $i => $book) {
            if ($i > 0 && $delay > 0) {
                $this->pause($delay);
            }

            try {
                $outcome = $check->evaluate($book);
            } catch (\Throwable $e) {
                Log::warning("books:webnovel-check: lookup of book {$book->id} threw: " . $e->getMessage());
                $outcome = ['result' => WebNovelCheck::FAILED, 'reason' => 'error: ' . $e->getMessage(), 'url' => null, 'title' => null, 'score' => null, 'metadata' => null];
            }
            $result = $outcome['result'];

            if ($result === WebNovelCheck::FAILED) {
                $counts[WebNovelCheck::FAILED]++;
                $this->warn("  ! #{$book->id} {$book->name}: {$outcome['reason']} (tried again next run)");
                if (++$failuresInARow >= $maxFailures) {
                    $this->warn("  {$failuresInARow} lookups failed in a row: stopping (NovelUpdates or FlareSolverr is not answering).");
                    break;
                }
                continue;
            }
            $failuresInARow = 0;
            $counts['checked']++;

            if ($result === WebNovelCheck::CONVERTED) {
                $line = sprintf('  → #%d %s → %s (%s, score %.3f)', $book->id, $book->name, $outcome['title'] ?? '?', $outcome['url'], (float) $outcome['score']);
                if ($dryRun) {
                    $this->line($line . ' [dry run]');
                } else {
                    try {
                        $check->convert($book, $outcome);
                        $this->info($line);
                    } catch (\Throwable $e) {
                        // Not stamped: tried again next run.
                        Log::error("books:webnovel-check: converting book {$book->id} failed: " . $e->getMessage());
                        $this->error("  ! #{$book->id} {$book->name}: converting failed: " . $e->getMessage());
                        $counts['checked']--;
                        $counts[WebNovelCheck::FAILED]++;
                        continue;
                    }
                }
                $counts[WebNovelCheck::CONVERTED]++;
                continue;
            }

            $counts[$result]++;
            $this->line("  · #{$book->id} {$book->name}: {$outcome['reason']}", null, 'v');
            if (!$dryRun) {
                $book->forceFill(['webnovel_checked_at' => now()])->saveQuietly();
            }
        }

        $summary = sprintf(
            '%sChecked %d: %d %s to Novels, %d not web novels, %d already in Novels; %d failed.',
            $dryRun ? '[dry run] ' : '',
            $counts['checked'],
            $counts[WebNovelCheck::CONVERTED],
            $dryRun ? 'would move' : 'moved',
            $counts[WebNovelCheck::NOT_WEB_NOVEL],
            $counts[WebNovelCheck::DUPLICATE],
            $counts[WebNovelCheck::FAILED],
        );
        $this->info($summary);
        if (!$dryRun) {
            Log::info('books:webnovel-check: ' . $summary);
        }

        return self::SUCCESS;
    }

    private function undo(WebNovelCheck $check, int $id): int
    {
        $novel = Novel::find($id);
        if ($novel === null) {
            $this->error("Series {$id} not found.");
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $was = $novel->converted_snapshot['fields']['name'] ?? null;
            $this->info($novel->wasConvertedFromBook()
                ? "[dry run] Would put #{$id} \"{$novel->name}\" back in Books as \"{$was}\"."
                : "#{$id} \"{$novel->name}\" was not moved from Books by this check.");
            return $novel->wasConvertedFromBook() ? self::SUCCESS : self::FAILURE;
        }

        try {
            $check->undo($novel);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info("#{$id} is back in Books as \"{$novel->name}\".");

        return self::SUCCESS;
    }

    /** Wait between lookups; overridden in tests. */
    protected function pause(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
