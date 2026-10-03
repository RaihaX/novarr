<?php

namespace App\Http\Controllers;

use App\NovelChapter;
use App\Http\Helpers\CacheHelper;
use Illuminate\Http\Request;

class NovelChapterController extends Controller
{
    protected $novelchapters;

    public function __construct(NovelChapter $novelchapters)
    {
        $this->novelchapters = $novelchapters;
    }

    /**
     * Display a chapter with previous/next navigation.
     */
    public function show(Request $request, $id)
    {
        $chapter = $this->novelchapters->with(['novel:id,name', 'text'])->findOrFail($id);

        // Neighbours in reading order: (book, sort_key, chapter) tuple
        // comparison — same book and a later key, else the next book. Rows
        // without a sort_key fall back to the legacy chapter number.
        $prev = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->relativeTo($chapter, '<')
            ->orderedDesc()
            ->first(['id', 'chapter', 'label']);

        $next = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->relativeTo($chapter, '>')
            ->ordered()
            ->first(['id', 'chapter', 'label']);

        // Opening a downloaded chapter marks it read — but only when a person
        // actually opened it. Background fetches are skipped (see
        // isBackgroundFetch); the page tells the reader JS via `deferRead` so it
        // can mark the chapter once it is genuinely shown (a rendered Turbo
        // prefetch, or an inline continuous-reading section scrolled into view).
        $background = $this->isBackgroundFetch($request);
        $unread = $chapter->status && $chapter->read_at === null;

        if (!$background && $unread) {
            $chapter->forceFill(['read_at' => now()])->saveQuietly();
            CacheHelper::clearNovelCache($chapter->novel_id);
        }

        return view('chapters.show', [
            'chapter' => $chapter,
            'prev' => $prev,
            'next' => $next,
            // Serialised into the page for the reader JS (continuous loading,
            // TOC, position sync) — and parsed back out of fetched pages when
            // appending the next chapter inline.
            'readerState' => [
                'id' => $chapter->id,
                'novelId' => $chapter->novel_id,
                'label' => $chapter->label ?: 'Chapter ' . $chapter->chapter,
                'chapter' => $chapter->chapter,
                'url' => route('chapters.show', $chapter->id),
                'progress' => $chapter->read_progress,
                'read' => $chapter->read_at !== null,
                // Served to a background fetch without being marked read: the
                // client marks it when the chapter is actually displayed.
                'deferRead' => $background && $unread,
                'hasContent' => (bool) $chapter->rawText(),
                'prev' => $prev ? ['id' => $prev->id, 'chapter' => $prev->chapter, 'label' => $prev->label, 'url' => route('chapters.show', $prev->id)] : null,
                'next' => $next ? ['id' => $next->id, 'chapter' => $next->chapter, 'label' => $next->label, 'url' => route('chapters.show', $next->id)] : null,
            ],
        ]);
    }

    /**
     * Whether this request is a fetch the reader never asked to see:
     *  - browser / Turbo prefetches (`Sec-Purpose`, `Purpose`, and Turbo 8's
     *    `X-Sec-Purpose: prefetch` for hover/instant prefetch);
     *  - our own background fetches, tagged `X-Novarr-Fetch: offline`
     *    (service-worker "Download for offline") or `continuous` (the next
     *    chapter appended inline before the reader reaches it);
     *  - an explicit `?prefetch=1`.
     */
    protected function isBackgroundFetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (str_contains(strtolower((string) $request->header($header, '')), 'prefetch')) {
                return true;
            }
        }

        if (in_array(strtolower(trim((string) $request->header('X-Novarr-Fetch', ''))), ['offline', 'continuous', 'prefetch'], true)) {
            return true;
        }

        return $request->boolean('prefetch');
    }

    /**
     * Manually toggle a chapter's read state (override the auto-mark).
     */
    public function toggleRead(Request $request, $id)
    {
        $chapter = $this->novelchapters->findOrFail($id);
        $read = $chapter->read_at === null;
        // An explicit mark also settles the in-chapter position: done when
        // read, forgotten when unread — so Continue Reading doesn't resume
        // into a chapter the user has already dealt with.
        $chapter->forceFill([
            'read_at' => $read ? now() : null,
            'read_progress' => $read ? 100 : null,
        ])->saveQuietly();
        CacheHelper::clearNovelCache($chapter->novel_id);

        return response()->json([
            'success' => true,
            'read' => $chapter->read_at !== null,
        ]);
    }

    /**
     * Persist how far through a chapter the reader has scrolled (0–100), so
     * the position survives across devices. Accepts JSON or form data — the
     * pagehide save arrives via navigator.sendBeacon, which can only POST
     * form-encoded bodies.
     */
    public function progress(Request $request, $id)
    {
        // `read` lets the reader mark a chapter read at the moment it is
        // actually shown (continuous reading, a rendered prefetch) rather than
        // when its HTML was fetched. Progress is then optional.
        $data = $request->validate([
            'progress' => 'required_without:read|nullable|integer|min:0|max:100',
            'read' => 'sometimes|boolean',
        ]);

        $chapter = $this->novelchapters->findOrFail($id);

        $fill = [];
        if (isset($data['progress'])) {
            $fill['read_progress'] = $data['progress'];
        }
        $markRead = !empty($data['read']) && $chapter->status && $chapter->read_at === null;
        if ($markRead) {
            $fill['read_at'] = now();
        }

        if ($fill) {
            $chapter->forceFill($fill)->saveQuietly();
        }
        if ($markRead) {
            CacheHelper::clearNovelCache($chapter->novel_id);
        }

        return response()->json(['success' => true, 'read' => $chapter->read_at !== null]);
    }

    /**
     * Mark this chapter and every earlier downloaded chapter as read — for
     * catching up read state after reading elsewhere. Already-read chapters
     * keep their original timestamp.
     */
    public function readThrough($id)
    {
        $chapter = $this->novelchapters->findOrFail($id);

        $count = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->where('status', 1)
            ->whereNull('read_at')
            ->relativeTo($chapter, '<=')
            ->update(['read_at' => now(), 'read_progress' => 100]);

        CacheHelper::clearNovelCache($chapter->novel_id);

        return response()->json(['success' => true, 'marked' => $count]);
    }

    /**
     * Bulk mark chapters read or unread (novel-page table).
     *
     * Scopes: 'ids' (explicit selection, default), 'all' (whole novel),
     * 'up_to' / 'from' (everything before-and-including / after-and-including
     * an anchor chapter, in the list's reading order — NovelChapter::ordered()).
     */
    public function bulkRead(Request $request)
    {
        $data = $request->validate([
            'read' => 'required|boolean',
            'scope' => 'nullable|in:ids,all,up_to,from',
            'ids' => 'required_if:scope,ids|required_without:scope|array',
            'ids.*' => 'integer',
            'novel_id' => 'required_if:scope,all|integer',
            'anchor_id' => 'required_if:scope,up_to,from|integer',
        ]);

        $scope = $data['scope'] ?? 'ids';
        $values = [
            'read_at' => $data['read'] ? now() : null,
            'read_progress' => $data['read'] ? 100 : null,
        ];

        if ($scope === 'ids') {
            $count = NovelChapter::whereIn('id', $data['ids'])->update($values);

            foreach (NovelChapter::whereIn('id', $data['ids'])->distinct()->pluck('novel_id') as $novelId) {
                CacheHelper::clearNovelCache($novelId);
            }

            return response()->json(['success' => true, 'count' => $count]);
        }

        if ($scope === 'all') {
            $novelId = (int) $data['novel_id'];
            $query = NovelChapter::where('novel_id', $novelId)->where('blacklist', 0);
        } else {
            $anchor = NovelChapter::findOrFail($data['anchor_id']);
            $novelId = $anchor->novel_id;
            $before = $scope === 'up_to';

            $query = NovelChapter::where('novel_id', $novelId)
                ->where('blacklist', 0)
                ->relativeTo($anchor, $before ? '<=' : '>=');
        }

        $count = $query->update($values);
        CacheHelper::clearNovelCache($novelId);

        return response()->json(['success' => true, 'count' => $count]);
    }
}
