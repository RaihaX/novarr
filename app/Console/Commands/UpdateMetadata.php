<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\File;
use App\Novel;

class UpdateMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "novel:metadata {novel?}";

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

        $query = Novel::with("file");
        if ($novelId) {
            $query->where("id", $novelId);
        }

        foreach ($query->get() as $item) {
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

            $isEmpire = stripos($item->translator_url ?? '', 'empirenovel.com') !== false;

            if ($isEmpire) {
                // Empire Novel covers are Cloudflare-blocked — take the cover
                // and richer description/genres from NovelUpdates, but keep
                // Empire Novel's chapter count.
                $this->line("  Empire Novel: {$item->translator_url} (cover via NovelUpdates)");
                $en = getMetadataFromEmpireNovel($item->translator_url);
                $metadata = getMetadata($item);
                if (!empty($en["no_of_chapters"])) {
                    $metadata["no_of_chapters"] = $en["no_of_chapters"];
                }
                foreach (["description", "author", "genres"] as $key) {
                    if (empty($metadata[$key]) && !empty($en[$key])) {
                        $metadata[$key] = $en[$key];
                    }
                }
                $this->reportFetch($metadata);
                $coverCandidates = array_filter([$metadata["image"] ?? null, $en["image"] ?? null]);
            } else {
                $this->line("  NovelUpdates: https://www.novelupdates.com/series/" . novelSlug($item->name) . "/");
                $metadata = getMetadata($item);
                $this->reportFetch($metadata);
                $coverCandidates = array_filter([$metadata["image"] ?? null]);
            }

            $needsFallback = empty($metadata["image"])
                || empty($metadata["description"])
                || empty($metadata["author"])
                || empty($metadata["no_of_chapters"]);

            if ($needsFallback && !$isEmpire) {
                $fallback = getMetadataFromNovelBin($item);
                $this->line("  Falling back to NovelBin: " . implode(", ", $fallback["tried_urls"] ?? []));

                if (!empty($fallback["image"])) {
                    $coverCandidates[] = $fallback["image"];
                }

                foreach (["description", "author", "no_of_chapters", "image", "genres"] as $key) {
                    if (empty($metadata[$key]) && !empty($fallback[$key])) {
                        $metadata[$key] = $fallback[$key];
                    }
                }
                $this->reportFetch($metadata);
            }

            if (empty($metadata["description"])) {
                $this->warn("  ✗ No description found on either source — check the URLs above in a browser.");
            }

            $novel = Novel::find($item->id);

            if (!empty($metadata["description"])) {
                $novel->description = $metadata["description"];
            }

            if (!empty($metadata["author"])) {
                $novel->author = $metadata["author"];
            }

            if (!empty($metadata["no_of_chapters"])) {
                $novel->no_of_chapters = $metadata["no_of_chapters"];
            }

            $novel->save();

            if (!empty($metadata["genres"])) {
                $tagIds = collect($metadata["genres"])
                    ->map(fn($g) => \App\Tag::firstOrCreate(["name" => $g])->id);
                $novel->tags()->syncWithoutDetaching($tagIds);
                $this->line("    genres: " . implode(", ", $metadata["genres"]));
            }

            if (!$hasValidCover && !empty($coverCandidates)) {
                $saved = false;

                foreach (array_unique($coverCandidates) as $imageUrl) {
                    $downloaded = downloadCoverImage($imageUrl, $novel->id);

                    if ($downloaded) {
                        $file_object = new File([
                            "file_name" => $downloaded["basename"],
                            "file_path" => "public/" . $downloaded["filename"],
                        ]);
                        $novel->file()->save($file_object);
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
