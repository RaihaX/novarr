<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Novel extends Model
{
    use HasFactory;
    use SoftDeletes;

    public function chapters() {
        return $this->hasMany('App\NovelChapter');
    }

    public function file() {
        return $this->morphOne('App\File', 'file');
    }

    public function group() {
        return $this->belongsTo('App\Group');
    }

    public function language() {
        return $this->belongsTo('App\Language');
    }
}
