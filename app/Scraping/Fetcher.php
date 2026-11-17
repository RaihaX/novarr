<?php

namespace App\Scraping;

use Carbon\Carbon;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one place scraper code touches the network.
 *
 * Every page/API fetch made by the source adapters and the chapter/TOC
 * helpers goes through an instance resolved with app(Fetcher::class), so a
 * test can swap in a fake with
 *     app()->instance(Fetcher::class, new \Tests\Support\FakeFetcher([...]));
 * and drive the real parsers against saved fixtures without any network.
 *
 * The global helpers fetchWithBrowser(), fetchWithBrowserSession() and
 * createHttpClient() are thin delegators to this class, so existing callers
 * keep working unchanged.
 */
class Fetcher
{
    /** Browser-like headers for the plain (non-FlareSolverr) client. */
    public const DEFAULT_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Connection' => 'keep-alive',
        'Upgrade-Insecure-Requests' => '1',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-User' => '?1',
        'Cache-Control' => 'max-age=0',
    ];

    /** Target statuses that mean "slow down": reported as reason 'rate_limited'. */
    public const RATE_LIMIT_STATUSES = [429, 503];

    /** Longest Retry-After honoured (60 minutes). */
    public const RETRY_AFTER_CAP_SECONDS = 3600;

    /** Markers of a Cloudflare challenge page (not just any mention of Cloudflare). */
    public const CLOUDFLARE_CHALLENGE_MARKERS = [
        '<title>Just a moment...</title>',
        'cf-challenge-running',
        'Verifying you are human',
    ];

    /** @var array<string, HttpClientInterface> built clients, keyed by kind + timeout */
    private array $clients = [];

    /**
     * Retry-After seconds per host from the latest rate-limited answer.
     * Static because app(Fetcher::class) hands out a fresh instance per call;
     * the scraper reads it with pullRetryAfter() after a rate_limited fetch.
     * Every answer the Fetcher classifies for a host overwrites the entry
     * (set on a rate limit with a usable Retry-After, removed otherwise), so
     * a value nobody pulled can't be applied to a later rate limit.
     *
     * @var array<string, int>
     */
    private static array $retryAfter = [];

    /** Cache key prefix of the per-host clearance store (see clearance()). */
    public const CLEARANCE_CACHE_PREFIX = 'novarr:clearance:';

    /**
     * Cookies kept in a clearance entry and sent on the plain request: the
     * same set the TOC helpers have always sent (just cf_clearance).
     */
    public const CLEARANCE_COOKIES = ['cf_clearance'];

    /**
     * Fetch page HTML through FlareSolverr (bypasses Cloudflare).
     *
     * Returns null on failure; $reason then says why: 'decode_failed',
     * 'flaresolverr_error', 'http_404', 'http_4xx', 'http_5xx',
     * 'rate_limited' (target answered 429/503), 'empty_response' or
     * 'exception'.
     *
     * $waitForSelector is accepted for existing callers but not used:
     * FlareSolverr's request.get has no wait-for-selector option.
     */
    public function html(string $url, ?string &$reason = null, ?string $waitForSelector = null, int $maxAttempts = 3): ?string
    {
        $solution = $this->solve($url, $reason, $maxAttempts);
        if ($solution === null) {
            return null;
        }
        $this->rememberSolution($url, $solution);

        return $solution['response'];
    }

    /**
     * A page, as cheaply as the host allows: a plain GET carrying the host's
     * cached Cloudflare clearance (~0.4 s) when there is one, else (or when
     * that is refused) html() through FlareSolverr (~7 s), whose solve
     * stores/refreshes the clearance for the next page.
     *
     * Plain answer handling:
     *   200 with real content    -> returned ($via 'plain')
     *   404                      -> null, reason 'http_404', no fallback, entry kept
     *   429                      -> null, reason 'rate_limited' (Retry-After recorded
     *                               by plain()), no fallback, entry kept
     *   anything else (403, 503, a challenge body, empty body, transport
     *   error, other statuses)   -> entry forgotten, FlareSolverr fallback
     *
     * With novarr.clearance_minutes = 0 this is exactly html().
     * $via reports which path produced the result: 'plain' or 'flaresolverr'.
     */
    public function page(string $url, ?string &$reason = null, ?string &$via = null): ?string
    {
        $reason = null;
        $via = null;

        $entry = $this->clearance($url);
        if ($entry !== null) {
            $plain = $this->plainWithClearance($url, $entry);
            if (!$plain['fallback']) {
                $via = 'plain';
                $reason = $plain['reason'];
                return $plain['html'];
            }
        }

        $via = 'flaresolverr';

        return $this->html($url, $reason);
    }

    /**
     * htmlWithSession() through the clearance store: with a cached entry for
     * the host, the page is tried plain first and the entry's cookie and
     * user agent are handed back as the session; otherwise (or when the
     * plain request is refused, as for page()) a FlareSolverr solve, which
     * refreshes the entry. Same return shape as htmlWithSession(). With
     * novarr.clearance_minutes = 0 this is exactly htmlWithSession().
     *
     * @return array{html: ?string, cf_clearance: ?string, user_agent: ?string}
     */
    public function sessionPage(string $url, ?string &$reason = null, ?string &$via = null): array
    {
        $reason = null;
        $via = null;

        $entry = $this->clearance($url);
        if ($entry !== null) {
            $plain = $this->plainWithClearance($url, $entry);
            if (!$plain['fallback']) {
                $via = 'plain';
                $reason = $plain['reason'];
                if ($plain['html'] === null) {
                    return ['html' => null, 'cf_clearance' => null, 'user_agent' => null];
                }

                return [
                    'html' => $plain['html'],
                    'cf_clearance' => $entry['cookies']['cf_clearance'] ?? null,
                    'user_agent' => $entry['user_agent'],
                ];
            }
        }

        $via = 'flaresolverr';

        return $this->htmlWithSession($url, $reason);
    }

    /**
     * The cached clearance for the URL's host (or a bare host), or null when
     * there is none, it expired, or the feature is off.
     *
     * Cloudflare ties cf_clearance to the user agent AND the client IP it
     * was issued to. The plain request therefore sends exactly the stored
     * user agent, and the whole scheme only works while FlareSolverr and
     * Novarr reach the site from the same public IP. If they ever leave from
     * different IPs, every plain request is refused (403/challenge), the
     * entry is forgotten and the FlareSolverr fallback serves the page:
     * correct, just one wasted ~0.4 s request per page.
     *
     * @return array{cookies: array<string,string>, user_agent: string}|null
     */
    public function clearance(string $urlOrHost): ?array
    {
        $key = $this->clearanceKey($urlOrHost);
        if ($key === null) {
            return null;
        }

        try {
            $entry = \Cache::get($key);
        } catch (\Throwable $e) {
            \Log::debug("Fetcher: clearance cache read failed: " . $e->getMessage());
            return null;
        }

        if (!is_array($entry) || empty($entry['cookies']['cf_clearance']) || empty($entry['user_agent'])) {
            return null;
        }

        return ['cookies' => (array) $entry['cookies'], 'user_agent' => (string) $entry['user_agent']];
    }

    /**
     * Store (replace) the host's clearance for novarr.clearance_minutes.
     * Nothing is stored without a cf_clearance cookie and a user agent, or
     * when the feature is off.
     *
     * @param array<string,string> $cookies name => value (only CLEARANCE_COOKIES are kept)
     */
    public function rememberClearance(string $urlOrHost, array $cookies, ?string $userAgent): void
    {
        $key = $this->clearanceKey($urlOrHost);
        $cookies = array_intersect_key($cookies, array_flip(self::CLEARANCE_COOKIES));
        if ($key === null || empty($cookies['cf_clearance']) || $userAgent === null || $userAgent === '') {
            return;
        }

        try {
            \Cache::put($key, ['cookies' => $cookies, 'user_agent' => $userAgent], now()->addMinutes($this->clearanceMinutes()));
        } catch (\Throwable $e) {
            \Log::debug("Fetcher: clearance cache write failed: " . $e->getMessage());
        }
    }

    /** Drop the host's clearance (the next page() goes through FlareSolverr). */
    public function forgetClearance(string $urlOrHost): void
    {
        $key = $this->clearanceKey($urlOrHost);
        if ($key === null) {
            return;
        }

        try {
            \Cache::forget($key);
        } catch (\Throwable $e) {
            \Log::debug("Fetcher: clearance cache forget failed: " . $e->getMessage());
        }
    }

    /** Minutes a clearance entry lives; 0 turns the whole store off. */
    public function clearanceMinutes(): int
    {
        return max(0, (int) config('novarr.clearance_minutes', 25));
    }

    /**
     * One plain GET with a clearance entry, judged for page()/sessionPage().
     * 'fallback' true means the entry has been forgotten and FlareSolverr
     * should serve the page; otherwise html/reason are the final answer.
     *
     * @param array{cookies: array<string,string>, user_agent: string} $entry
     * @return array{html: ?string, reason: ?string, fallback: bool}
     */
    private function plainWithClearance(string $url, array $entry): array
    {
        $status = null;
        // Same headers the TOC helpers' plain path has always sent: the
        // client's DEFAULT_HEADERS with the clearance's own User-Agent, plus
        // the Cookie header.
        $body = $this->plain($url, ['User-Agent' => $entry['user_agent']], $entry['cookies'], $status);

        if ($body !== null && !self::isCloudflareChallenge($body)) {
            return ['html' => $body, 'reason' => null, 'fallback' => false];
        }
        if ($status === 404) {
            // Exactly what FlareSolverr would report for the same page.
            return ['html' => null, 'reason' => 'http_404', 'fallback' => false];
        }
        if ($status === 429) {
            // The host asked us to slow down: plain() has recorded its
            // Retry-After; going round through FlareSolverr would ignore it.
            return ['html' => null, 'reason' => 'rate_limited', 'fallback' => false];
        }

        // 403 / 503 / challenge body / empty body / transport error / other:
        // the clearance no longer gets us in. A 503 here is a Cloudflare
        // refusal, not a rate limit: drop the Retry-After plain() recorded
        // for it so it can't leak into a later rate-limited answer.
        $what = $body !== null ? 'a challenge page' : ($status === 200 ? 'an empty body' : (string) ($status ?? 'no response'));
        \Log::debug("Fetcher: plain request with clearance got {$what} for {$url}; forgetting the clearance, using FlareSolverr");
        if ($status === 503) {
            self::recordRetryAfter($url, null);
        }
        $this->forgetClearance($url);

        return ['html' => null, 'reason' => null, 'fallback' => true];
    }

    /** Store the clearance a FlareSolverr solve carried (if any) for the URL's host. */
    private function rememberSolution(string $url, array $solution): void
    {
        if ($this->clearanceMinutes() <= 0) {
            return;
        }

        $cookies = [];
        foreach ($solution['cookies'] ?? [] as $cookie) {
            $name = $cookie['name'] ?? null;
            if (is_string($name) && in_array($name, self::CLEARANCE_COOKIES, true) && isset($cookie['value'])) {
                $cookies[$name] = (string) $cookie['value'];
            }
        }

        // No cf_clearance: the site isn't challenging right now. Store
        // nothing (an existing entry is left to expire or be refused).
        if (empty($cookies['cf_clearance'])) {
            return;
        }

        $this->rememberClearance($url, $cookies, is_string($solution['userAgent'] ?? null) ? $solution['userAgent'] : null);
    }

    /** Cache key for the URL's (or bare host's) clearance; null when off or host-less. */
    private function clearanceKey(string $urlOrHost): ?string
    {
        if ($this->clearanceMinutes() <= 0) {
            return null;
        }

        $host = str_contains($urlOrHost, '://') ? parse_url($urlOrHost, PHP_URL_HOST) : $urlOrHost;
        if (!is_string($host) || $host === '') {
            return null;
        }

        return self::CLEARANCE_CACHE_PREFIX . strtolower($host);
    }

    /**
     * Like html() but also returns the Cloudflare clearance cookie and
     * user-agent, so later same-site pages can be fetched with plain() and
     * the cf_clearance cookie instead of a full browser render each time.
     * Same retries and failure reasons as html(). On failure 'html' is null.
     *
     * @return array{html: ?string, cf_clearance: ?string, user_agent: ?string}
     */
    public function htmlWithSession(string $url, ?string &$reason = null): array
    {
        $solution = $this->solve($url, $reason, 3);
        if ($solution === null) {
            return ['html' => null, 'cf_clearance' => null, 'user_agent' => null];
        }
        $this->rememberSolution($url, $solution);

        $cf = null;
        foreach ($solution['cookies'] ?? [] as $cookie) {
            if (($cookie['name'] ?? '') === 'cf_clearance') {
                $cf = $cookie['value'] ?? null;
                break;
            }
        }

        return [
            'html' => $solution['response'],
            'cf_clearance' => $cf,
            'user_agent' => $solution['userAgent'] ?? null,
        ];
    }

    /**
     * Plain HTTP GET with browser-like headers. Returns the body on a 200
     * (non-empty), else null; $status receives the HTTP status, or
     * 'exception' when the request itself failed. Never throws. A 429/503
     * answer records its Retry-After (see pullRetryAfter()).
     *
     * @param array<string,string> $headers extra/overriding request headers
     * @param array<string,string> $cookies name => value, sent as one Cookie header
     */
    public function plain(string $url, array $headers = [], array $cookies = [], &$status = null): ?string
    {
        $status = null;

        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers['Cookie'] = implode('; ', $pairs);
        }

        try {
            $response = $this->client()->request('GET', $url, ['headers' => $headers]);
            $status = $response->getStatusCode();
            if (self::isRateLimitStatus($status)) {
                self::recordRetryAfter($url, self::retryAfterFromHeaders($response->getHeaders(false)));
                return null;
            }
            self::recordRetryAfter($url, null);
            if ($status !== 200) {
                return null;
            }
            $body = $response->getContent(false);

            return $body === '' ? null : $body;
        } catch (\Throwable $e) {
            $status = 'exception';
            \Log::debug("Fetcher::plain failed for {$url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * GET a JSON endpoint. Returns the decoded array, or null on a non-200,
     * transport error or undecodable body (logged). $status receives what
     * plain() reported (HTTP status or 'exception').
     */
    public function json(string $url, array $headers = [], &$status = null): ?array
    {
        $status = null;
        $body = $this->plain($url, ['Accept' => 'application/json'] + $headers, [], $status);

        if ($body === null) {
            \Log::warning("Fetcher::json HTTP " . ($status ?? 'no response') . " for {$url}");
            return null;
        }

        $json = json_decode($body, true);

        return is_array($json) ? $json : null;
    }

    /**
     * Binary GET streamed to a file (manga page images). $headers override
     * the client's defaults (a source that needs its own User-Agent passes
     * it here). Returns true when a 200 answer was written to $path in full;
     * otherwise false, nothing left at $path, and $meta['reason'] says why:
     * 'rate_limited' (429/503, Retry-After recorded for the host as for
     * plain()), 'http_404', 'http_4xx', 'http_5xx', 'too_large' (over
     * $maxBytes; 0 = no cap), 'empty_response' or 'exception'. $status is
     * the HTTP status (or 'exception'). Never throws.
     *
     * @param array<string,string> $headers
     * @param-out array{bytes: int, content_type: ?string, cached: bool, ms: int, reason: ?string} $meta
     */
    public function download(string $url, string $path, array $headers = [], int $maxBytes = 0, &$status = null, ?array &$meta = null): bool
    {
        $status = null;
        $started = microtime(true);
        $meta = ['bytes' => 0, 'content_type' => null, 'cached' => false, 'ms' => 0, 'reason' => null];
        $finish = function (bool $ok, ?string $reason) use (&$meta, $started, $path): bool {
            $meta['reason'] = $ok ? null : $reason;
            $meta['ms'] = (int) round((microtime(true) - $started) * 1000);
            if (!$ok) {
                @unlink($path);
            }

            return $ok;
        };

        $handle = null;
        try {
            $response = $this->client()->request('GET', $url, [
                'headers' => ['Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8,*/*;q=0.5'] + $headers,
                'buffer' => false,
            ]);
            $status = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);

            if (self::isRateLimitStatus($status)) {
                self::recordRetryAfter($url, self::retryAfterFromHeaders($responseHeaders));
                $response->cancel();
                return $finish(false, 'rate_limited');
            }
            self::recordRetryAfter($url, null);
            if ($status !== 200) {
                $response->cancel();
                return $finish(false, match (true) {
                    $status === 404 => 'http_404',
                    $status >= 500 => 'http_5xx',
                    default => 'http_4xx',
                });
            }

            $meta['content_type'] = $responseHeaders['content-type'][0] ?? null;
            $meta['cached'] = str_starts_with(strtoupper((string) ($responseHeaders['x-cache'][0] ?? '')), 'HIT');
            $length = $responseHeaders['content-length'][0] ?? null;
            if ($maxBytes > 0 && is_numeric($length) && (int) $length > $maxBytes) {
                $response->cancel();
                return $finish(false, 'too_large');
            }

            $handle = fopen($path, 'wb');
            if ($handle === false) {
                $response->cancel();
                return $finish(false, 'exception');
            }

            foreach ($this->client()->stream($response) as $chunk) {
                $data = $chunk->getContent();
                if ($data === '') {
                    continue;
                }
                $meta['bytes'] += strlen($data);
                if ($maxBytes > 0 && $meta['bytes'] > $maxBytes) {
                    $response->cancel();
                    fclose($handle);
                    $handle = null;
                    return $finish(false, 'too_large');
                }
                fwrite($handle, $data);
            }
            fclose($handle);
            $handle = null;

            return $finish($meta['bytes'] > 0, 'empty_response');
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $status = 'exception';
            \Log::debug("Fetcher::download failed for {$url}: " . $e->getMessage());
            return $finish(false, 'exception');
        }
    }

    /**
     * POST a JSON body (best effort, e.g. MangaDex@Home's image report).
     * Returns the HTTP status, or null when the request failed. Never throws.
     *
     * @param array<string,string> $headers
     */
    public function postJson(string $url, array $body, array $headers = []): ?int
    {
        try {
            $response = $this->client()->request('POST', $url, [
                'headers' => ['Accept' => 'application/json'] + $headers,
                'json' => $body,
                'timeout' => 10,
            ]);

            return $response->getStatusCode();
        } catch (\Throwable $e) {
            \Log::debug("Fetcher::postJson failed for {$url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * POST a JSON body and decode a JSON answer (a GraphQL endpoint such as
     * Suwayomi-Server's). Returns the decoded array on a 200, else null;
     * $status receives the HTTP status, or 'exception' when the request
     * itself failed (transport error, timeout, undecodable body counts as
     * the 200 it was). $timeout is the idle timeout in seconds — a GraphQL
     * call that has the server fetch from a slow site can take a minute.
     * Never throws.
     *
     * Separate from postJson(), which only reports a status with a short
     * timeout (fire-and-forget reports).
     *
     * @param array<string,string> $headers
     */
    public function postJsonForJson(string $url, array $body, array $headers = [], int $timeout = 30, &$status = null): ?array
    {
        $status = null;

        try {
            $response = $this->client()->request('POST', $url, [
                'headers' => ['Accept' => 'application/json'] + $headers,
                'json' => $body,
                'timeout' => max(1, $timeout),
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                \Log::warning("Fetcher::postJsonForJson HTTP {$status} for {$url}");
                return null;
            }
            $json = json_decode($response->getContent(false), true);

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            $status = 'exception';
            \Log::warning("Fetcher::postJsonForJson failed for {$url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * The wait a rate-limited answer asks for, from its response headers:
     * Retry-After (seconds or an HTTP date), else X-RateLimit-Retry-After
     * (MangaDex: the Unix time the window ends). Capped as parseRetryAfter().
     *
     * @param array<string, string[]> $headers lower-cased names, as Symfony returns them
     */
    public static function retryAfterFromHeaders(array $headers): ?int
    {
        $seconds = self::parseRetryAfter($headers['retry-after'][0] ?? null);
        if ($seconds !== null) {
            return $seconds;
        }

        $until = $headers['x-ratelimit-retry-after'][0] ?? null;
        if (is_scalar($until) && ctype_digit(trim((string) $until)) && (int) $until > 1000000000) {
            return max(0, min((int) $until - Carbon::now()->getTimestamp(), self::RETRY_AFTER_CAP_SECONDS));
        }

        return null;
    }

    /**
     * A configured Symfony HTTP client with browser-like headers (what the
     * createHttpClient() helper returns). Built once per Fetcher instance.
     * Prefer plain()/json() in scraper code so tests can intercept the
     * request.
     */
    public function client(): HttpClientInterface
    {
        return $this->clients['plain'] ??= $this->buildClient([
            'timeout' => 30,
            // TLS verification on (audit F34); config('novarr.tls_verify')
            // exists only as an escape hatch for a site with a broken chain.
            'verify_peer' => $this->tlsVerify(),
            'verify_host' => $this->tlsVerify(),
            'headers' => self::DEFAULT_HEADERS,
        ]);
    }

    /**
     * POST form data to $url through FlareSolverr (request.post) and return
     * the target's response body, or null on failure ($reason says why, as
     * for html()). Single attempt.
     */
    public function post(string $url, string $postData, ?string &$reason = null): ?string
    {
        $reason = null;

        try {
            $response = $this->flareSolverrClient(60)->request('POST', $this->flareSolverrUrl(), [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['cmd' => 'request.post', 'url' => $url, 'postData' => $postData, 'maxTimeout' => 60000],
            ]);
            $outcome = $this->classify($url, json_decode($response->getContent(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE));
            $reason = $outcome['reason'];

            return $outcome['html'];
        } catch (\Throwable $e) {
            $reason = 'exception';
            \Log::warning("Fetcher::post failed for {$url}: " . $e->getMessage());
            return null;
        }
    }

    /** Is this HTTP status a "slow down" answer (429 / 503)? */
    public static function isRateLimitStatus($status): bool
    {
        return is_int($status) && in_array($status, self::RATE_LIMIT_STATUSES, true);
    }

    /** Does this page look like a Cloudflare challenge rather than content? */
    public static function isCloudflareChallenge(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }

        foreach (self::CLOUDFLARE_CHALLENGE_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds to wait from a Retry-After header value: delta-seconds or an
     * HTTP date, capped at RETRY_AFTER_CAP_SECONDS. Null when absent or
     * unparseable. Pure apart from the clock.
     */
    public static function parseRetryAfter($value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $seconds = (int) $value;
        } else {
            try {
                $seconds = (int) Carbon::now()->diffInSeconds(Carbon::parse($value), false);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return max(0, min($seconds, self::RETRY_AFTER_CAP_SECONDS));
    }

    /**
     * The Retry-After (seconds) the host sent with its latest 429/503, or
     * null. Read once: the value is forgotten so it can't leak into a later
     * failure.
     */
    public static function pullRetryAfter(string $host): ?int
    {
        $host = strtolower($host);
        $seconds = self::$retryAfter[$host] ?? null;
        unset(self::$retryAfter[$host]);

        return $seconds;
    }

    /**
     * Hand a recorded Retry-After from the host that answered (an API or
     * image host) to the host the scraper keeps its cooldown under (the
     * series' site host), so ChapterScraper::noteHostFailure() finds it.
     */
    public static function moveRetryAfter(string $fromHost, string $toHost): void
    {
        $seconds = self::pullRetryAfter($fromHost);
        if ($seconds !== null) {
            self::$retryAfter[strtolower($toHost)] = $seconds;
        }
    }

    /** Remember (or, with null, clear) a host's Retry-After. */
    protected static function recordRetryAfter(string $url, ?int $seconds): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return;
        }

        $host = strtolower($host);
        if ($seconds === null) {
            unset(self::$retryAfter[$host]);
        } else {
            self::$retryAfter[$host] = $seconds;
        }
    }

    /**
     * request.get through FlareSolverr with retry/backoff. Returns the
     * FlareSolverr `solution` (its 'response' a non-empty string) or null,
     * with $reason set as for html().
     */
    private function solve(string $url, ?string &$reason, int $maxAttempts): ?array
    {
        $flareSolverrUrl = $this->flareSolverrUrl();
        $maxAttempts = max(1, $maxAttempts);
        $lastError = null;
        $reason = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $retry = true;

            try {
                \Log::debug("Fetching URL via FlareSolverr (attempt {$attempt}/{$maxAttempts}): {$url}");

                $response = $this->flareSolverrClient()->request('POST', $flareSolverrUrl, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => [
                        'cmd' => 'request.get',
                        'url' => $url,
                        'maxTimeout' => 60000,
                    ],
                ]);

                // FlareSolverr embeds page HTML (with raw control chars /
                // invalid UTF-8) in the JSON; decode leniently.
                $data = json_decode($response->getContent(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                $outcome = $this->classify($url, $data);

                if ($outcome['status'] !== null) {
                    \Log::debug("FlareSolverr target status {$outcome['status']} for {$url}");
                }

                if ($outcome['html'] !== null) {
                    \Log::debug("Successfully fetched URL via FlareSolverr: {$url} (length: " . strlen($outcome['html']) . ")");
                    $reason = null;
                    return $data['solution'];
                }

                $reason = $outcome['reason'];
                $retry = $outcome['retry'];
                $lastError = match ($reason) {
                    'decode_failed' => 'decode_failed: ' . json_last_error_msg(),
                    'flaresolverr_error' => 'flaresolverr_error: ' . ($data['message'] ?? 'Unknown error'),
                    'http_404', 'http_4xx', 'http_5xx', 'rate_limited' => "{$reason} (target status {$outcome['status']})",
                    default => $reason,
                };
            } catch (\Exception $e) {
                $reason = 'exception';
                $lastError = 'exception: ' . $e->getMessage();
            }

            if (!$retry) {
                \Log::warning("FlareSolverr fetch failed for {$url}: {$lastError} (not retrying)");
                return null;
            }

            if ($attempt < $maxAttempts) {
                $delay = 2 ** $attempt; // 2s, 4s
                \Log::warning("FlareSolverr attempt {$attempt} failed for {$url} ({$lastError}); retrying in {$delay}s");
                $this->sleepSeconds($delay);
            }
        }

        \Log::error("FlareSolverr failed after {$maxAttempts} attempts for URL {$url}: {$lastError}");
        return null;
    }

    /**
     * classifyFlareSolverrResponse() plus the rate-limit rule: a 429/503
     * from the target is 'rate_limited', not retried here (hammering a
     * host that asked us to slow down only makes it worse), and its
     * Retry-After header — when FlareSolverr passes headers through — is
     * recorded for the host.
     *
     * @return array{html: ?string, reason: ?string, retry: bool, status: ?int}
     */
    private function classify(string $url, $data): array
    {
        $outcome = classifyFlareSolverrResponse($data);

        if (self::isRateLimitStatus($outcome['status']) && $outcome['html'] === null) {
            $headers = array_change_key_case((array) ($data['solution']['headers'] ?? []), CASE_LOWER);
            self::recordRetryAfter($url, self::parseRetryAfter($headers['retry-after'] ?? null));

            return ['html' => null, 'reason' => 'rate_limited', 'retry' => false, 'status' => $outcome['status']];
        }

        // Any other answer from the target replaces a stale Retry-After.
        // (A FlareSolverr error carries no target status: nothing to judge.)
        if ($outcome['status'] !== null || $outcome['html'] !== null) {
            self::recordRetryAfter($url, null);
        }

        return $outcome;
    }

    protected function tlsVerify(): bool
    {
        return (bool) config('novarr.tls_verify', true);
    }

    /** Client for talking to FlareSolverr itself (TLS verification per config); one per timeout. */
    protected function flareSolverrClient(int $timeout = 120): HttpClientInterface
    {
        return $this->clients["flaresolverr:{$timeout}"] ??= $this->buildClient([
            'timeout' => $timeout,
            'verify_peer' => $this->tlsVerify(),
            'verify_host' => $this->tlsVerify(),
        ]);
    }

    /** Build a Symfony client. Overridable so tests can hand in a MockHttpClient. */
    protected function buildClient(array $options): HttpClientInterface
    {
        return HttpClient::create($options);
    }

    /** Wait between FlareSolverr retries. Overridable so tests don't sleep. */
    protected function sleepSeconds(int $seconds): void
    {
        sleep($seconds);
    }

    protected function flareSolverrUrl(): string
    {
        return (string) setting('flaresolverr_url', config('novarr.flaresolverr_url'));
    }
}
