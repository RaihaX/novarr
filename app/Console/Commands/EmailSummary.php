<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewChapters;
use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;

class EmailSummary extends Command
{
    protected $signature = "novel:email-summary
        {--hours=24 : Look-back window for downloaded chapters}
        {--to= : Override the recipient address}";

    protected $description = "Email a summary of newly downloaded chapters and newly completed novels.";

    public function handle()
    {
        $to = $this->option("to") ?: config("mail.summary_email");

        if (empty($to)) {
            $this->error("No recipient configured. Set MAIL_SUMMARY_EMAIL or pass --to.");
            return 1;
        }

        $since = Carbon::now()->subHours((int) $this->option("hours"));

        // Select only light columns — description is a longtext and loading it
        // for thousands of chapters exhausts memory.
        $chapters = NovelChapter::with("novel:id,name,no_of_chapters")
            ->where("status", 1)
            ->where("blacklist", 0)
            ->where("download_date", ">=", $since)
            ->orderBy("novel_id")
            ->orderBy("book")
            ->orderBy("chapter")
            ->get(["id", "novel_id", "label", "chapter", "book", "download_date"]);

        $newChapters = $chapters
            ->filter(fn($chapter) => $chapter->novel !== null)
            ->map(function ($chapter) {
                $novel = $chapter->novel;
                $progress = $novel->no_of_chapters == 0
                    ? 0
                    : round(($chapter->chapter / $novel->no_of_chapters) * 100, 2);

                return [
                    "novel" => $novel->name,
                    "label" => $chapter->label,
                    "chapter" => $chapter->chapter,
                    "book" => $chapter->book,
                    "progress" => number_format($progress, 2, ".", ","),
                ];
            })
            ->values()
            ->all();

        $completedNovels = Novel::where("status", 1)
            ->where("completed_at", ">=", $since)
            ->orderBy("name")
            ->get(["name", "completed_at"])
            ->map(fn($novel) => [
                "name" => $novel->name,
                "completed_at" => $novel->completed_at,
            ])
            ->all();

        $attention = $this->novelsNeedingAttention();

        if (empty($newChapters) && empty($completedNovels) && empty($attention)) {
            $this->info("Nothing new since {$since->toDateTimeString()} — no email sent.");
            return 0;
        }

        try {
            Mail::to($to)->send(new NewChapters([
                "since" => $since,
                "chapters" => $newChapters,
                "completed" => $completedNovels,
                "attention" => $attention,
            ]));
        } catch (\Throwable $e) {
            Log::error("Failed to send chapter summary email to {$to}: " . $e->getMessage());
            $this->error("Failed to send summary: " . $e->getMessage());
            return 1;
        }

        Log::info("Sent chapter summary email to {$to}: " . count($newChapters) . " chapter(s), " . count($completedNovels) . " completed novel(s), " . count($attention) . " needing attention.");
        $this->info("Summary sent to {$to}: " . count($newChapters) . " chapter(s), " . count($completedNovels) . " newly completed novel(s), " . count($attention) . " needing attention.");
        return 0;
    }

    /**
     * Active novels that look unhealthy: repeated all-failed scrape runs, or
     * pending chapters that haven't progressed in over a week (stalled).
     */
    private function novelsNeedingAttention(): array
    {
        $attention = [];

        $failing = Novel::where("status", 0)
            ->where("scrape_failures", ">=", 3)
            ->orderBy("name")
            ->get(["id", "name", "scrape_failures", "translator_url"]);

        foreach ($failing as $novel) {
            $attention[$novel->id] = [
                "name" => $novel->name,
                "reason" => "{$novel->scrape_failures} consecutive scrape runs failed — the source site may have changed",
                "url" => $this->sourceUrlFor($novel),
            ];
        }

        $stalled = Novel::where("status", 0)
            ->whereHas("chapters", fn($q) => $q->where("status", 0)->where("blacklist", 0))
            ->orderBy("name")
            ->get(["id", "name"]);

        foreach ($stalled as $novel) {
            if (isset($attention[$novel->id])) {
                continue;
            }

            $lastDownload = NovelChapter::where("novel_id", $novel->id)
                ->where("status", 1)
                ->max("download_date");

            if ($lastDownload === null || Carbon::parse($lastDownload)->lt(Carbon::now()->subDays(7))) {
                $pending = NovelChapter::where("novel_id", $novel->id)
                    ->where("status", 0)->where("blacklist", 0)->count();
                $attention[$novel->id] = [
                    "name" => $novel->name,
                    "reason" => "{$pending} pending chapter(s) but no successful download since "
                        . ($lastDownload ? Carbon::parse($lastDownload)->format("j M Y") : "ever"),
                    "url" => $this->sourceUrlFor($novel),
                ];
            }
        }

        return array_values($attention);
    }

    /**
     * The URL the scraper is failing on: the next pending chapter's resolved
     * source URL, falling back to the novel's translator page.
     */
    private function sourceUrlFor(Novel $novel): ?string
    {
        $chapter = NovelChapter::with("novel.group")
            ->where("novel_id", $novel->id)
            ->where("status", 0)
            ->where("blacklist", 0)
            ->orderBy("book")
            ->orderBy("chapter")
            ->first(["id", "novel_id", "chapter", "book", "url"]);

        if ($chapter && $chapter->novel) {
            return chapterSourceUrl($chapter);
        }

        return $novel->translator_url ?: null;
    }
}
