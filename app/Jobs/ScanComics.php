<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * "Scan now" on the Manage comics page (and a grab imported by hand):
 * `comics:scan` on the commands queue. The scan takes its own lock, so it
 * never runs twice at once with the scheduled one.
 */
class ScanComics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('commands');
    }

    public function handle(): void
    {
        Artisan::call('comics:scan');
    }
}
