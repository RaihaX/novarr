<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Manga extends Model
{
    public function chapters() {
        return $this->hasMany('App\MangaChapter');
    }

    public function file() {
        return $this->morphOne('App\File', 'file');
    }
}
