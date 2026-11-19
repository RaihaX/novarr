<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One reader's read state of one chapter (table chapter_reads): when it was
 * read, how far in (0–100) and when that position was written. The real key
 * is (user_id, novel_chapter_id); Eloquent has no composite keys, so rows are
 * only read through this model — every write goes through
 * App\Services\ReadState with the query builder.
 */
class ChapterRead extends Model
{
    protected $table = 'chapter_reads';

    protected $primaryKey = 'novel_chapter_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'novel_id' => 'integer',
        'novel_chapter_id' => 'integer',
        'read_at' => 'datetime',
        'bulk' => 'boolean',
        'progress' => 'integer',
        'progress_at' => 'datetime',
    ];
}
