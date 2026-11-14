<?php

namespace Tests\Feature;

use App\Scraping\Fetcher;
use Tests\Support\FakeFetcher;

/**
 * The per-host clearance store (Fetcher::page() / sessionPage()) as the
 * chapter and TOC helpers use it, against the golden fixtures: a cached
 * cf_clearance lets chapter and TOC pages go plain; a plain fetch that
 * parses to no or thin content is retried once through FlareSolverr; the
 * NovelPing-style 429 rule and the failure-reason mapping hold on the plain
 * path too.
 */
class ClearanceReuseTest extends GoldenTestCase
{
    private const NOVEL = 'https://novelfull.com/shadow-slave.html';
    private const AJAX = 'https://novelfull.com/ajax-chapter-option?novelId=1396';
    private const CHAPTER = 'https://novelfull.com/shadow-slave/chapter-1-nightmare-begins.html';
    private const EMPIRE = 'https://www.empirenovel.com/novel/taming-the-villainesses';

    /** A page that is neither a challenge nor a chapter (what a wrong plain answer looks like). */
    private const THIN = '<html><body><div id="chapter-content"><p>Loading the chapter, please wait.</p></div></body></html>';

    /**
     * A FakeFetcher whose plain() can answer differently from html() for the
     * same URL ($plainRoutes win; anything else falls through to $routes),
     * and which records the user agent and cookies each plain() call sent.
     * $onHtml runs after every successful html(), e.g. to emulate a solve
     * refreshing the clearance store.
     */
    private function fakeSplit(array $routes, array $plainRoutes = [], array $prefixes = [], ?\Closure $onHtml = null): FakeFetcher
    {
        $fake = new class($routes, $prefixes, $plainRoutes, $onHtml) extends FakeFetcher {
            /** @var array<int, array{url: string, ua: ?string, cookies: array}> */
            public array $sent = [];

            public function __construct(array $routes, array $prefixes, private array $plainRoutes, private ?\Closure $onHtml)
            {
                parent::__construct($routes, $prefixes);
            }

            public function plain(string $url, array $headers = [], array $cookies = [], &$status = null): ?string
            {
                $this->sent[] = ['url' => $url, 'ua' => $headers['User-Agent'] ?? null, 'cookies' => $cookies];
                if (!array_key_exists($url, $this->plainRoutes)) {
                    return parent::plain($url, $headers, $cookies, $status);
                }

                $this->calls[] = ['method' => 'plain', 'url' => $url];
                $value = $this->plainRoutes[$url];
                if (is_array($value)) {
                    $status = $value['status'] ?? $value['reason'];
                    return null;
                }
                $status = 200;
                $body = FakeFetcher::body($value);

                return $body === '' ? null : $body;
            }

            public function html(string $url, ?string &$reason = null, ?string $waitForSelector = null, int $maxAttempts = 3): ?string
            {
                $html = parent::html($url, $reason, $waitForSelector, $maxAttempts);
                if ($html !== null && $this->onHtml !== null) {
                    ($this->onHtml)($url);
                }

                return $html;
            }
        };

        $this->fetcher = $fake;
        app()->instance(Fetcher::class, $fake);

        return $fake;
    }

    private function seedClearance(string $host, string $cf = 'cached-cf', string $ua = 'Cached-UA/1.0'): void
    {
        app(Fetcher::class)->rememberClearance($host, ['cf_clearance' => $cf], $ua);
        $this->assertNotNull(app(Fetcher::class)->clearance($host));
    }

    private function chapterFor(string $url = self::CHAPTER)
    {
        return $this->chapter($this->novel(self::NOVEL, 'Shadow Slave'), $url, 1, 'Chapter 1 Nightmare Begins');
    }

    // ---- chapters ---------------------------------------------------------

    public function testWithoutAnEntryChaptersGoThroughFlareSolverr(): void
    {
        $fetcher = $this->fakeSplit([self::CHAPTER => 'novelfull/chapter-1-nightmare-begins.html']);

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapterFor(), $reason);

        $this->assertNull($reason);
        $this->assertContentGolden('novelfull/chapter-1-nightmare-begins.expected.json', $paragraphs, 'Chapter 1 Nightmare Begins');
        $this->assertSame([self::CHAPTER], $fetcher->urls('html'));
        $this->assertSame([], $fetcher->urls('plain'));
    }

    public function testACachedEntryServesTheChapterPlainWithItsUserAgentAndCookie(): void
    {
        $fetcher = $this->fakeSplit([], [self::CHAPTER => 'novelfull/chapter-1-nightmare-begins.html']);
        $this->seedClearance('novelfull.com');

        \Log::spy();
        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapterFor(), $reason);

        $this->assertNull($reason);
        $this->assertContentGolden('novelfull/chapter-1-nightmare-begins.expected.json', $paragraphs, 'Chapter 1 Nightmare Begins');
        $this->assertSame([self::CHAPTER], $fetcher->urls('plain'));
        $this->assertSame([], $fetcher->urls('html'), 'FlareSolverr not called');
        $this->assertSame([['url' => self::CHAPTER, 'ua' => 'Cached-UA/1.0', 'cookies' => ['cf_clearance' => 'cached-cf']]], $fetcher->sent);
        \Log::shouldHaveReceived('debug')->withArgs(fn($m) => str_contains($m, 'ChapterGenerator fetched ' . self::CHAPTER . ' via plain'))->once();
    }

    public function testThinPlainContentIsRetriedOnceViaFlareSolverrAndForgetsTheEntryWhenThatIsComplete(): void
    {
        $fetcher = $this->fakeSplit(
            [self::CHAPTER => 'novelfull/chapter-1-nightmare-begins.html'],
            [self::CHAPTER => self::THIN]
        );
        $this->seedClearance('novelfull.com');

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapterFor(), $reason);

        $this->assertNull($reason);
        $this->assertContentGolden('novelfull/chapter-1-nightmare-begins.expected.json', $paragraphs, 'Chapter 1 Nightmare Begins');
        $this->assertSame([self::CHAPTER], $fetcher->urls('plain'));
        $this->assertSame([self::CHAPTER], $fetcher->urls('html'), 'exactly one FlareSolverr retry');
        $this->assertNull(app(Fetcher::class)->clearance('novelfull.com'), 'the plain path was served something else');
        $this->assertSame([], \App\Scraping\FailureSnapshot::files(7));
    }

    public function testThinOnBothPathsRetriesOnceAndKeepsTheEntry(): void
    {
        $fetcher = $this->fakeSplit([self::CHAPTER => self::THIN], [self::CHAPTER => self::THIN]);
        $this->seedClearance('novelfull.com');

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapterFor(), $reason);

        $this->assertNull($reason, 'short content is left to the caller, as before');
        $this->assertNotSame([], $paragraphs);
        $this->assertFalse(chapterParagraphsLookComplete($paragraphs));
        $this->assertSame([self::CHAPTER], $fetcher->urls('plain'));
        $this->assertSame([self::CHAPTER], $fetcher->urls('html'), 'one retry, not more');
        $this->assertNotNull(app(Fetcher::class)->clearance('novelfull.com'));

        $snapshots = \App\Scraping\FailureSnapshot::files(7);
        $this->assertCount(1, $snapshots);
        $this->assertSame('short_content', $snapshots[0]['reason']);
    }

    public function testNoPlainContentWithAFailedRetryReportsNoContent(): void
    {
        $empty = '<html><body><div id="chapter-content"></div></body></html>';
        $fetcher = $this->fakeSplit([self::CHAPTER => FakeFetcher::fail('http_5xx', 500)], [self::CHAPTER => $empty]);
        $this->seedClearance('novelfull.com');

        $reason = null;
        $this->assertSame([], chapterGenerator($this->chapterFor(), $reason));

        $this->assertSame('no_content', $reason);
        $this->assertSame([self::CHAPTER], $fetcher->urls('html'), 'one retry');
        $this->assertNotNull(app(Fetcher::class)->clearance('novelfull.com'));
    }

    public function testPlain429IsRateLimitedWithoutFlareSolverr(): void
    {
        $fetcher = $this->fakeSplit([], [self::CHAPTER => FakeFetcher::fail('rate_limited', 429)]);
        $this->seedClearance('novelfull.com');

        $reason = null;
        $this->assertSame([], chapterGenerator($this->chapterFor(), $reason));

        $this->assertSame('rate_limited', $reason);
        $this->assertSame([], $fetcher->urls('html'));
        $this->assertNotNull(app(Fetcher::class)->clearance('novelfull.com'));
    }

    public function testPlain404IsNotFoundWithoutFlareSolverr(): void
    {
        $fetcher = $this->fakeSplit([], [self::CHAPTER => FakeFetcher::fail('http_404', 404)]);
        $this->seedClearance('novelfull.com');

        $reason = null;
        $this->assertSame([], chapterGenerator($this->chapterFor(), $reason));

        $this->assertSame('not_found', $reason);
        $this->assertSame([], $fetcher->urls('html'));
    }

    public function testRefusedPlainFallsBackOnceWithoutTheContentRetry(): void
    {
        $fetcher = $this->fakeSplit(
            [self::CHAPTER => 'novelfull/chapter-1-nightmare-begins.html'],
            [self::CHAPTER => FakeFetcher::fail('http_4xx', 403)]
        );
        $this->seedClearance('novelfull.com');

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapterFor(), $reason);

        $this->assertNull($reason);
        $this->assertTrue(chapterParagraphsLookComplete($paragraphs));
        $this->assertSame([self::CHAPTER], $fetcher->urls('plain'));
        $this->assertSame([self::CHAPTER], $fetcher->urls('html'));
        $this->assertNull(app(Fetcher::class)->clearance('novelfull.com'), 'refused entry forgotten');
    }

    public function testZeroMinutesIgnoresTheStoreEntirely(): void
    {
        $fetcher = $this->fakeSplit([self::CHAPTER => self::THIN], [self::CHAPTER => 'novelfull/chapter-1-nightmare-begins.html']);
        $this->seedClearance('novelfull.com');
        config(['novarr.clearance_minutes' => 0]);

        chapterGenerator($this->chapterFor());

        $this->assertSame([], $fetcher->urls('plain'));
        $this->assertSame([self::CHAPTER], $fetcher->urls('html'), 'no plain fetch, no content retry');
    }

    // ---- tables of contents -----------------------------------------------

    private function empireRoutes(): array
    {
        return [
            self::EMPIRE . '?page=1' => 'empirenovel/toc-taming-the-villainesses-page-1.html',
            self::EMPIRE . '?page=2' => 'empirenovel/toc-taming-the-villainesses-page-2.html',
            self::EMPIRE . '?page=6' => 'empirenovel/toc-taming-the-villainesses-page-6.html',
            self::EMPIRE . '?page=12' => 'empirenovel/toc-taming-the-villainesses-page-12.html',
        ];
    }

    public function testEmpireNovelTocWithACachedEntryNeedsNoSolve(): void
    {
        $fetcher = $this->fakeSplit($this->empireRoutes(), [], [self::EMPIRE . '?page=' => 'empirenovel/toc-page-not-captured.html']);
        $this->seedClearance('www.empirenovel.com');

        $toc = empireNovelToc(self::EMPIRE);

        $this->assertTocGolden('empirenovel/toc-taming-the-villainesses.expected.json', $toc, 'https://www.empirenovel.com');
        $this->assertSame([], $fetcher->urls('session'), 'page 1 went plain too');
        $this->assertSame([], $fetcher->urls('html'));
        $this->assertSame(array_map(fn($p) => self::EMPIRE . '?page=' . $p, range(1, 12)), $fetcher->urls('plain'));
        foreach ($fetcher->sent as $sent) {
            $this->assertSame(['cf_clearance' => 'cached-cf'], $sent['cookies']);
            $this->assertSame('Cached-UA/1.0', $sent['ua']);
        }
    }

    public function testEmpireNovelTocPicksUpAClearanceRefreshedByAFallbackSolve(): void
    {
        $fetcher = $this->fakeSplit(
            $this->empireRoutes(),
            [self::EMPIRE . '?page=1' => FakeFetcher::fail('http_4xx', 403), self::EMPIRE . '?page=2' => FakeFetcher::fail('http_4xx', 403)],
            [self::EMPIRE . '?page=' => 'empirenovel/toc-page-not-captured.html'],
            fn(string $url) => app(Fetcher::class)->rememberClearance($url, ['cf_clearance' => 'fresh-cf'], 'Fresh-UA'),
        );
        $this->seedClearance('www.empirenovel.com');

        $toc = empireNovelToc(self::EMPIRE);

        $this->assertTocGolden('empirenovel/toc-taming-the-villainesses.expected.json', $toc, 'https://www.empirenovel.com');
        // Page 1: plain refused -> solve. Its fake solve carried the cached
        // session's cookie (FakeFetcher's session); page 2 plain refused ->
        // html() fallback, which refreshed the store; 3..12 use the fresh one.
        $this->assertSame([self::EMPIRE . '?page=1'], $fetcher->urls('session'));
        $this->assertSame([self::EMPIRE . '?page=2'], $fetcher->urls('html'));
        $later = array_values(array_filter($fetcher->sent, fn($s) => !str_ends_with($s['url'], '?page=1') && !str_ends_with($s['url'], '?page=2')));
        $this->assertCount(10, $later);
        foreach ($later as $sent) {
            $this->assertSame(['cf_clearance' => 'fresh-cf'], $sent['cookies']);
            $this->assertSame('Fresh-UA', $sent['ua']);
        }
        $this->assertFalse(tocRunWasPartial());
    }

    public function testNovelFullTocWithACachedEntryNeedsNoSolve(): void
    {
        $fetcher = $this->fakeSplit([
            self::NOVEL => 'novelfull/novel-page-shadow-slave.html',
            self::AJAX => 'novelfull/ajax-chapter-option-shadow-slave.html',
        ]);
        $this->seedClearance('novelfull.com');

        $toc = novelFullToc(self::NOVEL);

        $this->assertTocGolden('novelfull/toc-shadow-slave.expected.json', $toc, 'https://novelfull.com');
        $this->assertSame([], $fetcher->urls('session'));
        $this->assertSame([], $fetcher->urls('html'));
        $this->assertSame([self::NOVEL, self::AJAX], $fetcher->urls('plain'));
    }

    public function testNovelFullTocWithoutAnEntryIsUnchanged(): void
    {
        $fetcher = $this->fakeSplit([
            self::NOVEL => 'novelfull/novel-page-shadow-slave.html',
            self::AJAX => 'novelfull/ajax-chapter-option-shadow-slave.html',
        ]);

        $toc = novelFullToc(self::NOVEL);

        $this->assertTocGolden('novelfull/toc-shadow-slave.expected.json', $toc, 'https://novelfull.com');
        $this->assertSame([self::NOVEL], $fetcher->urls('session'));
        $this->assertSame([self::AJAX], $fetcher->urls('plain'));
    }
}
