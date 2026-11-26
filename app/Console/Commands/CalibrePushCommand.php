<?php

namespace App\Console\Commands;

use App\Books\BookLibrary;
use App\Books\CalibrePush;
use App\Novel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * books:calibre-push — send books to Calibre (App\Books\CalibrePush).
 *
 *   (no option)  books added in Novarr that Calibre does not have yet, and
 *                sends that failed (hourly, after the Calibre import)
 *   --id=N       these books (also one that came from Calibre: its details)
 *   --dry-run    list what would be sent
 */
class CalibrePushCommand extends Command
{
    protected $signature = 'books:calibre-push {--id=* : Novel ids} {--limit=50} {--dry-run}';

    protected $description = 'Send books added or edited in Novarr to Calibre (two-way sync).';

    public function handle(CalibrePush $push): int
    {
        if (!CalibrePush::enabled()) {
            $this->warn('Calibre sync is off: set NOVARR_CALIBRE_WEB_URL, its username and password (and NOVARR_CALIBRE_WEB_PUSH is not false).');

            return self::SUCCESS;
        }
        $lock = Cache::lock('books:calibre-push', 3600);
        if (!$lock->get()) {
            $this->warn('Another books:calibre-push is running.');

            return self::SUCCESS;
        }

        try {
            $ids = array_filter(array_map('intval', (array) $this->option('id')));
            $books = $ids !== []
                ? Novel::whereIn('id', $ids)->get()
                : Novel::where('medium', Novel::MEDIUM_BOOK)
                    ->where(fn($q) => $q->whereNull('source_ref')->orWhere('source_ref', 'not like', BookLibrary::CALIBRE_REF . '%'))
                    ->where(fn($q) => $q->whereNull('calibre_id')->orWhereNotNull('calibre_sync_error'))
                    ->orderBy('id')
                    ->limit(max(1, (int) $this->option('limit')))
                    ->get();

            $counts = ['uploaded' => 0, 'updated' => 0, 'failed' => 0];
            foreach ($books as $novel) {
                if ($this->option('dry-run')) {
                    $this->line(($novel->calibre_id ? 'update ' : 'upload ') . "#{$novel->id} {$novel->name}");
                    continue;
                }
                $done = $push->pushQuietly($novel);
                $counts[$done ?? 'failed']++;
                $this->line(($done ?? 'FAILED') . " #{$novel->id} {$novel->name}" . ($done === null ? ': ' . $novel->calibre_sync_error : ''));
            }
            if (!$this->option('dry-run')) {
                $this->info("Uploaded {$counts['uploaded']}, updated {$counts['updated']}, failed {$counts['failed']}.");
            }

            return $counts['failed'] > 0 && $counts['uploaded'] + $counts['updated'] === 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
