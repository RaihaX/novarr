<?php

namespace App\Mail;

/** To a reader: the owner declined the audiobook they uploaded (with the reason, when given); its files are deleted. */
class AudiobookUploadDeclined extends RequestMail
{
    public function __construct(
        public string $name,
        string $title,
        ?string $coverUrl,
        ?string $reason,
        string $requestsUrl,
    ) {
        parent::__construct($title, 'audiobook', $coverUrl, $reason, $reason !== null ? 'Reason' : null, $requestsUrl, 'See your requests');
    }

    public function subjectLine(): string
    {
        return "Your audiobook upload was declined: {$this->seriesTitle}";
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
        return 'warning';
    }

    public function chip(): ?string
    {
        return 'Declined';
    }

    public function intro(): string
    {
        return "The owner declined the audiobook you uploaded, {$this->seriesTitle}, so it was not added to the library and its files were deleted.";
    }

    public function footer(): string
    {
        return 'You get this email because you uploaded an audiobook to Novarr.';
    }
}
