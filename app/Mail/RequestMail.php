<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The reader-request mails (App\Services\Requests\RequestNotifier), one
 * template (emails.request / emails.request_text) in the frame of the other
 * Novarr mails: a heading, a sentence, the series (title, medium, cover),
 * an optional quoted note or reason, and one button. Sent synchronously,
 * like the other mails; a failure never fails the request.
 */
abstract class RequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $seriesTitle,
        public string $mediumLabel,
        public ?string $coverUrl,
        public ?string $quote,
        public ?string $quoteLabel,
        public string $buttonUrl,
        public string $buttonText,
    ) {
    }

    abstract public function subjectLine(): string;

    abstract public function heading(): string;

    abstract public function intro(): string;

    public function footer(): string
    {
        return 'You get this email because of a request on Novarr.';
    }

    public function build()
    {
        return $this->subject($this->subjectLine())
            ->view('emails.request')
            ->text('emails.request_text')
            ->with([
                'heading' => $this->heading(),
                'intro' => $this->intro(),
                'footer' => $this->footer(),
            ]);
    }
}
