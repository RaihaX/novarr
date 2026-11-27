<?php

namespace App\Mail;

/**
 * The reply to an email sent to the books inbox (App\Books\BookInbox): what
 * was received and what was not, in the request mails' frame. The reader is
 * told again when each book is in the library (RequestAvailable).
 */
class BookInboxReply extends RequestMail
{
    /**
     * @param  string[]  $sentTitles
     * @param  string[]  $refusedLines  "file: why"
     */
    public function __construct(
        public array $sentTitles,
        public array $refusedLines,
        public bool $forOwner,
        ?string $coverUrl,
        string $buttonUrl,
    ) {
        $title = count($sentTitles) === 1 ? $sentTitles[0] : (count($sentTitles) > 1 ? count($sentTitles) . ' books' : 'Your email');
        // What arrived is a list (items()), not a quote.
        parent::__construct(
            $title,
            'book',
            $coverUrl,
            null,
            null,
            $buttonUrl,
            $forOwner && $sentTitles !== [] ? 'Review and match' : 'See your requests',
        );
    }

    public function subjectLine(): string
    {
        return match (true) {
            $this->sentTitles === [] => 'No books could be added from your email',
            count($this->sentTitles) === 1 => "Received: {$this->sentTitles[0]}",
            default => 'Received ' . count($this->sentTitles) . ' books',
        };
    }

    public function heading(): string
    {
        return match (true) {
            $this->sentTitles === [] => 'Nothing was added',
            count($this->sentTitles) === 1 => 'Book received',
            default => 'Books received',
        };
    }

    public function intro(): string
    {
        if ($this->sentTitles === []) {
            return $this->refusedLines === []
                ? 'Your email had no ePub attached. Attach the .epub file itself (not a link) and send it again.'
                : 'None of the files in your email could be added. The reasons are below.';
        }
        [$what, $it, $is] = count($this->sentTitles) === 1 ? ['Your book is', 'it', 'it is'] : ['Your books are', 'them', 'each is'];

        return $this->forOwner
            ? "{$what} waiting in the review queue: match the details and cover, then add {$it}, from your home network."
            : "{$what} with the owner, who checks the details before adding {$it} to the library. You'll get another email when {$is} ready to read.";
    }

    public function eyebrow(): ?string
    {
        return 'Books inbox';
    }

    public function tone(): string
    {
        return $this->sentTitles === [] ? 'warning' : 'success';
    }

    public function chip(): ?string
    {
        return $this->forOwner ? 'In review' : 'With the owner';
    }

    /** One book: its card. Several, or none: the list says it all. */
    public function showCard(): bool
    {
        return count($this->sentTitles) === 1;
    }

    public function items(): array
    {
        if (count($this->sentTitles) === 1 && $this->refusedLines === []) {
            return [];
        }

        return array_merge(
            array_map(fn($t) => ['title' => $t, 'status' => 'Received', 'tone' => 'success'], $this->sentTitles),
            array_map(function ($line) {
                [$file, $why] = array_pad(explode(': ', $line, 2), 2, null);

                return ['title' => $file, 'meta' => $why, 'status' => 'Not added', 'tone' => 'danger'];
            }, $this->refusedLines),
        );
    }

    public function itemsLabel(): ?string
    {
        return $this->items() !== [] ? 'What arrived' : null;
    }

    public function homeUrl(): string
    {
        return $this->forOwner ? \App\Services\Requests\RequestNotifier::adminBase() . '/' : parent::homeUrl();
    }

    public function footer(): string
    {
        return 'You get this email because you sent books to Novarr by email.';
    }
}
