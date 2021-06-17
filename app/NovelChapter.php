<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class NovelChapter extends Model
{
    public function novel() {
        return $this->belongsTo('App\Novel');
    }

    public function getDescriptionAttribute($value) {
        $value = str_replace("<p>", "[[p]]", $value);
        $value = str_replace("</p>", "[[/p]]", $value);
        $value = str_replace(">", "", $value);
        $value = str_replace("<", "", $value);
        $value = str_replace("<p>&nbsp;</p>", "", $value);
        $value = str_replace("[[p]]", "<p>", $value);
        $value = str_replace("[[/p]]", "</p>", $value);

        return $value;
    }
}
