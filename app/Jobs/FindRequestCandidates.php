<?php

namespace App\Jobs;

use App\Novel;
use App\NovelRequest;
use App\Services\Discover;
use App\Services\Requests\CandidateEvaluator;
use App\Services\Requests\Requests;
use App\Services\Requests\RequestSearch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fill the shortlist of a request made from the Discover shelf, then rank
 * it (Requests::queueFindCandidates()). Such a request starts with one
 * candidate, the chart's source; the title is looked up on every source of
 * the medium (RequestSearch::collect(): remembered results or a fresh
 * search, no reader slot), the first work whose normalised title is the
 * request's or one of the chart item's alt titles supplies the other
 * sources' hits, appended after the existing candidates (up to
 * Requests::maxCandidates()). A failed lookup is logged and the request
 * ranked as it is. Shares RankRequestCandidates' lock: one shortlist job
 * per request at a time.
 */
class FindRequestCandidates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** @param  string[]  $altTitles */
    public function __construct(public int $requestId, public array $altTitles = []) {}

    public function handle(RequestSearch $searches, Discover $discover, CandidateEvaluator $evaluator): void
    {
        @set_time_limit($this->timeout);

        $lock = Cache::lock("request_rank_{$this->requestId}", $this->timeout);
        if (!$lock->get()) {
            return; // already being ranked
        }
        try {
            $request = NovelRequest::find($this->requestId);
            if ($request === null || !$request->isPending()) {
                return;
            }

            // A comic: the Suwayomi comic sources, and a clear match added.
            if ($request->medium === Novel::MEDIUM_COMIC) {
                app(\App\Services\Requests\Requests::class)->findComic($request);
                return;
            }

            try {
                $this->addCandidates($request, $searches, $discover);
            } catch (\Throwable $e) {
                Log::warning("Finding more sources for request {$this->requestId} failed: " . $e->getMessage());
            }

            if ($request->medium === Novel::MEDIUM_MANGA) {
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

    private function addCandidates(NovelRequest $request, RequestSearch $searches, Discover $discover): void
    {
        $wanted = [];
        foreach (array_merge([(string) $request->title], array_map('strval', $this->altTitles)) as $title) {
            $normalized = Discover::normalizeName($title);
            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }

        $outcome = $searches->collect((string) $request->medium, (string) $request->title, $discover);
        $match = null;
        foreach ((array) $outcome['groups'] as $group) {
            if (isset($wanted[(string) ($group['normalized_title'] ?? '')])) {
                $match = $group;
                break;
            }
        }
        if ($match === null) {
            return;
        }

        $candidates = $request->candidateList();
        $have = array_flip(array_map(fn(array $c) => (string) ($c['source'] ?? ''), $candidates));
        $new = array_values(array_filter((array) $match['hits'], fn(array $hit) => !isset($have[(string) $hit['source']])));
        $room = Requests::maxCandidates() - count($candidates);
        if ($new === [] || $room <= 0) {
            return;
        }

        $request->candidates = array_merge($candidates, Requests::candidatesFromHits($new, $room));
        $request->save();
    }
}
