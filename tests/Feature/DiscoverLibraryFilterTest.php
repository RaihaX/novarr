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

    public function testNewListAsksTheSourceForItsNewestNovels(): void
    {
        $this->getJson('/novels/discover/browse?type=new&source=novelarrow')
            ->assertOk()
            ->assertJsonPath('hidden', 2)
            ->assertJsonCount(1, 'items');

        $this->assertSame(
            ['https://novelping.com/api-web/novels?status=all&sort=NEW&limit=40&page=1&genre=ALL'],
            $this->fetcher->urls()
        );
    }

    public function testRandomListDrawsAPageWithinTheCatalogueAndIsNeverCached(): void
    {
        // The catalogue's page count is learned from a list answer.
        $this->fetcher = new FakeFetcher([], ['https://novelping.com/api-web/novels?' => json_encode([
            'items' => [['novel_id' => 'free-novel', 'novel_name' => 'A Free Novel']],
            'pagination' => ['page' => 1, 'limit' => 40, 'total' => 280, 'totalPages' => 7],
        ])]);
        app()->instance(Fetcher::class, $this->fetcher);
        $this->getJson('/novels/discover/browse?type=popular&source=novelarrow')->assertOk();
        $this->assertSame(7, Cache::get(\App\Sources\NovelArrowSource::DISCOVER_PAGES_KEY));

        $before = count($this->fetcher->urls());
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/novels/discover/browse?type=random&source=novelarrow')
                ->assertOk()
                ->assertJsonCount(1, 'items');
        }

        // One request per click (not cached), each for a page in 1..7.
        $random = array_slice($this->fetcher->urls(), $before);
        $this->assertCount(5, $random);
        foreach ($random as $url) {
            $this->assertMatchesRegularExpression('~status=all&sort=POPULAR&limit=40&page=[1-7]&genre=ALL$~', $url);
        }
    }

    public function testRandomFallsBackToTheFirstPageWhenTheDrawnPageIsEmpty(): void
    {
        Cache::put(\App\Sources\NovelArrowSource::DISCOVER_PAGES_KEY, 900);
        // Longest match first: page 1 has a novel, every other page is empty.
        $this->fetcher = new FakeFetcher([], [
            'https://novelping.com/api-web/novels?status=all&sort=POPULAR&limit=40&page=1&genre=ALL'
                => json_encode(['items' => [['novel_id' => 'free-novel', 'novel_name' => 'A Free Novel']]]),
            'https://novelping.com/api-web/novels?' => json_encode(['items' => []]),
        ]);
        app()->instance(Fetcher::class, $this->fetcher);

        $this->getJson('/novels/discover/browse?type=random&source=novelarrow')
            ->assertOk()
            ->assertJsonCount(1, 'items');
    }

    public function testSearchOnlySourcesAnswerEmptyForTheNewLists(): void
    {
        foreach (['new', 'random'] as $type) {
            $this->getJson("/novels/discover/browse?type={$type}&source=novelfull")
                ->assertOk()
                ->assertJsonCount(0, 'items');
        }
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
