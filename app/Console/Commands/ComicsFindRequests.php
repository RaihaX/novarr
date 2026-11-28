<?php

namespace App\Console\Commands;

use App\Comics\SuwayomiComicFinder;
use App\Novel;
use App\NovelRequest;
use App\Services\Requests\Requests;
use Illuminate\Console\Command;

/**
 * Pending comic requests looked up on the Suwayomi comic sources
 * (Requests::findComic()): a clear match is added and starts downloading,
 * other matches become the request's candidates. New requests are looked up
 * when they are made; this catches up the ones made before, or retries.
 */
class ComicsFindRequests extends Command
{
    protected $signature = 'comics:find-requests {request? : One request id (default: every pending comic request)}';

    protected $description = 'Look pending comic requests up on the Suwayomi comic sources and add clear matches';

    public function handle(Requests $requests): int
    {
        if (!SuwayomiComicFinder::enabled()) {
            $this->error('No Suwayomi comic source: set NOVARR_SUWAYOMI_URL and NOVARR_SUWAYOMI_COMIC_SOURCES.');
            return self::FAILURE;
        }

        $query = NovelRequest::query()->where('medium', Novel::MEDIUM_COMIC)->where('status', NovelRequest::STATUS_PENDING)->orderBy('id');
        if ($this->argument('request') !== null) {
            $query->whereKey((int) $this->argument('request'));
        }

        $added = 0;
        foreach ($query->get() as $request) {
            $outcome = $requests->findComic($request);
            if ($outcome !== null) {
                $added++;
                $this->info("#{$request->id} {$request->title}: added as \"{$outcome['novel']->name}\" (novel {$outcome['novel']->id}), downloading");
            } else {
                $count = count($request->fresh()?->candidateList() ?? []);
                $this->line("#{$request->id} {$request->title}: not added ({$count} candidates)");
            }
        }
        $this->info("{$added} added.");

        return self::SUCCESS;
    }
}
