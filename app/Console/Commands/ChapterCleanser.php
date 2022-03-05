<?php

namespace App\Console\Commands;


use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

use App\NovelChapter;

use Carbon\Carbon;

class ChapterCleanser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'novel:chapter_cleanser {novel=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanse chapter of unwanted tags and characters.';

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
        echo var_dump($args["novel"]);

        if ( $args["novel"] == 0 ) {

        } else {
            foreach ( NovelChapter::where('novel_id', $args["novel"])->limit(1)->get() as $item ) {
                $item->description = str_replace("<p>&nbsp;</p>", "", $item->description);
                $item->save();
            }          
        }
    }
}
