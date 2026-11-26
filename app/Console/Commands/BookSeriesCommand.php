<?php

namespace App\Console\Commands;

use App\Books\BookSeries;
use App\Books\CalibreWeb;
use Illuminate\Console\Command;

/**
 * Series of the Books library (App\Books\BookSeries).
 *
 *   books:series            guess series for books without one (App\Books\SeriesInference)
 *   books:series --calibre  first take Calibre-Web's series for the books imported from it
 *   books:series --dry-run  count what would change, change nothing
 *
 * Runs nightly (routes/console.php) and after books:calibre-import.
 */
class BookSeriesCommand extends Command
{
    protected $signature = 'books:series
        {--dry-run : Count what would change without saving}
        {--calibre : Backfill series from Calibre-Web for books imported from it}';

    protected $description = 'Group the Books library into series (from Calibre-Web and from titles)';

    public function handle(CalibreWeb $calibre): int
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

        // A dry run after a dry --calibre guesses from the series as stored now.
        $i = BookSeries::infer($dryRun);
        $this->info("{$prefix}Inferred: {$i['books']} books, {$i['assigned']} assigned, {$i['cleared']} cleared, {$i['series']} inferred series.");

        return self::SUCCESS;
    }
}
