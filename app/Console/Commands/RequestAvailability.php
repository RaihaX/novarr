<?php

namespace App\Console\Commands;

use App\Services\Requests\RequestFeed;
use App\Services\Requests\Requests;
use Illuminate\Console\Command;

/**
 * Every ten minutes (routes/console.php): approved reader requests whose
 * series has at least one downloaded chapter become available, and each
 * requester is told once (feed item + email). Also drops notification-feed
 * items older than RequestFeed::KEEP_DAYS.
 */
class RequestAvailability extends Command
{
    protected $signature = 'requests:availability';

    protected $description = 'Mark approved reader requests available once their series has a downloaded chapter, and tell the requesters.';

    public function handle(Requests $requests): int
    {
        $available = $requests->markAvailable();
        $pruned = RequestFeed::prune();

        $this->info("{$available} request(s) became available; {$pruned} old notification(s) removed.");

        return self::SUCCESS;
    }
}
