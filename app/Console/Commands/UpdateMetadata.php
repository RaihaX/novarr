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
    protected $signature = 'novel:update_metadata';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Metadata of novels';

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
        foreach ( Novel::where('status', 0)->get() as $item ) {
            $metadata = __getMetadata($item);

            if ( isset($metadata["description"]) && $metadata["description"] != "" ) {
                $item->description = $metadata["description"];
            }

            if ( isset($metadata["author"]) && $metadata["author"] != "" ) {
                $item->author = $metadata["author"];
            }

            if ( isset($metadata["no_of_chapters"]) && $metadata["no_of_chapters"] > 0 ) {
                $item->no_of_chapters = $metadata["no_of_chapters"];
            }

            $item->save();
        }
    }
}
