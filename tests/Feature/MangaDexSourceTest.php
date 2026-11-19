<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Services\Discover;
use App\Sources\MangaDexSource;
use App\Sources\NovelArrowSource;
use App\Sources\SourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFetcher;

/**
 * MangaDexSource against API answers captured from api.mangadex.org
 * (2026-11-17, trimmed; tests/fixtures/mangadex/README.md). Never touches
 * the network.
 */
class MangaDexSourceTest extends GoldenTestCase
{
    use RefreshDatabase;

    private const SERIES = 'a77742b1-befd-49a4-bff5-1ad4e6b0ef7b';
    private const SHORT = '9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2';
    private const CHAPTER = 'fdfb18b6-8f34-4971-afa2-0f9d1e750470';
    private const API = 'https://api.mangadex.org';

    private static function feedUrl(string $id, int $offset): string
    {
        return self::API . "/manga/{$id}/feed?" . MangaDexSource::query(MangaDexSource::feedParams($offset));
    }

    // ---- URLs ----------------------------------------------------------------

    public function testUrlMatchingAndResolution(): void
    {
        $source = new MangaDexSource();
        foreach ([
            'https://mangadex.org/title/' . self::SERIES . '/chainsaw-man',
            'https://www.mangadex.org/title/' . self::SERIES,
            'https://mangadex.org/chapter/' . self::CHAPTER,
        ] as $url) {
            $this->assertTrue($source->matchesUrl($url), $url);
            $this->assertInstanceOf(MangaDexSource::class, SourceResolver::forUrl($url), $url);
        }
        foreach (['https://api.mangadex.org/manga', 'https://notmangadex.org/title/x', 'https://novelping.com/novel/shadow-slave'] as $url) {
            $this->assertFalse($source->matchesUrl($url), $url);
        }
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('https://novelping.com/novel/shadow-slave'));

        $this->assertSame('manga', $source->medium());
        $this->assertSame('novel', (new NovelArrowSource())->medium());
        $this->assertSame('mangadex', array_key_first(SourceResolver::classes()), 'registered first');

        $this->assertSame(self::SERIES, MangaDexSource::seriesId('https://mangadex.org/title/' . strtoupper(self::SERIES) . '/slug'));
        $this->assertNull(MangaDexSource::seriesId('https://mangadex.org/chapter/' . self::CHAPTER));
        $this->assertSame(self::CHAPTER, MangaDexSource::chapterId('https://mangadex.org/chapter/' . self::CHAPTER));
    }

    public function testLibrarySlugIsTheSeriesUuid(): void
    {
        $source = new MangaDexSource();
        $this->assertSame(self::SERIES, $source->librarySlug('https://mangadex.org/title/' . self::SERIES . '/chainsaw-man'));
        $this->assertSame(self::SERIES, $source->librarySlug('https://www.mangadex.org/title/' . self::SERIES, stored: true));
        $this->assertNull($source->librarySlug('https://novelping.com/novel/chainsaw-man'));
    }

    /** Group 1 (the install-id special cases) never rewrites a manga's chapter URL. */
    public function testGroupIdSpecialCasesDoNotTouchAManga(): void
    {
        $group = new \App\Group();
        $group->forceFill(['id' => 1, 'label' => 'Default', 'url' => 'https://novelping.com'])->save();
        $novel = Novel::create(['name' => 'CSM', 'group_id' => 1, 'translator_url' => 'https://mangadex.org/title/' . self::SERIES]);
        $novel->forceFill(['medium' => 'manga', 'alternative_url' => 'https://example.test/read/'])->save();
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://mangadex.org/chapter/' . self::CHAPTER]);

        $this->assertSame('https://mangadex.org/chapter/' . self::CHAPTER, chapterSourceUrl($chapter->fresh()));
        $this->assertInstanceOf(MangaDexSource::class, SourceResolver::for($novel));

        // The same row on a text novel still takes the alternative URL.
        $novel->forceFill(['medium' => 'novel'])->save();
        $this->assertSame('https://example.test/read/1', chapterSourceUrl($chapter->fresh()));
    }

    // ---- TOC -----------------------------------------------------------------

    public function testTableOfContentsPaginatesDeduplicatesAndOrders(): void
    {
        $fetcher = $this->fake([
            self::feedUrl(self::SERIES, 0) => 'mangadex/feed-page-1.json',
            self::feedUrl(self::SERIES, 500) => 'mangadex/feed-page-2.json',
        ]);
        $novel = $this->novel('https://mangadex.org/title/' . self::SERIES . '/chainsaw-man', 'Chainsaw Man');

        $toc = tableOfContentGenerator($novel);

        $this->assertSame([self::feedUrl(self::SERIES, 0), self::feedUrl(self::SERIES, 500)], $fetcher->urls('json'));
        $this->assertFalse(tocRunWasPartial());
        // English only, every content rating, nothing without images.
        $this->assertStringContainsString('translatedLanguage[]=en&contentRating[]=safe&contentRating[]=suggestive&contentRating[]=erotica&contentRating[]=pornographic&includeExternalUrl=0&includeEmptyPages=0', $fetcher->urls('json')[0]);
        // Requests carry MangaDex's honest User-Agent, never the browser one.
        $this->assertSame(['User-Agent' => 'Novarr/1.0'], $fetcher->calls[0]['headers']);
        $this->assertSame([['label' => 'Oneshot', 'chapter' => '0'], ['label' => 'Chapter 1: A Dog and a Chainsaw', 'chapter' => '1'],
            ['label' => "Chapter 2: Pochita's Whereabouts", 'chapter' => '2'], ['label' => 'Chapter 2.5: Omake', 'chapter' => '2.5'],
            ['label' => 'Chapter 3', 'chapter' => '3'], ['label' => 'Chapter 5: Tie Earlier', 'chapter' => '5'],
            ['label' => 'Chapter 10: Ten', 'chapter' => '10']],
            array_map(fn($r) => ['label' => $r['label'], 'chapter' => (string) $r['chapter']], $toc));

        $urls = array_column($toc, 'url');
        // Viz (externalUrl) and the zero-page chapter 4 are skipped; the
        // biggest group (Mangastream) wins chapters 1, 2 and the oneshot;
        // the C/D tie on chapter 5 goes to the earlier upload.
        $this->assertContains('https://mangadex.org/chapter/73af4d8d-1532-4a72-b1b9-8f4e5cd295c9', $urls);
        $this->assertContains('https://mangadex.org/chapter/a00000e1-0000-4000-8000-0000000000e1', $urls);
        $this->assertContains('https://mangadex.org/chapter/d0000005-0000-4000-8000-000000000005', $urls);
        $this->assertNotContains('https://mangadex.org/chapter/6f2b5712-2461-48fd-a519-c2c9cb93f0b1', $urls);
        $this->assertNotContains('https://mangadex.org/chapter/a0000004-0000-4000-8000-000000000004', $urls);
        $this->assertNotContains('https://mangadex.org/chapter/b0000001-0000-4000-8000-000000000001', $urls);
        $this->assertSame([0], array_values(array_unique(array_column($toc, 'book'))), 'volumes never order a manga');
    }

    public function testChapterStatsForARequestCandidate(): void
    {
        $fetcher = $this->fake([
            self::feedUrl(self::SERIES, 0) => 'mangadex/feed-page-1.json',
            self::feedUrl(self::SERIES, 500) => 'mangadex/feed-page-2.json',
        ]);
        $source = new MangaDexSource();

        $stats = $source->chapterStats('https://mangadex.org/title/' . self::SERIES . '/chainsaw-man', ['en']);

        // The TOC's chapters, the newest downloadable one's date, two feed pages.
        $this->assertSame(['0', '1', '2', '2.5', '3', '5', '10'], array_map('strval', $stats['numbers']));
        $this->assertSame(['en'], $stats['languages']);
        $this->assertTrue($stats['in_preferred_language']);
        $this->assertSame('2020-07-05T00:00:00+00:00', $stats['updated_at']);
        $this->assertSame(2, $stats['requests']);
        $this->assertCount(2, $fetcher->urls('json'));
        // Nothing is written to the library, and a TOC run is not marked partial.
        $this->assertSame(0, \App\Novel::count());

        // A preferred language MangaDex is not set up for: its own languages, flagged.
        $this->assertFalse($source->chapterStats('https://mangadex.org/title/' . self::SERIES, ['fr'])['in_preferred_language']);
        $this->assertNull($source->chapterStats('https://example.com/not-mangadex', ['en']));
    }

    public function testChapterStatsFailIsNull(): void
    {
        $this->fake([self::feedUrl(self::SERIES, 0) => FakeFetcher::fail('rate_limited', 429)]);

        $this->assertNull((new MangaDexSource())->chapterStats('https://mangadex.org/title/' . self::SERIES, ['en']));
    }

    public function testTocRowsGoThroughTheRealSyncInChapterOrder(): void
    {
        $this->fake([
            self::feedUrl(self::SERIES, 0) => 'mangadex/feed-page-1.json',
            self::feedUrl(self::SERIES, 500) => 'mangadex/feed-page-2.json',
        ]);
        \App\Group::forceCreate(['id' => 1, 'label' => 'Default', 'url' => 'https://novelping.com']);
        $novel = Novel::create(['name' => 'Chainsaw Man', 'group_id' => 1, 'translator_url' => 'https://mangadex.org/title/' . self::SERIES]);
        $novel->forceFill(['medium' => 'manga'])->save();

        $command = new NovelScraper();
        $command->setLaravel($this->app);
        $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput()));
        $command->processNovel($novel->fresh(), true);

        $rows = NovelChapter::where('novel_id', $novel->id)->ordered()->get();
        $this->assertSame(['Oneshot', 'Chapter 1: A Dog and a Chainsaw', "Chapter 2: Pochita's Whereabouts", 'Chapter 2.5: Omake', 'Chapter 3', 'Chapter 5: Tie Earlier', 'Chapter 10: Ten'], $rows->pluck('label')->all());
        $this->assertSame([0.0, 1.0, 2.0, 2.5, 3.0, 5.0, 10.0], $rows->pluck('chapter')->all());
        $this->assertSame(2.5, $rows[3]->number, 'decimal chapter survives');
        $this->assertSame(0, (int) $rows[3]->part);
        $this->assertSame([0], $rows->pluck('book')->unique()->values()->all());
    }

    public function testLanguagesComeFromConfig(): void
    {
        config(['novarr.mangadex.languages' => ['en', 'pt-br', 'bad lang']]);
        $this->assertSame(['en', 'pt-br'], MangaDexSource::languages());
        $this->assertStringContainsString('translatedLanguage[]=en&translatedLanguage[]=pt-br&', MangaDexSource::query(MangaDexSource::feedParams(0)));
    }

    public function testAFeedPageFailingAfterTheFirstMakesTheRunPartial(): void
    {
        $this->fake([
            self::feedUrl(self::SERIES, 0) => 'mangadex/feed-page-1.json',
            self::feedUrl(self::SERIES, 500) => FakeFetcher::fail('fetch_failed', 500),
        ]);
        $toc = tableOfContentGenerator($this->novel('https://mangadex.org/title/' . self::SERIES, 'Chainsaw Man'));

        $this->assertTrue(tocRunWasPartial());
        $this->assertSame(['1', '2', '2.5'], array_map(fn($r) => (string) $r['chapter'], $toc));
    }

    public function testAFailedFirstFeedPageIsAnEmptyToc(): void
    {
        $this->fake([self::feedUrl(self::SERIES, 0) => FakeFetcher::fail('rate_limited', 429)]);
        $this->assertSame([], tableOfContentGenerator($this->novel('https://mangadex.org/title/' . self::SERIES, 'Chainsaw Man')));
    }

    // ---- Metadata --------------------------------------------------------------

    private function metadataUrl(string $id): string
    {
        return self::API . "/manga/{$id}?includes[]=cover_art&includes[]=author&includes[]=artist";
    }

    public function testMetadata(): void
    {
        $fetcher = $this->fake([$this->metadataUrl(self::SHORT) => 'mangadex/manga.json']);
        $metadata = (new MangaDexSource())->metadata($this->novel('https://mangadex.org/title/' . self::SHORT . '/x', 'Giving Love Advice'));

        $this->assertSame([$this->metadataUrl(self::SHORT)], $fetcher->urls(), 'MangaDex only — never NovelUpdates');
        $this->assertStringContainsString('Alternative Official Raw - X (Formerly Twitter)', $metadata['description']);
        $this->assertStringNotContainsString('**', $metadata['description']);
        $this->assertSame('Yorima Riyo', $metadata['author'], 'author and artist de-duplicated');
        $this->assertSame(2, $metadata['no_of_chapters']);
        $this->assertSame(['Romance', 'School Life', 'Slice Of Life'], $metadata['genres']);
        $cover = 'https://uploads.mangadex.org/covers/' . self::SHORT . '/a27edf78-1ff6-4877-bbfc-ffbc577a8f4c.jpg';
        $this->assertSame([$cover, $cover . '.512.jpg'], $metadata['cover_candidates']);
        $this->assertSame($cover, $metadata['image']);
        $this->assertSame('Completed', $metadata['status_text']);
        $this->assertTrue($metadata['completed']);
        $this->assertSame('rtl', $metadata['reading_mode'], 'Japanese original');
    }

    public function testReadingModeCases(): void
    {
        $manga = json_decode(file_get_contents(base_path('tests/fixtures/mangadex/manga.json')), true)['data'];

        $korean = $manga;
        $korean['attributes']['originalLanguage'] = 'ko';
        $this->assertSame('ltr', MangaDexSource::seriesMetadata($korean)['reading_mode']);

        $strip = $manga;
        $strip['attributes']['tags'][] = ['id' => MangaDexSource::LONG_STRIP_TAG, 'type' => 'tag', 'attributes' => ['name' => ['en' => 'Long Strip'], 'group' => 'format']];
        $this->assertSame('vertical', MangaDexSource::seriesMetadata($strip)['reading_mode'], 'Long Strip wins over a Japanese original');
        $this->assertNotContains('Long Strip', MangaDexSource::seriesMetadata($strip)['genres'], 'format tags are not genres');
    }

    public function testMetadataFailureIsEmpty(): void
    {
        $this->fake([$this->metadataUrl(self::SHORT) => FakeFetcher::fail('http_404', 404)]);
        $metadata = (new MangaDexSource())->metadata($this->novel('https://mangadex.org/title/' . self::SHORT, 'x'));
        $this->assertSame('', $metadata['description']);
        $this->assertSame([], $metadata['cover_candidates']);
        $this->assertArrayNotHasKey('reading_mode', $metadata);
    }

    // ---- Pages -----------------------------------------------------------------

    public function testChapterPagesFromTheAtHomeServer(): void
    {
        $this->fake([self::API . '/at-home/server/' . self::CHAPTER => 'mangadex/at-home.json']);
        $failure = 'stale';
        $pages = (new MangaDexSource())->chapterPages('https://mangadex.org/chapter/' . self::CHAPTER, $failure);

        $this->assertNull($failure);
        $this->assertCount(3, $pages);
        $this->assertSame('https://cmdxd98sb0x3yprd.mangadex.network/data/246a64f8b990f7f329ea1ef6430524d3/1-ade7b56257417a4a57957adccc6ffa527f6c70cc2584d7632903f909563f1d94.png', $pages[0]['url']);
        $this->assertSame(['User-Agent' => 'Novarr/1.0'], $pages[0]['headers']);
    }

    public function testChapterPagesFailures(): void
    {
        $url = 'https://mangadex.org/chapter/' . self::CHAPTER;
        $source = new MangaDexSource();

        $this->fake([self::API . '/at-home/server/' . self::CHAPTER => FakeFetcher::fail('rate_limited', 429)]);
        $this->assertSame([], $source->chapterPages($url, $failure));
        $this->assertSame('rate_limited', $failure);

        $this->fake([self::API . '/at-home/server/' . self::CHAPTER => FakeFetcher::fail('http_404', 404)]);
        $this->assertSame([], $source->chapterPages($url, $failure));
        $this->assertSame('not_found', $failure);

        $this->fake([self::API . '/at-home/server/' . self::CHAPTER => FakeFetcher::fail('exception', null)]);
        $this->assertSame([], $source->chapterPages($url, $failure));
        $this->assertSame('fetch_failed', $failure);

        $this->fake([self::API . '/at-home/server/' . self::CHAPTER => '{"result":"ok","baseUrl":"https://x.mangadex.network","chapter":{"hash":"h","data":[],"dataSaver":[]}}']);
        $this->assertSame([], $source->chapterPages($url, $failure));
        $this->assertSame('no_content', $failure);

        // Not a chapter URL: this source serves no pages for it.
        $this->assertNull($source->chapterPages('https://mangadex.org/title/' . self::SERIES, $failure));
        $this->assertNull((new NovelArrowSource())->chapterPages($url), 'text sources serve no pages');
    }

    /** A 429 from the API host cools down the series host the sweep tracks. */
    public function testRateLimitRetryAfterMovesToTheSiteHost(): void
    {
        Fetcher::pullRetryAfter('mangadex.org');
        $this->fake([self::API . '/at-home/server/' . self::CHAPTER => FakeFetcher::fail('rate_limited', 429)]);
        $recorder = new class extends Fetcher {
            public static function record(string $url, int $s): void { self::recordRetryAfter($url, $s); }
        };
        $recorder::record(self::API . '/at-home/server/x', 42);

        (new MangaDexSource())->chapterPages('https://mangadex.org/chapter/' . self::CHAPTER, $failure);

        $this->assertSame(42, Fetcher::pullRetryAfter('mangadex.org'));
        $this->assertNull(Fetcher::pullRetryAfter('api.mangadex.org'));
    }

    public function testRetryAfterFromMangaDexRateLimitHeaders(): void
    {
        $this->assertSame(30, Fetcher::retryAfterFromHeaders(['retry-after' => ['30']]));
        $this->assertEqualsWithDelta(60, Fetcher::retryAfterFromHeaders(['x-ratelimit-retry-after' => [(string) (time() + 60)]]), 2);
        $this->assertNull(Fetcher::retryAfterFromHeaders([]));
    }

    public function testImageReportOnlyForAtHomeNodes(): void
    {
        $fetcher = $this->fake([]);
        $source = new MangaDexSource();
        $source->reportPageFetch(['url' => 'https://cmdxd98sb0x3yprd.mangadex.network/data/h/1.png', 'headers' => []], true, 1234, 56, true);
        $source->reportPageFetch(['url' => 'https://uploads.mangadex.org/data/h/1.png', 'headers' => []], true, 1234, 56, false);

        $reports = array_values(array_filter($fetcher->calls, fn($c) => $c['method'] === 'post_json'));
        $this->assertCount(1, $reports);
        $this->assertSame('https://api.mangadex.network/report', $reports[0]['url']);
        $this->assertSame(['url' => 'https://cmdxd98sb0x3yprd.mangadex.network/data/h/1.png', 'success' => true, 'cached' => true, 'bytes' => 1234, 'duration' => 56], $reports[0]['body']);
    }

    // ---- Discover --------------------------------------------------------------

    public function testDiscoverPopularAndSearch(): void
    {
        $source = new MangaDexSource();
        $this->assertSame(['search', 'popular'], $source->discoverLists());

        $popular = self::API . '/manga?limit=40&order[followedCount]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
        $search = self::API . '/manga?limit=40&title=tabun%20kanojo&order[relevance]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
        $fetcher = $this->fake([$popular => 'mangadex/discover-popular.json', $search => 'mangadex/discover-search.json']);
        $checks = $this->routeReadable($fetcher, [
            '32d76d19-8a05-4db0-9fc2-e0b0648fe9d0' => 200,
            'aa6c76f7-5f5f-46b6-a800-911145f81b9b' => 110,
            '18d481be-f310-4088-a9c1-de29e08990cd' => 3,
            '95142415-c448-4a00-966b-676925721bd7' => 1,
        ]);

        $items = $source->discover('popular');
        $this->assertSame(['Solo Leveling', 'My Dress-Up Darling'], array_column($items, 'name'));
        $this->assertSame('https://mangadex.org/title/32d76d19-8a05-4db0-9fc2-e0b0648fe9d0', $items[0]['url']);
        $this->assertSame('https://uploads.mangadex.org/covers/32d76d19-8a05-4db0-9fc2-e0b0648fe9d0/e90bdc47-c8b9-4df7-b2c0-17641b645ee1.jpg.256.jpg', $items[0]['cover']);
        $this->assertSame('Completed', $items[0]['status']);
        $this->assertSame(200, $items[0]['chapters']);
        $this->assertStringContainsString('Chugong', $items[0]['author']);
        $this->assertStringStartsWith('10 years ago', $items[0]['description']);

        $found = $source->discover('search', 'tabun kanojo');
        $this->assertNotEmpty($found);
        foreach ($found as $item) {
            $this->assertMatchesRegularExpression('#^https://mangadex\.org/title/' . MangaDexSource::UUID . '$#', $item['url']);
        }

        // Cached: a second popular read makes no request.
        $source->discover('popular');
        $this->assertSame([$popular, $checks[0], $checks[1], $search, $checks[2], $checks[3]], $fetcher->urls());

        $this->assertSame([], $source->discover('completed'));
    }

    /** Route the per-series "has downloadable chapters" checks; returns their URLs. */
    private function routeReadable($fetcher, array $totals): array
    {
        $urls = [];
        foreach ($totals as $id => $total) {
            $urls[] = $url = self::API . '/chapter?' . MangaDexSource::query(MangaDexSource::readableParams($id));
            $fetcher->route($url, is_array($total) ? $total : json_encode(['result' => 'ok', 'data' => [], 'total' => $total]));
        }

        return $urls;
    }

    public function testDiscoverHidesSeriesWithNothingToDownload(): void
    {
        // MangaDex still lists English for a series whose chapters were
        // taken down; a failed check keeps the item.
        $popular = self::API . '/manga?limit=40&order[followedCount]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
        $fetcher = $this->fake([$popular => 'mangadex/discover-popular.json']);
        $this->routeReadable($fetcher, ['32d76d19-8a05-4db0-9fc2-e0b0648fe9d0' => 0, 'aa6c76f7-5f5f-46b6-a800-911145f81b9b' => 110]);
        $this->assertSame(['My Dress-Up Darling'], array_column((new MangaDexSource())->discover('popular'), 'name'));

        \Illuminate\Support\Facades\Cache::flush();
        $this->routeReadable($fetcher, ['32d76d19-8a05-4db0-9fc2-e0b0648fe9d0' => \Tests\Support\FakeFetcher::fail('http_503', 503)]);
        $this->assertSame(['Solo Leveling', 'My Dress-Up Darling'], array_column((new MangaDexSource())->discover('popular'), 'name'));
    }

    public function testDiscoverCoversAreProxiedAndMangaNeverMatchesANovelOfTheSameName(): void
    {
        $popular = self::API . '/manga?limit=40&order[followedCount]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
        $this->routeReadable($this->fake([$popular => 'mangadex/discover-popular.json']), ['32d76d19-8a05-4db0-9fc2-e0b0648fe9d0' => 200, 'aa6c76f7-5f5f-46b6-a800-911145f81b9b' => 110]);
        Novel::create(['name' => 'Solo Leveling', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/solo-leveling']);
        $manga = Novel::create(['name' => 'Something Else', 'group_id' => 1, 'translator_url' => 'https://mangadex.org/title/aa6c76f7-5f5f-46b6-a800-911145f81b9b/my-dress-up-darling']);
        $manga->forceFill(['medium' => 'manga'])->save();

        $items = app(Discover::class)->results(new MangaDexSource(), 'popular');

        $this->assertFalse($items[0]['in_library'], 'the Solo Leveling novel is not the manga');
        $this->assertSame($manga->id, $items[1]['novel_id'], 'matched by series uuid');
        $this->assertStringStartsWith(route('discover.cover') . '?u=', $items[0]['cover']);
        $this->assertSame('https://uploads.mangadex.org/covers/32d76d19-8a05-4db0-9fc2-e0b0648fe9d0/e90bdc47-c8b9-4df7-b2c0-17641b645ee1.jpg.256.jpg', urldecode(substr($items[0]['cover'], strlen(route('discover.cover') . '?u='))));
    }

    // ---- Discover paging ----------------------------------------------------------

    /** The popular list's URL at an offset (page 1 carries none). */
    private static function popularUrl(int $offset = 0): string
    {
        return self::API . '/manga?limit=40' . ($offset > 0 ? "&offset={$offset}" : '')
            . '&order[followedCount]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
    }

    /** discover-popular.json answered at another offset / total. */
    private static function popularPage(int $offset, int $total): string
    {
        $json = json_decode(FakeFetcher::body('mangadex/discover-popular.json'), true);

        return json_encode(['offset' => $offset, 'total' => $total] + $json);
    }

    public function testDiscoverPagesByOffsetWithHasMoreFromTheTotal(): void
    {
        $source = new MangaDexSource();
        $this->assertInstanceOf(\App\Sources\PagedDiscover::class, $source);
        $fetcher = $this->fake([
            self::popularUrl() => 'mangadex/discover-popular.json',     // total 86342
            self::popularUrl(40) => self::popularPage(40, 120),
            self::popularUrl(80) => self::popularPage(80, 120),
        ]);
        $this->routeReadable($fetcher, ['32d76d19-8a05-4db0-9fc2-e0b0648fe9d0' => 200, 'aa6c76f7-5f5f-46b6-a800-911145f81b9b' => 110]);

        $first = $source->discoverPage('popular', null, 1);
        $this->assertTrue($first['has_more']);
        $this->assertSame(['Solo Leveling', 'My Dress-Up Darling'], array_column($first['items'], 'name'));
        $this->assertSame($first['items'], $source->discover('popular'), 'discover() is page 1');

        // Page 2 is its own request (not page 1's cache): offset 40, more until 120.
        $second = $source->discoverPage('popular', null, 2);
        $this->assertTrue($second['has_more'], '40 + 40 < 120');
        $third = $source->discoverPage('popular', null, 3);
        $this->assertFalse($third['has_more'], '80 + 40 = 120: the last page');

        // Each page cached on its own: no further requests.
        $this->assertSame($second, $source->discoverPage('popular', null, 2));
        $source->discoverPage('popular', null, 1);
        $this->assertSame([self::popularUrl(), self::popularUrl(40), self::popularUrl(80)], array_values(array_filter(
            $fetcher->urls(), fn($u) => str_starts_with($u, self::API . '/manga?')
        )));
    }

    public function testDiscoverPageSearchHasNoMoreWhenTheTotalFitsOnePage(): void
    {
        $search = self::API . '/manga?limit=40&title=tabun%20kanojo&order[relevance]=desc&includes[]=cover_art&includes[]=author&contentRating[]=safe&contentRating[]=suggestive&availableTranslatedLanguage[]=en&hasAvailableChapters=true';
        $search2 = str_replace('limit=40&', 'limit=40&offset=40&', $search);
        $fetcher = $this->fake([$search => 'mangadex/discover-search.json', $search2 => self::popularPage(40, 2)]);
        $this->routeReadable($fetcher, ['18d481be-f310-4088-a9c1-de29e08990cd' => 3, '95142415-c448-4a00-966b-676925721bd7' => 1]);

        $page = (new MangaDexSource())->discoverPage('search', ' tabun kanojo ', 1);
        $this->assertFalse($page['has_more'], 'total 2');
        $this->assertCount(2, $page['items']);
        $this->assertSame(['items' => [], 'has_more' => false], (new MangaDexSource())->discoverPage('search', '  ', 1));
        $this->assertSame(['items' => [], 'has_more' => false], (new MangaDexSource())->discoverPage('completed', null, 1));
    }

    public function testDiscoverPageThatLosesEveryItemToTheReadableFilterStillHasMore(): void
    {
        $fetcher = $this->fake([self::popularUrl(40) => self::popularPage(40, 5000)]);
        $this->routeReadable($fetcher, ['32d76d19-8a05-4db0-9fc2-e0b0648fe9d0' => 0, 'aa6c76f7-5f5f-46b6-a800-911145f81b9b' => 0]);

        $this->assertSame(['items' => [], 'has_more' => true], (new MangaDexSource())->discoverPage('popular', null, 2));
    }

    public function testDiscoverPageCapAndWindow(): void
    {
        $fetcher = $this->fake([self::popularUrl(1960) => self::popularPage(1960, 86342)]);
        $this->routeReadable($fetcher, ['32d76d19-8a05-4db0-9fc2-e0b0648fe9d0' => 200, 'aa6c76f7-5f5f-46b6-a800-911145f81b9b' => 110]);
        $source = new MangaDexSource();

        $last = $source->discoverPage('popular', null, \App\Sources\PagedDiscover::MAX_PAGE);
        $this->assertCount(2, $last['items']);
        $this->assertFalse($last['has_more'], 'nothing past the cap');

        $calls = count($fetcher->calls);
        $this->assertSame(['items' => [], 'has_more' => false], $source->discoverPage('popular', null, \App\Sources\PagedDiscover::MAX_PAGE + 1));
        $this->assertSame(['items' => [], 'has_more' => false], $source->discoverPage('popular', null, 0));
        $this->assertCount($calls, $fetcher->calls, 'no request beyond the cap');
    }

    public function testDiscoverPageUnreachableIsNull(): void
    {
        $this->fake([self::popularUrl(40) => FakeFetcher::fail('http_503', 503)]);
        $this->assertNull((new MangaDexSource())->discoverPage('popular', null, 2));
    }

    public function testDiscoverCoverProxyServesOnlyAllowedHosts(): void
    {
        $cover = 'https://uploads.mangadex.org/covers/32d76d19-8a05-4db0-9fc2-e0b0648fe9d0/e90bdc47.jpg.256.jpg';
        $fetcher = $this->fake([$cover => 'mangadex/page.jpg']);

        $response = $this->get(route('discover.cover', ['u' => $cover]));
        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
        $this->assertSame(['User-Agent' => 'Novarr/1.0'], $fetcher->calls[0]['headers']);

        // Cached: no second fetch.
        $this->get(route('discover.cover', ['u' => $cover]))->assertOk();
        $this->assertCount(1, $fetcher->urls('download'));

        $this->get(route('discover.cover', ['u' => 'https://evil.example/x.jpg']))->assertNotFound();
        $this->get(route('discover.cover', ['u' => 'http://uploads.mangadex.org/x.jpg']))->assertNotFound();
        $this->get(route('discover.cover'))->assertNotFound();
    }

    public function testDiscoverPageListsMangaDexAfterTheNovelSources(): void
    {
        $sources = app(Discover::class)->sources();
        $this->assertSame('mangadex', array_key_last($sources));
        $this->assertNotSame('mangadex', array_key_first($sources));
        $this->assertSame(['mangadex'], array_keys(app(Discover::class)->sourcesFor('manga')));
        $this->assertNotContains('mangadex', array_keys(app(Discover::class)->sourcesFor('novel')));
    }
}
