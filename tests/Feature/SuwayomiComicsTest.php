<?php

namespace Tests\Feature;

use App\Comics\SuwayomiComicFinder;
use App\Jobs\FindRequestCandidates;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelRequest;
use App\Services\Discover;
use App\Services\Requests\RequestRefused;
use App\Services\Requests\Requests;
use App\Services\Requests\RequestSearch;
use App\Sources\ComicCatalogueSource;
use App\Sources\SourceResolver;
use App\Sources\SuwayomiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeFetcher;
use Tests\Support\UsesAccounts;

/**
 * Comics through Suwayomi's comic sources (novarr.suwayomi.comic_sources):
 * their series are comics, a comic request is looked up on them, and a
 * clear match is added and downloaded (Requests::findComic()).
 */
class SuwayomiComicsTest extends GoldenTestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const BASE = 'http://192.168.1.37:4567';
    private const GQL = self::BASE . '/api/graphql';
    private const RCO_LOL = '3920948196044407056';
    private const RCO = '7185601298150078890';
    private const MANGAPILL = '8448310129093543312';

    /** Search answers per Suwayomi source id: [[id, title], …]. */
    private array $searches = [];

    /** Manga id => its Suwayomi source name (QUERY_MANGA_SOURCE). */
    private array $mangaSources = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        Bus::fake([RunNovelCommand::class, \App\Jobs\FinishNovelCheck::class]);
        config([
            'novarr.suwayomi.url' => self::BASE,
            'novarr.suwayomi.languages' => ['en'],
            'novarr.suwayomi.exclude_sources' => ['MangaDex', 'Local source'],
            'novarr.suwayomi.comic_sources' => ['ReadComicsOnline.lol', 'Read Comics Online'],
            'novarr.suwayomi.comic_auto_add' => true,
            'novarr.comicvine.api_key' => null,
        ]);
        $sources = json_encode(['data' => ['sources' => ['nodes' => [
            ['id' => self::MANGAPILL, 'name' => 'MangaPill', 'lang' => 'en', 'isNsfw' => false, 'supportsLatest' => true],
            ['id' => self::RCO, 'name' => 'Read Comics Online', 'lang' => 'en', 'isNsfw' => false, 'supportsLatest' => true],
            ['id' => self::RCO_LOL, 'name' => 'ReadComicsOnline.lol', 'lang' => 'en', 'isNsfw' => false, 'supportsLatest' => true],
        ]]]]);
        $this->fake([self::GQL => function (array $body) use ($sources) {
            $v = $body['variables'] ?? [];

            return match ($body['query']) {
                SuwayomiSource::QUERY_SOURCES => $sources,
                SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA => json_encode(['data' => ['fetchSourceManga' => ['hasNextPage' => false, 'mangas' => array_map(
                    fn(array $m) => ['id' => $m[0], 'title' => $m[1], 'url' => '/x', 'thumbnailUrl' => "/api/v1/manga/{$m[0]}/thumbnail", 'status' => 'ONGOING', 'author' => '', 'description' => ''],
                    $this->searches[$v['input']['source']] ?? []
                )]]]),
                SuwayomiSource::QUERY_MANGA_SOURCE => isset($this->mangaSources[$v['id']])
                    ? json_encode(['data' => ['manga' => ['sourceId' => '1', 'source' => ['name' => $this->mangaSources[$v['id']]]]]])
                    : 'suwayomi/error.json',
                default => null,
            };
        }]);
    }

    private function comicRequest(string $title, array $candidates = []): NovelRequest
    {
        $this->enableSignIn();

        return NovelRequest::create([
            'user_id' => $this->makeReader()->id, 'medium' => Novel::MEDIUM_COMIC, 'title' => $title,
            'normalized_title' => mb_strtolower($title), 'status' => NovelRequest::STATUS_PENDING, 'candidates' => $candidates,
        ]);
    }

    // ---- Titles and runs -------------------------------------------------------

    public function test_titles_without_their_run_years_and_the_pick(): void
    {
        $this->assertSame('absolute batman', SuwayomiComicFinder::titleKey('Absolute Batman (2024-)'));
        $this->assertSame('absolute batman', SuwayomiComicFinder::titleKey('Absolute Batman'));
        $this->assertSame('batman 66', SuwayomiComicFinder::titleKey("Batman '66 [I]"));
        $this->assertSame('batman 2099', SuwayomiComicFinder::titleKey('Batman 2099'), 'a year outside brackets is part of the title');
        $this->assertSame(2011, SuwayomiComicFinder::year('Batman (2011 - 2016)'));
        $this->assertNull(SuwayomiComicFinder::year('Batman 2099'));

        $m = fn(string $source, ?int $year) => ['source' => $source, 'year' => $year];
        $this->assertNull(SuwayomiComicFinder::pick([], null));
        $this->assertSame('a', SuwayomiComicFinder::pick([$m('a', null), $m('b', 2024)], null)['source'], 'one run: the first source');
        $this->assertNull(SuwayomiComicFinder::pick([$m('a', 2016), $m('b', 2011)], null), 'several runs and no year: the owner decides');
        $this->assertSame('b', SuwayomiComicFinder::pick([$m('a', null), $m('b', 2011)], 2011)['source'], 'the wanted year first');
    }

    // ---- The source's medium ---------------------------------------------------

    public function test_comic_sources_are_comics_on_discover_and_their_series_are_comics(): void
    {
        $this->assertSame(Novel::MEDIUM_COMIC, (new SuwayomiSource(self::RCO_LOL, 'ReadComicsOnline.lol', 'en'))->medium());
        $this->assertSame(Novel::MEDIUM_MANGA, (new SuwayomiSource(self::MANGAPILL, 'MangaPill', 'en'))->medium());
        $this->assertSame(Novel::MEDIUM_MANGA, (new SuwayomiSource())->medium());

        $discover = app(Discover::class);
        $this->assertSame(['suwayomi:' . self::RCO_LOL, 'suwayomi:' . self::RCO], array_keys(RequestSearch::suwayomiComicSources($discover)), 'in the configured order');
        $this->assertNotContains('suwayomi:' . self::RCO, array_keys($discover->sourcesFor(Novel::MEDIUM_MANGA)));
        $this->assertSame([ComicCatalogueSource::KEY, 'suwayomi:' . self::RCO_LOL, 'suwayomi:' . self::RCO], array_keys(app(RequestSearch::class)->sources($discover, Novel::MEDIUM_COMIC)));

        $this->mangaSources = [7166 => 'ReadComicsOnline.lol', 2132 => 'MangaPill'];
        $this->assertSame(Novel::MEDIUM_COMIC, SourceResolver::mediumForUrl(self::BASE . '/manga/7166'));
        $this->assertSame(Novel::MEDIUM_MANGA, SourceResolver::mediumForUrl(self::BASE . '/manga/2132'));
        $this->assertSame(Novel::MEDIUM_MANGA, SourceResolver::mediumForUrl(self::BASE . '/manga/999'), 'unknown: manga');
        $this->assertSame(Novel::MEDIUM_NOVEL, SourceResolver::mediumForUrl('https://novelping.com/novel/x'));

        // A comic source's series reads left to right.
        $this->assertSame('ltr', SuwayomiSource::seriesMetadata(['id' => 7166, 'title' => 'Absolute Batman', 'genre' => ['Superhero'], 'source' => ['name' => 'ReadComicsOnline.lol']])['reading_mode']);
        $this->assertSame('rtl', SuwayomiSource::seriesMetadata(['id' => 2132, 'title' => 'Ippo', 'genre' => [], 'source' => ['name' => 'MangaPill']])['reading_mode']);
    }

    // ---- Requests --------------------------------------------------------------

    public function test_a_comic_request_is_found_added_as_a_comic_and_starts_downloading(): void
    {
        $this->searches = [
            self::RCO_LOL => [[7166, 'Absolute Batman'], [7170, 'Absolute Batman: Ark M Special']],
            self::RCO => [[8001, 'Absolute Batman (2024-)']],
        ];
        $this->mangaSources = [8001 => 'Read Comics Online'];
        $request = $this->comicRequest('Absolute Batman', [
            ['source' => ComicCatalogueSource::KEY, 'source_name' => 'Comic Vine', 'url' => 'https://comicvine.gamespot.com/absolute-batman/4050-1/', 'title' => 'Absolute Batman', 'status' => '2024'],
        ]);

        (new FindRequestCandidates($request->id))->handle(app(RequestSearch::class), app(Discover::class), app(\App\Services\Requests\CandidateEvaluator::class));

        $request->refresh();
        $this->assertSame(NovelRequest::STATUS_APPROVED, $request->status);
        $novel = Novel::find($request->novel_id);
        $this->assertSame(Novel::MEDIUM_COMIC, $novel->medium);
        // Comic Vine says 2024: the run whose title confirms it wins over an
        // undated title on the preferred source.
        $this->assertSame(self::BASE . '/manga/8001', $novel->translator_url);
        $this->assertSame(
            [ComicCatalogueSource::KEY, 'suwayomi:' . self::RCO_LOL, 'suwayomi:' . self::RCO],
            array_column($request->candidateList(), 'source'),
            'both matches kept as candidates, the Ark M special left out'
        );
        $this->assertSame((int) $this->owner()->id, (int) $request->decided_by);
        Bus::assertDispatched(RunNovelCommand::class, fn($job) => str_contains(json_encode($job), 'novel:metadata'));
    }

    public function test_several_runs_without_a_year_are_left_to_the_owner(): void
    {
        $this->searches = [self::RCO => [[9001, 'Batman (2016-)'], [9002, 'Batman (2011 - 2016)'], [9003, 'Batman Beyond']]];
        $request = $this->comicRequest('Batman');

        $this->assertNull(app(Requests::class)->findComic($request));

        $request->refresh();
        $this->assertTrue($request->isPending());
        $this->assertSame(['Batman (2016-)', 'Batman (2011 - 2016)'], array_column($request->candidateList(), 'title'));
        $this->assertSame(0, Novel::count());

        // The owner adds one of them: a Suwayomi candidate can be approved.
        $outcome = app(Requests::class)->approve($request, $this->owner(), ['candidate' => 1]);
        $this->assertSame(Novel::MEDIUM_MANGA, $outcome['novel']->medium, 'the server did not say its source (fake): manga');
        $this->assertSame(self::BASE . '/manga/9002', $outcome['novel']->translator_url);
    }

    public function test_the_year_in_the_request_picks_the_run(): void
    {
        $this->searches = [self::RCO => [[9001, 'Batman (2016-)'], [9002, 'Batman (2011 - 2016)']]];
        $this->mangaSources = [9002 => 'Read Comics Online'];
        $request = $this->comicRequest('Batman (2011)');

        $outcome = app(Requests::class)->findComic($request);

        $this->assertSame(self::BASE . '/manga/9002', $outcome['novel']->translator_url);
        $this->assertSame(Novel::MEDIUM_COMIC, $outcome['novel']->medium);
        $this->assertSame(['Batman (2011 - 2016)'], array_column($request->fresh()->candidateList(), 'title'));
    }

    public function test_nothing_found_or_auto_add_off_leaves_the_request_pending(): void
    {
        $request = $this->comicRequest('Saga');
        $this->assertNull(app(Requests::class)->findComic($request));
        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame([], $request->fresh()->candidateList());

        config(['novarr.suwayomi.comic_auto_add' => false]);
        $this->searches = [self::RCO_LOL => [[7166, 'Saga']]];
        Cache::flush(); // the empty search above is cached ten minutes
        $this->assertNull(app(Requests::class)->findComic($request));
        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(['Saga'], array_column($request->fresh()->candidateList(), 'title'));
    }

    public function test_a_catalogue_candidate_still_cannot_be_approved(): void
    {
        $request = $this->comicRequest('Saga', [
            ['source' => ComicCatalogueSource::KEY, 'source_name' => 'Comic Vine', 'url' => 'https://comicvine.gamespot.com/saga/4050-1/', 'title' => 'Saga', 'status' => '2012'],
        ]);

        try {
            app(Requests::class)->approve($request, $this->owner(), ['candidate' => 0]);
            $this->fail('approved a catalogue candidate');
        } catch (RequestRefused $e) {
            $this->assertSame('comic_import', $e->errorCode);
        }
    }

    public function test_a_new_comic_request_queues_the_lookup_and_none_without_comic_sources(): void
    {
        Bus::fake([FindRequestCandidates::class, RunNovelCommand::class]);
        $reader = $this->makeReader('kim', 'Kim');
        $this->enableSignIn();

        app(Requests::class)->createFreeText($reader, 'Saga', Novel::MEDIUM_COMIC, null, null);
        Bus::assertDispatched(FindRequestCandidates::class);

        config(['novarr.suwayomi.comic_sources' => []]);
        Bus::fake([FindRequestCandidates::class]);
        app(Requests::class)->createFreeText($reader, 'Paper Girls', Novel::MEDIUM_COMIC, null, null);
        Bus::assertNotDispatched(FindRequestCandidates::class);
    }
}
