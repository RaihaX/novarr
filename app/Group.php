<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    public function novels() {
        return $this->hasMany('App\Novel');
    }
}
