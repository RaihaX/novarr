<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Novel;
use App\NovelRequest;
use App\NovelRequestFollower;
use App\Services\ReadingLists;
use App\Services\Requests\RequestBrowse;
use App\Services\Requests\RequestCategories;
use App\Services\Requests\RequestRefused;
use App\Services\Requests\Requests;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reader requests (App\Services\Requests\Requests).
 *
 * Reader routes (also on the public address): list, show, make, cancel.
 * Admin routes (home network only, like every admin feature): approve,
 * decline, rerank. The "admin view" — every request (?scope=all), the full
 * ranked shortlist, can_decide — is given when the request context may
 * decide (the `admin` gate: the admin, not on the public address).
 *
 * Errors are JSON {message, code, ...}: see the codes in Requests.
 */
class RequestController extends Controller
{
    public const PER_PAGE = 50;

    /** The feature switch (config novarr.requests.enabled): a 404 when off. */
    public static function disabled(): ?JsonResponse
    {
        return config('novarr.requests.enabled', true)
            ? null
            : response()->json(['message' => 'Requests are turned off on this server.', 'code' => 'requests_disabled'], 404);
    }

    private static function adminView(): bool
    {
        return Gate::allows('admin');
    }

    /**
     * GET /api/v1/requests?scope=mine|all&status=&page= — newest first. The
     * caller's own and those they joined ("Me too"); the admin view may ask
     * for everyone's (scope=all). status: pending | approved | declined |
     * available.
     */
    public function index(Request $request): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $data = $request->validate([
            'scope' => 'nullable|in:mine,all',
            'status' => 'nullable|in:pending,approved,declined,available',
            'page' => 'nullable|integer|min:1',
        ]);
        $user = app(CurrentReader::class)->user();
        $admin = static::adminView();
        if (($data['scope'] ?? 'mine') === 'all' && !$admin) {
            return response()->json(['message' => 'Only the admin can see every request.', 'code' => 'forbidden'], 403);
        }
        $page = (int) ($data['page'] ?? 1);

        $rows = NovelRequest::query()
            ->when(($data['scope'] ?? 'mine') !== 'all', fn($q) => $q->where(fn($mine) => $mine
                ->where('user_id', $user->id)
                ->orWhereIn('id', NovelRequestFollower::select('novel_request_id')->where('user_id', (int) $user->id))))
            ->when(isset($data['status']), function ($q) use ($data) {
                match ($data['status']) {
                    'approved' => $q->where('status', NovelRequest::STATUS_APPROVED)->whereNull('available_at'),
                    'available' => $q->where('status', NovelRequest::STATUS_APPROVED)->whereNotNull('available_at'),
                    default => $q->where('status', $data['status']),
                };
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $pageRows = $rows->take(self::PER_PAGE);
        $requesters = RequestPresenter::requesters($pageRows);
        $followers = RequestPresenter::followers($pageRows);
        $categories = RequestCategories::forReader((int) $user->id, $pageRows->pluck('id')->map(fn($id) => (int) $id)->all());

        return response()->json([
            'requests' => $pageRows->map(fn(NovelRequest $r) => RequestPresenter::request($r, $user, $admin, $requesters, $followers, $categories))->values()->all(),
            'page' => $page,
            'has_more' => $rows->count() > self::PER_PAGE,
            'can_decide' => $admin,
            'pending_limit' => $admin ? null : Requests::maxPending(),
        ]);
    }

    /** GET /api/v1/requests/{id} — the caller's own request or one they joined, or any in the admin view. */
    public function show(int $id): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        [$request, $user, $admin] = $this->visible($id);
        if ($request === null) {
            return static::notFound();
        }

        return response()->json(['request' => RequestPresenter::request($request, $user, $admin, RequestPresenter::requesters(collect([$request])))]);
    }

    /**
     * POST /api/v1/requests — one of three bodies:
     *   - a search result {search_id, key, note?} (any other field is
     *     ignored: the candidates come from the server's search record);
     *   - a Discover shelf result {list, page?, key, note?, medium?} (when
     *     search_id is absent and list is given; page defaults to 1, medium
     *     to manga): the candidate comes from the server's own page of that
     *     list (GET /api/v1/requests/browse), the shortlist is then filled
     *     from the other sources in the background; 410 browse_expired when
     *     the result is no longer on the list;
     *   - free text {title, medium, link?, note?}.
     * 201 {request, joined: false} — a new request;
     * 200 {request, joined: true}  — someone else already asked for this
     *     work (pending, or approved and not yet available): the caller now
     *     follows their request ("Me too"; note and link are ignored).
     *
     * Any body may add categories: [list ids] (the caller's own; 404
     * otherwise, before anything is made): remembered on the request for
     * the caller (request.requested_categories) and applied when it becomes
     * available. When the series is already in the library (409 in_library
     * with novel_id) it is put in them at once (added_to_categories); when
     * the caller already asked for it (409 already_requested) their choice
     * on that request is replaced (requested_categories).
     */
    public function store(Request $request, Requests $requests): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $fromSearch = $request->filled('search_id');
        $fromBrowse = !$fromSearch && $request->filled('list');
        $data = $request->validate(match (true) {
            $fromSearch => [
                'search_id' => 'required|string|max:64',
                'key' => 'required|string|max:64',
                'note' => 'nullable|string|max:500',
            ],
            $fromBrowse => [
                'list' => ['required', 'string', Rule::in(array_keys(RequestBrowse::LISTS))],
                'page' => 'nullable|integer|min:1|max:' . RequestBrowse::MAX_PAGE,
                'key' => 'required|string|max:64',
                'note' => 'nullable|string|max:500',
                'medium' => ['nullable', Rule::in(RequestBrowse::MEDIA)],
            ],
            default => [
                'title' => 'required|string|max:255',
                'medium' => 'required|in:novel,manga,book,comic',
                'link' => 'nullable|url:http,https|max:2048',
                'note' => 'nullable|string|max:500',
            ],
        });
        $user = app(CurrentReader::class)->user();
        $extra = $request->validate([
            'categories' => 'nullable|array|max:' . RequestCategories::MAX,
            'categories.*' => 'integer',
        ]);
        $categories = array_key_exists('categories', $extra) && $extra['categories'] !== null
            ? RequestCategories::validIds((int) $user->id, $extra['categories'])
            : null;

        try {
            $made = match (true) {
                $fromSearch => $requests->createFromSearch($user, $data['search_id'], $data['key'], $data['note'] ?? null),
                $fromBrowse => $requests->createFromBrowse($user, (string) ($data['medium'] ?? Novel::MEDIUM_MANGA), $data['list'], (int) ($data['page'] ?? 1), $data['key'], $data['note'] ?? null),
                default => $requests->createFreeText($user, $data['title'], $data['medium'], $data['link'] ?? null, $data['note'] ?? null),
            };
        } catch (RequestRefused $refused) {
            return ($categories !== null ? static::withCategories($refused, (int) $user->id, $categories) : $refused)->toResponse();
        }

        if ($categories !== null) {
            RequestCategories::remember($made['request'], (int) $user->id, $categories);
        }

        return response()->json([
            'request' => $this->present($made['request']->fresh()),
            'joined' => $made['joined'],
        ], $made['joined'] ? 200 : 201);
    }

    /**
     * A refused request that named categories: a series already in the
     * library goes in them now; the caller's own request remembers them.
     */
    private static function withCategories(RequestRefused $refused, int $userId, array $categories): RequestRefused
    {
        $extra = null;
        if ($refused->errorCode === 'in_library' && !empty($refused->extra['novel_id'])) {
            try {
                ReadingLists::setMemberships($userId, [(int) $refused->extra['novel_id']], $categories, []);
                $extra = ['added_to_categories' => $categories];
            } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
                $extra = ['added_to_categories' => []];
            }
        } elseif ($refused->errorCode === 'already_requested' && !empty($refused->extra['request_id'])
            && ($own = NovelRequest::find((int) $refused->extra['request_id'])) !== null) {
            RequestCategories::remember($own, $userId, $categories);
            $extra = ['requested_categories' => $categories];
        }

        return $extra === null ? $refused : new RequestRefused($refused->status, $refused->getMessage(), $refused->errorCode, $refused->extra + $extra, $refused->headers);
    }

    /**
     * PUT /api/v1/requests/{id}/categories {categories: [list ids]} — the
     * categories the caller (its requester or a follower) wants the series
     * in once it is available; replaces their choice ([] clears it). 200
     * {request}; 404 for a request they don't see or a list not theirs; 409
     * not_open once the request is decided against or already available.
     */
    public function categories(Request $http, int $id): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $data = $http->validate([
            'categories' => 'present|array|max:' . RequestCategories::MAX,
            'categories.*' => 'integer',
        ]);
        $user = app(CurrentReader::class)->user();
        $request = NovelRequest::find($id);
        if ($request === null || ((int) $request->user_id !== (int) $user->id && !$request->isFollowedBy((int) $user->id))) {
            return static::notFound();
        }
        $categories = RequestCategories::validIds((int) $user->id, $data['categories']);
        if ($request->displayStatus() === NovelRequest::STATUS_AVAILABLE && $request->novel_id !== null) {
            // Already readable: straight into the categories.
            ReadingLists::setMemberships((int) $user->id, [(int) $request->novel_id], $categories, []);
        } elseif (!in_array($request->displayStatus(), [NovelRequest::STATUS_PENDING, NovelRequest::STATUS_APPROVED], true)) {
            return response()->json(['message' => 'This request has already been decided.', 'code' => 'not_open', 'status' => $request->displayStatus()], 409);
        }
        RequestCategories::remember($request, (int) $user->id, $categories);

        return response()->json(['request' => $this->present($request->fresh())]);
    }

    /**
     * DELETE /api/v1/requests/{id} — 204:
     *   - a follower leaves the request (only their follow goes), while it is
     *     pending or approved;
     *   - the requester cancels their own pending request: it is deleted,
     *     or, when others joined it, handed to the oldest follower;
     *   - the admin view may delete any.
     */
    public function destroy(int $id, Requests $requests)
    {
        if ($off = static::disabled()) {
            return $off;
        }
        [$request, $user, $admin] = $this->visible($id);
        if ($request === null) {
            return static::notFound();
        }

        try {
            $isRequester = (int) $request->user_id === (int) $user->id;
            if (!$isRequester && $request->isFollowedBy((int) $user->id)) {
                if (!$request->isOpenForFollowers() && !$admin) {
                    return response()->json(['message' => 'This request has already been decided.', 'code' => 'not_pending', 'status' => $request->displayStatus()], 409);
                }
                $requests->leave($request, $user);

                return response()->noContent();
            }
            if ($isRequester && $request->isPending()) {
                $requests->cancel($request);

                return response()->noContent();
            }
            if (!$admin) {
                return response()->json(['message' => 'Only a pending request can be cancelled.', 'code' => 'not_pending', 'status' => $request->displayStatus()], 409);
            }

            $requests->delete($request);
        } catch (RequestRefused $refused) {
            return $refused->toResponse();
        }

        return response()->noContent();
    }

    /**
     * POST /api/v1/requests/{id}/approve {candidate?, force?} | {url, title?, force?} | {novel_id}
     * (admin). 200 {request, novel_id, created}.
     */
    public function approve(Request $http, int $id, Requests $requests): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $request = NovelRequest::find($id);
        if ($request === null) {
            return static::notFound();
        }
        $data = $http->validate([
            'candidate' => 'nullable|integer|min:0',
            'force' => 'nullable|boolean',
            'url' => 'nullable|url|max:2048',
            'title' => 'nullable|string|max:255',
            'novel_id' => 'nullable|integer|min:1',
        ]);
        $data = array_filter($data, fn($v) => $v !== null);
        $data['force'] = $http->boolean('force');

        try {
            $result = $requests->approve($request, app(CurrentReader::class)->user(), $data);
        } catch (RequestRefused $refused) {
            return $refused->toResponse();
        }

        return response()->json([
            'request' => $this->present($result['request']->fresh()),
            'novel_id' => (int) $result['novel']->id,
            'created' => $result['created'],
        ]);
    }

    /** POST /api/v1/requests/{id}/decline {reason?} (admin). 200 {request}. */
    public function decline(Request $http, int $id, Requests $requests): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $request = NovelRequest::find($id);
        if ($request === null) {
            return static::notFound();
        }
        $data = $http->validate(['reason' => 'nullable|string|max:500']);

        try {
            $request = $requests->decline($request, app(CurrentReader::class)->user(), $data['reason'] ?? null);
        } catch (RequestRefused $refused) {
            return $refused->toResponse();
        }

        return response()->json(['request' => $this->present($request->fresh())]);
    }

    /** POST /api/v1/requests/{id}/rerank (admin) — rebuild a manga shortlist in the background. 202 {request}. */
    public function rerank(int $id, Requests $requests): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $request = NovelRequest::find($id);
        if ($request === null) {
            return static::notFound();
        }
        if ($request->medium !== Novel::MEDIUM_MANGA || $request->candidateList() === []) {
            return response()->json(['message' => 'Only a manga request with sources has a shortlist to rank.', 'code' => 'nothing_to_rank'], 422);
        }

        $requests->queueRanking($request);

        return response()->json(['request' => $this->present($request->fresh())], 202);
    }

    /** @return array{0: ?NovelRequest, 1: \App\User, 2: bool} */
    private function visible(int $id): array
    {
        $user = app(CurrentReader::class)->user();
        $admin = static::adminView();
        $request = NovelRequest::find($id);
        if ($request !== null && !$admin && (int) $request->user_id !== (int) $user->id && !$request->isFollowedBy((int) $user->id)) {
            $request = null;
        }

        return [$request, $user, $admin];
    }

    private function present(NovelRequest $request): array
    {
        return RequestPresenter::request($request, app(CurrentReader::class)->user(), static::adminView(), RequestPresenter::requesters(collect([$request])));
    }

    private static function notFound(): JsonResponse
    {
        return response()->json(['message' => 'No such request.', 'code' => 'not_found'], 404);
    }
}
