<?php

namespace App\Jobs;

use App\Console\Commands\GenerateePub;
use App\Mail\SendToKindle;
use App\Novel;
use App\NovelChapter;
use App\Services\SendEpub;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One "Send to my Kindle / email" (App\Services\SendEpub): the ePub is built
 * first when it is missing or older than the newest downloaded chapter (a
 * book's ePub is its original file), then mailed as an attachment. The
 * outcome is written to the reader's send state; nothing is retried (the
 * reader presses the button again).
 */
class SendEpubToReader implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    /** Amazon takes up to 50 MB per email; Resend 40 MB in all, base64 adding about a third. */
    public const MAX_MB_KINDLE = 50;
    public const MAX_MB_RESEND = 28;

    public function __construct(public int $userId, public int $novelId, public string $to)
    {
    }

    public function handle(): void
    {
        SendEpub::put($this->userId, $this->novelId, SendEpub::SENDING, $this->to);
        try {
            $this->send();
            SendEpub::put($this->userId, $this->novelId, SendEpub::SENT, $this->to, "Sent to {$this->to}. It arrives in a few minutes.");
        } catch (\Throwable $e) {
            $message = $e instanceof \RuntimeException && $e->getCode() === 422
                ? $e->getMessage()
                : 'It could not be sent. Try again later.';
            Log::warning("Send ePub of novel {$this->novelId} for user {$this->userId} failed: " . $e->getMessage());
            SendEpub::put($this->userId, $this->novelId, SendEpub::FAILED, $this->to, $message);
        }
    }

    public function failed(?\Throwable $e): void
    {
        SendEpub::put($this->userId, $this->novelId, SendEpub::FAILED, $this->to, 'It could not be sent. Try again later.');
    }

    private function send(): void
    {
        $novel = Novel::find($this->novelId);
        if ($novel === null) {
            throw new \RuntimeException('This series is no longer in the library.', 422);
        }
        if ($novel->isManga()) {
            throw new \RuntimeException('A manga has no ePub to send.', 422);
        }

        $path = GenerateePub::epubPath($novel);
        if (!$novel->isBook() && $this->needsBuild($novel, $path)) {
            Artisan::call('novel:epub', ['novel' => $novel->id]);
            clearstatcache(true, $path);
        }
        if (!is_file($path)) {
            throw new \RuntimeException($novel->isBook()
                ? "This book's file is missing from the library."
                : 'The ePub could not be built: this novel may have no downloaded chapters yet.', 422);
        }

        $mb = filesize($path) / 1024 / 1024;
        $limit = config('mail.default') === 'resend' ? self::MAX_MB_RESEND : self::MAX_MB_KINDLE;
        if ($mb > $limit) {
            throw new \RuntimeException(sprintf('The ePub is %.0f MB, too large to email (the limit is %d MB). Download it from the page instead.', $mb, $limit), 422);
        }

        Mail::to($this->to)->send(new SendToKindle($path, (string) $novel->name, GenerateePub::epubFilename($novel)));
        Log::info('ePub sent by email', ['user' => $this->userId, 'novel' => $this->novelId, 'mb' => round($mb, 1)]);
    }

    private function needsBuild(Novel $novel, string $path): bool
    {
        if (!is_file($path)) {
            return true;
        }
        $builtAt = $novel->epub_generated ?? Carbon::createFromTimestamp(filemtime($path));
        $newest = NovelChapter::where('novel_id', $novel->id)->where('status', 1)->max('download_date');

        return $newest !== null && Carbon::parse($newest)->gt($builtAt);
    }
}
