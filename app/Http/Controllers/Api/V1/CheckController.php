<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Novel;
use App\Services\NovelCheck;
use Illuminate\Http\JsonResponse;

/**
 * "Check for new chapters now" for one novel (see App\Services\NovelCheck):
 * POST starts it in the background, GET reports it.
 */
class CheckController extends Controller
{
    /** GET /api/v1/novels/{id}/check */
    public function show(NovelCheck $checks, $id): JsonResponse
    {
        abort_unless(Novel::whereKey($id)->exists(), 404);

        return response()->json(['check' => $checks->state((int) $id)]);
    }

    /** POST /api/v1/novels/{id}/check — 202 with the new or the already running check. */
    public function start(NovelCheck $checks, $id): JsonResponse
    {
        $novel = Novel::findOrFail($id);

        [$check] = $checks->start($novel);

        return response()->json(['check' => $check], 202);
    }
}
