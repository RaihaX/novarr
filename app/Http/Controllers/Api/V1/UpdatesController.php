<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Updates\NewChapterUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/updates?since=<cursor> — series the caller follows that got
 * new chapters to read since the last poll (App\Services\Updates\
 * NewChapterUpdates): {cursor, series: [{novel_id, name, medium, new_count,
 * chapter_id, chapter_label, latest_at}]}. Send the cursor back as `since`;
 * without it the list is empty (a first call never notifies). A malformed
 * cursor is a 422. A reader route, allowed on the public address; no source
 * is ever called.
 */
class UpdatesController extends Controller
{
    public function index(Request $request, NewChapterUpdates $updates): JsonResponse
    {
        $request->validate(['since' => 'nullable|string|max:64']);

        $raw = $request->query('since');
        $since = null;
        if (is_string($raw) && trim($raw) !== '') {
            $since = NewChapterUpdates::decode($raw);
            if ($since === null) {
                throw ValidationException::withMessages(['since' => 'The since cursor is not valid.']);
            }
        }

        return response()->json($updates->read($since));
    }
}
