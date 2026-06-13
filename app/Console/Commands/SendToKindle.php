<?php

namespace App\Console\Commands;

use App\Mail\SendToKindle as SendToKindleMailable;
use App\Novel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

class SendToKindle extends Command
{
    protected $signature = 'novel:send-to-kindle {novel} {--to=} {--generate : Generate the ePub first if missing}';
    protected $description = 'Email a novel\'s ePub to a Kindle address.';

    public function handle(): int
    {
        $novelId = (int) $this->argument('novel');
        $kindleEmail = $this->option('to') ?: setting('kindle_email', config('mail.kindle_email'));

        if (empty($kindleEmail)) {
            $this->error('No Kindle email configured. Set KINDLE_EMAIL in .env or pass --to=name@kindle.com');
            return self::FAILURE;
        }

        $novel = Novel::find($novelId);
        if (!$novel) {
            $this->error("Novel {$novelId} not found.");
            return self::FAILURE;
        }

        $safeFilename = $this->sanitizeFilename($novel->name . ' - ' . ($novel->author ?: 'Unknown'));
        $epubPath = storage_path("app/ePub/{$safeFilename}.epub");

        if (!File::exists($epubPath)) {
            if (!$this->option('generate')) {
                $this->error("ePub not found at {$epubPath}. Generate it first with `novel:epub {$novelId}` or re-run with --generate.");
                return self::FAILURE;
            }

            $this->info('ePub not found. Generating...');
            $this->call('novel:epub', ['novel' => $novelId]);

            if (!File::exists($epubPath)) {
                $this->error("ePub generation completed but file is still missing: {$epubPath}");
                return self::FAILURE;
            }
        }

        $sizeMb = File::size($epubPath) / 1024 / 1024;
        $this->info(sprintf('Sending "%s" (%.2f MB) to %s...', $novel->name, $sizeMb, $kindleEmail));

        // Amazon caps Send to Kindle attachments at 50 MB total per email.
        if ($sizeMb > 50) {
            $this->warn('Warning: ePub exceeds 50 MB — Amazon may reject it.');
        }

        // Resend caps total message size at 40 MB, and base64 encoding adds
        // ~37% overhead, so ePubs over ~28 MB will be rejected by the API.
        if ($sizeMb > 28 && config('mail.default') === 'resend') {
            $this->warn('Warning: ePub exceeds ~28 MB — Resend (40 MB total, base64-encoded) may reject it. Consider MAIL_MAILER=smtp for this send.');
        }

        try {
            Mail::to($kindleEmail)->send(new SendToKindleMailable($epubPath, $novel->name));
        } catch (\Throwable $e) {
            $this->error('Failed to send: ' . $e->getMessage());
            \Log::error("Send to Kindle failed for novel {$novelId}: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Sent. Check your Kindle library in a few minutes.');
        return self::SUCCESS;
    }

    protected function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[\/\\\\:*?"<>|]/', '', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = trim($filename);

        if (strlen($filename) > 200) {
            $filename = substr($filename, 0, 200);
        }

        return $filename ?: 'novel';
    }
}
