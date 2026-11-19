<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\RequestCoverController;
use App\NovelRequest;
use App\NovelRequestFollower;
use App\Services\Discover;
use App\User;
use Illuminate\Support\Collection;

/**
 * JSON shapes of the requests API (searches, requests). Covers go through
 * RequestCoverController::url(): signed for the reader reading them.
 */
class RequestPresenter
{
    /** The `search` object of GET /api/v1/requests/searches/{id}. */
    public static function search(array $record, User $viewer, Discover $discover): array
    {
        $done = $record['status'] === 'done';

        return [
            'id' => (string) $record['id'],
            'status' => (string) $record['status'],
            'message' => $record['status'] === 'failed' ? (string) ($record['message'] ?? 'The search failed.') : null,
            'query' => (string) $record['query'],
            'medium' => (string) $record['medium'],
            'results' => $done ? static::results((array) ($record['groups'] ?? []), $viewer, $discover) : null,
            'skipped_sources' => $done ? array_values((array) ($record['skipped_sources'] ?? [])) : [],
        ];
    }

    /**
     * Result groups with the live flags: in_library (Discover / DuplicateSeries
     * matching, one library query) and requested (one requests query).
     */
    public static function results(array $groups, User $viewer, Discover $discover): array
    {
        if ($groups === []) {
            return [];
        }
        $library = $discover->libraryIndex();

        $open = NovelRequest::whereIn('normalized_title', array_values(array_unique(array_column($groups, 'normalized_title'))))
            ->whereIn('status', NovelRequest::OPEN_STATUSES)
            ->get(['id', 'user_id', 'medium', 'normalized_title', 'status', 'available_at']);
        // by_me: the viewer asked for it, or joined it ("Me too").
        $followed = $open->isEmpty() ? [] : NovelRequestFollower::whereIn('novel_request_id', $open->pluck('id'))
            ->where('user_id', (int) $viewer->id)->pluck('novel_request_id')->map(fn($id) => (int) $id)->flip()->all();
        $requested = [];
        foreach ($open as $request) {
            $key = $request->medium . '|' . $request->normalized_title;
            $mine = (int) $request->user_id === (int) $viewer->id || isset($followed[(int) $request->id]);
            if (!isset($requested[$key]) || ($mine && !$requested[$key]['by_me'])) {
                $requested[$key] = ['status' => $request->displayStatus(), 'by_me' => $mine];
            }
        }

        return array_map(function (array $group) use ($library, $discover, $requested) {
            $novelId = null;
            foreach ($group['hits'] as $hit) {
                $novelId = $discover->match(['name' => $hit['title'], 'url' => $hit['url']], $library, $group['medium']);
                if ($novelId !== null) {
                    break;
                }
            }

            return [
                'key' => (string) $group['key'],
                'title' => (string) $group['title'],
                'author' => $group['author'] ?? null,
                'cover_url' => RequestCoverController::url($group['cover_url'] ?? null),
                'description' => $group['description'] ?? null,
                'medium' => (string) $group['medium'],
                'sources' => array_map(fn(array $hit) => [
                    'source' => (string) $hit['source'],
                    'source_name' => (string) $hit['source_name'],
                    'chapters' => isset($hit['chapters']) ? (int) $hit['chapters'] : null,
                    'status' => $hit['status'] ?? null,
                ], $group['hits']),
                'in_library' => $novelId !== null,
                'novel_id' => $novelId !== null ? (int) $novelId : null,
                'requested' => $requested[$group['medium'] . '|' . $group['normalized_title']] ?? null,
            ];
        }, $groups);
    }

    /** Requesters' {id, name} for a page of requests, one query. */
    public static function requesters(Collection $requests): array
    {
        $ids = $requests->pluck('user_id')->unique()->values()->all();

        return User::whereIn('id', $ids)->get(['id', 'name', 'username'])
            ->mapWithKeys(fn(User $u) => [(int) $u->id => ['id' => (int) $u->id, 'name' => (string) ($u->name ?: $u->username)]])
            ->all();
    }

    /**
     * Followers of a page of requests, two queries: request id => [{id,
     * name}], oldest first. A follower whose account is gone is left out.
     *
     * @return array<int, array<int, array{id: int, name: string}>>
     */
    public static function followers(Collection $requests): array
    {
        $ids = $requests->pluck('id')->map(fn($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $rows = NovelRequestFollower::whereIn('novel_request_id', $ids)->orderBy('created_at')->orderBy('id')->get(['novel_request_id', 'user_id']);
        $users = $rows->isEmpty() ? collect() : User::whereIn('id', $rows->pluck('user_id')->unique()->values())->get(['id', 'name', 'username'])->keyBy('id');

        $followers = array_fill_keys($ids, []);
        foreach ($rows as $row) {
            $user = $users->get((int) $row->user_id);
            if ($user !== null) {
                $followers[(int) $row->novel_request_id][] = ['id' => (int) $user->id, 'name' => (string) ($user->name ?: $user->username)];
            }
        }

        return $followers;
    }

    /**
     * One request. $admin: the admin view (the request context may decide):
     * the full ranked shortlist and the followers' names; otherwise just the
     * source names and followers null. $requesters / $followers: from
     * requesters() / followers() for a page (read here when not given).
     *
     * Fields added for "Me too" (old clients ignore them): also_wanted_by
     * (how many joined), mine ("requester" | "follower" | null), followers.
     */
    public static function request(NovelRequest $request, User $viewer, bool $admin, array $requesters = [], ?array $followers = null): array
    {
        $followers ??= static::followers(collect([$request]));
        $joined = $followers[(int) $request->id] ?? [];
        $isRequester = (int) $request->user_id === (int) $viewer->id;
        $isFollower = !$isRequester && in_array((int) $viewer->id, array_column($joined, 'id'), true);
        $pending = $request->isPending();
        $candidates = $request->candidateList();

        return [
            'id' => (int) $request->id,
            'title' => (string) $request->title,
            'author' => $request->author,
            'cover_url' => RequestCoverController::url($request->cover_url),
            'description' => $request->description,
            'medium' => (string) $request->medium,
            'note' => $request->note,
            'link' => $request->link,
            'status' => $request->displayStatus(),
            'decline_reason' => $request->decline_reason,
            'requested_by' => $requesters[(int) $request->user_id] ?? ['id' => (int) $request->user_id, 'name' => null],
            'novel_id' => $request->novel_id !== null ? (int) $request->novel_id : null,
            'created_at' => Presenter::timestamp($request->created_at),
            'decided_at' => Presenter::timestamp($request->decided_at),
            'available_at' => Presenter::timestamp($request->available_at),
            'candidates' => $admin
                ? array_map(fn(array $c, int $i) => static::candidate($c, $i), $candidates, array_keys($candidates))
                : array_values(array_unique(array_map(fn(array $c) => (string) ($c['source_name'] ?? $c['source'] ?? ''), $candidates))),
            'candidates_ranked_at' => $admin ? Presenter::timestamp($request->candidates_ranked_at) : null,
            'can_decide' => $admin && $pending,
            'can_cancel' => $admin || ($isRequester && $pending) || ($isFollower && $request->isOpenForFollowers()),
            'also_wanted_by' => count($joined),
            'mine' => $isRequester ? 'requester' : ($isFollower ? 'follower' : null),
            'followers' => $admin ? $joined : null,
        ];
    }

    /** One shortlist entry (admin view); `index` is what approve's `candidate` takes. */
    public static function candidate(array $c, int $index): array
    {
        return [
            'index' => $index,
            'source' => (string) ($c['source'] ?? ''),
            'source_name' => (string) ($c['source_name'] ?? ''),
            'url' => (string) ($c['url'] ?? ''),
            'title' => (string) ($c['title'] ?? ''),
            'chapters' => isset($c['chapters']) ? (int) $c['chapters'] : null,
            'status' => $c['status'] ?? null,
            'languages' => array_values((array) ($c['languages'] ?? [])),
            'chapters_available' => isset($c['chapters_available']) ? (int) $c['chapters_available'] : null,
            'highest_chapter' => $c['highest_chapter'] ?? null,
            'gaps' => isset($c['gaps']) ? (int) $c['gaps'] : null,
            'updated_at' => Presenter::timestamp($c['updated_at'] ?? null),
            'error' => $c['error'] ?? null,
            'reason' => $c['reason'] ?? null,
        ];
    }
}
