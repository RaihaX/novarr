<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Manga extends Model
{
    use HasFactory;
    use SoftDeletes;
    
    public function chapters() {
        return $this->hasMany('App\MangaChapter');
    }

    public function file() {
        return $this->morphOne('App\File', 'file');
    }
}
