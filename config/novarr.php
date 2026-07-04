<?php

/*
|--------------------------------------------------------------------------
| Novarr integration endpoints
|--------------------------------------------------------------------------
|
| env() must only be read inside config files — once `config:cache` is in
| play, .env is no longer loaded at runtime and env() calls elsewhere
| silently return null. Runtime code reads these via config('novarr.*'),
| usually as the fallback behind the DB-backed setting() store.
|
*/

return [
    'flaresolverr_url' => env('FLARESOLVERR_URL', 'http://192.168.1.41:8191/v1'),
    'notification_webhook_url' => env('NOTIFICATION_WEBHOOK_URL'),
];
