<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Discover;
use App\Services\Requests\RequestRefused;
use App\Services\Requests\RequestSearch;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Searching for something to request (App\Services\Requests\RequestSearch):
 * reader routes, allowed on the public address. A query string only, never
 * a URL; every searchable source of the medium is asked, in the background.
 *
 *   POST /api/v1/requests/searches {q, medium: novel|manga}
 *        → 202 {search: {id, status: "running", ...}}
 *        → 429 {message, code: search_running | search_rate_limited | search_busy} + Retry-After
 *   GET  /api/v1/requests/searches/{id}
 *        → 200 {search: {id, status: running|done|failed, message, query,
 *               medium, results|null, skipped_sources}}
 *        → 404 unknown, expired, or someone else's search
 */
class RequestSearchController extends Controller
{
    public function store(Request $request, RequestSearch $searches, Discover $discover): JsonResponse
    {
        if ($off = RequestController::disabled()) {
            return $off;
        }
        $data = $request->validate([
            'q' => 'required|string|max:100',
            'medium' => 'required|in:novel,manga,book',
        ]);
        $query = trim(preg_replace('/\s+/u', ' ', $data['q']) ?? '');
        if ($query === '') {
            return response()->json(['message' => 'Type what to search for.', 'errors' => ['q' => ['Type what to search for.']]], 422);
        }

        $user = app(CurrentReader::class)->user();
        try {
            $record = $searches->start((int) $user->id, $query, $data['medium']);
        } catch (RequestRefused $refused) {
            return $refused->toResponse();
        }

        return response()->json(['search' => RequestPresenter::search($searches->find($record['id']) ?? $record, $user, $discover)], 202);
    }

    public function show(string $id, RequestSearch $searches, Discover $discover): JsonResponse
    {
        if ($off = RequestController::disabled()) {
            return $off;
        }
        $user = app(CurrentReader::class)->user();
        $record = $searches->find($id);
        if ($record === null || ((int) $record['user_id'] !== (int) $user->id && !$user->isAdmin())) {
            return response()->json(['message' => 'This search has expired. Search again.', 'code' => 'search_expired'], 404);
        }

        return response()->json(['search' => RequestPresenter::search($record, $user, $discover)]);
    }
}
