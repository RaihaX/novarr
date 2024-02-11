<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NovelChapter extends Model
{
    protected $fillable = [
        "novel_id",
        "chapter",
        "label",
        "description",
        "url",
        "book",
        "unique_id",
    ];

    public function novel()
    {
        return $this->belongsTo("App\Novel");
    }

    public function getDescriptionAttribute($value)
    {
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
