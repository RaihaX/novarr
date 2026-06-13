<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $table = 'app_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * All settings as a [key => value] map, cached until a write clears it.
     */
    public static function all($columns = ['*'])
    {
        return Cache::rememberForever('settings_all', fn() => static::query()->pluck('value', 'key'));
    }

    public static function get(string $key, $default = null)
    {
        $value = static::all()->get($key);

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings_all');
    }
}
