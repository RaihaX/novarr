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

        return $solution === null ? null : $solution['response'];
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
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
                self::recordRetryAfter($url, self::parseRetryAfter($retryAfter));
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
