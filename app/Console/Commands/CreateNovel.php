<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\File;
use App\Novel;

class CreateNovel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "novel:create {name} {url}";

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

        // Check for duplicate novel by name
        $existingNovel = Novel::where('name', $name)->first();

        if ($existingNovel) {
            $this->warn("A novel with the name '{$name}' already exists (ID: {$existingNovel->id}).");

            if (!$this->confirm('Do you want to create another novel with the same name?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        // Check for duplicate novel by URL
        $existingByUrl = Novel::where('translator_url', $url)->first();

        if ($existingByUrl) {
            $this->warn("A novel with the same URL already exists: '{$existingByUrl->name}' (ID: {$existingByUrl->id}).");

            if (!$this->confirm('Do you want to create another novel with the same URL?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $object = new Novel();
        $object->name = $name;
        $object->translator_url = $url;
        $object->group_id = 1;
        $object->save();

        $metadata = getMetadata($object);

        $needsFallback = empty($metadata["image"])
            || empty($metadata["description"])
            || empty($metadata["author"])
            || empty($metadata["no_of_chapters"]);

        if ($needsFallback) {
            $this->info("  Fetching fallback metadata from novelbin...");
            $fallback = getMetadataFromNovelBin($object);
            foreach (["description", "author", "no_of_chapters", "image"] as $key) {
                if (empty($metadata[$key]) && !empty($fallback[$key])) {
                    $metadata[$key] = $fallback[$key];
                }
            }
        }

        if (!empty($metadata["description"])) {
            $object->description = $metadata["description"];
        }

        if (!empty($metadata["author"])) {
            $object->author = $metadata["author"];
        }

        if (!empty($metadata["no_of_chapters"])) {
            $object->no_of_chapters = $metadata["no_of_chapters"];
        }

        $object->save();

        if (!empty($metadata["image"])) {
            $downloaded = downloadCoverImage($metadata["image"], $object->id);

            if ($downloaded) {
                $file_object = new File([
                    "file_name" => $downloaded["basename"],
                    "file_path" => "public/" . $downloaded["filename"],
                ]);
                $object->file()->save($file_object);
                $this->info("  Cover saved: {$downloaded['filename']}");
            } else {
                $this->warn("  Cover image download failed.");
            }
        }

        $this->info("New Novel ID: {$object->id}");
    }
}
