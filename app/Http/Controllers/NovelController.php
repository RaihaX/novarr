<?php

namespace App\Http\Controllers;

use App\Group;
use App\Language;
use App\Novel;
use App\NovelChapter;
use App\File;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

use DOMDocument;
use Goutte;
use DataTables;
use Madzipper;

use Carbon\Carbon;

class NovelController extends Controller
{
    /**
     * The novel repository instance.
     */
    protected $novels;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     * @return void
     */
    public function __construct(Novel $novels)
    {
        $this->novels = $novels;
    }

    public function datatables() {
        return DataTables::of($this->novels->with(['file' => function($q) {
            $q->orderBy('id', 'desc');
        }, 'group'])->orderBy('name')->get())->toJson();
    }

    public function get_metadata($data) {
        $metadata = array();

        $name = str_replace("!", "", str_replace("?", "", str_replace("'", "", str_replace(" ", "-", strtolower($data->name)))));
        $name = str_replace(",", "", $name);
        $name = str_replace("'", "", $name);

        $crawler = Goutte::request('GET', 'https://www.novelupdates.com/series/' . $name);
        $crawler->filter('#editdescription')->each(function ($node, $key) use (&$metadata) {
            $metadata["description"] = $node->html();
        });

        $crawler->filter('#authtag')->each(function ($node, $key) use (&$metadata) {
            if ( $key == 0 ) {
                $metadata["author"] = $node->text();
            }
        });

        $crawler->filter('#editstatus')->each(function ($node, $key) use (&$metadata) {
            $array = explode(" ", str_replace("Chapter ", "Chapters ", $node->text()));
            $key = array_search("Chapters", $array);

            $metadata["no_of_chapters"] = $key == 0 ? 0 : intval(trim($array[$key - 1]));
        });

        return $metadata;
    }

    public function update_all_metadata() {
        foreach ( $this->novels->where('status', 0)->get() as $item ) {
            $metadata = $this->get_metadata($item);

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

    public function update_metadata($id) {
        $data = $this->novels->find($id);

        $metadata = $this->get_metadata($data);

        if ( isset($metadata["description"]) && $metadata["description"] != "" ) {
            $data->description = $metadata["description"];
        }

        if ( isset($metadata["author"]) && $metadata["author"] != "" ) {
            $data->author = $metadata["author"];
        }

        if ( isset($metadata["no_of_chapters"]) && $metadata["no_of_chapters"] > 0 ) {
            $data->no_of_chapters = $metadata["no_of_chapters"];
        }

        $data->save();
    }

    public function get_novel($id) {
        $data = $this->novels->with(['file' => function($q) {
            $q->orderBy('id', 'desc');
        }, 'group', 'language'])->find($id);
        $count = NovelChapter::where('novel_id', $id)->where('status', 1)->where('blacklist', 0)->count();
        $not_downloaded_count = NovelChapter::where('novel_id', $id)->where('status', 0)->where('blacklist', 0)->count();
        $progress = $data->no_of_chapters == 0 ? 0 : ($count / $data->no_of_chapters) * 100;
        $new_chapters = NovelChapter::where('novel_id', $id)->where('blacklist', 0)->where('status', 0)->count();
        $duplicate_chapters = NovelChapter::where('novel_id', $id)->where('blacklist', 0)->groupBy('chapter', 'book')->havingRaw('count(id) > 1')->select('chapter', 'book')->get();

        $latestChapter = NovelChapter::where('blacklist', 0)->where('novel_id', $id)->max('chapter');

        $chapterArray = array();
        $existingChapterArray = array();

        for ( $i = 1; $i <= $latestChapter; $i++ ) {
            array_push($chapterArray, $i);
        }

        foreach ( NovelChapter::where('novel_id', $id)->where('blacklist', 0)->get(['chapter', 'double_chapter']) as $item ) {
            array_push($existingChapterArray, intval($item->chapter));

            if ( $item->double_chapter == 1 ) {
                array_push($existingChapterArray, intval($item->chapter) + 1);
            }
        }

        $missing_chapters = array_diff($chapterArray, $existingChapterArray);

        return response()->json([
            'data' => $data,
            'new_chapters' => $new_chapters,
            'duplicate_chapters' => $duplicate_chapters,
            'missing_chapters' => $missing_chapters,
            'current_chapters' => $count,
            'current_chapters_not_downloaded' => $not_downloaded_count,
            'progress' => round($progress)
        ]);
    }

    public function download_epub($id) {
        $object = $this->novels->find($id);

        $epub = storage_path('app/ePub/' . $object->name . ' - ' . $object->author . '.epub');

        return response()->download($epub);
    }

    /** only run on artisan and change the id manually */
    public function generate_all_epub() {
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

//                $zip = new \ZipArchive();
//                $zip->open($epub, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
//                $zip->addFile($file_content, 'content.opf')->addFile($file_toc, 'toc.ncx');

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

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('novels.index', [
            'groups' => Group::orderBy('label')->get(),
            'languages' => Language::orderBy('label')->get()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $object = $this->novels;

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('author') ) {
            $object->author = $request->author;
        }

        if ( $request->has('translator') ) {
            $object->translator = $request->translator;
        }

        if ( $request->has('translator_url') ) {
            $object->translator_url = $request->translator_url;
        }

        if ( $request->has('chapter_url') ) {
            $object->chapter_url = $request->chapter_url;
        }

        if ( $request->has('no_of_chapters') ) {
            $object->no_of_chapters = $request->no_of_chapters == "" ? 0 : $request->nof_of_chapters;
        }

        if ( $request->has('status') ) {
            $object->status = $request->status;
        }

        if ( $request->has('group_id') ) {
            $object->group_id = $request->group_id;
        }

        if ( $request->has('json') ) {
            $object->json = $request->json;
        }

        $object->unique_id = $request->has('unique_id') ? $request->unique_id : 0;

        if ( $request->has('language_id') ) {
            $object->language_id = $request->language_id;
        }

        if ( $request->has('alternative_url') ) {
            $object->alternative_url = $request->alternative_url;
        }

        $object->save();

        if ( $request->hasFile('image') ) {
            $file_object = new File([
                'file_name' => $request->file('image')->getClientOriginalName(),
                'file_path' => $request->file('image')->store('public')
            ]);

            $object->file()->save($file_object);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $data = $this->novels->with(['file' => function($q) {
            $q->orderBy('id', 'desc');
        }, 'group', 'language'])->find($id);
        $count = NovelChapter::where('novel_id', $id)->where('blacklist', 0)->where('status', 1)->count();
        $not_downloaded_count = NovelChapter::where('novel_id', $id)->where('blacklist', 0)->where('status', 0)->count();
        $progress = $data->no_of_chapters == 0 ? 0 : ($count / $data->no_of_chapters) * 100;
        $new_chapters = NovelChapter::where('novel_id', $id)->where('blacklist', 0)->where('status', 0)->count();
        $duplicate_chapters = NovelChapter::where('novel_id', $id)->where('blacklist', 0)->groupBy('chapter', 'book')->havingRaw('count(id) > 1')->select('chapter', 'book')->get();

        $latestChapter = NovelChapter::where('blacklist', 0)->where('novel_id', $id)->max('chapter');

        $chapterArray = array();
        $existingChapterArray = array();

        for ( $i = 1; $i <= $latestChapter; $i++ ) {
            array_push($chapterArray, $i);
        }

        foreach ( NovelChapter::where('novel_id', $id)->where('blacklist', 0)->get(['chapter', 'double_chapter']) as $item ) {
            array_push($existingChapterArray, intval($item->chapter));

            if ( $item->double_chapter == 1 ) {
                array_push($existingChapterArray, intval($item->chapter) + 1);
            }
        }

        $missing_chapters = array_diff($chapterArray, $existingChapterArray);

        return view('novels.show', [
            'data' => $data,
            'new_chapters' => $new_chapters,
            'duplicate_chapters' => $duplicate_chapters,
            'missing_chapters' => $missing_chapters,
            'current_chapters' => $count,
            'current_chapters_not_downloaded' => $not_downloaded_count,
            'progress' => round($progress),
            'groups' => Group::orderBy('label')->get(),
            'languages' => Language::orderBy('label')->get()
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $object = $this->novels->find($id);

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('author') ) {
            $object->author = $request->author;
        }

        if ( $request->has('translator') ) {
            $object->translator = $request->translator;
        }

        if ( $request->has('translator_url') ) {
            $object->translator_url = $request->translator_url;
        }

        if ( $request->has('chapter_url') ) {
            $object->chapter_url = $request->chapter_url;
        }

        if ( $request->has('no_of_chapters') ) {
            $object->no_of_chapters = $request->no_of_chapters;
        }

        if ( $request->has('status') ) {
            $object->status = $request->status;
        }

        if ( $request->has('group_id') ) {
            $object->group_id = $request->group_id;
        }

        if ( $request->has('json') ) {
            $object->json = $request->json;
        }

        if ( $request->has('unique_id') ) {
            $object->unique_id = $request->unique_id;
        }

        if ( $request->has('language_id') ) {
            $object->language_id = $request->language_id;
        }

        if ( $request->has('alternative_url') ) {
            $object->alternative_url = $request->alternative_url;
        }

        $object->save();

        if ( $request->hasFile('image') ) {
            $file_object = new File([
                'file_name' => $request->file('image')->getClientOriginalName(),
                'file_path' => $request->file('image')->store('public')
            ]);

            $object->file()->save($file_object);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $object = $this->novels->find($id);
        $object->delete();
    }

    public function calculate_chapters() {
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
