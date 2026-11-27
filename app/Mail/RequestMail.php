<?php

namespace App\Mail;

use App\Services\Requests\RequestNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The reader-request mails (App\Services\Requests\RequestNotifier), one
 * template (emails.request / emails.request_text) in the shared Novarr frame
 * (emails.layout): an eyebrow and heading, a sentence, the series card
 * (title, medium, cover), an optional list or quoted note / reason, and one
 * button. Sent synchronously,
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

    /** The small label over the heading. */
    public function eyebrow(): ?string
    {
        return 'Requests';
    }

    /** info | success | warning | danger: the eyebrow and the card's rule. */
    public function tone(): string
    {
        return 'info';
    }

    /** A status chip on the card's meta line. */
    public function chip(): ?string
    {
        return null;
    }

    /** Whether the series card is shown. */
    public function showCard(): bool
    {
        return true;
    }

    /** @return list<array{title: string, meta?: ?string, status?: ?string, tone?: ?string}> a list under the card */
    public function items(): array
    {
        return [];
    }

    public function itemsLabel(): ?string
    {
        return null;
    }

    /** The footer's "Open Novarr": where the recipient reads (the public address for a reader). */
    public function homeUrl(): string
    {
        return RequestNotifier::readerBase() . '/';
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
                'eyebrow' => $this->eyebrow(),
                'tone' => $this->tone(),
                'chip' => $this->chip(),
                'showCard' => $this->showCard(),
                'items' => $this->items(),
                'itemsLabel' => $this->itemsLabel(),
                'homeUrl' => $this->homeUrl(),
            ]);
    }
}
