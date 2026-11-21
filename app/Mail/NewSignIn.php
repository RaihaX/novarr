<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * "New sign-in to your Novarr account": a password sign-in from a device
 * the account had not used before (App\Services\KnownDevices). Sent after
 * the response, like the other mails never queued.
 */
class NewSignIn extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'New sign-in to your Novarr account';

    public function __construct(
        public string $name,
        public Carbon $at,
        public string $device,
        public string $ip,
        /** Came in on the public address (else the home network). */
        public bool $public,
    ) {
    }

    public function build()
    {
        return $this->subject(self::SUBJECT)
            ->view('emails.new_sign_in')
            ->text('emails.new_sign_in_text')
            ->with([
                'when' => $this->at->copy()->timezone(config('app.timezone'))->format('j F Y, H:i T'),
                'where' => $this->public ? 'the public address' : 'your home network',
            ]);
    }
}
