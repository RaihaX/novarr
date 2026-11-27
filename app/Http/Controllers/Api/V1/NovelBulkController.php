<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\NovelChapter;
use App\Services\ReadState;
use App\Support\ReadingClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whole series at once, for the apps' multi-select (Library long-press →
 * "Mark as read" / "Mark as unread"). The reader's own state only.
 *
 *   POST /api/v1/novels/bulk-read {ids: [novel ids] (1..500), read: bool}
 *     → 200 {success: true, count: <series>, chapters: <chapter rows changed>}
 *
 * read=true marks every unread chapter of the series read (chapters already
 * read keep their date, so earlier days' stats are not rewritten — as the
 * web Library's "Mark read", novels.bulk); read=false marks every chapter
 * unread. Unknown ids: 422. One statement pair per call (ReadState::markMany),
 * whatever the number of series.
 */
class NovelBulkController extends Controller
{
    public function __construct(private ReadState $readState)
    {
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:' . ReadingListController::MAX_BULK,
            'ids.*' => 'integer|distinct|exists:novels,id',
            'read' => 'required|boolean',
        ]);
        $read = (bool) $data['read'];

        $chapters = $this->readState->markMany(
            NovelChapter::whereIn('novel_id', $data['ids'])->where('blacklist', 0),
            $read,
            now(),
            onlyUnread: $read,
            client: ReadingClient::from($request)
        );

        return response()->json([
            'success' => true,
            'count' => count($data['ids']),
            'chapters' => $chapters,
        ]);
    }
}
