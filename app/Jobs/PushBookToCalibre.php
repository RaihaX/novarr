<?php

namespace App\Jobs;

use App\Books\CalibrePush;
use App\Novel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Send one book to Calibre (App\Books\CalibrePush) after it is added or
 * edited in Novarr. One at a time per book; a failure is kept on the book and
 * retried by the hourly books:calibre-push.
 */
class PushBookToCalibre implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;
    public int $tries = 1;
    public int $uniqueFor = 600;

    public function __construct(public int $novelId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->novelId;
    }

    /** Queue a send when the sync is on and the row is a book. */
    public static function for(Novel $novel): void
    {
        if (CalibrePush::enabled() && CalibrePush::applies($novel)) {
            static::dispatch((int) $novel->id)->onQueue('commands');
        }
    }

    public function handle(CalibrePush $push): void
    {
        $novel = Novel::find($this->novelId);
        if ($novel !== null && CalibrePush::applies($novel)) {
            $push->pushQuietly($novel);
        }
    }
}
