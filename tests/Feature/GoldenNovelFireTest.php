<?php

namespace Tests\Feature;

use App\Novel;
use App\Sources\EmpireNovelSource;
use App\Sources\MangaDexSource;
use App\Sources\NovelArrowSource;
use App\Sources\NovelFireSource;
use App\Sources\NovelFullSource;
use App\Sources\SourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFetcher;

/**
 * novelfire.net through the real adapter, against pages saved from the live
 * site on 2026-11-20. See tests/fixtures/novelfire/README.md.
 */
class GoldenNovelFireTest extends GoldenTestCase
{
    private const BOOK = 'https://novelfire.net/book/shadow-slave';
    private const SMALL_BOOK = 'https://novelfire.net/book/shadow-slave-error';

    private static function listPage(string $slug, int $page): string
    {
        return NovelFireSource::chapterListUrl($slug, $page);
    }

    // ---- table of contents -------------------------------------------------

    public function testTableOfContentsWalksTheChapterListNewestFirst(): void
    {
        $fetcher = $this->fake([
            self::listPage('shadow-slave-error', 1) => 'novelfire/chapters-shadow-slave-error-desc-page-1.html',
            self::listPage('shadow-slave-error', 2) => 'novelfire/chapters-shadow-slave-error-desc-page-2.html',
        ]);
        $novel = $this->novel(self::SMALL_BOOK, 'Shadow Slave: Error');

        $this->assertInstanceOf(NovelFireSource::class, SourceResolver::for($novel));
        $toc = tableOfContentGenerator($novel);

        $summary = $this->assertTocGolden('novelfire/toc-shadow-slave-error.expected.json', $toc, 'https://novelfire.net');
        $this->assertSame(108, $summary['count']);
        $this->assertFalse(tocRunWasPartial());
        $this->assertFalse(tocRunWasIncremental());

        // Two list pages (100 rows each), plain GETs only: no FlareSolverr.
        $this->assertSame([self::listPage('shadow-slave-error', 1), self::listPage('shadow-slave-error', 2)], $fetcher->urls('plain'));
        $this->assertSame([], $fetcher->urls('html'));

        // Oldest first, numbered 1..108 in order, every URL the book's own.
        $this->assertSame(array_map('strval', range(1, 108)), array_map(fn($r) => (string) $r['chapter'], $toc));
        foreach ($toc as $i => $row) {
            $this->assertSame(self::SMALL_BOOK . '/chapter-' . ($i + 1), $row['url']);
            $this->assertSame(0, (int) $row['book']);
        }

        // "Chapter 1 - 1: Three Legendary Transmigrator" would parse as part
        // 1 of chapter 1 ("1.1"); the repeated number is dropped. An untitled
        // "Chapter 108 -" keeps just "Chapter 108".
        $this->assertSame('Chapter 1: Three Legendary Transmigrator', $toc[0]['label']);
        $this->assertSame('Chapter 108', $toc[107]['label']);

        // An arc part in the title ("… (1)") is not a split chapter: the
        // label parser alone would number these 69.1, 70.2, 71.3.
        $this->assertSame('Chapter 69: Siege Of The Crimson Spire (1)', $toc[68]['label']);
        $this->assertSame(['69', '70', '71'], array_map(fn($r) => (string) $r['chapter'], array_slice($toc, 68, 3)));
    }

    public function testIncrementalWalkStopsAtTheFirstPageOfStoredChapters(): void
    {
        $fetcher = $this->fake([
            self::listPage('shadow-slave', 1) => 'novelfire/chapters-shadow-slave-desc-page-1.html',
        ]);
        $novel = $this->novel(self::BOOK, 'Shadow Slave');

        $known = [];
        foreach (range(3100, 3252) as $n) {
            $known[self::BOOK . '/chapter-' . $n] = true;
        }
        $toc = tableOfContentGenerator($novel, fn() => $known);

        // Page 1 of 33 holds chapters 3252..3153, all stored: one request.
        $this->assertSame([self::listPage('shadow-slave', 1)], $fetcher->urls());
        $this->assertTrue(tocRunWasIncremental());
        $this->assertCount(100, $toc);
        $this->assertSame('3153', (string) $toc[0]['chapter']);
        $this->assertSame('3252', (string) $toc[99]['chapter']);
        $this->assertSame('Chapter 3252 Preventative Measures', $toc[99]['label']);
        $this->assertSame(33, NovelFireSource::lastListPage(FakeFetcher::body('novelfire/chapters-shadow-slave-desc-page-1.html'), 'shadow-slave'));
    }

    public function testAPageThatFailsTwiceEndsTheWalkAsPartial(): void
    {
        $fetcher = $this->fake([
            self::listPage('shadow-slave-error', 1) => 'novelfire/chapters-shadow-slave-error-desc-page-1.html',
            self::listPage('shadow-slave-error', 2) => FakeFetcher::fail('http_5xx', 500),
        ]);
        $novel = $this->novel(self::SMALL_BOOK, 'Shadow Slave: Error');

        $toc = tableOfContentGenerator($novel);

        $this->assertTrue(tocRunWasPartial());
        $this->assertCount(100, $toc);
        $this->assertSame('9', (string) $toc[0]['chapter']);
        // Plain, then the FlareSolverr path, twice.
        $this->assertSame(array_fill(0, 2, self::listPage('shadow-slave-error', 2)), $fetcher->urls('html'));
    }

    /** A rate-limited list page is waited out and asked again, RATE_LIMIT_WAITS times, before the walk ends as partial. */
    public function testARateLimitedPageIsRetriedBeforeTheWalkEndsAsPartial(): void
    {
        $fetcher = $this->fake([
            self::listPage('shadow-slave-error', 1) => 'novelfire/chapters-shadow-slave-error-desc-page-1.html',
            self::listPage('shadow-slave-error', 2) => FakeFetcher::fail('rate_limited', 429),
        ]);

        $toc = tableOfContentGenerator($this->novel(self::SMALL_BOOK, 'Shadow Slave: Error'));

        $this->assertTrue(tocRunWasPartial());
        $this->assertCount(100, $toc);
        $asked = array_keys($fetcher->urls('plain'), self::listPage('shadow-slave-error', 2), true);
        $this->assertCount(1 + NovelFireSource::RATE_LIMIT_WAITS, $asked);
        // Never through FlareSolverr: it would get the same answer.
        $this->assertSame([], $fetcher->urls('html'));
    }

    public function testUnreachableChapterListGivesNoEntries(): void
    {
        $this->fake([self::listPage('shadow-slave', 1) => FakeFetcher::fail('http_404', 404)]);

        $this->assertSame([], tableOfContentGenerator($this->novel(self::BOOK, 'Shadow Slave')));
    }

    // ---- chapter content ---------------------------------------------------

    #[DataProvider('chapters')]
    public function testChapterContent(string $book, int $number, string $label, string $fixture): void
    {
        $url = "https://novelfire.net/book/{$book}/chapter-{$number}";
        $fetcher = $this->fake([$url => "novelfire/{$fixture}.html"]);
        $novel = $this->novel("https://novelfire.net/book/{$book}", $book);

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapter($novel, $url, $number, $label), $reason);

        $this->assertNull($reason);
        $this->assertContentGolden("novelfire/{$fixture}.expected.json", $paragraphs, $label);
        $this->assertTrue(chapterParagraphsLookComplete($paragraphs));
        // The adapter's plain fast path: no FlareSolverr round trip.
        $this->assertSame([$url], $fetcher->urls('plain'));
        $this->assertSame([], $fetcher->urls('html'));

        foreach ($paragraphs as $p) {
            $text = self::plain($p);
            $this->assertStringNotContainsStringIgnoringCase('novelfire', $text);
            $this->assertStringNotContainsStringIgnoringCase('novel fire', $text);
            $this->assertStringNotContainsStringIgnoringCase('a-ads', $text);
            $this->assertStringNotContainsStringIgnoringCase('Add to library', $text);
        }
    }

    public static function chapters(): array
    {
        return [
            'shadow slave 1' => ['shadow-slave', 1, 'Chapter 1: Nightmare Begins', 'chapter-shadow-slave-1'],
            'legendary mechanic 500' => ['the-legendary-mechanic', 500, 'Chapter 500 - Return', 'chapter-the-legendary-mechanic-500'],
        ];
    }

    public function testChapterHeadingInsideTheBodyIsRemoved(): void
    {
        $paragraphs = \App\Scraping\ContentExtractor::make()->extract(
            FakeFetcher::body('novelfire/chapter-the-legendary-mechanic-500.html'),
            new NovelFireSource()
        );

        $this->assertSame('Translator: Atlas Studios Editor: Atlas Studios', trim(self::plain($paragraphs[0])));
        foreach ($paragraphs as $p) {
            $this->assertNotSame('Chapter 500: Return', trim(self::plain($p)));
        }
    }

    /** A diverted request (302 to the partner site) is asked for again. */
    public function testDivertedChapterIsFetchedAgain(): void
    {
        $url = self::BOOK . '/chapter-1';
        $fetcher = $this->sequenced([$url => ['novelfire/redirect-readnovel-loader.html', 'novelfire/chapter-shadow-slave-1.html']]);

        $failure = 'unset';
        $paragraphs = (new NovelFireSource())->chapterContent($url, $failure);

        $this->assertNull($failure);
        $this->assertCount(96, $paragraphs);
        $this->assertSame([$url, $url], $fetcher->urls('plain'));
    }

    /**
     * Diverted on every attempt: nothing, as a rate limit, so the chapter
     * waits for a later run instead of FlareSolverr following the partner
     * site's script redirect.
     */
    public function testChapterDivertedEveryTimeIsLeftForLater(): void
    {
        $url = self::BOOK . '/chapter-3252';
        $novel = $this->novel(self::BOOK, 'Shadow Slave');
        $fetcher = $this->sequenced([$url => array_fill(0, NovelFireSource::CHAPTER_ATTEMPTS, 'novelfire/redirect-readnovel-loader.html')]);

        $reason = null;
        $paragraphs = chapterGenerator($this->chapter($novel, $url, 3252, 'Chapter 3252 Preventative Measures'), $reason);

        $this->assertSame([], $paragraphs);
        $this->assertSame('rate_limited', $reason);
        $this->assertSame(array_fill(0, NovelFireSource::CHAPTER_ATTEMPTS, $url), $fetcher->urls('plain'));
        $this->assertSame([], $fetcher->urls('html'));
        $this->assertFalse(NovelFireSource::isChapterPage(FakeFetcher::body('novelfire/redirect-readnovel-loader.html')));
        $this->assertFalse(NovelFireSource::isChapterPage(FakeFetcher::body('novelfire/redirect-302-shadow-slave-3252.html')));
    }

    public function testMissingChapterIsNotFound(): void
    {
        $url = self::BOOK . '/chapter-999999';
        $this->fake([$url => FakeFetcher::fail('http_404', 404)]);

        $failure = null;
        $this->assertSame([], (new NovelFireSource())->chapterContent($url, $failure));
        $this->assertSame('not_found', $failure);
    }

    // ---- metadata ----------------------------------------------------------

    public function testMetadataFromTheBookPage(): void
    {
        $fetcher = $this->fake([self::BOOK => 'novelfire/book-shadow-slave.html']);

        // A chapter URL resolves to its book page.
        $meta = (new NovelFireSource())->pageMetadata('https://www.novelfire.net/book/shadow-slave/chapter-12');
        $expected = $this->golden('novelfire/book-shadow-slave.expected.json', $meta);

        $this->assertSame($expected, $meta);
        $this->assertSame([self::BOOK], $fetcher->urls());
        $this->assertSame('Shadow Slave', $meta['title']);
        $this->assertSame('Guiltythree', $meta['author']);
        $this->assertSame(3252, $meta['no_of_chapters']);
        $this->assertSame('https://novelfire.net/server-1/shadow-slave.jpg', $meta['image']);
        $this->assertSame(['Action', 'Adventure', 'Fantasy', 'Romance'], $meta['genres']);
        $this->assertSame('Ongoing', $meta['status_text']);
        $this->assertFalse($meta['completed']);
        $this->assertStringStartsWith('<p>Growing up in poverty, Sunny', $meta['description']);
        $this->assertStringNotContainsString('Show More', $meta['description']);
    }

    public function testMetadataMergedWithoutNovelUpdates(): void
    {
        $this->fake([self::BOOK => 'novelfire/book-shadow-slave.html']);
        $source = new class extends NovelFireSource {
            protected function novelUpdatesMetadata(Novel $novel): array
            {
                return [];
            }
        };

        $meta = $source->metadata($this->novel(self::BOOK, 'Shadow Slave'));

        foreach (['description', 'author', 'no_of_chapters', 'image', 'genres', 'cover_candidates'] as $key) {
            $this->assertArrayHasKey($key, $meta);
        }
        $this->assertSame('Guiltythree', $meta['author']);
        $this->assertSame(3252, $meta['no_of_chapters']);
        $this->assertSame(['https://novelfire.net/server-1/shadow-slave.jpg'], $meta['cover_candidates']);
    }

    // ---- discover ----------------------------------------------------------

    public function testSearchResults(): void
    {
        $fetcher = $this->fake([NovelFireSource::BASE . '/search?keyword=shadow+slave' => 'novelfire/search-shadow-slave.html']);

        $items = (new NovelFireSource())->discover('search', 'shadow slave');
        $expected = $this->golden('novelfire/search-shadow-slave.expected.json', $items);

        $this->assertSame($expected, $items);
        $this->assertSame([NovelFireSource::BASE . '/search?keyword=shadow+slave'], $fetcher->urls('plain'));
        // The 20 results of page 1, not the "Some Popular Novels" block below them.
        $this->assertCount(20, $items);
        $this->assertSame([
            'name' => 'Shadow Slave',
            'url' => self::BOOK,
            'cover' => 'https://novelfire.net/server-1/shadow-slave.jpg',
            'author' => '',
            'chapters' => 3252,
        ], $items[0]);
        foreach ($items as $item) {
            $this->assertSame(['name', 'url', 'cover', 'author', 'chapters'], array_keys($item));
            $this->assertStringStartsWith('https://novelfire.net/book/', $item['url']);
            $this->assertStringStartsWith('https://novelfire.net/', $item['cover']);
        }
        $this->assertNotContains('https://novelfire.net/book/heavenly-genius', array_column($items, 'url'));
    }

    public function testSearchWithNoResultsIsEmpty(): void
    {
        $this->fake([NovelFireSource::BASE . '/search?keyword=zzqxvjwq+nonexistent' => 'novelfire/search-no-results.html']);

        $this->assertSame([], (new NovelFireSource())->discover('search', 'zzqxvjwq nonexistent'));
    }

    public function testSearchWhenTheSiteIsUnreachableIsNull(): void
    {
        $url = NovelFireSource::BASE . '/search?keyword=unreachable+query';
        $fetcher = $this->fake([$url => FakeFetcher::fail('http_5xx', 503)]);

        $this->assertNull((new NovelFireSource())->discover('search', 'unreachable query'));
        // Plain refused, then the clearance/FlareSolverr path.
        $this->assertSame([$url], $fetcher->urls('plain'));
        $this->assertSame([$url], $fetcher->urls('html'));
    }

    public function testOnlySearchIsListed(): void
    {
        $this->fake([]);
        $source = new NovelFireSource();

        $this->assertSame(['search'], $source->discoverLists());
        $this->assertSame([], $source->discover('popular'));
        $this->assertSame('novelfire.net', $source->discoverHost());
        $this->assertSame('novelfire', $source->name());
        $this->assertSame(Novel::MEDIUM_NOVEL, $source->medium());
        $this->assertNull($source->supportsMultiPage());
    }

    // ---- URL matching ------------------------------------------------------

    public function testUrlMatchingAndSlugs(): void
    {
        $this->fake([]);
        $source = new NovelFireSource();

        foreach ([
            'https://novelfire.net/book/shadow-slave',
            'https://www.novelfire.net/book/shadow-slave',
            'http://novelfire.net/book/shadow-slave/',
            'https://novelfire.net/book/shadow-slave/chapter-1',
            'https://www.novelfire.net/book/shadow-slave/chapters?page=2',
            'novelfire.net/book/shadow-slave',
        ] as $url) {
            $this->assertTrue($source->matchesUrl($url), $url);
            $this->assertInstanceOf(NovelFireSource::class, SourceResolver::forUrl($url), $url);
            $this->assertSame('shadow-slave', $source->librarySlug($url), $url);
            $this->assertSame('shadow-slave', $source->librarySlug($url, stored: true), $url);
            // The catch-all has no slug for it either.
            $this->assertNull((new NovelArrowSource())->librarySlug($url), $url);
        }

        $this->assertTrue(NovelFireSource::isChapterUrl('https://www.novelfire.net/book/shadow-slave/chapter-3252'));
        $this->assertFalse(NovelFireSource::isChapterUrl('https://novelfire.net/book/shadow-slave'));
        $this->assertFalse(NovelFireSource::isChapterUrl('https://novelfire.net/book/shadow-slave/chapters'));
        $this->assertFalse(NovelFireSource::isChapterUrl('https://novelfull.com/book/shadow-slave/chapter-1'));

        // Look-alike hosts and non-book pages.
        foreach (['https://notnovelfire.net/book/shadow-slave', 'https://novelfire.net.evil.test/book/shadow-slave'] as $url) {
            $this->assertFalse($source->matchesUrl($url), $url);
            $this->assertNull($source->librarySlug($url), $url);
        }
        $this->assertNull($source->librarySlug('https://novelfire.net/search?keyword=x'));
        $this->assertNull($source->librarySlug('https://novelfire.net/genre-all/sort-new/status-all/all-novel'));

        // Every other source keeps its own URLs.
        $this->assertInstanceOf(NovelFullSource::class, SourceResolver::forUrl('https://novelfull.com/shadow-slave.html'));
        $this->assertInstanceOf(EmpireNovelSource::class, SourceResolver::forUrl('https://www.empirenovel.com/novel/shadow-slave'));
        $this->assertInstanceOf(MangaDexSource::class, SourceResolver::forUrl('https://mangadex.org/title/a96676e5-8ae2-425e-b549-7f15dd34a6d8'));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('https://novelping.com/novel/shadow-slave'));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('https://example.test/novel/x'));
        $this->assertFalse($source->matchesUrl('https://novelfull.com/shadow-slave.html'));

        // No fast path (and no request) for anyone else's chapter.
        $failure = 'unset';
        $this->assertNull($source->chapterContent('https://novelfull.com/shadow-slave/chapter-1.html', $failure));
        $this->assertNull($failure);
        $this->assertSame([], $this->fetcher->calls);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * A FakeFetcher whose plain() answers each listed URL with the given
     * bodies in turn (the last one repeats).
     *
     * @param array<string, string[]> $sequences
     */
    private function sequenced(array $sequences): FakeFetcher
    {
        $this->fetcher = new class($sequences) extends FakeFetcher {
            public function __construct(private array $sequences)
            {
                parent::__construct();
            }

            public function plain(string $url, array $headers = [], array $cookies = [], &$status = null): ?string
            {
                if (!isset($this->sequences[$url])) {
                    return parent::plain($url, $headers, $cookies, $status);
                }
                $this->calls[] = ['method' => 'plain', 'url' => $url];
                $body = count($this->sequences[$url]) > 1 ? array_shift($this->sequences[$url]) : $this->sequences[$url][0];
                $status = 200;

                return FakeFetcher::body($body);
            }
        };
        app()->instance(\App\Scraping\Fetcher::class, $this->fetcher);

        return $this->fetcher;
    }
}
