<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a reader last read a series, and on which device — one row per
 * (reader, novel). Written and read through App\Services\ReadingPositions.
 */
class ReadingPosition extends Model
{
    protected $fillable = ['user_id', 'novel_id', 'novel_chapter_id', 'progress', 'device_id', 'device_name', 'recorded_at'];

    protected $casts = [
        'user_id' => 'integer',
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
