<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class NovelChapter extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        "novel_id",
        "chapter",
        "label",
        "description",
        "url",
        "book",
        "unique_id",
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'download_date' => 'datetime',
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

    public function getDescriptionAttribute($value): string
    {
        $value = str_replace("<p>", "[[p]]", $value);
        $value = str_replace("</p>", "[[/p]]", $value);
        $value = str_replace(">", "", $value);
        $value = str_replace("<", "", $value);
        $value = str_replace("<p>&nbsp;</p>", "", $value);
        $value = str_replace("[[p]]", "<p>", $value);
        $value = str_replace("[[/p]]", "</p>", $value);

        return $value;
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
