<?php

namespace App\Console\Commands;

use App\Scraping\MangaFallback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fill manga chapters their own source keeps failing on from the same
 * series on another installed source (App\Scraping\MangaFallback). Hourly
 * (routes/console.php) while novarr.manga.fallback_enabled; by hand any time.
 */
class FillMissingMangaChapters extends Command
{
    protected $signature = 'manga:fill-missing
        {--novel= : Only this series (by id)}
        {--dry-run : List what would be fetched from where; change nothing}
        {--refresh : Search the other sources again now, whatever the cached alternates}
        {--max-minutes= : Stop starting new work after this many minutes (default novarr.manga.fallback_max_minutes)}';

    protected $description = 'Manga only: download chapters the series\' own source keeps failing on (broken pages, empty, 404) from the same series on another installed source. Never for text novels: two translations cannot be matched by chapter number.';

    /** Two runs never overlap (the schedule and a manual run). */
    public const LOCK = 'manga:fill-missing';

    public function handle(MangaFallback $fallback): int
    {
        $novelId = $this->option('novel') !== null && $this->option('novel') !== '' ? (int) $this->option('novel') : null;
        $dryRun = (bool) $this->option('dry-run');

        if (!MangaFallback::enabled() && $novelId === null && !$dryRun) {
            $this->info('The manga fallback is turned off (NOVARR_MANGA_FALLBACK_ENABLED); pass --novel= to run it for one series.');
            return self::SUCCESS;
        }

        $minutes = $this->option('max-minutes') !== null ? max(1, (int) $this->option('max-minutes')) : MangaFallback::maxMinutes();
        $lock = Cache::lock(self::LOCK, ($minutes + 10) * 60);
        if (!$lock->get()) {
            $this->warn('Another manga:fill-missing run is in progress; not starting a second one.');
            Log::info('manga:fill-missing: another run holds the lock; skipped.');
            return self::SUCCESS;
        }

        try {
            if ($dryRun) {
                $this->line('Dry run: nothing is downloaded or saved.');
            }
            $stats = $fallback->run($novelId, $dryRun, (bool) $this->option('refresh'), $minutes, fn(string $line) => $this->line($line));
        } finally {
            $lock->release();
        }

        $summary = $dryRun
            ? "{$stats['planned']} chapter(s) would be fetched for {$stats['novels']} series ({$stats['searched']} source search(es))."
            : "Filled {$stats['filled']} chapter(s) for {$stats['novels']} series; {$stats['failed']} could not be filled; {$stats['searched']} source search(es).";
        $this->info($summary);
        if (!$dryRun && $stats['novels'] > 0) {
            Log::info("manga:fill-missing: {$summary}");
        }

        return self::SUCCESS;
    }
}
