<?php

namespace Tests\Feature;

use App\Scraping\Fetcher;
use App\Services\Discover;
use App\Services\Requests\RequestSearch;
use App\Sources\SuwayomiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Which manga sources a reader-request search asks, and in what order
 * (RequestSearch::sources() / searchOrder()): source_order by key, then
 * source_name_preference by display name, then Discover order; one member
 * per source family; at most max_sources_per_search.
 */
class RequestSourceSelectionTest extends TestCase
{
    use RefreshDatabase;

    /** The English sources installed on the owner's Suwayomi server. */
    private const INSTALLED = [
        'Asura Scans', 'Atsumaru', 'Flame Comics', 'Kagane', 'Manga Demon', 'MANGA Plus by SHUEISHA',
        'Mangabat', 'MangaFire', 'MangaFox', 'Mangago', 'Mangahere', 'MangaHub', 'Mangakakalot',
        'MangaKatana', 'Manganato', 'MangaPark.tv (unoriginal)', 'MangaPill', 'Mangatown', 'TCB Scans',
        'Toonily', 'Webtoons.com', 'Weeb Central',
    ];

    public function test_the_owners_server_searches_the_preferred_sources_one_per_family(): void
    {
        config([
            'novarr.suwayomi.url' => 'http://suwayomi.test:4567',
            'novarr.suwayomi.languages' => ['en'],
            'novarr.suwayomi.exclude_sources' => ['Local source'],
        ]);
        // The server's source list as SuwayomiSource caches it (ids by
        // install order, not by name). No route: any request would throw.
        $rows = [];
        foreach (array_reverse(self::INSTALLED) as $i => $name) {
            $rows[] = ['id' => (string) (7000 + $i), 'name' => $name, 'lang' => 'en'];
        }
        $rows[] = ['id' => '9999', 'name' => 'Some Spanish Source', 'lang' => 'es'];
        Cache::put(SuwayomiSource::sourcesCacheKey(), $rows, 3600);
        $fetcher = new FakeFetcher();
        $this->app->instance(Fetcher::class, $fetcher);

        $sources = app(RequestSearch::class)->sources(app(Discover::class), 'manga');
        $names = array_map(fn($source) => RequestSearch::displayName($source, ''), array_values($sources));

        $this->assertSame([
            'MangaDex (manga)', 'MANGA Plus by SHUEISHA', 'Asura Scans', 'Flame Comics', 'MangaFire',
            'Weeb Central', 'Webtoons.com', 'TCB Scans', 'Toonily', 'MangaPark.tv (unoriginal)',
            'Mangakakalot', 'MangaPill', 'Kagane', 'MangaKatana', 'Manga Demon',
        ], $names);
        $this->assertCount(15, $sources, 'max_sources_per_search defaults to 15');
        $this->assertSame('mangadex', array_key_first($sources));
        $this->assertSame([], $fetcher->calls, 'names come from the cached source list: no request');

        // Without the cap: the rest of the preference, then the unnamed ones
        // in Discover order — family members other than the best placed never.
        config(['novarr.requests.max_sources_per_search' => 50]);
        $all = array_map(fn($source) => RequestSearch::displayName($source, ''), array_values(app(RequestSearch::class)->sources(app(Discover::class), 'manga')));
        $this->assertSame(['Atsumaru', 'Mangago', 'MangaHub', 'MangaFox'], array_slice($all, 15));
        foreach (['Manganato', 'Mangabat', 'Mangahere', 'Mangatown'] as $mirror) {
            $this->assertNotContains($mirror, $all);
        }
    }

    public function test_search_order_rules(): void
    {
        $names = [
            'a' => 'Alpha', 'b' => 'Beta', 'suwayomi:1' => 'Gamma Scans', 'suwayomi:2' => 'Delta',
            'suwayomi:3' => 'Mangakakalot', 'suwayomi:4' => 'Manganato', 'suwayomi:5' => 'Mangagoyaoi', 'e' => 'Epsilon',
        ];

        // Unnamed sources keep their relative order after the named ones.
        $this->assertSame(['e', 'suwayomi:1', 'a', 'b', 'suwayomi:2', 'suwayomi:3', 'suwayomi:4', 'suwayomi:5'],
            RequestSearch::searchOrder($names, [], ['epsilon', 'GAMMA'], []));

        // An explicit source_order entry wins over the name preference; a
        // prefix key ("suwayomi") places all of its entries.
        $this->assertSame(['b', 'e', 'a', 'suwayomi:1', 'suwayomi:2', 'suwayomi:3', 'suwayomi:4', 'suwayomi:5'],
            RequestSearch::searchOrder($names, ['b'], ['Epsilon'], []));
        $this->assertSame(['suwayomi:1', 'suwayomi:2', 'suwayomi:3', 'suwayomi:4', 'suwayomi:5', 'a', 'b', 'e'],
            RequestSearch::searchOrder($names, ['suwayomi', 'nope'], ['Alpha'], []));

        // Families: only the best-placed member; it can be placed by preference.
        $this->assertSame(['suwayomi:4', 'a', 'b', 'suwayomi:1', 'suwayomi:2', 'suwayomi:5', 'e'],
            RequestSearch::searchOrder($names, [], ['Manganato'], [['Mangakakalot', 'Manganato', 'Mangabat']]));
        // A one-member family or a family not installed changes nothing.
        $this->assertSame(array_keys($names), RequestSearch::searchOrder($names, [], [], [['Mangakakalot'], ['X', 'Y']]));
    }

    public function test_names_match_case_insensitively_and_by_whole_words(): void
    {
        $this->assertTrue(RequestSearch::nameMatches('MANGA Plus by SHUEISHA', 'manga plus'));
        $this->assertTrue(RequestSearch::nameMatches('Webtoons.com', 'Webtoons'));
        $this->assertTrue(RequestSearch::nameMatches('MangaPark.tv (unoriginal)', 'MangaPark'));
        $this->assertTrue(RequestSearch::nameMatches('MangaDex (manga)', 'MangaDex'));
        $this->assertTrue(RequestSearch::nameMatches('Weeb Central', 'weeb central'));
        $this->assertFalse(RequestSearch::nameMatches('Mangagoyaoi', 'Mangago'));
        // An entry matches any name that starts with it word for word.
        $this->assertTrue(RequestSearch::nameMatches('Manga Demon', 'Manga'));
        $this->assertFalse(RequestSearch::nameMatches('Manga Demon', 'Demon'));
        $this->assertFalse(RequestSearch::nameMatches('Anything', ''));
    }

    public function test_a_suwayomi_entry_is_named_by_its_source_without_a_request(): void
    {
        $this->app->instance(Fetcher::class, $fetcher = new FakeFetcher());

        $this->assertSame('Asura Scans', RequestSearch::displayName(new SuwayomiSource('1', 'Asura Scans', 'en'), 'suwayomi:1'));
        $this->assertSame('Asura Scans', (new SuwayomiSource('1', 'Asura Scans', 'en'))->sourceName());
        $this->assertNull((new SuwayomiSource())->sourceName());
        $this->assertSame([], $fetcher->calls);
    }

    public function test_the_defaults(): void
    {
        $this->assertSame(15, config('novarr.requests.max_sources_per_search'));
        $this->assertSame(5, RequestSearch::parallelism());
        $this->assertSame(4, RequestSearch::hostParallelism());
        $this->assertSame(20.0, RequestSearch::sourceTimeoutSeconds());
        $this->assertSame('MangaDex', config('novarr.requests.source_name_preference.0'));
        $this->assertSame([['Mangakakalot', 'Manganato', 'Mangabat'], ['MangaFox', 'Mangahere', 'Mangatown']], config('novarr.requests.source_families'));
    }
}
