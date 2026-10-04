<?php

namespace App\Console\Commands;

use App\Novel;
use App\Scraping\OriginInference;
use Illuminate\Console\Command;

/**
 * Backfill / refresh each novel's origin (translated web novel vs original
 * English):
 *
 *   · confident NovelUpdates match → read #showtype / #showlang from its
 *     series page (2 s apart — be polite to NovelUpdates)
 *   · otherwise → OriginInference on what's stored (author, host, first
 *     chapters, TOC labels), no network
 *
 * Manual origins are never touched. Rows already set by NovelUpdates, and
 * inferred rows with a definite answer, are skipped unless --force; unknown
 * rows are always re-evaluated (cheap).
 */
class NovelOrigin extends Command
{
    protected $signature = "novel:origin {novel=0 : Novel ID (0 = all novels)}
        {--force : Also re-evaluate inferred rows and re-fetch rows already set from NovelUpdates}
        {--no-fetch : Skip NovelUpdates; infer from stored data only}
        {--delay=2 : Seconds between NovelUpdates fetches}";

    protected $description = "Mark novels as translated or original English (NovelUpdates, else inferred).";

    public function handle(): int
    {
        $novelId = (int) $this->argument("novel");
        $force = (bool) $this->option("force");
        $fetch = !$this->option("no-fetch");
        $delay = max(0.0, (float) $this->option("delay"));

        $query = Novel::query()->orderBy("id");
        if ($novelId > 0) {
            $query->where("id", $novelId);
        }
        $novels = $query->get();

        if ($novels->isEmpty()) {
            $this->error($novelId > 0 ? "Novel {$novelId} not found." : "No novels.");
            return 1;
        }

        $fetched = 0;
        $changed = 0;

        foreach ($novels as $novel) {
            $before = [$novel->origin, $novel->origin_language, $novel->origin_source];
            $how = $this->evaluate($novel, $force, $fetch, $delay, $fetched);

            $after = [$novel->origin, $novel->origin_language, $novel->origin_source];
            if ($before !== $after) {
                $changed++;
            }

            $this->line(sprintf(
                "  #%-4d %-45s %-26s %s",
                $novel->id,
                mb_strimwidth((string) $novel->name, 0, 45, "…"),
                $novel->originLabel() ?? "unknown",
                $how
            ));
        }

        // Summary over the whole library (or the one novel).
        $rows = Novel::query()
            ->when($novelId > 0, fn($q) => $q->where("id", $novelId))
            ->get(["origin", "origin_source"]);
        $count = fn(string $origin) => $rows->filter(fn($n) => ($n->origin ?? OriginInference::UNKNOWN) === $origin);

        $this->newLine();
        $this->table(
            ["Origin", "Novels", "NovelUpdates", "Inferred", "Manual"],
            collect([OriginInference::TRANSLATED, OriginInference::ORIGINAL, OriginInference::UNKNOWN])
                ->map(function ($origin) use ($count) {
                    $set = $count($origin);
                    return [
                        $origin,
                        $set->count(),
                        $set->where("origin_source", "novelupdates")->count(),
                        $set->where("origin_source", "inferred")->count(),
                        $set->where("origin_source", "manual")->count(),
                    ];
                })
                ->all()
        );
        $this->info("{$changed} novel(s) changed, {$fetched} NovelUpdates page(s) fetched.");

        return 0;
    }

    /** Update one novel in place; returns a short note on what was done. */
    private function evaluate(Novel $novel, bool $force, bool $fetch, float $delay, int &$fetched): string
    {
        if ($novel->origin_source === "manual") {
            return "manual — kept";
        }

        if ($novel->hasConfidentNovelUpdatesMatch()) {
            if ($novel->origin_source === "novelupdates" && !$force) {
                return "NovelUpdates — kept";
            }
            if ($fetch) {
                if ($fetched > 0 && $delay > 0) {
                    usleep((int) ($delay * 1_000_000));
                }
                $fetched++;
                $attributes = OriginInference::fromNovelUpdates(fetchNovelUpdatesMetadata($novel->novelupdates_url));
                if ($attributes) {
                    $novel->applyOrigin($attributes, "novelupdates");
                    return "NovelUpdates" . ($novel->origin_type ? " ({$novel->origin_type})" : "");
                }
                // Page unreadable (Cloudflare, layout change): fall through.
                if ($novel->origin_source === "novelupdates") {
                    return "NovelUpdates page unreadable — kept";
                }
            }
        }

        if ($novel->origin_source === "inferred" && $novel->origin !== OriginInference::UNKNOWN && $novel->origin !== null && !$force) {
            return "inferred — kept";
        }

        $result = $novel->inferOrigin();

        return $result ? "inferred: {$result['reason']}" : "kept";
    }
}
