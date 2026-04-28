<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class SendToKindle extends Mailable
{
    use Queueable, SerializesModels;

    public string $epubPath;
    public string $novelTitle;

    public function __construct(string $epubPath, string $novelTitle)
    {
        $this->epubPath = $epubPath;
        $this->novelTitle = $novelTitle;
    }

    public function build()
    {
        // Amazon's Send to Kindle service uses the subject line as the document
        // title in the Kindle library, so we set it to the novel name.
        return $this->subject($this->novelTitle)
            ->html('<p>Sent from Novarr.</p>')
            ->attach($this->epubPath, [
                'as'   => basename($this->epubPath),
                'mime' => 'application/epub+zip',
            ]);
    }
}
