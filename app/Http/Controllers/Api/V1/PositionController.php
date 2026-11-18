<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Novel;
use App\Services\ReadingPositions;
use Illuminate\Http\JsonResponse;

/**
 * A series' latest reading position across devices (App\Services\ReadingPositions),
 * for the clients' "read on another device more recently — go there?" prompt.
 * Never cached: it is asked right as a chapter opens.
 */
class PositionController extends Controller
{
    /** GET /api/v1/novels/{id}/position → {position: {…} | null} */
    public function show(ReadingPositions $positions, $id): JsonResponse
    {
        abort_unless(Novel::whereKey($id)->exists(), 404);

        return response()->json(['position' => $positions->latest((int) $id)]);
    }
}
