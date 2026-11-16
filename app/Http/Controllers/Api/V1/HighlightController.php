<?php

namespace App\Http\Controllers\Api\V1;

use App\Bookmark;
use App\Http\Controllers\Controller;
use App\NovelChapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Highlights for the native apps: the website's bookmarks (App\Bookmark,
 * BookmarkController) — an excerpt of a chapter plus an optional note.
 */
class HighlightController extends Controller
{
    public const PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    /** The website's bookmark form limits (BookmarkController::store()). */
    public const MAX_LENGTH = 2000;

    /**
     * GET /api/v1/highlights?novel_id=&page=&per_page= — newest first.
     * Highlights whose novel or chapter is gone are left out, as on the
     * website's Highlights page.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'novel_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
        ]);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? self::PER_PAGE);

        // One row past the page tells whether another page follows.
        $rows = static::query()
            ->whereHas('novel')
            ->whereHas('chapter')
            ->when(isset($data['novel_id']), fn($q) => $q->where('novel_id', (int) $data['novel_id']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        return response()->json([
            'highlights' => $rows->take($perPage)->map(fn(Bookmark $b) => static::present($b))->values()->all(),
            'page' => $page,
            'has_more' => $rows->count() > $perPage,
        ]);
    }

    /**
     * POST /api/v1/highlights — 201 with the new highlight; 200 with the
     * stored one when this chapter already has this excerpt (its note is
     * replaced when a non-empty note is sent).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chapter_id' => ['required', 'integer', Rule::exists('novel_chapters', 'id')->whereNull('deleted_at')],
            'excerpt' => 'required|string|max:' . self::MAX_LENGTH,
            'note' => 'nullable|string|max:' . self::MAX_LENGTH,
        ]);

        $chapter = NovelChapter::findOrFail($data['chapter_id']);
        $excerpt = trim($data['excerpt']);
        $note = trim($data['note'] ?? '') ?: null;

        $existing = Bookmark::where('novel_chapter_id', $chapter->id)->where('excerpt', $excerpt)->orderBy('id')->first();
        if ($existing !== null) {
            if ($note !== null && $note !== $existing->note) {
                $existing->update(['note' => $note]);
            }

            return response()->json(['highlight' => static::present(static::query()->findOrFail($existing->id))]);
        }

        $bookmark = Bookmark::create([
            'novel_id' => $chapter->novel_id,
            'novel_chapter_id' => $chapter->id,
            'excerpt' => $excerpt,
            'note' => $note,
        ]);

        return response()->json(['highlight' => static::present(static::query()->findOrFail($bookmark->id))], 201);
    }

    /** DELETE /api/v1/highlights/{id} — 204, also when it is already gone. */
    public function destroy($id): Response
    {
        Bookmark::whereKey($id)->delete();

        return response()->noContent();
    }

    /** Bookmarks with what present() reads, eager loaded. */
    private static function query()
    {
        return Bookmark::with(['novel:id,name', 'chapter:id,chapter,label']);
    }

    public static function present(Bookmark $bookmark): array
    {
        return [
            'id' => (int) $bookmark->id,
            'novel_id' => (int) $bookmark->novel_id,
            'novel_name' => $bookmark->novel?->name,
            'chapter_id' => (int) $bookmark->novel_chapter_id,
            'chapter_label' => $bookmark->chapter ? Presenter::label($bookmark->chapter) : null,
            'excerpt' => $bookmark->excerpt,
            'note' => $bookmark->note,
            'created_at' => Presenter::timestamp($bookmark->created_at),
        ];
    }
}
