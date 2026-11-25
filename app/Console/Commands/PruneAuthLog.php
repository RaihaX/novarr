<?php

namespace App\Console\Commands;

use App\AuthEvent;
use Illuminate\Console\Command;

/**
 * Drops sign-in history (App\AuthEvent) older than novarr.auth_log_days
 * (NOVARR_AUTH_LOG_DAYS, default 180; 0 = keep forever), signed-in
 * browser rows past the remember-me lifetime (App\Services\BrowserSessions),
 * expired remember-me rows (App\Services\RememberTokens) and unfinished
 * second steps (App\Services\SecondStep).
 *
 * The addresses recorded with reads (chapter_reads.ip, reading_positions.ip;
 * App\Support\ReadingClient) follow the sign-in history's retention: older
 * than novarr.auth_log_days they are cleared (the read, its device, client
 * and public flag stay). Kept forever with 0, like the history.
 * Daily, from the scheduler (routes/console.php).
 */
class PruneAuthLog extends Command
{
    protected $signature = 'novarr:prune-auth-log';

    protected $description = 'Delete sign-in history, and clear the addresses of reads, older than NOVARR_AUTH_LOG_DAYS (default 180 days)';

    public function handle(): int
    {
        // Remember-me rows unused for 30 days (or a year old), and second
        // steps nobody finished.
        app(\App\Services\RememberTokens::class)->prune();
        \App\Services\SecondStep::sweep();
        // Signed-in browsers past the remember-me lifetime cannot come back.
        $browsers = app(\App\Services\BrowserSessions::class)->prune();
        if ($browsers > 0) {
            $this->info("Forgot {$browsers} signed-in " . ($browsers === 1 ? 'browser' : 'browsers') . ' not seen within the remember-me lifetime.');
        }

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

        $cleared = $this->clearReadAddresses($cutoff);
        $this->info("Cleared the address of {$cleared} " . ($cleared === 1 ? 'read' : 'reads') . " older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * chapter_reads.ip / reading_positions.ip older than $cutoff → NULL.
     * Reads one reader at a time, so each UPDATE is a (user_id, read_at)
     * range on the index rather than a scan of the whole table. Returns
     * the rows changed.
     */
    private function clearReadAddresses(\Carbon\CarbonInterface $cutoff): int
    {
        $cleared = 0;
        foreach (\App\User::query()->pluck('id') as $userId) {
            $cleared += \Illuminate\Support\Facades\DB::table('chapter_reads')
                ->where('user_id', $userId)
                ->where('read_at', '<', $cutoff)
                ->whereNotNull('ip')
                ->update(['ip' => null]);
        }
        // One row per reader and series: a small table.
        $cleared += \Illuminate\Support\Facades\DB::table('reading_positions')
            ->where('recorded_at', '<', $cutoff)
            ->whereNotNull('ip')
            ->update(['ip' => null]);

        return $cleared;
    }
}
