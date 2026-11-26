<?php

namespace App\Console\Commands;

use App\Books\CalibreImport;
use App\Books\CalibreWeb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Import books from the owner's Calibre-Web (App\Books\CalibreImport).
 *
 *   books:calibre-import            books added to Calibre since the last run
 *                                   (the first run only sets the starting point)
 *   books:calibre-import --all      the whole library (English ePubs; skips what is in)
 *   books:calibre-import --limit=N  stop after N imports
 *   books:calibre-import --dry-run  list, import nothing
 *
 * After a run that imported something, the series inference runs over the
 * whole library (App\Books\BookSeries::infer).
 */
class CalibreImportCommand extends Command
{
    protected $signature = 'books:calibre-import
        {--all : Every book in Calibre, not only the new ones}
        {--limit= : Stop after this many imports}
        {--dry-run : Show what would be imported}';

    protected $description = 'Import books from Calibre-Web into the Books library';

    public function handle(CalibreImport $import, CalibreWeb $calibre): int
    {
        if (!CalibreWeb::enabled()) {
            $this->info('Calibre-Web is not set up (NOVARR_CALIBRE_WEB_URL); nothing to do.');

            return self::SUCCESS;
        }
        if ($problem = $calibre->problem()) {
            $this->error($problem);

            return self::FAILURE;
        }

        // One run at a time (the hourly sync and a manual --all must not race).
        $lock = Cache::lock('books_calibre_import', 6 * 3600);
        if (!$lock->get()) {
            $this->warn('Another Calibre import is running.');

            return self::SUCCESS;
        }

        try {
            $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
            $counts = $import->sync((bool) $this->option('all'), $limit, (bool) $this->option('dry-run'),
                function (array $entry, array $outcome) {
                    $line = sprintf('%-8s %s — %s', $outcome['result'], $entry['title'], implode(', ', (array) $entry['authors']));
                    if ($outcome['result'] !== CalibreImport::IMPORTED) {
                        $line .= " ({$outcome['message']})";
                    }
                    $outcome['result'] === CalibreImport::FAILED ? $this->warn($line) : $this->line($line);
                });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        if ($counts['mark_set']) {
            $this->info('Starting point set: books added to Calibre from now on are imported. Use --all for the whole library.');
        }
        $this->info("Imported {$counts['imported']}, skipped {$counts['skipped']}, failed {$counts['failed']}.");

        // Each book was grouped with its author's books as it arrived; one
        // pass over the whole library catches authors written differently.
        if ($counts['imported'] > 0 && !$this->option('dry-run')) {
            $series = \App\Books\BookSeries::infer();
            $this->info("Series: {$series['assigned']} books grouped, {$series['cleared']} cleared ({$series['series']} inferred series).");
        }

        return $counts['failed'] > 0 && $counts['imported'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
