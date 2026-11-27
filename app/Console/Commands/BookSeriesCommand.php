<?php

namespace App\Console\Commands;

use App\Books\BookSeries;
use App\Books\CalibreWeb;
use App\Books\Hardcover;
use Illuminate\Console\Command;

/**
 * Series of the Books library (App\Books\BookSeries).
 *
 *   books:series            guess series for books without one (App\Books\SeriesInference)
 *   books:series --calibre  first take Calibre-Web's series for the books imported from it
 *   books:series --hardcover  then Hardcover's for books without one (--limit=, each book asked once)
 *   books:series --dry-run  count what would change, change nothing
 *
 * Runs nightly (routes/console.php) and after books:calibre-import.
 */
class BookSeriesCommand extends Command
{
    protected $signature = 'books:series
        {--dry-run : Count what would change without saving}
        {--calibre : Backfill series from Calibre-Web for books imported from it}
        {--hardcover : Ask Hardcover for the series of books without one (each book once)}
        {--limit= : Books asked of Hardcover in this run (default novarr.books.hardcover_series_limit)}';

    protected $description = 'Group the Books library into series (from Calibre-Web and from titles)';

    public function handle(CalibreWeb $calibre, Hardcover $hardcover): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[dry run] ' : '';

        if ($this->option('calibre')) {
            if (!CalibreWeb::enabled()) {
                $this->warn('Calibre-Web is not set up (NOVARR_CALIBRE_WEB_URL); skipping --calibre.');
            } elseif ($problem = $calibre->problem()) {
                $this->error($problem);

                return self::FAILURE;
            } else {
                try {
                    $c = BookSeries::backfillFromCalibre($calibre, $dryRun);
                } catch (\Throwable $e) {
                    $this->error('Calibre-Web: ' . $e->getMessage());

                    return self::FAILURE;
                }
                $this->info("{$prefix}Calibre: {$c['seen']} books read, {$c['matched']} in the library, {$c['updated']} series set, {$c['cleared']} cleared.");
            }
        }

        if ($this->option('hardcover')) {
            if (!Hardcover::enabled()) {
                $this->warn('Hardcover is not set up (NOVARR_HARDCOVER_TOKEN); skipping --hardcover.');
            } else {
                $limit = $this->option('limit') !== null ? (int) $this->option('limit') : (int) config('novarr.books.hardcover_series_limit', 150);
                $h = BookSeries::backfillFromHardcover($hardcover, max(0, $limit), $dryRun);
                $this->info("{$prefix}Hardcover: {$h['asked']} books asked, {$h['found']} in a series, {$h['updated']} series set, {$h['failed']} could not be asked.");
            }
        }

        // A dry run after a dry --calibre guesses from the series as stored now.
        $i = BookSeries::infer($dryRun);
        $this->info("{$prefix}Inferred: {$i['books']} books, {$i['assigned']} assigned, {$i['cleared']} cleared, {$i['series']} inferred series.");

        return self::SUCCESS;
    }
}
