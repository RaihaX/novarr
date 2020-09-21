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

    public function search_novels(Request $request) {
        $data = array();

        $crawler = Goutte::request('GET', 'https://www.novelupdates.com/?s=' . $request->name . '&post_type=seriesplans');
        $crawler->filter('.search_title > a')->each(function ($node, $key) use (&$data) {
            array_push($data, array(
                'name' => $node->text(),
                'url' => $node->attr('href')
            ));
        });

        return response()->json($data);
    }

    public function datatables() {
        return DataTables::of($this->novels->with(['file' => function($q) {
            $q->orderBy('id', 'desc');
        }, 'group'])->orderBy('name')->get())->toJson();
    }

    public function update_metadata($id) {
        $data = $this->novels->find($id);

        $metadata = __getMetadata($data);

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

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('novels.index', [
            'novels' => Novel::orderBy('name')->get(),
            'groups' => Group::orderBy('label')->get(),
            'languages' => Language::orderBy('label')->get()
        ]);
    }

    public function create()
    {
        return view('novels.create', [

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
            'title' => 'Novels',
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
}
