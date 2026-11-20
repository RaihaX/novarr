<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Novel;
use App\Mail\NewChapters;
use App\Scraping\Fetcher;
use App\Scraping\HostPace;
use App\Scraping\SourceUnavailable;
use App\Services\DownloadLanes;
use App\Services\LaneProcessStarter;
use App\Services\NovelHealth;
use App\Services\SourceHealth;
use App\Sources\SuwayomiSource;
use Carbon\Carbon;

class ChapterScraper extends Command
{
    /** Consecutive blocking failures (cloudflare / fetch_failed / rate_limited) that stop a novel for the run. */
    public const CIRCUIT_BREAKER_THRESHOLD = 3;

    /**
     * Consecutive content failures (not_found / no_content / short_content)
     * that end a novel for the run and park its content-failed chapters
     * (see SourceUnavailable). Neither cools the host down nor feeds the
     * blocking breaker.
     */
    public const CONTENT_CIRCUIT_THRESHOLD = 3;

    /** Reading within this many days moves a novel up the sweep order. */
    public const READING_RECENT_DAYS = 30;

    /** How long a host stays skipped after a Cloudflare challenge (or a rate limit without Retry-After). */
    public const HOST_COOLDOWN_MINUTES = 15;

    /** Consecutive fetch_failed results on one host (across novels and runs) that put it on cooldown. */
    public const HOST_FAILURE_THRESHOLD = 3;

    /** Upper bound on the pause after a failed fetch. */
    public const FAILURE_DELAY_CAP = 10;

    /** First per-chapter retry delay; doubles with every further failure. */
    public const BACKOFF_BASE_SECONDS = 600;

    /** Longest per-chapter retry delay (3 days). */
    public const BACKOFF_CAP_SECONDS = 259200;

    /** Flat retry delay for transient / site-wide failures (cloudflare, fetch_failed, rate_limited). */
    public const BLOCKING_BACKOFF_SECONDS = 1800;

    /** Failed attempts after which a chapter is "needs review". */
    public const NEEDS_REVIEW_ATTEMPTS = \App\NovelChapter::REVIEW_ATTEMPTS;

    /** Seconds the per-novel scrape lock is held at most (a killed run frees it after this). */
    // Longer than any lane run (bounded by max_run_minutes, at most 140), so
    // a sweep never outlives it; the lock is released in a finally.
    // An explicit run has no deadline and can take longer than the TTL
    // (~180 chapters at a 60 s delay), so it renews the lock once half the
    // TTL has passed (renewNovelLock()) and stops if someone else took it.
    // A killed process frees it after the TTL; a timed-out queued job frees
    // it in RunNovelCommand::failed().
    public const NOVEL_LOCK_SECONDS = 10800;

    /** Unix time after which the sweep starts no more chapters (null = unbounded). */
    private ?float $deadlineAt = null;

    /** The sweep's max_run_minutes, for the deadline message. */
    private int $maxRunMinutes = 0;

    /** Cached Schema::hasColumn('novel_chapters', 'sort_key'). */
    private static ?bool $hasSortKey = null;

    /** Per-host request pace for this run (resolved in handle()). */
    private ?HostPace $pace = null;

    private ?DownloadLanes $lanes = null;

    /**
     * Download lanes this process holds: lane => {lock, at (when the lock was
     * last taken, for renewal), mode, started_at, done}.
     *
     * @var array<string, object>
     */
    private array $heldLanes = [];

    protected $signature = "novel:chapter {novel=0}"
        . " {--chapter= : Download a single chapter by its id}"
        . " {--lane=* : Download the due chapters of this download lane (one source); started by the scheduled dispatcher}"
        . " {--max-minutes=0 : Stop a single-novel run after this many minutes; the scheduled downloader takes the rest (0 = no limit)}";
    protected $description = "Scrape new chapters for each novel.";

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The one per-novel exclusion shared by the scheduled sweep, UI-queued
     * runs (they execute this same command) and the TOC scraper, so a TOC
     * refresh and a chapter download of the same novel never interleave.
     * Callers take it with a non-blocking get() and release it in a finally.
     */
    public static function novelLock(int $novelId): \Illuminate\Contracts\Cache\Lock
    {
        return Cache::lock("novel-scrape:{$novelId}", self::NOVEL_LOCK_SECONDS);
    }

    /**
     * Four modes:
     *  - `--chapter=ID`: one chapter (the reader's button), outside the lanes;
     *  - `--lane=L` (repeatable): download the due chapters of those lanes;
     *    one such process per lane is what the dispatcher starts;
     *  - `{novel}`: an explicit run of one novel, in its lane;
     *  - no novel (the schedule): the dispatcher — starts one lane process
     *    per source with due chapters and returns.
     */
    public function handle()
    {
        $this->pace = app(HostPace::class);

        if ($this->option("chapter")) {
            return $this->scrapeSingleChapter((int) $this->option("chapter"));
        }

        $lanes = array_values(array_unique(array_filter(
            array_map("strval", (array) $this->option("lane")),
            fn($lane) => trim($lane) !== ""
        )));
        if ($lanes !== []) {
            $newChapters = $this->runLanes($lanes);
            Log::info("Finished the download lane(s) " . implode(", ", $lanes) . ". Total new chapters: " . count($newChapters));
            return 0;
        }

        $novelId = (int) $this->argument("novel");
        if ($novelId === 0) {
            $this->dispatchLanes();
            return 0;
        }

        Log::info("Starting chapter scraping for novel ID: $novelId");

        // A queued run (RunNovelCommand) must end before its job timeout:
        // what is left stays queued for the scheduled downloader.
        $this->maxRunMinutes = max(0, (int) $this->option("max-minutes"));
        $this->deadlineAt = $this->maxRunMinutes > 0 ? $this->clock() + $this->maxRunMinutes * 60 : null;

        $newChapters = $this->scrapeChapters($novelId);

        Log::info(
            "Finished chapter scraping. Total new chapters: " .
                count($newChapters)
        );

        return 0;
    }

    /**
     * Download exactly one chapter — used by the reader's "Download this
     * chapter" button on pending chapters. No polite-delay sleep since it's
     * a single user-triggered fetch.
     *
     * Deliberately outside the download lanes: it takes no lane lock and
     * runs even while the novel's source is busy with another download. It
     * is one fetch the user is waiting for, not a queue.
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

        if ($chapter->novel->isManga()) {
            $result = $this->downloadChapterPages($chapter);
            if ($result->ok) {
                $this->updateMangaChapter($chapter, $result->pages);
                SourceHealth::record(SourceHealth::keyForNovel($chapter->novel), SourceHealth::KIND_CHAPTER, true);
                \App\Http\Helpers\CacheHelper::clearNovelCache($chapter->novel_id);
                $this->info("  ✓ Downloaded: {$chapter->label} ({$result->pages} pages)");
                return 0;
            }

            $category = $result->category ?: "fetch_failed";
            self::recordFailure($chapter, $category);
            SourceHealth::record(SourceHealth::keyForNovel($chapter->novel), SourceHealth::KIND_CHAPTER, false, $category, "{$chapter->label}: " . self::failureHint($category, true));
            $this->error("  ✗ The chapter's pages could not all be downloaded — not saved. " . self::failureHint($category, true)
                . ($result->message ? " ({$result->message})" : ""));
            return 1;
        }

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
            SourceHealth::record(SourceHealth::keyForNovel($chapter->novel), SourceHealth::KIND_CHAPTER, true);
            \App\Http\Helpers\CacheHelper::clearNovelCache($chapter->novel_id);
            $this->info("  ✓ Downloaded: {$chapter->label} ({$wordCount} words)");
            return 0;
        }

        $hint = self::failureHint($failureReason);

        // User-triggered, so the schedule was ignored for the fetch — but the
        // failure still counts towards the chapter's backoff.
        $category = $failureReason ?: ($wordCount == 0 ? "no_content" : "short_content");
        self::recordFailure($chapter, $category);
        SourceHealth::record(SourceHealth::keyForNovel($chapter->novel), SourceHealth::KIND_CHAPTER, false, $category, "{$chapter->label}: " . self::failureHint($category));

        $this->error("  ✗ Fetched only {$wordCount} words (need >{$minWords}) — not saved. {$hint}");
        return 1;
    }

    /** The single-chapter download's explanation of a failure category. */
    private static function failureHint(?string $category, bool $manga = false): string
    {
        return match ($category) {
            "fetch_failed" => $manga ? "The page list or an image could not be fetched." : "The chapter page could not be fetched.",
            "cloudflare" => "The source is answering with a Cloudflare challenge.",
            "rate_limited" => "The source is rate-limiting requests (HTTP 429/503) — try again later.",
            "no_content" => $manga ? "The source lists no page images for this chapter." : "The page loaded but contains no chapter text.",
            "not_found" => "The source answers 404 for this chapter — it may have been removed or renumbered there.",
            "exception" => "The scraper hit an error while parsing the page (see the log).",
            "partial_pages" => "A page image was missing, not an image, or too large.",
            default => "The source may not have this chapter yet.",
        };
    }

    /**
     * Novel ids with pending chapters, oldest successful download first: the
     * sweep's candidates (due chapters only, paused novels left out) for
     * $novelId 0, else that one novel (any pending chapter, paused or not).
     */
    private function candidateIds(int $novelId)
    {
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
        // The sweep then re-sorts this order (sweepOrder()).
        $lastDownload = \App\NovelChapter::selectRaw("MAX(download_date)")
            ->whereColumn("novel_chapters.novel_id", "novels.id")
            ->where("status", 1);

        // Snapshot the ids up front: chunking a whereHas(pending) query skips
        // novels as earlier ones drop out of the filter mid-run.
        return $query
            ->orderBy($lastDownload)
            ->orderBy("updated_at")
            ->orderBy("id")
            ->pluck("id");
    }

    /**
     * Explicit run of one novel (the novel page's button, the API check):
     * neither capped nor cut short, the plain sequential fetch-then-wait
     * loop — inside the novel's download lane. When another download of
     * that source is running, the run does not wait: it says so and leaves
     * the chapters to the lane's next sweep (success, not a failure).
     */
    private function scrapeChapters(int $novelId): array
    {
        $newChapters = [];

        foreach ($this->candidateIds($novelId) as $id) {
            $novel = Novel::find($id);
            if (!$novel) {
                continue;
            }

            $first = $this->pendingChapters($novel, false, 1)->first();
            $lane = $this->lanes()->laneFor($novel, $first);
            if (!$this->takeLane($lane, "manual")) {
                $pending = $novel->chapters()->where("status", 0)->where("blacklist", 0)->count();
                $what = $pending === 1 ? "1 chapter of {$novel->name} is" : "{$pending} chapters of {$novel->name} are";
                $line = "Another download from " . DownloadLanes::label($lane) . " is running; {$what} queued and will start when it finishes.";
                $this->info($line);
                Log::info("{$line} (lane {$lane}, novel ID {$novel->id})");
                continue;
            }

            try {
                $this->processNovel($novel, $newChapters, $lane);
            } finally {
                $this->releaseLanes();
            }
        }

        return $newChapters;
    }

    /**
     * The scheduled dispatcher: work out which lanes have due chapters (the
     * sweep's candidates and order, grouped by lane) and start one lane
     * process for each that is free, at most max_parallel_lanes running at
     * once — lanes that have waited longest first; the rest wait for the
     * next tick. Downloads nothing itself and returns quickly.
     */
    private function dispatchLanes(): void
    {
        $queues = $this->laneQueues($this->sweepOrder($this->candidateIds(0)->all()));
        if ($queues === []) {
            $this->info("Nothing is due for download.");
            Log::info("novel:chapter dispatcher: nothing is due for download.");
            return;
        }

        $busy = [];
        $free = [];
        foreach (array_keys($queues) as $lane) {
            $lane = (string) $lane;
            if (DownloadLanes::busy($lane)) {
                $busy[] = $lane;
            } else {
                $free[] = $lane;
            }
        }

        $max = max(1, (int) setting("max_parallel_lanes", config("novarr.defaults.max_parallel_lanes", 6)));
        $free = DownloadLanes::longestWaitingFirst($free);
        $start = array_slice($free, 0, max(0, $max - count($busy)));
        $later = array_slice($free, count($start));

        foreach ($busy as $lane) {
            $this->line("  " . DownloadLanes::label($lane) . ": already downloading");
        }
        foreach ($start as $lane) {
            $this->info("  " . DownloadLanes::label($lane) . ": starting (" . count($queues[$lane]) . " novel(s) due)");
            DownloadLanes::markStarted($lane);
        }
        foreach ($later as $lane) {
            $this->line("  " . DownloadLanes::label($lane) . ": waits for a free slot (max {$max} at once)");
        }
        Log::info("novel:chapter dispatcher: " . count($start) . " lane(s) started"
            . ($start ? " (" . implode(", ", $start) . ")" : "")
            . ", " . count($busy) . " already running, " . count($later) . " waiting for a slot.");

        if ($start !== []) {
            app(LaneProcessStarter::class)->startAll($start, fn(array $lanes) => $this->runLanes($lanes));
        }
    }

    /**
     * A Suwayomi lane is labelled by its source's name, which is only known
     * from the server's source list: fetch it here when it has been lost
     * (Activity never makes a request). Failure just leaves the "#id" label.
     */
    private function learnLaneName(string $lane): void
    {
        if (!str_starts_with($lane, SuwayomiSource::LANE_PREFIX)) {
            return;
        }
        try {
            (new SuwayomiSource())->ensureSourceName(substr($lane, strlen(SuwayomiSource::LANE_PREFIX)));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A lane process: take each lane's lock (a lane held elsewhere is left
     * alone) and sweep the due chapters of those lanes' novels with the
     * usual cap, deadline, order, pace, cooldown and failure rules. One lane
     * per process in production; several share one interleaved sweep when
     * run in-process (tests).
     *
     * @param  string[]  $lanes
     */
    private function runLanes(array $lanes): array
    {
        $newChapters = [];

        $taken = [];
        foreach ($lanes as $lane) {
            if ($this->takeLane($lane, "sweep")) {
                $taken[] = $lane;
                $this->learnLaneName($lane);
            } else {
                $this->line("Another download from " . DownloadLanes::label($lane) . " is already running; leaving it to that one.");
                Log::info("novel:chapter --lane={$lane}: the lane is busy; nothing to do.");
            }
        }
        if ($taken === []) {
            return $newChapters;
        }

        try {
            $queues = array_intersect_key(
                $this->laneQueues($this->sweepOrder($this->candidateIds(0)->all())),
                array_flip($taken)
            );

            // Per-novel cap for the sweep so one huge backlog can't hog a run.
            // <= 0 disables the cap.
            $cap = (int) setting("max_chapters_per_novel_per_run", 25);

            // Bound the lane run: what's left goes to the next run, which
            // starts from the novels that have waited longest. Checked
            // before every chapter.
            $this->maxRunMinutes = max(0, (int) setting("max_run_minutes", 100));
            $this->deadlineAt = $this->maxRunMinutes > 0 ? $this->clock() + $this->maxRunMinutes * 60 : null;

            $this->sweep($queues, $cap, $newChapters);
        } finally {
            $this->releaseLanes();
        }

        return $newChapters;
    }

    /**
     * Group the sweep's novels (in order) by download lane: those with a due
     * chapter, by the lane of the chapter they would fetch first.
     *
     * @param  int[]  $ids
     * @return array<string, int[]>
     */
    private function laneQueues(array $ids): array
    {
        $queues = [];
        foreach ($ids as $id) {
            $novel = Novel::find($id);
            if (!$novel) {
                continue;
            }
            $first = $this->pendingChapters($novel, true, 1)->first();
            if (!$first) {
                continue;
            }
            $queues[$this->lanes()->laneFor($novel, $first)][] = (int) $id;
        }

        return $queues;
    }

    private function lanes(): DownloadLanes
    {
        return $this->lanes ??= app(DownloadLanes::class);
    }

    /** Take a lane's lock for this process (non-blocking). */
    private function takeLane(string $lane, string $mode): bool
    {
        $lock = DownloadLanes::lock($lane);
        if (!$lock->get()) {
            return false;
        }

        $this->heldLanes[$lane] = (object) [
            "lock" => $lock,
            "at" => $this->clock(),
            "mode" => $mode,
            "started_at" => now()->toIso8601String(),
            "done" => 0,
        ];

        return true;
    }

    /**
     * Keep holding a lane before its next chapter: renew the lock once
     * RENEW_AFTER_SECONDS have passed (release and take it straight back, as
     * renewNovelLock()). False when it was lost — it lapsed and another
     * process took the lane — and this process must stop working it.
     */
    private function holdLane(string $lane): bool
    {
        $held = $this->heldLanes[$lane] ?? null;
        if ($held === null) {
            return false;
        }
        if ($this->clock() - $held->at <= DownloadLanes::RENEW_AFTER_SECONDS) {
            return true;
        }

        $held->lock->release();
        if ($held->lock->get()) {
            $held->at = $this->clock();
            return true;
        }

        unset($this->heldLanes[$lane]);
        $this->warn("  Lost the download lane " . DownloadLanes::label($lane) . " to another run — stopping it here");
        Log::warning("novel:chapter lost the download lane {$lane}: could not renew its lock, another run holds it.");

        return false;
    }

    /** Release every lane this process holds and clear their Activity state. */
    private function releaseLanes(): void
    {
        foreach ($this->heldLanes as $lane => $held) {
            DownloadLanes::clearState($lane);
            try {
                $held->lock->release();
            } catch (\Throwable $e) {
                Log::error("novel:chapter could not release the download lane {$lane}: " . $e->getMessage());
            }
        }
        $this->heldLanes = [];
    }

    /** Tell the Activity page which chapter a lane is on (once per chapter). */
    private function noteLaneState(object $run): void
    {
        $held = $this->heldLanes[$run->laneKey] ?? null;
        $next = $run->chapters[$run->index] ?? null;
        if ($held === null || $next === null) {
            return;
        }

        DownloadLanes::putState($run->laneKey, [
            "novel_id" => (int) $run->novel->id,
            "novel_name" => (string) $run->novel->name,
            "chapter_id" => (int) $next->id,
            "chapter_label" => (string) $next->label,
            "mode" => $held->mode,
            "started_at" => $held->started_at,
            "done_this_run" => $held->done,
        ]);
    }

    /** Count a downloaded chapter towards its lane's run. */
    private function noteLaneDone(object $run, ?array $result): void
    {
        if (($result["ok"] ?? false) && isset($this->heldLanes[$run->laneKey])) {
            $this->heldLanes[$run->laneKey]->done++;
        }
    }

    /**
     * The automatic sweep's novel order, from the oldest-download-first $ids:
     *  (a) novels with at least one due never-tried chapter (attempts = 0,
     *      next_attempt_at NULL) before novels whose due chapters are all
     *      retries, so fresh chapters are not queued behind dead ones;
     *  (b) within each group, novels read in the last READING_RECENT_DAYS
     *      days first, most recently read first;
     *  (c) then the incoming order.
     * Two cheap queries over all candidates (never one per novel): a UNION
     * ALL of `LIMIT 1` probes on idx_novel_pending_due, and one grouped
     * latest-read range read on chapter_reads (novel_id, read_at) — read by
     * any reader counts.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function sweepOrder(array $ids): array
    {
        $ids = array_values(array_map('intval', $ids));
        if (count($ids) < 2) {
            return $ids;
        }

        $fresh = NovelHealth::firstPerNovel($ids, fn($q) => $q
            ->where("status", 0)
            ->where("blacklist", 0)
            ->where("attempts", 0)
            ->whereNull("next_attempt_at")
            ->select(["id", "novel_id"]))
            ->keys()
            ->map(fn($id) => (int) $id)
            ->flip()
            ->all();

        $lastRead = app(\App\Services\ReadState::class)
            ->recentlyReadByAnyone($ids, Carbon::now()->subDays(self::READING_RECENT_DAYS));

        return self::orderSweep($ids, $fresh, $lastRead);
    }

    /**
     * The pure part of sweepOrder(): stable sort of $ids by (has a fresh
     * chapter, read recently, most recent read), keeping the incoming order
     * for ties.
     *
     * @param  int[]  $ids
     * @param  array<int, mixed>  $fresh  novel id => anything, for novels with a due never-tried chapter
     * @param  array<int, int>  $lastRead  novel id => Unix time of the latest recent read
     * @return int[]
     */
    public static function orderSweep(array $ids, array $fresh, array $lastRead): array
    {
        $position = array_flip($ids);
        usort($ids, function (int $a, int $b) use ($fresh, $lastRead, $position) {
            return [
                isset($fresh[$a]) ? 0 : 1,
                isset($lastRead[$a]) ? 0 : 1,
                -($lastRead[$a] ?? 0),
                $position[$a],
            ] <=> [
                isset($fresh[$b]) ? 0 : 1,
                isset($lastRead[$b]) ? 0 : 1,
                -($lastRead[$b] ?? 0),
                $position[$b],
            ];
        });

        return $ids;
    }

    /**
     * A lane run's sweep. The politeness delay is per lane (a lane is the
     * host, or one Suwayomi source — DownloadLanes): each lane has a
     * nextAllowedAt, and the loop always works the novel whose lane is
     * ready soonest, so while one lane waits out its delay (HostPace: the
     * adaptive per-lane pace, or the plain random min..max) the others are
     * fetched. In production a process works one lane; the interleaving
     * matters when several lanes share a process. The short failure pause
     * across lanes follows only blocking failures; a content failure (404 /
     * empty) just follows the lane pace. Within a lane novels run one after
     * another in the oldest-download-first order of the queues, so per novel
     * every rule of the sequential loop still holds (cap, backoff, rotation,
     * circuit breaker, lane cooldown at novel start). It only ever sleeps
     * until the earliest lane is ready, and stops working a lane whose lock
     * it lost.
     *
     * @param  array<string, int[]>  $queues  lane => novel ids, in the run's order
     */
    private function sweep(array $queues, int $cap, array &$newChapters): void
    {
        $failureDelay = self::failureDelaySeconds((int) setting("scrape_min_delay", 30));

        $current = [];       // lane => run state of the novel being worked
        $nextAllowedAt = []; // lane => earliest time of its next request
        $notBefore = 0.0;    // the failure pause, across lanes

        try {
            while (true) {
                $host = null; // the lane picked next
                $readyAt = INF;
                foreach ($queues as $h => $queue) {
                    if (!isset($current[$h]) && $queue === []) {
                        continue;
                    }
                    $at = max($nextAllowedAt[$h] ?? 0.0, $notBefore);
                    if ($at < $readyAt) {
                        $host = (string) $h;
                        $readyAt = $at;
                    }
                }
                if ($host === null) {
                    break;
                }
                $paceKey = $host !== DownloadLanes::NO_HOST ? $host : null;

                if ($this->pastDeadline()) {
                    $this->stopAtDeadline($current);
                    break;
                }

                // Starting (or skipping) a novel makes no request: no wait.
                if (!isset($current[$host])) {
                    $novel = Novel::find(array_shift($queues[$host]));
                    if ($novel && ($run = $this->startNovel($novel, true, $cap, $host))) {
                        $current[$host] = $run;
                    }
                    continue;
                }

                $run = $current[$host];

                // The host went on cooldown while this novel was being worked
                // (a 429 with Retry-After, a Cloudflare wall): end the novel
                // for this run without another request. Novels not started
                // yet are skipped by startNovel()'s own cooldown check.
                if ($this->hostCooledDownMidRun($run)) {
                    $this->finishNovel($run);
                    unset($current[$host]);
                    continue;
                }

                if (!$this->hasNextChapter($run)) {
                    $this->step($run, $newChapters); // records circuit_open
                    $this->finishNovel($run);
                    unset($current[$host]);
                    continue;
                }

                $now = $this->clock();
                if ($readyAt > $now) {
                    if ($this->deadlineAt !== null && $readyAt > $this->deadlineAt) {
                        $this->stopAtDeadline($current);
                        break;
                    }
                    $wait = (int) ceil($readyAt - $now);
                    Log::info("Waiting {$wait} seconds before the next chapter" . ($paceKey !== null ? " ({$run->label})" : "") . "...");
                    $this->info("  Waiting {$wait} seconds before next chapter...");
                    $this->pause($wait);

                    if ($this->pastDeadline()) {
                        $this->stopAtDeadline($current);
                        break;
                    }
                }

                // The lane lock lapsed and someone else has the lane now:
                // leave its remaining novels to that run.
                if (!$this->holdLane($run->laneKey)) {
                    $run->done = true;
                    $this->finishNovel($run);
                    unset($current[$host]);
                    $queues[$host] = [];
                    continue;
                }
                $this->noteLaneState($run);

                try {
                    $result = $this->step($run, $newChapters);
                } catch (\Throwable $e) {
                    $this->handleNovelException($run, $e);
                    $result = null;
                    // The throw may have come after the request was sent:
                    // the lane still gets its politeness delay.
                    $nextAllowedAt[$host] = $this->clock() + $this->hostPace()->delay($paceKey);
                }
                $this->noteLaneDone($run, $result);

                if ($result === null) {
                    $this->finishNovel($run);
                    unset($current[$host]);
                    continue;
                }

                if ($result["fetched"]) {
                    $nextAllowedAt[$host] = $this->clock() + $this->hostPace()->delay($paceKey);
                    if (!$result["ok"] && self::isBlockingFailure($result["category"])
                        && $failureDelay > 0 && !self::circuitOpen($run->consecutiveBlocked)) {
                        $notBefore = $this->clock() + $failureDelay;
                    }
                }
            }
        } finally {
            foreach ($current as $run) {
                $this->finishNovel($run);
            }
        }
    }

    /**
     * Explicit single-novel run: fetch, wait, fetch… in $lane (whose lock
     * the caller holds). No deadline unless --max-minutes is given: then it
     * stops before the next chapter once the time is up, and the chapters
     * left stay queued for the scheduled downloader.
     */
    private function processNovel($novel, &$newChapters, string $lane)
    {
        $run = $this->startNovel($novel, false, 0, $lane);
        if ($run === null) {
            return;
        }

        try {
            while (true) {
                // Same lane-cooldown rule as at the start of the run.
                if ($this->hostCooledDownMidRun($run) || !$this->renewNovelLock($run) || !$this->holdLane($run->laneKey)) {
                    break;
                }
                if ($this->pastDeadline()) {
                    $left = $novel->chapters()->where("status", 0)->where("blacklist", 0)->count();
                    if ($left > 0) {
                        $line = "Stopped after {$this->maxRunMinutes} minutes: {$left} chapter(s) of {$novel->name} stay queued and the scheduled downloader continues with them.";
                        $this->info($line);
                        Log::info("{$line} (novel ID {$novel->id})");
                    }
                    break;
                }
                $this->noteLaneState($run);
                $result = $this->step($run, $newChapters);
                $this->noteLaneDone($run, $result);
                if ($result === null) {
                    break;
                }
                if (!$result["fetched"]) {
                    continue;
                }

                $blocking = !$result["ok"] && self::isBlockingFailure($result["category"]);
                if (!$result["ok"] && (self::circuitOpen($run->consecutiveBlocked) || $run->contentTripped)) {
                    // A breaker just opened: the next step() ends the run.
                    continue;
                }

                // Polite delay before the next chapter: the lane's pace
                // (HostPace; the plain random min..max with adaptive off).
                // A content failure (404 / empty) follows the same pace; a
                // blocking failure waits at least the failure pause.
                $delay = $this->hostPace()->delay($run->lane);
                if ($blocking) {
                    $delay = max($delay, self::failureDelaySeconds((int) setting('scrape_min_delay', 30)));
                }
                if ($delay > 0) {
                    Log::info("Waiting {$delay} seconds before next chapter...");
                    $this->info("  Waiting {$delay} seconds before next chapter...");
                    $this->pause($delay);
                }
            }
        } catch (\Throwable $e) {
            $this->handleNovelException($run, $e);
        } finally {
            $this->finishNovel($run);
        }
    }

    /**
     * Take the novel's lock, load its pending chapters and check the lane
     * cooldown. Returns the run state, or null when the novel is skipped
     * this run (lock held elsewhere, lane cooling down, or an error).
     *
     * $lane is the novel's download lane (DownloadLanes), the key of its
     * pace, cooldown and failure count; for every source but Suwayomi it is
     * the chapter host. Null: the chapter host.
     */
    private function startNovel(Novel $novel, bool $sweep, int $cap, ?string $lane = null): ?object
    {
        $lock = self::novelLock((int) $novel->id);
        if (!$lock->get()) {
            $this->warn("Skipping {$novel->name}: another scrape of this novel is running");
            Log::info("Skipping {$novel->name} (ID {$novel->id}): its scrape lock is held by another run.");
            return null;
        }

        try {
            // Loaded per novel (not up front) so chapters another process
            // downloaded meanwhile aren't fetched again.
            $novel->setRelation("chapters", $this->pendingChapters($novel, $sweep, $cap));

            // Another novel on this lane just hit a Cloudflare wall or a rate
            // limit — don't keep hammering it. Not counted as a failed run
            // (nothing was attempted).
            $host = self::novelHost($novel);
            $laneKey = $lane ?? $host ?? DownloadLanes::NO_HOST;
            $paceKey = $laneKey !== DownloadLanes::NO_HOST ? $laneKey : null;
            $label = DownloadLanes::label($laneKey);
            if ($paceKey !== null && self::hostCoolingDown($paceKey)) {
                $this->warn("Skipping {$novel->name}: {$label} is cooling down after a Cloudflare challenge, rate limit or repeated failed fetches");
                Log::warning("Skipping {$novel->name}: host {$label} is in a cooldown");
                $lock->release();
                return null;
            }

            Log::info("Processing novel: {$novel->name}");
        } catch (\Throwable $e) {
            $lock->release();
            $this->error("  ✗ Error while preparing {$novel->name}: {$e->getMessage()}");
            Log::error("novel:chapter could not start {$novel->name} (ID {$novel->id}): " . $e->getMessage());
            return null;
        }

        return (object) [
            "novel" => $novel,
            // The real host (Retry-After, the "listed on …" message) …
            "host" => $host,
            // … and the lane: the key of pace, cooldown and failure count
            // (null without a host), its lock key and its display name.
            "lane" => $paceKey,
            "laneKey" => $laneKey,
            "label" => $label,
            "lock" => $lock,
            "chapters" => $novel->chapters->values()->all(),
            "index" => 0,
            "pendingTotal" => count($novel->chapters),
            "attempted" => 0,
            "succeeded" => 0,
            "failed" => 0,
            // Consecutive blocking results for this novel.
            "consecutiveBlocked" => 0,
            // Consecutive content failures (404 / empty / stub) and whether
            // they ended the novel (parked its chapters) this run.
            "consecutiveContent" => 0,
            "contentTripped" => false,
            "stats" => self::emptyStats(),
            // Resolved once per novel — the acceptance helper stays pure.
            "minWords" => (int) setting("min_chapter_words", 250),
            // The chapter being worked, for the exception handler.
            "current" => null,
            // When the lock was (re)acquired, for renewNovelLock().
            "lockAcquiredAt" => $this->clock(),
            "done" => false,
            "finished" => false,
        ];
    }

    /** Pending chapters of a novel in fetch order. */
    private function pendingChapters(Novel $novel, bool $sweep, int $cap)
    {
        return $novel->chapters()
            ->where("status", 0)
            ->where("blacklist", 0)
            // Chapters still backing off after a failure wait — but an
            // explicit run (the novel page's Download button) is the user
            // asking for them now, so it ignores the schedule.
            ->when($sweep, fn($q) => $q->where(fn($d) => self::whereDue($d)))
            // Never-failed chapters (attempts = 0) first, in chapter order;
            // then previously failed ones, those that have waited longest
            // since their last attempt first. Dead or stub chapters can then
            // neither fill the cap on every run nor trip the content circuit
            // ahead of fresh chapters. Portable NULLS FIRST.
            ->orderByRaw("CASE WHEN attempts > 0 THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN attempts > 0 THEN last_attempt_at END")
            ->orderBy("book")
            ->when(self::hasSortKey(), fn($q) => $q->orderBy("sort_key"))
            ->orderBy("chapter")
            ->when($cap > 0, fn($q) => $q->limit($cap))
            ->get();
    }

    /** Per-run failure breakdown, so the health report can name the actual cause. */
    private static function emptyStats(): array
    {
        return [
            "bad_url" => 0,
            "fetch_failed" => 0,
            "not_found" => 0,   // source answers 404: the chapter is gone there
            "exception" => 0,   // parser/DOM error while reading the page
            "cloudflare" => 0,
            "rate_limited" => 0, // source answered 429/503
            "no_content" => 0,
            "short_content" => 0,
            // Manga: a page image missing, invalid or too large.
            "partial_pages" => 0,
            // Not a failure: numbered chapters accepted because their body is
            // an author/translator message. Ignored by summarizeScrapeIssue().
            "author_note" => 0,
            // Pending chapters left untried because the circuit breaker
            // opened (too many consecutive blocked/failed fetches).
            "circuit_open" => 0,
            // Pending chapters left untried because the content circuit
            // opened (the source lists chapters it does not serve). With it:
            // content_parked (chapters now parked), content_host and
            // content_recheck_at (set only when the circuit tripped).
            "content_circuit" => 0,
            // Not counted as failures: chapters that have failed
            // NEEDS_REVIEW_ATTEMPTS+ times and failed again. They keep being
            // retried on the 3-day cadence but no longer flag the novel.
            "needs_review" => 0,
            "example_bad_url" => null,
            "short_min" => null,
            "short_max" => null,
        ];
    }

    /** Would step() work another chapter (rather than end the novel)? */
    private function hasNextChapter(object $run): bool
    {
        return !$run->done
            && $run->index < count($run->chapters)
            && !self::circuitOpen($run->consecutiveBlocked)
            && !$run->contentTripped;
    }

    /**
     * Work the novel's next pending chapter. Returns null when the novel is
     * done for this run (no chapters left, or a circuit breaker opened),
     * else whether a fetch was made, whether it succeeded and the failure
     * category (null on success).
     *
     * @return ?array{fetched: bool, ok: bool, category: ?string}
     */
    private function step(object $run, array &$newChapters): ?array
    {
        $novel = $run->novel;

        if ($run->done || $run->index >= count($run->chapters)) {
            $run->done = true;
            return null;
        }

        if (self::circuitOpen($run->consecutiveBlocked)) {
            $run->stats["circuit_open"] = $run->pendingTotal - $run->attempted;
            $this->error("  ✗ {$run->consecutiveBlocked} fetches in a row failed — stopping {$novel->name} for this run");
            Log::warning(
                "Circuit breaker opened for {$novel->name} after {$run->consecutiveBlocked} consecutive failed fetches; "
                . "{$run->stats["circuit_open"]} pending chapter(s) left for the next run."
            );
            $run->done = true;
            return null;
        }

        $item = $run->chapters[$run->index++];
        $run->attempted++;
        $run->current = $item;
        $stats = &$run->stats;
        $minWords = $run->minWords;
        $lane = $run->lane;

        // chapterGenerator reads $chapter->novel; share the loaded model
        // instead of lazy-loading it once per chapter.
        $item->setRelation("novel", $novel);

        Log::debug("Processing chapter: {$item->label}");
        $this->info("Processing: {$novel->name} - {$item->label}");

        // A bad TOC parse can leave junk pending rows behind (label "Arial",
        // url "https://novelfull.comArial, sans-serif") — those are never
        // worth a fetch.
        $url = (string) chapterSourceUrl($item);
        if (!preg_match('~^https?://[^\s,]+$~', $url)) {
            $run->current = null;
            if (self::isNeedsReview(self::recordFailure($item, "bad_url"))) {
                $stats["needs_review"]++;
                $this->error("  ✗ Skipped (needs review): invalid source URL \"{$url}\"");
                return ["fetched" => false, "ok" => false, "category" => "bad_url"];
            }
            $run->failed++;
            $stats["bad_url"]++;
            self::recordAttempt($item);
            $stats["example_bad_url"] = $stats["example_bad_url"] ?? $url;
            $this->error("  ✗ Skipped: invalid source URL \"{$url}\"");
            Log::warning(
                "Chapter skipped due to invalid source URL: {$item->label} (\"{$url}\")"
            );
            return ["fetched" => false, "ok" => false, "category" => "bad_url"];
        }

        // A manga chapter is page images, not text: its own download, the
        // same bookkeeping.
        if ($novel->isManga()) {
            self::recordAttempt($item);
            return $this->stepManga($run, $item, $newChapters);
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

            $run->succeeded++;
            $run->consecutiveBlocked = 0;
            $run->consecutiveContent = 0;
            // Read before updateChapter() clears the failure fields.
            $wasUnavailable = SourceUnavailable::isContentFailure($item->last_failure_reason);
            $this->updateChapter(
                $item,
                $description,
                self::chapterKind($item->label, $wordCount, $minWords, $description)
            );
            $run->current = null;
            if ($lane !== null) {
                Cache::forget(self::hostFailuresKey($lane));
            }
            $this->hostPace()->record($lane, null);
            SourceHealth::record(SourceHealth::keyForNovel($novel), SourceHealth::KIND_CHAPTER, true);
            // A chapter the source had stopped serving is back: release the
            // novel's parked chapters now instead of at the next re-check. A
            // brand-new chapter downloading says nothing about the dead ones,
            // so it leaves them parked (and the re-check backoff growing).
            if ($wasUnavailable) {
                SourceUnavailable::release($novel);
            }
            $this->addChapterToArray($novel, $item, $newChapters);

            return ["fetched" => true, "ok" => true, "category" => null];
        }

        // Anything the fetcher didn't already explain is judged on word
        // count: nothing at all vs. a stub chapter.
        $category = $failureReason ?: ($wordCount == 0 ? "no_content" : "short_content");

        return $this->failStep(
            $run,
            $item,
            $category,
            $wordCount,
            "  ✗ Skipped: Only {$wordCount} words (need >{$minWords})",
            "Chapter skipped due to insufficient description: {$item->label} ({$wordCount} words, cause: {$category})"
        );
    }

    /**
     * A fetched chapter that was not kept: record the failure (backoff,
     * attempts), feed the breakers, the host pace and the host failure
     * count, tally the run's stats, report it, and trip the content circuit
     * when that streak is reached. Shared by text and manga chapters.
     *
     * @return array{fetched: bool, ok: bool, category: ?string}
     */
    private function failStep(object $run, \App\NovelChapter $item, string $category, ?int $wordCount, string $errorLine, string $logLine): array
    {
        $stats = &$run->stats;
        $lane = $run->lane;

        $attempts = self::recordFailure($item, $category);
        $run->current = null;
        $run->consecutiveBlocked = self::isBlockingFailure($category) ? $run->consecutiveBlocked + 1 : 0;
        // Any other result between two content failures breaks the run.
        $run->consecutiveContent = SourceUnavailable::isContentFailure($category) ? $run->consecutiveContent + 1 : 0;
        $this->hostPace()->record($lane, $category);
        SourceHealth::record(SourceHealth::keyForNovel($run->novel), SourceHealth::KIND_CHAPTER, false, $category, "{$item->label}: " . self::failureHint($category, $run->novel->isManga()));

        if (self::isNeedsReview($attempts)) {
            // A long-dead chapter: retried on the slow cadence but kept out
            // of the failure streak and the issue summary.
            $stats["needs_review"]++;
        } else {
            $run->failed++;
            // Tolerate a reason this table doesn't know yet: an undefined
            // key here used to abort the whole sweep.
            $stats[$category] = ($stats[$category] ?? 0) + 1;
        }

        if ($lane !== null) {
            $this->noteHostFailure($lane, $category, $run->host);
        }

        if ($category === "short_content" && $wordCount !== null) {
            $stats["short_min"] = $stats["short_min"] === null
                ? $wordCount
                : min($stats["short_min"], $wordCount);
            $stats["short_max"] = $stats["short_max"] === null
                ? $wordCount
                : max($stats["short_max"], $wordCount);
        }

        $this->error($errorLine);
        $this->warn("    Cause: {$category}" . (self::isNeedsReview($attempts) ? " (needs review after {$attempts} attempts)" : ""));
        Log::warning($logLine);

        if (self::circuitOpen($run->consecutiveContent, self::CONTENT_CIRCUIT_THRESHOLD)) {
            $this->tripContentCircuit($run);
        }

        return ["fetched" => true, "ok" => false, "category" => $category];
    }

    /**
     * step() for a manga chapter: download every page image (all or
     * nothing, PageDownloader). Success and failure are booked exactly as a
     * text chapter's — status, retry state, host pace, host failures, the
     * content circuit, parked-chapter release — with page counts instead of
     * word counts. No chapter_texts row is written.
     *
     * @return array{fetched: bool, ok: bool, category: ?string}
     */
    private function stepManga(object $run, \App\NovelChapter $item, array &$newChapters): array
    {
        $novel = $run->novel;
        $lane = $run->lane;

        $result = $this->downloadChapterPages($item);

        if ($result->ok) {
            $this->line("  → Fetched {$result->pages} pages");
            Log::info("Successfully downloaded chapter: {$item->label} ({$result->pages} pages, {$result->bytes} bytes)");
            $this->info("  ✓ Downloaded: {$item->label} ({$result->pages} pages)");

            $run->succeeded++;
            $run->consecutiveBlocked = 0;
            $run->consecutiveContent = 0;
            // Read before updateMangaChapter() clears the failure fields.
            $wasUnavailable = SourceUnavailable::isContentFailure($item->last_failure_reason);
            $this->updateMangaChapter($item, $result->pages);
            $run->current = null;
            if ($lane !== null) {
                Cache::forget(self::hostFailuresKey($lane));
            }
            $this->hostPace()->record($lane, null);
            SourceHealth::record(SourceHealth::keyForNovel($novel), SourceHealth::KIND_CHAPTER, true);
            if ($wasUnavailable) {
                SourceUnavailable::release($novel);
            }
            $this->addChapterToArray($novel, $item, $newChapters);

            return ["fetched" => true, "ok" => true, "category" => null];
        }

        $category = $result->category ?: "fetch_failed";
        $why = $result->message ? " — {$result->message}" : "";
        $this->line("  → Page download failed");

        return $this->failStep(
            $run,
            $item,
            $category,
            null,
            "  ✗ Skipped: the chapter's pages could not all be downloaded{$why}",
            "Manga chapter skipped, pages not downloaded: {$item->label} (cause: {$category}{$why})"
        );
    }

    /**
     * CONTENT_CIRCUIT_THRESHOLD content failures in a row: the source lists
     * these chapters but does not serve them. End the novel for this run and
     * park its content-failed chapters as a group until the next re-check
     * (SourceUnavailable::park()). The host is not cooled down and the
     * blocking breaker is not fed: the site answered fine.
     */
    private function tripContentCircuit(object $run): void
    {
        $novel = $run->novel;
        $run->contentTripped = true;
        $run->done = true;
        $run->stats["content_circuit"] = max(0, $run->pendingTotal - $run->attempted);

        try {
            $park = SourceUnavailable::park($novel);
        } catch (\Throwable $e) {
            Log::error("novel:chapter could not park the unavailable chapters of {$novel->name} (ID {$novel->id}): " . $e->getMessage());
            return;
        }

        $run->stats["content_parked"] = $park["parked"];
        $run->stats["content_host"] = $run->host;
        $run->stats["content_recheck_at"] = $park["recheck_at"]->toDateTimeString();
        $run->stats["content_streak"] = $park["streak"];

        $this->error("  ✗ {$run->consecutiveContent} chapters in a row are not available on the source — stopping {$novel->name} for this run");
        Log::warning(
            "Content circuit opened for {$novel->name}: the source lists these chapters but does not serve them "
            . "(not found / empty, {$run->consecutiveContent} in a row). {$park["parked"]} chapter(s) parked until "
            . "{$park["recheck_at"]->toDateTimeString()} (re-check #{$park["streak"]}); "
            . "{$run->stats["content_circuit"]} pending chapter(s) left untried this run."
        );
    }

    /** The run's request pace (set in handle(); resolved from the container otherwise). */
    protected function hostPace(): HostPace
    {
        return $this->pace ??= app(HostPace::class);
    }

    /**
     * Lane-wide failure handling: a Cloudflare challenge or a rate limit
     * cools the lane down at once (a rate limit for its Retry-After, when
     * given); fetch_failed does so after HOST_FAILURE_THRESHOLD in a row on
     * that lane, counted across novels and runs. The lane is the host for
     * every source but Suwayomi, where it is one site behind the server.
     * $host is the real host the Retry-After was recorded under.
     */
    private function noteHostFailure(string $lane, string $category, ?string $host = null): void
    {
        if ($category === "cloudflare") {
            self::coolDownHost($lane, self::HOST_COOLDOWN_MINUTES * 60, "Cloudflare challenge");
            return;
        }

        if ($category === "rate_limited") {
            $seconds = Fetcher::pullRetryAfter($host ?? $lane) ?? self::HOST_COOLDOWN_MINUTES * 60;
            self::coolDownHost($lane, $seconds, "rate limit (HTTP 429/503)");
            return;
        }

        if ($category === "fetch_failed") {
            $key = self::hostFailuresKey($lane);
            $count = (int) Cache::get($key, 0) + 1;
            if ($count >= self::HOST_FAILURE_THRESHOLD) {
                Cache::forget($key);
                self::coolDownHost($lane, self::HOST_COOLDOWN_MINUTES * 60, "{$count} failed fetches in a row");
            } else {
                Cache::put($key, $count, now()->addDay());
            }
        }
    }

    /**
     * Cool a lane down. The value is the expiry's Unix time (truthy, like
     * the plain `true` it used to be) so the Activity page can say until when.
     */
    private static function coolDownHost(string $lane, int $seconds, string $why): void
    {
        if ($seconds <= 0) {
            return;
        }

        $until = now()->addSeconds($seconds);
        Cache::put(self::hostCooldownKey($lane), $until->getTimestamp(), $until);
        $minutes = (int) ceil($seconds / 60);
        Log::warning("{$why} from " . DownloadLanes::label($lane) . "; skipping novels on it for {$minutes} minute(s).");
    }

    /**
     * An error escaped while working a novel: log it, charge it to the
     * chapter being worked (so its backoff applies and it doesn't lead the
     * next run) and end the novel for this run. The sweep goes on.
     */
    private function handleNovelException(object $run, \Throwable $e): void
    {
        $novel = $run->novel;
        $item = $run->current;
        $run->current = null;
        $run->done = true;

        $this->error("  ✗ Error while scraping {$novel->name}: {$e->getMessage()}");
        Log::error(
            "novel:chapter error on {$novel->name} (ID {$novel->id})"
            . ($item ? ", chapter {$item->id}" : "") . ": " . $e->getMessage(),
            ["exception" => $e]
        );

        if ($item instanceof \App\NovelChapter) {
            SourceHealth::record(SourceHealth::keyForNovel($novel), SourceHealth::KIND_CHAPTER, false, "exception", "{$item->label}: " . mb_substr($e->getMessage(), 0, 200));
            try {
                if (!self::isNeedsReview(self::recordFailure($item, "exception"))) {
                    $run->failed++;
                    $run->stats["exception"]++;
                } else {
                    $run->stats["needs_review"]++;
                }
            } catch (\Throwable $inner) {
                Log::error("novel:chapter could not record the failure of chapter {$item->id}: " . $inner->getMessage());
            }
        }
    }

    /**
     * Did the novel's host go on cooldown while the novel was in progress?
     * Only asked while chapters remain, so a novel that is done anyway ends
     * the usual way.
     */
    private function hostCooledDownMidRun(object $run): bool
    {
        if ($run->lane === null || !$this->hasNextChapter($run) || !self::hostCoolingDown($run->lane)) {
            return false;
        }

        $this->warn("  {$run->label} is cooling down — stopping {$run->novel->name} for this run");
        Log::warning("Stopping {$run->novel->name} mid-run: host {$run->label} went into a cooldown; remaining chapters wait for the next run.");
        $run->done = true;

        return true;
    }

    /**
     * Keep an explicit run's novel lock from lapsing: once half the TTL has
     * passed since it was taken, release it and take it straight back. False
     * when the re-take fails — another run got the lock in the gap (or after
     * it expired) — and the run must stop.
     */
    private function renewNovelLock(object $run): bool
    {
        if ($this->clock() - $run->lockAcquiredAt <= self::NOVEL_LOCK_SECONDS / 2) {
            return true;
        }

        $run->lock->release();
        if ($run->lock->get()) {
            $run->lockAcquiredAt = $this->clock();
            return true;
        }

        $this->warn("  Lost the scrape lock of {$run->novel->name} to another run — stopping this run");
        Log::warning("novel:chapter stopped the explicit run of {$run->novel->name} (ID {$run->novel->id}): could not renew its scrape lock, another run holds it.");
        $run->done = true;

        return false;
    }

    /** Record the novel's health and release its lock (once). */
    private function finishNovel(object $run): void
    {
        if ($run->finished) {
            return;
        }
        $run->finished = true;

        try {
            $this->trackScrapeHealth($run->novel, $run->succeeded, $run->failed, $run->stats, $run->minWords);
        } catch (\Throwable $e) {
            Log::error("novel:chapter could not record scrape health for {$run->novel->name}: " . $e->getMessage());
        } finally {
            $run->lock->release();
        }
    }

    private function pastDeadline(): bool
    {
        return $this->deadlineAt !== null && $this->clock() > $this->deadlineAt;
    }

    /** @param array<string, object> $current novels in progress */
    private function stopAtDeadline(array $current): void
    {
        foreach ($current as $run) {
            if ($this->hasNextChapter($run)) {
                $this->warn("  Run time limit reached mid-novel; remaining chapters wait for the next run.");
                Log::info("novel:chapter stopped mid-novel at the run-time limit ({$run->novel->name}); remaining chapters deferred.");
            }
        }

        $this->warn("Run time limit of {$this->maxRunMinutes} minutes reached; remaining novels wait for the next run.");
        Log::info("novel:chapter stopped at the {$this->maxRunMinutes}-minute limit; remaining novels deferred to the next run.");
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
     * (cloudflare, fetch_failed, rate_limited) wait a flat 30 minutes — the circuit breaker
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
        return $category === "cloudflare" || $category === "fetch_failed" || $category === "rate_limited";
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

    /** Consecutive fetch_failed results on a host (see noteHostFailure()). */
    public static function hostFailuresKey(string $host): string
    {
        return "scrape:host-fetch-failures:" . strtolower($host);
    }

    /**
     * The host a novel's chapters are fetched from: the first pending
     * chapter's resolved source URL, else the group's URL.
     */
    private static function novelHost($novel): ?string
    {
        return self::chapterHost($novel->chapters->first(), $novel);
    }

    /** Host of a chapter's resolved source URL (the novel's group URL without one). */
    private static function chapterHost($chapter, $novel): ?string
    {
        return DownloadLanes::hostOf($chapter, $novel);
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

        // Links, Ko-fi/Patreon/Discord plugs and thanks to subscribers are
        // neutral — real author notes are full of them.
        // Genuine locked/empty-page boilerplate. Two tiers:
        //  - hard lock phrases always reject, even under a "Translator:" line
        //    (a locked page can carry a translator header too);
        //  - site chrome / paywall phrasing rejects only when there is no
        //    explicit speaker line. Every entry is a PHRASE, not a bare word:
        //    "register" alone matched "registered their members" in a real
        //    translator's note (White Dragon Lord ch. 2281) and threw it away.
        $hardLock = '/\b(?:locked|unlock(?:s|ed)?\b(?! (?:in|on|at) )|premium (?:chapter|content|only)|coins? (?:to|required|needed)'
            . '|log ?in (?:to|required|now|first)|sign ?up (?:to|now|for)|register (?:to|an account|now|for free)|registration (?:required|needed)'
            . '|captcha|cloudflare|not found|404\b)/iu';
        $chrome = '/\b(?:please wait|loading\.{0,3}$|not (?:yet )?available|try again(?: later)?|refresh (?:the )?page'
            . '|enable javascript|you are reading|read (?:more|the latest) at'
            . '|report (?:this )?(?:chapter|issue)|next chapter|prev(?:ious)? chapter|chapter list|table of contents'
            // Paywall / advance-release stubs: "available early on my Patreon",
            // "the rest of this chapter will be posted tomorrow". A bare
            // Ko-fi/Patreon/Discord mention is still neutral.
            . '|advance(?:d)? chapters?|early access|available (?:early|now) on|rest of (?:this|the) chapter'
            . '|(?:will|to) be (?:posted|uploaded|released)|(?:read|continue) (?:the )?(?:full|rest of)|unlocks? (?:in|on|at))/iu';

        if (preg_match($hardLock, $plain) === 1) {
            return false;
        }

        // Explicit speaker prefix at a line/paragraph start, or a named note:
        // the strongest signal there is — accepted even if the body mentions
        // "next chapter" or "report".
        $speaker = '/^\s*(?:translator|author|tl|tn|editor|a\/?n|t\/?n)\s*[:：]/imu';
        $namedNote = '/\b(?:author\'?s? (?:note|message|words?)|translator\'?s? note)/iu';
        if (preg_match($speaker, $plain) === 1 || preg_match($namedNote, $plain) === 1) {
            return true;
        }

        if (preg_match($chrome, $plain) === 1) {
            return false;
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
     * @param array{bad_url?: int, fetch_failed?: int, cloudflare?: int, rate_limited?: int, no_content?: int, short_content?: int, author_note?: int, circuit_open?: int, example_bad_url?: ?string, short_min?: ?int, short_max?: ?int} $stats
     * @param ?int $minWords The configured minimum word count (null = 250).
     */
    public static function summarizeScrapeIssue(array $stats, ?int $minWords = null, bool $manga = false): ?string
    {
        // The content circuit ended the run: a known, parked state rather
        // than a transient failure, so it is stated as such (even when the
        // chapters were too long dead to count as failures).
        if (isset($stats["content_parked"], $stats["content_recheck_at"])) {
            return self::sourceUnavailableMessage(
                (int) $stats["content_parked"],
                $stats["content_host"] ?? null,
                Carbon::parse($stats["content_recheck_at"])
            );
        }

        $badUrl = (int) ($stats["bad_url"] ?? 0);
        $fetchFailed = (int) ($stats["fetch_failed"] ?? 0);
        $notFound = (int) ($stats["not_found"] ?? 0);
        $exception = (int) ($stats["exception"] ?? 0);
        $cloudflare = (int) ($stats["cloudflare"] ?? 0);
        $rateLimited = (int) ($stats["rate_limited"] ?? 0);
        $noContent = (int) ($stats["no_content"] ?? 0);
        $shortContent = (int) ($stats["short_content"] ?? 0);
        $partialPages = (int) ($stats["partial_pages"] ?? 0);

        $total = $badUrl + $fetchFailed + $notFound + $exception + $cloudflare + $rateLimited + $noContent + $shortContent + $partialPages;
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

        if ($rateLimited > 0) {
            return "the source site is rate-limiting fetches (HTTP 429/503)";
        }

        if ($notFound === $total) {
            return "the source answers 404 for every pending chapter — they may have been removed or renumbered there";
        }

        if ($exception > 0 && $exception + $fetchFailed === $total) {
            return "the scraper hit an error while parsing chapter pages (see the log)";
        }

        if ($fetchFailed + $notFound === $total) {
            return $manga ? "chapter page images could not be fetched" : "chapter pages could not be fetched";
        }

        if ($manga && $noContent > 0) {
            return "the source lists no page images for the pending chapters";
        }

        if ($partialPages > 0) {
            return "some page images of the pending chapters could not be downloaded (missing, not an image, or too large)";
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
     * The plain explanation for parked chapters: listed on the source but
     * not served there, and when Novarr looks again. Pure.
     */
    public static function sourceUnavailableMessage(int $parked, ?string $host, Carbon $recheckAt): string
    {
        $where = $host ? preg_replace('/^www\./', '', $host) : "the source";
        $count = $parked === 1 ? "1 chapter is" : "{$parked} chapters are";

        return "{$count} listed on {$where} but not available there (not found or empty). "
            . "Novarr checks again on " . $recheckAt->copy()->setTimezone(config("app.timezone"))->format("j M Y \\a\\t H:i") . ".";
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
        if (isset($stats["content_parked"])) {
            // The content circuit parked chapters: one clear, non-alarming
            // state. Not a failed run (no scrape_failures streak, so no
            // "failed 3 runs in a row" alert and no Failed badge); the issue
            // line says what is going on and when it is re-checked. The
            // webhook hears about it at most once a week per novel, however
            // often the canary re-check trips again.
            $issue = self::summarizeScrapeIssue($stats, $minWords, (bool) $novel->isManga());
            $novel->scrape_failures = 0;
            $novel->last_scrape_issue = $issue;
            $novel->save();

            Log::info("{$novel->name}: {$issue}");
            if (Cache::add("scrape:source-unavailable-notified:{$novel->id}", true, now()->addDays(7))) {
                notify_webhook("ℹ️ {$novel->name} — {$issue}");
            }
            return;
        }

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
            $issue = self::summarizeScrapeIssue($stats, $minWords, (bool) $novel->isManga());

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

    /** Current time (Unix seconds) for the sweep's delays and deadline. Overridable for a fake clock. */
    protected function clock(): float
    {
        return microtime(true);
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

    /**
     * A manga chapter whose pages are all on disk: downloaded, its page
     * count, retry state cleared. No body text (no chapter_texts row).
     * Public static: App\Scraping\MangaFallback marks a chapter it filled
     * from another source exactly the same way.
     */
    public static function updateMangaChapter(\App\NovelChapter $chapter, int $pages): void
    {
        $chapter->last_attempt_at = Carbon::now();
        $chapter->kind = null;
        $chapter->forceFill([
            "attempts" => 0,
            "next_attempt_at" => null,
            "last_failure_reason" => null,
            "page_count" => $pages,
        ]);
        $chapter->status = 1;
        $chapter->download_date = Carbon::now();
        $chapter->save();

        Log::info("Updated chapter ID: {$chapter->id}, status set to: {$chapter->status} ({$pages} pages)");
    }

    /** Download a manga chapter's pages. Protected so tests can stub the network. */
    protected function downloadChapterPages(\App\NovelChapter $chapter): \App\Scraping\PageDownloadResult
    {
        return app(\App\Scraping\PageDownloader::class)->download($chapter);
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
