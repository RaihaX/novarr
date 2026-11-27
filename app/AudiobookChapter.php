<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A chapter of an audiobook: a title and its span in the WHOLE book
 * (start_ms inclusive, end_ms exclusive), whichever parts it lies in.
 */
class AudiobookChapter extends Model
{
    public $timestamps = false;

    protected $fillable = ['audiobook_id', 'idx', 'title', 'start_ms', 'end_ms'];

    protected $casts = [
        'audiobook_id' => 'integer',
        'idx' => 'integer',
        'start_ms' => 'integer',
        'end_ms' => 'integer',
    ];
}
