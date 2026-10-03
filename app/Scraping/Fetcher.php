<?php

namespace App\Scraping;

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

    /**
     * Fetch page HTML through FlareSolverr (bypasses Cloudflare).
     *
     * Returns null on failure; $reason then says why: 'decode_failed',
     * 'flaresolverr_error', 'http_404', 'http_4xx', 'http_5xx',
     * 'empty_response' or 'exception'.
     */
    public function html(string $url, ?string &$reason = null, ?string $waitForSelector = null, int $maxAttempts = 3): ?string
    {
        $flareSolverrUrl = $this->flareSolverrUrl();
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
                $outcome = classifyFlareSolverrResponse($data);

                if ($outcome['status'] !== null) {
                    \Log::debug("FlareSolverr target status {$outcome['status']} for {$url}");
                }

                if ($outcome['html'] !== null) {
                    \Log::debug("Successfully fetched URL via FlareSolverr: {$url} (length: " . strlen($outcome['html']) . ")");
                    $reason = null;
                    return $outcome['html'];
                }

                $reason = $outcome['reason'];
                $retry = $outcome['retry'];
                $lastError = match ($reason) {
                    'decode_failed' => 'decode_failed: ' . json_last_error_msg(),
                    'flaresolverr_error' => 'flaresolverr_error: ' . ($data['message'] ?? 'Unknown error'),
                    'http_404', 'http_4xx', 'http_5xx' => "{$reason} (target status {$outcome['status']})",
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
                sleep($delay);
            }
        }

        \Log::error("FlareSolverr failed after {$maxAttempts} attempts for URL {$url}: {$lastError}");
        return null;
    }

    /**
     * Like html() but also returns the Cloudflare clearance cookie and
     * user-agent, so later same-site pages can be fetched with plain() and
     * the cf_clearance cookie instead of a full browser render each time.
     * Single attempt. On failure 'html' is null.
     *
     * @return array{html: ?string, cf_clearance: ?string, user_agent: ?string}
     */
    public function htmlWithSession(string $url): array
    {
        $empty = ['html' => null, 'cf_clearance' => null, 'user_agent' => null];

        try {
            $response = $this->flareSolverrClient()->request('POST', $this->flareSolverrUrl(), [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['cmd' => 'request.get', 'url' => $url, 'maxTimeout' => 60000],
            ]);
            $data = json_decode($response->getContent(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

            if (($data['status'] ?? null) !== 'ok' || empty($data['solution']['response'])) {
                return $empty;
            }

            $cf = null;
            foreach ($data['solution']['cookies'] ?? [] as $cookie) {
                if (($cookie['name'] ?? '') === 'cf_clearance') {
                    $cf = $cookie['value'];
                    break;
                }
            }

            return [
                'html' => $data['solution']['response'],
                'cf_clearance' => $cf,
                'user_agent' => $data['solution']['userAgent'] ?? null,
            ];
        } catch (\Throwable $e) {
            \Log::error("fetchWithBrowserSession error for {$url}: " . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Plain HTTP GET with browser-like headers. Returns the body on a 200
     * (non-empty), else null; $status receives the HTTP status, or
     * 'exception' when the request itself failed. Never throws.
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
     * transport error or undecodable body (logged).
     */
    public function json(string $url, array $headers = []): ?array
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
     * createHttpClient() helper returns). Prefer plain()/json() in scraper
     * code so tests can intercept the request.
     */
    public function client(): HttpClientInterface
    {
        return HttpClient::create([
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
            $outcome = classifyFlareSolverrResponse(json_decode($response->getContent(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE));
            $reason = $outcome['reason'];

            return $outcome['html'];
        } catch (\Throwable $e) {
            $reason = 'exception';
            \Log::warning("Fetcher::post failed for {$url}: " . $e->getMessage());
            return null;
        }
    }

    protected function tlsVerify(): bool
    {
        return (bool) config('novarr.tls_verify', true);
    }

    /** Client for talking to FlareSolverr itself (TLS verification per config). */
    protected function flareSolverrClient(int $timeout = 120): HttpClientInterface
    {
        return HttpClient::create([
            'timeout' => $timeout,
            'verify_peer' => $this->tlsVerify(),
            'verify_host' => $this->tlsVerify(),
        ]);
    }

    protected function flareSolverrUrl(): string
    {
        return (string) setting('flaresolverr_url', config('novarr.flaresolverr_url'));
    }
}
