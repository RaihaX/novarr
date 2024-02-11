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
    protected $signature = "novel:metadata";

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
        foreach (Novel::with("file")->get() as $item) {
            $this->info($item->name);

            $metadata = getMetadata($item);

            $novel = Novel::find($item->id);

            if (
                isset($metadata["description"]) &&
                $metadata["description"] != ""
            ) {
                $novel->description = $metadata["description"];
            }

            if (isset($metadata["author"]) && $metadata["author"] != "") {
                $novel->author = $metadata["author"];
            }

            if (
                isset($metadata["no_of_chapters"]) &&
                $metadata["no_of_chapters"] > 0
            ) {
                $novel->no_of_chapters = $metadata["no_of_chapters"];
            }

            $novel->save();

            if (!isset($item->file->id)) {
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
                        $filename =
                            md5($novel->id . date("now")) . "." . $basename[1];

                        $path = "public/" . $filename;
                        file_put_contents(
                            storage_path("app/public/") . $filename,
                            $image
                        );

                        $file_object = new File([
                            "file_name" => basename($metadata["image"]),
                            "file_path" => $path,
                        ]);

                        $novel->file()->save($file_object);
                    }
                }
            }
        }
    }
}
