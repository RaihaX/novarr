<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader who joined someone else's request ("Me too"): told about its
 * decisions as the requester is (App\Services\Requests\RequestNotifier),
 * sees it in their own list, and may leave it. Unique per request and
 * reader.
 */
class NovelRequestFollower extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'novel_request_followers';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(NovelRequest::class, 'novel_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
