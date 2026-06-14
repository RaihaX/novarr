<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        // Read-state endpoints are replayed from the offline sync queue, where a
        // session CSRF token may have rotated. This is a single-user app behind
        // Tailscale with no auth, so the tokenless replay is acceptable here.
        'chapters/*/toggle-read',
        'chapters/*/read-through',
        'chapters/bulk-read',
    ];
}
