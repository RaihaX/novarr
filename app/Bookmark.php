<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reader's highlight: an excerpt of a chapter plus an optional note. */
class Bookmark extends Model
{
    protected $fillable = ['user_id', 'novel_id', 'novel_chapter_id', 'excerpt', 'note'];

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(NovelChapter::class, 'novel_chapter_id');
    }
}
