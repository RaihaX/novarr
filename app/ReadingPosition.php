<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a series was last read, and on which device — one row per novel.
 * Written and read through App\Services\ReadingPositions.
 */
class ReadingPosition extends Model
{
    protected $fillable = ['novel_id', 'novel_chapter_id', 'progress', 'device_id', 'device_name', 'recorded_at'];

    protected $casts = [
        'novel_id' => 'integer',
        'novel_chapter_id' => 'integer',
        'progress' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(NovelChapter::class, 'novel_chapter_id');
    }
}
