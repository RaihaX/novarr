<?php

namespace App\Downloads;

use App\DownloadEvent;
use App\DownloadGrab;
use App\DownloadWanted;
use App\Novel;
use App\NovelRequest;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Support\Facades\Log;

/**
 * One search for a wanted item (App\DownloadWanted), automatic (the
 * scheduled `downloads:search-wanted`, the job when an item becomes wanted
 * or a grab of it failed) or the owner's "Search now":
 *
 *   1. skipped when it is gone or no longer wanted, while a grab of it is
 *      still downloading or waiting to be imported, and (automatic only)
 *      once today's search cap is reached (App\Downloads\SearchBudget);
 *   2. Prowlarr is searched (App\Downloads\DownloadSearch: the results stay
 *      on the server, for the interactive search too);
 *   3. with auto_grab, the first confident result (DownloadProfile::picks():
 *      not blocklisted, exact title and author, an allowed format…) is
 *      grabbed (auto); a client that refuses it moves on to the next one;
 *   4. nothing confident but results: needs_choice (the owner picks on the
 *      item's page; told once, App\Console\Commands\SearchWantedCommand's
 *      digest); nothing at all: wait;
 *   5. the next search follows the backoff (WantedList::nextSearch()).
 *
 * @return array{status: string, message: string, grab?: DownloadGrab, search_id?: string}
 *   status: grabbed | needs_choice | nothing | error | busy | capped | gone
 */
class WantedSearcher
{
    public function __construct(private DownloadSearch $search, private Grabber $grabber)
    {
    }

    public function search(DownloadWanted $row, bool $automatic = true): array
    {
        $request = $row->kind === DownloadGrab::KIND_EBOOK ? NovelRequest::find($row->novel_request_id) : null;
        $novel = $row->kind === DownloadGrab::KIND_AUDIOBOOK ? Novel::find($row->novel_id) : null;
        if (($row->kind === DownloadGrab::KIND_EBOOK && ($request === null || !$request->isPending() || !$request->isWanted()))
            || ($row->kind === DownloadGrab::KIND_AUDIOBOOK && ($novel === null || \App\Audiobook::where('novel_id', $novel->id)->exists()))) {
            $row->delete();

            return ['status' => 'gone', 'message' => 'It is no longer wanted.'];
        }
        if (($busy = $row->busyGrab()) !== null) {
            return ['status' => 'busy', 'message' => 'A download of it is under way: ' . Downloads::statusLine($busy) . '.'];
        }
        if ($automatic && !SearchBudget::available()) {
            return ['status' => 'capped', 'message' => 'Today\'s search limit (' . DownloadProfile::searchesPerDay() . ') is reached; it is searched tomorrow.'];
        }

        try {
            $search = $this->search->run($row->kind, '', $request, $novel);
        } catch (DownloadFailed $e) {
            $row->forceFill([
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'last_searched_at' => now(),
                // A failed search does not climb the backoff: try again in an hour.
                'next_search_at' => now()->addHour(),
            ])->save();

            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $searches = $row->searches + 1;
        $results = array_values(array_filter($search['results'], fn($r) => !($r['blocklisted'] ?? false)));
        $row->forceFill([
            'searches' => $searches,
            'last_searched_at' => now(),
            'next_search_at' => WantedList::nextSearch($searches),
            'results_count' => count($results),
            'last_error' => null,
        ]);

        if (DownloadProfile::autoGrab()) {
            $wanted = DownloadSearch::wanted($request, $novel);
            foreach (DownloadProfile::picks($results, $row->kind, $wanted) as $pick) {
                try {
                    $grab = $this->grabber->grab($pick, $row->kind, $request, $novel, $this->owner(), true, $row);
                } catch (DownloadFailed $e) {
                    Log::info("Downloads: automatic grab of “{$pick['title']}” refused: " . $e->getMessage());
                    $row->last_error = mb_substr($e->getMessage(), 0, 1000);

                    continue;
                }
                $row->forceFill(['needs_choice' => false, 'notified_at' => null])->save();

                return ['status' => 'grabbed', 'message' => "Grabbed “{$grab->title}” ({$pick['why']}).", 'grab' => $grab, 'search_id' => $search['id']];
            }
        }

        if ($results !== []) {
            $first = !$row->needs_choice;
            $row->forceFill(['needs_choice' => true])->save();
            if ($first) {
                DownloadEvent::log(DownloadEvent::NEEDS_CHOICE, $row->title(), count($results) . ' results, none a clear match to grab alone.', null, $row);
            }

            return ['status' => 'needs_choice', 'message' => 'Needs your choice: ' . count($results) . ' ' . (count($results) === 1 ? 'result' : 'results') . '.', 'search_id' => $search['id']];
        }
        $row->forceFill(['needs_choice' => false])->save();

        return ['status' => 'nothing', 'message' => 'Nothing found; searched again ' . $row->next_search_at->diffForHumans() . '.', 'search_id' => $search['id']];
    }

    private function owner(): ?User
    {
        try {
            return User::find(CurrentReader::ownerId());
        } catch (\Throwable) {
            return null;
        }
    }
}
