<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A reader's bookmark in an audiobook: a whole-book position and an
 * optional note (500 characters). Private to the reader.
 */
class AudiobookBookmark extends Model
{
    public const UPDATED_AT = null;

    public const MAX_NOTE = 500;

    /** Bookmarks one reader may keep in one audiobook. */
    public const MAX_PER_AUDIOBOOK = 500;

    protected $fillable = ['user_id', 'audiobook_id', 'position_ms', 'note'];

    protected $casts = [
        'user_id' => 'integer',
        'audiobook_id' => 'integer',
        'position_ms' => 'integer',
    ];
}
