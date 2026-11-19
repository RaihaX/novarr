<?php

namespace App\Jobs;

use App\Services\Discover;
use App\Services\Requests\RequestSearch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * One reader-request search across every source of a medium (see
 * App\Services\Requests\RequestSearch). Whatever happens the search ends
 * done or failed and its concurrency slots are freed; slots also expire on
 * their own, should the process die before this runs to the end.
 */
class RunRequestSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $searchId) {}

    public function handle(RequestSearch $searches, Discover $discover): void
    {
        // Runs after the response in the web process: the time budget is
        // checked between sources, and one source call may still overrun it.
        @set_time_limit($this->timeout);

        try {
            $searches->run($this->searchId, $discover);
        } catch (\Throwable $e) {
            Log::warning("Request search {$this->searchId} threw: " . $e->getMessage());
            $searches->fail($this->searchId);
        } finally {
            $searches->releaseById($this->searchId);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $searches = app(RequestSearch::class);
        $searches->fail($this->searchId);
        $searches->releaseById($this->searchId);
    }
}
