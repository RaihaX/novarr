<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Discover;
use App\Services\DiscoverSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Background Discover browse/search (see App\Services\DiscoverSearch): POST
 * starts it with GET /discover/browse's parameters, GET follows it.
 */
class DiscoverSearchController extends Controller
{
    /** POST /api/v1/discover/searches — 202 with the new search. */
    public function store(Request $request, Discover $discover, DiscoverSearch $searches): JsonResponse
    {
        $data = $request->validate(DiscoverController::browseRules($discover->sources()));

        return response()->json(['search' => $searches->start($data)], 202);
    }

    /** GET /api/v1/discover/searches/{id} — 404 when unknown or expired. */
    public function show(DiscoverSearch $searches, string $id): JsonResponse
    {
        $search = $searches->state($id);
        abort_if($search === null, 404);

        return response()->json(['search' => $search]);
    }
}
