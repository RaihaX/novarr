<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Your Novarr sign-in code": the emailed second step
 * (App\Services\SecondStep::sendEmailCode). Six digits, valid ten minutes.
 * Sent after the response, never queued, like the other sign-in mails.
 */
class SignInCode extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Your Novarr sign-in code';

    public function __construct(
        public string $name,
        public string $code,
        public int $minutes,
        public string $ip,
        /** Asked for to finish a sign-in (else to confirm a change on the Account page). */
        public bool $signIn = true,
    ) {
    }

    public function build()
    {
        return $this->subject(self::SUBJECT)
            ->view('emails.sign_in_code')
            ->text('emails.sign_in_code_text')
            ->with([
                'purpose' => $this->signIn ? 'to finish signing in to Novarr' : 'to confirm a change to your Novarr account',
            ]);
    }
}
