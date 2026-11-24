<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Novel;
use App\Services\Discover;
use App\Services\Requests\RequestBrowse;
use App\Support\CurrentReader;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Requests page's Discover shelf (App\Services\Requests\RequestBrowse):
 * a reader route, allowed on the public address, throttled per reader
 * (novarr-request-browse).
 *
 *   GET /api/v1/requests/browse?medium=manga&list=&page=
 *       → 200 {medium, lists: [{id, name}], list, source_name, page,
 *              has_more, results: [...request-search results...]}
 *         (lists [] and list null when the shelf has nothing to show);
 *         `list` omitted = the first list; a series already in the
 *         library is left out, so a page can hold fewer results than the
 *         source's page (even none, with has_more still true). A result
 *         is requested with POST /api/v1/requests {list, page, key, note?}.
 *       → 422 a list the shelf doesn't offer
 *       → 502 {code: browse_unavailable} the source could not be reached
 *       → 503 {code: browse_busy} + Retry-After: another reader is loading the page
 */
class RequestBrowseController extends Controller
{
    public const UNAVAILABLE = "Couldn't load this list right now. Try again later.";

    public const BUSY = 'This list is being loaded. Try again in a moment.';

    public function index(Request $request, RequestBrowse $browse, Discover $discover): JsonResponse
    {
        if ($off = RequestController::disabled()) {
            return $off;
        }
        $data = $request->validate([
            'medium' => ['nullable', Rule::in(RequestBrowse::MEDIA)],
            'list' => 'nullable|string|max:32',
            'page' => 'nullable|integer|min:1|max:' . RequestBrowse::MAX_PAGE,
        ]);
        $medium = (string) ($data['medium'] ?? Novel::MEDIUM_MANGA);
        $page = (int) ($data['page'] ?? 1);

        $lists = $browse->lists($medium);
        if ($lists === []) {
            return response()->json([
                'medium' => $medium,
                'lists' => [],
                'list' => null,
                'source_name' => null,
                'page' => 1,
                'has_more' => false,
                'results' => [],
            ]);
        }
        $ids = array_column($lists, 'id');
        $request->validate(['list' => ['nullable', Rule::in($ids)]]);
        $list = (string) ($data['list'] ?? $ids[0]);

        try {
            $result = $browse->page($medium, $list, $page);
        } catch (LockTimeoutException) {
            return response()->json(['message' => self::BUSY, 'code' => 'browse_busy'], 503, ['Retry-After' => '5']);
        }
        if ($result === null) {
            return response()->json(['message' => self::UNAVAILABLE, 'code' => 'browse_unavailable'], 502);
        }

        // The shelf is for finding something new: what the library already
        // has is dropped here, per request, because the cached page is
        // shared and the library changes between its refreshes.
        $results = array_values(array_filter(
            RequestPresenter::results($result['groups'], app(CurrentReader::class)->user(), $discover),
            static fn (array $shown): bool => !$shown['in_library'],
        ));

        return response()->json([
            'medium' => $medium,
            'lists' => $lists,
            'list' => $list,
            'source_name' => $result['source_name'] !== '' ? $result['source_name'] : null,
            'page' => $page,
            'has_more' => (bool) $result['has_more'],
            'results' => $results,
        ]);
    }
}
