<?php

namespace App\Jobs;

use App\Downloads\WantedSearcher;
use App\DownloadWanted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Search one wanted item now (App\Downloads\WantedSearcher, automatic):
 * when it becomes wanted, or a grab of it failed. The daily cap still
 * applies; an item already downloading is left alone.
 */
class SearchWanted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $wantedId)
    {
    }

    public function handle(WantedSearcher $searcher): void
    {
        $row = DownloadWanted::find($this->wantedId);
        if ($row !== null && $row->monitored) {
            $searcher->search($row, true);
        }
    }
}
