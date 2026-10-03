<?php

namespace Tests\Unit;

use App\Novel;
use App\Scraping\Fetcher;
use App\Sources\NovelArrowSource;
use App\Sources\NovelFullSource;
use App\Sources\SourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * novelarrow.com → novelping.com rebrand: both hosts are recognised, every
 * outgoing URL is built on the configured canonical host, and slugs are
 * trimmed of the trailing "_"/"-" that makes the API answer with nothing.
 */
class NovelPingHostTest extends TestCase
{
    public static function hosts(): array
    {
        return [
            'novelping'          => ['https://novelping.com/novel/shadow-slave', true, true],
            'www novelping'      => ['https://www.novelping.com/novel/shadow-slave', true, true],
            'novelarrow'         => ['https://novelarrow.com/novel/shadow-slave', true, true],
            'www novelarrow http'=> ['http://www.novelarrow.com/chapter/shadow-slave/chapter-1', true, true],
            'upper case'         => ['HTTPS://NovelPing.COM/novel/x', true, true],
            'legacy novelbin'    => ['https://novelbin.me/novel-book/shadow-slave', false, true],
            'novelfull'          => ['https://novelfull.com/shadow-slave.html', false, false],
            'lookalike'          => ['https://novelarrow.com.evil.test/novel/x', false, false],
            'sub of other'       => ['https://notnovelping.com/novel/x', false, false],
            'empty'              => ['', false, false],
        ];
    }

    #[DataProvider('hosts')]
    public function testHostMatching(string $url, bool $match, bool $matchLegacy): void
    {
        $this->assertSame($match, isNovelArrowUrl($url));
        $this->assertSame($matchLegacy, isNovelArrowUrl($url, legacy: true));
    }

    public function testCanonicalUrlsUseTheConfiguredHost(): void
    {
        $this->assertSame('novelping.com', novelArrowHost());
        $this->assertSame('https://novelping.com', novelArrowBase());
        $this->assertSame('https://novelping.com/chapter/shadow-slave/chapter-1', novelArrowChapterUrl('shadow-slave', 'chapter-1'));
        $this->assertSame('https://novelping.com/novel/shadow-slave', novelArrowNovelUrl('shadow-slave'));
        $this->assertSame('https://images.novelping.com/novel/shadow-slave.jpg', novelArrowCoverUrl('shadow-slave'));

        // A pasted origin is tolerated; another host is honoured everywhere.
        config(['novarr.novelarrow_host' => 'https://www.Example-Mirror.test/']);
        $this->assertSame('example-mirror.test', novelArrowHost());
        $this->assertSame('https://example-mirror.test/chapter/a/b', novelArrowChapterUrl('a', 'b'));
        $this->assertTrue(isNovelArrowUrl('https://example-mirror.test/novel/a'));
        $this->assertTrue(isNovelArrowUrl('https://novelarrow.com/novel/a'), 'old brands stay recognised');

        config(['novarr.novelarrow_host' => '']);
        $this->assertSame('novelping.com', novelArrowHost());
    }

    public static function slugs(): array
    {
        return [
            'novel page'          => ['https://novelping.com/novel/shadow-slave', 'shadow-slave'],
            'trailing underscore' => ['https://novelarrow.com/novel/i-can-see-your-combat-power_', 'i-can-see-your-combat-power'],
            'trailing dash+slash' => ['https://novelarrow.com/novel/some-novel-/', 'some-novel'],
            'mixed tail + spaces' => ["  https://novelping.com/novel/some-novel_-_  ", 'some-novel'],
            'chapter page'        => ['https://novelping.com/chapter/some-novel_/chapter-3', 'some-novel'],
            'legacy query'        => ['https://novelbin.me/ajax/chapter-archive?novelId=some-novel_', 'some-novel'],
            'inner underscore'    => ['https://novelping.com/novel/a_b', 'a_b'],
        ];
    }

    #[DataProvider('slugs')]
    public function testSlugTrimming(string $url, string $slug): void
    {
        $this->assertSame($slug, novelArrowSlug($url));
    }

    public function testTrailingUnderscoreSlugHitsTheCleanApiPath(): void
    {
        $api = 'https://novelping.com/api-web/novels/i-can-see-your-combat-power/chapters?sort=asc';
        $fake = new FakeFetcher([$api => json_encode(['items' => [
            ['chapter_id' => 'chapter-1-start', 'chapter_name' => 'Chapter 1 Start'],
        ]])]);
        app()->instance(Fetcher::class, $fake);

        $toc = novelArrowChapterArchive('https://novelarrow.com/novel/i-can-see-your-combat-power_');

        $this->assertSame([$api], $fake->urls('json'));
        $this->assertSame('https://novelping.com/chapter/i-can-see-your-combat-power/chapter-1-start', $toc[0]['url']);
    }

    public function testResolverKeepsNovelArrowAsTheDefault(): void
    {
        $novel = fn(string $url) => (new Novel())->forceFill(['translator_url' => $url, 'group_id' => 1]);

        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::for($novel('https://novelping.com/novel/x')));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::for($novel('https://novelarrow.com/novel/x')));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::for($novel('https://unknown.example/novel/x')));
        $this->assertInstanceOf(NovelFullSource::class, SourceResolver::for($novel('https://novelfull.com/x.html')));
    }

    public function testMetadataBuildsCanonicalCoverAndTriedUrls(): void
    {
        $api = 'https://novelping.com/api-web/novels/shadow-slave';
        app()->instance(Fetcher::class, new FakeFetcher([$api => json_encode(['item' => ['novelInfo' => [
            'novel_desc' => 'Desc', 'novel_author' => 'Guiltythree', 'totalChapter' => 3206,
        ]]])]));

        $novel = (new Novel())->forceFill(['name' => 'Shadow Slave', 'translator_url' => 'https://www.novelarrow.com/novel/shadow-slave_']);
        $meta = getMetadataFromNovelArrow($novel);

        $this->assertSame('https://images.novelping.com/novel/shadow-slave.jpg', $meta['image']);
        $this->assertSame(['https://novelping.com/novel/shadow-slave'], $meta['tried_urls']);
    }
}
