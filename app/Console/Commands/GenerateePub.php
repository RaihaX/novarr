<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Novel;

use Carbon\Carbon;
use Storage;

class GenerateePub extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'novel:generate_epub {novel=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate ePub for all the completed novels.';

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
            Novel::where('status', 1)->whereNull('epub_generated')->whereHas('chapters')->with(['chapters' => function($q) {
                $q->where('blacklist', 0)->where('status', 1)->orderBy('book')->orderBy('chapter');
            }])->chunk(5, function ($novels) {
                foreach ( $novels as $object ) {
                    $id = $object->id;

                    // Generate HTML
                    $nav_path = '/Novel/' . $id . '/Text/nav.xhtml';
                    $nav = __generateHTMLNav($object->chapters);

                    Storage::put($nav_path, $nav);

                    $toc_path = '/Novel/' . $id . '/toc.ncx';
                    $toc = __generateHTMLToc($object);

                    Storage::put($toc_path, $toc);

                    $content_path = '/Novel/' . $id . '/content.opf';
                    $content = __generateHTMLContent($object);

                    Storage::put($content_path, $content);

                    foreach ( $object->chapters as $c ) {
                        $content = "<h1>" . $c->label . "</h1>" . $c->description;
                        $chapter = __generateHTMLChapter($content);
                        $path = '/Novel/' . $id . '/Text/' . $c->id . '_chapter.xhtml';

                        if ( Storage::put($path, $chapter) ) {
                            $c->html_file = $path;
                            $c->save();
                        }
                    }

                    // Generate ePub
                    $file_chapters = glob(storage_path('app/Novel/' . $id . '/Text/*'));
                    $file_content = storage_path('app/Novel/' . $id . '/content.opf');
                    $file_toc = storage_path('app/Novel/' . $id . '/toc.ncx');

                    $epub = storage_path('app/ePub/' . $object->name . ' - ' . $object->author . '.epub');

                    $zipper = new \Madnest\Madzipper\Madzipper;
                    $zipper->make($epub)->add($file_content)->add($file_toc);

                    foreach ( $file_chapters as $file ) {
                        $zipper->folder('Text')->add($file);
                    }

                    $object->epub_generated = Carbon::now();
                    $object->save();

                    $zipper->close();
                }
            });
        } else {
            Novel::where('id', $args["novel"])->whereHas('chapters')->with(['chapters' => function($q) {
                $q->where('blacklist', 0)->where('status', 1)->orderBy('book')->orderBy('chapter');
            }])->chunk(5, function ($novels) {
                foreach ( $novels as $object ) {
                    if (file_exists(storage_path('app/ePub/' . $object->name . ' - ' . $object->author . '.epub'))) {
                        unlink(storage_path('app/ePub/' . $object->name . ' - ' . $object->author . '.epub'));
                    }

                    if (file_exists(storage_path(storage_path('app/Novel/' . $object->id)))) {
                        rmdir(storage_path('app/Novel/' . $object->id));
                    }

                    $id = $object->id;

                    // Generate HTML
                    $nav_path = '/Novel/' . $id . '/Text/nav.xhtml';
                    $nav = __generateHTMLNav($object->chapters);

                    Storage::put($nav_path, $nav);

                    $toc_path = '/Novel/' . $id . '/toc.ncx';
                    $toc = __generateHTMLToc($object);

                    Storage::put($toc_path, $toc);

                    $content_path = '/Novel/' . $id . '/content.opf';
                    $content = __generateHTMLContent($object);

                    Storage::put($content_path, $content);

                    foreach ( $object->chapters as $c ) {
                        $content = "<h1>" . $c->label . "</h1>" . $c->description;
                        $chapter = __generateHTMLChapter($content);
                        $path = '/Novel/' . $id . '/Text/' . $c->id . '_chapter.xhtml';

                        if ( Storage::put($path, $chapter) ) {
                            $c->html_file = $path;
                            $c->save();
                        }
                    }

                    // Generate ePub
                    $file_chapters = glob(storage_path('app/Novel/' . $id . '/Text/*'));
                    $file_content = storage_path('app/Novel/' . $id . '/content.opf');
                    $file_toc = storage_path('app/Novel/' . $id . '/toc.ncx');

                    $epub = storage_path('app/ePub/' . $object->name . ' - ' . $object->author . '.epub');

                    $zipper = new \Madnest\Madzipper\Madzipper;
                    $zipper->make($epub)->add($file_content)->add($file_toc);

                    foreach ( $file_chapters as $file ) {
                        $zipper->folder('Text')->add($file);
                    }

                    $object->epub_generated = Carbon::now();
                    $object->save();

                    $zipper->close();
                }
            });
        }
    }
}
