<?php

namespace App\Http\Controllers\Api\V1;

use App\Books\BookLinkReader;
use App\Http\Controllers\Controller;
use App\Novel;
use App\Services\Discover;
use App\Services\Requests\RequestRefused;
use App\Services\Requests\RequestSearch;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Searching for something to request (App\Services\Requests\RequestSearch):
 * reader routes, allowed on the public address. A query string, asked of
 * every searchable source of the medium in the background — never a URL,
 * with one exception: for medium book, a link to a book page on Goodreads,
 * Amazon / Audible, Hardcover, Google Books or Open Library (an allowlist,
 * App\Books\BookLinkReader) is read for that one book. A link of any other
 * host, or for another medium, is refused (422) and never fetched.
 *
 *   POST /api/v1/requests/searches {q (100 characters; a book link 2048), medium: novel|manga|book}
 *        → 202 {search: {id, status: "running", ...}}
 *        → 429 {message, code: search_running | search_rate_limited | search_busy} + Retry-After
 *   GET  /api/v1/requests/searches/{id}
 *        → 200 {search: {id, status: running|done|failed, message, query,
 *               medium, results|null, skipped_sources}}
 *        → 404 unknown, expired, or someone else's search
 */
class RequestSearchController extends Controller
{
    public const MAX_QUERY_LENGTH = 100;

    /** A pasted book link may be longer (Amazon's carry tracking). */
    public const MAX_LINK_LENGTH = 2048;

    private static function refuseQuery(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['q' => [$message]]], 422);
    }

    public function store(Request $request, RequestSearch $searches, Discover $discover): JsonResponse
    {
        if ($off = RequestController::disabled()) {
            return $off;
        }
        $data = $request->validate([
            'q' => 'required|string|max:' . self::MAX_LINK_LENGTH,
            'medium' => 'required|in:novel,manga,book',
        ]);
        $query = trim(preg_replace('/\s+/u', ' ', $data['q']) ?? '');
        if ($query === '') {
            return response()->json(['message' => 'Type what to search for.', 'errors' => ['q' => ['Type what to search for.']]], 422);
        }
        if (BookLinkReader::looksLikeUrl($query)) {
            // Only a supported book page, for a book search; nothing else is fetched.
            if ($data['medium'] !== Novel::MEDIUM_BOOK) {
                return static::refuseQuery('Type a title or author, not a link. To ask for a series by its page, use "Request by title".');
            }
            if (BookLinkReader::canonical($query) === null) {
                return static::refuseQuery(BookLinkReader::refusal($query));
            }
        } elseif (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return static::refuseQuery('The search can be at most ' . self::MAX_QUERY_LENGTH . ' characters.');
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
