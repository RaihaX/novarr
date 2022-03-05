<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Novel;
use App\NovelChapter;

class ChapterCleaner extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'novel:chaptercleaner {novel=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape all active novels to create the chapter list.';

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
        $args = $this->arguments('novel');

        if ( $args["novel"] == 0 ) {
            
        } else {
            foreach ( NovelChapter::where('novel_id', $args["novel"])->get() as $c ) {
                $string = $c->description;
                $string = str_replace("</p>", "", $string);
                $array = explode("<p>", $string);

                if ( count($array) <= 10 ) {
                    $c->description = "";
                    $c->status = 0;
                    $c->save();                    

                    echo "Chapter " . $c->chapter . " - " . count($array) . "<br/>\r\n"; 
                }
            }
        }

    }
}
