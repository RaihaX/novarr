<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The same manga series found on another installed source, used to fill
 * chapters its own source keeps failing on (App\Scraping\MangaFallback).
 * Written only by MangaFallback's refresh; `failures` counts consecutive
 * failed fills (an alternate is skipped at MangaFallback::MAX_FAILURES
 * until the next refresh finds it again).
 */
class MangaAlternate extends Model
{
    protected $table = 'manga_alternates';

    protected $fillable = [
        'novel_id', 'source_key', 'source_name', 'series_url', 'title', 'rank',
        'chapter_count', 'max_chapter', 'found_at', 'checked_at', 'last_failed_at', 'failures',
    ];

    protected $casts = [
        'novel_id' => 'integer',
        'rank' => 'integer',
        'chapter_count' => 'integer',
        'max_chapter' => 'float',
        'failures' => 'integer',
        'found_at' => 'datetime',
        'checked_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }
}
