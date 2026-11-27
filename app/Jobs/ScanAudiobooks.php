<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * "Scan now" from the Audiobooks page / POST /api/v1/audiobooks/scan:
 * `audiobooks:scan` on the commands queue (drained every minute by the
 * scheduler). The scan takes its own lock, so it never runs twice at once
 * with the scheduled one. Entries still settling wait for a later scan.
 */
class ScanAudiobooks implements ShouldQueue
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
        Artisan::call('audiobooks:scan');
    }
}
