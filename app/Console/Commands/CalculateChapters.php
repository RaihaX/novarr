<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\NovelChapter;

class CalculateChapters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'novel:calculate_chapter';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate chapters number for each novel.';

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
        foreach ( $this->novels->get() as $item ) {
            $current_chapters = NovelChapter::where('novel_id', $item->id)->where('status', 1)->where('blacklist', 0)->count();
            $chapters_not_downloaded = NovelChapter::where('novel_id', $item->id)->where('status', 0)->where('blacklist', 0)->count();
            $duplicate_chapters = NovelChapter::where('novel_id', $item->id)->where('blacklist', 0)->groupBy('chapter', 'book')->havingRaw('count(id) > 1')->select('chapter', 'book')->count();

            $latestChapter = NovelChapter::where('novel_id', $item->id)->where('blacklist', 0)->max('chapter');

            $chapterArray = array();
            $existingChapterArray = array();
            $missingChapters = array();

            for ( $i = 1; $i <= $latestChapter; $i++ ) {
                array_push($chapterArray, $i);
            }

            foreach ( NovelChapter::where('novel_id', $item->id)->where('blacklist', 0)->get(['chapter', 'double_chapter']) as $i ) {
                array_push($existingChapterArray, intval($i->chapter));

                if ( $i->double_chapter == 1 ) {
                    array_push($existingChapterArray, intval($i->chapter) + 1);
                }
            }

            $missingChapters = array_diff($chapterArray, $existingChapterArray);

            $item->current_chapters = $current_chapters;
            $item->chapters_not_downloaded = $chapters_not_downloaded;
            $item->duplicate_chapters = $duplicate_chapters;
            $item->progress = $item->no_of_chapters == 0 ? 0 : ($current_chapters / $item->no_of_chapters) * 100;
            $item->missing_chapters = count($missingChapters);
            $item->save();
        }
    }
}
