<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Base for the golden source tests: the real adapters/parsers run against
 * pages saved from the live sites (tests/fixtures/{source}/), served by a
 * FakeFetcher that throws on any URL it was not given — so these tests can
 * never touch the network.
 *
 * Expected output lives next to the fixtures as *.expected.json. Run with
 * GOLDEN_UPDATE=1 to rewrite those files from the current output (then
 * review the diff — a golden file is only as good as that review).
 */
abstract class GoldenTestCase extends TestCase
{
    protected ?FakeFetcher $fetcher = null;
    protected string $snapshotDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotDir = sys_get_temp_dir() . '/novarr-golden-' . getmypid() . '-' . bin2hex(random_bytes(4));
        config([
            'novarr.chapter_fetch_delay_ms' => [0, 0],
            'novarr.snapshots.path' => $this->snapshotDir,
            'novarr.snapshots.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $unexpected = $this->fetcher?->unexpected ?? [];
        $this->fetcher = null;

        if (is_dir($this->snapshotDir)) {
            exec('rm -rf ' . escapeshellarg($this->snapshotDir));
        }

        $this->assertSame([], $unexpected, 'A golden test attempted an un-faked fetch');

        parent::tearDown();
    }

    protected function fake(array $routes, array $prefixes = []): FakeFetcher
    {
        $this->fetcher = new FakeFetcher($routes, $prefixes);
        app()->instance(Fetcher::class, $this->fetcher);

        return $this->fetcher;
    }

    protected function novel(string $url, string $name, int $id = 7): Novel
    {
        $novel = new Novel();
        $novel->forceFill(['id' => $id, 'name' => $name, 'translator_url' => $url, 'group_id' => 1]);

        return $novel;
    }

    protected function chapter(Novel $novel, string $url, $number, string $label, int $id = 11): NovelChapter
    {
        $chapter = new NovelChapter();
        $chapter->forceFill(['id' => $id, 'url' => $url, 'chapter' => $number, 'label' => $label, 'novel_id' => $novel->id]);
        $chapter->setRelation('novel', $novel);

        return $chapter;
    }

    protected static function plain(string $paragraph): string
    {
        return html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ---- golden files ----------------------------------------------------

    /**
     * Compare $actual with tests/fixtures/$path (or write it under
     * GOLDEN_UPDATE=1). Returns the expected data.
     */
    protected function golden(string $path, array $actual): array
    {
        $file = dirname(__DIR__) . '/fixtures/' . $path;

        if (getenv('GOLDEN_UPDATE')) {
            file_put_contents($file, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            // Comparisons would pass by construction: never report green.
            $this->markTestIncomplete("golden regenerated: {$path}");
        }

        $this->assertFileExists($file, "Golden file missing: {$path} (run with GOLDEN_UPDATE=1)");
        $expected = json_decode(file_get_contents($file), true);
        $this->assertIsArray($expected, "Golden file unreadable: {$path}");

        return $expected;
    }

    /** TOC summary: count, first/last 3 rows, URL sanity. */
    protected function tocSummary(array $toc, string $groupUrl): array
    {
        $row = fn($r) => [
            'label' => $r['label'],
            'url' => $r['url'],
            'chapter' => (string) $r['chapter'],
            'book' => (int) $r['book'],
        ];
        $urls = array_column($toc, 'url');

        return [
            'count' => count($toc),
            'first' => array_map($row, array_slice($toc, 0, 3)),
            'last' => array_map($row, array_slice($toc, -3)),
            'invalid_urls' => count(array_filter($urls, fn($u) => !NovelScraper::validTocUrl($u, $groupUrl))),
            'duplicate_urls' => count($urls) - count(array_unique($urls)),
        ];
    }

    protected function assertTocGolden(string $path, array $toc, string $groupUrl): array
    {
        $actual = $this->tocSummary($toc, $groupUrl);
        $expected = $this->golden($path, $actual);

        $this->assertSame($expected['count'], $actual['count'], 'entry count');
        $this->assertSame($expected['first'], $actual['first'], 'first 3 entries');
        $this->assertSame($expected['last'], $actual['last'], 'last 3 entries');
        $this->assertSame(0, $actual['invalid_urls'], 'invalid URLs');
        $this->assertSame($expected['duplicate_urls'], $actual['duplicate_urls'], 'duplicate URLs');

        return $actual;
    }

    /** Content summary: paragraph count, word count, first/last paragraph. */
    protected function contentSummary(array $paragraphs): array
    {
        $words = chapterParagraphWords($paragraphs);

        return [
            'paragraphs' => count($paragraphs),
            'words' => ['min' => (int) floor($words * 0.97), 'max' => (int) ceil($words * 1.03)],
            'first' => $paragraphs ? self::plain($paragraphs[0]) : null,
            'last' => $paragraphs ? self::plain(end($paragraphs)) : null,
        ];
    }

    protected function assertContentGolden(string $path, array $paragraphs, ?string $label = null): void
    {
        $actual = $this->contentSummary($paragraphs);
        $expected = $this->golden($path, $actual);

        $this->assertSame($expected['paragraphs'], $actual['paragraphs'], 'paragraph count');
        $words = chapterParagraphWords($paragraphs);
        $this->assertGreaterThanOrEqual($expected['words']['min'], $words, 'word count (min)');
        $this->assertLessThanOrEqual($expected['words']['max'], $words, 'word count (max)');
        $this->assertSame($expected['first'], $actual['first'], 'first paragraph');
        $this->assertSame($expected['last'], $actual['last'], 'last paragraph');

        $this->assertCleanChapter($paragraphs, $label);
    }

    /**
     * No title duplication, no reader chrome, no watermark lines, every
     * paragraph a well-formed "<p>…</p>".
     */
    protected function assertCleanChapter(array $paragraphs, ?string $label = null): void
    {
        $this->assertNotEmpty($paragraphs);

        foreach ($paragraphs as $i => $p) {
            $this->assertMatchesRegularExpression('#^<p>.*</p>$#s', $p, "paragraph {$i} shape");
            $text = trim(self::plain($p));
            $this->assertNotSame('', $text, "paragraph {$i} empty");

            foreach (['report chapter', 'If you find any errors', 'Prev Chapter', 'Next Chapter', 'Quality checked by', 'Use arrow keys', 'Advertisement'] as $chrome) {
                $this->assertStringNotContainsStringIgnoringCase($chrome, $text, "paragraph {$i} has reader chrome");
            }
            $this->assertDoesNotMatchRegularExpression('/^\.(me|com|net|org)\b/i', $text, "paragraph {$i} is a watermark tail");
            $this->assertDoesNotMatchRegularExpression('/\b(novelfull|novelbin|novelarrow|novelping|empirenovel)\s*\.\s*(com|me|net)\b/i', $text, "paragraph {$i} has a site watermark");
            $this->assertNull(
                isChapterWatermark($text) ? $text : null,
                "paragraph {$i} is a watermark line"
            );
        }

        if ($label !== null) {
            // The chapter title must not open the body (it is the EPUB heading).
            $first = trim(self::plain($paragraphs[0]));
            $this->assertStringStartsNotWith(strtolower($label), strtolower($first), 'title duplicated as first paragraph');
            $this->assertNotSame(strtolower($label), strtolower($first));
        }
    }
}
