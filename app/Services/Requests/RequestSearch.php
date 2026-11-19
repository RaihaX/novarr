<?php

namespace App\Services\Requests;

use App\Http\Controllers\Api\V1\DiscoverController;
use App\Jobs\RunRequestSearch;
use App\Services\Discover;
use App\Sources\Source;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A reader's search for something to request (POST/GET
 * /api/v1/requests/searches): one query string sent to EVERY searchable
 * source of the medium — never a URL — run in the background by
 * RunRequestSearch after the POST's response, like DiscoverSearch.
 *
 * Limits (config novarr.requests.*), checked in this order on start():
 *   - one running search per account                 → 429 search_running
 *   - searches_per_window per search_window_minutes  → 429 search_rate_limited
 *   - max_concurrent_searches across ALL accounts    → 429 search_busy
 *
 * Concurrency slots are cache entries added atomically (Cache::add):
 * "request_search_slot_{n}" for n < max_concurrent_searches, plus
 * "request_search_user_{id}" per account, each holding the search id. The
 * job frees both when it ends (done, failed or thrown); every slot also
 * expires on its own after slotSeconds() (the time budget plus a grace),
 * so a worker that dies can never keep one forever. A record still running
 * past the same time is reported failed and frees its slots.
 *
 * The record (cache "request_search_{id}", kept RECORD_TTL_MINUTES):
 * {id, user_id, status: running|done|failed, message, query, medium,
 * groups, skipped_sources, sources_asked, created_at, finished_at}. A
 * request made from a search names this record and a group key; the
 * server copies that group's hits from here (Requests::createFromSearch()).
 */
class RequestSearch
{
    public const RECORD_TTL_MINUTES = 30;

    /** Seconds a slot outlives the time budget (a source call already under way may overrun it). */
    public const SLOT_GRACE_SECONDS = 120;

    /** Work groups kept per search. */
    public const MAX_RESULTS = 60;

    public const BUSY = 'Search is busy right now. Try again in a moment.';

    public const RUNNING = 'You already have a search running. Wait for it to finish.';

    public const TOO_SLOW = 'The search took too long. Try again.';

    public const JOB_FAILED = 'The search failed. Try again later.';

    /** Seconds a client is told to wait when every slot is taken. */
    public const BUSY_RETRY_SECONDS = 15;

    public static function key(string $id): string
    {
        return "request_search_{$id}";
    }

    public static function slotKey(int $n): string
    {
        return "request_search_slot_{$n}";
    }

    public static function userSlotKey(int $userId): string
    {
        return "request_search_user_{$userId}";
    }

    public static function isId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    public static function budgetSeconds(): int
    {
        return max(1, (int) config('novarr.requests.search_budget_seconds', 90));
    }

    /** How long a slot (and a running record) lasts without its job. */
    public static function slotSeconds(): int
    {
        return static::budgetSeconds() + self::SLOT_GRACE_SECONDS;
    }

    public static function maxConcurrent(): int
    {
        return max(1, (int) config('novarr.requests.max_concurrent_searches', 3));
    }

    /**
     * Take the slots, record the search and queue it. Returns the record.
     *
     * @throws RequestRefused 429 when a limit is reached
     */
    public function start(int $userId, string $query, string $medium): array
    {
        $id = bin2hex(random_bytes(16));
        $ttl = static::slotSeconds();

        // One running search per account.
        if (!Cache::add(static::userSlotKey($userId), $id, $ttl)) {
            throw new RequestRefused(429, self::RUNNING, 'search_running', [], ['Retry-After' => '5']);
        }

        // The account's rate limit.
        $limiterKey = 'request-search|' . $userId;
        $max = max(1, (int) config('novarr.requests.searches_per_window', 12));
        if (RateLimiter::tooManyAttempts($limiterKey, $max)) {
            $this->forgetIfMine(static::userSlotKey($userId), $id);
            $wait = max(1, RateLimiter::availableIn($limiterKey));
            $minutes = (int) ceil($wait / 60);
            throw new RequestRefused(429, 'Too many searches. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.', 'search_rate_limited', [], ['Retry-After' => (string) $wait]);
        }

        // A slot of the whole server.
        $slot = null;
        for ($n = 0; $n < static::maxConcurrent(); $n++) {
            if (Cache::add(static::slotKey($n), $id, $ttl)) {
                $slot = $n;
                break;
            }
        }
        if ($slot === null) {
            $this->forgetIfMine(static::userSlotKey($userId), $id);
            throw new RequestRefused(429, self::BUSY, 'search_busy', [], ['Retry-After' => (string) self::BUSY_RETRY_SECONDS]);
        }

        RateLimiter::hit($limiterKey, max(1, (int) config('novarr.requests.search_window_minutes', 10)) * 60);

        $record = [
            'id' => $id,
            'user_id' => $userId,
            'status' => 'running',
            'message' => null,
            'query' => $query,
            'medium' => $medium,
            'groups' => null,
            'skipped_sources' => [],
            'sources_asked' => [],
            'slot' => $slot,
            'created_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];
        $this->store($record);

        try {
            RunRequestSearch::dispatchAfterResponse($id);
        } catch (\Throwable $e) {
            // Never ran (the queue is unreachable): nothing may stay held.
            $this->release($record);
            Cache::forget(static::key($id));
            throw $e;
        }

        return $this->record($id) ?? $record;
    }

    /**
     * The record, or null when unknown or expired. A record running longer
     * than slotSeconds() is stored (and answered) as failed, its slots freed.
     */
    public function find(string $id): ?array
    {
        if (!static::isId($id)) {
            return null;
        }
        $record = $this->record($id);
        if ($record === null) {
            return null;
        }

        if ($record['status'] === 'running'
            && Carbon::parse($record['created_at'])->lte(now()->subSeconds(static::slotSeconds()))) {
            $record = array_merge($record, ['status' => 'failed', 'message' => self::TOO_SLOW, 'finished_at' => now()->toIso8601String()]);
            $this->store($record);
            $this->release($record);
        }

        return $record;
    }

    /**
     * The searchable sources of a medium, in search order: config
     * novarr.requests.source_order first, then Discover order (hidden
     * Discover sources and Suwayomi's language / excluded-source settings
     * apply, as on Discover), at most max_sources_per_search.
     *
     * @return array<string, Source>
     */
    public function sources(Discover $discover, string $medium): array
    {
        $sources = array_filter($discover->sourcesFor($medium), fn(Source $s) => in_array('search', $s->discoverLists(), true));
        $order = CandidateRanking::sourceOrder((array) config('novarr.requests.source_order', []), array_keys($sources));
        uksort($sources, fn($a, $b) => $order[$a] <=> $order[$b]);

        return array_slice($sources, 0, max(1, (int) config('novarr.requests.max_sources_per_search', 12)), true);
    }

    /** Run a recorded search (the job's work); a no-op when it is gone or no longer running. */
    public function run(string $id, Discover $discover): void
    {
        $record = $this->record($id);
        if ($record === null || $record['status'] !== 'running') {
            return;
        }

        $deadline = now()->getPreciseTimestamp(3) + static::budgetSeconds() * 1000;
        $hits = [];
        $skipped = [];
        $asked = [];
        foreach ($this->sources($discover, (string) $record['medium']) as $key => $source) {
            $key = (string) $key;
            $name = $this->label($source, $key);
            if (now()->getPreciseTimestamp(3) >= $deadline) {
                $skipped[] = ['source' => $key, 'source_name' => $name, 'reason' => 'timeout'];
                continue;
            }
            $asked[] = $key;
            try {
                $items = $source->discover('search', (string) $record['query']);
            } catch (\Throwable $e) {
                Log::warning("Request search {$id}: {$key} threw: " . $e->getMessage());
                $items = null;
            }
            if ($items === null) {
                $skipped[] = ['source' => $key, 'source_name' => $name, 'reason' => 'failed'];
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    $hits[] = ['key' => $key, 'name' => $name, 'item' => DiscoverController::item($item, $key)];
                }
            }
        }

        $this->finish($id, [
            'status' => 'done',
            'message' => null,
            'groups' => static::group($hits, (string) $record['medium'], (string) $record['query']),
            'skipped_sources' => $skipped,
            'sources_asked' => $asked,
        ]);
    }

    /**
     * Hits → works: hits of the same normalised title (Discover::normalizeName)
     * form one group, its title / author / cover / description from the
     * first hit that has one (search order), one hit per source. Groups whose
     * title is exactly the query come first, then those found on more
     * sources, then first-seen order. Pure.
     *
     * @param  array<int, array{key: string, name: string, item: array}>  $hits
     * @return array<int, array>
     */
    public static function group(array $hits, string $medium, string $query): array
    {
        $groups = [];
        $seen = 0;
        foreach ($hits as $hit) {
            $item = $hit['item'];
            $normalized = Discover::normalizeName((string) $item['title']);
            if ($normalized === '' || (string) ($item['url'] ?? '') === '') {
                continue;
            }
            $key = static::groupKey($medium, $normalized);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'title' => (string) $item['title'],
                    'normalized_title' => $normalized,
                    'author' => null,
                    'cover_url' => null,
                    'description' => null,
                    'medium' => $medium,
                    'hits' => [],
                    'seen' => $seen++,
                ];
            }
            if (isset($groups[$key]['hits'][$hit['key']])) {
                continue; // one hit per source
            }
            foreach (['author', 'cover_url', 'description'] as $field) {
                $groups[$key][$field] ??= $item[$field] ?? null;
            }
            $groups[$key]['hits'][$hit['key']] = [
                'source' => $hit['key'],
                'source_name' => $hit['name'],
                'url' => (string) $item['url'],
                'title' => (string) $item['title'],
                'cover_url' => $item['cover_url'] ?? null,
                'chapters' => $item['chapters'] ?? null,
                'status' => $item['status'] ?? null,
            ];
        }

        $wanted = Discover::normalizeName($query);
        $groups = array_values($groups);
        usort($groups, fn(array $a, array $b) => (($b['normalized_title'] === $wanted) <=> ($a['normalized_title'] === $wanted))
            ?: (count($b['hits']) <=> count($a['hits']))
            ?: ($a['seen'] <=> $b['seen']));

        return array_map(function (array $group) {
            unset($group['seen']);
            $group['hits'] = array_values($group['hits']);

            return $group;
        }, array_slice($groups, 0, self::MAX_RESULTS));
    }

    /** A result key: stable for the same work (medium + normalised title) across searches. */
    public static function groupKey(string $medium, string $normalizedTitle): string
    {
        return substr(hash('sha256', $medium . '|' . $normalizedTitle), 0, 16);
    }

    /** End a running search as failed (the job threw or was given up). */
    public function fail(string $id, string $message = self::JOB_FAILED): void
    {
        $this->finish($id, ['status' => 'failed', 'message' => $message]);
    }

    /** Free the record's slots (only those it still holds). */
    public function release(array $record): void
    {
        $id = (string) $record['id'];
        if (isset($record['slot'])) {
            $this->forgetIfMine(static::slotKey((int) $record['slot']), $id);
        }
        $this->forgetIfMine(static::userSlotKey((int) $record['user_id']), $id);
    }

    public function releaseById(string $id): void
    {
        $record = $this->record($id);
        if ($record !== null) {
            $this->release($record);
        }
    }

    private function forgetIfMine(string $key, string $id): void
    {
        try {
            if (Cache::get($key) === $id) {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            // The slot then expires by itself.
        }
    }

    private function label(Source $source, string $key): string
    {
        try {
            return $source->discoverLabel();
        } catch (\Throwable) {
            return $key;
        }
    }

    private function finish(string $id, array $outcome): void
    {
        $record = $this->record($id);
        if ($record === null || $record['status'] !== 'running') {
            Log::info("Request search {$id} finished after it was resolved or expired; outcome dropped.");

            return;
        }

        $this->store(array_merge($record, $outcome, ['finished_at' => now()->toIso8601String()]));
    }

    private function record(string $id): ?array
    {
        $record = Cache::get(static::key($id));

        return is_array($record) ? $record : null;
    }

    private function store(array $record): void
    {
        Cache::put(static::key($record['id']), $record, now()->addMinutes(self::RECORD_TTL_MINUTES));
    }
}
