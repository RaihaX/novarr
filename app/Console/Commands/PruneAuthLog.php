<?php

namespace App\Console\Commands;

use App\AuthEvent;
use Illuminate\Console\Command;

/**
 * Drops sign-in history (App\AuthEvent) older than novarr.auth_log_days
 * (NOVARR_AUTH_LOG_DAYS, default 180; 0 = keep forever). Daily, from the
 * scheduler (routes/console.php).
 */
class PruneAuthLog extends Command
{
    protected $signature = 'novarr:prune-auth-log';

    protected $description = 'Delete sign-in history older than NOVARR_AUTH_LOG_DAYS (default 180 days)';

    public function handle(): int
    {
        $days = (int) config('novarr.auth_log_days', 180);
        if ($days <= 0) {
            $this->info('Sign-in history is kept forever (NOVARR_AUTH_LOG_DAYS=0).');

            return self::SUCCESS;
        }

        $deleted = 0;
        $cutoff = now()->subDays($days);
        // In chunks: one huge DELETE would hold a lock on the table.
        do {
            $ids = AuthEvent::where('occurred_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : AuthEvent::whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Deleted {$deleted} sign-in history " . ($deleted === 1 ? 'entry' : 'entries') . " older than {$days} days.");

        return self::SUCCESS;
    }
}
