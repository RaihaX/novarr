<?php

namespace App\Console\Commands;

use App\Audiobooks\AudiobookInbox;
use App\Audiobooks\AudiobookLibrary;
use Illuminate\Console\Command;

/**
 * Import audiobooks from the drop-in folder (App\Audiobooks\AudiobookInbox).
 *
 *   audiobooks:scan                every settled entry (scheduled every 10 minutes)
 *   audiobooks:scan --path=NAME    one entry of the inbox (its name, or its full path)
 *   audiobooks:scan --dry-run      say what would be imported; move nothing
 *
 * An entry still being copied (its files changed within the last
 * novarr.audiobooks.settle_seconds) waits for a later scan. Without
 * ffprobe the scan stops at once and leaves the inbox alone.
 */
class ScanAudiobooksCommand extends Command
{
    protected $signature = 'audiobooks:scan
        {--path= : Only this inbox entry (its name or full path)}
        {--dry-run : Show what would be imported, import nothing}';

    protected $description = 'Import audiobooks dropped into the audiobooks inbox';

    public function handle(AudiobookInbox $inbox): int
    {
        $only = $this->option('path');
        if ($only !== null) {
            $only = trim((string) $only);
            // A full path must be an entry of the inbox itself.
            if (str_contains($only, '/') && dirname(rtrim($only, '/')) !== AudiobookLibrary::inbox()) {
                $this->error('That is not in the audiobooks inbox (' . AudiobookLibrary::inbox() . ').');

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry-run');
        $counts = $inbox->scan($only ?: null, $dry, function (string $name, string $result, string $message) {
            $line = sprintf('%-12s %s%s', $result, $name, $message !== '' ? " — {$message}" : '');
            $result === 'failed' ? $this->warn($line) : $this->line($line);
        });

        if ($counts['busy']) {
            $this->warn('Another audiobooks scan is running.');

            return self::SUCCESS;
        }
        if ($counts['problem'] !== null) {
            $this->error($counts['problem']);

            return self::FAILURE;
        }

        $this->info(($dry ? 'Dry run: ' : '') . "Imported {$counts['imported']}, failed {$counts['failed']}, waiting {$counts['waiting']}.");

        return self::SUCCESS;
    }
}
