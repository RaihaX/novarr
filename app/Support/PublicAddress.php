<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The public address: the hostname(s) Novarr is published on to the internet
 * (config novarr.public_hosts, env NOVARR_PUBLIC_HOSTS), e.g. through a
 * Cloudflare Tunnel. The LAN and Tailscale addresses are everything else.
 *
 * On a public-host request (App\Http\Middleware\IdentifyReader,
 * App\Http\Middleware\HardenPublicAddress, the `admin` gate):
 *   - admin features are unavailable, even to the admin;
 *   - sign-in is required (never "everyone is the owner");
 *   - URLs are https on the public host, cookies Secure, HSTS, noindex,
 *     baseline security headers;
 *   - tighter per-IP limits on credential attempts (App\Services\Credentials).
 * Invite and app-download links use url() whatever host the admin is on.
 *
 * No hosts configured = the feature is off and nothing changes. Everything is
 * read from config and the request per call (no statics): Octane-safe.
 */
final class PublicAddress
{
    public const ADMIN_MESSAGE = 'Admin features are only available on your home network.';

    public const SIGN_IN_OFF_MESSAGE = 'This address is only available once sign-in is turned on.';

    /** Heading of the refusal page on the public address. */
    public const TITLE = 'Not available at this address';

    /** @return list<string> lowercased hostnames, no port */
    public static function hosts(): array
    {
        $hosts = config('novarr.public_hosts', []);
        if (!is_array($hosts)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn($host) => is_string($host) ? strtolower(trim($host)) : '',
            $hosts
        ), fn(string $host) => $host !== ''));
    }

    /** Whether a public address is set up (the feature is on). */
    public static function configured(): bool
    {
        return static::hosts() !== [];
    }

    /**
     * The public base URL (no trailing slash): NOVARR_PUBLIC_URL, else
     * https:// + the first public host; null when none is configured.
     */
    public static function url(): ?string
    {
        $hosts = static::hosts();
        if ($hosts === []) {
            return null;
        }
        $url = config('novarr.public_url');

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : 'https://' . $hosts[0];
    }

    /**
     * Whether $request came in on a public host (case-insensitive, port
     * ignored). Both the request's host (which honours X-Forwarded-Host from
     * trusted proxies only) and the raw Host header are checked, and either
     * one matching counts: a forwarded header can never turn a request that
     * arrived on the public hostname into a home-network one.
     */
    public static function isPublicRequest(Request $request): bool
    {
        return static::matchedHost($request) !== null;
    }

    /** The public host $request came in on, or null. */
    public static function matchedHost(Request $request): ?string
    {
        $hosts = static::hosts();
        if ($hosts === []) {
            return null;
        }

        $candidates = [
            static::normaliseHost($request->getHost()),
            static::normaliseHost((string) $request->headers->get('host', '')),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && in_array($candidate, $hosts, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether the current request is on a public host. No runningInConsole()
     * shortcut: Octane workers run under the CLI SAPI. A console command's
     * request is the synthetic http://localhost one, never public.
     */
    public static function current(): bool
    {
        if (!static::configured() || !app()->bound('request')) {
            return false;
        }

        return static::isPublicRequest(app('request'));
    }

    /** "Read.Example.com:443" → "read.example.com"; "[::1]:80" → "[::1]". */
    private static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }

        return (string) preg_replace('/:\d*$/', '', $host);
    }
}
