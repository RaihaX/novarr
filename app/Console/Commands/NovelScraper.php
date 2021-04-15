<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Novel;
use App\NovelChapter;

class NovelScraper extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'novel:novel_scraper {novel=0}';

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
            foreach ( Novel::where('status', 0)->where('group_id', '!=', 37)->orderBy('name', 'asc')->get() as $n ) {
                echo $n->name . "\r\n";
                $toc = __tableOfContentGenerator($n);

                if ( $n->group_id != 6 ) {
                    foreach ($toc as $item) {
                        $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->where('book', intval($item["book"]))->select('id')->first();

                        if (empty($check_duplicate)) {
                            if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                                $object = new NovelChapter();
                                $object->novel_id = $n->id;
                                $object->label = $item["label"];
                                $object->url = $item["url"];
                                $object->chapter = $item["chapter"];
                                $object->book = intval($item["book"]);
                                $object->save();
                            }
                        } else {
                            $check_duplicate->label = $item["label"];
                            $check_duplicate->chapter = $item["chapter"];
                            $check_duplicate->book = intval($item["book"]);
                            $check_duplicate->url = $item["url"];
                            $check_duplicate->save();
                        }
                    }
                } else {
                    foreach ($toc as $item) {
                        $urlArr = explode("/", str_replace("https://www.webnovel.com/book/", "", $item["url"]));

                        $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->select('id')->first();

                        if (empty($check_duplicate)) {
                            if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                                $object = new NovelChapter();
                                $object->novel_id = $n->id;
                                $object->label = $item["label"];
                                $object->url = $item["url"];
                                $object->chapter = $item["chapter"];
                                $object->book = intval($item["book"]);
                                $object->unique_id = $urlArr[1];
                                $object->save();
                            }
                        } else {
                            $check_duplicate->label = $item["label"];
                            $check_duplicate->chapter = $item["chapter"];
                            $check_duplicate->book = intval($item["book"]);
                            $check_duplicate->url = $item["url"];
                            $check_duplicate->unique_id = $urlArr[1];
                            $check_duplicate->save();
                        }
                    }
                }
            }
        } else {
            foreach ( Novel::where('status', 0)->where('id', $args["novel"])->where('group_id', '!=', 37)->orderBy('name', 'asc')->get() as $n ) {
                echo $n->name . "\r\n";
                $toc = __tableOfContentGenerator($n);

                if ( $n->group_id != 6 ) {
                    foreach ($toc as $item) {
                        $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->where('book', intval($item["book"]))->select('id')->first();

                        if (empty($check_duplicate)) {
                            if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                                $object = new NovelChapter();
                                $object->novel_id = $n->id;
                                $object->label = $item["label"];
                                $object->url = $item["url"];
                                $object->chapter = $item["chapter"];
                                $object->book = intval($item["book"]);
                                $object->save();
                            }
                        } else {
                            $check_duplicate->label = $item["label"];
                            $check_duplicate->chapter = $item["chapter"];
                            $check_duplicate->book = intval($item["book"]);
                            $check_duplicate->url = $item["url"];
                            $check_duplicate->save();
                        }
                    }
                } else {
                    foreach ($toc as $item) {
                        $urlArr = explode("/", str_replace("https://www.webnovel.com/book/", "", $item["url"]));

                        $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->select('id')->first();

                        if (empty($check_duplicate)) {
                            if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                                $object = new NovelChapter();
                                $object->novel_id = $n->id;
                                $object->label = $item["label"];
                                $object->url = $item["url"];
                                $object->chapter = $item["chapter"];
                                $object->book = intval($item["book"]);
                                $object->unique_id = $urlArr[1];
                                $object->save();
                            }
                        } else {
                            $check_duplicate->label = $item["label"];
                            $check_duplicate->chapter = $item["chapter"];
                            $check_duplicate->book = intval($item["book"]);
                            $check_duplicate->url = $item["url"];
                            $check_duplicate->unique_id = $urlArr[1];
                            $check_duplicate->save();
                        }
                    }
                }
            }
        }

    }
}
