<?php

namespace Tests\Feature;

use App\Novel;
use App\Scraping\Fetcher;
use App\Sources\NovelArrowSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * GET /api/v1/discover and /api/v1/discover/browse, against faked
 * upstream answers (no network).
 */
class ApiV1DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private const NA_LIST = 'https://novelping.com/api-web/novels?';
    private const EN_SEARCH = 'https://www.empirenovel.com/search-live?q=';
    private const NF_SEARCH = 'https://novelfull.com/search?keyword=';

    private const ITEM_KEYS = ['title', 'author', 'cover_url', 'description', 'status', 'chapters', 'url', 'source', 'in_library', 'novel_id'];

    private FakeFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function upstream(array $rows = null, bool $failing = false): void
    {
        $rows ??= [
            ['novel_id' => 'shadow-slave', 'novel_name' => 'Shadow Slave', 'novel_author' => 'Guiltythree', 'novel_desc' => '<p>Growing up in poverty.</p><p>Sunny &amp; co.</p>', 'novel_status' => 0, 'totalChapter' => 3207],
            ['novel_id' => 'lord-of-the-mysteries', 'novel_name' => 'Lord of the Mysteries', 'novel_author' => 'Cuttlefish', 'novel_status' => 1],
            ['novel_id' => 'free-novel', 'novel_name' => 'A Free Novel', 'novel_author' => ''],
        ];

        $this->fetcher = $failing
            ? new FakeFetcher([], [
                self::NA_LIST => FakeFetcher::fail('fetch_failed', 500),
                self::EN_SEARCH => FakeFetcher::fail('flaresolverr_error', null),
                self::NF_SEARCH => FakeFetcher::fail('flaresolverr_error', null),
            ])
            : new FakeFetcher([], [
                self::NA_LIST => json_encode(['items' => $rows]),
                self::EN_SEARCH => 'empirenovel/search-live-shadow.html',
                self::NF_SEARCH => 'novelfull/search-shadow-slave.html',
            ]);
        app()->instance(Fetcher::class, $this->fetcher);
    }

    public function testIndexListsTheSourcesAndTheirLists(): void
    {
        $sources = $this->getJson('/api/v1/discover')->assertOk()->json('sources');

        $this->assertSame(['novelarrow', 'empirenovel', 'novelfull'], array_column($sources, 'id'));
        $this->assertSame([
            'id' => 'novelarrow',
            'name' => 'NovelPing (novelarrow)',
            'host' => 'novelping.com',
            'default' => true,
            'lists' => [
                ['id' => 'popular', 'name' => 'Popular'],
                ['id' => 'new', 'name' => 'New'],
                ['id' => 'random', 'name' => 'Random'],
                ['id' => 'completed', 'name' => 'Completed'],
                ['id' => 'search', 'name' => 'Search'],
            ],
        ], $sources[0]);
        $this->assertFalse($sources[1]['default']);
        $this->assertSame([['id' => 'search', 'name' => 'Search']], $sources[1]['lists']);
        $this->assertSame('empirenovel.com', $sources[1]['name']);
    }

    public function testBrowseShapeAndLibraryFlag(): void
    {
        $this->upstream();
        $inLibrary = Novel::create(['name' => 'Shadow Slave (WN)', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);

        $response = $this->getJson('/api/v1/discover/browse?source=novelarrow&type=popular')->assertOk();

        $this->assertSame(1, $response->json('page'));
        $this->assertFalse($response->json('has_more'));
        $items = $response->json('items');
        // Unlike the web page, browse lists keep library novels, flagged.
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame(self::ITEM_KEYS, array_keys($item));
        }

        $this->assertSame([
            'title' => 'Shadow Slave',
            'author' => 'Guiltythree',
            'cover_url' => 'https://images.novelping.com/novel/shadow-slave.jpg',
            'description' => 'Growing up in poverty. Sunny & co.',
            'status' => 'Ongoing',
            'chapters' => 3207,
            'url' => 'https://novelping.com/novel/shadow-slave',
            'source' => 'novelarrow',
            'in_library' => true,
            'novel_id' => $inLibrary->id,
        ], $items[0]);

        $this->assertSame('Completed', $items[1]['status']);
        $this->assertNull($items[1]['chapters']);
        $this->assertFalse($items[1]['in_library']);
        $this->assertNull($items[1]['novel_id']);
        $this->assertNull($items[2]['author']);
        $this->assertNull($items[2]['description']);
    }

    public function testDefaultSourceIsTheFirstOne(): void
    {
        $this->upstream();

        $this->getJson('/api/v1/discover/browse?type=popular')
            ->assertOk()
            ->assertJsonPath('items.0.source', 'novelarrow');
    }

    public function testLibraryMatchByNameGivesTheNovelId(): void
    {
        $this->upstream();
        $novel = Novel::create(['name' => 'Lord Of The Mysteries!', 'group_id' => 1, 'translator_url' => 'https://example.test/lotm']);

        $this->getJson('/api/v1/discover/browse?type=completed')
            ->assertOk()
            ->assertJsonPath('items.1.in_library', true)
            ->assertJsonPath('items.1.novel_id', $novel->id);
    }

    public function testDescriptionIsTruncated(): void
    {
        $this->upstream([
            ['novel_id' => 'long', 'novel_name' => 'Long', 'novel_desc' => str_repeat('word ', 400)],
        ]);

        $description = $this->getJson('/api/v1/discover/browse?type=popular')->assertOk()->json('items.0.description');

        $this->assertLessThanOrEqual(503, mb_strlen($description));
        $this->assertStringEndsWith('...', $description);
    }

    public function testPaging(): void
    {
        $rows = [];
        for ($i = 1; $i <= 45; $i++) {
            $rows[] = ['novel_id' => "novel-{$i}", 'novel_name' => "Novel {$i}"];
        }
        $this->upstream($rows);

        $first = $this->getJson('/api/v1/discover/browse?type=popular')->assertOk();
        $this->assertCount(20, $first->json('items'));
        $this->assertTrue($first->json('has_more'));
        $this->assertSame('Novel 1', $first->json('items.0.title'));

        $third = $this->getJson('/api/v1/discover/browse?type=popular&page=3')->assertOk();
        $this->assertSame(3, $third->json('page'));
        $this->assertSame(['Novel 41', 'Novel 42', 'Novel 43', 'Novel 44', 'Novel 45'], array_column($third->json('items'), 'title'));
        $this->assertFalse($third->json('has_more'));

        $this->getJson('/api/v1/discover/browse?type=popular&page=2&per_page=40')
            ->assertOk()
            ->assertJsonCount(5, 'items')
            ->assertJsonPath('has_more', false);

        $this->getJson('/api/v1/discover/browse?type=popular&page=9')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('has_more', false);

        // One upstream request: the list is cached, pages slice it.
        $this->assertCount(1, $this->fetcher->urls());
    }

    public function testRandomComesWholeOnTheFirstPage(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['novel_id' => "novel-{$i}", 'novel_name' => "Novel {$i}"];
        }
        $this->upstream($rows);
        Cache::put(NovelArrowSource::DISCOVER_PAGES_KEY, 1);

        $this->getJson('/api/v1/discover/browse?type=random')
            ->assertOk()
            ->assertJsonCount(30, 'items')
            ->assertJsonPath('has_more', false);
        $this->getJson('/api/v1/discover/browse?type=random&page=2')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function testSearchOnAFlareSolverrSource(): void
    {
        $this->upstream();

        $items = $this->getJson('/api/v1/discover/browse?source=novelfull&type=search&q=shadow+slave')->assertOk()->json('items');

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame('novelfull', $item['source']);
            $this->assertStringStartsWith('https://novelfull.com/', $item['url']);
            if ($item['cover_url'] !== null) {
                $this->assertMatchesRegularExpression('#^https?://#', $item['cover_url']);
            }
        }
    }

    public function testAListTheSourceDoesNotOfferIsEmpty(): void
    {
        $this->upstream();

        $this->getJson('/api/v1/discover/browse?source=novelfull&type=popular')
            ->assertOk()
            ->assertExactJson(['items' => [], 'page' => 1, 'has_more' => false]);
        $this->assertSame([], $this->fetcher->urls());
    }

    public function testUpstreamFailureIs502WithAPlainMessage(): void
    {
        $this->upstream(failing: true);

        foreach (['type=popular', 'source=empirenovel&type=search&q=shadow', 'source=novelfull&type=search&q=shadow'] as $query) {
            $this->getJson("/api/v1/discover/browse?{$query}")
                ->assertStatus(502)
                ->assertExactJson(['message' => "Couldn't load this list from the source. Try again later."]);
        }
    }

    public function testAnExceptionFromTheSourceIs502Too(): void
    {
        app()->instance(Fetcher::class, new class extends Fetcher {
            public function json(string $url, array $headers = [], &$status = null): ?array
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->getJson('/api/v1/discover/browse?type=popular')
            ->assertStatus(502)
            ->assertExactJson(['message' => "Couldn't load this list from the source. Try again later."]);
    }

    public function testValidation(): void
    {
        $this->getJson('/api/v1/discover/browse')->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->getJson('/api/v1/discover/browse?type=search')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/discover/browse?type=popular&source=nope')->assertStatus(422)->assertJsonValidationErrors(['source']);
        $this->getJson('/api/v1/discover/browse?type=popular&page=0')->assertStatus(422)->assertJsonValidationErrors(['page']);
        // Without an Accept header too (a plain HTTP client): JSON, not a redirect.
        $this->get('/api/v1/discover/browse?type=bogus')->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['type']]);
    }

    public function testWebBrowseIsUnchanged(): void
    {
        $this->upstream();
        Novel::create(['name' => 'Shadow Slave (WN)', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);

        $response = $this->getJson('/novels/discover/browse?type=popular&source=novelarrow')->assertOk();

        $this->assertSame(1, $response->json('hidden'));
        $this->assertArrayNotHasKey('novel_id', $response->json('items.0'));
        // Plus how a library item matched and a link to it (DuplicateSeries).
        $this->assertSame(['name', 'url', 'cover', 'cover_thumb', 'author', 'description', 'status', 'in_library', 'library_match', 'novel_url'], array_keys($response->json('items.0')));
    }
}
