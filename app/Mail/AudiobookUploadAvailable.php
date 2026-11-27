<?php

namespace App\Mail;

/** To a reader: the audiobook they uploaded is in the library (App\Audiobooks\AudiobookUploads::approve()). */
class AudiobookUploadAvailable extends RequestMail
{
    public function __construct(
        public string $name,
        string $title,
        ?string $coverUrl,
        string $listenUrl,
    ) {
        parent::__construct($title, 'audiobook', $coverUrl, null, null, $listenUrl, 'Listen now');
    }

    public function subjectLine(): string
    {
        return "Your audiobook is in the library: {$this->seriesTitle}";
    }

    public function heading(): string
    {
        return "Hi {$this->name},";
    }

    public function eyebrow(): ?string
    {
        return 'Audiobooks';
    }

    public function tone(): string
    {
        return 'success';
    }

    public function chip(): ?string
    {
        return 'In the library';
    }

    public function intro(): string
    {
        return "Your audiobook is in the library: the owner added {$this->seriesTitle}, which you uploaded. Thank you! Everyone on Novarr can listen to it now.";
    }

    public function footer(): string
    {
        return 'You get this email because you uploaded an audiobook to Novarr.';
    }
}
