<?php

namespace App\Console\Commands;

use App\Novel;
use App\Scraping\OriginInference;
use App\Services\MangaOrigin;
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
 * inferred rows with a definite answer, are skipped unless --force (or
 * --reinfer for the inferred ones); unknown rows are always re-evaluated
 * (cheap).
 *
 * --medium=manga runs App\Services\MangaOrigin instead (MangaDex's original
 * language, a Manga/Manhwa/Manhua genre, the cached MangaDex title lookup):
 * rows without an origin are filled, rows the source set are re-detected
 * with --reinfer or --force. --dry-run prints what would change and writes
 * nothing.
 */
class NovelOrigin extends Command
{
    protected $signature = "novel:origin {novel=0 : Novel ID (0 = all novels)}
        {--force : Also re-evaluate inferred rows and re-fetch rows already set from NovelUpdates}
        {--reinfer : Re-run detection for rows whose origin was inferred (manga: set by the source), replacing it}
        {--dry-run : Print id, name, author, old → new, confidence and reason; change nothing}
        {--medium=novel : novel | manga}
        {--no-fetch : Skip NovelUpdates (manga: MangaDex); use stored data only}
        {--delay=2 : Seconds between NovelUpdates fetches}";

    protected $description = "Mark novels as translated or original English (NovelUpdates, else inferred).";

    public function handle(): int
    {
        $novelId = (int) $this->argument("novel");
        $force = (bool) $this->option("force");
        $fetch = !$this->option("no-fetch");
        $delay = max(0.0, (float) $this->option("delay"));
        $reinfer = (bool) $this->option("reinfer");
        $dryRun = (bool) $this->option("dry-run");
        $medium = (string) $this->option("medium");
        if (!in_array($medium, [Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA], true)) {
            $this->error("--medium must be novel or manga.");
            return 1;
        }

        // A web novel is inferred / looked up on NovelUpdates; a manga's
        // origin comes from its source (MangaOrigin) — never the other way.
        $query = Novel::query()->with("tags")->where("medium", $medium)->orderBy("id");
        if ($novelId > 0) {
            $query->where("id", $novelId);
        }
        $novels = $query->get();

        if ($novels->isEmpty()) {
            $this->error($novelId > 0 ? "Novel {$novelId} not found." : "No novels.");
            return 1;
        }

        if ($dryRun) {
            return $this->dryRun($novels, $medium, $force || $reinfer, $fetch);
        }

        $fetched = 0;
        $changed = 0;

        foreach ($novels as $novel) {
            $before = [$novel->origin, $novel->origin_language, $novel->origin_source, $novel->origin_confidence];
            $how = $medium === Novel::MEDIUM_MANGA
                ? $this->evaluateManga($novel, $force || $reinfer, $fetch)
                : $this->evaluate($novel, $force, $reinfer, $fetch, $delay, $fetched);

            $after = [$novel->origin, $novel->origin_language, $novel->origin_source, $novel->origin_confidence];
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
    private function evaluate(Novel $novel, bool $force, bool $reinfer, bool $fetch, float $delay, int &$fetched): string
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

        if ($this->keepsInferred($novel, $force || $reinfer)) {
            return "inferred — kept";
        }

        $result = $novel->inferOrigin();

        return $result ? "inferred ({$result['confidence']}): {$result['reason']}" : "kept";
    }

    /** An inferred row with a definite answer stays unless re-inference was asked for. */
    private function keepsInferred(Novel $novel, bool $again): bool
    {
        return $novel->origin_source === "inferred" && $novel->origin !== OriginInference::UNKNOWN && $novel->origin !== null && !$again;
    }

    private ?MangaOrigin $mangaOrigin = null;

    /** One instance for the run, so MangaDex requests are paced. */
    private function mangaOrigin(): MangaOrigin
    {
        return $this->mangaOrigin ??= app(MangaOrigin::class);
    }

    /** A manga: MangaOrigin; manual kept, a source answer kept unless $again. */
    private function evaluateManga(Novel $novel, bool $again, bool $fetch): string
    {
        if ($novel->origin_source === "manual") {
            return "manual — kept";
        }
        if ($novel->origin_source === "source" && !$again) {
            return "source — kept";
        }

        $result = $this->mangaOrigin()->detect($novel, $fetch);

        return $result ? "source ({$result['confidence']}): {$result['reason']}" : "no origin found";
    }

    /**
     * --dry-run: what a run would set, as a table; nothing is written and
     * NovelUpdates is not fetched (a row it would decide says so).
     */
    private function dryRun($novels, string $medium, bool $again, bool $fetch): int
    {
        $rows = [];
        $changes = 0;
        foreach ($novels as $novel) {
            $old = self::describe($novel->origin, $novel->origin_language);
            $new = $old;
            $confidence = (string) ($novel->origin_confidence ?? "—");
            $reason = "kept";

            if ($novel->origin_source === "manual") {
                $reason = "manual — kept";
            } elseif ($medium === Novel::MEDIUM_MANGA) {
                if ($novel->origin_source === "source" && !$again) {
                    $reason = "source — kept";
                } elseif ($result = $this->mangaOrigin()->detect($novel, $fetch, false)) {
                    [$new, $confidence, $reason] = [self::describe($result["origin"], $result["origin_language"]), $result["confidence"], $result["reason"]];
                } else {
                    $reason = "no origin found";
                }
            } elseif ($novel->origin_source === "novelupdates") {
                $reason = "NovelUpdates — kept";
            } elseif ($novel->hasConfidentNovelUpdatesMatch() && $fetch) {
                $reason = "NovelUpdates match — not fetched in a dry run";
            } elseif ($this->keepsInferred($novel, $again)) {
                $reason = "inferred — kept";
            } elseif ($result = $novel->inferOrigin(false)) {
                [$new, $confidence, $reason] = [self::describe($result["origin"], $result["origin_language"]), $result["confidence"], $result["reason"]];
            }

            if ($new !== $old || $confidence !== (string) ($novel->origin_confidence ?? "—")) {
                $changes++;
            }
            $rows[] = [
                $novel->id,
                mb_strimwidth((string) $novel->name, 0, 40, "…"),
                mb_strimwidth((string) ($novel->author ?? ""), 0, 24, "…"),
                "{$old} → {$new}",
                $confidence,
                $reason,
            ];
        }

        $this->table(["ID", "Name", "Author", "Old → new", "Confidence", "Reason"], $rows);
        $this->info("Dry run: {$changes} novel(s) would change; nothing was written.");

        return 0;
    }

    /** "translated · ko" / "original · en" / "unknown". */
    private static function describe(?string $origin, ?string $language): string
    {
        $origin = $origin ?? OriginInference::UNKNOWN;

        return $language ? "{$origin} · {$language}" : $origin;
    }
}
