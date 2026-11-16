<?php

namespace App\Jobs;

use App\Services\Discover;
use App\Services\DiscoverSearch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * One background Discover browse/search (see App\Services\DiscoverSearch).
 * Whatever happens, the search ends done or failed, never stuck running:
 * an exception is recorded as failed here, and failed() covers what the
 * worker kills (timeout).
 */
class RunDiscoverSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** A FlareSolverr fetch may take a couple of minutes; the search is reported stale after five. */
    public int $timeout = 300;

    public function __construct(public string $searchId) {}

    public function handle(DiscoverSearch $searches, Discover $discover): void
    {
        // Runs after the response in the web process: waiting on the source must not hit PHP's time limit.
        @set_time_limit($this->timeout);

        try {
            $searches->run($this->searchId, $discover);
        } catch (\Throwable $e) {
            // Recorded, not rethrown: the outcome is the record, and on the
            // sync queue a rethrow would turn the POST into a 500.
            Log::warning("Discover search {$this->searchId} threw: " . $e->getMessage());
            $searches->fail($this->searchId);
        }
    }

    public function failed(?\Throwable $e): void
    {
        app(DiscoverSearch::class)->fail($this->searchId);
    }
}
