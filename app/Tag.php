<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = ['name'];

    public function novels(): BelongsToMany
    {
        return $this->belongsToMany(Novel::class, 'novel_tag');
    }
}
