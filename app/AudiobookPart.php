<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file of an audiobook, in playing order (idx, 0-based). path is
 * relative to the audiobook's folder; start_ms is where it starts in the
 * whole book. Streamed as it is (no transcoding) by
 * Api\V1\AudiobookController::part().
 */
class AudiobookPart extends Model
{
    public $timestamps = false;

    protected $fillable = ['audiobook_id', 'idx', 'path', 'mime', 'duration_ms', 'size_bytes', 'start_ms'];

    protected $casts = [
        'audiobook_id' => 'integer',
        'idx' => 'integer',
        'duration_ms' => 'integer',
        'size_bytes' => 'integer',
        'start_ms' => 'integer',
    ];

    public function audiobook(): BelongsTo
    {
        return $this->belongsTo(Audiobook::class);
    }
}
