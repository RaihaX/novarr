<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class NovelChapter extends Model
{
    public function novel() {
        return $this->belongsTo('App\Novel');
    }

    public function getDescriptionAttribute($value) {
//        $value = str_replace("<Void-ification Undying Technique>", "[Void-ification Undying Technique]", $value);
//
//        $value = str_replace("<Scarlet Cloud Combat Technique>", "[Scarlet Cloud Combat Technique]", $value);
//
//        $value = str_replace("<Southern Cloud 12 Sacred Styles>", "[Southern Cloud 12 Sacred Styles]", $value);
//
//        $value = str_replace("<Scripture of the Past>", "[Scripture of the Past]", $value);
//
//        $value = str_replace("<Five Phases Sealing Technique>", "[Five Phases Sealing Technique]", $value);
//
//        $value = str_replace("<Three Realms Technique>", "[Three Realms Technique]", $value);

        $value = str_replace("<p>", "[[p]]", $value);
        $value = str_replace("</p>", "[[/p]]", $value);
        $value = str_replace(">", "", $value);
        $value = str_replace("<", "", $value);
        $value = str_replace("[[p]]", "<p>", $value);
        $value = str_replace("[[/p]]", "</p>", $value);

        return $value;
    }
}
