<?php

namespace App\Console\Commands;

use App\Mail\NewChapters;
use Illuminate\Console\Command;

use App\Novel;

class ChapterScraper extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'novel:chapter_scraper';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape new chapters for each novels.';

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
        $newChapters = array();

        Novel::where('status', 0)->where('group_id', '!=', 37)->whereHas('chapters', function($q) {
            $q->where('status', 0)->where('blacklist', 0);
        })->with(['chapters' => function($q) {
            $q->where('status', 0)->where('blacklist', 0)->orderBy('book')->orderBy('chapter');
        }])->orderBy('name', 'desc')->chunk(5, function ($novels) use (&$newChapters) {
            foreach ( $novels as $novel ) {
                if ( count($novel->chapters) > 0 ) {
                    foreach ( $novel->chapters as $item ) {
                        $chapter = $this->chapter_generator($item);

                        $description = "";
                        foreach ( $chapter as $c ) {
                            $description .= $c;
                        }

                        if ( str_word_count($description) > 250 ) {
                            $progress = $novel->no_of_chapters == 0 ? 0 : round(($item->chapter / $novel->no_of_chapters * 100), 2);

                            array_push($newChapters, array(
                                'novel' => $novel->name,
                                'label' => $item->label,
                                'chapter' => $item->chapter,
                                'book' => $item->book,
                                'progress' => number_format($progress, 2, ".", ",")
                            ));

//                            echo $novel->name . " - " . $item->chapter . "\r\n";

                            $item->description = $description;

                            if ( trim($description) != "" ) {
                                $item->status = 1;
                            }
                            $item->download_date = Carbon::now();
                            $item->save();
                        } else {
//                            echo $item->novel->name . " - " . $item->chapter . " (Incomplete)\r\n";
                        }
                    }
                }
            }
        });

        if ( count($newChapters) > 0 ) {
            Mail::to("reyhan.thee@icloud.com")->send(new NewChapters($newChapters));
        }
    }
}
