<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Novel extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        "name",
        "slug",
        "author",
        "description",
        "cover",
        "translator_url",
        "status",
        "group_id",
        "language_id",
        "last_update",
        "newest_chapter",
        "no_of_chapters",
        "no_of_views",
        "rating",
        "follows",
        "votes",
        "comments",
        "external_url",
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_update' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'status' => 'boolean',
        'no_of_chapters' => 'integer',
        'no_of_views' => 'integer',
        'rating' => 'float',
        'follows' => 'integer',
        'votes' => 'integer',
        'comments' => 'integer',
    ];

    public function chapters(): HasMany
    {
        return $this->hasMany(NovelChapter::class);
    }

    public function file(): MorphOne
    {
        return $this->morphOne(File::class, "file");
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Scope to eager load common relations.
     */
    public function scopeWithRelations($query)
    {
        return $query->with(['file' => function($q) {
            $q->orderBy('id', 'desc');
        }, 'group', 'language']);
    }

    /**
     * Scope to order by name.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }
}
