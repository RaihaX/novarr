<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\DiscoverController;
use App\Jobs\RunDiscoverSearch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A background Discover browse/search for the API (POST/GET
 * /api/v1/discover/searches): the blocking GET /discover/browse's work
 * (DiscoverController::listing()) run by RunDiscoverSearch after the POST's
 * response has been sent, so a slow FlareSolverr source cannot hold a
 * request open and the search does not queue behind long scrapes.
 *
 * One cache record per search under an unguessable id, kept TTL_MINUTES:
 * {id, status: running|done|failed, message, result, params, created_at}.
 * A record still running STALE_AFTER_MINUTES after it was created is
 * reported (and stored) as failed: a worker died, or the queue was busy.
 */
class DiscoverSearch
{
    public const TTL_MINUTES = 15;

    public const STALE_AFTER_MINUTES = 5;

    public const TOO_SLOW = 'The source took too long.';

    public const JOB_FAILED = 'The search failed. Try again later.';

    public static function key(string $id): string
    {
        return "api_discover_search_{$id}";
    }

    /** 32 lowercase hex characters: url-safe, unguessable. */
    public static function isId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    /**
     * Record a search for validated browse parameters and queue it; returns
     * the `search` object as it stands after dispatch (the job runs once the
     * response has been sent).
     */
    public function start(array $params): array
    {
        $id = bin2hex(random_bytes(16));

        $this->store([
            'id' => $id,
            'status' => 'running',
            'message' => null,
            'result' => null,
            'params' => $params,
            'created_at' => now()->toIso8601String(),
        ]);

        try {
            // After the response is sent, in this same PHP process: a search then
            // starts at once instead of waiting behind long scrapes on the
            // `commands` queue (or for the Docker stacks' once-a-minute drain).
            RunDiscoverSearch::dispatchAfterResponse($id);
        } catch (\Throwable $e) {
            // The job records its own failures; reaching here means it never
            // ran (the queue is unreachable), so the record must not claim a
            // running search.
            if (($this->record($id)['status'] ?? null) === 'running') {
                Cache::forget(static::key($id));
            }
            throw $e;
        }

        return $this->state($id) ?? static::present(['id' => $id, 'status' => 'failed', 'message' => self::JOB_FAILED]);
    }

    /** The `search` object, or null when unknown or expired. */
    public function state(string $id): ?array
    {
        if (!static::isId($id)) {
            return null;
        }

        $record = $this->record($id);
        if ($record === null) {
            return null;
        }

        if ($record['status'] === 'running'
            && Carbon::parse($record['created_at'])->lte(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            $record = array_merge($record, ['status' => 'failed', 'message' => self::TOO_SLOW]);
            $this->store($record);
        }

        return static::present($record);
    }

    /** Run a recorded search (the job's work); a no-op when it is gone or no longer running. */
    public function run(string $id, Discover $discover): void
    {
        $record = $this->record($id);
        if ($record === null || $record['status'] !== 'running') {
            return;
        }

        $sources = Discover::ofMedium($discover->sources(), \App\Http\Controllers\Api\V1\DiscoverController::medium($record['params']));
        if ($sources === []) {
            $result = ['items' => [], 'page' => 1, 'has_more' => false];
        } elseif (isset($record['params']['source']) && !isset($sources[$record['params']['source']])) {
            // The source was removed from Discover between the POST and the job.
            $result = null;
        } else {
            $result = DiscoverController::listing($discover, $sources, $record['params']);
        }

        $this->finish($id, $result === null
            ? ['status' => 'failed', 'message' => DiscoverController::UPSTREAM_FAILED, 'result' => null]
            : ['status' => 'done', 'message' => null, 'result' => $result]);
    }

    /** End a running search as failed (the job threw or was given up by the queue). */
    public function fail(string $id, string $message = self::JOB_FAILED): void
    {
        $this->finish($id, ['status' => 'failed', 'message' => $message, 'result' => null]);
    }

    /** Store the outcome unless the record expired or was already resolved (e.g. reported stale). */
    private function finish(string $id, array $outcome): void
    {
        $record = $this->record($id);
        if ($record === null || $record['status'] !== 'running') {
            Log::info("Discover search {$id} finished after it was resolved or expired; outcome dropped.");

            return;
        }

        $this->store(array_merge($record, $outcome));
    }

    private function record(string $id): ?array
    {
        $record = Cache::get(static::key($id));

        return is_array($record) ? $record : null;
    }

    /** (Re)store a record; the outcome is kept TTL_MINUTES from now. */
    private function store(array $record): void
    {
        Cache::put(static::key($record['id']), $record, now()->addMinutes(self::TTL_MINUTES));
    }

    /** @return array{id: string, status: string, message: ?string, result: ?array} */
    private static function present(array $record): array
    {
        $done = ($record['status'] ?? null) === 'done';

        return [
            'id' => (string) $record['id'],
            'status' => (string) $record['status'],
            'message' => $record['status'] === 'failed' ? (string) ($record['message'] ?? self::JOB_FAILED) : null,
            'result' => $done ? ($record['result'] ?? null) : null,
        ];
    }
}
