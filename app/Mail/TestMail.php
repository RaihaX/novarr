<?php

namespace App\Mail;

use App\Services\Requests\RequestNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Settings → "Send test email": proves mail delivery works end to end, in the same frame as every other mail. */
class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Novarr test email';

    public const MESSAGE = 'This is a test email from Novarr — your mail settings are working.';

    public function build()
    {
        return $this->subject(self::SUBJECT)
            ->view('emails.test')
            ->text('emails.test_text')
            ->with([
                'mailer' => (string) config('mail.default'),
                'sentAt' => now()->timezone(config('app.timezone'))->format('j F Y, H:i T'),
                'homeUrl' => RequestNotifier::adminBase() . '/',
            ]);
    }
}
