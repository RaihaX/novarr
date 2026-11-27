<?php

namespace App\Mail;

use App\Services\Requests\RequestNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * To the owner: one admin alert (App\Services\AdminAlert) in the shared
 * frame (emails.admin_alert / emails.admin_alert_text). $body is the
 * one-sentence message the webhook also gets; $details structure the email
 * (all optional):
 *
 *   heading     the h1 (default: the subject)
 *   eyebrow     the small label above it ("Downloads", "Health")
 *   tone        info | success | warning | danger: eyebrow + card rule colour
 *   intro       the sentence under the heading (default: $body)
 *   cover       absolute cover URL (reachable from outside: AdminAlertMail::cover*())
 *   title       the card's title (a book, a source); no title = no card
 *   meta        the card's meta line ("Book · Brandon Sanderson")
 *   chip        a status chip on the card ("In the library")
 *   items       a list: [['title', 'meta'?, 'status'?, 'tone'?, 'url'?, 'cover'?], …]
 *   items_label its label; more: a line under it ("and 3 more")
 *   facts       a facts table: [label => value] or [[label, value, mono?], …]
 *   note        a quoted note; note_label its label
 *   button      the button's words (default "Open in Novarr" when there is a URL)
 *   button_url  where it goes (default: $url)
 *   secondary   ['text' => …, 'url' => …] a link beside the button
 *   footer      the reason line
 */
class AdminAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public const FOOTER = 'You get this email because you are the owner of this Novarr.';

    public function __construct(
        public string $subjectLine,
        public string $body,
        public ?string $url = null,
        public array $details = [],
    ) {
        $this->details['tone'] = in_array($details['tone'] ?? null, MailTheme::TONES, true) ? $details['tone'] : 'info';
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->view('emails.admin_alert')
            ->text('emails.admin_alert_text')
            ->with(['m' => $this->viewModel()]);
    }

    /** Every field the views read, defaults filled in. */
    public function viewModel(): array
    {
        $d = $this->details;
        $buttonUrl = $d['button_url'] ?? $this->url;
        $items = array_values(array_filter(array_map(
            fn($item) => is_array($item) && trim((string) ($item['title'] ?? '')) !== '' ? $item : null,
            (array) ($d['items'] ?? []),
        )));

        return [
            'heading' => (string) ($d['heading'] ?? $this->subjectLine),
            'eyebrow' => $d['eyebrow'] ?? 'Owner alert',
            'tone' => $d['tone'],
            'intro' => (string) ($d['intro'] ?? $this->body),
            'cover' => $d['cover'] ?? null,
            'title' => isset($d['title']) && trim((string) $d['title']) !== '' ? (string) $d['title'] : null,
            'title_url' => $d['title_url'] ?? $buttonUrl,
            'meta' => $d['meta'] ?? null,
            'chip' => $d['chip'] ?? null,
            'items' => $items,
            'items_label' => $d['items_label'] ?? null,
            'more' => $d['more'] ?? null,
            'facts' => (array) ($d['facts'] ?? []),
            'note' => $d['note'] ?? null,
            'note_label' => $d['note_label'] ?? null,
            'button' => $buttonUrl !== null ? (string) ($d['button'] ?? 'Open in Novarr') : null,
            'button_url' => $buttonUrl,
            'secondary' => $d['secondary'] ?? null,
            'footer' => (string) ($d['footer'] ?? self::FOOTER),
            'home_url' => RequestNotifier::adminBase() . '/',
        ];
    }

    /**
     * A library book's cover for the owner's email: absolute on the reader
     * (public) address when there is one, so it loads away from home too.
     */
    public static function coverOf(?\App\Novel $novel): ?string
    {
        if ($novel === null) {
            return null;
        }
        try {
            $novel->loadMissing('file');

            return static::coverUrl($novel->coverUrl('md'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Any cover URL (a stored one, a request's source cover) made absolute and loadable from outside. */
    public static function coverUrl(?string $cover): ?string
    {
        try {
            $ownerId = \App\Support\CurrentReader::ownerId();
        } catch (\Throwable) {
            $ownerId = null;
        }

        return \App\Http\Controllers\RequestCoverController::url($cover, $ownerId, RequestNotifier::readerBase());
    }
}
