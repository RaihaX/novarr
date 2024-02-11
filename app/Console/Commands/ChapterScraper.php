<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Novel;
use App\Mail\NewChapters;
use Carbon\Carbon;

class ChapterScraper extends Command
{
    protected $signature = "novel:chapter {novel=0}";
    protected $description = "Scrape new chapters for each novel.";

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $novelId = $this->argument("novel");
        $newChapters = $this->scrapeChapters($novelId);
    }

    private function scrapeChapters($novelId)
    {
        $newChapters = [];
        $query = Novel::where("status", 0)
            ->where("group_id", "!=", 37)
            ->whereHas("chapters", function ($q) {
                $q->where("status", 0)->where("blacklist", 0);
            });

        if ($novelId != 0) {
            $query->where("id", $novelId);
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
                    $this->processNovel($novel, $newChapters);
                }
            });

        return $newChapters;
    }

    private function processNovel($novel, &$newChapters)
    {
        if (count($novel->chapters) > 0) {
            foreach ($novel->chapters as $item) {
                $this->info("Processing: {$novel->name} - {$item->label}");
                $description = $this->generateChapterDescription($item);

                if (str_word_count($description) > 250) {
                    $this->updateChapter($item, $description);
                    $this->addChapterToArray($novel, $item, $newChapters);
                }
            }
        }
    }

    private function generateChapterDescription($chapter)
    {
        $description = "";

        foreach (chapterGenerator($chapter) as $c) {
            $description .= $c;
        }
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
    }
}
