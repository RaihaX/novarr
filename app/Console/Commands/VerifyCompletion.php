<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;

class VerifyCompletion extends Command
{
    protected $signature = "novel:verify-completion
        {novel=0 : Novel ID to check (0 = all active novels)}
        {--dry-run : Report what would be marked complete without saving}
        {--force : Mark the given novel complete even if checks fail (requires a novel ID)}
        {--no-kindle : Skip emailing the generated ePub to the Kindle address}";

    protected $description = "Verify novels against NovelUpdates and mark them complete when every chapter is downloaded.";

    public function handle()
    {
        $novelId = (int) $this->argument("novel");

        if ($this->option("force") && $novelId === 0) {
            $this->error("--force requires a specific novel ID.");
            return 1;
        }

        $novels = Novel::where("status", 0)
            ->when($novelId !== 0, fn($q) => $q->where("id", $novelId))
            // Paused novels are skipped in the automatic sweep but still run
            // when a specific novel is requested explicitly.
            ->when($novelId === 0, fn($q) => $q->whereNull("paused_at"))
            ->where("group_id", "!=", 37)
            ->orderBy("name")
            ->get();

        if ($novels->isEmpty()) {
            $this->warn("No active novels found to verify.");
            return 0;
        }

        $completed = 0;

        foreach ($novels as $novel) {
            $this->info("Checking: {$novel->name}");

            $local = $this->localStats($novel);

            if ($this->option("force")) {
                $this->markComplete($novel, $local, ["forced manually"]);
                $completed++;
                continue;
            }

            $metadata = getMetadata($novel);
            $reasons = $this->failureReasons($metadata, $local);

            $this->line(sprintf(
                "  NU: %s | translated: %s | NU chapters: %d | latest local: %s | downloaded: %d | pending: %d | missing: %d",
                $metadata["status_text"] ?: "unknown",
                $metadata["fully_translated"] === null ? "unknown" : ($metadata["fully_translated"] ? "yes" : "no"),
                (int) $metadata["no_of_chapters"],
                $local["latest_chapter"],
                $local["downloaded"],
                $local["pending"],
                $local["missing"]
            ));

            if (empty($reasons)) {
                $this->markComplete($novel, $local, []);
                $completed++;
            } else {
                $this->warn("  Not complete: " . implode("; ", $reasons));
            }

            // Be polite to NovelUpdates when sweeping all novels
            if ($novels->count() > 1) {
                sleep(rand(2, 5));
            }
        }

        $this->info("Done. {$completed} novel(s) " . ($this->option("dry-run") ? "would be" : "") . " marked complete.");
        return 0;
    }

    /**
     * Gather local chapter statistics for a novel.
     */
    private function localStats(Novel $novel): array
    {
        $base = NovelChapter::where("novel_id", $novel->id)->where("blacklist", 0);

        $downloaded = (clone $base)->where("status", 1)->count();
        $pending = (clone $base)->where("status", 0)->count();
        $latestChapter = (clone $base)->max("chapter") ?? 0;

        $existing = [];
        foreach ((clone $base)->get(["chapter", "double_chapter"]) as $row) {
            $existing[] = intval($row->chapter);
            if ($row->double_chapter == 1) {
                $existing[] = intval($row->chapter) + 1;
            }
        }

        // Check gaps from the earliest chapter we actually have, not from 1 —
        // novels whose numbering starts mid-series (or with a chapter-0
        // prologue) would otherwise report hundreds of false missing chapters
        // and never auto-complete.
        $firstChapter = max(1, empty($existing) ? 1 : min($existing));

        $missing = $latestChapter >= 1
            ? array_diff(range($firstChapter, (int) $latestChapter), $existing)
            : [];

        return [
            "downloaded" => $downloaded,
            "pending" => $pending,
            "latest_chapter" => $latestChapter,
            "missing" => count($missing),
            "missing_list" => array_values($missing),
        ];
    }

    /**
     * Return the list of reasons a novel cannot be marked complete (empty = complete).
     */
    private function failureReasons(array $metadata, array $local): array
    {
        $reasons = [];

        if (!$metadata["completed"]) {
            $reasons[] = "NovelUpdates does not list the series as completed" .
                ($metadata["status_text"] ? " ({$metadata["status_text"]})" : "");
        }

        if ($metadata["fully_translated"] === false) {
            $reasons[] = "NovelUpdates lists the translation as incomplete";
        } elseif ($metadata["fully_translated"] === null) {
            $reasons[] = "could not determine the Fully Translated flag from NovelUpdates";
        }

        $nuChapters = (int) $metadata["no_of_chapters"];
        if ($nuChapters <= 0) {
            $reasons[] = "could not read the chapter count from NovelUpdates";
        } elseif ($local["latest_chapter"] < $nuChapters) {
            $reasons[] = "latest local chapter ({$local["latest_chapter"]}) is below the NovelUpdates count ({$nuChapters})";
        }

        if ($local["pending"] > 0) {
            $reasons[] = "{$local["pending"]} chapter(s) still pending download";
        }

        if ($local["missing"] > 0) {
            $preview = implode(", ", array_slice($local["missing_list"], 0, 10));
            $reasons[] = "{$local["missing"]} chapter number(s) missing from the sequence (e.g. {$preview})";
        }

        return $reasons;
    }

    private function markComplete(Novel $novel, array $local, array $notes): void
    {
        $suffix = $notes ? " (" . implode("; ", $notes) . ")" : "";

        if ($this->option("dry-run")) {
            $this->info("  ✓ Would mark complete: {$novel->name}{$suffix}");
            return;
        }

        $novel->status = 1;
        $novel->completed_at = Carbon::now();
        $novel->save();

        Log::info("Novel marked complete: {$novel->name} (ID {$novel->id}), latest chapter {$local["latest_chapter"]}, {$local["downloaded"]} downloaded{$suffix}");
        $this->info("  ✓ Marked complete: {$novel->name}{$suffix}");

        notify_webhook("📚 Completed: {$novel->name} ({$local["downloaded"]} chapters)");

        $this->postCompletionTasks($novel);
    }

    /**
     * After a novel is marked complete: ensure it has a cover, generate the
     * ePub, and email it to the Kindle address. Failures are logged but never
     * roll back the completion itself.
     */
    private function postCompletionTasks(Novel $novel): void
    {
        if (!$this->hasValidCover($novel)) {
            $this->info("  No valid cover on disk — fetching metadata/cover...");
            $this->runArtisan($novel, ["novel:metadata", $novel->id], "metadata/cover refresh");
        }

        $this->info("  Generating ePub...");
        if (!$this->runArtisan($novel, ["novel:epub", $novel->id], "ePub generation")) {
            return; // No ePub — nothing to send to Kindle.
        }

        if ($this->option("no-kindle") || setting("auto_kindle", "1") !== "1") {
            return;
        }

        if (empty(setting("kindle_email", config("mail.kindle_email")))) {
            $this->warn("  KINDLE_EMAIL not configured — skipping Send to Kindle.");
            return;
        }

        $this->info("  Sending to Kindle...");
        $this->runArtisan($novel, ["novel:send-to-kindle", $novel->id], "Send to Kindle");
    }

    /**
     * Run a sub-command as its own PHP process. ePub generation and mailing
     * large attachments are memory-heavy; isolating them keeps one novel's
     * fatal error from killing the whole verification sweep.
     */
    private function runArtisan(Novel $novel, array $arguments, string $label): bool
    {
        try {
            $result = Process::timeout(1800)->run(
                array_merge([PHP_BINARY, base_path("artisan")], $arguments)
            );

            foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
                $this->line("    " . $line);
            }

            if ($result->failed()) {
                $this->warn("  {$label} failed (exit {$result->exitCode()}).");
                Log::error("Post-completion {$label} failed for novel {$novel->id}: " . $result->errorOutput());
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->warn("  {$label} failed: {$e->getMessage()}");
            Log::error("Post-completion {$label} failed for novel {$novel->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Same validity check novel:metadata uses — a File record whose image
     * actually exists and parses on disk.
     */
    private function hasValidCover(Novel $novel): bool
    {
        $novel->load("file");

        if (!isset($novel->file->id) || !$novel->file->file_path) {
            return false;
        }

        $coverPath = storage_path("app/public/" . $novel->file->file_path);
        if (!file_exists($coverPath)) {
            $coverPath = storage_path("app/" . $novel->file->file_path);
        }

        return file_exists($coverPath) && @getimagesize($coverPath) !== false;
    }
}
