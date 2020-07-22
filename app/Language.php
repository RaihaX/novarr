<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    public function novels() {
        return $this->hasMany('App\Novel');
    }
}
