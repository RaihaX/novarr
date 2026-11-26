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
        $lines = array_merge(
            array_map(fn($t) => "✓ {$t}", $sentTitles),
            array_map(fn($l) => "✗ {$l}", $refusedLines),
        );
        $title = count($sentTitles) === 1 ? $sentTitles[0] : (count($sentTitles) > 1 ? count($sentTitles) . ' books' : 'Your email');
        parent::__construct(
            $title,
            'book',
            $coverUrl,
            $lines !== [] ? implode("\n", $lines) : null,
            $lines !== [] ? 'What arrived' : null,
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
        return $this->sentTitles === [] ? 'Nothing was added' : 'Book received';
    }

    public function intro(): string
    {
        if ($this->sentTitles === []) {
            return $this->refusedLines === []
                ? 'Your email had no ePub attached. Attach the .epub file itself (not a link) and send it again.'
                : 'None of the files in your email could be added. The reasons are below.';
        }
        $what = count($this->sentTitles) === 1 ? 'Your book is' : 'Your books are';

        return $this->forOwner
            ? "{$what} waiting in the review queue: match the details and cover, then add it, from your home network."
            : "{$what} with the owner, who checks the details before adding it to the library. You'll get another email when it is ready to read.";
    }

    public function footer(): string
    {
        return 'You get this email because you sent books to Novarr by email.';
    }
}
