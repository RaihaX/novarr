<?php

namespace Tests\Feature;

use App\Novel;
use App\Sources\MangaDexSource;
use App\Sources\PagedDiscover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFetcher;

/**
 * The web Discover page's "Load more" past the first page:
 * GET /novels/discover/browse?page=N for a paged source (MangaDex here),
 * `has_more` / `page` in the answer, page validation, text sources answering
 * page 1 only, and API v1 Discover still reading page 1 only. Never touches
 * the network.
 */
class DiscoverPagingWebTest extends GoldenTestCase
{
    use RefreshDatabase;

    private const API = 'https://api.mangadex.org';
    private const SOLO = '32d76d19-8a05-4db0-9fc2-e0b0648fe9d0';
    private const DARLING = 'aa6c76f7-5f5f-46b6-a800-911145f81b9b';

    private static function popularUrl(int $offset = 0): string
    {
        return self::API . '/manga?limit=40' . ($offset > 0 ? "&offset={$offset}" : '')
            . '&order[followedCount]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
    }

    private static function popularPage(int $offset, int $total): string
    {
        $json = json_decode(FakeFetcher::body('mangadex/discover-popular.json'), true);

        return json_encode(['offset' => $offset, 'total' => $total] + $json);
    }

    private function upstream(array $routes): FakeFetcher
    {
        $fetcher = $this->fake($routes);
        foreach ([self::SOLO => 200, self::DARLING => 110] as $id => $total) {
            $fetcher->route(self::API . '/chapter?' . MangaDexSource::query(MangaDexSource::readableParams($id)),
                json_encode(['result' => 'ok', 'data' => [], 'total' => $total]));
        }

        return $fetcher;
    }

    private static function mangaRequests(FakeFetcher $fetcher): array
    {
        return array_values(array_filter($fetcher->urls(), fn($u) => str_starts_with($u, self::API . '/manga?')));
    }

    public function testBrowsePageOneAndTwoOfAPagedSource(): void
    {
        $fetcher = $this->upstream([self::popularUrl() => 'mangadex/discover-popular.json', self::popularUrl(40) => self::popularPage(40, 80)]);

        $first = $this->getJson('/novels/discover/browse?source=mangadex&type=popular')->assertOk();
        $first->assertJsonPath('success', true)->assertJsonPath('page', 1)->assertJsonPath('has_more', true)->assertJsonPath('hidden', 0);
        $this->assertSame(['Solo Leveling', 'My Dress-Up Darling'], array_column($first->json('items'), 'name'));

        $second = $this->getJson('/novels/discover/browse?source=mangadex&type=popular&page=2')->assertOk();
        $this->assertSame(['success', 'items', 'hidden', 'has_more', 'page'], array_keys($second->json()));
        $second->assertJsonPath('page', 2)->assertJsonPath('has_more', false);
        $this->assertSame('https://mangadex.org/title/' . self::SOLO, $second->json('items.0.url'));
        $this->assertArrayNotHasKey('novel_id', $second->json('items.0'));
        $this->assertSame([self::popularUrl(), self::popularUrl(40)], self::mangaRequests($fetcher));
    }

    public function testLaterPagesHideLibraryNovelsAndCountThem(): void
    {
        $this->upstream([self::popularUrl(40) => self::popularPage(40, 5000)]);
        $manga = Novel::create(['name' => 'Something Else', 'group_id' => 1, 'translator_url' => 'https://mangadex.org/title/' . self::DARLING]);
        $manga->forceFill(['medium' => 'manga'])->save();

        $this->getJson('/novels/discover/browse?source=mangadex&type=popular&page=2')->assertOk()
            ->assertJsonPath('hidden', 1)->assertJsonPath('has_more', true)
            ->assertJsonPath('items.0.name', 'Solo Leveling')->assertJsonCount(1, 'items');
    }

    public function testSearchPagesKeepTheQuery(): void
    {
        $search2 = self::API . '/manga?limit=40&offset=40&title=solo&order[relevance]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
        $fetcher = $this->upstream([$search2 => self::popularPage(40, 81)]);

        $this->getJson('/novels/discover/browse?' . http_build_query(['source' => 'mangadex', 'type' => 'search', 'q' => 'solo', 'page' => 2]))
            ->assertOk()->assertJsonPath('page', 2)->assertJsonPath('has_more', true)->assertJsonCount(2, 'items');
        $this->assertSame([$search2], self::mangaRequests($fetcher));
    }

    public function testAnUnreachablePageIsABadGateway(): void
    {
        $this->upstream([self::popularUrl(40) => FakeFetcher::fail('http_503', 503)]);

        $this->getJson('/novels/discover/browse?source=mangadex&type=popular&page=2')
            ->assertStatus(502)->assertJsonPath('success', false);
    }

    public function testBadPagesAreRejected(): void
    {
        $fetcher = $this->fake([]);
        foreach (['0', '-1', 'two', '1.5', (string) (PagedDiscover::MAX_PAGE + 1)] as $page) {
            $this->getJson('/novels/discover/browse?source=mangadex&type=popular&page=' . urlencode($page))
                ->assertStatus(422)->assertJsonValidationErrors('page');
        }
        $this->assertSame([], $fetcher->calls);
    }

    public function testATextSourceHasOnlyOnePage(): void
    {
        // Page 2 of a source that does not page is empty without a request.
        $fetcher = $this->fake([]);
        foreach ([
            'source=novelarrow&type=popular', 'source=novelarrow&type=random', 'source=novelarrow&type=search&q=shadow',
            'source=novelfull&type=search&q=shadow', 'source=empirenovel&type=search&q=shadow',
        ] as $query) {
            $this->getJson("/novels/discover/browse?{$query}&page=2")->assertOk()
                ->assertExactJson(['success' => true, 'items' => [], 'hidden' => 0, 'has_more' => false, 'page' => 2]);
        }
        // A list the source doesn't offer stays an empty answer.
        $this->getJson('/novels/discover/browse?source=empirenovel&type=popular&page=2')->assertOk()
            ->assertExactJson(['success' => true, 'items' => [], 'has_more' => false, 'page' => 2]);
        $this->assertSame([], $fetcher->calls);
    }

    public function testApiV1BrowseStillReadsPageOneOnly(): void
    {
        $fetcher = $this->upstream([self::popularUrl() => 'mangadex/discover-popular.json']);

        $first = $this->getJson('/api/v1/discover/browse?medium=manga&source=mangadex&type=popular')->assertOk();
        $this->assertSame(['Solo Leveling', 'My Dress-Up Darling'], array_column($first->json('items'), 'title'));
        $first->assertJsonPath('page', 1)->assertJsonPath('has_more', false);

        $this->getJson('/api/v1/discover/browse?medium=manga&source=mangadex&type=popular&page=2')->assertOk()
            ->assertJsonPath('items', [])->assertJsonPath('has_more', false);
        $this->assertSame([self::popularUrl()], self::mangaRequests($fetcher), 'never offset');
    }

    public function testThePageScriptFetchesTheNextPage(): void
    {
        $html = $this->get('/novels/discover')->assertOk()->getContent();

        $this->assertStringContainsString('id="discoverMore"', $html);
        $this->assertStringContainsString("page: String(ctx.page + 1)", $html);
        $this->assertStringContainsString('discoverPaging', $html);
    }
}
