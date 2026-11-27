<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NovelChapterController;
use App\NovelChapter;
use App\Services\ReadState;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/chapters/{id}: one chapter's text and neighbours for the native
 * reader. Never marks the chapter read — the app prefetches for offline and
 * marks it explicitly through the progress route.
 */
class ChapterController extends Controller
{
    public function show(ReadState $readState, $id): JsonResponse
    {
        $chapter = NovelChapter::with(['novel:id,name,medium', 'text'])->findOrFail($id);
        $paged = $chapter->novel?->hasPages();

        // A manga chapter is page images: this text endpoint has nothing to
        // give (clients that read manga use /chapters/{id}/pages).
        if ($paged) {
            $comic = $chapter->novel->isComic();

            return response()->json([
                'message' => 'This chapter belongs to a ' . ($comic ? 'comic' : 'manga') . ': it has page images, not text. Use /api/v1/chapters/' . (int) $chapter->id . '/pages.',
                'medium' => $comic ? \App\Novel::MEDIUM_COMIC : \App\Novel::MEDIUM_MANGA,
                'pages' => true,
            ], 422);
        }

        [$prev, $next] = NovelChapterController::neighbours($chapter, ['id', 'chapter', 'book', 'label', 'status']);
        $readState->get($chapter);

        // The exact HTML the web reader renders (chapters/show.blade.php).
        $html = $chapter->rawText() ? $chapter->description : '';

        return response()->json([
            'chapter' => [
                'id' => (int) $chapter->id,
                'novel_id' => (int) $chapter->novel_id,
                'novel_name' => $chapter->novel?->name,
                'label' => Presenter::label($chapter),
                'chapter' => Presenter::number($chapter->chapter),
                'book' => (int) $chapter->book,
                'downloaded' => (bool) $chapter->status,
                'read' => $chapter->isRead(),
                'read_progress' => $chapter->readProgress(),
                'page_count' => $chapter->page_count === null ? null : (int) $chapter->page_count,
                'paragraphs' => static::paragraphs($html),
                'html' => $html,
                'prev' => $prev ? Presenter::chapterRef($prev, true) : null,
                'next' => $next ? Presenter::chapterRef($next, true) : null,
            ],
        ]);
    }

    /**
     * GET /api/v1/chapters/{id}/pages — a manga chapter's pages, the same
     * JSON as the web pages.json (absolute URLs on the request's origin).
     * 404 for a text chapter.
     */
    public function pages($id): JsonResponse
    {
        $chapter = NovelChapter::with('novel:id,name,medium,reading_mode')->findOrFail($id);
        if (!$chapter->novel?->hasPages()) {
            return response()->json(['message' => 'Not a manga chapter.'], 404);
        }

        return response()->json(\App\Http\Controllers\MangaController::pagesPayload($chapter));
    }

    /**
     * The sanitised HTML's paragraphs as plain text: split at every block
     * boundary the reader shows as a new line (<p>, <br/>, <hr/>), tags
     * stripped, entities decoded, whitespace collapsed as a browser would,
     * empty ones dropped.
     *
     * @return string[]
     */
    public static function paragraphs(string $html): array
    {
        $out = [];
        foreach (preg_split('#<\s*/?\s*(?:p|br|hr)\b[^>]*>#i', $html) as $chunk) {
            $text = html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }
}
