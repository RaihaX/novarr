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

        $prev = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->where(function ($q) use ($chapter) {
                $q->where('book', '<', $chapter->book)
                  ->orWhere(function ($q2) use ($chapter) {
                      $q2->where('book', $chapter->book)->where('chapter', '<', $chapter->chapter);
                  });
            })
            ->orderBy('book', 'desc')->orderBy('chapter', 'desc')
            ->first(['id', 'chapter', 'label']);

        $next = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->where(function ($q) use ($chapter) {
                $q->where('book', '>', $chapter->book)
                  ->orWhere(function ($q2) use ($chapter) {
                      $q2->where('book', $chapter->book)->where('chapter', '>', $chapter->chapter);
                  });
            })
            ->orderBy('book')->orderBy('chapter')
            ->first(['id', 'chapter', 'label']);

        // Opening a downloaded chapter marks it read — but not when the
        // browser is only prefetching the prev/next pages (<link rel=prefetch>
        // sends Sec-Purpose/Purpose: prefetch), which would mark chapters read
        // before they were ever opened.
        $purpose = strtolower($request->header('Sec-Purpose', $request->header('Purpose', '')));
        $isPrefetch = str_contains($purpose, 'prefetch');

        if (!$isPrefetch && $chapter->status && $chapter->read_at === null) {
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
                'hasContent' => (bool) $chapter->rawText(),
                'prev' => $prev ? ['id' => $prev->id, 'chapter' => $prev->chapter, 'label' => $prev->label, 'url' => route('chapters.show', $prev->id)] : null,
                'next' => $next ? ['id' => $next->id, 'chapter' => $next->chapter, 'label' => $next->label, 'url' => route('chapters.show', $next->id)] : null,
            ],
        ]);
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
        $data = $request->validate(['progress' => 'required|integer|min:0|max:100']);

        $this->novelchapters->findOrFail($id)
            ->forceFill(['read_progress' => $data['progress']])
            ->saveQuietly();

        return response()->json(['success' => true]);
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
            ->where(function ($q) use ($chapter) {
                $q->where('book', '<', $chapter->book)
                  ->orWhere(function ($q2) use ($chapter) {
                      $q2->where('book', $chapter->book)->where('chapter', '<=', $chapter->chapter);
                  });
            })
            ->update(['read_at' => now(), 'read_progress' => 100]);

        CacheHelper::clearNovelCache($chapter->novel_id);

        return response()->json(['success' => true, 'marked' => $count]);
    }

    /**
     * Bulk mark chapters read or unread (novel-page table).
     *
     * Scopes: 'ids' (explicit selection, default), 'all' (whole novel),
     * 'up_to' / 'from' (everything before-and-including / after-and-including
     * an anchor chapter, in the list's (book, chapter) order).
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
                ->where(function ($q) use ($anchor, $before) {
                    $q->where('book', $before ? '<' : '>', $anchor->book)
                        ->orWhere(function ($qq) use ($anchor, $before) {
                            $qq->where('book', $anchor->book)
                                ->where('chapter', $before ? '<=' : '>=', $anchor->chapter);
                        });
                });
        }

        $count = $query->update($values);
        CacheHelper::clearNovelCache($novelId);

        return response()->json(['success' => true, 'count' => $count]);
    }
}
