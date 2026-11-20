<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** To the owner: one short admin alert (App\Services\AdminAlert), a sentence and a link. */
class AdminAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $body,
        public ?string $url = null,
    ) {
    }

    public function build()
    {
        $html = '<p style="font-family:sans-serif;font-size:15px;line-height:1.5">' . e($this->body) . '</p>';
        if ($this->url !== null) {
            $html .= '<p style="font-family:sans-serif;font-size:15px"><a href="' . e($this->url) . '">Open in Novarr</a></p>';
        }
        $html .= '<p style="font-family:sans-serif;font-size:12px;color:#777">You get this email because you are the owner of this Novarr.</p>';

        return $this->subject($this->subjectLine)->html($html);
    }
}
