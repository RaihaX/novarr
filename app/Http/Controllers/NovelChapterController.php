<?php

namespace App\Http\Controllers;

use App\NovelChapter;

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

        return view('chapters.show', [
            'chapter' => $chapter,
            'prev' => $prev,
            'next' => $next,
        ]);
    }
}
