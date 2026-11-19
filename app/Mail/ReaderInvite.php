<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * "You're invited to Novarr": the link to make a reader account
 * (App\UserInvite). Sent synchronously by App\Http\Controllers\UserInviteController,
 * like the other mails; the plain link exists only in this mail and on the
 * admin's screen, so the mail is never queued (it would be stored).
 */
class ReaderInvite extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = "You're invited to Novarr";

    public function __construct(
        public string $name,
        public string $link,
        public Carbon $expiresAt,
        public string $inviterName,
        /** The Android app download (same origin as $link), null when none is offered. */
        public ?string $androidUrl = null,
    ) {
    }

    public function build()
    {
        return $this->subject(self::SUBJECT)
            ->view('emails.invite')
            ->text('emails.invite_text')
            ->with([
                'expires' => $this->expiresAt->copy()->timezone(config('app.timezone'))->format('j F Y'),
            ]);
    }
}
