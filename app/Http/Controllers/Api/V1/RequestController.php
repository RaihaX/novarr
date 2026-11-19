<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Novel;
use App\NovelRequest;
use App\Services\Requests\RequestRefused;
use App\Services\Requests\Requests;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
     * caller's own; the admin view may ask for everyone's (scope=all).
     * status: pending | approved | declined | available.
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
            ->when(($data['scope'] ?? 'mine') !== 'all', fn($q) => $q->where('user_id', $user->id))
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

        return response()->json([
            'requests' => $pageRows->map(fn(NovelRequest $r) => RequestPresenter::request($r, $user, $admin, $requesters))->values()->all(),
            'page' => $page,
            'has_more' => $rows->count() > self::PER_PAGE,
            'can_decide' => $admin,
            'pending_limit' => $admin ? null : Requests::maxPending(),
        ]);
    }

    /** GET /api/v1/requests/{id} — the caller's own request, or any in the admin view. */
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
     * POST /api/v1/requests — from a search result {search_id, key, note?}
     * (any other field is ignored: the candidates come from the server's
     * search record), or free text {title, medium, link?, note?}.
     * 201 {request}.
     */
    public function store(Request $request, Requests $requests): JsonResponse
    {
        if ($off = static::disabled()) {
            return $off;
        }
        $fromSearch = $request->filled('search_id');
        $data = $request->validate($fromSearch
            ? [
                'search_id' => 'required|string|max:64',
                'key' => 'required|string|max:64',
                'note' => 'nullable|string|max:500',
            ]
            : [
                'title' => 'required|string|max:255',
                'medium' => 'required|in:novel,manga',
                'link' => 'nullable|url:http,https|max:2048',
                'note' => 'nullable|string|max:500',
            ]);
        $user = app(CurrentReader::class)->user();

        try {
            $made = $fromSearch
                ? $requests->createFromSearch($user, $data['search_id'], $data['key'], $data['note'] ?? null)
                : $requests->createFreeText($user, $data['title'], $data['medium'], $data['link'] ?? null, $data['note'] ?? null);
        } catch (RequestRefused $refused) {
            return $refused->toResponse();
        }

        return response()->json(['request' => $this->present($made->fresh())], 201);
    }

    /**
     * DELETE /api/v1/requests/{id} — the requester cancels their own pending
     * request; the admin view may delete any. 204.
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
        if (!$admin && !$request->isPending()) {
            return response()->json(['message' => 'Only a pending request can be cancelled.', 'code' => 'not_pending', 'status' => $request->displayStatus()], 409);
        }

        $requests->delete($request);

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
        if ($request !== null && !$admin && (int) $request->user_id !== (int) $user->id) {
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
