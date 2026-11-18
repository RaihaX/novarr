<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use Tests\Support\FakeFetcher;

/**
 * chapterGenerator() failure paths: only a NovelPing API answer of HTTP 429
 * skips the FlareSolverr page fetch (the host asked us to slow down). Any
 * other API failure — 403 from a WAF, 404, 5xx including a 503 challenge,
 * transport error, undecodable body, empty content — falls back to the page
 * fetch, and the reason reported is the page fetch's. Also the rate_limited
 * reason from the page fetch and \Throwable isolation.
 */
class NovelPingChapterFailureTest extends GoldenTestCase
{
    private const NOVEL = 'https://novelping.com/novel/shadow-slave';
    private const CHAPTER = 'https://novelping.com/chapter/shadow-slave/chapter-1-nightmare-begins';
    private const API = 'https://novelping.com/api-web/novels/shadow-slave/chapters/chapter-1-nightmare-begins';
    /** Where the chapter page is fetched: the site's reader, not the stored chapter URL. */
    private const PAGE = 'https://novelping.com/book/shadow-slave/chapter-1-nightmare-begins';

    private function generate(string $url = self::CHAPTER, string $novelUrl = self::NOVEL): array
    {
        $reason = 'unset';
        $paragraphs = chapterGenerator(
            $this->chapter($this->novel($novelUrl, 'Shadow Slave'), $url, 1, 'Chapter 1 Nightmare Begins'),
            $reason
        );

        return [$paragraphs, $reason];
    }

    private static function page(): string
    {
        return '<html><body><div id="chr-content">' . str_repeat('<p>Sunny walked on through the long night again.</p>', 12) . '</div></body></html>';
    }

    public function testApi429DoesNotFallBackToFlareSolverr(): void
    {
        $fetcher = $this->fake([self::API => FakeFetcher::fail('rate_limited', 429)]);

        [$paragraphs, $reason] = $this->generate();

        $this->assertSame([], $paragraphs);
        $this->assertSame('rate_limited', $reason);
        $this->assertSame([self::API], $fetcher->urls(), 'no page fetch after the API said 429');
    }

    public static function apiFailures(): array
    {
        return [
            '403 from a WAF' => [FakeFetcher::fail('http_4xx', 403)],
            '404' => [FakeFetcher::fail('http_404', 404)],
            '500' => [FakeFetcher::fail('http_5xx', 500)],
            '503 challenge' => [FakeFetcher::fail('rate_limited', 503)],
            'transport error' => [FakeFetcher::fail('exception', null)],
            '200 with a non-JSON body' => ['<html><title>Just a moment...</title></html>'],
            'empty content' => ['{"item":{"chapterInfo":{"chapter_content":"   "}}}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiFailures')]
    public function testOtherApiFailuresFallBackToThePageFetch(array|string $failure): void
    {
        $fetcher = $this->fake([self::API => $failure, self::PAGE => self::page()]);

        [$paragraphs, $reason] = $this->generate();

        $this->assertSame([self::API, self::PAGE], $fetcher->urls());
        $this->assertSame(['json', 'html'], array_column($fetcher->calls, 'method'));
        $this->assertNotSame([], $paragraphs);
        $this->assertNull($reason);
    }

    public function testAChapterMissingFromTheApiIsReadFromTheReaderPage(): void
    {
        $chapter = 'https://novelping.com/chapter/martial-artist-lee-gwak/chapter-451';
        $fetcher = $this->fake([
            'https://novelping.com/api-web/novels/martial-artist-lee-gwak/chapters/chapter-451' => FakeFetcher::fail('http_404', 404),
            'https://novelping.com/book/martial-artist-lee-gwak/chapter-451' => file_get_contents(base_path('tests/fixtures/novelarrow/book-chapter-page.html')),
        ]);

        [$paragraphs, $reason] = $this->generate($chapter, 'https://novelping.com/novel/martial-artist-lee-gwak');

        $this->assertNull($reason);
        $this->assertGreaterThanOrEqual(10, count($paragraphs));
        $this->assertStringContainsString('The Blood Wind Blows from the West', implode(' ', $paragraphs));
        $this->assertSame('https://novelping.com/book/martial-artist-lee-gwak/chapter-451', $fetcher->urls()[1]);
    }

    public function testReaderPageUrlAndSlugs(): void
    {
        $source = \App\Sources\NovelArrowSource::class;
        $this->assertSame('https://novelping.com/book/a-b/chapter-1', $source::readerPageUrl('https://novelping.com/chapter/a-b/chapter-1'));
        $this->assertNull($source::readerPageUrl('https://novelping.com/novel/a-b'));
        $this->assertNull($source::readerPageUrl('https://example.com/chapter/a-b/chapter-1'));
        $this->assertSame('a-b', $source::slugFrom('https://novelping.com/book/a-b'));
        $this->assertSame('a-b', $source::slugFrom('https://novelping.com/book/a-b/chapter-1'));
    }

    public function testFallbackFailureReportsThePageFetchReason(): void
    {
        $this->fake([self::API => FakeFetcher::fail('rate_limited', 503), self::PAGE => FakeFetcher::fail('http_404', 404)]);

        [$paragraphs, $reason] = $this->generate();

        $this->assertSame([], $paragraphs);
        $this->assertSame('not_found', $reason, 'an API 503 alone is not a rate limit');
    }

    public function testRateLimitedApiFailureIsBlocking(): void
    {
        $this->assertTrue(ChapterScraper::isBlockingFailure('rate_limited'));
        $this->assertSame(ChapterScraper::BLOCKING_BACKOFF_SECONDS, ChapterScraper::backoffSeconds(5, 'rate_limited'));
    }

    public function testNonNovelPingUrlGoesStraightToThePageFetch(): void
    {
        $fetcher = $this->fake(['https://novelfull.com/x/chapter-1.html' => FakeFetcher::fail('rate_limited', 429)]);

        [$paragraphs, $reason] = $this->generate('https://novelfull.com/x/chapter-1.html', 'https://novelfull.com/x.html');

        $this->assertSame([], $paragraphs);
        $this->assertSame('rate_limited', $reason, 'a 429/503 page fetch is reported as rate_limited');
        $this->assertSame(['html'], array_column($fetcher->calls, 'method'));
    }

    public function testCloudflareChallengePageIsDetected(): void
    {
        $this->fake(['https://novelfull.com/x/chapter-1.html' => '<html><head><title>Just a moment...</title></head><body></body></html>']);

        [$paragraphs, $reason] = $this->generate('https://novelfull.com/x/chapter-1.html', 'https://novelfull.com/x.html');

        $this->assertSame([], $paragraphs);
        $this->assertSame('cloudflare', $reason);
    }

    public function testAnErrorWhileFetchingIsReportedNotThrown(): void
    {
        $fetcher = new class extends FakeFetcher {
            public function html(string $url, ?string &$reason = null, ?string $waitForSelector = null, int $maxAttempts = 3): ?string
            {
                throw new \TypeError('bad DOM');
            }
        };
        app()->instance(\App\Scraping\Fetcher::class, $fetcher);

        [$paragraphs, $reason] = $this->generate('https://novelfull.com/x/chapter-1.html', 'https://novelfull.com/x.html');

        $this->assertSame([], $paragraphs);
        $this->assertSame('exception', $reason);
    }
}
