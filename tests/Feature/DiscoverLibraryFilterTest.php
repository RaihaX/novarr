<?php

namespace Tests\Feature;

use App\Novel;
use App\Scraping\Fetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Discover browse lists (Popular / Completed) leave out novels already in
 * the library and report how many were hidden; typed searches keep every
 * result and only mark library ones.
 */
class DiscoverLibraryFilterTest extends TestCase
{
    use RefreshDatabase;

    private FakeFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $list = json_encode(['items' => [
            ['novel_id' => 'shadow-slave', 'novel_name' => 'Shadow Slave', 'novel_author' => 'Guiltythree', 'novel_status' => 0, 'totalChapter' => 3207],
            ['novel_id' => 'lord-of-the-mysteries', 'novel_name' => 'Lord of the Mysteries', 'novel_author' => 'Cuttlefish', 'novel_status' => 1],
            ['novel_id' => 'free-novel', 'novel_name' => 'A Free Novel', 'novel_author' => 'Someone'],
        ]]);
        $this->fetcher = new FakeFetcher([], ['https://novelping.com/api-web/novels?' => $list]);
        app()->instance(Fetcher::class, $this->fetcher);

        // In the library: one on the OLD host with a trailing underscore
        // (matched by slug), one only by a differently punctuated name.
        Novel::create(['name' => 'Shadow Slave (WN)', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);
        Novel::create(['name' => 'Lord Of The Mysteries!', 'group_id' => 1, 'translator_url' => 'https://example.test/lotm']);
    }

    public function testBrowseListHidesLibraryNovels(): void
    {
        $response = $this->getJson('/novels/discover/browse?type=popular&source=novelarrow')->assertOk();

        $this->assertSame(['A Free Novel'], array_column($response->json('items'), 'name'));
        $this->assertSame(2, $response->json('hidden'));
        $this->assertFalse($response->json('items.0.in_library'));
        $this->assertSame('https://novelping.com/novel/free-novel', $response->json('items.0.url'));
        $this->assertSame('https://images.novelping.com/novel/free-novel.jpg', $response->json('items.0.cover'));
    }

    public function testCompletedListIsFilteredToo(): void
    {
        $this->getJson('/novels/discover/browse?type=completed&source=novelarrow')
            ->assertOk()
            ->assertJsonPath('hidden', 2)
            ->assertJsonCount(1, 'items');
    }

    public function testSearchKeepsEveryResultButMarksLibraryOnes(): void
    {
        $response = $this->getJson('/novels/discover/browse?type=search&q=slave&source=novelarrow')->assertOk();

        $items = collect($response->json('items'))->keyBy('name');
        $this->assertCount(3, $items);
        $this->assertSame(0, $response->json('hidden'));
        $this->assertTrue($items['Shadow Slave']['in_library']);
        $this->assertTrue($items['Lord of the Mysteries']['in_library']);
        $this->assertFalse($items['A Free Novel']['in_library']);
    }

    public function testListCacheIsFilteredAfterTheRead(): void
    {
        $this->getJson('/novels/discover/browse?type=popular&source=novelarrow')->assertJsonPath('hidden', 2);

        // The library changes; the cached list does not — no second fetch,
        // but the newly added novel is hidden now.
        Novel::create(['name' => 'Totally Different', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/free-novel/']);
        $this->getJson('/novels/discover/browse?type=popular&source=novelarrow')
            ->assertJsonPath('hidden', 3)
            ->assertJsonCount(0, 'items');

        $this->assertCount(1, $this->fetcher->urls('json'));
    }

    public function testPageCarriesTheHiddenNoteAndEmptyState(): void
    {
        $this->get('/novels/discover')
            ->assertOk()
            ->assertSee('id="discoverHidden"', false)
            ->assertSee('already in your library hidden', false)
            ->assertSee('Everything here is already in your library', false)
            ->assertSee('NovelPing (novelarrow)', false);
    }
}
