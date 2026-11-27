<?php

namespace App\Console\Commands;

use App\Books\WebNovelCheck;
use App\Novel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * books:webnovel-check — move books that are web novels / web fiction to
 * Novels (App\Books\WebNovelCheck has the rules).
 *
 * Each run checks up to --limit books not checked yet, oldest first. A
 * book's own ePub is read first (web-fiction evidence: free, no network);
 * then, for a book never looked up, NovelUpdates and Royal Road, one book
 * at a time with a pause between lookups (novarr.books.webnovel_check_delay
 * seconds: NovelUpdates is behind Cloudflare and rate limited). Each pass
 * that got an answer stamps its marker (webfiction_checked_at,
 * webnovel_checked_at), so it runs once per book; a failed lookup is not
 * stamped and is tried again next run. After webnovel_check_max_failures
 * failed lookups in a row the run stops: NovelUpdates or FlareSolverr is
 * down, and asking again only hammers it.
 *
 *   --evidence-only  only the ePub pass, no network, over every book whose
 *                    ePub was never read (all of them with --all); no
 *                    limit unless --limit is given. The backlog in one go.
 *   --all            ignore the markers: check every book again (a book
 *                    the owner put back with Undo, webnovel_kept_at, is
 *                    skipped: it is never moved again)
 *   --id=*           only these books (checked again even when checked before)
 *   --dry-run        say what would move (book title → new title, rule,
 *                    URL), write nothing
 *   --undo=ID        put a converted series back in Books, exactly as it was
 */
class BookWebNovelCheck extends Command
{
    protected $signature = 'books:webnovel-check
        {--limit= : Books checked in this run (default 200; no limit with --evidence-only)}
        {--dry-run : Show what would move to Novels; change nothing}
        {--evidence-only : Only read each book\'s own ePub for web-fiction evidence (no network)}
        {--all : Check every book again, ignoring the checked markers}
        {--id=* : Only these book IDs (checked again if they were checked before)}
        {--undo= : Put this converted series back in Books}';

    protected $description = 'Move books that are web novels (ePub evidence, NovelUpdates, Royal Road) to Novels';

    public function handle(WebNovelCheck $check): int
    {
        if (($undo = $this->option('undo')) !== null && $undo !== '') {
            return $this->undo($check, (int) $undo);
        }

        $dryRun = (bool) $this->option('dry-run');
        $evidenceOnly = (bool) $this->option('evidence-only');
        $all = (bool) $this->option('all');
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null && $limitOption !== '' ? max(1, (int) $limitOption) : ($evidenceOnly ? null : 200);
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $forced = $ids !== [] || $all;

        if ($ids !== []) {
            $query = Novel::books()->whereIn('id', $ids)->orderBy('id');
        } elseif ($all) {
            // A book the owner put back with Undo stays a book.
            $query = Novel::books()->orderBy('id')->whereNull('webnovel_kept_at');
        } else {
            $query = WebNovelCheck::pending($evidenceOnly);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $delay = max(0.0, (float) config('novarr.books.webnovel_check_delay', 3));
        $maxFailures = max(1, (int) config('novarr.books.webnovel_check_max_failures', 5));
        $counts = ['checked' => 0, WebNovelCheck::CONVERTED => 0, WebNovelCheck::NOT_WEB_NOVEL => 0, WebNovelCheck::DUPLICATE => 0, WebNovelCheck::FAILED => 0];
        $byRule = [];
        $failuresInARow = 0;
        $lookedUp = false;
        $seen = 0;
        $stopped = false;

        $bookIds = (clone $query)->pluck('id')->all();
        if ($bookIds === []) {
            $this->info($this->option('id') !== [] ? 'None of those IDs is a book.' : 'No books left to check.');
            return self::SUCCESS;
        }

        foreach (array_chunk($bookIds, 200) as $chunk) {
            foreach (Novel::whereIn('id', $chunk)->orderBy('id')->get() as $book) {
                $seen++;
                $evidence = $forced || $book->webfiction_checked_at === null;
                $lookup = !$evidenceOnly && ($forced || $book->webnovel_checked_at === null);
                if (!$evidence && !$lookup) {
                    continue;
                }
                // Pause before a book that may be looked up, after one that was.
                if ($lookup && $lookedUp && $delay > 0) {
                    $this->pause($delay);
                }

                try {
                    $outcome = $check->evaluate($book, $evidence, $lookup);
                } catch (\Throwable $e) {
                    Log::warning("books:webnovel-check: checking book {$book->id} threw: " . $e->getMessage());
                    $outcome = ['result' => WebNovelCheck::FAILED, 'reason' => 'error: ' . $e->getMessage(), 'url' => null, 'title' => null, 'score' => null, 'metadata' => null, 'rule' => null, 'answered' => []];
                }
                $result = $outcome['result'];
                $answered = (array) ($outcome['answered'] ?? []);
                $networked = (bool) ($outcome['networked'] ?? $lookup);
                $lookedUp = $lookedUp || $networked;

                if (!$dryRun && $answered !== [] && $result !== WebNovelCheck::CONVERTED) {
                    $stamp = [];
                    if (in_array(WebNovelCheck::PASS_EVIDENCE, $answered, true)) {
                        $stamp['webfiction_checked_at'] = now();
                    }
                    if (in_array(WebNovelCheck::PASS_LOOKUP, $answered, true)) {
                        $stamp['webnovel_checked_at'] = now();
                    }
                    $book->forceFill($stamp)->saveQuietly();
                }

                if ($result === WebNovelCheck::FAILED) {
                    $counts[WebNovelCheck::FAILED]++;
                    $this->warn("  ! #{$book->id} {$book->name}: {$outcome['reason']} (tried again next run)");
                    // Only failed lookups stop the run (the network is down); a missing ePub does not.
                    if ($networked && ++$failuresInARow >= $maxFailures) {
                        $this->warn("  {$failuresInARow} lookups failed in a row: stopping (NovelUpdates or FlareSolverr is not answering).");
                        $stopped = true;
                        break 2;
                    }
                    continue;
                }
                if ($networked) {
                    $failuresInARow = 0;
                }
                $counts['checked']++;

                if ($result === WebNovelCheck::CONVERTED) {
                    $line = $this->convertedLine($book, $outcome);
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
                    $rule = (string) ($outcome['rule'] ?? WebNovelCheck::RULE_NOVELUPDATES);
                    $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
                    continue;
                }

                $counts[$result]++;
                if ($result === WebNovelCheck::DUPLICATE) {
                    $this->line("  = #{$book->id} {$book->name}: {$outcome['reason']}");
                } else {
                    $this->line("  · #{$book->id} {$book->name}: {$outcome['reason']}", null, 'v');
                }
            }
        }

        $rules = [];
        foreach ([WebNovelCheck::RULE_EVIDENCE => 'by their ePub', WebNovelCheck::RULE_NOVELUPDATES => 'by NovelUpdates', WebNovelCheck::RULE_ROYALROAD => 'by Royal Road'] as $rule => $label) {
            if (!empty($byRule[$rule])) {
                $rules[] = "{$byRule[$rule]} {$label}";
            }
        }
        $summary = sprintf(
            '%sChecked %d: %d %s to Novels, %d not web novels, %d already in Novels; %d failed.%s',
            $dryRun ? '[dry run] ' : '',
            $counts['checked'],
            $counts[WebNovelCheck::CONVERTED],
            $dryRun ? 'would move' : 'moved',
            $counts[WebNovelCheck::NOT_WEB_NOVEL],
            $counts[WebNovelCheck::DUPLICATE],
            $counts[WebNovelCheck::FAILED],
            $rules !== [] ? ($dryRun ? ' Would move ' : ' Moved ') . implode(', ', $rules) . '.' : '',
        );
        $this->info($summary);
        if (!$dryRun) {
            Log::info('books:webnovel-check' . ($evidenceOnly ? ' --evidence-only' : '') . ': ' . $summary);
        }

        return self::SUCCESS;
    }

    /** "  → #12 Old title → New title (rule: url, score)". */
    private function convertedLine(Novel $book, array $outcome): string
    {
        $rule = $outcome['rule'] ?? WebNovelCheck::RULE_NOVELUPDATES;
        if ($rule === WebNovelCheck::RULE_NOVELUPDATES) {
            $line = sprintf('  → #%d %s → %s (%s, score %.3f)', $book->id, $book->name, $outcome['title'] ?? '?', $outcome['url'], (float) $outcome['score']);
            if (($outcome['converted_reason'] ?? WebNovelCheck::REASON_NOVELUPDATES) !== WebNovelCheck::REASON_NOVELUPDATES) {
                $line .= ' [' . $outcome['converted_reason'] . ']';
            }

            return $line;
        }

        return sprintf('  → #%d %s (%s%s)', $book->id, $book->name, $outcome['converted_reason'] ?? $outcome['reason'], !empty($outcome['source_url']) ? ': ' . $outcome['source_url'] : '');
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
