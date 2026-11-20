<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Where the alternate-source search of one manga series stands
 * (App\Scraping\MangaFallback): a cycle walks the other installed sources
 * a few per run (`cursor` of `sources_total`); `cycle_completed_at` is when
 * every source was last searched. `source_titles` caches what the series'
 * own source calls it (JSON list), so a series renamed in Novarr still
 * matches without asking its source every run.
 */
class MangaAlternateSearch extends Model
{
    protected $table = 'manga_alternate_searches';

    protected $fillable = [
        'novel_id', 'source_titles', 'cursor', 'sources_total',
        'cycle_started_at', 'cycle_completed_at', 'searched_at',
    ];

    protected $casts = [
        'novel_id' => 'integer',
        'source_titles' => 'array',
        'cursor' => 'integer',
        'sources_total' => 'integer',
        'cycle_started_at' => 'datetime',
        'cycle_completed_at' => 'datetime',
        'searched_at' => 'datetime',
    ];

    public function cycleComplete(): bool
    {
        return $this->cycle_completed_at !== null
            && ($this->cycle_started_at === null || $this->cycle_completed_at->gte($this->cycle_started_at));
    }
}
