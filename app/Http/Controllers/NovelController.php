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

        $stats = $this->novelStats($id);

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
     * Return the list of downloaded chapters for a novel so the PWA can
     * pre-cache them for offline reading. Cover + novel page are included
     * so the offline library tile renders without a connection.
     *
     * Optional filters keep big series manageable:
     *   ?unread=1            only downloaded-but-unread chapters
     *   ?from=X&to=Y         chapter-number range (either bound optional)
     *   ?limit=N             cap to the first N (in reading order)
     */
    public function offlineManifest($id, Request $request)
    {
        $novel = $this->novels->with(['file' => fn($q) => $q->orderBy('id', 'desc')])->findOrFail($id);

        $query = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->where('status', 1)
            ->orderBy('book')->orderBy('chapter');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        if ($request->filled('from')) {
            $query->where('chapter', '>=', (float) $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('chapter', '<=', (float) $request->query('to'));
        }
        if ($request->filled('limit')) {
            $query->limit(max(1, (int) $request->query('limit')));
        }

        $chapters = $query->get(['id', 'chapter', 'book', 'label']);

        return response()->json([
            'id' => $novel->id,
            'name' => $novel->name,
            'author' => $novel->author,
            'cover' => $novel->file ? Storage::url($novel->file->file_path) : null,
            'url' => route('novels.show', $novel->id),
            'chapterCount' => $chapters->count(),
            'chapters' => $chapters->map(fn($c) => [
                'id' => $c->id,
                'chapter' => $c->chapter,
                'book' => $c->book,
                'label' => $c->label,
                'url' => route('chapters.show', $c->id),
            ])->values(),
        ]);
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
            ]);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('tags.id', $request->tag));
        }

        // Sort: explicit ?sort= wins, otherwise the last choice in session.
        $sort = $request->query('sort');
        if (!in_array($sort, ['name', 'progress', 'updated', 'chapters'], true)) {
            $sort = session('novels_sort', 'name');
        }
        session(['novels_sort' => $sort]);

        match ($sort) {
            'progress' => $query->orderByRaw('(downloaded_chapters_count / NULLIF(chapters_count, 0)) DESC'),
            'chapters' => $query->orderByDesc('chapters_count'),
            'updated' => $query->orderByDesc(
                NovelChapter::select('download_date')
                    ->whereColumn('novel_id', 'novels.id')
                    ->orderByDesc('download_date')
                    ->limit(1)
            ),
            default => $query->orderBy('name'),
        };

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
            'sort' => $sort,
            'tags' => \App\Tag::orderBy('name')->get(['id', 'name']),
            'activeTag' => $request->query('tag'),
        ]);
    }

    public function create()
    {
        return view('novels.create');
    }

    public function edit($id)
    {
        return view('novels.edit', [
            'novel' => $this->novels->with('tags')->findOrFail($id),
            'groups' => \App\Group::orderBy('label')->get(['id', 'label']),
        ]);
    }

    /**
     * Jump to a chapter by its number — opens the reader for the matching
     * chapter, so big novels don't require paging through the table.
     */
    public function jumpChapter(Request $request, $id)
    {
        $number = (float) $request->query('n');

        $chapter = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->where('chapter', $number)
            ->orderByDesc('status')
            ->first(['id']);

        if (!$chapter) {
            return redirect()->route('novels.show', $id)
                ->with('status', "No chapter {$request->query('n')} found.");
        }

        return redirect()->route('chapters.show', $chapter->id);
    }

    /**
     * Remove duplicate chapters (same novel + chapter + book), keeping the
     * best copy: prefer a downloaded one, then the earliest.
     */
    public function removeDuplicates($id)
    {
        $groups = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->select('chapter', 'book')
            ->groupBy('chapter', 'book')
            ->havingRaw('count(id) > 1')
            ->get();

        $removed = 0;

        foreach ($groups as $group) {
            $dupes = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->where('chapter', $group->chapter)
                ->where('book', $group->book)
                ->orderByDesc('status')
                ->orderBy('id')
                ->get();

            // Keep the first, soft-delete the rest.
            foreach ($dupes->slice(1) as $dupe) {
                $dupe->delete();
                $removed++;
            }
        }

        CacheHelper::clearNovelCache($id);

        return response()->json(['success' => true, 'removed' => $removed]);
    }

    /**
     * Replace a novel's tags from a comma-separated list, creating any new
     * tag names on the fly.
     */
    public function syncTags(Request $request, $id)
    {
        $novel = $this->novels->findOrFail($id);

        $data = $request->validate([
            'tags' => 'array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        $novel->tags()->sync($data['tags'] ?? []);

        return response()->json([
            'success' => true,
            'tags' => $novel->tags()->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Create a tag from the picker's "add new" box, returning its id/name.
     */
    public function storeTag(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:50']);
        $tag = \App\Tag::firstOrCreate(['name' => trim($data['name'])]);

        return response()->json(['success' => true, 'id' => $tag->id, 'name' => $tag->name]);
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
     * Toggle a novel's completed status (mark complete / reopen).
     */
    public function toggleComplete($id)
    {
        $novel = $this->novels->findOrFail($id);
        $novel->status = $novel->status ? 0 : 1;
        $novel->completed_at = $novel->status ? now() : null;
        $novel->save();

        CacheHelper::clearNovelCache($id);
        CacheHelper::clearNovelDataTablesCache();
        Cache::forget('dashboard_stats');

        return response()->json([
            'success' => true,
            'completed' => (bool) $novel->status,
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
            'novelupdates_url' => 'nullable|url|max:2048',
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

        if ( $request->has('novelupdates_url') ) {
            $object->novelupdates_url = $request->novelupdates_url ?: null;
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

        $object->tags()->sync($request->input('tags', []));

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
        }, 'group', 'language', 'tags'])->findOrFail($id);

        $stats = $this->novelStats($id);

        $progress = $this->calculateProgress($data, $stats);

        $chapters = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->orderBy('book')
            ->orderBy('chapter')
            ->paginate(50, ['id', 'novel_id', 'chapter', 'book', 'label', 'status', 'download_date', 'read_at']);

        return view('novels.show', [
            'data' => $data,
            'synopsis' => $this->cleanSynopsis($data->description, $data->name),
            'chapters' => $chapters,
            'new_chapters' => $stats['new_chapters'],
            'duplicate_chapters' => $stats['duplicate_chapters'],
            'missing_chapters' => $stats['missing_chapters'],
            'current_chapters' => $stats['count'],
            'current_chapters_not_downloaded' => $stats['not_downloaded_count'],
            'read_count' => $stats['read_count'] ?? 0,
            'continue_chapter_id' => $stats['continue_chapter_id'] ?? null,
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
            'novelupdates_url' => 'nullable|url|max:2048',
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

        if ( $request->has('novelupdates_url') ) {
            $object->novelupdates_url = $request->novelupdates_url ?: null;
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

        if ($request->has('tags')) {
            $object->tags()->sync($request->input('tags', []));
        }

        return redirect()->route('novels.show', $id)->with('status', 'Novel updated.');
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
     * Per-novel stats (counts, continue target, duplicates, missing chapters)
     * shared by show() and get_novel(). Cached for 5 minutes under a stable key
     * so CacheHelper::clearNovelCache() can invalidate it.
     */
    protected function novelStats(int $id): array
    {
        return Cache::remember("novel_stats_{$id}", now()->addMinutes(5), function () use ($id) {
            // Consolidate the counts into a single aggregate query.
            $aggregates = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->selectRaw('
                    COUNT(CASE WHEN status = 1 THEN 1 END) as downloaded_count,
                    COUNT(CASE WHEN status = 0 THEN 1 END) as not_downloaded_count,
                    COUNT(CASE WHEN status = 1 AND read_at IS NOT NULL THEN 1 END) as read_count,
                    MAX(chapter) as latest_chapter
                ')
                ->first();

            $count = $aggregates->downloaded_count ?? 0;
            $not_downloaded_count = $aggregates->not_downloaded_count ?? 0;
            $read_count = $aggregates->read_count ?? 0;
            $latestChapter = (int) ($aggregates->latest_chapter ?? 0);

            // First downloaded-but-unread chapter — the "continue reading" target.
            $continueChapterId = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->where('status', 1)
                ->whereNull('read_at')
                ->orderBy('book')->orderBy('chapter')
                ->value('id');

            // Duplicate (chapter, book) groups.
            $duplicate_chapters = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->groupBy('chapter', 'book')
                ->havingRaw('count(id) > 1')
                ->select('chapter', 'book')
                ->get();

            // Gaps in the 1..latest sequence: pull the existing chapter numbers
            // and diff against the full range.
            $existingChapters = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->select('chapter', 'double_chapter')
                ->get();

            $existingChapterArray = [];
            foreach ($existingChapters as $item) {
                $existingChapterArray[] = intval($item->chapter);
                if ($item->double_chapter == 1) {
                    $existingChapterArray[] = intval($item->chapter) + 1;
                }
            }

            $missing_chapters = array_values(
                array_diff(range(1, max($latestChapter, 1)), $existingChapterArray)
            );
            // range() always yields [1]; if there are no chapters at all there's
            // nothing missing.
            if ($latestChapter === 0) {
                $missing_chapters = [];
            }

            return [
                'count' => $count,
                'not_downloaded_count' => $not_downloaded_count,
                'read_count' => $read_count,
                'continue_chapter_id' => $continueChapterId,
                'new_chapters' => $not_downloaded_count,
                'duplicate_chapters' => $duplicate_chapters,
                'missing_chapters' => $missing_chapters,
            ];
        });
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
