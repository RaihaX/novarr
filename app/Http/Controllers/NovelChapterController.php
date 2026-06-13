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
    public function show($id)
    {
        $chapter = $this->novelchapters->with('novel:id,name')->findOrFail($id);

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

        // Opening a downloaded chapter marks it read.
        if ($chapter->status && $chapter->read_at === null) {
            $chapter->forceFill(['read_at' => now()])->saveQuietly();
            CacheHelper::clearNovelCache($chapter->novel_id);
        }

        return view('chapters.show', [
            'chapter' => $chapter,
            'prev' => $prev,
            'next' => $next,
        ]);
    }

    /**
     * Manually toggle a chapter's read state (override the auto-mark).
     */
    public function toggleRead(Request $request, $id)
    {
        $chapter = $this->novelchapters->findOrFail($id);
        $chapter->forceFill(['read_at' => $chapter->read_at ? null : now()])->saveQuietly();
        CacheHelper::clearNovelCache($chapter->novel_id);

        return response()->json([
            'success' => true,
            'read' => $chapter->read_at !== null,
        ]);
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
            ->update(['read_at' => now()]);

        CacheHelper::clearNovelCache($chapter->novel_id);

        return response()->json(['success' => true, 'marked' => $count]);
    }

    /**
     * Bulk mark a set of chapters read or unread (novel-page table).
     */
    public function bulkRead(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'read' => 'required|boolean',
        ]);

        NovelChapter::whereIn('id', $data['ids'])
            ->update(['read_at' => $data['read'] ? now() : null]);

        foreach (NovelChapter::whereIn('id', $data['ids'])->distinct()->pluck('novel_id') as $novelId) {
            CacheHelper::clearNovelCache($novelId);
        }

        return response()->json(['success' => true, 'count' => count($data['ids'])]);
    }
}
