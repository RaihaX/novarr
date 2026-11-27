<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Novarr 1.17 is out": a new Android app version, with its release notes
 * (App\Console\Commands\AnnounceAndroidRelease). The button opens the
 * public download page (an intent:// link is unreliable in mail); an
 * installed app offers the update itself the next time it is opened. The
 * footer's signed "Stop app update emails" link turns it off for the
 * account (users.app_update_emails_off_at).
 */
class AndroidReleaseMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param list<string> $notes
     */
    public function __construct(
        public string $version,
        public array $notes,
        public string $downloadUrl,
        public ?string $name = null,
        public ?string $unsubscribeUrl = null,
        public ?string $homeUrl = null,
    ) {
    }

    public function subjectLine(): string
    {
        return "Novarr {$this->version} is out";
    }

    public function build()
    {
        $intro = 'A new version of the Novarr app for Android is ready. Open Novarr and tap Update, or download it here.';

        return $this->subject($this->subjectLine())
            ->view('emails.android_release')
            ->text('emails.android_release_text')
            ->with([
                'heading' => $this->subjectLine(),
                'greeting' => $this->name !== null && trim($this->name) !== '' ? 'Hi ' . trim($this->name) . ',' : null,
                'intro' => $intro,
                'items' => array_map(fn(string $note) => ['title' => $note], $this->notes),
                'footer' => 'You get this because you use the Novarr app on Android.',
            ]);
    }
}
