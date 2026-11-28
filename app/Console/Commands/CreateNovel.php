<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

use App\Novel;
use App\Services\DuplicateSeries;

class CreateNovel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "novel:create {name} {url}
        {--force : Add it even when a series of the same title is already in the library}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Create new novel.";

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
        $name = $this->argument("name");
        $url = $this->argument("url");

        // Already in the library (DuplicateSeries): the same source page is
        // always skipped; the same title in the medium from another source
        // unless --force. Skipping is not a failure — the command runs
        // unattended from the queue (the web Discover page, the Commands page).
        $existing = app(DuplicateSeries::class)->find($name, DuplicateSeries::mediumFor($url), [], $url);
        if ($existing !== null && ($existing['by'] === DuplicateSeries::BY_SOURCE || !$this->option('force'))) {
            $novel = $existing['novel'];
            $hint = $existing['by'] === DuplicateSeries::BY_TITLE ? ' Run with --force to add it anyway.' : '';
            $this->warn('Skipped: ' . DuplicateSeries::message($novel) . " Existing ID: {$novel->id}.{$hint}");
            Log::info('novel:create skipped a series already in the library', [
                'name' => $name,
                'url' => $url,
                'existing' => $novel->id,
                'by' => $existing['by'],
            ]);

            return self::SUCCESS;
        }

        $object = new Novel();
        $object->name = $name;
        $object->translator_url = $url;
        $object->group_id = 1;
        // Novel or manga: decided once, by the source that claims the URL.
        $object->medium = \App\Sources\SourceResolver::mediumForUrl($url);
        $object->save();

        $source = \App\Sources\SourceResolver::for($object);
        $this->info("  Source: {$source->name()} — fetching metadata…");
        $metadata = $source->metadata($object);
        $coverCandidates = $metadata["cover_candidates"] ?? array_filter([$metadata["image"] ?? null]);

        if (!empty($metadata["description"])) {
            $object->description = $metadata["description"];
        }

        if (!empty($metadata["author"])) {
            $object->author = $metadata["author"];
        }

        if (!empty($metadata["no_of_chapters"])) {
            $object->no_of_chapters = $metadata["no_of_chapters"];
        }

        // A manga source suggests how its pages read; never over a choice.
        if (!empty($metadata["reading_mode"]) && $object->reading_mode === null
            && in_array($metadata["reading_mode"], Novel::READING_MODES, true)) {
            $object->reading_mode = $metadata["reading_mode"];
        }

        // The site behind a shared host (a Suwayomi source): the novel's
        // download lane (App\Services\DownloadLanes).
        if (!empty($metadata["source_ref"])) {
            $object->source_ref = (string) $metadata["source_ref"];
        }

        $object->save();

        // Origin: getMetadata() already set it when NovelUpdates matched;
        // otherwise infer from the author/source (no network, never
        // overrides NovelUpdates). Not for a manga: origin describes a
        // translated / original web novel.
        if (!$object->hasPages() && $object->origin_source === null && ($origin = $object->inferOrigin())) {
            $this->info("  Origin: " . ($object->originLabel() ?? "unknown") . " ({$origin['reason']})");
        }

        if (!empty($metadata["genres"])) {
            $tagIds = collect($metadata["genres"])
                ->map(fn($g) => \App\Tag::firstOrCreate(["name" => $g])->id);
            $object->tags()->syncWithoutDetaching($tagIds);
            $this->info("  Genres: " . implode(", ", $metadata["genres"]));
        }

        // A manga's origin comes from its source (MangaDex's original
        // language, a Manhwa/Manhua/Manga genre, else a cached title lookup).
        if ($object->isManga() && ($origin = app(\App\Services\MangaOrigin::class)->fromMetadata($object, $metadata))) {
            $this->info("  Origin: " . ($object->originCountryLabel() ?? ($origin['origin_language'] ?? 'unknown')) . " ({$origin['reason']})");
        }

        foreach (array_unique($coverCandidates) as $imageUrl) {
            $downloaded = downloadCoverImage($imageUrl, $object->id);

            if ($downloaded) {
                $object->attachCover($downloaded["filename"], $downloaded["basename"]);
                $this->info("  Cover saved: {$downloaded['filename']}");
                break;
            }

            $this->warn("  Cover download failed from {$imageUrl}.");
        }

        $this->info("New Novel ID: {$object->id}");

        $this->queueFirstCheck($object);
    }

    /**
     * Fetch the chapter list and start downloading right away instead of
     * waiting for the nightly novel:toc. Only with a real queue: on the
     * sync connection the scrape would run inside this command.
     */
    private function queueFirstCheck(Novel $novel): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        try {
            app(\App\Services\NovelCheck::class)->start($novel);
            $this->info("  Queued: chapter list and first downloads.");
        } catch (\Throwable $e) {
            $this->warn("  Could not queue the first chapter check ({$e->getMessage()}); the nightly run will pick it up.");
        }
    }
}
