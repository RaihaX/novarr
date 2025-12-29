<?php

namespace App\Http\Controllers;

use App\MangaChapter;
use App\File;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

use DOMDocument;
use DataTables;
use Madzipper;

use Carbon\Carbon;

class MangaChapterController extends Controller
{
    /**
     * The novel repository instance.
     */
    protected $mangaChapters;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     * @return void
     */
    public function __construct(MangaChapter $mangaChapters)
    {
        $this->mangaChapters = $mangaChapters;
    }

    public function datatables() {
        return DataTables::of($this->mangaChapters->orderBy('name')->get())->toJson();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('mangaChapters.index', []);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $object = $this->mangaChapters;

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('chapter') ) {
            $object->chapter = $request->chapter;
        }

        if ( $request->has('manga_id') ) {
            $object->manga_id = $request->manga_id;
        }

        $object->save();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Manga  $novel
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $data = $this->mangaChapters->find($id);

        return view('mangaChapters.show', [
            'data' => $data
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Manga  $novel
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $object = $this->mangaChapters->find($id);

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('chapter') ) {
            $object->chapter = $request->chapter;
        }

        if ( $request->has('manga_id') ) {
            $object->manga_id = $request->manga_id;
        }

        $object->save();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Manga  $novel
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $object = $this->mangaChapters->find($id);
        $object->delete();
    }
}
