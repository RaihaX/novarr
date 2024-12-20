<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Novel;
use App\NovelChapter;

class ShowNovelInfo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "novel:info";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Show novel information, including the total chapters, how many of them have content in the description field, and the percentage of completion.";

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $novels = Novel::with("chapters")
            ->get()
            ->map(function ($novel) {
                $totalChapters = $novel->chapters->count();
                $chaptersWithContent = $novel->chapters
                    ->where("description", "!=", "")
                    ->count();
                $percentageComplete =
                    $totalChapters > 0
                        ? round(
                            ($chaptersWithContent / $totalChapters) * 100,
                            2
                        )
                        : 0;

                return [
                    "novel" => $novel,
                    "totalChapters" => $totalChapters,
                    "chaptersWithContent" => $chaptersWithContent,
                    "percentageComplete" => $percentageComplete,
                ];
            })
            ->sortBy("percentageComplete");

        if ($novels->isEmpty()) {
            $this->info("No novels found.");
            return 0;
        }

        $this->info("Novel Information:");

        foreach ($novels as $data) {
            $novel = $data["novel"];
            $totalChapters = $data["totalChapters"];
            $chaptersWithContent = $data["chaptersWithContent"];
            $percentageComplete = $data["percentageComplete"];

            $this->line("---------------------------------");
            $this->line("Novel: {$novel->name}");
            $this->line("Total Chapters: {$totalChapters}");
            $this->line("Chapters with Content: {$chaptersWithContent}");
            $this->line("Completion Percentage: {$percentageComplete}%");
        }

        return 0;
    }
}
