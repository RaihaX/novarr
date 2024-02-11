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
        $object = new Novel();
        $object->name = $this->argument("name");
        $object->translator_url = $this->argument("url");
        $object->group_id = 1;
        $object->save();

        $metadata = getMetadata($object);

        if (isset($metadata["description"]) && $metadata["description"] != "") {
            $object->description = $metadata["description"];
        }

        if (isset($metadata["author"]) && $metadata["author"] != "") {
            $object->author = $metadata["author"];
        }

        if (
            isset($metadata["no_of_chapters"]) &&
            $metadata["no_of_chapters"] > 0
        ) {
            $object->no_of_chapters = $metadata["no_of_chapters"];
        }

        $object->save();

        if (isset($metadata["image"])) {
            $file = new File();
            $url_headers = @get_headers($metadata["image"]);

            if (
                !$url_headers ||
                $url_headers[0] == "HTTP/1.1 200 OK" ||
                $url_headers[0] == "HTTP/1.0 200 OK"
            ) {
                $image = file_get_contents($metadata["image"]);
                $basename = basename($metadata["image"]);
                $basename = explode(".", $basename);
                $filename = md5($object->id . date("now")) . "." . $basename[1];

                $path = "public/" . $filename;
                file_put_contents(
                    storage_path("app/public/") . $filename,
                    $image
                );

                $file_object = new File([
                    "file_name" => basename($metadata["image"]),
                    "file_path" => $path,
                ]);

                $object->file()->save($file_object);
            }
        }

        $this->info("New Novel ID: {$object->id}");
    }
}
