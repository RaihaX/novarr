<?php

namespace Tests\Unit;

use App\Scraping\Fetcher;
use Carbon\Carbon;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\TestCase;

/**
 * App\Scraping\Fetcher against a MockHttpClient: FlareSolverr retry/backoff
 * for html() and htmlWithSession(), rate-limit classification with
 * Retry-After, client reuse and the Cloudflare challenge check, and the
 * per-host clearance store behind page() / sessionPage().
 */
class FetcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Fetcher::pullRetryAfter('a.test');
        parent::tearDown();
    }

    /**
     * A Fetcher whose clients all answer from $responses (MockResponse
     * objects, in order) and whose retry sleeps are only recorded.
     */
    private function fetcher(array $responses): Fetcher
    {
        return new class($responses) extends Fetcher {
            public array $sleeps = [];
            public array $built = [];
            public array $requests = [];
            private MockHttpClient $mock;

            public function __construct(array $responses)
            {
                $this->mock = new MockHttpClient(function ($method, $url, $options) use (&$responses) {
                    $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null, 'headers' => $options['headers'] ?? []];
                    return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
                });
            }

            protected function buildClient(array $options): HttpClientInterface
            {
                $this->built[] = $options['timeout'] ?? null;
                return $this->mock;
            }

            protected function sleepSeconds(int $seconds): void
            {
                $this->sleeps[] = $seconds;
            }

            protected function flareSolverrUrl(): string
            {
                return 'http://flaresolverr.test/v1';
            }
        };
    }

    private static function solved(string $html, int $status = 200, array $extra = []): MockResponse
    {
        return new MockResponse(json_encode([
            'status' => 'ok',
            'solution' => ['status' => $status, 'response' => $html] + $extra,
        ]));
    }

    private static function flareError(): MockResponse
    {
        return new MockResponse(json_encode(['status' => 'error', 'message' => 'Timeout']));
    }

    public function testHtmlRetriesWithBackoffThenSucceeds(): void
    {
        $fetcher = $this->fetcher([self::flareError(), new MockResponse('not json'), self::solved('<p>ok</p>')]);

        $reason = 'unset';
        $this->assertSame('<p>ok</p>', $fetcher->html('https://a.test/c1', $reason));
        $this->assertNull($reason);
        $this->assertSame([2, 4], $fetcher->sleeps);
        $this->assertCount(3, $fetcher->requests);
        $this->assertSame('http://flaresolverr.test/v1', $fetcher->requests[0]['url']);
        $this->assertStringContainsString('"cmd":"request.get"', $fetcher->requests[0]['body']);
    }

    public function testHtmlGivesUpAfterMaxAttempts(): void
    {
        $fetcher = $this->fetcher([self::flareError(), self::flareError(), self::flareError(), self::solved('<p>never</p>')]);

        $reason = null;
        $this->assertNull($fetcher->html('https://a.test/c1', $reason));
        $this->assertSame('flaresolverr_error', $reason);
        $this->assertSame([2, 4], $fetcher->sleeps);
        $this->assertCount(3, $fetcher->requests);
    }

    public function testHtml404IsNotRetried(): void
    {
        $fetcher = $this->fetcher([self::solved('<h1>gone</h1>', 404)]);

        $reason = null;
        $this->assertNull($fetcher->html('https://a.test/c1', $reason));
        $this->assertSame('http_404', $reason);
        $this->assertSame([], $fetcher->sleeps);
    }

    public function testTransportExceptionIsRetried(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['error' => 'connection refused']), self::solved('<p>ok</p>')]);

        $this->assertSame('<p>ok</p>', $fetcher->html('https://a.test/c1'));
        $this->assertSame([2], $fetcher->sleeps);
    }

    public function testTarget429IsRateLimitedNotRetriedAndRecordsRetryAfter(): void
    {
        $fetcher = $this->fetcher([self::solved('<p>slow down</p>', 429, ['headers' => ['Retry-After' => '120']])]);

        $reason = null;
        $this->assertNull($fetcher->html('https://A.test/c1', $reason));
        $this->assertSame('rate_limited', $reason);
        $this->assertSame([], $fetcher->sleeps);
        $this->assertSame(120, Fetcher::pullRetryAfter('a.test'));
        $this->assertNull(Fetcher::pullRetryAfter('a.test'), 'read once');
    }

    public function testTarget503IsRateLimitedEvenWithoutRetryAfter(): void
    {
        $fetcher = $this->fetcher([self::solved('<p>busy</p>', 503)]);

        $reason = null;
        $this->assertNull($fetcher->html('https://a.test/c1', $reason));
        $this->assertSame('rate_limited', $reason);
        $this->assertNull(Fetcher::pullRetryAfter('a.test'));
    }

    public function testOther5xxIsStillRetried(): void
    {
        $fetcher = $this->fetcher([self::solved('<p>oops</p>', 500), self::solved('<p>ok</p>')]);

        $this->assertSame('<p>ok</p>', $fetcher->html('https://a.test/c1'));
        $this->assertSame([2], $fetcher->sleeps);
    }

    public function testHtmlWithSessionReturnsCookieAndUserAgent(): void
    {
        $fetcher = $this->fetcher([self::solved('<p>page</p>', 200, [
            'cookies' => [['name' => 'other', 'value' => 'x'], ['name' => 'cf_clearance', 'value' => 'clear-me']],
            'userAgent' => 'UA/1.0',
        ])]);

        $reason = 'unset';
        $this->assertSame(
            ['html' => '<p>page</p>', 'cf_clearance' => 'clear-me', 'user_agent' => 'UA/1.0'],
            $fetcher->htmlWithSession('https://a.test/novel', $reason)
        );
        $this->assertNull($reason);
    }

    public function testHtmlWithSessionRetriesAndReportsTheReason(): void
    {
        $fetcher = $this->fetcher([self::flareError(), self::solved('<p>page</p>', 200, ['userAgent' => 'UA'])]);
        $session = $fetcher->htmlWithSession('https://a.test/novel');
        $this->assertSame('<p>page</p>', $session['html']);
        $this->assertNull($session['cf_clearance']);
        $this->assertSame([2], $fetcher->sleeps);

        $failing = $this->fetcher([self::solved('', 404)]);
        $reason = null;
        $this->assertSame(['html' => null, 'cf_clearance' => null, 'user_agent' => null], $failing->htmlWithSession('https://a.test/novel', $reason));
        $this->assertSame('http_404', $reason);

        // An empty solution is classified (and retried) like html().
        $empty = $this->fetcher([self::solved(''), self::solved(''), self::solved('')]);
        $reason = null;
        $this->assertNull($empty->htmlWithSession('https://a.test/novel', $reason)['html']);
        $this->assertSame('empty_response', $reason);
        $this->assertSame([2, 4], $empty->sleeps);
    }

    public function testClientsAreBuiltOncePerInstanceAndTimeout(): void
    {
        $fetcher = $this->fetcher([
            self::solved('<p>1</p>'), self::solved('<p>2</p>'), self::solved('<p>3</p>'),
            new MockResponse('a'), new MockResponse('b'), self::solved('<p>posted</p>'),
        ]);

        $fetcher->html('https://a.test/1');
        $fetcher->html('https://a.test/2');
        $fetcher->htmlWithSession('https://a.test/3');
        $fetcher->plain('https://a.test/4');
        $this->assertSame($fetcher->client(), $fetcher->client());
        $fetcher->plain('https://a.test/5');
        $fetcher->post('https://a.test/6', 'x=1');

        // FlareSolverr (120 s), plain (30 s), FlareSolverr POST (60 s).
        $this->assertSame([120, 30, 60], $fetcher->built);
    }

    public function testPlainRateLimitRecordsRetryAfterAndJsonPassesTheStatus(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $date = Carbon::now()->addSeconds(90)->toRfc7231String();
        $fetcher = $this->fetcher([
            new MockResponse('slow', ['http_code' => 429, 'response_headers' => ["Retry-After: {$date}"]]),
            new MockResponse('{"ok":true}'),
            new MockResponse('down', ['http_code' => 503]),
        ]);

        $status = null;
        $this->assertNull($fetcher->plain('https://a.test/api', [], [], $status));
        $this->assertSame(429, $status);
        $this->assertSame(90, Fetcher::pullRetryAfter('a.test'));

        $this->assertSame(['ok' => true], $fetcher->json('https://a.test/api', [], $status));
        $this->assertSame(200, $status);

        $this->assertNull($fetcher->json('https://a.test/api', [], $status));
        $this->assertSame(503, $status);
    }

    /** A Retry-After nobody pulled is overwritten by the host's next answer, so it can't leak into a later rate limit. */
    public function testEveryClassifiedAnswerOverwritesTheHostsRetryAfter(): void
    {
        // plain(): 429 with Retry-After, then a 200.
        $fetcher = $this->fetcher([
            new MockResponse('slow', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]),
            new MockResponse('{"ok":true}'),
        ]);
        $fetcher->plain('https://a.test/api');
        $fetcher->plain('https://a.test/api');
        $this->assertNull(Fetcher::pullRetryAfter('a.test'), 'plain(): cleared by the 200');

        // plain(): 429 with Retry-After, then a 404.
        $fetcher = $this->fetcher([
            new MockResponse('slow', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]),
            new MockResponse('gone', ['http_code' => 404]),
        ]);
        $fetcher->plain('https://a.test/api');
        $fetcher->plain('https://a.test/api');
        $this->assertNull(Fetcher::pullRetryAfter('a.test'), 'plain(): cleared by the 404');

        // FlareSolverr: 429 with Retry-After, then a page.
        $fetcher = $this->fetcher([
            self::solved('<p>slow down</p>', 429, ['headers' => ['Retry-After' => '120']]),
            self::solved('<p>ok</p>'),
        ]);
        $fetcher->html('https://a.test/c1');
        $this->assertSame('<p>ok</p>', $fetcher->html('https://a.test/c2'));
        $this->assertNull(Fetcher::pullRetryAfter('a.test'), 'html(): cleared by the page');

        // Another host's answer leaves a.test's entry alone.
        $fetcher = $this->fetcher([
            self::solved('<p>slow down</p>', 429, ['headers' => ['Retry-After' => '120']]),
            self::solved('<p>ok</p>'),
        ]);
        $fetcher->html('https://a.test/c1');
        $fetcher->html('https://b.test/c1');
        $this->assertSame(120, Fetcher::pullRetryAfter('a.test'));
    }

    public function testParseRetryAfter(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');

        $this->assertSame(120, Fetcher::parseRetryAfter('120'));
        $this->assertSame(0, Fetcher::parseRetryAfter('0'));
        $this->assertSame(3600, Fetcher::parseRetryAfter('86400'), 'capped at 60 minutes');
        $this->assertSame(300, Fetcher::parseRetryAfter('Sat, 14 Nov 2026 12:05:00 GMT'));
        $this->assertSame(0, Fetcher::parseRetryAfter('Sat, 14 Nov 2026 11:00:00 GMT'), 'a past date means now');
        $this->assertSame(45, Fetcher::parseRetryAfter([' 45 ']));
        $this->assertNull(Fetcher::parseRetryAfter(null));
        $this->assertNull(Fetcher::parseRetryAfter(''));
        $this->assertNull(Fetcher::parseRetryAfter('soon-ish'));
    }

    public function testRateLimitStatuses(): void
    {
        $this->assertTrue(Fetcher::isRateLimitStatus(429));
        $this->assertTrue(Fetcher::isRateLimitStatus(503));
        $this->assertFalse(Fetcher::isRateLimitStatus(500));
        $this->assertFalse(Fetcher::isRateLimitStatus('exception'));
        $this->assertFalse(Fetcher::isRateLimitStatus(null));
    }

    public function testCloudflareChallengeDetection(): void
    {
        $this->assertTrue(Fetcher::isCloudflareChallenge('<html><head><title>Just a moment...</title></head></html>'));
        $this->assertTrue(Fetcher::isCloudflareChallenge('<div class="cf-challenge-running"></div>'));
        $this->assertTrue(Fetcher::isCloudflareChallenge('<p>Verifying you are human. This may take a few seconds.</p>'));
        $this->assertFalse(Fetcher::isCloudflareChallenge('<p>Protected by Cloudflare. Chapter 1 begins…</p>'));
        $this->assertFalse(Fetcher::isCloudflareChallenge(''));
        $this->assertFalse(Fetcher::isCloudflareChallenge(null));
    }

    // ---- Per-host clearance store: page() / sessionPage() ----------------

    private const CHAPTER = 'https://a.test/novel/chapter-1.html';

    private static function solvedWithClearance(string $html, string $cf = 'clear-me', string $ua = 'UA/1.0'): MockResponse
    {
        return self::solved($html, 200, [
            'cookies' => [['name' => 'other', 'value' => 'x'], ['name' => 'cf_clearance', 'value' => $cf]],
            'userAgent' => $ua,
        ]);
    }

    /** Requests that went to the target host itself (not to FlareSolverr). */
    private static function targetRequests(Fetcher $fetcher): array
    {
        return array_values(array_filter($fetcher->requests, fn($r) => str_starts_with($r['url'], 'https://a.test/')));
    }

    private static function flareRequests(Fetcher $fetcher): array
    {
        return array_values(array_filter($fetcher->requests, fn($r) => str_starts_with($r['url'], 'http://flaresolverr.test/')));
    }

    public function testPageWithoutClearanceUsesFlareSolverrAndStoresTheEntry(): void
    {
        $fetcher = $this->fetcher([self::solvedWithClearance('<p>chapter</p>')]);

        $reason = 'unset';
        $via = null;
        $this->assertSame('<p>chapter</p>', $fetcher->page(self::CHAPTER, $reason, $via));
        $this->assertNull($reason);
        $this->assertSame('flaresolverr', $via);
        $this->assertCount(1, self::flareRequests($fetcher));
        $this->assertCount(0, self::targetRequests($fetcher));

        // Only cf_clearance is kept (the cookie set the plain path sends).
        $this->assertSame(['cookies' => ['cf_clearance' => 'clear-me'], 'user_agent' => 'UA/1.0'], $fetcher->clearance('a.test'));
        $this->assertSame($fetcher->clearance('a.test'), $fetcher->clearance('https://A.test/other'), 'keyed by host');
        $this->assertNull($fetcher->clearance('b.test'));
    }

    public function testASolveWithoutCfClearanceStoresNothing(): void
    {
        $fetcher = $this->fetcher([self::solved('<p>chapter</p>', 200, ['cookies' => [['name' => 'other', 'value' => 'x']], 'userAgent' => 'UA/1.0'])]);

        $via = null;
        $this->assertSame('<p>chapter</p>', $fetcher->page(self::CHAPTER, $reason, $via));
        $this->assertSame('flaresolverr', $via);
        $this->assertNull($fetcher->clearance('a.test'));
    }

    public function testPageWithClearanceGoesPlainWithExactlyTheStoredUserAgentAndCookie(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<p>chapter via plain</p>')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'stored-cf', 'ignored' => 'y'], 'Stored-UA/2.0');

        $reason = 'unset';
        $via = null;
        $this->assertSame('<p>chapter via plain</p>', $fetcher->page(self::CHAPTER, $reason, $via));
        $this->assertNull($reason);
        $this->assertSame('plain', $via);
        $this->assertCount(0, self::flareRequests($fetcher), 'FlareSolverr not called');

        $target = self::targetRequests($fetcher);
        $this->assertCount(1, $target);
        $this->assertSame('GET', $target[0]['method']);
        $this->assertContains('User-Agent: Stored-UA/2.0', $target[0]['headers']);
        $this->assertContains('Cookie: cf_clearance=stored-cf', $target[0]['headers']);
        $this->assertCount(1, array_filter($target[0]['headers'], fn($h) => stripos($h, 'User-Agent:') === 0), 'one user agent');
        $this->assertNotNull($fetcher->clearance('a.test'), 'entry kept');
    }

    public static function refusedPlainAnswers(): array
    {
        return [
            '403' => [fn() => new MockResponse('<p>forbidden</p>', ['http_code' => 403])],
            '503' => [fn() => new MockResponse('<p>busy</p>', ['http_code' => 503, 'response_headers' => ['Retry-After: 120']])],
            'challenge body with a 200' => [fn() => new MockResponse('<html><head><title>Just a moment...</title></head></html>')],
            'transport error' => [fn() => new MockResponse('', ['error' => 'connection reset'])],
            'empty body' => [fn() => new MockResponse('')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedPlainAnswers')]
    public function testRefusedPlainForgetsTheEntryFallsBackToFlareSolverrAndRefreshesIt(\Closure $plainAnswer): void
    {
        $fetcher = $this->fetcher([$plainAnswer(), self::solvedWithClearance('<p>chapter</p>', 'fresh-cf', 'Fresh-UA')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'stale-cf'], 'Old-UA');

        $reason = 'unset';
        $via = null;
        $this->assertSame('<p>chapter</p>', $fetcher->page(self::CHAPTER, $reason, $via));
        $this->assertNull($reason);
        $this->assertSame('flaresolverr', $via);
        $this->assertCount(1, self::targetRequests($fetcher));
        $this->assertCount(1, self::flareRequests($fetcher));
        $this->assertSame(['cookies' => ['cf_clearance' => 'fresh-cf'], 'user_agent' => 'Fresh-UA'], $fetcher->clearance('a.test'));
        $this->assertNull(Fetcher::pullRetryAfter('a.test'), "a refusal's Retry-After does not linger");
    }

    public function testRefusedPlainWithASolveThatCarriesNoClearanceLeavesNoEntry(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['http_code' => 403]), self::solved('<p>chapter</p>')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'stale-cf'], 'Old-UA');

        $this->assertSame('<p>chapter</p>', $fetcher->page(self::CHAPTER));
        $this->assertNull($fetcher->clearance('a.test'));
    }

    public function testPlain404IsNotFoundWithoutFlareSolverr(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<h1>gone</h1>', ['http_code' => 404]), self::solved('<p>never</p>')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'cf'], 'UA');

        $reason = null;
        $via = null;
        $this->assertNull($fetcher->page(self::CHAPTER, $reason, $via));
        $this->assertSame('http_404', $reason, 'as FlareSolverr would classify it');
        $this->assertSame('plain', $via);
        $this->assertCount(0, self::flareRequests($fetcher));
        $this->assertNotNull($fetcher->clearance('a.test'), 'entry kept');
    }

    public function testPlain429IsRateLimitedWithRetryAfterAndNoFlareSolverr(): void
    {
        $fetcher = $this->fetcher([new MockResponse('slow', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]), self::solved('<p>never</p>')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'cf'], 'UA');

        $reason = null;
        $via = null;
        $this->assertNull($fetcher->page(self::CHAPTER, $reason, $via));
        $this->assertSame('rate_limited', $reason);
        $this->assertSame('plain', $via);
        $this->assertSame(120, Fetcher::pullRetryAfter('a.test'));
        $this->assertCount(0, self::flareRequests($fetcher));
        $this->assertNotNull($fetcher->clearance('a.test'), 'entry kept');
    }

    public function testClearanceExpiresAfterTheTtl(): void
    {
        config(['novarr.clearance_minutes' => 25]);
        $fetcher = $this->fetcher([]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'cf'], 'UA');

        $this->travel(24)->minutes();
        $this->assertNotNull($fetcher->clearance('a.test'));
        $this->travel(2)->minutes();
        $this->assertNull($fetcher->clearance('a.test'));
    }

    public function testTheEntryIsSharedThroughTheApplicationCache(): void
    {
        $this->fetcher([self::solvedWithClearance('<p>x</p>'),])->page(self::CHAPTER);

        // A fresh instance (another process, in production) sees it.
        $this->assertSame('clear-me', $this->fetcher([])->clearance('a.test')['cookies']['cf_clearance']);
        $this->assertSame('UA/1.0', \Cache::get(Fetcher::CLEARANCE_CACHE_PREFIX . 'a.test')['user_agent']);
    }

    public function testZeroMinutesMakesPageExactlyHtml(): void
    {
        config(['novarr.clearance_minutes' => 0]);
        $fetcher = $this->fetcher([self::solvedWithClearance('<p>chapter</p>'), self::solvedWithClearance('<p>chapter</p>')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'cf'], 'UA');
        $this->assertNull($fetcher->clearance('a.test'), 'nothing stored when off');

        $via = null;
        $this->assertSame('<p>chapter</p>', $fetcher->page(self::CHAPTER, $reason, $via));
        $this->assertSame('flaresolverr', $via);
        $this->assertSame($fetcher->html(self::CHAPTER), '<p>chapter</p>');
        $this->assertCount(0, self::targetRequests($fetcher), 'never plain');
        $this->assertCount(2, self::flareRequests($fetcher));
        $this->assertNull(\Cache::get(Fetcher::CLEARANCE_CACHE_PREFIX . 'a.test'), 'solves store nothing');

        // sessionPage() is then exactly htmlWithSession().
        $session = $this->fetcher([self::solvedWithClearance('<p>toc</p>')])->sessionPage('https://a.test/novel');
        $this->assertSame(['html' => '<p>toc</p>', 'cf_clearance' => 'clear-me', 'user_agent' => 'UA/1.0'], $session);
        $this->assertNull(\Cache::get(Fetcher::CLEARANCE_CACHE_PREFIX . 'a.test'));
    }

    public function testHtmlAndHtmlWithSessionRefreshTheEntry(): void
    {
        $fetcher = $this->fetcher([self::solvedWithClearance('<p>1</p>', 'cf-1', 'UA-1'), self::solvedWithClearance('<p>2</p>', 'cf-2', 'UA-2')]);

        $fetcher->html('https://a.test/1');
        $this->assertSame('cf-1', $fetcher->clearance('a.test')['cookies']['cf_clearance']);

        $session = $fetcher->htmlWithSession('https://a.test/2');
        $this->assertSame(['html' => '<p>2</p>', 'cf_clearance' => 'cf-2', 'user_agent' => 'UA-2'], $session, 'return value unchanged');
        $this->assertSame(['cookies' => ['cf_clearance' => 'cf-2'], 'user_agent' => 'UA-2'], $fetcher->clearance('a.test'));
    }

    public function testSessionPageGoesPlainWithACachedEntryAndHandsItBack(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<p>toc page 1</p>')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'cf'], 'UA');

        $via = null;
        $this->assertSame(
            ['html' => '<p>toc page 1</p>', 'cf_clearance' => 'cf', 'user_agent' => 'UA'],
            $fetcher->sessionPage('https://a.test/novel?page=1', $reason, $via)
        );
        $this->assertSame('plain', $via);
        $this->assertCount(0, self::flareRequests($fetcher));
    }

    public function testSessionPageFallsBackAndRefreshesWhenRefused(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['http_code' => 403]), self::solvedWithClearance('<p>toc</p>', 'fresh', 'Fresh-UA')]);
        $fetcher->rememberClearance('a.test', ['cf_clearance' => 'stale'], 'Old-UA');

        $via = null;
        $this->assertSame(
            ['html' => '<p>toc</p>', 'cf_clearance' => 'fresh', 'user_agent' => 'Fresh-UA'],
            $fetcher->sessionPage('https://a.test/novel', $reason, $via)
        );
        $this->assertSame('flaresolverr', $via);
        $this->assertSame('fresh', $fetcher->clearance('a.test')['cookies']['cf_clearance']);
    }
}
