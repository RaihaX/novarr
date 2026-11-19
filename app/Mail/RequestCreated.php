<?php

namespace App\Mail;

/** To the owner: a reader requested a series (link to the web Requests page). */
class RequestCreated extends RequestMail
{
    public function __construct(
        public string $requesterName,
        string $seriesTitle,
        string $mediumLabel,
        ?string $coverUrl,
        ?string $note,
        string $requestsUrl,
    ) {
        parent::__construct($seriesTitle, $mediumLabel, $coverUrl, $note, $note !== null ? "{$requesterName}'s note" : null, $requestsUrl, 'Review the request');
    }

    public function subjectLine(): string
    {
        return "{$this->requesterName} requested {$this->seriesTitle}";
    }

    public function heading(): string
    {
        return 'New request';
    }

    public function intro(): string
    {
        return "{$this->requesterName} would like {$this->seriesTitle} ({$this->mediumLabel}) added to the library. Approve or decline it on the Requests page, from your home network.";
    }

    public function footer(): string
    {
        return 'You get this email because you are the owner of this Novarr.';
    }
}
