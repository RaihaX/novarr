<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Requests\RequestFeed;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/notifications?since=<cursor> — the phone apps' polling feed
 * (App\Services\Requests\RequestFeed): {cursor, has_more, items: [{id, kind,
 * title, body, request_id, novel_id, at}]}. Send the last cursor back as
 * `since` to get only newer items; without it, the last 24 hours. A reader
 * route, allowed on the public address; no source is ever called.
 */
class NotificationController extends Controller
{
    public function index(Request $request, RequestFeed $feed): JsonResponse
    {
        $request->validate(['since' => 'nullable|string|max:64']);

        return response()->json($feed->read(app(CurrentReader::class)->user(), $request->query('since')));
    }
}
