<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Novel;

class UpdateMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "novel:metadata {novel?}
        {--novelupdates-url= : Force this NovelUpdates series URL for the novel (match score 1.0; requires a novel ID)}
        {--all : Include completed novels (default without a novel ID: active novels only)}";

    /** Seconds between novels in a multi-novel run (each one hits the network). */
    public const DELAY_SECONDS = 2;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Update Metadata of novels";

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $novelId = $this->argument("novel");

        if ($forced = $this->option("novelupdates-url")) {
            if (!$novelId) {
                $this->error("--novelupdates-url requires a novel ID.");
                return 1;
            }
            if (!preg_match('#^https?://(?:www\.)?novelupdates\.com/series/[a-z0-9-]+/?$#i', $forced)) {
                $this->error("Not a NovelUpdates series URL: {$forced}");
                return 1;
            }
            $target = Novel::find($novelId);
            if (!$target) {
                $this->error("Novel {$novelId} not found.");
                return 1;
            }
            // A hand-picked match is fully trusted.
            $target->forceFill([
                "novelupdates_url" => rtrim($forced, "/") . "/",
                "novelupdates_match_score" => 1.0,
                // A forced URL supersedes any remembered "no match".
                "novelupdates_checked_at" => null,
            ])->saveQuietly();
            $this->info("NovelUpdates URL set to {$target->novelupdates_url} (score 1.0)");
        }

        $query = Novel::with("file");
        if ($novelId) {
            $query->where("id", $novelId);
        } elseif (!$this->option("all")) {
            $query->where("status", 0);
        }

        $previousFetched = false;
        foreach ($query->get() as $item) {
            // Be gentle with the sources (and NovelUpdates) across a long run.
            if ($previousFetched) {
                $this->pause(self::DELAY_SECONDS);
            }
            $previousFetched = false;

            $this->info($item->name);

            // Detect invalid/missing cover on disk; drop the stale File record so fallback runs.
            $hasValidCover = false;
            if (isset($item->file->id) && $item->file->file_path) {
                $coverPath = storage_path("app/public/" . $item->file->file_path);
                // file_path may be stored as "public/abc.jpg" — strip the leading "public/" if present
                if (!file_exists($coverPath)) {
                    $coverPath = storage_path("app/" . $item->file->file_path);
                }
                if (file_exists($coverPath) && @getimagesize($coverPath)) {
                    $hasValidCover = true;
                } else {
                    $this->warn("  Existing cover invalid or missing on disk — re-fetching.");
                    $item->file->delete();
                    $item->unsetRelation('file');
                }
            }

            $source = \App\Sources\SourceResolver::for($item);
            $this->line("  Source: {$source->name()}");
            $previousFetched = true; // every source's metadata() fetches its novel page or API
            $metadata = $source->metadata($item);
            $coverCandidates = $metadata["cover_candidates"] ?? array_filter([$metadata["image"] ?? null]);
            $this->reportFetch($metadata);

            if (empty($metadata["description"])) {
                $this->warn("  ✗ No description found on either source — check the URLs above in a browser.");
            }

            $novel = Novel::find($item->id);

            if (!empty($metadata["description"])) {
                $novel->description = $metadata["description"];
            }

            if (!empty($metadata["author"])) {
                // No novelupdates_checked_at reset here: on a weak slug match
                // the NovelUpdates and source authors alternate between runs,
                // and a reset on every change made the weekly lookup run
                // every other run. A human edit (NovelController) or
                // --novelupdates-url clears the stamp instead.
                $novel->author = $metadata["author"];
            }

            if (!empty($metadata["no_of_chapters"])) {
                $novel->no_of_chapters = $metadata["no_of_chapters"];
            }

            // A manga source suggests how its pages read; only filled in
            // while the novel has none (never over a user's choice).
            if (!empty($metadata["reading_mode"]) && $novel->reading_mode === null
                && in_array($metadata["reading_mode"], Novel::READING_MODES, true)) {
                $novel->reading_mode = $metadata["reading_mode"];
                $this->line("    reading mode: {$novel->reading_mode}");
            }

            // The site behind a shared host (a Suwayomi source): the novel's
            // download lane (App\Services\DownloadLanes).
            if (!empty($metadata["source_ref"]) && $novel->source_ref !== (string) $metadata["source_ref"]) {
                $novel->source_ref = (string) $metadata["source_ref"];
                $this->line("    source: {$novel->source_ref}");
            }

            $novel->save();

            if (!empty($metadata["genres"])) {
                $tagIds = collect($metadata["genres"])
                    ->map(fn($g) => \App\Tag::firstOrCreate(["name" => $g])->id);
                $novel->tags()->syncWithoutDetaching($tagIds);
                $this->line("    genres: " . implode(", ", $metadata["genres"]));
            }

            // A manga's origin from its source (App\Services\MangaOrigin):
            // never over a manual one.
            if ($novel->isManga() && ($origin = app(\App\Services\MangaOrigin::class)->fromMetadata($novel, $metadata))) {
                $this->line("    origin: " . ($novel->originCountryLabel() ?? ($origin["origin_language"] ?? "unknown")) . " ({$origin['reason']})");
            }

            if (!$hasValidCover && !empty($coverCandidates)) {
                $saved = false;

                foreach (array_unique($coverCandidates) as $imageUrl) {
                    $downloaded = downloadCoverImage($imageUrl, $novel->id);

                    if ($downloaded) {
                        $novel->attachCover($downloaded["filename"], $downloaded["basename"]);
                        $this->info("  Cover saved: {$downloaded['filename']}");
                        $saved = true;
                        break;
                    }

                    $this->warn("  Cover download failed from {$imageUrl}" . (count($coverCandidates) > 1 ? " — trying next source" : ""));
                }

                if (!$saved) {
                    $this->warn("  ✗ No cover could be downloaded.");
                }
            }
        }
    }

    /** Wait between novels; overridden in tests. */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * One-line summary of what a metadata fetch returned, so an all-novels
     * run shows exactly what was found (or missed) per source as it goes.
     */
    private function reportFetch(array $metadata): void
    {
        $this->line(sprintf(
            "    description: %s | author: %s | chapters: %s | cover: %s",
            !empty($metadata["description"]) ? strlen($metadata["description"]) . " chars" : "—",
            !empty($metadata["author"]) ? $metadata["author"] : "—",
            !empty($metadata["no_of_chapters"]) ? $metadata["no_of_chapters"] : "—",
            !empty($metadata["image"]) ? "yes" : "—"
        ));
    }
}
