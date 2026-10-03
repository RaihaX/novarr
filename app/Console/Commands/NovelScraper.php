<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Novel;
use App\NovelChapter;
use App\Scraping\ChapterLabelParser;
use App\Services\ChapterNumberResolver;

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
            $this->syncTableOfContents($novel, is_array($toc) ? $toc : []);

            // Self-heal any chapter the parser couldn't number (e.g. labels
            // carrying two numbers) via elimination against the sequence.
            [$fixed] = \App\Services\ChapterNumberResolver::fixUnnumbered(
                $novel->id,
                fn($msg) => $this->info("  {$msg}")
            );
        }
    }

    /**
     * Sync one novel's scraped TOC into novel_chapters.
     *
     * A page's URL is its identity, so every entry is matched on URL first.
     * Only when the URL is unknown may it take over an existing row by
     * (book, chapter) number — and only if that row's own URL is no longer
     * anywhere in the current TOC (the source moved the page) and the number
     * is a real one (non-zero). Otherwise a new row is created. Without that
     * rule two entries parsing to the same number fought over one row every
     * run (and the second chapter was never created), and an unnumbered
     * end-matter row the resolver had numbered max+1 got hijacked by the next
     * real chapter.
     *
     * Existing rows are loaded once into url / number maps (no per-entry
     * queries); new rows are batch-inserted in TOC order (the resolver's
     * positional elimination relies on ids following reading order).
     *
     * Re-download guard: a downloaded row adopted under a new URL is queued
     * for re-download (its text belongs to the old page) — unless only the
     * scheme/host changed (same path: a domain move, e.g. novelbin →
     * novelarrow), or the run looks like a whole-site URL migration (more
     * than 50 rows, or more than 20% of the novel's rows, relocated at once).
     * In that case no status is reset and the count is returned as
     * `relocated_mass`, so a migration can't trigger a library re-download.
     *
     * @return array{created: int, updated: int, skipped: int, relocated_mass: int}
     */
    public function syncTableOfContents($novel, array $toc): array
    {
        $groupUrl = $novel->group->url ?? "";
        $counts = ["created" => 0, "updated" => 0, "skipped" => 0, "relocated_mass" => 0];

        // Validate first so the set of "URLs still in the TOC" is exact.
        $entries = [];
        foreach ($toc as $item) {
            if (!self::validTocUrl($item["url"] ?? null, $groupUrl)) {
                $counts["skipped"]++;
                $this->warn("  Skipped TOC entry with invalid URL: \"" . ($item["label"] ?? "") . "\" (\"" . ($item["url"] ?? "") . "\")");
                \Log::warning(
                    "TOC entry skipped for {$novel->name}: invalid chapter URL \"" . ($item["url"] ?? "") . "\""
                );
                continue;
            }

            if ($novel->group_id == 6) {
                $item["unique_id"] = $this->extractUniqueId($item["url"]);
            }

            $entries[] = $item;
        }

        $tocUrls = [];
        foreach ($entries as $item) {
            $tocUrls[(string) $item["url"]] = true;
        }

        // One query per novel instead of up to two per TOC entry.
        $rows = NovelChapter::where("novel_id", $novel->id)->orderBy("id")->get();
        $byUrl = [];
        $byNumber = [];
        foreach ($rows as $row) {
            if ((string) $row->url !== "" && !isset($byUrl[(string) $row->url])) {
                $byUrl[(string) $row->url] = $row;
            }
            $byNumber[self::numberKey($row->chapter)][] = $row;
        }

        $claimed = [];   // row id => true, rows already matched this run
        $pending = [];   // url => attributes for rows to insert
        $matches = [];   // [row, item] updates, applied after the relocation count
        $endMatter = []; // row id => resolver-numbered end matter a new chapter collides with

        foreach ($entries as $item) {
            $url = (string) $item["url"];

            // Same URL listed twice in one TOC: the later entry wins, as it
            // did when each entry was saved straight away.
            if (isset($pending[$url])) {
                $pending[$url] = $this->newRowAttributes($novel->id, $item);
                continue;
            }

            $row = $byUrl[$url] ?? null;

            if (!$row) {
                $row = $this->findRelocatedRow($byNumber, $item, $tocUrls, $claimed);
            }

            if ($row) {
                $claimed[$row->id] = true;
                $matches[] = [$row, $item];
                continue;
            }

            $pending[$url] = $this->newRowAttributes($novel->id, $item);

            // A new real chapter landing on the number the resolver gave an
            // unnumbered end-matter row (label without digits, e.g.
            // "Afterword" → max+1): that row is moved past the end below.
            // Also caught by sort key: end matter sits at max+0.9, so any new
            // chapter keyed past it would otherwise read before it.
            $value = (float) $pending[$url]["chapter"];
            $newKey = $pending[$url]["sort_key"];
            if ($value > 0) {
                foreach ($rows as $other) {
                    if ((int) $other->book !== $pending[$url]["book"] || preg_match('/\d/', (string) $other->label)
                        || (float) $other->chapter <= 0) {
                        continue;
                    }
                    $collides = self::numberKey($other->chapter) === self::numberKey($value)
                        || ($newKey !== null && ChapterNumberResolver::isEndMatterSortKey($other->sort_key)
                            && $newKey > $other->sort_key);
                    if ($collides) {
                        $endMatter[$other->id] = $other;
                    }
                }
            }
        }

        $relocations = count(array_filter(
            $matches,
            fn($m) => self::urlPathChanged((string) $m[0]->url, (string) $m[1]["url"])
        ));
        $allowReset = $relocations <= 50 && $relocations <= 0.2 * max(1, $rows->count());
        if (!$allowReset) {
            $counts["relocated_mass"] = $relocations;
            $this->warn("  {$relocations} chapter URLs changed at once — looks like a source migration; not re-downloading");
            \Log::warning(
                "TOC sync for {$novel->name}: {$relocations} of {$rows->count()} chapter rows relocated in one run; "
                . "treated as a URL migration, no chapters queued for re-download"
            );
        }

        foreach ($matches as [$row, $item]) {
            if ($this->updateChapter($row, $item, $allowReset)) {
                $counts["updated"]++;
            }
        }

        if ($pending) {
            $now = now();
            $insert = array_map(
                fn($attrs) => $attrs + ["created_at" => $now, "updated_at" => $now],
                array_values($pending)
            );
            foreach (array_chunk($insert, 200) as $batch) {
                NovelChapter::insert($batch);
            }
            $counts["created"] = count($insert);
            $this->info("  Created {$counts["created"]} new chapter row(s)");
        }

        foreach ($endMatter as $row) {
            $max = (float) NovelChapter::where("novel_id", $novel->id)
                ->where("book", (int) $row->book)
                ->where("id", "!=", $row->id)
                ->max("chapter");
            $old = $row->chapter;
            // Legacy number max+1 (compatibility), sorted at max+0.9 so the
            // next real chapter's max+1 never collides with it.
            $max = max($max, (float) $row->chapter - 1);
            $row->chapter = floor($max) + 1;
            $row->number = (float) $row->chapter;
            $row->part = 0;
            $row->sort_key = ChapterNumberResolver::endMatterSortKey($max);
            $row->save();
            \Log::info("TOC sync for {$novel->name}: end matter \"{$row->label}\" moved from {$old} to {$row->chapter} (a new chapter took its number)");
        }

        return $counts;
    }

    /**
     * Number fallback for a TOC entry whose URL matched no row: an existing
     * row with the same (book, chapter) may be adopted only when its own URL
     * has vanished from the TOC (the source moved that page) and the number
     * is non-zero. Each row can be adopted at most once per run.
     */
    private function findRelocatedRow(array $byNumber, array $item, array $tocUrls, array $claimed): ?NovelChapter
    {
        $chapterValue = $this->getChapterValue($item);
        if ((float) $chapterValue == 0.0) {
            return null;
        }

        foreach ($byNumber[self::numberKey($chapterValue)] ?? [] as $row) {
            if (isset($claimed[$row->id])) {
                continue;
            }
            if (isset($item["book"]) && (int) $row->book !== intval($item["book"])) {
                continue;
            }
            if ((string) $row->url !== "" && isset($tocUrls[(string) $row->url])) {
                continue; // still a live page of its own — not relocated
            }
            return $row;
        }

        return null;
    }

    /**
     * Did a chapter URL change beyond its scheme/host? A domain move keeps
     * the same page (same path + query), so its stored text stays valid.
     */
    public static function urlPathChanged(string $old, string $new): bool
    {
        if ($old === $new) {
            return false;
        }

        $tail = function (string $url): string {
            $parts = parse_url($url) ?: [];
            return ($parts["path"] ?? "") . "?" . ($parts["query"] ?? "");
        };

        return $tail($old) !== $tail($new);
    }

    /** Map key for a chapter number; tolerant of float/string differences. */
    private static function numberKey($value): string
    {
        return number_format((float) $value, 2, ".", "");
    }

    private function newRowAttributes(int $novelId, array $item): array
    {
        $value = $this->getChapterValue($item);
        if ((float) $value > 0) {
            $this->info("Chapter processed: " . (float) $value);
        }

        return [
            "novel_id" => $novelId,
            "label" => $item["label"] ?? null,
            "url" => $item["url"] ?? null,
            "chapter" => (float) $value,
            "book" => intval($item["book"] ?? 0),
            "unique_id" => $item["unique_id"] ?? null,
        ] + $this->structuredColumns($item, (float) $value);
    }

    /**
     * number / part / title / source_label / sort_key for a TOC entry, from
     * ChapterLabelParser reconciled with the legacy chapter value (which
     * stays authoritative — `chapter` is written exactly as before). A parse
     * that disagrees with it beyond the part fraction is logged.
     */
    private function structuredColumns(array $item, float $legacy): array
    {
        $label = (string) ($item["label"] ?? "");
        $book = intval($item["book"] ?? 0);
        $parsed = ChapterLabelParser::parse($label, $item["url"] ?? null, $book);
        $structured = ChapterLabelParser::reconcile($parsed, $legacy);

        if (!$structured["agrees"]) {
            \Log::warning(
                "TOC label parse disagrees with the chapter number: \"{$label}\" parsed "
                . var_export($parsed->number, true) . " (part {$parsed->part}), TOC chapter {$legacy}; ordering by the TOC number"
            );
        }

        return [
            "number" => $structured["number"],
            "part" => $structured["part"],
            "title" => $parsed->title !== null ? mb_substr($parsed->title, 0, 255) : null,
            "source_label" => $parsed->sourceLabel !== "" ? $parsed->sourceLabel : null,
            "sort_key" => $structured["sort_key"],
        ];
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

        if (preg_match('~^https?://[^\s,]+$~', $resolved) !== 1) {
            return false;
        }

        // A real hostname ends in an alphabetic TLD. The 404 page of a moved
        // AJAX endpoint yields <option> values like "16px", which resolved
        // against the group URL become "https://novelfull.com16px" — a string
        // the loose regex above happily accepted (and stored as a chapter).
        $host = (string) (parse_url($resolved, PHP_URL_HOST) ?? '');

        return preg_match('/^(?:[a-z0-9-]+\.)+(?:[a-z]{2,}|xn--[a-z0-9-]+)$/i', $host) === 1
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
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

    /**
     * Apply a TOC entry to an existing row. Returns true when it changed.
     */
    private function updateChapter(NovelChapter $chapter, array $item, bool $allowReset = true): bool
    {
        // Never downgrade an already-resolved number: if this scrape failed to
        // parse one (0) but the row has one (fixed manually or by the
        // resolver), the resolved value wins.
        $newValue = $this->getChapterValue($item);
        $keepResolved = $newValue <= 0 && (float) $chapter->chapter > 0;
        if ($keepResolved) {
            $newValue = $chapter->chapter;
        }

        $oldUrl = (string) $chapter->url;

        $structured = $this->structuredColumns($item, (float) $newValue);
        if ($keepResolved && $chapter->sort_key !== null) {
            // Same never-downgrade rule for the structured number: keep what
            // the resolver (or a manual fix) assigned, e.g. end matter at max+0.9.
            unset($structured["number"], $structured["part"], $structured["sort_key"]);
        }

        $chapter->fill([
            "label" => $item["label"] ?? null,
            "url" => $item["url"] ?? null,
            "chapter" => $newValue,
            "book" => intval($item["book"] ?? 0),
            "unique_id" => $item["unique_id"] ?? null,
        ] + $structured);

        // The stored text belongs to the old page — queue a re-download.
        if ($allowReset && $chapter->status && self::urlPathChanged($oldUrl, (string) $chapter->url)) {
            $chapter->status = 0;
            $this->warn("  Chapter {$chapter->chapter} moved to a new URL — queued for re-download");
            \Log::info(
                "Chapter {$chapter->id} ({$chapter->label}) URL changed from \"{$oldUrl}\" to \"{$chapter->url}\"; status reset for re-download"
            );
        }

        if (!$chapter->isDirty()) {
            return false;
        }

        $chapter->save();

        if ($chapter->chapter > 0) {
            $this->info("Chapter processed: " . $chapter->chapter);
        }

        return true;
    }
}
