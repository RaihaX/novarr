<?php

namespace App\Downloads;

use App\DownloadBlock;
use App\DownloadEvent;
use App\DownloadGrab;

/**
 * A grab failed (App\DownloadGrab::fail(): the client reported a failure,
 * it stalled for stall_hours, the finished download had no usable file or
 * did not import, or the owner pressed "Mark failed & search again"),
 * Sonarr style:
 *
 *   1. its release is blocklisted (guid, title + indexer, the reason):
 *      never grabbed automatically again (a manual Grab still can);
 *   2. failed and blocklisted go into the History;
 *   3. when it was for a monitored wanted item, that item is searched
 *      again at once (WantedList::searchSoon(): the job, when automatic
 *      search is on). The search skips blocklisted releases, so the next
 *      best confident one is grabbed; none: it waits for the owner
 *      ("Needs your choice") and the backoff.
 */
class FailedDownloads
{
    public function __construct(private WantedList $wanted)
    {
    }

    /** $releaseAtFault false: Novarr's own problem (a path, the review queue): only the History records it. */
    public function handle(DownloadGrab $grab, bool $releaseAtFault = true): void
    {
        DownloadEvent::log(DownloadEvent::FAILED, $grab->title, $grab->error, $grab);
        if (!$releaseAtFault) {
            return;
        }

        if (!DownloadBlock::where('title', $grab->title)->where('indexer', $grab->indexer)->exists()) {
            DownloadBlock::create([
                'kind' => $grab->kind,
                'guid' => $grab->guid,
                'title' => mb_substr($grab->title, 0, 500),
                'indexer' => $grab->indexer,
                'reason' => $grab->error !== null ? mb_substr($grab->error, 0, 1000) : null,
                'grab_id' => $grab->id,
            ]);
            DownloadEvent::log(DownloadEvent::BLOCKLISTED, $grab->title, 'Not grabbed automatically again.', $grab);
        }

        $row = WantedList::forGrab($grab);
        if ($row !== null && $row->monitored) {
            $this->wanted->searchSoon($row);
        }
    }
}
