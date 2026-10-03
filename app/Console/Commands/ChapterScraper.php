<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Novel;
use App\Mail\NewChapters;
use Carbon\Carbon;

class ChapterScraper extends Command
{
    /** Consecutive blocking failures (cloudflare / fetch_failed) that stop a novel for the run. */
    public const CIRCUIT_BREAKER_THRESHOLD = 3;

    /** How long a host stays skipped after it answered with a Cloudflare challenge. */
    public const HOST_COOLDOWN_MINUTES = 15;

    /** Upper bound on the pause after a failed fetch. */
    public const FAILURE_DELAY_CAP = 10;

    /** First per-chapter retry delay; doubles with every further failure. */
    public const BACKOFF_BASE_SECONDS = 600;

    /** Longest per-chapter retry delay (3 days). */
    public const BACKOFF_CAP_SECONDS = 259200;

    /** Flat retry delay for transient / site-wide failures (cloudflare, fetch_failed). */
    public const BLOCKING_BACKOFF_SECONDS = 1800;

    /** Failed attempts after which a chapter is "needs review". */
    public const NEEDS_REVIEW_ATTEMPTS = \App\NovelChapter::REVIEW_ATTEMPTS;

    /** Cached Schema::hasColumn('novel_chapters', 'sort_key'). */
    private static ?bool $hasSortKey = null;

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
        self::recordAttempt($chapter);
        $failureReason = null;
        $description = $this->generateChapterDescription($chapter, $failureReason);
        $wordCount = self::countChapterWords($description);
        $minWords = (int) setting("min_chapter_words", 250);

        if (self::acceptableWordCount($chapter->label, $wordCount, $minWords, $description)) {
            if ($wordCount <= $minWords) {
                if (self::specialLabelMatches($chapter->label, $wordCount)) {
                    $this->info("Accepted short special chapter: {$chapter->label} ({$wordCount} words)");
                    Log::info("Accepted short special chapter: {$chapter->label} ({$wordCount} words)");
                } else {
                    $this->info("Accepted author message: {$chapter->label} ({$wordCount} words)");
                    Log::info("Accepted author message: {$chapter->label} ({$wordCount} words)");
                }
            }

            $kind = self::chapterKind($chapter->label, $wordCount, $minWords, $description);
            $this->updateChapter($chapter, $description, $kind);
            \App\Http\Helpers\CacheHelper::clearNovelCache($chapter->novel_id);
            $this->info("  ✓ Downloaded: {$chapter->label} ({$wordCount} words)");
            return 0;
        }

        $hint = match ($failureReason) {
            "fetch_failed" => "The chapter page could not be fetched.",
            "cloudflare" => "The source is answering with a Cloudflare challenge.",
            "no_content" => "The page loaded but contains no chapter text.",
            "not_found" => "The source answers 404 for this chapter — it may have been removed or renumbered there.",
            "exception" => "The scraper hit an error while parsing the page (see the log).",
            default => "The source may not have this chapter yet.",
        };

        // User-triggered, so the schedule was ignored for the fetch — but the
        // failure still counts towards the chapter's backoff.
        self::recordFailure($chapter, $failureReason ?: ($wordCount == 0 ? "no_content" : "short_content"));

        $this->error("  ✗ Fetched only {$wordCount} words (need >{$minWords}) — not saved. {$hint}");
        return 1;
    }

    private function scrapeChapters($novelId)
    {
        $newChapters = [];
        Log::debug("Building query for novels.");

        $query = Novel::where("status", 0)
            ->where("group_id", "!=", 37)
            ->whereHas("chapters", function ($q) use ($novelId) {
                $q->where("status", 0)->where("blacklist", 0);
                if ($novelId == 0) {
                    self::whereDue($q);
                }
            });

        if ($novelId != 0) {
            $query->where("id", $novelId);
        } else {
            // Paused novels are skipped in the automatic sweep but still run
            // when a specific novel is requested explicitly.
            $query->whereNull("paused_at");
        }

        // Novels that have waited longest for a successful download go first
        // (never-downloaded novels sort first: NULL is lowest), so a long run
        // can't starve the same novels every time. Falls back to updated_at.
        $lastDownload = \App\NovelChapter::selectRaw("MAX(download_date)")
            ->whereColumn("novel_chapters.novel_id", "novels.id")
            ->where("status", 1);

        // Snapshot the ids up front: chunking a whereHas(pending) query skips
        // novels as earlier ones drop out of the filter mid-run.
        $ids = $query
            ->orderBy($lastDownload)
            ->orderBy("updated_at")
            ->orderBy("id")
            ->pluck("id");

        // Per-novel cap for the sweep so one huge backlog can't hog a run.
        // An explicitly requested novel is not capped. <= 0 disables the cap.
        $cap = $novelId != 0 ? 0 : (int) setting("max_chapters_per_novel_per_run", 25);

        // Bound the sweep so it always finishes inside the scheduler mutex
        // (150 min; the check runs between novels and one capped novel can
        // take ~40 min): what's left goes to the next run, which starts from the
        // novels that have waited longest. Sweep only; an explicit novel run
        // is never cut short.
        $startedAt = microtime(true);
        $maxMinutes = $novelId != 0 ? 0 : max(0, (int) setting("max_run_minutes", 100));

        foreach ($ids as $id) {
            if ($maxMinutes > 0 && (microtime(true) - $startedAt) > $maxMinutes * 60) {
                $this->warn("Run time limit of {$maxMinutes} minutes reached; remaining novels wait for the next run.");
                Log::info("novel:chapter stopped at the {$maxMinutes}-minute limit; remaining novels deferred to the next run.");
                break;
            }

            $novel = Novel::find($id);
            if (!$novel) {
                continue;
            }

            // Loaded per novel (not up front) so chapters another process
            // downloaded meanwhile aren't fetched again.
            $novel->setRelation(
                "chapters",
                $novel->chapters()
                    ->where("status", 0)
                    ->where("blacklist", 0)
                    // Chapters still backing off after a failure wait — but an
                    // explicit run (the novel page's Download button) is the
                    // user asking for them now, so it ignores the schedule.
                    ->when($novelId == 0, fn($q) => $q->where(fn($d) => self::whereDue($d)))
                    // Never-tried chapters first, then the ones that have
                    // waited longest since their last attempt, so a few dead
                    // or stub chapters at the front can't fill the cap on
                    // every run and starve the rest. Portable NULLS FIRST.
                    ->orderByRaw("last_attempt_at IS NOT NULL")
                    ->orderBy("last_attempt_at")
                    ->orderBy("book")
                    ->when(self::hasSortKey(), fn($q) => $q->orderBy("sort_key"))
                    ->orderBy("chapter")
                    ->when($cap > 0, fn($q) => $q->limit($cap))
                    ->get()
            );

            Log::info("Processing novel: {$novel->name}");
            $this->processNovel($novel, $newChapters);
        }

        return $newChapters;
    }

    private function processNovel($novel, &$newChapters)
    {
        // Another novel on this host just hit a Cloudflare wall — don't keep
        // hammering it. Not counted as a failed run (nothing was attempted).
        $host = self::novelHost($novel);
        if ($host !== null && self::hostCoolingDown($host)) {
            $this->warn("Skipping {$novel->name}: {$host} is cooling down after a Cloudflare challenge");
            Log::warning("Skipping {$novel->name}: host {$host} is in a Cloudflare cooldown");
            return;
        }

        $succeeded = 0;
        $failed = 0;

        // Why the failures happened, so the health report can name the actual
        // cause instead of guessing "the source site may have changed".
        $stats = [
            "bad_url" => 0,
            "fetch_failed" => 0,
            "not_found" => 0,   // source answers 404: the chapter is gone there
            "exception" => 0,   // parser/DOM error while reading the page
            "cloudflare" => 0,
            "no_content" => 0,
            "short_content" => 0,
            // Not a failure: numbered chapters accepted because their body is
            // an author/translator message. Ignored by summarizeScrapeIssue().
            "author_note" => 0,
            // Pending chapters left untried because the circuit breaker
            // opened (too many consecutive blocked/failed fetches).
            "circuit_open" => 0,
            // Not counted as failures: chapters that have failed
            // NEEDS_REVIEW_ATTEMPTS+ times and failed again. They keep being
            // retried on the 3-day cadence but no longer flag the novel.
            "needs_review" => 0,
            "example_bad_url" => null,
            "short_min" => null,
            "short_max" => null,
        ];

        // Resolved once per run — the acceptance helper stays pure.
        $minWords = (int) setting("min_chapter_words", 250);

        // Consecutive cloudflare / fetch_failed results for this novel.
        $consecutiveBlocked = 0;
        $pendingTotal = count($novel->chapters);
        $attempted = 0;

        if ($pendingTotal > 0) {
            foreach ($novel->chapters as $item) {
                // chapterGenerator reads $chapter->novel; share the loaded
                // model instead of lazy-loading it once per chapter.
                $item->setRelation("novel", $novel);
                if (self::circuitOpen($consecutiveBlocked)) {
                    $stats["circuit_open"] = $pendingTotal - $attempted;
                    $this->error("  ✗ {$consecutiveBlocked} fetches in a row failed — stopping {$novel->name} for this run");
                    Log::warning(
                        "Circuit breaker opened for {$novel->name} after {$consecutiveBlocked} consecutive failed fetches; "
                        . "{$stats["circuit_open"]} pending chapter(s) left for the next run."
                    );
                    break;
                }
                $attempted++;

                Log::debug("Processing chapter: {$item->label}");
                $this->info("Processing: {$novel->name} - {$item->label}");

                // A bad TOC parse can leave junk pending rows behind (label
                // "Arial", url "https://novelfull.comArial, sans-serif") —
                // those are never worth a fetch.
                $url = (string) chapterSourceUrl($item);
                if (!preg_match('~^https?://[^\s,]+$~', $url)) {
                    if (self::isNeedsReview(self::recordFailure($item, "bad_url"))) {
                        $stats["needs_review"]++;
                        $this->error("  ✗ Skipped (needs review): invalid source URL \"{$url}\"");
                        continue;
                    }
                    $failed++;
                    $stats["bad_url"]++;
                    self::recordAttempt($item);
                    $stats["example_bad_url"] = $stats["example_bad_url"] ?? $url;
                    $this->error("  ✗ Skipped: invalid source URL \"{$url}\"");
                    Log::warning(
                        "Chapter skipped due to invalid source URL: {$item->label} (\"{$url}\")"
                    );
                    continue;
                }

                $failureReason = null;
                self::recordAttempt($item);
                $description = $this->generateChapterDescription($item, $failureReason);

                $wordCount = self::countChapterWords($description);
                $this->line("  → Fetched {$wordCount} words");

                if (self::acceptableWordCount($item->label, $wordCount, $minWords, $description)) {
                    if ($wordCount <= $minWords) {
                        if (self::specialLabelMatches($item->label, $wordCount)) {
                            $this->info("Accepted short special chapter: {$item->label} ({$wordCount} words)");
                            Log::info("Accepted short special chapter: {$item->label} ({$wordCount} words)");
                        } else {
                            $stats["author_note"]++;
                            $this->info("Accepted author message: {$item->label} ({$wordCount} words)");
                            Log::info("Accepted author message: {$item->label} ({$wordCount} words)");
                        }
                    }

                    Log::debug("Chapter description valid for: {$item->label}");
                    Log::info("Successfully downloaded chapter: {$item->label} ({$wordCount} words)");
                    $this->info("  ✓ Downloaded: {$item->label} ({$wordCount} words)");

                    $succeeded++;
                    $consecutiveBlocked = 0;
                    $this->updateChapter(
                        $item,
                        $description,
                        self::chapterKind($item->label, $wordCount, $minWords, $description)
                    );
                    $this->addChapterToArray($novel, $item, $newChapters);

                    // Polite, human-like delay between chapters. Range is
                    // configurable from Settings (defaults 30–90s).
                    $min = max(1, (int) setting('scrape_min_delay', 30));
                    $max = max($min, (int) setting('scrape_max_delay', 90));
                    $readingDelay = rand($min, $max);
                    Log::info("Waiting {$readingDelay} seconds before next chapter...");
                    $this->info("  Waiting {$readingDelay} seconds before next chapter...");
                    $this->pause($readingDelay);
                } else {
                    // Anything the fetcher didn't already explain is judged on
                    // word count: nothing at all vs. a stub chapter.
                    $category = $failureReason ?: ($wordCount == 0 ? "no_content" : "short_content");

                    $attempts = self::recordFailure($item, $category);
                    $consecutiveBlocked = self::isBlockingFailure($category) ? $consecutiveBlocked + 1 : 0;

                    if (self::isNeedsReview($attempts)) {
                        // A long-dead chapter: retried on the slow cadence but
                        // kept out of the failure streak and the issue summary.
                        $stats["needs_review"]++;
                    } else {
                        $failed++;
                        // Tolerate a reason this table doesn't know yet: an
                        // undefined key here used to abort the whole sweep.
                        $stats[$category] = ($stats[$category] ?? 0) + 1;
                    }

                    if ($category === "cloudflare" && $host !== null) {
                        Cache::put(self::hostCooldownKey($host), true, now()->addMinutes(self::HOST_COOLDOWN_MINUTES));
                        Log::warning("Cloudflare challenge from {$host}; skipping novels on it for " . self::HOST_COOLDOWN_MINUTES . " minutes.");
                    }

                    if ($category === "short_content") {
                        $stats["short_min"] = $stats["short_min"] === null
                            ? $wordCount
                            : min($stats["short_min"], $wordCount);
                        $stats["short_max"] = $stats["short_max"] === null
                            ? $wordCount
                            : max($stats["short_max"], $wordCount);
                    }

                    $this->error("  ✗ Skipped: Only {$wordCount} words (need >{$minWords})");
                    $this->warn("    Cause: {$category}" . (self::isNeedsReview($attempts) ? " (needs review after {$attempts} attempts)" : ""));
                    Log::warning(
                        "Chapter skipped due to insufficient description: {$item->label} ({$wordCount} words, cause: {$category})"
                    );

                    // Failures used to loop with no pause at all — a blocked
                    // source got hammered. Short back-off before the next try.
                    $failureDelay = self::failureDelaySeconds((int) setting('scrape_min_delay', 30));
                    if ($failureDelay > 0 && !self::circuitOpen($consecutiveBlocked)) {
                        $this->info("  Waiting {$failureDelay} seconds after failure...");
                        $this->pause($failureDelay);
                    }
                }
            }
        }

        $this->trackScrapeHealth($novel, $succeeded, $failed, $stats, $minWords);
    }

    /**
     * Count the words of fetched chapter HTML. str_word_count() on raw HTML
     * counted tag names and entity fragments, and returned ~0 for CJK text.
     * Entities are decoded and tags stripped; words are runs of letters/
     * digits (with inner apostrophes/hyphens); CJK characters (no spaces
     * between words) count as half a word each. Pure.
     */
    public static function countChapterWords(string $html): int
    {
        if ($html === "") {
            return 0;
        }

        // Block-level tags become spaces so "</p><p>" can't glue words.
        $text = preg_replace('~<\s*(br|/?p|/?div|/?h[1-6]|/?li)\b[^>]*>~i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $cjkClass = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}';
        $cjk = (int) preg_match_all("/[{$cjkClass}]/u", $text);

        // Count non-CJK words with CJK characters removed, so a CJK run
        // isn't also counted as one long "word".
        $rest = preg_replace("/[{$cjkClass}]+/u", ' ', $text) ?? $text;
        $words = (int) preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}'’-]*/u", $rest);

        return $words + intdiv($cjk, 2);
    }

    /**
     * Seconds until a chapter that just failed is due again. $attempts is the
     * failure count INCLUDING this one. Transient / site-wide failures
     * (cloudflare, fetch_failed) wait a flat 30 minutes — the circuit breaker
     * and host cooldown deal with those. Everything else doubles from 10
     * minutes (10m, 20m, 40m, ...) capped at 3 days. Pure.
     */
    public static function backoffSeconds(int $attempts, string $category): int
    {
        if (self::isBlockingFailure($category)) {
            return self::BLOCKING_BACKOFF_SECONDS;
        }

        // Exponent bounded so the multiplication can't overflow.
        $exponent = min(max($attempts, 1) - 1, 20);

        return (int) min(self::BACKOFF_BASE_SECONDS * (2 ** $exponent), self::BACKOFF_CAP_SECONDS);
    }

    /** Has a chapter failed often enough to be set aside for review? Pure. */
    public static function isNeedsReview(int $attempts): bool
    {
        return $attempts >= self::NEEDS_REVIEW_ATTEMPTS;
    }

    /**
     * Constrain a novel_chapters query to chapters that are due: never
     * failed, or past their backoff.
     */
    public static function whereDue($query)
    {
        return $query->where(function ($q) {
            $q->whereNull("next_attempt_at")->orWhere("next_attempt_at", "<=", Carbon::now());
        });
    }

    /**
     * Record a failed attempt: bump the attempt count (transient failures
     * never push it past 1), remember the category and schedule the next
     * try. Written with forceFill/saveQuietly so it neither depends on
     * $fillable nor fires model events. Returns the new attempt count.
     */
    public static function recordFailure(\App\NovelChapter $chapter, string $category): int
    {
        $current = (int) ($chapter->attempts ?? 0);
        $attempts = self::isBlockingFailure($category) ? max($current, 1) : $current + 1;

        $chapter->forceFill([
            "attempts" => $attempts,
            "last_failure_reason" => substr($category, 0, 32),
            "next_attempt_at" => Carbon::now()->addSeconds(self::backoffSeconds($attempts, $category)),
        ])->saveQuietly();

        return $attempts;
    }

    /** Does novel_chapters have the sort_key column yet? Cached per process. */
    private static function hasSortKey(): bool
    {
        return self::$hasSortKey ??= Schema::hasColumn("novel_chapters", "sort_key");
    }

    /** Failures that mean the source is blocking/unreachable (feed the circuit breaker). */
    public static function isBlockingFailure(?string $category): bool
    {
        return $category === "cloudflare" || $category === "fetch_failed";
    }

    /** Has the per-novel circuit breaker tripped? */
    public static function circuitOpen(int $consecutiveBlocked, int $threshold = self::CIRCUIT_BREAKER_THRESHOLD): bool
    {
        return $consecutiveBlocked >= $threshold;
    }

    /** Pause after a failed chapter: the configured minimum delay, capped. */
    public static function failureDelaySeconds(int $minDelay): int
    {
        return max(0, min($minDelay, self::FAILURE_DELAY_CAP));
    }

    public static function hostCooldownKey(string $host): string
    {
        return "scrape:host-cooldown:" . strtolower($host);
    }

    public static function hostCoolingDown(string $host): bool
    {
        return (bool) Cache::get(self::hostCooldownKey($host), false);
    }

    /**
     * The host a novel's chapters are fetched from: the first pending
     * chapter's resolved source URL, else the group's URL.
     */
    private static function novelHost($novel): ?string
    {
        $first = $novel->chapters->first();
        $url = $first ? (string) chapterSourceUrl($first) : (string) ($novel->group->url ?? "");
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== "" ? strtolower($host) : null;
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
     * A numbered chapter whose body is only an author/translator message
     * (apology, hiatus notice, thanks) is also accepted when $text is given
     * and looksLikeAuthorMessage() recognises it — its own 5-word floor
     * applies instead of the 50-word one.
     *
     * @param ?int    $threshold Normal minimum; null means the 250 default.
     * @param ?string $text      The fetched chapter body, for the author-message rule.
     */
    public static function acceptableWordCount(?string $label, int $wordCount, ?int $threshold = null, ?string $text = null): bool
    {
        $threshold = $threshold ?? 250;

        if ($wordCount > $threshold) {
            return true;
        }

        if (self::specialLabelMatches($label, $wordCount)) {
            return true;
        }

        return $text !== null && self::looksLikeAuthorMessage($text);
    }

    /**
     * What kind of chapter an accepted fetch is: 'note' when it only got in
     * because of the special-label or author-message rule (i.e. it is at or
     * under the threshold), otherwise null — a proper chapter.
     */
    public static function chapterKind(?string $label, int $wordCount, int $threshold, ?string $text): ?string
    {
        if ($wordCount > $threshold) {
            return null;
        }

        $label = trim((string) $label);

        // A short prologue / side story is still story — only labels that
        // name a note, or a body that reads as one, are stored as 'note'.
        if (($label !== "" && $wordCount >= 50 && self::isNoteLabel($label))
            || ($text !== null && self::looksLikeAuthorMessage($text))) {
            return \App\NovelChapter::KIND_NOTE;
        }

        return null;
    }

    /**
     * The special-label rule on its own: the label names a special chapter
     * (prologue, afterwords, author's note, "Chapter 0"…) and the body clears
     * the 50-word floor.
     */
    public static function specialLabelMatches(?string $label, int $wordCount): bool
    {
        $label = trim((string) $label);
        if ($label === "" || $wordCount < 50) {
            return false;
        }

        return self::isStructuralSpecialLabel($label) || self::isNoteLabel($label);
    }

    /**
     * Structural specials: short by nature but still STORY (prologue, side
     * story, extra, "Chapter 0"…). Accepted from 50 words; never a 'note'.
     */
    public static function isStructuralSpecialLabel(string $label): bool
    {
        $structural = '/\b(?:'
            . 'prologue|epilogue|prelude|interlude|intermission|foreword|preface|postface'
            . '|side[\s-]?stor(?:y|ies)|extras?|specials?|bonus|omake'
            . '|character[\s-]+(?:sheets?|profiles?|intros?|introductions?)|glossary'
            . ')\b/iu';

        return preg_match($structural, $label) === 1
            || preg_match('/\bchapter\s*0(\D|$)/i', $label) === 1;
    }

    /**
     * Note-type labels: the author/translator talking to the reader
     * (afterwords, author's note, thanks, announcement, hiatus…) or a
     * completion marker as the whole subtitle. These are stored as 'note'.
     */
    public static function isNoteLabel(string $label): bool
    {
        $note = '/\b(?:'
            . 'afterwords?|postscripts?|p\.s\b\.?|announcements?|notices?|hiatus'
            . '|author\'?s?[\s-]*(?:notes?|words?|thoughts?|comments?|messages?)'
            . '|translator\'?s?[\s-]*notes?'
            . '|(?:closing|final|last|ending|parting)[\s-]+(?:thoughts?|words?|remarks?|notes?|messages?)'
            . '|thank[\s-]?you|thanks|acknowledge?ments?|q\s*&\s*a'
            . ')\b/iu';

        // Completion marker as the WHOLE (sub)title after an optional
        // "Chapter N" prefix — "Chapter 4851: COMPLETE", "Chapter 900 - The
        // End", "END" match; "Chapter 12: Complete Victory", "Chapter 50:
        // Dead End" and "Final Battle" don't.
        $completion = '/^(?:(?:chapter|ch\.?)\s*\d+(?:\.\d+)?\s*[:\-–—]?\s*)?'
            . '(?:completed?|the end|end|fin|finale|finished|final)\s*\.?\s*$/iu';

        return preg_match($note, $label) === 1 || preg_match($completion, $label) === 1;
    }

    /**
     * Is this chapter body just a short note from the author/translator?
     *
     * Sources sometimes publish a NUMBERED chapter ("Chapter 1828 - 1828")
     * whose whole body is an apology or hiatus notice. Its label is ordinary,
     * so the label rule can't save it; this judges the content instead.
     *
     * True only when the text is 5–400 words, contains none of the site
     * boilerplate / locked-chapter vocabulary (those stubs must stay
     * rejected — a false accept is the worse error), and carries an author
     * voice signal: a "Translator:"/"Author:"/"A/N:" speaker prefix, an
     * "author's note" phrase, or first person plus apology/hiatus/thanks/
     * completion vocabulary.
     *
     * Pure — no DB, no settings.
     *
     * @param string $text Cleaned chapter text; may contain <p> HTML.
     */
    public static function looksLikeAuthorMessage(string $text): bool
    {
        // Paragraph/line breaks become newlines so the speaker-prefix check
        // can anchor on paragraph starts once the tags are gone.
        $plain = preg_replace('~<\s*(br\s*/?|/p|/div|/h[1-6]|/li)\s*>~i', "\n", $text) ?? $text;
        $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!mb_check_encoding($plain, 'UTF-8')) {
            $plain = mb_convert_encoding($plain, 'UTF-8', 'UTF-8');
        }
        // Curly apostrophes → straight, so "I’m" / "Author’s" match too.
        $plain = str_replace(["\u{2019}", "\u{2018}"], "'", $plain);

        $words = str_word_count($plain);
        if ($words < 5 || $words > 400) {
            return false;
        }

        // Genuine locked/empty-page boilerplate only. Links, Ko-fi/Patreon/
        // Discord plugs and thanks to subscribers are neutral — real author
        // notes are full of them. Anchored on a word start.
        $boilerplate = '/\b(?:log ?in|sign ?up|register|locked|unlock|premium|coins?|please wait|loading'
            . '|not (?:yet )?available|not found|404\b|try again(?: later)?|refresh (?:the )?page'
            . '|enable javascript|captcha|cloudflare|you are reading|read (?:more|the latest) at'
            . '|report (?:this )?(?:chapter|issue)|next chapter|prev(?:ious)? chapter|chapter list|table of contents'
            // Paywall / advance-release stubs: "available early on my Patreon",
            // "the rest of this chapter will be posted tomorrow". A bare
            // Ko-fi/Patreon/Discord mention is still neutral.
            . '|advance(?:d)? chapters?|early access|available (?:early|now) on|rest of (?:this|the) chapter'
            . '|(?:will|to) be (?:posted|uploaded|released)|(?:read|continue) (?:the )?(?:full|rest of)|unlocks? (?:in|on|at))/iu';
        if (preg_match($boilerplate, $plain) === 1) {
            return false;
        }

        // Explicit speaker prefix at a line/paragraph start, or a named note.
        $speaker = '/^\s*(?:translator|author|tl|tn|editor|a\/?n|t\/?n)\s*[:：]/imu';
        $namedNote = '/\b(?:author\'?s? (?:note|message|words?)|translator\'?s? note)/iu';
        if (preg_match($speaker, $plain) === 1 || preg_match($namedNote, $plain) === 1) {
            return true;
        }

        // A truncated chapter of a first-person novel also says "I" and may
        // say "sorry" — but it is full of dialogue, and a note to readers is
        // not. Two or more quoted spans means prose, not a note.
        $quoteMarks = preg_match_all('/["\x{201C}\x{201D}]/u', $plain);
        if ($quoteMarks >= 4) {
            return false;
        }

        // Otherwise: first person AND note-specific vocabulary. Kept to
        // phrases that rarely occur in story prose (no bare "work", "family",
        // "break", "support"…), so a short real chapter isn't misread.
        $firstPerson = "/\\b(?:i|i'm|i've|i'll|my|me|we|our)\\b/iu";
        $authorVoice = "/\\b(?:sorry|apolog|forgive me|couldn'?t write|can'?t write|unable to (?:write|update|post)"
            . "|no (?:chapter|update|release)s?(?: today| tomorrow| this week)?|won'?t be (?:a|an|any) (?:chapter|update)"
            . "|(?:chapter|update)s? (?:will be|is|are) (?:late|delayed)|postpone|hiatus|taking a (?:short |small |little )?break"
            . "|(?:in|at) (?:the )?hospital|fever|flu|covid|surgery|funeral|passed away|emergency|burn(?:ed|t) ?out"
            . "|back (?:soon|tomorrow|next week)|updates? (?:will )?resume|(?:release|update|upload) schedule"
            . "|thank you|thanks|see you|next (?:book|novel|volume|arc|project)|the end|come to an end|has ended"
            . "|is (?:now )?(?:complete|finished|over)|final chapter|last chapter|stuck with (?:it|me)"
            . "|next (?:translation )?project|suggestions|dear readers|everyone who (?:read|stuck|supported))/iu";

        return preg_match($firstPerson, $plain) === 1 && preg_match($authorVoice, $plain) === 1;
    }

    /**
     * Turn the per-run failure counts into one sentence explaining what
     * actually went wrong, most diagnostic cause first. Pure — no DB, no
     * logging — so it can be unit tested on its own.
     *
     * `author_note` (accepted author-message chapters) may be present but is
     * not a failure and is ignored here.
     *
     * `circuit_open` (chapters left untried after the breaker tripped) is
     * also ignored — the blocking failures that tripped it explain the run.
     *
     * `needs_review` (long-dead chapters that failed again) is ignored too:
     * those are counted apart so they don't keep the novel flagged.
     *
     * @param array{bad_url?: int, fetch_failed?: int, cloudflare?: int, no_content?: int, short_content?: int, author_note?: int, circuit_open?: int, example_bad_url?: ?string, short_min?: ?int, short_max?: ?int} $stats
     * @param ?int $minWords The configured minimum word count (null = 250).
     */
    public static function summarizeScrapeIssue(array $stats, ?int $minWords = null): ?string
    {
        $badUrl = (int) ($stats["bad_url"] ?? 0);
        $fetchFailed = (int) ($stats["fetch_failed"] ?? 0);
        $notFound = (int) ($stats["not_found"] ?? 0);
        $exception = (int) ($stats["exception"] ?? 0);
        $cloudflare = (int) ($stats["cloudflare"] ?? 0);
        $noContent = (int) ($stats["no_content"] ?? 0);
        $shortContent = (int) ($stats["short_content"] ?? 0);

        $total = $badUrl + $fetchFailed + $notFound + $exception + $cloudflare + $noContent + $shortContent;
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

        if ($notFound === $total) {
            return "the source answers 404 for every pending chapter — they may have been removed or renumbered there";
        }

        if ($exception > 0 && $exception + $fetchFailed === $total) {
            return "the scraper hit an error while parsing chapter pages (see the log)";
        }

        if ($fetchFailed + $notFound === $total) {
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
            $minWords = $minWords ?? 250;
            return "chapter pages only contain {$range} words (need >{$minWords}) "
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
    private function trackScrapeHealth($novel, int $succeeded, int $failed, array $stats = [], ?int $minWords = null): void
    {
        if ($succeeded > 0) {
            if ($novel->scrape_failures > 0) {
                Log::info("Scrape recovered for {$novel->name} after {$novel->scrape_failures} failed run(s).");
            }
            $novel->scrape_failures = 0;
            $novel->last_scrape_issue = null;
            $novel->save();
        } elseif ($failed === 0 && ($stats["needs_review"] ?? 0) > 0) {
            // The only failures were chapters already set aside for review —
            // not a failing source, so the novel stops being flagged.
            Log::info("{$novel->name}: {$stats["needs_review"]} chapter(s) need review (failed " . self::NEEDS_REVIEW_ATTEMPTS . "+ times); not counted as a failed run.");
            if ($novel->scrape_failures > 0 || $novel->last_scrape_issue !== null) {
                $novel->scrape_failures = 0;
                $novel->last_scrape_issue = null;
                $novel->save();
            }
        } elseif ($failed > 0) {
            $issue = self::summarizeScrapeIssue($stats, $minWords);

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

    /** Sleep between fetches. Overridable so tests don't wait. */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /** Fetch a chapter's text. Protected so tests can stub the network. */
    protected function generateChapterDescription($chapter, ?string &$failureReason = null)
    {
        $description = "";

        foreach (chapterGenerator($chapter, $failureReason) as $c) {
            $description .= $c;
        }
        Log::debug("Generated description for chapter ID: {$chapter->id}");
        return $description;
    }

    /**
     * Stamp the attempt before fetching so failed chapters rotate to the back
     * of the pending order on the next run. Saved quietly: no model events,
     * no chapter_texts write.
     */
    public static function recordAttempt(\App\NovelChapter $chapter): void
    {
        $chapter->forceFill(["last_attempt_at" => Carbon::now()])->saveQuietly();
    }

    private function updateChapter($chapter, $description, ?string $kind = null)
    {
        $chapter->description = $description;
        $chapter->last_attempt_at = Carbon::now();
        // 'note' for author messages / short specials, null for a proper chapter.
        $chapter->kind = $kind;
        // A success clears the retry state.
        $chapter->forceFill([
            "attempts" => 0,
            "next_attempt_at" => null,
            "last_failure_reason" => null,
        ]);
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
