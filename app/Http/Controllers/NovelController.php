<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use App\File;
use App\Http\Helpers\CacheHelper;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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

    public function update_metadata($id) {
        $data = $this->novels->find($id);

        $metadata = getMetadata($data);

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
        }, 'group', 'language'])->findOrFail($id);

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

        $progress = $this->calculateProgress($data, $stats);

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
    public function index(Request $request)
    {
        $query = Novel::withRelations()
            ->withCount([
                'chapters',
                'chapters as downloaded_chapters_count' => fn($q) => $q->where('status', 1)->where('blacklist', 0),
            ])
            ->ordered();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // List/grid toggle: explicit ?view= wins, otherwise the last choice
        // remembered in the session.
        $view = $request->query('view');
        if (!in_array($view, ['list', 'grid'], true)) {
            $view = session('novels_view', 'list');
        }
        session(['novels_view' => $view]);

        // Only what the list renders (plus relation keys) — novels.* would
        // drag the longtext description along for every row.
        return view('novels.index', [
            'novels' => $query->paginate(
                $view === 'grid' ? 48 : 25,
                ['id', 'name', 'author', 'status', 'paused_at', 'group_id', 'language_id']
            ),
            'view' => $view,
        ]);
    }

    public function create()
    {
        return view('novels.create');
    }

    /**
     * Bulk actions from the novels list: delete (novel + chapters,
     * soft-deleted) or mark as complete.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:delete,complete',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:novels,id',
        ]);

        foreach ($data['ids'] as $id) {
            if ($data['action'] === 'delete') {
                NovelChapter::where('novel_id', $id)->delete();
                Novel::find($id)?->delete();
            } else {
                $novel = Novel::find($id);
                if ($novel && !$novel->status) {
                    $novel->status = 1;
                    $novel->completed_at = now();
                    $novel->save();
                }
            }

            CacheHelper::clearNovelCache($id);
        }

        CacheHelper::clearNovelDataTablesCache();
        Cache::forget('dashboard_stats');
        Cache::forget('dashboard_attention');

        return response()->json(['success' => true, 'count' => count($data['ids'])]);
    }

    /**
     * Pause/resume automatic scraping for a novel ("ignore" on the
     * dashboard). Paused novels are skipped by the scheduled sweeps and by
     * needs-attention alerts; explicit per-novel commands still run.
     */
    public function togglePause($id)
    {
        $novel = $this->novels->findOrFail($id);
        $novel->paused_at = $novel->paused_at ? null : now();
        $novel->save();

        Cache::forget('dashboard_attention');
        CacheHelper::clearNovelCache($id);

        return response()->json([
            'success' => true,
            'paused' => (bool) $novel->paused_at,
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
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'translator' => 'nullable|string|max:255',
            'translator_url' => 'nullable|url|max:2048',
            'chapter_url' => 'nullable|url|max:2048',
            'alternative_url' => 'nullable|url|max:2048',
            'no_of_chapters' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'group_id' => 'nullable|integer|exists:groups,id',
            'language_id' => 'nullable|integer|exists:languages,id',
            'unique_id' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
        ]);

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
            $object->no_of_chapters = $request->no_of_chapters == "" ? 0 : $request->no_of_chapters;
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
            $storedPath = $request->file('image')->store('', ['disk' => 'public']);
            @chmod(storage_path('app/public/' . $storedPath), 0644);
            $file_object = new File([
                'file_name' => $request->file('image')->getClientOriginalName(),
                'file_path' => 'public/' . $storedPath,
            ]);

            $object->file()->save($file_object);
        }

        return redirect()->route('novels.show', $object->id);
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
        }, 'group', 'language'])->findOrFail($id);

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

        $progress = $this->calculateProgress($data, $stats);

        $chapters = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->orderBy('book')
            ->orderBy('chapter')
            ->paginate(50, ['id', 'novel_id', 'chapter', 'book', 'label', 'status', 'download_date']);

        return view('novels.show', [
            'data' => $data,
            'synopsis' => $this->cleanSynopsis($data->description, $data->name),
            'chapters' => $chapters,
            'new_chapters' => $stats['new_chapters'],
            'duplicate_chapters' => $stats['duplicate_chapters'],
            'missing_chapters' => $stats['missing_chapters'],
            'current_chapters' => $stats['count'],
            'current_chapters_not_downloaded' => $stats['not_downloaded_count'],
            'progress' => round($progress),
        ]);
    }

    /**
     * Scraped descriptions sometimes contain only heading junk or just the
     * novel's own title. Strip the noise and return null when nothing real
     * remains, so the view can fall back to "No summary available".
     */
    protected function cleanSynopsis(?string $html, string $name): ?string
    {
        if (empty($html)) {
            return null;
        }

        $html = preg_replace('/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', $html);
        $html = trim($html);

        $text = trim(html_entity_decode(strip_tags($html)));

        if ($text === '' || mb_strlen($text) < 20 || mb_strtolower($text) === mb_strtolower(trim($name))) {
            return null;
        }

        return $html;
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
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'translator' => 'nullable|string|max:255',
            'translator_url' => 'nullable|url|max:2048',
            'chapter_url' => 'nullable|url|max:2048',
            'alternative_url' => 'nullable|url|max:2048',
            'no_of_chapters' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'group_id' => 'nullable|integer|exists:groups,id',
            'language_id' => 'nullable|integer|exists:languages,id',
            'unique_id' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
        ]);

        $object = $this->novels->findOrFail($id);

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
            $storedPath = $request->file('image')->store('', ['disk' => 'public']);
            @chmod(storage_path('app/public/' . $storedPath), 0644);
            $file_object = new File([
                'file_name' => $request->file('image')->getClientOriginalName(),
                'file_path' => 'public/' . $storedPath,
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
        $object = $this->novels->findOrFail($id);

        // Soft-delete the chapters too — orphaned chapters would otherwise
        // keep counting toward pending/missing stats on the dashboard.
        NovelChapter::where('novel_id', $id)->delete();
        $object->delete();

        // Clear caches after deletion
        CacheHelper::clearNovelCache($id);
        CacheHelper::clearNovelDataTablesCache();
        Cache::forget('dashboard_stats');
        Cache::forget('dashboard_attention');

        return response()->json(['success' => true]);
    }

    /**
     * Compute download progress percentage.
     * Denominator: the largest of metadata's no_of_chapters vs the actual row count
     * (downloaded + pending). This avoids >100% when metadata is stale or never populated.
     */
    protected function calculateProgress($novel, array $stats): int
    {
        $total = max(
            (int) ($novel->no_of_chapters ?? 0),
            (int) $stats['count'] + (int) $stats['not_downloaded_count']
        );

        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(($stats['count'] / $total) * 100));
    }
}
