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
        return $this->mediumLabel === 'book'
            ? "{$this->requesterName} uploaded {$this->seriesTitle}"
            : "{$this->requesterName} requested {$this->seriesTitle}";
    }

    public function heading(): string
    {
        return 'New request';
    }

    public function intro(): string
    {
        if ($this->mediumLabel === 'book') {
            return "{$this->requesterName} uploaded an ePub, “{$this->seriesTitle}”, for the library. Check it is the right book, match its details and cover, then add or decline it, from your home network.";
        }

        return "{$this->requesterName} would like {$this->seriesTitle} ({$this->mediumLabel}) added to the library. Approve or decline it on the Requests page, from your home network.";
    }

    public function footer(): string
    {
        return 'You get this email because you are the owner of this Novarr.';
    }
}
