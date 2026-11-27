<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something Novarr looks for by itself (App\Downloads\WantedList): a
 * reader's wanted book (kind ebook, novel_request_id) or a book's audiobook
 * (kind audiobook, novel_id). See the migration for the columns.
 */
class DownloadWanted extends Model
{
    protected $table = 'download_wanted';

    protected $guarded = ['id'];

    protected $casts = [
        'novel_request_id' => 'integer',
        'novel_id' => 'integer',
        'monitored' => 'boolean',
        'requested_by' => 'integer',
        'searches' => 'integer',
        'results_count' => 'integer',
        'needs_choice' => 'boolean',
        'last_searched_at' => 'datetime',
        'next_search_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(NovelRequest::class, 'novel_request_id');
    }

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class, 'novel_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The book's (or the request's) title. */
    public function title(): string
    {
        return (string) ($this->kind === DownloadGrab::KIND_AUDIOBOOK ? $this->novel?->name : $this->request?->title) ?: '(gone)';
    }

    public function author(): ?string
    {
        return $this->kind === DownloadGrab::KIND_AUDIOBOOK ? $this->novel?->author : $this->request?->author;
    }

    /** Where its interactive search is: the wanted book's page, the book's page. */
    public function pageUrl(): ?string
    {
        if ($this->kind === DownloadGrab::KIND_AUDIOBOOK) {
            return $this->novel_id ? route('novels.show', $this->novel_id) . '#findAudiobook' : null;
        }

        if ($this->kind === DownloadGrab::KIND_COMIC) {
            return route('requests.index');
        }

        return $this->novel_request_id ? route('books.review', $this->novel_request_id) : null;
    }

    /** Its grab still in a client, or finished and not yet imported. */
    public function busyGrab(): ?DownloadGrab
    {
        return DownloadGrab::query()
            ->whereIn('status', [...DownloadGrab::ACTIVE, DownloadGrab::STATUS_COMPLETED])
            ->when($this->kind === DownloadGrab::KIND_AUDIOBOOK,
                fn($q) => $q->where('kind', DownloadGrab::KIND_AUDIOBOOK)->where('novel_id', $this->novel_id),
                fn($q) => $q->where('kind', $this->kind === DownloadGrab::KIND_COMIC ? DownloadGrab::KIND_COMIC : DownloadGrab::KIND_EBOOK)->where('novel_request_id', $this->novel_request_id))
            ->orderByDesc('id')
            ->first();
    }
}
