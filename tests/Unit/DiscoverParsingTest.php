<?php

namespace Tests\Unit;

use App\Http\Controllers\DiscoverController;
use App\Scraping\Fetcher;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Discover / metadata gaps: Empire Novel search synopsis + status, Novel
 * Arrow's novel_status, novelfull's "Status:" field.
 */
class DiscoverParsingTest extends TestCase
{
    private function fixture(string $path): string
    {
        return file_get_contents(dirname(__DIR__) . '/fixtures/' . $path);
    }

    public function testEmpireNovelSearchCarriesSynopsisAndStatus(): void
    {
        $items = (new DiscoverController())->parseEmpireNovelSearch($this->fixture('empirenovel/search-live-shadow.html'));

        $this->assertCount(3, $items, 'the row without a slug is dropped');

        $this->assertSame('Shadow Slave', $items[0]['name']);
        $this->assertSame('https://www.empirenovel.com/novel/shadow-slave', $items[0]['url']);
        $this->assertSame(
            'Growing up in poverty, Sunny never expected anything good from life. However, even he did not anticipate being chosen by the Nightmare Spell.',
            $items[0]['description']
        );
        $this->assertSame('Ongoing', $items[0]['status']);

        // summary delivered as a JSON-encoded string; entities decoded.
        $this->assertSame('A hunter & his shadow.', $items[1]['description']);
        $this->assertSame('Completed', $items[1]['status']);

        // No summary / status: blank synopsis, no status key.
        $this->assertSame('', $items[2]['description']);
        $this->assertArrayNotHasKey('status', $items[2]);
    }

    public function testEmpireNovelSearchToleratesJunk(): void
    {
        $controller = new DiscoverController();
        $this->assertSame([], $controller->parseEmpireNovelSearch('<html>Just a moment...</html>'));
        $this->assertSame([], $controller->parseEmpireNovelSearch('<pre>[not json]</pre>'));
    }

    public function testNovelArrowStatusMapping(): void
    {
        $this->assertSame('Ongoing', novelArrowStatus(0));
        $this->assertSame('Completed', novelArrowStatus(1));
        $this->assertSame('Completed', novelArrowStatus('1'));
        $this->assertSame('Completed', novelArrowStatus('COMPLETED'));
        $this->assertSame('Ongoing', novelArrowStatus('Ongoing'));
        $this->assertNull(novelArrowStatus(null));
        $this->assertNull(novelArrowStatus(7));
        $this->assertNull(novelArrowStatus('hiatus?'));
    }

    public function testNovelArrowMetadataMapsNovelStatus(): void
    {
        $api = 'https://novelarrow.com/api-web/novels/shadow-slave';
        $body = json_encode(['item' => ['novelInfo' => [
            'novel_status' => 1, 'novel_desc' => 'Desc', 'novel_author' => 'Guiltythree', 'totalChapter' => 3206,
        ]]]);
        app()->instance(Fetcher::class, new FakeFetcher([$api => $body]));

        $novel = new \App\Novel();
        $novel->forceFill(['name' => 'Shadow Slave', 'translator_url' => 'https://novelarrow.com/novel/shadow-slave']);

        $meta = getMetadataFromNovelArrow($novel);
        $this->assertSame('Completed', $meta['status_text']);
        $this->assertTrue($meta['completed']);
        $this->assertSame(3206, $meta['no_of_chapters']);
    }

    public function testNovelFullMetadataReadsStatusAndChapterCount(): void
    {
        $url = 'https://novelfull.com/shadow-slave.html';
        app()->instance(Fetcher::class, new FakeFetcher([$url => 'novelfull/novel-page-shadow-slave.html']));

        $meta = getMetadataFromNovelFull($url);
        $this->assertSame('Ongoing', $meta['status_text']);
        $this->assertFalse($meta['completed']);
        $this->assertSame(3203, $meta['no_of_chapters']);
    }
}
