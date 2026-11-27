<?php

namespace App\Console\Commands;

use App\DownloadWanted;
use App\Downloads\DownloadProfile;
use App\Downloads\SearchBudget;
use App\Downloads\WantedList;
use App\Downloads\WantedSearcher;
use App\Services\AdminAlert;
use App\Services\Requests\RequestNotifier;
use Illuminate\Console\Command;

/**
 * The wanted list's automatic searches (App\Downloads\WantedList,
 * WantedSearcher), every 15 minutes:
 *
 *   1. the list is brought in line (new wanted books, books that have an
 *      audiobook now…);
 *   2. the monitored items whose next search is due, soonest first, at most
 *      novarr.downloads.searches_per_run (10), and none once today's
 *      searches_per_day (60) is reached; an item already downloading is
 *      skipped;
 *   3. one email to the owner for the items that newly need a choice
 *      (results, none a clear match), each item at most once.
 *
 * Off (nothing searched) while automatic search is off or Prowlarr is not
 * set up; `--force` searches even then (not past the daily cap).
 */
class SearchWantedCommand extends Command
{
    protected $signature = 'downloads:search-wanted {--force : Search even when automatic search is off}';

    protected $description = 'Search Prowlarr for the wanted books and audiobooks whose search is due';

    public function handle(WantedList $list, WantedSearcher $searcher, AdminAlert $alert): int
    {
        if (!\App\Downloads\Downloads::enabled()) {
            $this->line('Searching downloads is not set up (NOVARR_PROWLARR_URL and NOVARR_PROWLARR_API_KEY).');

            return self::SUCCESS;
        }
        $list->sync();
        if (!DownloadProfile::autoSearch() && !$this->option('force')) {
            $this->line('Automatic search is off.');

            return self::SUCCESS;
        }

        $searched = 0;
        foreach ($list->due(max(0, (int) config('novarr.downloads.searches_per_run', 10))) as $row) {
            if (!SearchBudget::available()) {
                $this->warn('Today\'s search limit (' . DownloadProfile::searchesPerDay() . ') is reached.');
                break;
            }
            $result = $searcher->search($row, true);
            if (!in_array($result['status'], ['busy', 'gone'], true)) {
                $searched++;
            }
            $this->line(sprintf('%-13s %s — %s', $result['status'], $row->title(), $result['message']));
        }

        $this->digest($alert);
        $this->info("Searched {$searched}.");

        return self::SUCCESS;
    }

    /** One email for the items that newly need the owner's choice. */
    private function digest(AdminAlert $alert): void
    {
        $rows = DownloadWanted::where('monitored', true)->where('needs_choice', true)->whereNull('notified_at')->get();
        if ($rows->isEmpty()) {
            return;
        }
        $names = $rows->map(fn(DownloadWanted $r) => "\u{201C}{$r->title()}\u{201D}" . ($r->kind === 'audiobook' ? ' (audiobook)' : ''))->take(10)->implode(', ');
        $more = $rows->count() > 10 ? ' and ' . ($rows->count() - 10) . ' more' : '';
        $alert->send(
            'Downloads need your choice (' . $rows->count() . ')',
            "Novarr found results but no clear match to grab by itself for {$names}{$more}. Pick one on the Downloads page.",
            RequestNotifier::adminBase() . route('downloads.index', ['tab' => 'wanted'], false),
        );
        DownloadWanted::whereIn('id', $rows->pluck('id'))->update(['notified_at' => now()]);
    }
}
