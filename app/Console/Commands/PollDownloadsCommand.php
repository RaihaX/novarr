<?php

namespace App\Console\Commands;

use App\Downloads\DownloadPoller;
use App\Downloads\Downloads;
use Illuminate\Console\Command;

/**
 * Follow the grabbed downloads in NZBGet and Transmission
 * (App\Downloads\DownloadPoller), every 2 minutes: progress, finished,
 * failed; a finished ebook goes into the library or the review queue
 * (App\Downloads\BookDownloads). Nothing to follow: no client is asked.
 */
class PollDownloadsCommand extends Command
{
    protected $signature = 'downloads:poll';

    protected $description = 'Check the grabbed downloads in NZBGet / Transmission and take in the finished ebooks';

    public function handle(DownloadPoller $poller): int
    {
        if (!Downloads::enabled() && !\App\DownloadGrab::whereIn('status', [...\App\DownloadGrab::ACTIVE, \App\DownloadGrab::STATUS_COMPLETED])->exists()) {
            $this->line('Searching downloads is not set up (NOVARR_PROWLARR_URL), and nothing is downloading.');

            return self::SUCCESS;
        }
        $counts = $poller->poll();
        if ($counts['busy']) {
            $this->warn('Another downloads poll is running.');

            return self::SUCCESS;
        }
        foreach ($counts['problems'] as $problem) {
            $this->warn($problem);
        }
        $this->info("Checked {$counts['checked']}, finished {$counts['completed']}, imported {$counts['imported']}, failed {$counts['failed']}.");

        return self::SUCCESS;
    }
}
