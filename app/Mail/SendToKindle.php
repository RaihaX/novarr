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

    /** The attachment's name; the file's own name when null. */
    public ?string $fileName;

    /** Shown under the title in the note; Amazon reads only the subject and the file. */
    public ?string $author;

    public function __construct(string $epubPath, string $novelTitle, ?string $fileName = null, ?string $author = null)
    {
        $this->epubPath = $epubPath;
        $this->novelTitle = $novelTitle;
        $this->fileName = $fileName;
        $this->author = $author !== null && trim($author) !== '' ? trim($author) : null;
    }

    public function build()
    {
        // Amazon's Send to Kindle service uses the subject line as the document
        // title in the Kindle library, so we set it to the novel name. The
        // body is only a short branded note (Amazon ignores it).
        return $this->subject($this->novelTitle)
            ->view('emails.kindle')
            ->text('emails.kindle_text')
            ->attach($this->epubPath, [
                'as'   => $this->fileName ?? basename($this->epubPath),
                'mime' => 'application/epub+zip',
            ]);
    }
}
