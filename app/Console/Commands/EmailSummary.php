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

        $attention = app(\App\Services\NovelHealth::class)->needingAttention();

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

}
