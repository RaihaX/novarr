<?php

namespace App\Http\Controllers;

use App\Manga;
use App\File;
use App\Http\Helpers\CacheHelper;

use App\NovelChapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use DOMDocument;
use DataTables;
use Madzipper;

use Carbon\Carbon;

class MangaController extends Controller
{
    /**
     * The novel repository instance.
     */
    protected $mangas;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     * @return void
     */
    public function __construct(Manga $mangas)
    {
        $this->mangas = $mangas;
    }

    public function datatables() {
        // Cache DataTables response for 2 minutes
        return Cache::remember('datatables_mangas', now()->addMinutes(2), function () {
            $query = Manga::query()
                ->with(['file' => function($q) {
                    $q->orderBy('id', 'desc');
                }])
                ->orderBy('name');

            return DataTables::eloquent($query)->toJson();
        });
    }

    public function get_manga($id) {
        $data = $this->mangas->with(['file' => function($q) {
            $q->orderBy('id', 'desc');
        }])->find($id);

        // Use stable cache key so CacheHelper::clearMangaCache() can invalidate it
        $cacheKey = "manga_{$id}";

        $cachedData = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($data) {
            return [
                'data' => $data
            ];
        });

        return response()->json($cachedData);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('mangas.index', []);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $object = $this->mangas;

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('author') ) {
            $object->author = $request->author;
        }

        if ( $request->has('url') ) {
            $object->url = $request->url;
        }

        $object->save();

        // Clear DataTables cache for mangas
        CacheHelper::clearMangaDataTablesCache();

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
     * @param  \App\Manga  $novel
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $data = $this->mangas->with(['file' => function($q) {
            $q->orderBy('id', 'desc');
        }])->find($id);

        return view('mangas.show', [
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
        $object = $this->mangas->find($id);

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('author') ) {
            $object->author = $request->author;
        }

        if ( $request->has('url') ) {
            $object->url = $request->url;
        }

        $object->save();

        // Clear cache for this manga and DataTables
        CacheHelper::clearMangaCache($id);
        CacheHelper::clearMangaDataTablesCache();

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
     * @param  \App\Manga  $novel
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $object = $this->mangas->find($id);
        $object->delete();

        // Clear caches after deletion
        CacheHelper::clearMangaCache($id);
        CacheHelper::clearMangaDataTablesCache();
    }
}
