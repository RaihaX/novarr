<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Novel;
use App\Mail\NewChapters;
use Carbon\Carbon;

class ChapterScraper extends Command
{
    protected $signature = "novel:chapter {novel=0} {--chapter= : Download a single chapter by its id}";
    protected $description = "Scrape new chapters for each novel.";

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if ($this->option("chapter")) {
            return $this->scrapeSingleChapter((int) $this->option("chapter"));
        }

        $novelId = $this->argument("novel");
        Log::info("Starting chapter scraping for novel ID: $novelId");

        $newChapters = $this->scrapeChapters($novelId);

        Log::info(
            "Finished chapter scraping. Total new chapters: " .
                count($newChapters)
        );
    }

    /**
     * Download exactly one chapter — used by the reader's "Download this
     * chapter" button on pending chapters. No polite-delay sleep since it's
     * a single user-triggered fetch.
     */
    private function scrapeSingleChapter(int $chapterId): int
    {
        $chapter = \App\NovelChapter::with("novel")->find($chapterId);

        if (!$chapter) {
            $this->error("Chapter {$chapterId} not found.");
            return 1;
        }
        if ($chapter->status) {
            $this->info("Chapter already downloaded: {$chapter->label}");
            return 0;
        }

        $this->info("Downloading: {$chapter->novel->name} - {$chapter->label}");
        $failureReason = null;
        $description = $this->generateChapterDescription($chapter, $failureReason);
        $wordCount = str_word_count($description);
        $minWords = (int) setting("min_chapter_words", 250);

        if (self::acceptableWordCount($chapter->label, $wordCount, $minWords)) {
            if ($wordCount <= $minWords) {
                $this->info("Accepted short special chapter: {$chapter->label} ({$wordCount} words)");
                Log::info("Accepted short special chapter: {$chapter->label} ({$wordCount} words)");
            }

            $this->updateChapter($chapter, $description);
            \App\Http\Helpers\CacheHelper::clearNovelCache($chapter->novel_id);
            $this->info("  ✓ Downloaded: {$chapter->label} ({$wordCount} words)");
            return 0;
        }

        $hint = match ($failureReason) {
            "fetch_failed" => "The chapter page could not be fetched.",
            "cloudflare" => "The source is answering with a Cloudflare challenge.",
            "no_content" => "The page loaded but contains no chapter text.",
            default => "The source may not have this chapter yet.",
        };

        $this->error("  ✗ Fetched only {$wordCount} words (need >{$minWords}) — not saved. {$hint}");
        return 1;
    }

    private function scrapeChapters($novelId)
    {
        $newChapters = [];
        Log::debug("Building query for novels.");

        $query = Novel::where("status", 0)
            ->where("group_id", "!=", 37)
            ->whereHas("chapters", function ($q) {
                $q->where("status", 0)->where("blacklist", 0);
            });

        if ($novelId != 0) {
            $query->where("id", $novelId);
        } else {
            // Paused novels are skipped in the automatic sweep but still run
            // when a specific novel is requested explicitly.
            $query->whereNull("paused_at");
        }

        $query
            ->with([
                "chapters" => function ($q) {
                    $q->where("status", 0)
                        ->where("blacklist", 0)
                        ->orderBy("book")
                        ->orderBy("chapter");
                },
            ])
            ->orderBy("name", "desc")
            ->chunk(5, function ($novels) use (&$newChapters) {
                foreach ($novels as $novel) {
                    Log::info("Processing novel: {$novel->name}");
                    $this->processNovel($novel, $newChapters);
                }
            });

        return $newChapters;
    }

    private function processNovel($novel, &$newChapters)
    {
        $succeeded = 0;
        $failed = 0;

        // Why the failures happened, so the health report can name the actual
        // cause instead of guessing "the source site may have changed".
        $stats = [
            "bad_url" => 0,
            "fetch_failed" => 0,
            "cloudflare" => 0,
            "no_content" => 0,
            "short_content" => 0,
            "example_bad_url" => null,
            "short_min" => null,
            "short_max" => null,
        ];

        // Resolved once per run — the acceptance helper stays pure.
        $minWords = (int) setting("min_chapter_words", 250);

        if (count($novel->chapters) > 0) {
            foreach ($novel->chapters as $item) {
                Log::debug("Processing chapter: {$item->label}");
                $this->info("Processing: {$novel->name} - {$item->label}");

                // A bad TOC parse can leave junk pending rows behind (label
                // "Arial", url "https://novelfull.comArial, sans-serif") —
                // those are never worth a fetch.
                $url = (string) chapterSourceUrl($item);
                if (!preg_match('~^https?://[^\s,]+$~', $url)) {
                    $failed++;
                    $stats["bad_url"]++;
                    $stats["example_bad_url"] = $stats["example_bad_url"] ?? $url;
                    $this->error("  ✗ Skipped: invalid source URL \"{$url}\"");
                    Log::warning(
                        "Chapter skipped due to invalid source URL: {$item->label} (\"{$url}\")"
                    );
                    continue;
                }

                $failureReason = null;
                $description = $this->generateChapterDescription($item, $failureReason);

                $wordCount = str_word_count($description);
                $this->line("  → Fetched {$wordCount} words");

                if (self::acceptableWordCount($item->label, $wordCount, $minWords)) {
                    if ($wordCount <= $minWords) {
                        $this->info("Accepted short special chapter: {$item->label} ({$wordCount} words)");
                        Log::info("Accepted short special chapter: {$item->label} ({$wordCount} words)");
                    }

                    Log::debug("Chapter description valid for: {$item->label}");
                    Log::info("Successfully downloaded chapter: {$item->label} ({$wordCount} words)");
                    $this->info("  ✓ Downloaded: {$item->label} ({$wordCount} words)");

                    $succeeded++;
                    $this->updateChapter($item, $description);
                    $this->addChapterToArray($novel, $item, $newChapters);

                    // Polite, human-like delay between chapters. Range is
                    // configurable from Settings (defaults 30–90s).
                    $min = max(1, (int) setting('scrape_min_delay', 30));
                    $max = max($min, (int) setting('scrape_max_delay', 90));
                    $readingDelay = rand($min, $max);
                    Log::info("Waiting {$readingDelay} seconds before next chapter...");
                    $this->info("  Waiting {$readingDelay} seconds before next chapter...");
                    sleep($readingDelay);
                } else {
                    $failed++;

                    // Anything the fetcher didn't already explain is judged on
                    // word count: nothing at all vs. a stub chapter.
                    $category = $failureReason ?: ($wordCount == 0 ? "no_content" : "short_content");
                    $stats[$category]++;

                    if ($category === "short_content") {
                        $stats["short_min"] = $stats["short_min"] === null
                            ? $wordCount
                            : min($stats["short_min"], $wordCount);
                        $stats["short_max"] = $stats["short_max"] === null
                            ? $wordCount
                            : max($stats["short_max"], $wordCount);
                    }

                    $this->error("  ✗ Skipped: Only {$wordCount} words (need >{$minWords})");
                    $this->warn("    Cause: {$category}");
                    Log::warning(
                        "Chapter skipped due to insufficient description: {$item->label} ({$wordCount} words, cause: {$category})"
                    );
                }
            }
        }

        $this->trackScrapeHealth($novel, $succeeded, $failed, $stats);
    }

    /**
     * Is a fetched chapter long enough to keep?
     *
     * The blanket ">250 words" rule threw away real content: prologues, side
     * stories and extras are legitimately short (the ~150-word "Chapter 0:
     * Prologue" of An Investor Who Sees The Future was skipped on every run,
     * forever). The stubs we actually want to reject are junk pages on plain
     * NUMBERED chapters (23–111 words of boilerplate), so the threshold is
     * only relaxed when the label names a special chapter — those are short
     * by nature and almost always real. A 50-word floor still keeps genuinely
     * empty pages out.
     *
     * Pure — no DB, no settings lookup — so callers resolve the configured
     * threshold once per run and pass it in, and it stays unit testable.
     *
     * @param ?string $label     The chapter label, e.g. "Chapter 0: Prologue".
     * @param int     $wordCount Words fetched for the chapter.
     * @param ?int    $threshold Normal minimum; null means the 250 default.
     */
    public static function acceptableWordCount(?string $label, int $wordCount, ?int $threshold = null): bool
    {
        $threshold = $threshold ?? 250;

        if ($wordCount > $threshold) {
            return true;
        }

        $label = trim((string) $label);
        if ($label === "" || $wordCount < 50) {
            return false;
        }

        // Single-quoted so the regex escapes reach preg_match untouched.
        $special = '/\b(prologue|epilogue|prelude|interlude|intermission|afterword|side[\s-]?story|extra|special|announcement)\b/i';

        return preg_match($special, $label) === 1
            || preg_match('/\bchapter\s*0(\D|$)/i', $label) === 1;
    }

    /**
     * Turn the per-run failure counts into one sentence explaining what
     * actually went wrong, most diagnostic cause first. Pure — no DB, no
     * logging — so it can be unit tested on its own.
     *
     * @param array{bad_url?: int, fetch_failed?: int, cloudflare?: int, no_content?: int, short_content?: int, example_bad_url?: ?string, short_min?: ?int, short_max?: ?int} $stats
     */
    public static function summarizeScrapeIssue(array $stats): ?string
    {
        $badUrl = (int) ($stats["bad_url"] ?? 0);
        $fetchFailed = (int) ($stats["fetch_failed"] ?? 0);
        $cloudflare = (int) ($stats["cloudflare"] ?? 0);
        $noContent = (int) ($stats["no_content"] ?? 0);
        $shortContent = (int) ($stats["short_content"] ?? 0);

        $total = $badUrl + $fetchFailed + $cloudflare + $noContent + $shortContent;
        if ($total === 0) {
            return null;
        }

        if ($badUrl > 0) {
            $example = $stats["example_bad_url"] ?? "";
            return "{$badUrl} pending chapter(s) have an invalid source URL (e.g. \"{$example}\") "
                . "— the TOC scrape may have saved garbage entries";
        }

        if ($cloudflare > 0) {
            return "the source site is blocking fetches with a Cloudflare challenge";
        }

        if ($fetchFailed === $total) {
            return "chapter pages could not be fetched";
        }

        if ($noContent > 0) {
            return "chapter pages load but contain no chapter text "
                . "— the chapters may be empty on the source or its layout changed";
        }

        if ($shortContent > 0) {
            $min = (int) ($stats["short_min"] ?? 0);
            $max = (int) ($stats["short_max"] ?? $min);
            $range = $min === $max ? "{$min}" : "{$min}–{$max}";
            return "chapter pages only contain {$range} words (need >250) "
                . "— the source may only have stub chapters";
        }

        return null;
    }

    /**
     * Keep a consecutive-failure counter per novel so runs where every
     * pending chapter fails (site change, dead source) get surfaced in the
     * daily summary email instead of failing silently forever. The run's
     * failure breakdown is summarised into last_scrape_issue so the report
     * can state the cause.
     */
    private function trackScrapeHealth($novel, int $succeeded, int $failed, array $stats = []): void
    {
        if ($succeeded > 0) {
            if ($novel->scrape_failures > 0) {
                Log::info("Scrape recovered for {$novel->name} after {$novel->scrape_failures} failed run(s).");
            }
            $novel->scrape_failures = 0;
            $novel->last_scrape_issue = null;
            $novel->save();
        } elseif ($failed > 0) {
            $issue = self::summarizeScrapeIssue($stats);

            $novel->scrape_failures = $novel->scrape_failures + 1;
            $novel->last_scrape_issue = $issue;
            $novel->save();

            Log::warning(
                "All {$failed} pending chapter(s) failed for {$novel->name} (consecutive failed runs: {$novel->scrape_failures})."
                . ($issue ? " Cause: {$issue}." : "")
            );

            // Alert once when it first crosses the attention threshold, so a
            // dead source pings the webhook rather than only the daily email.
            if ($novel->scrape_failures == 3) {
                $message = "⚠️ {$novel->name} — scraping has failed 3 runs in a row; the source may have changed.";
                if ($issue) {
                    $message .= " Cause: {$issue}.";
                }
                notify_webhook($message);
            }
        }
    }

    private function generateChapterDescription($chapter, ?string &$failureReason = null)
    {
        $description = "";

        foreach (chapterGenerator($chapter, $failureReason) as $c) {
            $description .= $c;
        }
        Log::debug("Generated description for chapter ID: {$chapter->id}");
        return $description;
    }

    private function updateChapter($chapter, $description)
    {
        $chapter->description = $description;
        if (trim($description) != "") {
            $chapter->status = 1;
        }
        $chapter->download_date = Carbon::now();
        $chapter->save();

        Log::info(
            "Updated chapter ID: {$chapter->id}, status set to: {$chapter->status}"
        );
    }

    private function addChapterToArray($novel, $chapter, &$newChapters)
    {
        $progress =
            $novel->no_of_chapters == 0
                ? 0
                : round(($chapter->chapter / $novel->no_of_chapters) * 100, 2);

        $newChapters[] = [
            "novel" => $novel->name,
            "label" => $chapter->label,
            "chapter" => $chapter->chapter,
            "book" => $chapter->book,
            "progress" => number_format($progress, 2, ".", ","),
        ];

        Log::info(
            "Added chapter to array: {$chapter->label}, progress: {$progress}%"
        );
    }
}
