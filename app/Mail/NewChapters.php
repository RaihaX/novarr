<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class NewChapters extends Mailable
{
    use Queueable, SerializesModels;

    public $mailData;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($mailData)
    {
        $this->mailData = $mailData;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject($this->summarySubject())->view('emails.newchapters')->with([
            'data' => $this->mailData,
        ]);
    }

    /**
     * Summarise the payload in the subject, e.g.
     * "Novarr Daily Summary – 12 new chapters, 1 novel completed".
     */
    protected function summarySubject(): string
    {
        $chapters = $this->mailData['chapters']
            ?? (is_array($this->mailData) && array_is_list($this->mailData) ? $this->mailData : []);
        $completed = $this->mailData['completed'] ?? [];

        $parts = [];

        if (($count = count($chapters)) > 0) {
            $parts[] = $count . ' new chapter' . ($count === 1 ? '' : 's');
        }

        if (($count = count($completed)) > 0) {
            $parts[] = $count . ' novel' . ($count === 1 ? '' : 's') . ' completed';
        }

        if (($count = count($this->mailData['attention'] ?? [])) > 0) {
            $parts[] = $count . ' need' . ($count === 1 ? 's' : '') . ' attention';
        }

        return 'Novarr Daily Summary' . ($parts ? ' – ' . implode(', ', $parts) : '');
    }
}
