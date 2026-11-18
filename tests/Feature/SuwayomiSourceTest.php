<?php

namespace Tests\Feature;

use App\ChapterPage;
use App\Console\Commands\ChapterScraper;
use App\Console\Commands\NovelScraper;
use App\Group;
use App\Http\Controllers\DiscoverCoverController;
use App\Novel;
use App\NovelChapter;
use App\Scraping\HostPace;
use App\Services\Discover;
use App\Sources\MangaDexSource;
use App\Sources\NovelArrowSource;
use App\Sources\SourceResolver;
use App\Sources\SuwayomiSource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFetcher;

/**
 * SuwayomiSource against GraphQL answers shaped like Suwayomi-Server
 * v2.4.2366's (tests/fixtures/suwayomi/README.md). Never touches the
 * network: every GraphQL call goes to one URL, so the fake answers by the
 * query in the request body.
 */
class SuwayomiSourceTest extends GoldenTestCase
{
    use RefreshDatabase;

    private const BASE = 'http://192.168.1.37:4567';
    private const GQL = self::BASE . '/api/graphql';
    private const MANGAPILL = '8448310129093543312';
    private const SERIES = self::BASE . '/manga/2132';
    private const CHAPTER = self::BASE . '/manga/2132/chapter/1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'novarr.suwayomi.url' => self::BASE,
            'novarr.suwayomi.languages' => ['en'],
            'novarr.suwayomi.exclude_sources' => ['MangaDex', 'Local source'],
            'novarr.suwayomi.timeout' => 90,
        ]);
    }

    /**
     * Route the GraphQL endpoint: query text => fixture / body / fail() /
     * fn(array $variables). An unrouted query is an unexpected fetch.
     */
    private function graphql(array $answers, array $routes = []): FakeFetcher
    {
        return $this->fake([self::GQL => function (array $body) use ($answers) {
            $answer = $answers[$body['query']] ?? null;

            return $answer instanceof \Closure ? $answer($body['variables'] ?? []) : $answer;
        }] + $routes);
    }

    /** The recorded GraphQL calls: [query, variables, timeout]. */
    private static function gqlCalls(FakeFetcher $fetcher): array
    {
        return array_values(array_map(
            fn($c) => [$c['body']['query'], $c['body']['variables'] ?? [], $c['timeout']],
            array_filter($fetcher->calls, fn($c) => $c['method'] === 'post_json_for_json')
        ));
    }

    // ---- Not configured --------------------------------------------------------

    public function testNotConfiguredMatchesNothingListsNothingAndNeverAsks(): void
    {
        config(['novarr.suwayomi.url' => '']);
        $fetcher = $this->fake([]);
        $source = new SuwayomiSource();

        $this->assertFalse(SuwayomiSource::configured());
        $this->assertFalse($source->matchesUrl(self::SERIES));
        $this->assertFalse($source->matchesUrl(self::CHAPTER));
        $this->assertNotInstanceOf(SuwayomiSource::class, SourceResolver::forUrl(self::SERIES));
        $this->assertNull($source->librarySlug(self::SERIES));
        $this->assertSame([], $source->discoverSources());
        $this->assertSame([], $source->discoverLists());
        $this->assertNull(SourceResolver::get('suwayomi:' . self::MANGAPILL));
        $this->assertSame([], array_filter(array_keys(app(Discover::class)->sources()), fn($k) => str_starts_with($k, 'suwayomi')));
        $this->assertSame([], $source->tableOfContents($this->novel(self::SERIES, 'Ippo')));
        $this->assertNull($source->chapterPages(self::CHAPTER));
        $this->assertFalse(DiscoverCoverController::proxied(self::BASE . '/api/v1/manga/2132/thumbnail'));
        $this->assertSame([], $fetcher->calls, 'no request at all');

        // Anything but an http(s) URL is "not configured" too.
        config(['novarr.suwayomi.url' => '192.168.1.37:4567']);
        $this->assertFalse(SuwayomiSource::configured());
    }

    // ---- URLs ------------------------------------------------------------------

    public function testUrlMatchingAndResolution(): void
    {
        $fetcher = $this->fake([]);
        config(['novarr.suwayomi.url' => self::BASE . '/']); // trailing slash trimmed
        $source = new SuwayomiSource();

        foreach ([self::SERIES, self::SERIES . '/', self::CHAPTER, 'HTTP://192.168.1.37:4567/manga/2132/chapter/12/'] as $url) {
            $this->assertTrue($source->matchesUrl($url), $url);
            $this->assertInstanceOf(SuwayomiSource::class, SourceResolver::forUrl($url), $url);
        }
        foreach ([
            'https://192.168.1.37:4567/manga/2132',      // other scheme
            'http://192.168.1.37:4568/manga/2132',       // other port
            'http://192.168.1.38:4567/manga/2132',       // other host
            'http://192.168.1.37/manga/2132',            // default port is another origin
            self::BASE . '/manga/abc',
            self::BASE . '/manga/2132/chapter/1/page/0',
            self::BASE . '/api/v1/manga/2132/thumbnail',
            self::BASE . '/',
            '/manga/2132',
        ] as $url) {
            $this->assertFalse($source->matchesUrl($url), $url);
        }

        // Other sources are unaffected.
        $this->assertInstanceOf(MangaDexSource::class, SourceResolver::forUrl('https://mangadex.org/title/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b'));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('https://novelping.com/novel/shadow-slave'));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('http://192.168.1.38:4567/manga/2132'));
        $this->assertSame(['mangadex', 'suwayomi'], array_slice(array_keys(SourceResolver::classes()), 0, 2));

        $this->assertSame('manga', $source->medium());
        $this->assertSame('suwayomi', $source->name());
        $this->assertSame(2132, SuwayomiSource::mangaId(self::SERIES));
        $this->assertSame(2132, SuwayomiSource::mangaId(self::CHAPTER));
        $this->assertNull(SuwayomiSource::mangaId('https://mangadex.org/title/2132'));
        $this->assertSame(['manga' => 2132, 'order' => 1], SuwayomiSource::chapterRef(self::CHAPTER));
        $this->assertNull(SuwayomiSource::chapterRef(self::SERIES));
        $this->assertSame(self::SERIES, SuwayomiSource::seriesUrl(2132));
        $this->assertSame(self::CHAPTER, SuwayomiSource::chapterUrl(2132, 1));
        $this->assertSame('2132', $source->librarySlug(self::SERIES . '/', stored: true));
        $this->assertNull($source->librarySlug('https://novelping.com/novel/2132'));

        // Default ports and a base path behind a reverse proxy.
        config(['novarr.suwayomi.url' => 'http://suwayomi.lan']);
        $this->assertTrue($source->matchesUrl('http://suwayomi.lan:80/manga/5'));
        config(['novarr.suwayomi.url' => 'https://proxy.example/suwayomi']);
        $this->assertTrue($source->matchesUrl('https://proxy.example/suwayomi/manga/5/chapter/2'));
        $this->assertFalse($source->matchesUrl('https://proxy.example/manga/5'));
        $this->assertFalse($source->matchesUrl('https://proxy.example/suwayomifoo/manga/5'));
        $this->assertSame('https://proxy.example/suwayomi/manga/5/chapter/2', SuwayomiSource::chapterUrl(5, 2));

        $this->assertSame([], $fetcher->calls, 'URL resolution never makes a request');
    }

    // ---- TOC -------------------------------------------------------------------

    public function testTableOfContents(): void
    {
        $fetcher = $this->graphql([SuwayomiSource::MUTATION_FETCH_CHAPTERS => 'suwayomi/fetch-chapters.json']);

        $toc = tableOfContentGenerator($this->novel(self::SERIES, 'Hajime no Ippo'));

        $this->assertSame([[SuwayomiSource::MUTATION_FETCH_CHAPTERS, ['input' => ['mangaId' => 2132]], 90]], self::gqlCalls($fetcher));
        $this->assertStringNotContainsString('2132', SuwayomiSource::MUTATION_FETCH_CHAPTERS, 'ids travel as variables');
        $this->assertSame([
            // Unnumbered: one row, number 0, the name of the biggest scanlator's copy.
            ['label' => 'Special: Bonus', 'chapter' => '0', 'url' => self::BASE . '/manga/2132/chapter/9'],
            // Alpha (4 chapters) wins over Beta.
            ['label' => 'Chapter 1', 'chapter' => '1', 'url' => self::BASE . '/manga/2132/chapter/1'],
            ['label' => 'Chapter 2: The Jab', 'chapter' => '2', 'url' => self::BASE . '/manga/2132/chapter/3'],
            ['label' => 'Chapter 2.5: Omake', 'chapter' => '2.5', 'url' => self::BASE . '/manga/2132/chapter/4'],
            // Gamma / Delta tie on count: the earlier upload (Delta).
            ['label' => 'Chapter 3', 'chapter' => '3', 'url' => self::BASE . '/manga/2132/chapter/6'],
            // Epsilon / Zeta tie on count and upload: the lower id (Zeta, 35170).
            ['label' => 'Chapter 4: Tie', 'chapter' => '4', 'url' => self::BASE . '/manga/2132/chapter/7'],
        ], array_map(fn($r) => ['label' => $r['label'], 'chapter' => (string) $r['chapter'], 'url' => $r['url']], $toc));
        $this->assertSame([0], array_values(array_unique(array_column($toc, 'book'))));
    }

    public function testChapterTitle(): void
    {
        $this->assertSame('', SuwayomiSource::chapterTitle('Chapter 12', '12'));
        $this->assertSame('The Fight', SuwayomiSource::chapterTitle('Vol.3 Ch. 12.5 - The Fight', '12.5'));
        $this->assertSame('Chapter 120', SuwayomiSource::chapterTitle('Chapter 120', '12'), 'another number is kept whole');
        $this->assertSame('Prologue', SuwayomiSource::chapterTitle('Prologue', '0'));
    }

    public function testTocRowsGoThroughTheRealSync(): void
    {
        $this->graphql([SuwayomiSource::MUTATION_FETCH_CHAPTERS => 'suwayomi/fetch-chapters.json']);
        Group::forceCreate(['id' => 1, 'label' => 'Default', 'url' => 'https://novelping.com']);
        $novel = Novel::create(['name' => 'Hajime no Ippo', 'group_id' => 1, 'translator_url' => self::SERIES]);
        $novel->forceFill(['medium' => 'manga'])->save();

        $command = new NovelScraper();
        $command->setLaravel($this->app);
        $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput()));
        $command->processNovel($novel->fresh(), true);

        $rows = NovelChapter::where('novel_id', $novel->id)->ordered()->get();
        $this->assertSame(['Special: Bonus', 'Chapter 1', 'Chapter 2: The Jab', 'Chapter 2.5: Omake', 'Chapter 3', 'Chapter 4: Tie'], $rows->pluck('label')->all());
        $this->assertSame([0.0, 1.0, 2.0, 2.5, 3.0, 4.0], $rows->pluck('chapter')->all());
        $this->assertSame(self::CHAPTER, chapterSourceUrl($rows[1]), 'the group URL never rewrites a manga chapter');
    }

    public function testTocFailuresAreAnEmptyToc(): void
    {
        $this->graphql([SuwayomiSource::MUTATION_FETCH_CHAPTERS => 'suwayomi/error.json']);
        \Illuminate\Support\Facades\Log::spy();
        $this->assertSame([], tableOfContentGenerator($this->novel(self::SERIES, 'Ippo')));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn($m) => $m === 'SuwayomiSource: GraphQL error: java.lang.Exception: HTTP error 503');

        $this->graphql([SuwayomiSource::MUTATION_FETCH_CHAPTERS => FakeFetcher::fail('exception', null)]);
        $this->assertSame([], tableOfContentGenerator($this->novel(self::SERIES, 'Ippo')));
    }

    public function testErrorMessageIsShort(): void
    {
        $this->assertSame(str_repeat('x', 199) . '…', SuwayomiSource::errorMessage([['message' => str_repeat('x', 5000)]]));
        $this->assertSame('boom', SuwayomiSource::errorMessage([['message' => "boom\n\tat a.b(C.kt:1)"]]));
    }

    // ---- Metadata --------------------------------------------------------------

    public function testMetadata(): void
    {
        $fetcher = $this->graphql([SuwayomiSource::MUTATION_FETCH_MANGA => 'suwayomi/fetch-manga.json']);
        $metadata = (new SuwayomiSource())->metadata($this->novel(self::SERIES, 'Hajime no Ippo'));

        $this->assertSame([[SuwayomiSource::MUTATION_FETCH_MANGA, ['input' => ['id' => 2132]], 90]], self::gqlCalls($fetcher), 'Suwayomi only — never NovelUpdates');
        $this->assertSame('Hajime no Ippo', $metadata['title']);
        $this->assertSame('George Morikawa', $metadata['author'], 'artist de-duplicated');
        $this->assertSame("<p>Throughout his childhood, Makunouchi Ippo has always been picked on.</p><p>&quot;What is it like to be strong?&quot; he asks himself.<br>\nHe wants to be strong.</p>", $metadata['description']);
        $this->assertSame(['Action', 'Comedy', 'Drama', 'Shounen', 'Sports'], $metadata['genres']);
        $this->assertSame(self::BASE . '/api/v1/manga/2132/thumbnail', $metadata['image']);
        $this->assertSame([self::BASE . '/api/v1/manga/2132/thumbnail'], $metadata['cover_candidates']);
        $this->assertSame(0, $metadata['no_of_chapters'], 'unknown up front');
        $this->assertSame('Ongoing', $metadata['status_text']);
        $this->assertFalse($metadata['completed']);
        $this->assertSame('rtl', $metadata['reading_mode']);
        $this->assertSame([self::SERIES], $metadata['tried_urls']);
    }

    public function testMetadataReadingModeAndStatusMapping(): void
    {
        $this->graphql([SuwayomiSource::MUTATION_FETCH_MANGA => 'suwayomi/fetch-manga-manhwa.json']);
        $metadata = (new SuwayomiSource())->metadata($this->novel(self::BASE . '/manga/2202', 'Solo Leveling'));
        $this->assertSame('vertical', $metadata['reading_mode']);
        $this->assertSame('Chugong, DUBU (REDICE STUDIO)', $metadata['author']);
        $this->assertSame('Completed', $metadata['status_text'], 'publishing finished');
        $this->assertTrue($metadata['completed']);

        foreach (['webtoon', 'Manhua', 'Long Strip'] as $genre) {
            $this->assertSame('vertical', SuwayomiSource::seriesMetadata(['id' => 1, 'genre' => [$genre]])['reading_mode'], $genre);
        }
        $this->assertSame('rtl', SuwayomiSource::seriesMetadata(['id' => 1, 'genre' => ['Shounen']])['reading_mode']);
        $this->assertSame('Hiatus', SuwayomiSource::seriesMetadata(['id' => 1, 'status' => 'ON_HIATUS'])['status_text']);
        $this->assertSame('Cancelled', SuwayomiSource::seriesMetadata(['id' => 1, 'status' => 'CANCELLED'])['status_text']);
        $this->assertArrayNotHasKey('status_text', SuwayomiSource::seriesMetadata(['id' => 1, 'status' => 'UNKNOWN']));
    }

    public function testMetadataFailureIsEmpty(): void
    {
        $this->graphql([SuwayomiSource::MUTATION_FETCH_MANGA => 'suwayomi/error.json']);
        $metadata = (new SuwayomiSource())->metadata($this->novel(self::SERIES, 'x'));
        $this->assertSame('', $metadata['description']);
        $this->assertSame([], $metadata['cover_candidates']);
        $this->assertArrayNotHasKey('reading_mode', $metadata);
    }

    // ---- Pages -----------------------------------------------------------------

    public function testChapterPages(): void
    {
        $fetcher = $this->graphql([
            SuwayomiSource::QUERY_CHAPTER_ID => 'suwayomi/chapter-id.json',
            SuwayomiSource::MUTATION_FETCH_PAGES => 'suwayomi/chapter-pages.json',
        ]);
        $failure = 'stale';
        $pages = (new SuwayomiSource())->chapterPages(self::CHAPTER, $failure);

        $this->assertNull($failure);
        $this->assertSame([
            ['url' => self::BASE . '/api/v1/manga/2132/chapter/1/page/0', 'headers' => []],
            ['url' => self::BASE . '/api/v1/manga/2132/chapter/1/page/1', 'headers' => []],
            ['url' => self::BASE . '/api/v1/manga/2132/chapter/1/page/2', 'headers' => []],
        ], $pages);
        $this->assertSame([
            [SuwayomiSource::QUERY_CHAPTER_ID, ['mangaId' => 2132, 'sourceOrder' => 1], 90],
            [SuwayomiSource::MUTATION_FETCH_PAGES, ['input' => ['chapterId' => 35142]], 90],
        ], self::gqlCalls($fetcher));
    }

    public function testChapterPagesFailures(): void
    {
        $source = new SuwayomiSource();
        $cases = [
            'not_found' => [SuwayomiSource::QUERY_CHAPTER_ID => 'suwayomi/chapter-id-none.json'],
            'fetch_failed' => [SuwayomiSource::QUERY_CHAPTER_ID => 'suwayomi/chapter-id.json', SuwayomiSource::MUTATION_FETCH_PAGES => 'suwayomi/error.json'],
            'no_content' => [SuwayomiSource::QUERY_CHAPTER_ID => 'suwayomi/chapter-id.json', SuwayomiSource::MUTATION_FETCH_PAGES => 'suwayomi/chapter-pages-empty.json'],
            'rate_limited' => [SuwayomiSource::QUERY_CHAPTER_ID => FakeFetcher::fail('rate_limited', 429)],
        ];
        foreach ($cases as $expected => $answers) {
            $this->graphql($answers);
            $failure = null;
            $this->assertSame([], $source->chapterPages(self::CHAPTER, $failure), $expected);
            $this->assertSame($expected, $failure);
        }

        $this->graphql([SuwayomiSource::QUERY_CHAPTER_ID => FakeFetcher::fail('exception', null)]);
        $this->assertSame([], $source->chapterPages(self::CHAPTER, $failure));
        $this->assertSame('fetch_failed', $failure);

        // Not a chapter URL of the server: this source serves no pages for it.
        $this->assertNull($source->chapterPages(self::SERIES, $failure));
        $this->assertNull($source->chapterPages('http://192.168.1.38:4567/manga/2132/chapter/1', $failure));
    }

    // ---- Discover --------------------------------------------------------------

    public function testDiscoverExpandsIntoOneEntryPerSuwayomiSource(): void
    {
        $fetcher = $this->graphql([SuwayomiSource::QUERY_SOURCES => 'suwayomi/sources.json']);
        $discover = app(Discover::class);

        $sources = $discover->sources();
        $suwayomi = array_values(array_filter(array_keys($sources), fn($k) => str_starts_with($k, 'suwayomi')));
        // English only; MangaDex and the local source excluded; sorted by name.
        $this->assertSame(['suwayomi:1001157238479601077', 'suwayomi:' . self::MANGAPILL], $suwayomi);
        $this->assertNotContains('suwayomi', array_keys($sources), 'the registered instance itself lists nothing');
        $this->assertSame('novelarrow', array_key_first($sources), 'a manga source is never the default');
        $this->assertSame(['mangadex', ...$suwayomi], array_keys($discover->sourcesFor('manga')));
        $this->assertSame([], array_filter(array_keys($discover->sourcesFor('novel')), fn($k) => str_starts_with($k, 'suwayomi')));

        $options = $discover->sourceOptions('manga');
        $this->assertSame(['label' => 'Suwayomi · MangaPill', 'host' => '192.168.1.37:4567', 'lists' => ['search', 'popular'], 'medium' => 'manga'], $options['suwayomi:' . self::MANGAPILL]);
        $this->assertSame('Suwayomi · FlameScans.lol', $options['suwayomi:1001157238479601077']['label']);

        // Keys resolve back to their scoped instance; the plain key is the registered one.
        $scoped = SourceResolver::get('suwayomi:' . self::MANGAPILL);
        $this->assertInstanceOf(SuwayomiSource::class, $scoped);
        $this->assertSame(self::MANGAPILL, $scoped->sourceId());
        $this->assertNull(SourceResolver::get('suwayomi:999'));
        $this->assertNull(SourceResolver::get('suwayomi:'));
        $this->assertNull(SourceResolver::get('novelfull:1'));
        $this->assertNull(SourceResolver::get('suwayomi')->sourceId());

        // The source list is asked for once (cached).
        $discover->sources();
        $this->assertCount(1, self::gqlCalls($fetcher));
        $this->assertSame([SuwayomiSource::QUERY_SOURCES, [], 15], self::gqlCalls($fetcher)[0]);
    }

    public function testSourceNamesOutliveTheHourlyListAndAreRelearnedByADownloadRun(): void
    {
        $fetcher = $this->graphql([SuwayomiSource::QUERY_SOURCES => 'suwayomi/sources.json']);
        $lane = 'suwayomi:' . self::MANGAPILL;

        // Unknown: a label never asks the server …
        $this->assertSame('Suwayomi · #' . self::MANGAPILL, \App\Services\DownloadLanes::label($lane));
        $this->assertCount(0, self::gqlCalls($fetcher));

        // … a download run does, once.
        $this->assertSame('MangaPill', (new SuwayomiSource())->ensureSourceName(self::MANGAPILL));
        (new SuwayomiSource())->ensureSourceName(self::MANGAPILL);
        $this->assertCount(1, self::gqlCalls($fetcher));

        // The hourly list expires (or the server is down): the name stays.
        \Illuminate\Support\Facades\Cache::forget(SuwayomiSource::sourcesCacheKey());
        $this->assertSame('Suwayomi · MangaPill', \App\Services\DownloadLanes::label($lane));
        $this->assertCount(1, self::gqlCalls($fetcher));
    }

    public function testDiscoverLanguagesAndExcludeList(): void
    {
        $this->graphql([SuwayomiSource::QUERY_SOURCES => 'suwayomi/sources.json']);
        config(['novarr.suwayomi.languages' => ['EN', 'ja'], 'novarr.suwayomi.exclude_sources' => ['local source', 'FLAMESCANS.LOL']]);

        $labels = array_map(fn($s) => $s->discoverLabel(), (new SuwayomiSource())->discoverSources());
        $this->assertSame([
            '2499283573021220255' => 'Suwayomi · MangaDex (EN)',
            self::MANGAPILL => 'Suwayomi · MangaPill (EN)',
            '3600219145208924541' => 'Suwayomi · Rawkuma (JA)',
        ], $labels);
    }

    public function testDiscoverSourceListFailureListsNothing(): void
    {
        $fetcher = $this->graphql([SuwayomiSource::QUERY_SOURCES => FakeFetcher::fail('exception', null)]);
        $discover = app(Discover::class);

        $this->assertSame(['novelarrow', 'empirenovel', 'novelfull', 'mangadex'], array_keys($discover->sources()));
        $discover->sourceOptions();
        $this->assertCount(1, self::gqlCalls($fetcher), 'a dead server is asked once per page load');
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('suwayomi_sources_v1_' . md5(self::BASE)), 'a failure is not cached');
    }

    public function testDiscoverSearchAndPopular(): void
    {
        $fetcher = $this->graphql([SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA => fn(array $v) => match ($v['input']['type']) {
            'SEARCH' => 'suwayomi/fetch-source-manga-search.json',
            'POPULAR' => 'suwayomi/fetch-source-manga-popular.json',
        }]);
        $source = new SuwayomiSource(self::MANGAPILL, 'MangaPill', 'en');

        $popular = $source->discover('popular');
        $this->assertSame([
            ['name' => 'One Piece', 'url' => self::BASE . '/manga/2201', 'cover' => self::BASE . '/api/v1/manga/2201/thumbnail', 'cover_thumb' => '',
                'author' => 'Oda Eiichiro', 'description' => 'Gol D. Roger was known as the "Pirate King" & more.', 'status' => 'Ongoing'],
            ['name' => 'Solo Leveling', 'url' => self::BASE . '/manga/2202', 'cover' => self::BASE . '/api/v1/manga/2202/thumbnail', 'cover_thumb' => '',
                'author' => '', 'description' => '', 'status' => 'Completed'],
        ], $popular);

        $found = $source->discover('search', '  hajime no ippo ');
        $this->assertSame(['Hajime no Ippo', 'Kindaichi 37-sai no Jikenbo'], array_column($found, 'name'));
        $this->assertArrayNotHasKey('status', $found[1], 'UNKNOWN is no status');

        // Cached: no second request.
        $source->discover('popular');
        $source->discover('search', 'hajime no ippo');
        $this->assertSame([
            [SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA, ['input' => ['source' => self::MANGAPILL, 'type' => 'POPULAR', 'page' => 1]], 90],
            [SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA, ['input' => ['source' => self::MANGAPILL, 'type' => 'SEARCH', 'query' => 'hajime no ippo', 'page' => 1]], 90],
        ], self::gqlCalls($fetcher));

        $this->assertSame([], $source->discover('search', ' '));
        $this->assertSame([], $source->discover('completed'));
        $this->assertSame([], (new SuwayomiSource())->discover('popular'), 'the registered instance lists nothing');
    }

    public function testDiscoverPagesWithHasNextPage(): void
    {
        $page2 = json_encode(['data' => ['fetchSourceManga' => ['hasNextPage' => false, 'mangas' => [
            ['id' => 2203, 'title' => 'Berserk', 'url' => '/manga/4', 'thumbnailUrl' => '/api/v1/manga/2203/thumbnail', 'status' => 'ONGOING', 'author' => 'Miura', 'description' => ''],
        ]]]]);
        $fetcher = $this->graphql([SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA => fn(array $v) => match ([$v['input']['type'], $v['input']['page']]) {
            ['POPULAR', 1] => 'suwayomi/fetch-source-manga-popular.json',   // hasNextPage: true
            ['POPULAR', 2] => $page2,
            ['SEARCH', 3] => 'suwayomi/fetch-source-manga-search.json',
        }]);
        $source = new SuwayomiSource(self::MANGAPILL, 'MangaPill', 'en');
        $this->assertInstanceOf(\App\Sources\PagedDiscover::class, $source);

        $first = $source->discoverPage('popular', null, 1);
        $this->assertTrue($first['has_more']);
        $this->assertSame(['One Piece', 'Solo Leveling'], array_column($first['items'], 'name'));
        $this->assertSame($first['items'], $source->discover('popular'), 'discover() is page 1');

        $second = $source->discoverPage('popular', null, 2);
        $this->assertSame(['items' => [['name' => 'Berserk', 'url' => self::BASE . '/manga/2203', 'cover' => self::BASE . '/api/v1/manga/2203/thumbnail',
            'cover_thumb' => '', 'author' => 'Miura', 'description' => '', 'status' => 'Ongoing']], 'has_more' => false], $second);
        $this->assertSame(['Hajime no Ippo', 'Kindaichi 37-sai no Jikenbo'], array_column($source->discoverPage('search', ' hajime ', 3)['items'], 'name'));

        // Each page cached on its own.
        $this->assertSame($second, $source->discoverPage('popular', null, 2));
        $source->discoverPage('popular', null, 1);
        $this->assertSame([
            [SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA, ['input' => ['source' => self::MANGAPILL, 'type' => 'POPULAR', 'page' => 1]], 90],
            [SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA, ['input' => ['source' => self::MANGAPILL, 'type' => 'POPULAR', 'page' => 2]], 90],
            [SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA, ['input' => ['source' => self::MANGAPILL, 'type' => 'SEARCH', 'query' => 'hajime', 'page' => 3]], 90],
        ], self::gqlCalls($fetcher));
    }

    public function testDiscoverPageCap(): void
    {
        $fetcher = $this->graphql([SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA => 'suwayomi/fetch-source-manga-popular.json']);
        $source = new SuwayomiSource(self::MANGAPILL, 'MangaPill', 'en');

        $this->assertFalse($source->discoverPage('popular', null, \App\Sources\PagedDiscover::MAX_PAGE)['has_more'], 'hasNextPage, but the cap');
        $this->assertSame(['items' => [], 'has_more' => false], $source->discoverPage('popular', null, \App\Sources\PagedDiscover::MAX_PAGE + 1));
        $this->assertSame(['items' => [], 'has_more' => false], $source->discoverPage('popular', null, 0));
        $this->assertSame(['items' => [], 'has_more' => false], (new SuwayomiSource())->discoverPage('popular', null, 1), 'the registered instance lists nothing');
        $this->assertCount(1, self::gqlCalls($fetcher));
    }

    public function testDiscoverUnreachableIsNull(): void
    {
        $this->graphql([SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA => 'suwayomi/error.json']);
        $this->assertNull((new SuwayomiSource(self::MANGAPILL, 'MangaPill'))->discover('popular'));
    }

    public function testDiscoverResultsProxyCoversAndMatchTheLibraryByMangaId(): void
    {
        $this->graphql([SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA => 'suwayomi/fetch-source-manga-popular.json']);
        $manga = Novel::create(['name' => 'Something Else', 'group_id' => 1, 'translator_url' => self::BASE . '/manga/2202']);
        $manga->forceFill(['medium' => 'manga'])->save();

        $items = app(Discover::class)->results(new SuwayomiSource(self::MANGAPILL, 'MangaPill'), 'popular');

        $this->assertNull($items[0]['novel_id']);
        $this->assertSame($manga->id, $items[1]['novel_id'], 'matched by manga id');
        $this->assertStringStartsWith(route('discover.cover') . '?u=', $items[0]['cover']);
        $this->assertSame(self::BASE . '/api/v1/manga/2201/thumbnail', urldecode(substr($items[0]['cover'], strlen(route('discover.cover') . '?u='))));
    }

    public function testCoverProxyAcceptsOnlyThumbnailsOfTheConfiguredServer(): void
    {
        $thumbnail = self::BASE . '/api/v1/manga/2132/thumbnail';
        $this->assertTrue(DiscoverCoverController::proxied($thumbnail));
        foreach ([
            self::BASE . '/api/v1/manga/2132/chapter/1/page/0',
            self::BASE . '/api/graphql',
            self::BASE . '/api/v1/manga/2132/thumbnail?x=1',
            self::BASE . '/api/v1/manga/abc/thumbnail',
            'http://192.168.1.38:4567/api/v1/manga/2132/thumbnail',
            'https://192.168.1.37:4567/api/v1/manga/2132/thumbnail',
            'http://evil.example/api/v1/manga/2132/thumbnail',
            'http://uploads.mangadex.org/covers/x.jpg',
        ] as $url) {
            $this->assertFalse(DiscoverCoverController::proxied($url), $url);
        }
        $this->assertTrue(DiscoverCoverController::proxied('https://uploads.mangadex.org/covers/x.jpg'), 'the MangaDex rule is unchanged');

        $fetcher = $this->fake([$thumbnail => 'mangadex/page.jpg']);
        $this->get(route('discover.cover', ['u' => $thumbnail]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame([], $fetcher->calls[0]['headers'], 'no MangaDex User-Agent for Suwayomi');
        $this->get(route('discover.cover', ['u' => self::BASE . '/api/graphql']))->assertNotFound();
        $this->assertCount(1, $fetcher->urls('download'));
    }

    // ---- Web + API v1 with a scoped key ---------------------------------------

    private function discoverUpstream(): FakeFetcher
    {
        return $this->graphql([
            SuwayomiSource::QUERY_SOURCES => 'suwayomi/sources.json',
            SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA => fn(array $v) => match ($v['input']['type']) {
                'SEARCH' => 'suwayomi/fetch-source-manga-search.json',
                'POPULAR' => 'suwayomi/fetch-source-manga-popular.json',
            },
        ]);
    }

    public function testWebDiscoverWithAScopedKey(): void
    {
        $this->discoverUpstream();
        $key = 'suwayomi:' . self::MANGAPILL;

        $html = $this->get('/novels/discover?medium=manga')->assertOk()->getContent();
        $this->assertStringContainsString("<option value=\"{$key}\">Suwayomi · MangaPill</option>", $html);
        $this->assertStringContainsString('"' . $key . '":"192.168.1.37:4567"', $html);

        $items = $this->getJson('/novels/discover/browse?' . http_build_query(['type' => 'search', 'q' => 'hajime', 'source' => $key]))
            ->assertOk()->json('items');
        $this->assertSame('Hajime no Ippo', $items[0]['name']);
        $this->assertSame(self::SERIES, $items[0]['url']);

        $this->getJson('/novels/discover/browse?' . http_build_query(['type' => 'popular', 'source' => 'suwayomi:999']))
            ->assertStatus(422)->assertJsonValidationErrors('source');
    }

    public function testApiDiscoverWithAScopedKey(): void
    {
        $this->discoverUpstream();
        $key = 'suwayomi:' . self::MANGAPILL;

        $sources = collect($this->getJson('/api/v1/discover?medium=manga')->assertOk()->json('sources'))->keyBy('id');
        $this->assertSame(['id' => $key, 'name' => 'Suwayomi · MangaPill', 'host' => '192.168.1.37:4567', 'default' => false,
            'lists' => [['id' => 'popular', 'name' => 'Popular'], ['id' => 'search', 'name' => 'Search']]], $sources[$key]);
        $this->assertNotContains($key, array_column($this->getJson('/api/v1/discover')->json('sources'), 'id'), 'text-only clients never see it');

        $browse = $this->getJson('/api/v1/discover/browse?' . http_build_query(['medium' => 'manga', 'source' => $key, 'type' => 'popular']))->assertOk()->json();
        $this->assertSame(['One Piece', 'Solo Leveling'], array_column($browse['items'], 'title'));
        $this->assertSame($key, $browse['items'][0]['source']);
        $this->assertStringStartsWith(route('discover.cover') . '?u=', $browse['items'][0]['cover_url']);

        $search = $this->postJson('/api/v1/discover/searches', ['medium' => 'manga', 'source' => $key, 'type' => 'search', 'q' => 'hajime'])
            ->assertStatus(202)->json('search');
        $state = $this->getJson("/api/v1/discover/searches/{$search['id']}")->assertOk()->json('search');
        $this->assertSame('done', $state['status']);
        $this->assertSame('Hajime no Ippo', $state['result']['items'][0]['title']);
        $this->assertSame($key, $state['result']['items'][0]['source']);
    }

    // ---- End to end: one chapter downloaded through the manga path ------------

    public function testAChapterDownloadsThroughTheSweep(): void
    {
        Storage::fake('manga');
        config(['novarr.manga.page_delay_ms' => 0]);
        $this->app->instance(HostPace::class, new HostPace(fn(int $lo, int $hi) => $lo));
        $pages = [];
        foreach (['png', 'jpg', 'webp'] as $n => $ext) {
            $pages[self::BASE . "/api/v1/manga/2132/chapter/1/page/{$n}"] = "mangadex/page.{$ext}";
        }
        $fetcher = $this->graphql([
            SuwayomiSource::QUERY_CHAPTER_ID => 'suwayomi/chapter-id.json',
            SuwayomiSource::MUTATION_FETCH_PAGES => 'suwayomi/chapter-pages.json',
            // The source lookup fails: the novel stays on the server's lane.
            SuwayomiSource::QUERY_MANGA_SOURCE => 'suwayomi/error.json',
        ], $pages);

        $group = new Group();
        $group->forceFill(['id' => 9100, 'label' => 'Default', 'url' => 'https://novelping.com'])->save();
        $novel = Novel::create(['name' => 'Hajime no Ippo', 'status' => 0, 'group_id' => 9100, 'translator_url' => self::SERIES]);
        $novel->forceFill(['medium' => 'manga'])->save();
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => self::CHAPTER]);

        $scraper = new class extends ChapterScraper {
            protected function pause(int $seconds): void
            {
            }
        };
        $this->app[Kernel::class]->registerCommand($scraper);

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $chapter->refresh();
        $this->assertTrue($chapter->status, Artisan::output());
        $this->assertSame(3, $chapter->page_count);
        $this->assertSame(['image/png', 'image/jpeg', 'image/webp'], ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->pluck('mime')->all());
        Storage::disk('manga')->assertExists("{$novel->id}/{$chapter->id}/001.png");
        $this->assertSame(array_keys($pages), $fetcher->urls('download'));
        foreach (array_filter($fetcher->calls, fn($c) => $c['method'] === 'download') as $call) {
            $this->assertSame([], $call['headers']);
        }
        $this->assertSame([], array_filter($fetcher->calls, fn($c) => $c['method'] === 'post_json'), 'no MangaDex@Home report');

        // The sweep paces and cools down the LAN host like any other (plain http, a port).
        $this->assertSame(1, app(HostPace::class)->state('192.168.1.37')['clean']);
        $this->assertFalse(ChapterScraper::hostCoolingDown('192.168.1.37'));
    }

    public function testARateLimitedServerCoolsItsHostDown(): void
    {
        Storage::fake('manga');
        $this->graphql([
            SuwayomiSource::QUERY_CHAPTER_ID => FakeFetcher::fail('rate_limited', 429),
            SuwayomiSource::QUERY_MANGA_SOURCE => 'suwayomi/error.json', // source unknown: the server's lane
        ]);
        $group = new Group();
        $group->forceFill(['id' => 9100, 'label' => 'Default', 'url' => 'https://novelping.com'])->save();
        $novel = Novel::create(['name' => 'Hajime no Ippo', 'status' => 0, 'group_id' => 9100, 'translator_url' => self::SERIES]);
        $novel->forceFill(['medium' => 'manga'])->save();
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => self::CHAPTER]);
        $this->app[Kernel::class]->registerCommand(new class extends ChapterScraper {
            protected function pause(int $seconds): void
            {
            }
        });

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame('rate_limited', $chapter->fresh()->last_failure_reason);
        $this->assertTrue(ChapterScraper::hostCoolingDown('192.168.1.37'));
    }
}
