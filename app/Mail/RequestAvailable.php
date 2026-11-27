<?php

namespace App\Mail;

/** To the requester: the series they asked for is in the library and has chapters to read. */
class RequestAvailable extends RequestMail
{
    public function __construct(
        public string $name,
        string $seriesTitle,
        string $mediumLabel,
        ?string $coverUrl,
        string $seriesUrl,
    ) {
        parent::__construct($seriesTitle, $mediumLabel, $coverUrl, null, null, $seriesUrl, 'Start reading');
    }

    public function subjectLine(): string
    {
        return "{$this->seriesTitle} is ready to read";
    }

    public function heading(): string
    {
        return "Hi {$this->name},";
    }

    public function eyebrow(): ?string
    {
        return 'Ready to read';
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
        return "{$this->seriesTitle} ({$this->mediumLabel}), which you requested, is in the library and its first chapters have downloaded.";
    }
}
