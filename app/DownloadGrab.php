<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A release the owner grabbed from a Prowlarr search (App\Downloads\Grabber)
 * and Novarr follows in its download client (App\Downloads\DownloadPoller).
 *
 * status: queued (sent, not started) → downloading (progress 0–100) →
 * completed (finished in the client; completed_path is its name in the
 * inbox) → imported (an ebook taken into the review queue, an audiobook
 * imported by `audiobooks:scan`), or failed (error, plain words).
 *
 * Torrents keep seeding: their files stay in the inbox after the import
 * (copied, never moved), and the row (imported) is what tells the
 * importers not to take them twice.
 */
class DownloadGrab extends Model
{
    public const KIND_EBOOK = 'ebook';
    public const KIND_AUDIOBOOK = 'audiobook';
    public const KINDS = [self::KIND_EBOOK, self::KIND_AUDIOBOOK];

    public const USENET = 'usenet';
    public const TORRENT = 'torrent';

    public const NZBGET = 'nzbget';
    public const TRANSMISSION = 'transmission';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_FAILED = 'failed';

    /** Still in the download client. */
    public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_DOWNLOADING];

    /** imported_type values. */
    public const IMPORTED_REQUEST = 'request';
    public const IMPORTED_AUDIOBOOK = 'audiobook';
    /** Added to the library (approved automatically, or the library had it already): imported_id is the book. */
    public const IMPORTED_BOOK = 'book';

    protected $table = 'download_grabs';

    protected $guarded = ['id'];

    protected $casts = [
        'size' => 'integer',
        'progress' => 'integer',
        'novel_request_id' => 'integer',
        'novel_id' => 'integer',
        'imported_id' => 'integer',
        'user_id' => 'integer',
        'auto' => 'boolean',
        'wanted_id' => 'integer',
        'eta_seconds' => 'integer',
        'progress_at' => 'datetime',
        'completed_at' => 'datetime',
        'imported_at' => 'datetime',
        'polled_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(NovelRequest::class, 'novel_request_id');
    }

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class, 'novel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isTorrent(): bool
    {
        return $this->protocol === self::TORRENT;
    }

    /**
     * Mark it failed with the reason (plain words): its release is
     * blocklisted and its wanted item searched again
     * (App\Downloads\FailedDownloads). $releaseAtFault false (Novarr's own
     * problem: an inbox path, the review queue): neither, only the History.
     */
    public function fail(string $error, bool $releaseAtFault = true): self
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'error' => mb_substr($error, 0, 1000)])->save();
        app(\App\Downloads\FailedDownloads::class)->handle($this, $releaseAtFault);

        return $this;
    }

    /**
     * What the owner's pages and the widget (resources/js/download-finder.js)
     * show: status and progress, its target and, once done, where to go next.
     */
    public function present(): array
    {
        $request = $this->novel_request_id ? $this->request : null;
        $novel = $this->novel_id ? $this->novel : null;
        $next = null;
        if ($this->status === self::STATUS_IMPORTED && $this->imported_type === self::IMPORTED_REQUEST && $this->imported_id) {
            $next = route('books.review', $this->imported_id);
        } elseif ($this->status === self::STATUS_IMPORTED && $this->imported_type === self::IMPORTED_AUDIOBOOK && $this->imported_id) {
            $next = route('audiobooks.show', $this->imported_id);
        } elseif ($this->status === self::STATUS_IMPORTED && $this->imported_type === self::IMPORTED_BOOK && $this->imported_id) {
            $next = route('novels.show', $this->imported_id);
        }

        return [
            'id' => (int) $this->id,
            'kind' => $this->kind,
            'protocol' => $this->protocol,
            'client' => $this->client,
            'title' => $this->title,
            'size' => (int) $this->size,
            'indexer' => $this->indexer,
            'status' => $this->status,
            'progress' => (int) $this->progress,
            'error' => $this->error,
            'imported_type' => $this->imported_type,
            'auto' => (bool) $this->auto,
            'eta_seconds' => $this->eta_seconds !== null ? (int) $this->eta_seconds : null,
            'status_line' => \App\Downloads\Downloads::statusLine($this),
            'seeding' => $this->isTorrent(),
            'target' => $request !== null
                ? ['type' => 'request', 'id' => (int) $request->id, 'title' => $request->title, 'url' => route('books.review', $request->id)]
                : ($novel !== null ? ['type' => 'book', 'id' => (int) $novel->id, 'title' => $novel->name, 'url' => route('novels.show', $novel->id)] : null),
            'next_url' => $next,
            'status_url' => route('downloads.grabs.show', $this->id),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
