<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader's request for a series (App\Services\Requests\Requests).
 *
 * status: pending → approved | declined. The displayed state adds
 * "available": an approved request whose series has downloaded a chapter
 * (available_at set by `requests:availability`).
 *
 * candidates: the per-source hits copied from the server's own search record
 * (never from the client), each {source, source_name, url, title, cover_url,
 * chapters, status} plus, for manga once ranked, the evaluation
 * (App\Services\Requests\CandidateRanking): languages, chapters_available,
 * highest_chapter, gaps, updated_at, error, reason. Best first.
 */
class NovelRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';

    /** The displayed state of an approved request whose series has a downloaded chapter. */
    public const STATUS_AVAILABLE = 'available';

    /** Requests that block another request for the same work. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED];

    public const MEDIA = [Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA];

    protected $table = 'novel_requests';

    protected $guarded = ['id'];

    protected $casts = [
        'candidates' => 'array',
        'candidates_ranked_at' => 'datetime',
        'decided_at' => 'datetime',
        'available_at' => 'datetime',
        'requester_notified_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** pending | approved | declined | available */
    public function displayStatus(): string
    {
        return $this->status === self::STATUS_APPROVED && $this->available_at !== null
            ? self::STATUS_AVAILABLE
            : (string) $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** @return array<int, array> */
    public function candidateList(): array
    {
        return array_values(array_filter((array) ($this->candidates ?? []), 'is_array'));
    }

    /** "manga" / "novel" for messages. */
    public function mediumLabel(): string
    {
        return $this->medium === Novel::MEDIUM_MANGA ? 'manga' : 'novel';
    }
}
