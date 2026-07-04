<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chapter's body text, split out of novel_chapters so the main table stays
 * narrow — queries and ALTERs there no longer drag gigabytes of longtext.
 */
class ChapterText extends Model
{
    protected $table = 'chapter_texts';
    protected $primaryKey = 'novel_chapter_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['novel_chapter_id', 'content'];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(NovelChapter::class, 'novel_chapter_id');
    }
}
