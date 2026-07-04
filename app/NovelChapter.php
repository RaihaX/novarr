<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NovelChapter extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        "novel_id",
        "chapter",
        "label",
        "url",
        "book",
        "unique_id",
    ];

    /**
     * Pending body-text write; flushed to chapter_texts after save (the row
     * id must exist first). Note: saveQuietly() still fires no events —
     * never set description on a quiet save.
     */
    protected ?string $pendingContent = null;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'download_date' => 'datetime',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'status' => 'boolean',
        'novel_id' => 'integer',
        'chapter' => 'float',
    ];

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** The chapter's body text (split out of this table — see ChapterText). */
    public function text(): HasOne
    {
        return $this->hasOne(ChapterText::class, 'novel_chapter_id');
    }

    /**
     * Raw body text from chapter_texts, memoised on the relation so repeated
     * access doesn't re-query. Eager-load `text` in loops.
     */
    public function rawText(): ?string
    {
        if ($this->pendingContent !== null) {
            return $this->pendingContent;
        }
        if (!$this->relationLoaded('text')) {
            $this->setRelation('text', $this->text()->first());
        }

        return $this->getRelation('text')?->content;
    }

    /**
     * Sanitise stored content for display/ePub: whitelist basic formatting
     * tags; self-close <br>/<hr> so the output stays valid XHTML.
     */
    public static function presentContent(?string $value): string
    {
        $value = strip_tags($value ?? '', '<p><br><hr><em><strong><i><b><u><s>');
        $value = preg_replace('/<(br|hr)\s*\/?>/i', '<$1/>', $value);

        return str_replace('<p>&nbsp;</p>', '', $value);
    }

    // description behaves like it always has, but is backed by chapter_texts.
    public function getDescriptionAttribute(): string
    {
        return static::presentContent($this->rawText());
    }

    public function setDescriptionAttribute($value): void
    {
        $this->pendingContent = $value ?? '';
    }

    protected static function booted(): void
    {
        static::saved(function (self $chapter) {
            if ($chapter->pendingContent === null) {
                return;
            }
            if ($chapter->pendingContent === '') {
                ChapterText::where('novel_chapter_id', $chapter->id)->delete();
            } else {
                ChapterText::updateOrCreate(
                    ['novel_chapter_id' => $chapter->id],
                    ['content' => $chapter->pendingContent]
                );
            }
            $chapter->pendingContent = null;
            $chapter->unsetRelation('text');
        });
    }

    /**
     * Scope for active (downloaded and not blacklisted) chapters.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1)->where('blacklist', 0);
    }

    /**
     * Scope for chapters of a specific novel.
     */
    public function scopeForNovel($query, int $novelId)
    {
        return $query->where('novel_id', $novelId);
    }

    /**
     * Scope for non-blacklisted chapters.
     */
    public function scopeNotBlacklisted($query)
    {
        return $query->where('blacklist', 0);
    }

    /**
     * Scope for pending (not downloaded) chapters.
     */
    public function scopePending($query)
    {
        return $query->where('status', 0)->where('blacklist', 0);
    }

    /**
     * Scope to order by latest download date.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('download_date', 'desc')->orderBy('id', 'desc');
    }
}
