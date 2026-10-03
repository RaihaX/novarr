<?php

namespace Tests\Support;

use App\Scraping\Fetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Network-free Fetcher for tests.
 *
 *     $fake = new FakeFetcher([
 *         'https://novelfull.com/shadow-slave.html' => 'novelfull/novel-page.html', // fixture under tests/fixtures
 *         'https://example.com/inline' => '<html>…</html>',                          // inline body
 *         'https://example.com/gone' => FakeFetcher::fail('http_404', 404),         // simulated failure
 *     ], prefixes: ['https://www.empirenovel.com/novel/x?page=' => 'empirenovel/empty-page.html']);
 *     app()->instance(Fetcher::class, $fake);
 *
 * Exact URL routes win over prefix routes (longest prefix first). A value
 * that names an existing file (absolute, or relative to tests/fixtures) is
 * read from disk, otherwise it is the body itself. Every call is recorded
 * in $calls. An unrouted URL throws UnexpectedFetch — an \Error, so the
 * scraper's own catch (\Exception) blocks cannot swallow it — and is also
 * recorded in $unexpected for an assertion after the fact.
 */
class FakeFetcher extends Fetcher
{
    /** @var array<int, array{method: string, url: string}> */
    public array $calls = [];

    /** @var string[] */
    public array $unexpected = [];

    /**
     * @param array<string, string|array> $routes   exact URL => fixture/body/fail()
     * @param array<string, string|array> $prefixes URL prefix => fixture/body/fail()
     * @param array{cf_clearance?: ?string, user_agent?: ?string} $session what htmlWithSession() reports
     */
    public function __construct(
        private array $routes = [],
        private array $prefixes = [],
        private array $session = ['cf_clearance' => 'fake-clearance', 'user_agent' => 'FakeFetcher/1.0'],
    ) {
        uksort($this->prefixes, fn($a, $b) => strlen($b) <=> strlen($a));
    }

    /** A route value that makes the fetch fail with the given reason/status. */
    public static function fail(string $reason = 'http_404', ?int $status = 404): array
    {
        return ['__fail' => true, 'reason' => $reason, 'status' => $status];
    }

    public function route(string $url, string|array $value): static
    {
        $this->routes[$url] = $value;
        return $this;
    }

    public function html(string $url, ?string &$reason = null, ?string $waitForSelector = null, int $maxAttempts = 3): ?string
    {
        $reason = null;
        $hit = $this->resolve('html', $url);
        if (is_array($hit)) {
            $reason = $hit['reason'];
            return null;
        }
        return $hit;
    }

    public function htmlWithSession(string $url): array
    {
        $hit = $this->resolve('session', $url);
        if (is_array($hit)) {
            return ['html' => null, 'cf_clearance' => null, 'user_agent' => null];
        }

        return [
            'html' => $hit,
            'cf_clearance' => $this->session['cf_clearance'] ?? null,
            'user_agent' => $this->session['user_agent'] ?? null,
        ];
    }

    public function plain(string $url, array $headers = [], array $cookies = [], &$status = null): ?string
    {
        $hit = $this->resolve('plain', $url);
        if (is_array($hit)) {
            $status = $hit['status'] ?? $hit['reason'];
            return null;
        }
        $status = 200;
        return $hit;
    }

    public function post(string $url, string $postData, ?string &$reason = null): ?string
    {
        $reason = null;
        $hit = $this->resolve('post', $url);
        if (is_array($hit)) {
            $reason = $hit['reason'];
            return null;
        }
        return $hit;
    }

    public function json(string $url, array $headers = []): ?array
    {
        $hit = $this->resolve('json', $url);
        if (is_array($hit)) {
            return null;
        }
        $json = json_decode($hit, true);
        return is_array($json) ? $json : null;
    }

    /** Any raw client use is a network call too: fail loudly. */
    public function client(): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url) {
            $this->calls[] = ['method' => 'client', 'url' => $url];
            $this->unexpected[] = $url;
            throw new UnexpectedFetch("FakeFetcher: unexpected raw HTTP {$method} {$url}");
        });
    }

    /** URLs requested, optionally only for one method (html/session/plain/json). */
    public function urls(?string $method = null): array
    {
        return array_values(array_map(
            fn($c) => $c['url'],
            array_filter($this->calls, fn($c) => $method === null || $c['method'] === $method)
        ));
    }

    /** @return string|array body, or a fail() array */
    private function resolve(string $method, string $url): string|array
    {
        $this->calls[] = ['method' => $method, 'url' => $url];

        $value = $this->routes[$url] ?? null;
        if ($value === null) {
            foreach ($this->prefixes as $prefix => $candidate) {
                if (str_starts_with($url, $prefix)) {
                    $value = $candidate;
                    break;
                }
            }
        }

        if ($value === null) {
            $this->unexpected[] = $url;
            throw new UnexpectedFetch("FakeFetcher: unexpected {$method} fetch of {$url}");
        }

        if (is_array($value)) {
            return $value;
        }

        return self::body($value);
    }

    /** Fixture file contents (absolute or tests/fixtures-relative), else the string itself. */
    public static function body(string $value): string
    {
        if (strlen($value) < 512 && !str_contains($value, '<') && !str_starts_with(ltrim($value), '{')) {
            $path = str_starts_with($value, '/') ? $value : dirname(__DIR__) . '/fixtures/' . $value;
            if (is_file($path)) {
                return file_get_contents($path);
            }
            throw new \InvalidArgumentException("FakeFetcher: fixture not found: {$value}");
        }

        return $value;
    }
}
