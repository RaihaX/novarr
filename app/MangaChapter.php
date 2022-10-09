<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MangaChapter extends Model
{
    use HasFactory;
    use SoftDeletes;
    
    public function manga() {
        return $this->belongsTo('App\Manga');
    }
}
