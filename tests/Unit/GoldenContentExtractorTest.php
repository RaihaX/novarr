<?php

namespace Tests\Unit;

use App\Novel;
use App\Scraping\ContentExtractor;
use App\Scraping\Fetcher;
use App\Sources\AbstractSource;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Support\FakeFetcher;
use Tests\Support\UnexpectedFetch;
use Tests\TestCase;

/**
 * ContentExtractor v2: source selectors, the readability-style scorer
 * fallback and multi-page stitching (through FakeFetcher, no network).
 */
class GoldenContentExtractorTest extends TestCase
{
    private function sentence(int $i): string
    {
        return "Sentence {$i} of the story carries the plot forward while the wind howls across the silent valley below.";
    }

    /** A paragraph of roughly $words words. */
    private function paragraph(int $seed, int $words = 125): string
    {
        $out = [];
        for ($i = 0; count(explode(' ', implode(' ', $out))) < $words; $i++) {
            $out[] = $this->sentence($seed * 100 + $i);
        }
        return implode(' ', $out);
    }

    private function body(int $paragraphs = 8, int $words = 125): string
    {
        $html = '';
        for ($i = 1; $i <= $paragraphs; $i++) {
            $html .= '<p>' . $this->paragraph($i, $words) . '</p>';
        }
        return $html;
    }

    private function page(string $inner): string
    {
        return "<html><head><title>x</title></head><body>{$inner}</body></html>";
    }

    // ---- scorer ------------------------------------------------------------

    public function testCommentsWithManyShortParagraphsLoseToTheStoryBody(): void
    {
        $comments = '';
        for ($i = 1; $i <= 15; $i++) {
            $comments .= "<p>Comment {$i}: thanks for the chapter, this arc is getting really good, can't wait for more!</p>";
        }
        $html = $this->page(
            '<div class="wrap">'
            . '<div class="post-body">' . $this->body(8, 140) . '</div>'
            . '<div class="comments">' . $comments . '</div>'
            . '</div>'
        );

        $paragraphs = (new ContentExtractor())->extract($html);

        $this->assertCount(8, $paragraphs);
        $words = chapterParagraphWords($paragraphs);
        $this->assertGreaterThanOrEqual(1000, $words);
        $this->assertStringNotContainsString('Comment', implode('', $paragraphs));
    }

    private function longComments(int $n = 15): string
    {
        $out = '';
        for ($i = 1; $i <= $n; $i++) {
            $out .= '<p>' . str_repeat("Reader {$i} wrote: this chapter was really great and I cannot wait for the next one to come out soon. ", 3) . '</p>';
        }
        return $out;
    }

    /**
     * A locked/teaser stub in the known container is returned as-is (the
     * word gate then rejects and retries it) — the comments are never
     * substituted, whether they are labelled or not.
     */
    public function testStubInKnownContainerIsNeverReplacedByComments(): void
    {
        $stub = '<p>' . trim(str_repeat('This chapter is locked until the next release, please come back later to read it. ', 3)) . '</p>';
        $this->assertGreaterThanOrEqual(40, chapterParagraphWords([$stub]));
        $extractor = new ContentExtractor();

        foreach (['<div id="comments">', '<div class="wpd-thread-list">', '<div class="user-posts">'] as $open) {
            $html = $this->page('<div id="chapter-content">' . $stub . '</div>' . $open . $this->longComments() . '</div>');

            foreach ([null, new \App\Sources\NovelFullSource()] as $source) {
                $paragraphs = $extractor->extract($html, $source);
                $text = implode('', $paragraphs);
                $this->assertStringNotContainsString('Reader', $text, "comments substituted ({$open})");
                $this->assertLessThan(60, chapterParagraphWords($paragraphs));
                $this->assertContains($paragraphs, [[], [$stub]]);
            }
        }
    }

    /** No known container: the unlabeled body div beats a long #comments block. */
    public function testUnlabeledBodyBeatsCommentsWithoutAKnownContainer(): void
    {
        $html = $this->page('<div class="x1"><div>' . $this->body(8, 140) . '</div><div id="comments">' . $this->longComments() . '</div></div>');

        $paragraphs = (new ContentExtractor())->extract($html);

        $this->assertCount(8, $paragraphs);
        $this->assertStringNotContainsString('Reader', implode('', $paragraphs));

        // Comments alone never become the chapter, bonus or not.
        $this->assertSame([], (new ContentExtractor())->extract($this->page('<div id="comments">' . $this->longComments() . '</div>')));
        $this->assertSame([], (new ContentExtractor())->extract($this->page('<section class="disqus-thread"><div>' . $this->longComments() . '</div></section>')));
    }

    public function testScoreOrdersBodyAboveComments(): void
    {
        $comments = str_repeat('<p>Great chapter, thanks for the translation, really enjoyed it a lot today friends!</p>', 15);
        $doc = (new Crawler($this->page('<div id="body">' . $this->body(8) . '</div><div class="comment-list">' . $comments . '</div>')))->getNode(0)->ownerDocument;
        $xpath = new \DOMXPath($doc);
        $extractor = new ContentExtractor();

        $body = $xpath->query('//div[@id="body"]')->item(0);
        $list = $xpath->query('//div[@class="comment-list"]')->item(0);
        $this->assertGreaterThan($extractor->score($list), $extractor->score($body));
        $this->assertSame($body, $extractor->bestScoredNode($xpath));
    }

    public function testNavListOfLinksNeverWins(): void
    {
        $links = '';
        for ($i = 1; $i <= 40; $i++) {
            $links .= "<li><a href=\"/novel/chapter-{$i}.html\">Chapter {$i}: The Long and Winding Road Back Home Again</a></li>";
        }
        $nav = '<div class="chapter-list"><ul>' . $links . '</ul></div>';
        $extractor = new ContentExtractor();

        // Next to a real body: the body wins.
        $paragraphs = $extractor->extract($this->page($nav . '<div class="story">' . $this->body(6) . '</div>'));
        $this->assertCount(6, $paragraphs);
        $this->assertStringNotContainsString('Winding Road', implode('', $paragraphs));

        // Alone (or next to a thin body): nothing, never the link list.
        $this->assertSame([], $extractor->extract($this->page($nav)));
        $thin = $extractor->extract($this->page($nav . '<div class="story"><p>Just a short note.</p></div>'));
        $this->assertStringNotContainsString('Winding Road', implode('', $thin));

        $doc = (new Crawler($this->page($nav)))->getNode(0)->ownerDocument;
        $this->assertSame(0.0, $extractor->score((new \DOMXPath($doc))->query('//div')->item(0)));
    }

    public function testBrSeparatedBodyIsFoundByTheScorerWithEmphasis(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = $this->sentence($i) . ($i === 3 ? ' She said it <em>very</em> quietly.' : '');
        }
        $html = $this->page('<div class="sidebar"><a href="/">Home</a></div><div class="reader">' . implode('<br><br>', $lines) . '</div>');

        $paragraphs = (new ContentExtractor())->extract($html);

        $this->assertCount(12, $paragraphs);
        $this->assertStringContainsString('<em>very</em>', $paragraphs[2]);
        $this->assertStringNotContainsString('Home', implode('', $paragraphs));
    }

    public function testSourceSelectorsAndRemoveSelectorsApply(): void
    {
        $source = new class extends AbstractSource {
            public function name(): string { return 'test'; }
            public function matches(Novel $novel): bool { return true; }
            public function tableOfContents(Novel $novel): array { return []; }
            public function metadata(Novel $novel): array { return []; }
            public function contentSelectors(): array { return ['.my-body', ...self::GENERIC_CONTENT_SELECTORS]; }
            public function removeSelectors(): array { return [...self::GENERIC_REMOVE_SELECTORS, '.my-body .share-bar']; }
        };

        $html = $this->page(
            '<div id="content"><p>Decoy paragraph in the generic container.</p></div>'
            . '<div class="my-body">' . $this->body(4) . '<p class="share-bar">Share this chapter on social media now please</p></div>'
        );

        $paragraphs = (new ContentExtractor())->extract($html, $source);

        $this->assertCount(4, $paragraphs);
        $this->assertStringNotContainsString('Share this chapter', implode('', $paragraphs));
        $this->assertStringNotContainsString('Decoy', implode('', $paragraphs));
    }

    public function testAKnownContainerIsNotRemovedByABroadNoiseSelector(): void
    {
        // A widget-looking class on an ancestor of the real container.
        $html = $this->page('<div class="recommend-layout"><div id="chapter-content">' . $this->body(3) . '</div></div>');
        $this->assertCount(3, (new ContentExtractor())->extract($html));
    }

    // ---- multi-page ----------------------------------------------------------

    private function multiPageSource(): AbstractSource
    {
        return new class extends AbstractSource {
            public function name(): string { return 'paged'; }
            public function matches(Novel $novel): bool { return true; }
            public function tableOfContents(Novel $novel): array { return []; }
            public function metadata(Novel $novel): array { return []; }
            public function supportsMultiPage(): ?string { return 'a.next-part'; }
        };
    }

    private function part(string $label, ?string $next): string
    {
        $link = $next ? "<a class=\"next-part\" href=\"{$next}\">Next part</a>" : '';
        return $this->page("<div id=\"chapter-content\"><p>{$label} opens here and the story continues for a while longer.</p><p>{$label} closes here after much drama and many twists.</p></div>{$link}");
    }

    public function testMultiPageChapterIsStitched(): void
    {
        $url = 'https://example.com/novel/chapter-5.html';
        $fake = new FakeFetcher([
            'https://example.com/novel/chapter-5-2.html' => $this->part('Part two', 'chapter-5-3.html'),
            'https://example.com/novel/chapter-5-3.html' => $this->part('Part three', '/novel/chapter-6.html'),
        ]);
        app()->instance(Fetcher::class, $fake);

        $paragraphs = (new ContentExtractor())->extractMultiPage($this->part('Part one', '/novel/chapter-5-2.html'), $this->multiPageSource(), $url, null, ContentExtractor::MAX_PARTS, fn() => false);

        $this->assertCount(6, $paragraphs);
        $this->assertStringStartsWith('<p>Part one', $paragraphs[0]);
        $this->assertStringStartsWith('<p>Part three', $paragraphs[5]);
        // chapter-6 is the next *chapter*, not a part: never fetched.
        $this->assertSame(['https://example.com/novel/chapter-5-2.html', 'https://example.com/novel/chapter-5-3.html'], $fake->urls('html'));
        $this->assertSame([], $fake->unexpected);
    }

    /** A "-2" URL that the TOC lists as its own chapter is the next chapter, not part 2. */
    public function testPartUrlThatIsATocChapterIsNotStitched(): void
    {
        $url = 'https://example.com/novel/chapter-12.html';
        $fake = new FakeFetcher();
        app()->instance(Fetcher::class, $fake);
        $toc = ['https://example.com/novel/chapter-12.html', 'https://example.com/novel/chapter-12-2.html'];

        $paragraphs = (new ContentExtractor())->extractMultiPage(
            $this->part('Chapter twelve', '/novel/chapter-12-2.html'),
            $this->multiPageSource(),
            $url,
            null,
            ContentExtractor::MAX_PARTS,
            fn(string $u) => in_array($u, $toc, true)
        );

        $this->assertCount(2, $paragraphs);
        $this->assertSame([], $fake->calls, 'the next chapter was not fetched as a part');
    }

    public function testQueryStringPagesAndThePartCap(): void
    {
        $url = 'https://example.com/read/chapter-9';
        $routes = [];
        for ($p = 2; $p <= 9; $p++) {
            $routes["{$url}?page={$p}"] = $this->part("Page {$p}", '?page=' . ($p + 1));
        }
        $fake = new FakeFetcher($routes);
        app()->instance(Fetcher::class, $fake);

        $paragraphs = (new ContentExtractor())->extractMultiPage($this->part('Page 1', '?page=2'), $this->multiPageSource(), $url, null, ContentExtractor::MAX_PARTS, fn() => false);

        // At most MAX_PARTS (5) pages in total.
        $this->assertCount(2 * ContentExtractor::MAX_PARTS, $paragraphs);
        $this->assertCount(ContentExtractor::MAX_PARTS - 1, $fake->urls('html'));
    }

    public function testNoMultiPageSupportMeansNoFetch(): void
    {
        $fake = new FakeFetcher();
        app()->instance(Fetcher::class, $fake);

        $paragraphs = (new ContentExtractor())->extractMultiPage(
            $this->part('Only', '/novel/chapter-5-2.html'),
            new \App\Sources\NovelFullSource(),
            'https://novelfull.com/novel/chapter-5.html'
        );

        $this->assertCount(2, $paragraphs);
        $this->assertSame([], $fake->calls);
    }

    public function testPartUrlMatching(): void
    {
        $x = new ContentExtractor();
        $base = 'https://example.com/novel/chapter-5.html';
        $this->assertTrue($x->isPartOf('https://example.com/novel/chapter-5-2.html', $base, 2));
        $this->assertTrue($x->isPartOf('https://example.com/novel/chapter-5.html?page=2', $base, 2));
        $this->assertFalse($x->isPartOf('https://example.com/novel/chapter-5-3.html', $base, 2));
        $this->assertFalse($x->isPartOf('https://example.com/novel/chapter-6.html', $base, 2));
        $this->assertFalse($x->isPartOf('https://evil.example/novel/chapter-5-2.html', $base, 2));
    }

    public function testFakeFetcherRefusesUnroutedUrls(): void
    {
        $fake = new FakeFetcher(['https://a.example/x' => '<p>x</p>']);
        $this->assertSame('<p>x</p>', $fake->html('https://a.example/x'));

        $this->expectException(UnexpectedFetch::class);
        try {
            $fake->html('https://a.example/y');
        } finally {
            $this->assertSame(['https://a.example/y'], $fake->unexpected);
        }
    }
}
