<?php

namespace Tests\Feature;

use App\Novel;
use App\Scraping\Fetcher;
use App\Services\MangaOrigin;
use App\Sources\MangaDexSource;
use App\Sources\SuwayomiSource;
use App\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * A manga's origin from its source (App\Services\MangaOrigin): MangaDex's
 * originalLanguage, a Suwayomi Manga/Manhwa/Manhua genre, and the cached
 * MangaDex title lookup. Fixtures under tests/fixtures/mangadex and
 * tests/fixtures/suwayomi, inline API answers otherwise; no network.
 */
class MangaOriginTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT = '9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2';
    private const API = 'https://api.mangadex.org';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.mangadex.request_delay_ms' => 0]);
    }

    private function manga(string $name, string $url, array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Some Artist',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 0,
            'translator_url' => $url,
            'medium' => Novel::MEDIUM_MANGA,
        ], $attrs));
    }

    private function fake(array $routes = []): FakeFetcher
    {
        $fake = new FakeFetcher($routes);
        app()->instance(Fetcher::class, $fake);

        return $fake;
    }

    private static function searchUrl(string $title): string
    {
        return self::API . '/manga?' . MangaDexSource::query(['title' => $title, 'limit' => 10, 'contentRating' => MangaDexSource::CONTENT_RATINGS]);
    }

    private static function fixtureManga(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/mangadex/manga.json')), true)['data'];
    }

    private static function suwayomiManga(string $file): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures/suwayomi/{$file}")), true)['data']['fetchManga']['manga'];
    }

    // ---- MangaDex ----------------------------------------------------------------

    public function test_language_codes(): void
    {
        $this->assertSame('zh', MangaOrigin::languageCode('zh-hk'));
        $this->assertSame('zh', MangaOrigin::languageCode('ZH'));
        $this->assertSame('ja', MangaOrigin::languageCode('ja-ro'));
        $this->assertSame('ko', MangaOrigin::languageCode('ko'));
        $this->assertSame('pt-br', MangaOrigin::languageCode('pt-br'));
        $this->assertNull(MangaOrigin::languageCode(''));
        $this->assertNull(MangaOrigin::languageCode(null));
    }

    public function test_mangadex_metadata_carries_the_original_language_and_sets_a_source_origin(): void
    {
        $metadata = MangaDexSource::seriesMetadata(self::fixtureManga());
        $this->assertSame('ja', $metadata['original_language']);

        $novel = $this->manga('Invented Series', 'https://mangadex.org/title/' . self::SHORT);
        $found = app(MangaOrigin::class)->fromMetadata($novel, $metadata);

        $this->assertSame('MangaDex original language', $found['reason']);
        $fresh = $novel->fresh();
        $this->assertSame(['translated', 'ja', 'source', 'high'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source, $fresh->origin_confidence]);
        $this->assertSame('JP', $fresh->originCountry());
        $this->assertSame('Japanese manga', $fresh->originCountryLabel());
    }

    public function test_mangadex_korean_chinese_and_other_languages(): void
    {
        $cases = ['ko' => ['ko', 'KR', 'Korean manhwa'], 'zh-hk' => ['zh', 'CN', 'Chinese manhua'], 'en' => ['en', 'EN', 'English original'], 'id' => ['id', null, null]];
        foreach ($cases as $mangadex => [$stored, $code, $label]) {
            $manga = self::fixtureManga();
            $manga['attributes']['originalLanguage'] = $mangadex;
            $novel = $this->manga("Invented {$mangadex}", 'https://mangadex.org/title/' . self::SHORT);

            app(MangaOrigin::class)->fromMetadata($novel, MangaDexSource::seriesMetadata($manga));

            $fresh = $novel->fresh();
            $this->assertSame($stored, $fresh->origin_language, $mangadex);
            $this->assertSame($code, $fresh->originCountry(), $mangadex);
            $this->assertSame($label, $fresh->originCountryLabel(), $mangadex);
        }
    }

    public function test_detect_fetches_mangadex_metadata_when_allowed(): void
    {
        $fake = $this->fake([self::API . '/manga/' . self::SHORT . '?includes[]=cover_art&includes[]=author&includes[]=artist' => 'mangadex/manga.json']);
        $novel = $this->manga('Invented Series', 'https://mangadex.org/title/' . self::SHORT);

        // Without the network a MangaDex series (no deciding tag) stays unknown
        // and is never looked up by title.
        $this->assertNull(app(MangaOrigin::class)->detect($novel, false));
        $this->assertSame([], $fake->calls);

        app(MangaOrigin::class)->detect($novel, true);
        $this->assertSame('JP', $novel->fresh()->originCountry());
        $this->assertCount(1, $fake->calls);
    }

    public function test_a_manual_origin_is_never_replaced_by_the_source(): void
    {
        $novel = $this->manga('Invented Series', 'https://mangadex.org/title/' . self::SHORT, ['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'manual', 'origin_confidence' => 'high']);

        app(MangaOrigin::class)->fromMetadata($novel, MangaDexSource::seriesMetadata(self::fixtureManga()));

        $this->assertSame(['ko', 'manual'], [$novel->fresh()->origin_language, $novel->fresh()->origin_source]);
    }

    // ---- Suwayomi ----------------------------------------------------------------

    public function test_suwayomi_genre_decides_with_medium_confidence(): void
    {
        $metadata = SuwayomiSource::seriesMetadata(self::suwayomiManga('fetch-manga-manhwa.json'));
        $this->assertContains('Manhwa', $metadata['genres']);
        $this->assertArrayNotHasKey('original_language', $metadata);

        $fake = $this->fake();
        $novel = $this->manga('Invented Tower', SuwayomiSource::seriesUrl(2202));
        $found = app(MangaOrigin::class)->fromMetadata($novel, $metadata);

        $this->assertSame('genre tag', $found['reason']);
        $fresh = $novel->fresh();
        $this->assertSame(['translated', 'ko', 'source', 'medium'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source, $fresh->origin_confidence]);
        $this->assertSame('KR', $fresh->originCountry(), 'a source origin shows at medium');
        $this->assertSame([], $fake->calls, 'a deciding genre needs no lookup');
    }

    public function test_suwayomi_without_a_deciding_genre_looks_the_title_up_once(): void
    {
        $answer = json_encode(['result' => 'ok', 'data' => [
            ['id' => 'a', 'attributes' => ['title' => ['en' => 'The Quiet Lantern Returns'], 'altTitles' => [], 'originalLanguage' => 'ja']],
            ['id' => 'b', 'attributes' => ['title' => ['ko-ro' => 'Joyonghan Deung'], 'altTitles' => [['en' => 'The Quiet  Lantern!']], 'originalLanguage' => 'ko']],
        ]]);
        $fake = $this->fake([self::searchUrl('The Quiet Lantern') => $answer]);
        $novel = $this->manga('The Quiet Lantern', SuwayomiSource::seriesUrl(5));

        $found = app(MangaOrigin::class)->fromMetadata($novel, ['genres' => ['Action', 'Drama']]);

        $this->assertSame('MangaDex title match', $found['reason']);
        $this->assertSame(['ko', 'source', 'medium'], [$novel->fresh()->origin_language, $novel->fresh()->origin_source, $novel->fresh()->origin_confidence]);
        $this->assertCount(1, $fake->calls);

        // Cached: a second series of the same title asks nothing.
        $again = $this->manga('the quiet lantern', SuwayomiSource::seriesUrl(6));
        app(MangaOrigin::class)->fromMetadata($again, ['genres' => []]);
        $this->assertSame('KR', $again->fresh()->originCountry());
        $this->assertCount(1, $fake->calls);
        // …and an offline detect reads the same cache.
        $this->assertSame('ko', app(MangaOrigin::class)->lookupTitle('The Quiet Lantern', false));
    }

    public function test_title_lookup_caches_not_found_but_not_a_failure(): void
    {
        $fake = $this->fake([
            self::searchUrl('Paper Orchard') => json_encode(['result' => 'ok', 'data' => [
                ['id' => 'x', 'attributes' => ['title' => ['en' => 'Paper Orchard Two'], 'originalLanguage' => 'ja']],
            ]]),
            self::searchUrl('Glass Harbour') => FakeFetcher::fail('rate_limited', 429),
        ]);
        $lookup = app(MangaOrigin::class);

        $this->assertNull($lookup->lookupTitle('Paper Orchard'), 'only an exact title counts');
        $this->assertNull($lookup->lookupTitle('Paper Orchard'));
        $this->assertCount(1, array_filter($fake->urls(), fn($u) => str_contains($u, 'Paper%20Orchard')), '"not found" is cached');

        $this->assertNull($lookup->lookupTitle('Glass Harbour'));
        $this->assertNull($lookup->lookupTitle('Glass Harbour'));
        $this->assertCount(2, array_filter($fake->urls(), fn($u) => str_contains($u, 'Glass%20Harbour')), 'a failed request is asked again');

        $this->assertNull($lookup->lookupTitle('Never Asked', false), 'offline: cache only');
        $this->assertCount(3, $fake->calls);
    }

    public function test_matches_that_disagree_give_no_language(): void
    {
        $entries = [
            ['attributes' => ['title' => ['en' => 'Twin Bells'], 'originalLanguage' => 'ja']],
            ['attributes' => ['title' => ['en' => 'Twin  Bells'], 'originalLanguage' => 'ko']],
        ];
        $this->assertNull(MangaOrigin::matchLanguage($entries, MangaOrigin::normalise('Twin Bells')));
        $this->assertSame('ja', MangaOrigin::matchLanguage([$entries[0]], MangaOrigin::normalise('twin-bells')));
    }

    // ---- novel:origin --medium=manga -------------------------------------------------

    public function test_the_command_fills_and_with_reinfer_replaces_manga_origins(): void
    {
        $fake = $this->fake();
        $manhua = Tag::create(['name' => 'Manhua']);
        $stale = $this->manga('Invented Stale', SuwayomiSource::seriesUrl(7), ['origin' => 'translated', 'origin_language' => 'ja', 'origin_source' => 'source', 'origin_confidence' => 'medium']);
        $stale->tags()->attach($manhua->id);
        $empty = $this->manga('Invented Empty', SuwayomiSource::seriesUrl(8));
        $empty->tags()->attach($manhua->id);
        $manual = $this->manga('Invented Manual', SuwayomiSource::seriesUrl(9), ['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'manual', 'origin_confidence' => 'high']);
        $manual->tags()->attach($manhua->id);
        $text = Novel::create(['name' => 'Invented Text', 'author' => 'Some Writer', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);

        $this->artisan('novel:origin', ['--medium' => 'manga', '--no-fetch' => true])->assertSuccessful();
        $this->assertSame('ja', $stale->fresh()->origin_language, 'a source origin is kept without --reinfer');
        $this->assertSame(['zh', 'source', 'medium'], [$empty->fresh()->origin_language, $empty->fresh()->origin_source, $empty->fresh()->origin_confidence]);

        $this->artisan('novel:origin', ['--medium' => 'manga', '--reinfer' => true, '--no-fetch' => true])->assertSuccessful();
        $this->assertSame('zh', $stale->fresh()->origin_language);
        $this->assertSame(['ko', 'manual'], [$manual->fresh()->origin_language, $manual->fresh()->origin_source]);
        $this->assertNull($text->fresh()->origin_source, '--medium=manga leaves novels alone');
        $this->assertSame([], $fake->calls);
    }

    public function test_the_command_rejects_an_unknown_medium(): void
    {
        $this->artisan('novel:origin', ['--medium' => 'comics'])->assertFailed();
    }
}
