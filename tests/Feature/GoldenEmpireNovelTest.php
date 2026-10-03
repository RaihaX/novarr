<?php

namespace Tests\Feature;

use App\Sources\EmpireNovelSource;
use App\Sources\SourceResolver;
use Tests\Support\FakeFetcher;

/**
 * empirenovel.com through the real adapter (Taming The Villainesses, saved
 * 2026-10-03). Only TOC pages 1, 2, 6 and 12 were captured; the others are
 * served as an empty list page. See tests/fixtures/empirenovel/README.md.
 */
class GoldenEmpireNovelTest extends GoldenTestCase
{
    private const NOVEL = 'https://www.empirenovel.com/novel/taming-the-villainesses';

    private function fakeToc(): FakeFetcher
    {
        return $this->fake([
            self::NOVEL . '?page=1' => 'empirenovel/toc-taming-the-villainesses-page-1.html',
            self::NOVEL . '?page=2' => 'empirenovel/toc-taming-the-villainesses-page-2.html',
            self::NOVEL . '?page=6' => 'empirenovel/toc-taming-the-villainesses-page-6.html',
            self::NOVEL . '?page=12' => 'empirenovel/toc-taming-the-villainesses-page-12.html',
        ], [
            self::NOVEL . '?page=' => 'empirenovel/toc-page-not-captured.html',
        ]);
    }

    public function testTableOfContentsWalksAllTwelvePages(): void
    {
        $fetcher = $this->fakeToc();
        $novel = $this->novel(self::NOVEL, 'Taming The Villainesses');

        $this->assertInstanceOf(EmpireNovelSource::class, SourceResolver::for($novel));
        $toc = tableOfContentGenerator($novel);

        $this->assertTocGolden('empirenovel/toc-taming-the-villainesses.expected.json', $toc, 'https://www.empirenovel.com');

        // Page 1 through FlareSolverr (clearance cookie), pages 2..12 with
        // the plain client — pagination is read from page 1 and reaches 12.
        $this->assertSame([self::NOVEL . '?page=1'], $fetcher->urls('session'));
        $this->assertSame(
            array_map(fn($p) => self::NOVEL . '?page=' . $p, range(2, 12)),
            $fetcher->urls('plain')
        );
        $this->assertSame([], $fetcher->urls('html'), 'no FlareSolverr fallback needed');

        // Ascending, unique, numbered.
        $numbers = array_map(fn($r) => (int) $r['chapter'], $toc);
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers);
        $this->assertSame(count($numbers), count(array_unique($numbers)));

        // The source's own hole: page 2 jumps 813…799 → 455…441, so 456–798
        // (including 500, which is a "Not Found" page) are not listed.
        $this->assertContains(455, $numbers);
        $this->assertContains(799, $numbers);
        $this->assertSame([], array_values(array_filter($numbers, fn($n) => $n > 455 && $n < 799)));
        $this->assertNotContains(500, $numbers);
    }

    /**
     * A failed plain fetch falls back to FlareSolverr; when both fail the
     * walk stops instead of looping over every remaining page.
     */
    public function testPlainClientFailureFallsBackToFlareSolverrThenStops(): void
    {
        // Page 12's pagination (links to 1, 2, 9, 10, 11) served as page 1
        // → the walk covers 2..11. Page 2 is served; every other page fails.
        $fetcher = $this->fake([
            self::NOVEL . '?page=1' => 'empirenovel/toc-taming-the-villainesses-page-12.html',
            self::NOVEL . '?page=2' => 'empirenovel/toc-taming-the-villainesses-page-2.html',
        ], [
            self::NOVEL . '?page=' => FakeFetcher::fail('http_4xx', 403),
        ]);

        $toc = empireNovelToc(self::NOVEL);

        $this->assertSame([self::NOVEL . '?page=2', self::NOVEL . '?page=3'], $fetcher->urls('plain'));
        $this->assertSame([self::NOVEL . '?page=3'], $fetcher->urls('html'));
        $this->assertCount(13 + 30, $toc);
    }

    public function testChapterContent(): void
    {
        $url = self::NOVEL . '/1';
        $this->fake([$url => 'empirenovel/chapter-1-taming-the-villainesses.html']);
        $novel = $this->novel(self::NOVEL, 'Taming The Villainesses');

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapter($novel, $url, 1, 'Chapter 1'), $reason);

        $this->assertNull($reason);
        $this->assertContentGolden('empirenovel/chapter-1-taming-the-villainesses.expected.json', $paragraphs, 'Chapter 1');
        // The translator's header lines are not a "Chapter N" title — kept.
        $this->assertSame('(EP-1.1) Flower Aira', self::plain($paragraphs[0]));
    }

    /** The 500-chapter hole: a 200 "Not Found" page yields no text, and is snapshotted. */
    public function testNotFoundChapterYieldsNothing(): void
    {
        $url = self::NOVEL . '/500';
        $this->fake([$url => 'empirenovel/chapter-500-not-found.html']);
        $novel = $this->novel(self::NOVEL, 'Taming The Villainesses');

        $reason = null;
        $this->assertSame([], chapterGenerator($this->chapter($novel, $url, 500, 'Chapter 500', 99), $reason));
        $this->assertSame('no_content', $reason);

        $snapshots = \App\Scraping\FailureSnapshot::files(7);
        $this->assertCount(1, $snapshots);
        $this->assertSame(99, $snapshots[0]['chapter_id']);
        $this->assertSame('no_content', $snapshots[0]['reason']);
        $this->assertStringContainsString('Not Found', \App\Scraping\FailureSnapshot::read(7, $snapshots[0]['file']));
    }

    /** The same hole when FlareSolverr reports the target's 404. */
    public function testHttp404ChapterIsNotFound(): void
    {
        $url = self::NOVEL . '/500';
        $this->fake([$url => FakeFetcher::fail('http_404', 404)]);

        $reason = null;
        $this->assertSame([], chapterGenerator($this->chapter($this->novel(self::NOVEL, 'Taming The Villainesses'), $url, 500, 'Chapter 500'), $reason));
        $this->assertSame('not_found', $reason);
        $this->assertSame([], \App\Scraping\FailureSnapshot::files(7), 'nothing fetched, nothing to snapshot');
    }

    public function testMetadataFromTheNovelPage(): void
    {
        $this->fake([self::NOVEL => 'empirenovel/toc-taming-the-villainesses-page-1.html']);

        $meta = getMetadataFromEmpireNovel(self::NOVEL);
        $expected = $this->golden('empirenovel/novel-page-taming-the-villainesses.expected.json', $meta);

        $this->assertSame($expected, $meta);
        $this->assertSame(843, $meta['no_of_chapters']);
        $this->assertStringContainsString('cover_250x350.jpg', $meta['image']);
        $this->assertStringContainsString('Taming The Villainesses', $meta['description']);
    }

    /** Shadow Slave page 1: 107 list pages, newest first, plus the "First Chapter" link. */
    public function testShadowSlaveFirstPageParses(): void
    {
        $base = 'https://www.empirenovel.com/novel/shadow-slave';
        $fetcher = $this->fake(
            [$base . '?page=1' => 'empirenovel/toc-shadow-slave-page-1.html'],
            [$base . '?page=' => 'empirenovel/toc-page-not-captured.html']
        );

        $toc = empireNovelToc($base);

        $this->assertCount(106, $fetcher->urls('plain'), 'pages 2..107');
        $numbers = array_map(fn($r) => (int) $r['chapter'], $toc);
        $this->assertSame(1, $numbers[0]);
        $this->assertSame(3203, end($numbers));
        $this->assertCount(31, $toc);
    }
}
