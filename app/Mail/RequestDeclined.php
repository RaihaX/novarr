<?php

namespace App\Mail;

/** To the requester: the owner declined their request (with the reason, when given). */
class RequestDeclined extends RequestMail
{
    public function __construct(
        public string $name,
        string $seriesTitle,
        string $mediumLabel,
        ?string $coverUrl,
        ?string $reason,
        string $requestsUrl,
    ) {
        parent::__construct($seriesTitle, $mediumLabel, $coverUrl, $reason, $reason !== null ? 'Reason' : null, $requestsUrl, 'See your requests');
    }

    public function subjectLine(): string
    {
        return "Your request for {$this->seriesTitle} was declined";
    }

    public function heading(): string
    {
        return "Hi {$this->name},";
    }

    public function eyebrow(): ?string
    {
        return 'Request declined';
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
        return "Your request for {$this->seriesTitle} ({$this->mediumLabel}) was declined.";
    }
}
