<?php

namespace App\Jobs;

use App\NovelRequest;
use App\Services\Requests\CandidateEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Evaluate and rank a manga request's shortlist (CandidateEvaluator), on the
 * `commands` queue once the request is made (or the admin's "rerank"). One
 * ranking per request at a time; a request without a ranking keeps its
 * candidates in search order (candidates_ranked_at null) and can be reranked.
 */
class RankRequestCandidates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $requestId) {}

    public function handle(CandidateEvaluator $evaluator): void
    {
        @set_time_limit($this->timeout);

        $lock = Cache::lock("request_rank_{$this->requestId}", $this->timeout);
        if (!$lock->get()) {
            return; // already being ranked
        }
        try {
            $request = NovelRequest::find($this->requestId);
            if ($request !== null) {
                $evaluator->rank($request);
            }
        } catch (\Throwable $e) {
            // Recorded, not rethrown: on the sync queue a rethrow would turn
            // the request's POST into a 500.
            Log::warning("Ranking request {$this->requestId} failed: " . $e->getMessage());
        } finally {
            $lock->release();
        }
    }
}
