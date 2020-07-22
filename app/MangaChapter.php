<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class MangaChapter extends Model
{
    public function manga() {
        return $this->belongsTo('App\Manga');
    }
}
