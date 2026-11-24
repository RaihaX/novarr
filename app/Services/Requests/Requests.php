<?php

namespace App\Services\Requests;

use App\Jobs\FindRequestCandidates;
use App\Jobs\RankRequestCandidates;
use App\Novel;
use App\NovelRequest;
use App\NovelRequestFollower;
use App\RequestNotification;
use App\Services\AddSeries;
use App\Services\Discover;
use App\Services\DuplicateSeries;
use App\Services\NovelCheck;
use App\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Making and deciding reader requests (App\NovelRequest). The API
 * (Api\V1\RequestController) validates input; every rule lives here and a
 * refusal is a RequestRefused (status, message, code).
 *
 * A request made from a search names the server's own search record and a
 * result key: its candidates are copied from that record, never from the
 * client. A request made from the Discover shelf (RequestBrowse) names a
 * list, a page and a group key the same way: its one candidate (the chart's
 * source) is copied from the server's cached page, and FindRequestCandidates
 * then looks the title up on every other source to fill the shortlist
 * before ranking. A free-text request keeps a title, an optional link and
 * note as plain text; the link is never fetched.
 *
 * Approving adds the series through AddSeries (as POST /api/v1/novels
 * does), then starts the chapter check (NovelCheck) so chapters download.
 *
 * "Me too": asking for a work (same normalised title + medium) that someone
 * ELSE has already asked for — pending, or approved and not yet available —
 * makes the caller a follower of that request instead of a second request
 * (joined: true). A follower hears the same decisions as the requester,
 * sees the request in their own list and may leave it; joining tells the
 * owner nothing new and does not count against the pending cap. When the
 * requester cancels a pending request others joined, the oldest follower
 * becomes its requester and the request stays.
 */
class Requests
{
    public const CREATE_LOCK = 'novel_requests_create_lock';

    public const SEARCH_EXPIRED = 'This search has expired. Search again.';

    public const BROWSE_EXPIRED = 'This list has changed. Reload it and try again.';

    public const BUSY = 'Another request is being made right now. Try again in a moment.';

    /** The admin's "N pending" nav badge (pendingCount()); forgotten on every change. */
    public const PENDING_COUNT_CACHE = 'requests_pending_count';

    public function __construct(
        private RequestSearch $searches,
        private Discover $discover,
        private RequestNotifier $notifier,
    ) {
    }

    public static function maxPending(): int
    {
        return max(1, (int) config('novarr.requests.max_pending_per_reader', 5));
    }

    /**
     * Pending requests from everyone: the admin's nav badge. One cached
     * count query, forgotten whenever a request is made, decided or removed
     * (and expiring on its own, should a change bypass this class).
     */
    public static function pendingCount(): int
    {
        return (int) Cache::remember(self::PENDING_COUNT_CACHE, 600, fn() => (int) NovelRequest::query()->toBase()
            ->where('status', NovelRequest::STATUS_PENDING)
            ->selectRaw('count(*) as pending_count')
            ->value('pending_count'));
    }

    public static function forgetPendingCount(): void
    {
        Cache::forget(self::PENDING_COUNT_CACHE);
    }

    public static function maxCandidates(): int
    {
        return max(1, (int) config('novarr.requests.max_candidates', 8));
    }

    /**
     * A request for one result of the caller's search (or, for a work
     * someone else asked for, joining theirs).
     *
     * @return array{request: NovelRequest, joined: bool}
     *
     * @throws RequestRefused
     */
    public function createFromSearch(User $user, string $searchId, string $key, ?string $note): array
    {
        $search = $this->searches->find($searchId);
        if ($search === null) {
            throw new RequestRefused(410, self::SEARCH_EXPIRED, 'search_expired');
        }
        if ((int) $search['user_id'] !== (int) $user->id && !$user->isAdmin()) {
            throw new RequestRefused(410, self::SEARCH_EXPIRED, 'search_expired');
        }
        if ($search['status'] === 'running') {
            throw new RequestRefused(409, "The search hasn't finished yet.", 'search_running');
        }
        if ($search['status'] !== 'done') {
            throw new RequestRefused(410, 'This search failed. Search again.', 'search_expired');
        }

        $group = null;
        foreach ((array) ($search['groups'] ?? []) as $candidate) {
            if (($candidate['key'] ?? null) === $key) {
                $group = $candidate;
                break;
            }
        }
        if ($group === null) {
            throw new RequestRefused(422, 'That result is not part of this search.', 'unknown_result');
        }

        return $this->create($user, $this->fieldsFromGroup((string) $search['medium'], $group, $note));
    }

    /**
     * A request for one result of a Discover shelf list (RequestBrowse), as
     * createFromSearch() is for a search result: the group is looked up on
     * the server's own page of the list (or another cached page of it),
     * never taken from the client. A new request's shortlist is then filled
     * from the other sources (FindRequestCandidates).
     *
     * @return array{request: NovelRequest, joined: bool}
     *
     * @throws RequestRefused 410 browse_expired, 503 busy, or as create()
     */
    public function createFromBrowse(User $user, string $medium, string $list, int $page, string $key, ?string $note): array
    {
        try {
            $group = app(RequestBrowse::class)->find($medium, $list, $page, $key);
        } catch (LockTimeoutException) {
            throw new RequestRefused(503, self::BUSY, 'busy');
        }
        if ($group === null) {
            throw new RequestRefused(410, self::BROWSE_EXPIRED, 'browse_expired');
        }

        return $this->create($user, $this->fieldsFromGroup($medium, $group, $note) + [
            'from_browse' => true,
            'alt_titles' => array_values(array_filter((array) ($group['alt_titles'] ?? []), 'is_string')),
        ]);
    }

    /** create()'s fields for a result group: the server's own hits, one per source, in search order. */
    private function fieldsFromGroup(string $medium, array $group, ?string $note): array
    {
        return [
            'medium' => $medium,
            'title' => (string) $group['title'],
            'author' => $group['author'] ?? null,
            'cover_url' => $group['cover_url'] ?? null,
            'description' => $group['description'] ?? null,
            'note' => $note,
            'link' => null,
            'candidates' => static::candidatesFromHits((array) ($group['hits'] ?? []), static::maxCandidates()),
        ];
    }

    /**
     * Result hits (RequestSearch::group()) → request candidates, at most
     * $limit. Also used by FindRequestCandidates for the other sources' hits.
     */
    public static function candidatesFromHits(array $hits, int $limit): array
    {
        $candidates = [];
        foreach (array_slice(array_values($hits), 0, max(0, $limit)) as $hit) {
            $candidates[] = [
                'source' => (string) $hit['source'],
                'source_name' => (string) $hit['source_name'],
                'url' => (string) $hit['url'],
                'title' => (string) $hit['title'],
                'cover_url' => $hit['cover_url'] ?? null,
                'chapters' => $hit['chapters'] ?? null,
                'status' => $hit['status'] ?? null,
            ];
        }

        return $candidates;
    }

    /**
     * A request for something no search found: plain text for the owner (or,
     * for a work someone else asked for, joining theirs).
     *
     * @return array{request: NovelRequest, joined: bool}
     *
     * @throws RequestRefused
     */
    public function createFreeText(User $user, string $title, string $medium, ?string $link, ?string $note): array
    {
        return $this->create($user, [
            'medium' => $medium,
            'title' => $title,
            'author' => null,
            'cover_url' => null,
            'description' => null,
            'note' => $note,
            'link' => $link,
            'candidates' => [],
        ]);
    }

    /**
     * @return array{request: NovelRequest, joined: bool}
     *
     * @throws RequestRefused
     */
    private function create(User $user, array $fields): array
    {
        $fields['title'] = trim((string) $fields['title']);
        $fields['note'] = static::clean($fields['note'] ?? null);
        $fields['link'] = static::clean($fields['link'] ?? null);
        $normalized = Discover::normalizeName($fields['title']);
        if ($normalized === '') {
            throw new RequestRefused(422, 'Give the title of the series.', 'invalid_title');
        }

        try {
            $made = Cache::lock(self::CREATE_LOCK, 10)->block(5, function () use ($user, $fields, $normalized) {
                $open = NovelRequest::where('normalized_title', $normalized)
                    ->where('medium', $fields['medium'])
                    ->whereIn('status', NovelRequest::OPEN_STATUSES)
                    ->orderBy('id')
                    ->get();
                // Approving adds the series at once, but until a chapter is
                // downloaded the request is the useful thing to join.
                $downloading = $open->contains(fn(NovelRequest $r) => $r->displayStatus() === NovelRequest::STATUS_APPROVED);
                if (!$downloading) {
                    $this->refuseIfInLibrary($fields);
                }
                $joined = $this->joinIfRequested($user, $open);
                if ($joined !== null) {
                    return ['request' => $joined, 'joined' => true];
                }

                if (!$user->isAdmin()
                    && NovelRequest::where('user_id', $user->id)->where('status', NovelRequest::STATUS_PENDING)->count() >= static::maxPending()) {
                    throw new RequestRefused(429, 'You have ' . static::maxPending() . ' requests waiting already. Wait for the owner to decide on one, or cancel one.', 'pending_limit', ['limit' => static::maxPending()]);
                }

                return ['joined' => false, 'request' => NovelRequest::create([
                    'user_id' => (int) $user->id,
                    'medium' => $fields['medium'],
                    'title' => mb_substr($fields['title'], 0, 255),
                    'normalized_title' => mb_substr($normalized, 0, 255),
                    'author' => $fields['author'] !== null ? mb_substr(trim((string) $fields['author']), 0, 255) ?: null : null,
                    'cover_url' => $fields['cover_url'],
                    'description' => $fields['description'],
                    'note' => $fields['note'],
                    'link' => $fields['link'],
                    'status' => NovelRequest::STATUS_PENDING,
                    'candidates' => $fields['candidates'],
                ])];
            });
        } catch (LockTimeoutException) {
            throw new RequestRefused(503, self::BUSY, 'busy');
        }

        if ($made['joined']) {
            // The owner already knows of this request: nothing to announce or rank.
            return $made;
        }

        $request = $made['request'];
        static::forgetPendingCount();
        $this->notify(fn() => $this->notifier->created($request), $request);

        if (!empty($fields['from_browse'])) {
            // One candidate so far: the other sources first, then the ranking.
            $this->queueFindCandidates($request, (array) ($fields['alt_titles'] ?? []));
        } elseif ($request->medium === Novel::MEDIUM_MANGA && $request->candidateList() !== []) {
            $this->queueRanking($request);
        }

        return $made;
    }

    /** @throws RequestRefused 409 in_library */
    private function refuseIfInLibrary(array $fields): void
    {
        $library = $this->discover->libraryIndex();
        $match = null;
        foreach ($fields['candidates'] as $candidate) {
            $match = $this->discover->libraryMatch(['name' => $candidate['title'], 'url' => $candidate['url']], $library, $fields['medium']);
            if ($match !== null) {
                break;
            }
        }
        $match ??= app(DuplicateSeries::class)->match($library, $fields['medium'], [$fields['title']]);

        if ($match !== null) {
            $novel = Novel::find($match['id']);
            throw new RequestRefused(409, $novel !== null ? DuplicateSeries::message($novel) : 'This series is already in the library.', 'in_library', ['novel_id' => (int) $match['id']]);
        }
    }

    /**
     * The open requests for this work ($open: pending or approved, oldest
     * first), if any, decide what asking for it does:
     *   - already available (approved, a chapter downloaded) → 409 in_library;
     *   - the caller's own → 409 already_requested (by_me);
     *   - someone else's → the caller joins it (again, harmlessly, when they
     *     already follow it): returned.
     * Null when nobody has asked for it (a new request is made).
     *
     * @throws RequestRefused
     */
    private function joinIfRequested(User $user, \Illuminate\Support\Collection $open): ?NovelRequest
    {
        if ($open->isEmpty()) {
            return null;
        }

        $available = $open->first(fn(NovelRequest $r) => $r->displayStatus() === NovelRequest::STATUS_AVAILABLE);
        if ($available !== null) {
            $novel = $available->novel_id !== null ? Novel::find($available->novel_id) : null;
            throw new RequestRefused(409, $novel !== null ? DuplicateSeries::message($novel) : 'This series is already in the library.', 'in_library', ['novel_id' => $available->novel_id !== null ? (int) $available->novel_id : null]);
        }

        $mine = $open->first(fn(NovelRequest $r) => (int) $r->user_id === (int) $user->id);
        if ($mine !== null) {
            $state = $mine->isPending() ? 'waiting for a decision' : 'already approved';
            throw new RequestRefused(409, "You have already requested this ({$state}).", 'already_requested', [
                'by_me' => true,
                'status' => $mine->displayStatus(),
                'request_id' => (int) $mine->id,
            ]);
        }

        // Joining is idempotent: one already followed is answered as joined again.
        $followed = NovelRequestFollower::whereIn('novel_request_id', $open->pluck('id'))->where('user_id', (int) $user->id)->value('novel_request_id');
        if ($followed !== null) {
            return $open->first(fn(NovelRequest $r) => (int) $r->id === (int) $followed);
        }

        $request = $open->first();
        NovelRequestFollower::insertOrIgnore([
            'novel_request_id' => (int) $request->id,
            'user_id' => (int) $user->id,
            'created_at' => now(),
        ]);

        return $request;
    }

    /**
     * A reader stops following a request they joined. True when they were
     * following it.
     */
    public function leave(NovelRequest $request, User $user): bool
    {
        return NovelRequestFollower::where('novel_request_id', $request->id)->where('user_id', (int) $user->id)->delete() > 0;
    }

    /**
     * The requester cancels their pending request: deleted, unless others
     * joined it — then the oldest follower becomes the requester and the
     * request stays (one follower fewer). True when it was handed over.
     *
     * @throws RequestRefused 503 busy
     */
    public function cancel(NovelRequest $request): bool
    {
        try {
            $handedOver = Cache::lock(self::CREATE_LOCK, 10)->block(5, function () use ($request) {
                $next = NovelRequestFollower::where('novel_request_id', $request->id)->orderBy('created_at')->orderBy('id')->first();
                if ($next === null) {
                    return false;
                }
                $request->forceFill(['user_id' => (int) $next->user_id])->save();
                $next->delete();

                return true;
            });
        } catch (LockTimeoutException) {
            throw new RequestRefused(503, self::BUSY, 'busy');
        }

        if (!$handedOver) {
            $this->delete($request);
        }

        return $handedOver;
    }

    /**
     * Approve a pending request: add the series and start downloading.
     * $data: candidate (index into the shortlist), force, url + title (an
     * admin-supplied source), novel_id (a series the admin added).
     *
     * @return array{request: NovelRequest, novel: Novel, created: bool}
     *
     * @throws RequestRefused
     */
    public function approve(NovelRequest $request, User $admin, array $data): array
    {
        if (!$request->isPending()) {
            throw new RequestRefused(409, 'Only a pending request can be approved.', 'not_pending', ['status' => $request->displayStatus()]);
        }

        if (isset($data['novel_id'])) {
            $novel = Novel::find((int) $data['novel_id']);
            if ($novel === null) {
                throw new RequestRefused(422, 'That series is not in the library.', 'unknown_novel');
            }

            return ['request' => $this->markApproved($request, $admin, $novel), 'novel' => $novel, 'created' => false];
        }

        if (isset($data['url'])) {
            $title = trim((string) ($data['title'] ?? '')) ?: (string) $request->title;
            $url = (string) $data['url'];
        } else {
            $candidates = $request->candidateList();
            if ($candidates === []) {
                throw new RequestRefused(422, 'This request has no source to add it from. Send the url of the series (and a title), or the novel_id of a series you added yourself.', 'needs_source');
            }
            $index = (int) ($data['candidate'] ?? 0);
            if (!isset($candidates[$index])) {
                throw new RequestRefused(422, 'There is no candidate ' . $index . ' on this request.', 'unknown_candidate');
            }
            $title = trim((string) ($candidates[$index]['title'] ?? '')) ?: (string) $request->title;
            $url = (string) $candidates[$index]['url'];
        }

        try {
            $outcome = app(AddSeries::class)->add($title, $url, (bool) ($data['force'] ?? false));
        } catch (LockTimeoutException) {
            throw new RequestRefused(503, \App\Http\Controllers\Api\V1\AddNovelController::LOCK_BUSY, 'busy');
        }

        if ($outcome['result'] === AddSeries::DUPLICATE) {
            throw new RequestRefused(409, DuplicateSeries::message($outcome['novel']), 'duplicate', [
                'duplicate' => DuplicateSeries::describe($outcome['novel']),
            ]);
        }

        $novel = $outcome['novel'];
        $created = $outcome['result'] === AddSeries::CREATED;
        $request = $this->markApproved($request, $admin, $novel);

        if ($created) {
            // The chapter list, then a first download (as "check for new chapters").
            try {
                app(NovelCheck::class)->start($novel);
            } catch (\Throwable $e) {
                Log::warning("Request {$request->id}: the chapter check for novel {$novel->id} did not start: " . $e->getMessage());
            }
        }

        return ['request' => $request, 'novel' => $novel, 'created' => $created];
    }

    private function markApproved(NovelRequest $request, User $admin, Novel $novel): NovelRequest
    {
        $request->forceFill([
            'status' => NovelRequest::STATUS_APPROVED,
            'novel_id' => (int) $novel->id,
            'decided_by' => (int) $admin->id,
            'decided_at' => now(),
            'decline_reason' => null,
        ])->save();
        static::forgetPendingCount();

        $this->notify(fn() => $this->notifier->approved($request), $request);

        return $request;
    }

    /** @throws RequestRefused */
    public function decline(NovelRequest $request, User $admin, ?string $reason): NovelRequest
    {
        if (!$request->isPending()) {
            throw new RequestRefused(409, 'Only a pending request can be declined.', 'not_pending', ['status' => $request->displayStatus()]);
        }

        $request->forceFill([
            'status' => NovelRequest::STATUS_DECLINED,
            'decided_by' => (int) $admin->id,
            'decided_at' => now(),
            'decline_reason' => static::clean($reason),
        ])->save();
        static::forgetPendingCount();

        $this->notify(fn() => $this->notifier->declined($request), $request);

        return $request;
    }

    /** Remove a request, its feed items and its followers. */
    public function delete(NovelRequest $request): void
    {
        RequestNotification::where('novel_request_id', $request->id)->delete();
        NovelRequestFollower::where('novel_request_id', $request->id)->delete();
        $request->delete();
        static::forgetPendingCount();
    }

    /**
     * Re-evaluate a manga shortlist in the background, on the `commands`
     * queue (as AddSeries queues novel:metadata): ranking makes one slow
     * source call per candidate and can take minutes, which must not hold a
     * web worker after the response. The job records its own failures; on
     * the sync queue it runs here, and nothing it throws fails the request.
     */
    public function queueRanking(NovelRequest $request): void
    {
        try {
            Bus::dispatch((new RankRequestCandidates((int) $request->id))->onQueue('commands'));
        } catch (\Throwable $e) {
            Log::warning("Request {$request->id}: ranking not queued: " . $e->getMessage());
        }
    }

    /**
     * Fill a request made from the Discover shelf with the other sources'
     * hits for its title, then rank it (FindRequestCandidates), on the
     * `commands` queue. Asking every source takes up to a minute, so on the
     * sync queue (local development) it runs after the response rather than
     * holding the reader's POST. Nothing it throws fails the request.
     */
    public function queueFindCandidates(NovelRequest $request, array $altTitles = []): void
    {
        try {
            $job = new FindRequestCandidates((int) $request->id, array_values($altTitles));
            if (config('queue.default') === 'sync') {
                Bus::dispatchAfterResponse($job);
            } else {
                Bus::dispatch($job->onQueue('commands'));
            }
        } catch (\Throwable $e) {
            Log::warning("Request {$request->id}: finding candidates not queued: " . $e->getMessage());
        }
    }

    /**
     * Approved requests whose series has a downloaded chapter become
     * available (once): available_at is set and the requester told.
     * Returns how many became available.
     */
    public function markAvailable(): int
    {
        $count = 0;
        $candidates = NovelRequest::where('status', NovelRequest::STATUS_APPROVED)
            ->whereNull('available_at')
            ->whereNotNull('novel_id')
            ->whereExists(fn($q) => $q->selectRaw('1')->from('novel_chapters')
                ->whereColumn('novel_chapters.novel_id', 'novel_requests.novel_id')
                ->where('novel_chapters.status', 1)
                ->where('novel_chapters.blacklist', 0)
                ->whereNull('novel_chapters.deleted_at'))
            ->get();

        foreach ($candidates as $request) {
            // Only the run that sets it tells anyone.
            $updated = NovelRequest::whereKey($request->id)->whereNull('available_at')->update(['available_at' => now()]);
            if ($updated !== 1) {
                continue;
            }
            $count++;
            $this->notify(fn() => $this->notifier->available($request->fresh()), $request);
        }

        return $count;
    }

    /** A notification never fails the request (each channel also guards itself). */
    private function notify(\Closure $send, NovelRequest $request): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning("Request {$request->id}: a notification failed: " . $e->getMessage());
        }
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
