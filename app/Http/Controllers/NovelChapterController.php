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
}
