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

            $metadata = getMetadata($item);

            $needsFallback = empty($metadata["image"])
                || empty($metadata["description"])
                || empty($metadata["author"])
                || empty($metadata["no_of_chapters"]);

            if ($needsFallback) {
                $fallback = getMetadataFromNovelBin($item);
                foreach (["description", "author", "no_of_chapters", "image"] as $key) {
                    if (empty($metadata[$key]) && !empty($fallback[$key])) {
                        $metadata[$key] = $fallback[$key];
                    }
                }
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

            if (!$hasValidCover && !empty($metadata["image"])) {
                $downloaded = downloadCoverImage($metadata["image"], $novel->id);

                if ($downloaded) {
                    $file_object = new File([
                        "file_name" => $downloaded["basename"],
                        "file_path" => "public/" . $downloaded["filename"],
                    ]);
                    $novel->file()->save($file_object);
                    $this->info("  Cover saved: {$downloaded['filename']}");
                } else {
                    $this->warn("  Cover image download failed.");
                }
            }
        }
    }
}
