<?php

namespace App\Http\Controllers;

use App\ChapterPage;
use App\Http\Controllers\Api\V1\Presenter;
use App\Novel;
use App\NovelChapter;
use App\Services\ReadingPositions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The manga reader's server side (contract for the reader UI):
 *
 *   GET  /chapters/{id}/pages/{page}  one page image (chapters.page)
 *   GET  /chapters/{id}/pages.json    the chapter's pages + neighbours (chapters.pages)
 *   POST /novels/{id}/reading-mode    rtl | ltr | vertical (novels.reading_mode)
 *
 * Reading progress is not written here: the reader converts page ↔ percent
 * and posts to chapters.progress like the text reader. Nothing here marks a
 * chapter read.
 */
class MangaController extends Controller
{
    /**
     * Stream one page image. The path comes from the chapter_pages row only
     * (never from the request), so it cannot leave the manga disk. Immutable
     * for a year: a re-download writes new rows, and the ETag/Last-Modified
     * pair still answers revalidation.
     */
    public function page(Request $request, $id, $page)
    {
        $row = ChapterPage::where('novel_chapter_id', (int) $id)->where('page', (int) $page)->first();
        abort_if($row === null, 404);

        $disk = ChapterPage::disk();
        if (!$disk->exists($row->path)) {
            abort(404);
        }
        $path = $disk->path($row->path);

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $row->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], true, null, false, true);
        $response->setEtag(md5($row->id . '|' . $row->path . '|' . $row->bytes . '|' . $row->updated_at));
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');
        $response->isNotModified($request);

        return $response;
    }

    /** The chapter's pages as JSON (URLs on this request's origin). */
    public function pages($id): JsonResponse
    {
        $chapter = NovelChapter::with('novel:id,name,medium,reading_mode')->findOrFail($id);
        abort_unless($chapter->novel?->isManga(), 404, 'Not a manga chapter.');

        // The web reader's own copy also carries the series' latest position
        // (any device), so it needs no extra request for the "go there?"
        // prompt. Fetching pages records nothing, so this is always the
        // position from before the reader opened. Not in the API's payload:
        // native clients ask GET /api/v1/novels/{id}/position.
        return response()->json(static::pagesPayload($chapter) + [
            'latest_position' => app(ReadingPositions::class)->latestForWeb((int) $chapter->novel_id),
        ]);
    }

    /**
     * The pages.json body, shared with GET /api/v1/chapters/{id}/pages:
     * {chapter, pages, prev, next, reading_mode}. Page URLs are absolute.
     */
    public static function pagesPayload(NovelChapter $chapter): array
    {
        $novel = $chapter->novel;
        [$prev, $next] = NovelChapterController::neighbours($chapter, ['id', 'chapter', 'book', 'label', 'status']);

        app(\App\Services\ReadState::class)->get($chapter);

        $pages = $chapter->status
            ? ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->get(['page', 'width', 'height'])
            : collect();

        return [
            'chapter' => [
                'id' => (int) $chapter->id,
                'novel_id' => (int) $chapter->novel_id,
                'label' => Presenter::label($chapter),
                'number' => Presenter::number($chapter->number ?? $chapter->chapter),
                'page_count' => $chapter->page_count === null ? null : (int) $chapter->page_count,
                'read' => $chapter->isRead(),
                'progress' => $chapter->readProgress(),
            ],
            'pages' => $pages->map(fn(ChapterPage $p) => [
                'page' => (int) $p->page,
                'url' => Presenter::url(route('chapters.page', ['id' => $chapter->id, 'page' => $p->page])),
                'width' => $p->width === null ? null : (int) $p->width,
                'height' => $p->height === null ? null : (int) $p->height,
            ])->values()->all(),
            'prev' => $prev ? ['id' => (int) $prev->id, 'label' => Presenter::label($prev)] : null,
            'next' => $next ? ['id' => (int) $next->id, 'label' => Presenter::label($next), 'downloaded' => (bool) $next->status] : null,
            'reading_mode' => $novel instanceof Novel ? $novel->effectiveReadingMode() : 'ltr',
        ];
    }

    /** Save the novel's reading mode (the reader's toggle). */
    public function readingMode(Request $request, $id): JsonResponse
    {
        $novel = Novel::findOrFail($id);
        $data = $request->validate(['mode' => 'required|string|in:' . implode(',', Novel::READING_MODES)]);

        $novel->forceFill(['reading_mode' => $data['mode']])->saveQuietly();

        return response()->json(['reading_mode' => $novel->reading_mode]);
    }
}
