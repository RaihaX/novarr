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

    protected $casts = [
        'word_count' => 'integer',
    ];

    /**
     * The stored word count's one definition (the Home hero's minutes-left
     * and /stats read it). Rows saved before the column existed are filled
     * by `php artisan chapters:word-counts`.
     */
    public static function countWords(?string $content): int
    {
        return str_word_count(strip_tags((string) $content));
    }

    /**
     * Every body write goes through this model (NovelChapter's saved hook
     * uses updateOrCreate), so the count is kept in step here.
     */
    protected static function booted(): void
    {
        static::saving(function (self $text) {
            if ($text->isDirty('content') || $text->word_count === null) {
                $text->word_count = static::countWords($text->content);
            }
        });
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(NovelChapter::class, 'novel_chapter_id');
    }
}
