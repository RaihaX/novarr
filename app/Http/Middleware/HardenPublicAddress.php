<?php

namespace App\Http\Middleware;

use App\Support\PublicAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (after TrustProxies): HTTPS and crawler hygiene on the
 * public address (App\Support\PublicAddress). Who may call what there is
 * App\Http\Middleware\IdentifyReader's job.
 *
 * On a public-host request:
 *   - URLs generated during the request are https://<public host>/…
 *     (whatever the forwarded headers say);
 *   - every cookie the response sets (session, XSRF, remember-me) is Secure;
 *   - Strict-Transport-Security, X-Robots-Tag: noindex, and baseline security
 *     headers (nosniff, Referrer-Policy, X-Frame-Options) are added.
 * Other hosts (LAN, Tailscale) are untouched. No public address configured:
 * the middleware does nothing at all.
 *
 * Octane-safe: the URL generator is sandboxed per request by Octane and is
 * set on every request here (forced on the public host, cleared elsewhere,
 * so a reused generator — the test client's — never carries it over); the
 * cookie change is made on this response's own cookies.
 */
class HardenPublicAddress
{
    public const HSTS = 'max-age=31536000';

    public const ROBOTS = 'noindex, nofollow, noarchive';

    /** Added on public-host responses when the response has not set them. */
    public const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'X-Frame-Options' => 'DENY',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!PublicAddress::configured()) {
            return $next($request);
        }

        $host = PublicAddress::matchedHost($request);
        if ($host === null) {
            URL::forceRootUrl(null);
            URL::forceScheme(null);

            return $next($request);
        }

        URL::forceScheme('https');
        URL::forceRootUrl('https://' . $host);

        $response = $next($request);

        foreach ($response->headers->getCookies() as $cookie) {
            if (!$cookie->isSecure()) {
                $response->headers->setCookie($cookie->withSecure(true));
            }
        }

        $response->headers->set('Strict-Transport-Security', self::HSTS);
        $response->headers->set('X-Robots-Tag', self::ROBOTS);
        foreach (self::SECURITY_HEADERS as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
