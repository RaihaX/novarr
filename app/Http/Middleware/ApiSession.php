<?php

namespace App\Http\Middleware;

use App\Support\AuthMode;
use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the web UI's own same-origin calls to /api/v1/* authenticate with the
 * web session (the `api` group is stateless otherwise).
 *
 * Only when sign-in is on, and only for a request that carries the session
 * or remember-me cookie and no bearer token: the native clients (no cookies)
 * never start a session, so no session file is written per API call.
 * Writes signed in this way need the page's CSRF token (X-CSRF-TOKEN):
 * SameSite=Lax alone would let another site on the same host (another
 * port) or tailnet post with the cookie.
 */
class ApiSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!AuthMode::required() || $request->bearerToken() !== null || !$this->hasSessionCookie($request)) {
            return $next($request);
        }

        // CSRF too: a cookie-signed write must come from this app's own pages
        // (X-CSRF-TOKEN), not from another site on the same host or tailnet.
        return (new Pipeline(app()))
            ->send($request)
            ->through([EncryptCookies::class, StartSession::class, ValidateCsrfToken::class])
            ->then($next);
    }

    private function hasSessionCookie(Request $request): bool
    {
        if ($request->cookies->has((string) config('session.cookie'))) {
            return true;
        }
        // The remember-me cookie alone (session expired): remember_web_<hash>.
        $recaller = Auth::guard('web')->getRecallerName();

        return $request->cookies->has($recaller);
    }
}
