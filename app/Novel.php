<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Novel extends Model
{
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
