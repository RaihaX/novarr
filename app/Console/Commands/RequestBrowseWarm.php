<?php

namespace App\Console\Commands;

use App\Services\Requests\RequestBrowse;
use Illuminate\Console\Command;

/**
 * Every three hours (routes/console.php): page 1 of every Discover shelf
 * list (RequestBrowse) is fetched afresh, so the first reader to open the
 * Requests page never waits for a cold page. A list that could not be
 * fetched keeps its cached page. Nothing to do when requests or the shelf
 * are off, or no source offers a list.
 */
class RequestBrowseWarm extends Command
{
    protected $signature = 'requests:browse-warm';

    protected $description = "Refresh page 1 of the Requests page's Discover shelf lists.";

    public function handle(RequestBrowse $browse): int
    {
        if (!config('novarr.requests.enabled', true) || !RequestBrowse::enabled()) {
            $this->info('The Discover shelf is off: nothing to refresh.');

            return self::SUCCESS;
        }

        $any = false;
        foreach (RequestBrowse::MEDIA as $medium) {
            foreach ($browse->lists($medium) as $list) {
                $any = true;
                $this->line($browse->refresh($medium, $list['id'], 1)
                    ? "{$medium} {$list['id']}: refreshed."
                    : "{$medium} {$list['id']}: could not be fetched; the cached page stays.");
            }
        }
        if (!$any) {
            $this->info('No source offers a Discover shelf list: nothing to refresh.');
        }

        return self::SUCCESS;
    }
}
