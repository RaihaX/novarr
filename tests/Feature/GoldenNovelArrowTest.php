<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Sources\NovelArrowSource;
use App\Sources\SourceResolver;
use Tests\Support\FakeFetcher;

/**
 * Novel Arrow (now NovelPing) through the real adapter: TOC and chapter text
 * both come from the site's JSON API (responses saved 2026-10-03, Shadow
 * Slave). Stored novelarrow.com URLs still resolve, but every request and
 * every built URL is on the canonical novelping.com host. See
 * tests/fixtures/novelarrow/README.md.
 */
class GoldenNovelArrowTest extends GoldenTestCase
{
    private const NOVEL = 'https://novelping.com/novel/shadow-slave';
    private const LEGACY_NOVEL = 'https://novelarrow.com/novel/shadow-slave';
    private const API = 'https://novelping.com/api-web/novels/shadow-slave/chapters';

    public function testTableOfContentsFromTheApi(): void
    {
        $fetcher = $this->fake([self::API . '?sort=asc' => 'novelarrow/api-chapters-shadow-slave.json']);
        $novel = $this->novel(self::NOVEL, 'Shadow Slave');

        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::for($novel));
        $toc = tableOfContentGenerator($novel);

        $summary = $this->assertTocGolden('novelarrow/toc-shadow-slave.expected.json', $toc, 'https://novelping.com');
        $this->assertSame([self::API . '?sort=asc'], $fetcher->urls('json'));

        // The API really does list 2232–2234 twice; the adapter passes them
        // through (de-duplication is the sync's job).
        $this->assertSame(3, $summary['duplicate_urls']);

        foreach ($toc as $row) {
            $this->assertMatchesRegularExpression('#^https://novelping\.com/chapter/shadow-slave/chapter-[a-z0-9-]+$#', $row['url']);
            if ($row['label'] === 'Chapter Entertaining Guest') {
                // Number-less label (really 3189): 0 or right, never wrong.
                $this->assertContains((int) $row['chapter'], [0, 3189]);
            } else {
                $this->assertGreaterThan(0, (int) $row['chapter'], $row['label']);
            }
        }
    }

    /** A novel still stored on novelarrow.com (pre-migration) hits the novelping API and gets novelping URLs. */
    public function testLegacyNovelArrowUrlUsesTheCanonicalHost(): void
    {
        $fetcher = $this->fake([self::API . '?sort=asc' => 'novelarrow/api-chapters-shadow-slave.json']);
        $novel = $this->novel(self::LEGACY_NOVEL, 'Shadow Slave');

        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::for($novel));
        $toc = tableOfContentGenerator($novel);

        $this->assertSame([self::API . '?sort=asc'], $fetcher->urls('json'));
        $this->assertTocGolden('novelarrow/toc-shadow-slave.expected.json', $toc, 'https://novelping.com');
    }

    /** A chapter row still on novelarrow.com fetches its text from the novelping API. */
    public function testLegacyChapterUrlFetchesFromTheCanonicalHost(): void
    {
        $id = 'chapter-1-nightmare-begins';
        $fetcher = $this->fake([self::API . "/{$id}" => "novelarrow/api-{$id}.json"]);
        $novel = $this->novel(self::LEGACY_NOVEL, 'Shadow Slave');

        $paragraphs = chapterGenerator($this->chapter($novel, "https://www.novelarrow.com/chapter/shadow-slave/{$id}", 1, 'Chapter 1 Nightmare Begins'));

        $this->assertSame([self::API . "/{$id}"], $fetcher->urls());
        $this->assertContentGolden("novelarrow/api-{$id}.expected.json", $paragraphs, 'Chapter 1 Nightmare Begins');
    }

    /** API down → falls back to the page parse; an empty page is snapshotted. */
    public function testApiFailureFallsBackToThePageParse(): void
    {
        $fetcher = $this->fake([
            self::API . '?sort=asc' => FakeFetcher::fail('http_5xx', 503),
            self::NOVEL => '<html><body><div class="maintenance">Down for maintenance</div></body></html>',
        ]);
        $novel = $this->novel(self::NOVEL, 'Shadow Slave');

        $this->assertSame([], tableOfContentGenerator($novel));
        $this->assertSame([self::NOVEL], $fetcher->urls('html'));
        $this->assertSame('empty_toc', \App\Scraping\FailureSnapshot::files(7)[0]['reason'] ?? null);
    }

    #[DataProvider('chapters')]
    public function testChapterContentFromTheApi(string $id, int $number, string $label): void
    {
        $fetcher = $this->fake([self::API . "/{$id}" => "novelarrow/api-{$id}.json"]);
        $novel = $this->novel(self::NOVEL, 'Shadow Slave');

        $reason = 'unset';
        $paragraphs = chapterGenerator(
            $this->chapter($novel, "https://novelping.com/chapter/shadow-slave/{$id}", $number, $label),
            $reason
        );

        $this->assertNull($reason);
        $this->assertSame([self::API . "/{$id}"], $fetcher->urls(), 'API only — no page fetch');
        $this->assertContentGolden("novelarrow/api-{$id}.expected.json", $paragraphs, $label);

        // The <h4> title is its own node now, so it can't be glued to the
        // first line ("Chapter 1 Nightmare Begins  A frail-looking…").
        $this->assertStringNotContainsString('Nightmare Begins', self::plain($paragraphs[0]));
    }

    public static function chapters(): array
    {
        return [
            'chapter 1' => ['chapter-1-nightmare-begins', 1, 'Chapter 1 Nightmare Begins'],
            'chapter 1600' => ['chapter-1600-beast-farm', 1600, 'Chapter 1600 Beast Farm'],
            'chapter 3203' => ['chapter-3203-ultima-ratio-regum', 3203, 'Chapter 3203 Ultima Ratio Regum'],
        ];
    }

    /** The same chapter on novelfull and Novel Arrow extracts to the same text. */
    public function testApiAndHtmlPathsAgree(): void
    {
        $this->fake([
            self::API . '/chapter-1600-beast-farm' => 'novelarrow/api-chapter-1600-beast-farm.json',
            'https://novelfull.com/shadow-slave/chapter-1600-beast-farm.html' => 'novelfull/chapter-1600-beast-farm.html',
        ]);
        $na = chapterGenerator($this->chapter($this->novel(self::NOVEL, 'Shadow Slave'), 'https://novelping.com/chapter/shadow-slave/chapter-1600-beast-farm', 1600, 'Chapter 1600 Beast Farm'));
        $nf = chapterGenerator($this->chapter($this->novel('https://novelfull.com/shadow-slave.html', 'Shadow Slave'), 'https://novelfull.com/shadow-slave/chapter-1600-beast-farm.html', 1600, 'Chapter 1600 Beast Farm'));

        $this->assertEqualsWithDelta(chapterParagraphWords($na), chapterParagraphWords($nf), 10);
        $this->assertSame(self::plain($na[0]), self::plain($nf[0]));
    }
}
