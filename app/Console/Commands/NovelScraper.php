<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Novel;
use App\NovelChapter;

class NovelScraper extends Command
{
    protected $signature = "novel:toc {novel=0} {--frequent-only : Only novels flagged for hourly TOC checks}";
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
            // Paused novels are skipped in the automatic sweep but still run
            // when a specific novel is requested explicitly.
            ->when($novelId == 0, fn($query) => $query->whereNull("paused_at"))
            // Hourly priority sweep only touches flagged novels.
            ->when($this->option("frequent-only"), fn($query) => $query->where("frequent_toc", 1))
            ->where("group_id", "!=", 37)
            ->orderBy("name", "asc")
            ->get();

        foreach ($novels as $novel) {
            $this->info("Processing: {$novel->name}");
            $toc = tableOfContentGenerator($novel);
            $this->processChapters($novel, $toc);

            // Self-heal any chapter the parser couldn't number (e.g. labels
            // carrying two numbers) via elimination against the sequence.
            [$fixed] = \App\Services\ChapterNumberResolver::fixUnnumbered(
                $novel->id,
                fn($msg) => $this->info("  {$msg}")
            );
        }
    }

    private function processChapters($novel, $toc)
    {
        foreach ($toc as $item) {
            if (!self::validTocUrl($item["url"] ?? null, $novel->group->url ?? "")) {
                $this->warn("  Skipped TOC entry with invalid URL: \"" . ($item["label"] ?? "") . "\" (\"" . ($item["url"] ?? "") . "\")");
                \Log::warning(
                    "TOC entry skipped for {$novel->name}: invalid chapter URL \"" . ($item["url"] ?? "") . "\""
                );
                continue;
            }

            if ($novel->group_id == 6) {
                $item["unique_id"] = $this->extractUniqueId($item["url"]);
            }

            // A page's URL is its identity — match on it first so a chapter
            // whose label changed between scrapes (and now parses differently)
            // updates its own row instead of colliding with another chapter's
            // number or spawning a duplicate.
            $check_duplicate = null;
            if (!empty($item["url"])) {
                $check_duplicate = NovelChapter::where("novel_id", $novel->id)
                    ->where("url", $item["url"])
                    ->first();
            }

            if (!$check_duplicate) {
                $chapterValue = $this->getChapterValue($item);
                $check_duplicate = NovelChapter::where("novel_id", $novel->id)
                    ->where("chapter", $chapterValue)
                    ->when(isset($item["book"]), function ($query) use ($item) {
                        return $query->where("book", intval($item["book"]));
                    })
                    ->first();
            }

            $this->updateOrCreateChapter($check_duplicate, $novel->id, $item);
        }
    }

    /**
     * A TOC anchor only counts as a chapter if its resolved URL is a clean
     * link. Guards against page CSS scraped as chapters (label "Arial", url
     * "Arial, sans-serif") jamming the download queue with junk rows the
     * chapter scraper can never fetch. Mirrors chapterSourceUrl(): a relative
     * URL is resolved against the group's base URL. Pure — unit tested.
     */
    public static function validTocUrl(?string $url, ?string $groupUrl): bool
    {
        $resolved = preg_match("/^http/", (string) $url)
            ? (string) $url
            : (string) $groupUrl . (string) $url;

        return preg_match('~^https?://[^\s,]+$~', $resolved) === 1;
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

        // Never downgrade an already-resolved number: if this scrape failed to
        // parse one (0) but the row has one (fixed manually or by the
        // resolver), the resolved value wins.
        $newValue = $this->getChapterValue($item);
        if ($newValue <= 0 && (float) $chapter->chapter > 0) {
            $newValue = $chapter->chapter;
        }

        $chapter
            ->fill([
                "label" => $item["label"] ?? null,
                "url" => $item["url"] ?? null,
                "chapter" => $newValue,
                "book" => intval($item["book"] ?? 0),
                "unique_id" => $item["unique_id"] ?? null,
            ])
            ->save();

        if ($chapter->chapter > 0) {
            $this->info("Chapter processed: " . $chapter->chapter);
        }
    }
}
