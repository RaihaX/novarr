<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bookmark extends Model
{
    protected $fillable = ['novel_id', 'novel_chapter_id', 'excerpt', 'note'];

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(NovelChapter::class, 'novel_chapter_id');
    }
}
