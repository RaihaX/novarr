<?php

namespace App\Http\Controllers;

use App\Bookmark;
use App\NovelChapter;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    /**
     * All bookmarks, grouped by novel, newest first within each group.
     */
    public function index()
    {
        $bookmarks = Bookmark::with(['novel:id,name', 'chapter:id,chapter,label'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn($b) => $b->novel !== null && $b->chapter !== null);

        return view('bookmarks.index', [
            'grouped' => $bookmarks->groupBy(fn($b) => $b->novel->name),
        ]);
    }

    /**
     * Save a highlight from the reader.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'chapter_id' => 'required|integer|exists:novel_chapters,id',
            'excerpt' => 'required|string|max:2000',
            'note' => 'nullable|string|max:2000',
        ]);

        $chapter = NovelChapter::findOrFail($data['chapter_id']);

        $bookmark = Bookmark::create([
            'novel_id' => $chapter->novel_id,
            'novel_chapter_id' => $chapter->id,
            'excerpt' => trim($data['excerpt']),
            'note' => trim($data['note'] ?? '') ?: null,
        ]);

        return response()->json(['success' => true, 'id' => $bookmark->id]);
    }

    public function destroy($id)
    {
        Bookmark::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }
}
