<?php

namespace App\Console\Commands;

use App\Comics\ComicInbox;
use App\Comics\ComicLibrary;
use Illuminate\Console\Command;

/**
 * Import comics from the comics inbox (App\Comics\ComicInbox).
 *
 *   comics:scan                every settled entry (scheduled every 10 minutes)
 *   comics:scan --path=NAME    one entry of the inbox (its name, or its full path)
 *   comics:scan --dry-run      say what would be imported; import nothing
 */
class ScanComicsCommand extends Command
{
    protected $signature = 'comics:scan
        {--path= : Only this inbox entry (its name or full path)}
        {--dry-run : Show what would be imported, import nothing}';

    protected $description = 'Import comics and graphic novels from the comics inbox';

    public function handle(ComicInbox $inbox): int
    {
        $only = $this->option('path');
        if ($only !== null) {
            $only = trim((string) $only);
            if (str_contains($only, '/') && dirname(rtrim($only, '/')) !== ComicLibrary::inbox()) {
                $this->error('That is not in the comics inbox (' . ComicLibrary::inbox() . ').');

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry-run');
        $counts = $inbox->scan($only ?: null, $dry, function (string $name, string $result, string $message) {
            $line = sprintf('%-12s %s%s', $result, $name, $message !== '' ? " — {$message}" : '');
            $result === 'failed' ? $this->warn($line) : $this->line($line);
        });

        if ($counts['busy']) {
            $this->warn('Another comics scan is running.');

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
