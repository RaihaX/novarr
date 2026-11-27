<?php

namespace App\Console\Commands;

use App\Downloads\InboxTidy;
use Illuminate\Console\Command;

/**
 * downloads:tidy — the nightly tidy-up of the download inboxes
 * (App\Downloads\InboxTidy): imported downloads' leftovers once nothing
 * seeds them, and old unreadable audiobook files. Never anything a
 * Transmission torrent uses.
 */
class TidyDownloadsCommand extends Command
{
    protected $signature = 'downloads:tidy {--dry-run : List what would be deleted}';

    protected $description = 'Delete imported downloads no torrent seeds any more, and old unreadable audiobook files.';

    public function handle(InboxTidy $tidy): int
    {
        $result = $tidy->run((bool) $this->option('dry-run'));
        foreach ($result['deleted'] as $line) {
            $this->line(($this->option('dry-run') ? 'would delete ' : 'deleted ') . $line);
        }
        if ($result['problem'] !== null) {
            $this->warn($result['problem']);

            return self::SUCCESS;
        }
        $this->info(($this->option('dry-run') ? '[dry run] ' : '') . count($result['deleted']) . ' deleted, ' . count($result['kept']) . ' kept.');

        return self::SUCCESS;
    }
}
