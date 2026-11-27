<?php

namespace App;

use App\Audiobooks\AudiobookLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audiobook a reader uploads (App\Audiobooks\AudiobookUploads):
 *
 *   uploading   chunks arrive into {audiobooks root}/_uploads/{id}/
 *   processing  all chunks are in: being checked (SHA-256, ffprobe)
 *   pending     checked: waiting for the owner (Downloads → Needs you)
 *   approved    imported into the library (audiobook_id)
 *   declined    the owner said no (decline_reason); files deleted
 *   failed      not audio, or it could not be checked (error); files deleted
 *
 * files: one entry per file in the client's order (the chunk API's
 * {index}): name (sanitised, the client's relative path), size, chunks,
 * sha256 (the server's, once checked), client_sha256, path (relative to
 * the upload's folder, once assembled), audio (false for a cover image).
 *
 * probe: what the files say (title, author, narrator, duration_ms,
 * chapters, cover_path on the public disk, parts, duplicate_of, suggestion).
 */
class AudiobookUpload extends Model
{
    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_FAILED = 'failed';

    /** Not decided yet: counted against the reader's limit. */
    public const OPEN_STATUSES = [self::STATUS_UPLOADING, self::STATUS_PROCESSING, self::STATUS_PENDING];

    /** The folder under the audiobooks root that holds every upload (Part 2's Storage page measures it). */
    public const UPLOADS_DIR = '_uploads';

    protected $fillable = [
        'user_id', 'novel_id', 'status', 'title', 'note', 'decline_reason', 'error', 'dir', 'chunk_size',
        'total_bytes', 'received_bytes', 'files', 'probe', 'fingerprint', 'audiobook_id', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'novel_id' => 'integer',
        'chunk_size' => 'integer',
        'total_bytes' => 'integer',
        'received_bytes' => 'integer',
        'files' => 'array',
        'probe' => 'array',
        'audiobook_id' => 'integer',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    public function audiobook(): BelongsTo
    {
        return $this->belongsTo(Audiobook::class);
    }

    /** {audiobooks root}/_uploads */
    public static function uploadsRoot(): string
    {
        return AudiobookLibrary::root() . '/' . self::UPLOADS_DIR;
    }

    /** The upload's folder (absolute): {audiobooks root}/_uploads/{id}. */
    public function directory(): string
    {
        return self::uploadsRoot() . '/' . (int) $this->id;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** The reader's words for the status (My requests' chip). */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADING => 'Uploading',
            self::STATUS_PROCESSING => 'Checking the files',
            self::STATUS_PENDING => 'Waiting for the owner',
            self::STATUS_APPROVED => 'In the library',
            self::STATUS_DECLINED => 'Declined',
            default => 'Failed',
        };
    }

    /** pending | accent | success | danger | muted: the chip's tone. */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADING, self::STATUS_PROCESSING => 'accent',
            self::STATUS_PENDING => 'pending',
            self::STATUS_APPROVED => 'success',
            default => 'danger',
        };
    }

    /** The title shown: the files' tags, else what the reader named, else the first file's name. */
    public function displayTitle(): string
    {
        $title = trim((string) ($this->probe['title'] ?? $this->title ?? ''));
        if ($title !== '') {
            return $title;
        }
        $first = (array) (($this->files ?? [])[0] ?? []);

        return pathinfo(basename((string) ($first['name'] ?? 'Audiobook')), PATHINFO_FILENAME) ?: 'Audiobook';
    }
}
