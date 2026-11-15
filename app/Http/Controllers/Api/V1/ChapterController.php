<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NovelChapterController;
use App\NovelChapter;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/chapters/{id}: one chapter's text and neighbours for the native
 * reader. Never marks the chapter read — the app prefetches for offline and
 * marks it explicitly through the progress route.
 */
class ChapterController extends Controller
{
    public function show($id): JsonResponse
    {
        $chapter = NovelChapter::with(['novel:id,name', 'text'])->findOrFail($id);

        [$prev, $next] = NovelChapterController::neighbours($chapter, ['id', 'chapter', 'book', 'label', 'status']);

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
                'read' => $chapter->read_at !== null,
                'read_progress' => $chapter->read_progress === null ? null : (int) $chapter->read_progress,
                'paragraphs' => static::paragraphs($html),
                'html' => $html,
                'prev' => $prev ? Presenter::chapterRef($prev, true) : null,
                'next' => $next ? Presenter::chapterRef($next, true) : null,
            ],
        ]);
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
