<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Sources\NovelFullSource;
use App\Sources\SourceResolver;
use Tests\Support\FakeFetcher;

/**
 * novelfull.com through the real adapter, against pages saved from the live
 * site on 2026-10-03 (Shadow Slave). See tests/fixtures/novelfull/README.md.
 */
class GoldenNovelFullTest extends GoldenTestCase
{
    private const NOVEL = 'https://novelfull.com/shadow-slave.html';
    private const AJAX = 'https://novelfull.com/ajax-chapter-option?novelId=1396';
    private const LEGACY_AJAX = 'https://novelfull.com/ajax/chapter-option?novelId=1396';

    public function testTableOfContentsFromTheAjaxChapterOptionEndpoint(): void
    {
        $fetcher = $this->fake([
            self::NOVEL => 'novelfull/novel-page-shadow-slave.html',
            self::AJAX => 'novelfull/ajax-chapter-option-shadow-slave.html',
        ]);
        $novel = $this->novel(self::NOVEL, 'Shadow Slave');

        $this->assertInstanceOf(NovelFullSource::class, SourceResolver::for($novel));
        $toc = tableOfContentGenerator($novel);

        $this->assertTocGolden('novelfull/toc-shadow-slave.expected.json', $toc, 'https://novelfull.com');

        // Novel page via FlareSolverr (session), chapter list via the new
        // endpoint only — the legacy one is never needed.
        $this->assertSame([self::NOVEL], $fetcher->urls('session'));
        $this->assertSame([self::AJAX], $fetcher->urls('plain'));

        $byUrl = array_column($toc, null, 'url');

        // Typo'd labels: either parsed correctly or left at 0 for
        // ChapterNumberResolver — never given a wrong number.
        foreach ([
            '/shadow-slave/chpater-2812-from-dusk-till-dawn.html' => 2812,
            '/shadow-slave/chaoter-2843-where-it-all-began.html' => 2843,
            '/shadow-slave/chapter-entertaining-guest.html' => 3189,
        ] as $path => $number) {
            $row = $byUrl['https://novelfull.com' . $path] ?? null;
            $this->assertNotNull($row, "missing TOC entry {$path}");
            $this->assertContains((int) $row['chapter'], [0, $number], "{$path} numbered {$row['chapter']}");
        }

        // Every row is a real chapter page on the novel's own path.
        foreach ($toc as $row) {
            $this->assertMatchesRegularExpression('#^https://novelfull\.com/shadow-slave/[^\s"<>]+\.html$#', $row['url']);
        }
    }

    /**
     * The old endpoint now serves a 200 "Not Found" page whose reader
     * settings <option>s used to become junk chapters
     * ("https://novelfull.com16px"). It must yield nothing, with a warning,
     * and the page is kept as a failure snapshot.
     */
    public function testLegacyEndpointNotFoundPageYieldsNoEntries(): void
    {
        $fetcher = $this->fake([
            self::NOVEL => 'novelfull/novel-page-shadow-slave.html',
            self::AJAX => FakeFetcher::fail('http_5xx', 503),
            self::LEGACY_AJAX => 'novelfull/ajax-legacy-endpoint-not-found.html',
        ]);
        $novel = $this->novel(self::NOVEL, 'Shadow Slave');

        \Log::spy();
        $toc = tableOfContentGenerator($novel);

        $this->assertSame([], $toc);
        \Log::shouldHaveReceived('warning')->withArgs(fn($m) => str_contains($m, 'no chapter <option> entries'))->once();

        // Plain client first, FlareSolverr fallback, then the legacy path.
        $this->assertSame([self::AJAX, self::LEGACY_AJAX], $fetcher->urls('plain'));
        $this->assertSame([self::AJAX], $fetcher->urls('html'));

        $this->assertSame(null, parseNovelFullChapterOptions(FakeFetcher::body('novelfull/ajax-legacy-endpoint-not-found.html'), 'https://novelfull.com'));

        $snapshots = \App\Scraping\FailureSnapshot::files(7);
        $this->assertCount(1, $snapshots);
        $this->assertSame('empty_toc', $snapshots[0]['reason']);
        $this->assertSame(0, $snapshots[0]['chapter_id']);
    }

    #[DataProvider('chapters')]
    public function testChapterContent(string $slug, int $number, string $label): void
    {
        $url = "https://novelfull.com/shadow-slave/{$slug}.html";
        $this->fake([$url => "novelfull/{$slug}.html"]);
        $novel = $this->novel(self::NOVEL, 'Shadow Slave');

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapter($novel, $url, $number, $label), $reason);

        $this->assertNull($reason);
        $this->assertContentGolden("novelfull/{$slug}.expected.json", $paragraphs, $label);
        $this->assertTrue(chapterParagraphsLookComplete($paragraphs));
        $this->assertSame([], \App\Scraping\FailureSnapshot::files(7), 'a complete chapter is not snapshotted');
    }

    public static function chapters(): array
    {
        return [
            'chapter 1' => ['chapter-1-nightmare-begins', 1, 'Chapter 1 Nightmare Begins'],
            'chapter 1600' => ['chapter-1600-beast-farm', 1600, 'Chapter 1600 Beast Farm'],
        ];
    }

    /** The ".me" / ".me😉" watermark tails in chapter 1 are dropped. */
    public function testWatermarkTailsAreDropped(): void
    {
        $paragraphs = extractChapterParagraphs(FakeFetcher::body('novelfull/chapter-1-nightmare-begins.html'));
        foreach ($paragraphs as $p) {
            $this->assertDoesNotMatchRegularExpression('/^\.me/u', self::plain($p));
        }
        $this->assertStringContainsString('Prepare for your First Trial', self::plain(end($paragraphs)));
    }

    /** The original few-long-paragraphs fixture still extracts via the source path. */
    public function testEightParagraphChapterThroughTheSource(): void
    {
        $url = 'https://novelfull.com/divine-emperor-of-death/chapter-209.html';
        $this->fake([$url => 'novelfull-chapter-209-eight-paragraphs.html']);
        $novel = $this->novel('https://novelfull.com/divine-emperor-of-death.html', 'Divine Emperor of Death');

        $paragraphs = chapterGenerator($this->chapter($novel, $url, 209, 'Chapter 209'));

        $this->assertCount(6, $paragraphs);
        $this->assertGreaterThan(1000, chapterParagraphWords($paragraphs));
        $this->assertCleanChapter($paragraphs);
    }

    public function testMetadataFromTheNovelPage(): void
    {
        $this->fake([self::NOVEL => 'novelfull/novel-page-shadow-slave.html']);

        $meta = getMetadataFromNovelFull(self::NOVEL);
        $expected = $this->golden('novelfull/novel-page-shadow-slave.expected.json', $meta);

        $this->assertSame($expected, $meta);
        $this->assertSame('Shadow Slave', $meta['title']);
        $this->assertSame('Guiltythree', $meta['author']);
        $this->assertSame('https://novelfull.com/uploads/webp/novel/shadow-slave-ab66830263.webp', $meta['image']);
        $this->assertStringStartsWith('<p>Growing up in poverty, Sunny', $meta['description']);
        $this->assertSame(3203, $meta['no_of_chapters']);
    }

    /**
     * Discover search: cover and author are on the page (img.cover,
     * span.author). NovelFullSource::parseSearchResults() reads them;
     * DiscoverController::searchNovelFull() should call it.
     */
    public function testSearchResultsCarryCoverAndAuthor(): void
    {
        $items = NovelFullSource::parseSearchResults(FakeFetcher::body('novelfull/search-shadow-slave.html'));
        $expected = $this->golden('novelfull/search-shadow-slave.expected.json', $items);

        $this->assertSame($expected, $items);
        $this->assertCount(5, $items);
        $this->assertSame([
            'name' => 'Shadow Slave',
            'url' => 'https://novelfull.com/shadow-slave.html',
            'cover' => 'https://novelfull.com/uploads/webp/novel/shadow-slave-ab66830263.webp',
            'author' => 'Guiltythree',
        ], $items[0]);
        foreach ($items as $item) {
            $this->assertNotSame('', $item['cover']);
            $this->assertNotSame('', $item['author']);
        }
    }
}
