<?php

namespace App\Http\Controllers;

use App\Group;
use App\Language;
use App\Novel;
use App\NovelChapter;
use App\File;
use App\Http\Helpers\CacheHelper;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use DOMDocument;
use DataTables;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
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

        try {
            $httpClient = createHttpClient();
            $response = $httpClient->request('GET', 'https://www.novelupdates.com/?s=' . $request->name . '&post_type=seriesplans');
            $crawler = new Crawler($response->getContent());

            $crawler->filter('.search_title > a')->each(function ($node, $key) use (&$data) {
                array_push($data, array(
                    'name' => $node->text(),
                    'url' => $node->attr('href')
                ));
            });
        } catch (\Exception $e) {
            \Log::error("search_novels error: " . $e->getMessage());
        }

        return response()->json($data);
    }

    public function datatables() {
        // Cache DataTables response for 2 minutes
        return Cache::remember('datatables_novels', now()->addMinutes(2), function () {
            $query = Novel::query()
                ->with(['file' => function($q) {
                    $q->orderBy('id', 'desc');
                }, 'group'])
                ->orderBy('name');

            return DataTables::eloquent($query)->toJson();
        });
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

        // Use stable cache key so CacheHelper::clearNovelCache() can invalidate it
        $cacheKey = "novel_stats_{$id}";

        $stats = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($id) {
            // Consolidate multiple queries into single query with aggregations
            $aggregates = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->selectRaw('
                    COUNT(CASE WHEN status = 1 THEN 1 END) as downloaded_count,
                    COUNT(CASE WHEN status = 0 THEN 1 END) as not_downloaded_count,
                    MAX(chapter) as latest_chapter
                ')
                ->first();

            $count = $aggregates->downloaded_count ?? 0;
            $not_downloaded_count = $aggregates->not_downloaded_count ?? 0;
            $latestChapter = $aggregates->latest_chapter ?? 0;

            // Get duplicate chapters
            $duplicate_chapters = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->groupBy('chapter', 'book')
                ->havingRaw('count(id) > 1')
                ->select('chapter', 'book')
                ->get();

            // Get existing chapters in single query
            $existingChapters = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->select('chapter', 'double_chapter')
                ->get();

            $chapterArray = [];
            $existingChapterArray = [];

            for ($i = 1; $i <= $latestChapter; $i++) {
                $chapterArray[] = $i;
            }

            foreach ($existingChapters as $item) {
                $existingChapterArray[] = intval($item->chapter);

                if ($item->double_chapter == 1) {
                    $existingChapterArray[] = intval($item->chapter) + 1;
                }
            }

            $missing_chapters = array_values(array_diff($chapterArray, $existingChapterArray));

            return [
                'count' => $count,
                'not_downloaded_count' => $not_downloaded_count,
                'new_chapters' => $not_downloaded_count,
                'duplicate_chapters' => $duplicate_chapters,
                'missing_chapters' => $missing_chapters,
            ];
        });

        $progress = $data->no_of_chapters == 0 ? 0 : ($stats['count'] / $data->no_of_chapters) * 100;

        return response()->json([
            'data' => $data,
            'new_chapters' => $stats['new_chapters'],
            'duplicate_chapters' => $stats['duplicate_chapters'],
            'missing_chapters' => $stats['missing_chapters'],
            'current_chapters' => $stats['count'],
            'current_chapters_not_downloaded' => $stats['not_downloaded_count'],
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
            'novels' => Novel::withRelations()->ordered()->get(),
            'groups' => CacheHelper::getCachedGroups(),
            'languages' => CacheHelper::getCachedLanguages()
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

        // Clear DataTables cache for novels
        CacheHelper::clearNovelDataTablesCache();

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

        // Use stable cache key so CacheHelper::clearNovelCache() can invalidate it
        $cacheKey = "novel_stats_{$id}";

        $stats = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($id) {
            // Consolidate multiple queries into single query with aggregations
            $aggregates = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->selectRaw('
                    COUNT(CASE WHEN status = 1 THEN 1 END) as downloaded_count,
                    COUNT(CASE WHEN status = 0 THEN 1 END) as not_downloaded_count,
                    MAX(chapter) as latest_chapter
                ')
                ->first();

            $count = $aggregates->downloaded_count ?? 0;
            $not_downloaded_count = $aggregates->not_downloaded_count ?? 0;
            $latestChapter = $aggregates->latest_chapter ?? 0;

            // Get duplicate chapters
            $duplicate_chapters = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->groupBy('chapter', 'book')
                ->havingRaw('count(id) > 1')
                ->select('chapter', 'book')
                ->get();

            // Get existing chapters in single query
            $existingChapters = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->select('chapter', 'double_chapter')
                ->get();

            $chapterArray = [];
            $existingChapterArray = [];

            for ($i = 1; $i <= $latestChapter; $i++) {
                $chapterArray[] = $i;
            }

            foreach ($existingChapters as $item) {
                $existingChapterArray[] = intval($item->chapter);

                if ($item->double_chapter == 1) {
                    $existingChapterArray[] = intval($item->chapter) + 1;
                }
            }

            $missing_chapters = array_values(array_diff($chapterArray, $existingChapterArray));

            return [
                'count' => $count,
                'not_downloaded_count' => $not_downloaded_count,
                'new_chapters' => $not_downloaded_count,
                'duplicate_chapters' => $duplicate_chapters,
                'missing_chapters' => $missing_chapters,
            ];
        });

        $progress = $data->no_of_chapters == 0 ? 0 : ($stats['count'] / $data->no_of_chapters) * 100;

        return view('novels.show', [
            'data' => $data,
            'title' => 'Novels',
            'new_chapters' => $stats['new_chapters'],
            'duplicate_chapters' => $stats['duplicate_chapters'],
            'missing_chapters' => $stats['missing_chapters'],
            'current_chapters' => $stats['count'],
            'current_chapters_not_downloaded' => $stats['not_downloaded_count'],
            'progress' => round($progress),
            'groups' => CacheHelper::getCachedGroups(),
            'languages' => CacheHelper::getCachedLanguages()
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

        // Clear cache for this novel and DataTables
        CacheHelper::clearNovelCache($id);
        CacheHelper::clearNovelDataTablesCache();

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

        // Clear caches after deletion
        CacheHelper::clearNovelCache($id);
        CacheHelper::clearNovelDataTablesCache();
    }
}
