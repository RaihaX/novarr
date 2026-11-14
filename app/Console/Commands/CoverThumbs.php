<?php

namespace App\Console\Commands;

use App\Novel;
use App\Services\CoverThumbnails;
use Illuminate\Console\Command;

/**
 * Backfill (and self-heal) the small cover copies for every novel's current
 * cover. Existing thumbnails are skipped, so it is safe to re-run.
 */
class CoverThumbs extends Command
{
    protected $signature = 'covers:thumbs {--force : Regenerate thumbnails that already exist}';

    protected $description = 'Generate the sm/md cover thumbnails for every novel cover that lacks them.';

    public function handle(CoverThumbnails $thumbnails): int
    {
        $force = (bool) $this->option('force');
        $generated = $skipped = 0;
        $failed = [];

        Novel::with('file')->select(['id', 'name'])->orderBy('id')->chunkById(100, function ($novels) use ($thumbnails, $force, &$generated, &$skipped, &$failed) {
            foreach ($novels as $novel) {
                $path = $novel->file?->file_path;
                if (!$path) {
                    continue;
                }

                $complete = collect(array_keys(CoverThumbnails::SIZES))
                    ->every(fn(string $size) => $thumbnails->exists($path, $size));
                if ($complete && !$force) {
                    $skipped++;
                } elseif ($thumbnails->generate($path, $force)) {
                    $generated++;
                } else {
                    $failed[] = $novel->name;
                }
            }
        });

        $this->info(sprintf('Cover thumbnails: %d generated, %d skipped, %d failed.', $generated, $skipped, count($failed)));
        foreach ($failed as $name) {
            $this->warn("  Failed: {$name} (the original cover is still served)");
        }

        return self::SUCCESS;
    }
}
