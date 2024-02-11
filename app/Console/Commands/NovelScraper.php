<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Novel;
use App\NovelChapter;

class NovelScraper extends Command
{
    protected $signature = "novel:toc {novel=0}";
    protected $description = "Scrape all active novels to create the chapter list.";

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $novelId = $this->argument("novel");

        $novels = Novel::where("status", 0)
            ->when($novelId != 0, function ($query) use ($novelId) {
                return $query->where("id", $novelId);
            })
            ->where("group_id", "!=", 37)
            ->orderBy("name", "asc")
            ->get();

        foreach ($novels as $novel) {
            $this->info("Processing: {$novel->name}");
            $toc = tableOfContentGenerator($novel);
            $this->processChapters($novel, $toc);
        }
    }

    private function processChapters($novel, $toc)
    {
        foreach ($toc as $item) {
            if ($novel->group_id == 6) {
                $item["unique_id"] = $this->extractUniqueId($item["url"]);
            }

            $chapterValue = $this->getChapterValue($item);
            $check_duplicate = NovelChapter::where("novel_id", $novel->id)
                ->where("chapter", $chapterValue)
                ->when(isset($item["book"]), function ($query) use ($item) {
                    return $query->where("book", intval($item["book"]));
                })
                ->first();

            $this->updateOrCreateChapter($check_duplicate, $novel->id, $item);
        }
    }

    private function extractUniqueId($url)
    {
        $urlArr = explode(
            "/",
            str_replace("https://www.webnovel.com/book/", "", $url)
        );
        return $urlArr[1] ?? null;
    }

    private function getChapterValue($item)
    {
        if (strpos($item["chapter"], "-") !== false) {
            return substr($item["chapter"], 0, strpos($item["chapter"], "-"));
        }
        return round($item["chapter"], 2);
    }

    private function updateOrCreateChapter($chapter, $novelId, $item)
    {
        if (empty($chapter)) {
            $chapter = new NovelChapter();
            $chapter->novel_id = $novelId;
        }

        $chapter
            ->fill([
                "label" => $item["label"] ?? null,
                "url" => $item["url"] ?? null,
                "chapter" => $this->getChapterValue($item),
                "book" => intval($item["book"] ?? 0),
                "unique_id" => $item["unique_id"] ?? null,
            ])
            ->save();

        if ($chapter->chapter > 0) {
            $this->info("Chapter processed: " . $chapter->chapter);
        }
    }
}
