<?php

namespace Tests\Feature;

use App\ChapterPage;
use App\Console\Commands\FillMissingMangaChapters;
use App\Group;
use App\MangaAlternate;
use App\MangaAlternateSearch;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Scraping\MangaFallback;
use App\Scraping\SourceUnavailable;
use App\Services\DownloadLanes;
use App\Sources\SuwayomiSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * The manga fallback (App\Scraping\MangaFallback, manga:fill-missing)
 * against a fake Suwayomi server: one GraphQL endpoint answering from
 * $this->server (sources, searches, chapter lists, page lists) plus the
 * page images. MangaDex (direct) answers every search with nothing.
 */
class MangaFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://192.168.1.37:4567';
    private const GQL = self::BASE . '/api/graphql';

    // Suwayomi sources: the series' own, and three others.
    private const TCB = '100';
    private const WEEB = '200';
    private const FOX = '300';
    private const PILL = '400';

    /**
     * The fake server: search[sourceId] = [[mangaId, title], …];
     * chapters[mangaId] = [[number, sourceOrder, scanlator?], …];
     * pages[mangaId][sourceOrder] = page count (default 3);
     * titles[mangaId] = the title Suwayomi stores.
     */
    private array $server = ['search' => [], 'chapters' => [], 'pages' => [], 'titles' => []];

    private FakeFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manga');
        config([
            'novarr.suwayomi.url' => self::BASE,
            'novarr.suwayomi.languages' => ['en'],
            'novarr.suwayomi.exclude_sources' => [],
            'novarr.manga.page_delay_ms' => 0,
            'novarr.mangadex.request_delay_ms' => 0,
            'novarr.requests.source_order' => [],
            'novarr.requests.source_name_preference' => ['MangaDex', 'Weeb Central', 'MangaFox', 'MangaPill'],
            'novarr.requests.source_families' => [],
        ]);

        $group = new Group();
        $group->forceFill(['id' => 9200, 'label' => 'Default', 'url' => self::BASE])->save();

        $this->fetcher = new FakeFetcher(
            [self::GQL => fn(array $body) => $this->answer($body['query'], $body['variables'] ?? [])],
            [
                self::BASE . '/api/v1/manga/' => 'mangadex/page.png',
                'https://api.mangadex.org/manga?' => '{"result":"ok","data":[],"total":0,"limit":40,"offset":0}',
            ]
        );
        $this->app->instance(Fetcher::class, $this->fetcher);

        // No real waiting between chapters.
        $this->app->instance(MangaFallback::class, new class extends MangaFallback {
            public array $paused = [];

            protected function pause(int $seconds): void
            {
                $this->paused[] = $seconds;
            }
        });
    }

    // ---- The fake Suwayomi server ------------------------------------------------

    private function answer(string $query, array $v): string|array
    {
        $json = fn(array $data) => json_encode(['data' => $data]);

        switch ($query) {
            case SuwayomiSource::QUERY_SOURCES:
                $nodes = [];
                foreach ([self::TCB => 'TCB Scans', self::WEEB => 'Weeb Central', self::FOX => 'MangaFox', self::PILL => 'MangaPill'] as $id => $name) {
                    $nodes[] = ['id' => (string) $id, 'name' => $name, 'lang' => 'en', 'isNsfw' => false, 'supportsLatest' => true];
                }
                return $json(['sources' => ['nodes' => $nodes]]);

            case SuwayomiSource::QUERY_MANGA_TITLE:
                $title = $this->server['titles'][$v['id']] ?? null;
                return $json(['manga' => $title === null ? null : ['title' => $title]]);

            case SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA:
                $source = $v['input']['source'];
                if (($this->server['search'][$source] ?? null) === 'fail') {
                    return FakeFetcher::fail('exception', null);
                }
                $mangas = array_map(fn($m) => ['id' => $m[0], 'title' => $m[1], 'url' => '/x', 'thumbnailUrl' => '', 'inLibrary' => false,
                    'status' => 'ONGOING', 'author' => null, 'genre' => [], 'description' => null], $this->server['search'][$source] ?? []);
                return $json(['fetchSourceManga' => ['hasNextPage' => false, 'mangas' => $mangas]]);

            case SuwayomiSource::MUTATION_FETCH_CHAPTERS:
                $id = $v['input']['mangaId'];
                if (($this->server['chapters'][$id] ?? null) === 'fail') {
                    return FakeFetcher::fail('exception', null);
                }
                $chapters = [];
                foreach ($this->server['chapters'][$id] ?? [] as $i => $c) {
                    $chapters[] = ['id' => $id * 1000 + $c[1], 'name' => 'Chapter ' . $c[0], 'chapterNumber' => $c[0],
                        'sourceOrder' => $c[1], 'scanlator' => $c[2] ?? 'Group', 'uploadDate' => (string) (1700000000000 + $i)];
                }
                return $json(['fetchChapters' => ['chapters' => $chapters]]);

            case SuwayomiSource::QUERY_CHAPTER_ID:
                $known = false;
                foreach ($this->server['chapters'][$v['mangaId']] ?? [] as $c) {
                    $known = $known || $c[1] === $v['sourceOrder'];
                }
                return $json(['chapters' => ['nodes' => $known ? [['id' => $v['mangaId'] * 1000 + $v['sourceOrder']]] : []]]);

            case SuwayomiSource::MUTATION_FETCH_PAGES:
                $mangaId = intdiv($v['input']['chapterId'], 1000);
                $order = $v['input']['chapterId'] % 1000;
                $count = $this->server['pages'][$mangaId][$order] ?? 3;
                $paths = [];
                for ($n = 0; $n < $count; $n++) {
                    $paths[] = "/api/v1/manga/{$mangaId}/chapter/{$order}/page/{$n}";
                }
                return $json(['fetchChapterPages' => ['pages' => $paths]]);
        }

        throw new \RuntimeException("unrouted GraphQL query: {$query}");
    }

    /** GraphQL calls of one query. */
    private function calls(string $query): array
    {
        return array_values(array_filter($this->fetcher->calls, fn($c) => $c['method'] === 'post_json_for_json' && $c['body']['query'] === $query));
    }

    private function searchesOf(string $sourceId): int
    {
        return count(array_filter($this->calls(SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA), fn($c) => $c['body']['variables']['input']['source'] === $sourceId));
    }

    // ---- Library -------------------------------------------------------------------

    private function series(string $name = 'Attack on Titan', int $mangaId = 1, string $sourceRef = self::TCB, array $attributes = []): Novel
    {
        $novel = Novel::create(['name' => $name, 'status' => 0, 'group_id' => 9200, 'translator_url' => self::BASE . "/manga/{$mangaId}"]);
        $novel->forceFill(['medium' => 'manga', 'source_ref' => $sourceRef] + $attributes)->save();

        return $novel->fresh();
    }

    private function chapter(Novel $novel, float $number, array $state = []): NovelChapter
    {
        $mangaId = SuwayomiSource::mangaId($novel->translator_url);
        $text = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $text,
            'label' => "Chapter {$text}",
            'url' => self::BASE . "/manga/{$mangaId}/chapter/" . ((int) round($number * 10)),
        ]);
        $chapter->forceFill($state)->save();

        return $chapter->fresh();
    }

    private function stuck(Novel $novel, float $number, string $reason = 'partial_pages', int $attempts = 3): NovelChapter
    {
        return $this->chapter($novel, $number, ['attempts' => $attempts, 'last_failure_reason' => $reason, 'next_attempt_at' => now()->addHour()]);
    }

    private function fill(array $options = []): array
    {
        return app(MangaFallback::class)->run($options['novel'] ?? null, $options['dry'] ?? false, $options['refresh'] ?? false);
    }

    private function ids($query): array
    {
        return $query->pluck('id')->map(fn($id) => (int) $id)->sort()->values()->all();
    }

    // ---- Qualifying -------------------------------------------------------------------

    public function testQualifyingRules(): void
    {
        $novel = $this->series();
        $partial = $this->stuck($novel, 1, 'partial_pages', 3);
        $this->stuck($novel, 2, 'partial_pages', 2);                       // below the threshold
        $empty = $this->stuck($novel, 3, 'no_content', 3);
        $gone = $this->stuck($novel, 4, 'not_found', 4);
        $fetchFailed = $this->stuck($novel, 5, 'fetch_failed', 1);          // blocking: attempts never pass 1
        $this->stuck($novel, 6, 'rate_limited', 9);                         // pace, never
        $this->stuck($novel, 7, 'cloudflare', 9);
        $parkedCandidate = $this->stuck($novel, 8, 'no_content', 1);        // parked only with a streak
        $this->chapter($novel, 9, ['status' => 1, 'attempts' => 5, 'last_failure_reason' => 'partial_pages']);
        $this->stuck($novel, 10, 'partial_pages', 5)->forceFill(['blacklist' => 1])->save();
        $this->stuck($novel, 11, 'partial_pages', 5)->delete();

        $this->assertSame([$partial->id, $empty->id, $gone->id], $this->ids(MangaFallback::qualifyingQuery($novel)));

        // fetch_failed: the series failed run after run (Kingdom: 18 failed runs).
        $novel->forceFill(['scrape_failures' => 3])->save();
        $this->assertSame([$partial->id, $empty->id, $gone->id, $fetchFailed->id], $this->ids(MangaFallback::qualifyingQuery($novel->fresh())));
        $novel->forceFill(['scrape_failures' => 0])->save();

        // Parked by SourceUnavailable (The Last Human: no_content ×1).
        SourceUnavailable::park($novel->fresh());
        $novel = $novel->fresh();
        $this->assertSame(3, SourceUnavailable::parkedCounts([$novel->id])[$novel->id]['count'], 'chapters 3, 4 and 8 are parked');
        $this->assertSame([$partial->id, $empty->id, $gone->id, $parkedCandidate->id], $this->ids(MangaFallback::qualifyingQuery($novel)));

        // The threshold is configurable.
        config(['novarr.manga.fallback_after_attempts' => 2]);
        $this->assertCount(5, MangaFallback::qualifyingQuery($novel)->get());

        $this->assertSame([$novel->id], MangaFallback::candidateNovels()->modelKeys());
    }

    public function testPausedAndTextSeriesNeverQualify(): void
    {
        $paused = $this->series('Kingdom', 2, self::FOX, ['paused_at' => now()]);
        $this->stuck($paused, 1);
        $text = $this->series('Shadow Slave', 3);
        $text->forceFill(['medium' => 'novel'])->save();
        $this->stuck($text, 1);

        $this->assertSame([], MangaFallback::qualifyingQuery($paused)->get()->all());
        $this->assertSame([], MangaFallback::qualifyingQuery($text->fresh())->get()->all());
        $this->assertTrue(MangaFallback::candidateNovels()->isEmpty());

        $this->artisan('manga:fill-missing')->expectsOutputToContain('no manga chapter qualifies')->assertExitCode(0);
        $this->assertSame([], $this->fetcher->calls, 'nothing was asked');
    }

    // ---- Title matching (pure) ------------------------------------------------------------

    public function testTitleNormalisationAndExactMatching(): void
    {
        $this->assertSame('last human', MangaFallback::normalizeTitle('The Last Human'));
        $this->assertSame('attack on titan', MangaFallback::normalizeTitle('  Attack on Titan (Official) '));
        $this->assertSame('jojos bizarre adventure part 5 golden wind', MangaFallback::normalizeTitle("JoJo's Bizarre Adventure Part 5: Golden Wind"));
        $this->assertSame('re zero', MangaFallback::normalizeTitle('Re:Zéro'));
        $this->assertSame('kaguya sama love and war', MangaFallback::normalizeTitle('Kaguya-sama: Love & War'));
        $this->assertSame('last human colored', MangaFallback::normalizeTitle('The Last Human (Colored)'));

        $wanted = MangaFallback::titleKeys(['Kingdom']);
        $this->assertTrue(MangaFallback::matchesTitles(['name' => 'KINGDOM'], $wanted, false));
        $this->assertFalse(MangaFallback::matchesTitles(['name' => 'Kingdom Hearts'], $wanted, false));
        $this->assertFalse(MangaFallback::matchesTitles(['name' => 'Kingdom Hearts II'], $wanted, false));

        $aot = MangaFallback::titleKeys(['Attack on Titan']);
        $this->assertFalse(MangaFallback::matchesTitles(['name' => 'Attack on Titan - Before the Fall'], $aot, false));
        $this->assertFalse(MangaFallback::matchesTitles(['name' => 'Attack on Titan: No Regrets'], $aot, false));
        $this->assertTrue(MangaFallback::matchesTitles(['name' => 'Shingeki no Kyojin', 'alt_titles' => ['進撃の巨人', 'Attack on Titan']], $aot, false), 'alt title');

        $human = MangaFallback::titleKeys(['The Last Human']);
        $this->assertTrue(MangaFallback::matchesTitles(['name' => 'Last Human'], $human, false));
        $this->assertFalse(MangaFallback::matchesTitles(['name' => 'The Last Human (Colored)'], $human, false));
        $this->assertFalse(MangaFallback::matchesTitles(['name' => 'Last Human', 'alt_titles' => []], MangaFallback::titleKeys(['The Last Human (Colored)']), true), 'a colored series wants the colored pages');
        $this->assertFalse(MangaFallback::matchesTitles(['name' => 'The Last Human - Digital Colored'], $human, false));
        $this->assertTrue(MangaFallback::matchesTitles(['name' => 'The Last Human (Colored)'], MangaFallback::titleKeys(['The Last Human (Colored)']), true));
    }

    public function testMinPagesFollowsTheSeriesMedian(): void
    {
        $novel = $this->series();
        $this->assertSame(3, MangaFallback::minPages($novel), 'nothing downloaded yet');
        foreach ([30, 40, 50] as $i => $pages) {
            $this->chapter($novel, 100 + $i, ['status' => 1, 'page_count' => $pages]);
        }
        $this->assertSame(14, MangaFallback::minPages($novel), 'a third of the median (40)');

        $short = $this->series('Four Panels', 9, self::PILL);
        foreach ([2, 2, 2] as $i => $pages) {
            $this->chapter($short, 1 + $i, ['status' => 1, 'page_count' => $pages]);
        }
        $this->assertSame(2, MangaFallback::minPages($short), 'typically shorter than 3');
    }

    // ---- Alternate search -----------------------------------------------------------------

    public function testSearchKeepsExactTitlesOnlyInPreferenceOrderAndSkipsTheOwnSource(): void
    {
        $novel = $this->series('Attack on Titan');
        $this->stuck($novel, 139);
        $this->server['search'] = [
            self::TCB => [[1, 'Attack on Titan']],                                 // the series itself
            self::PILL => [[41, 'Attack on Titan'], [42, 'Attack on Titan - Before the Fall']],
            self::FOX => [[31, 'Attack on Titan: No Regrets']],                      // near miss only
            self::WEEB => [[21, 'Attack on Titan (Colored)'], [22, 'Attack on Titan'], [23, 'Attack on Titan']],
        ];
        $this->server['chapters'] = [
            21 => [[139, 139]], 22 => [[1, 1], [139, 139]], 23 => [[1, 1], [2, 2], [139, 139]],
            41 => [[139, 139]], 42 => [[1, 1]],
        ];
        // The series' own chapter count is 3 (one stuck + two more).
        $this->chapter($novel, 1, ['status' => 1, 'page_count' => 3]);
        $this->chapter($novel, 2, ['status' => 1, 'page_count' => 3]);

        $stats = $this->fill(['dry' => false]);

        $this->assertSame(4, $stats['searched'], 'MangaDex, Weeb Central, MangaFox, MangaPill — never TCB Scans');
        $this->assertSame(0, $this->searchesOf(self::TCB));
        $alternates = MangaAlternate::where('novel_id', $novel->id)->orderBy('rank')->get();
        $this->assertSame(['suwayomi:' . self::WEEB, 'suwayomi:' . self::PILL], $alternates->pluck('source_key')->all());
        $this->assertSame(['Weeb Central', 'MangaPill'], $alternates->pluck('source_name')->all());
        // Of Weeb Central's two exact hits the one with 3 chapters (as the series) wins; the colored edition is never looked at.
        $this->assertSame(self::BASE . '/manga/23', $alternates[0]->series_url);
        $this->assertSame(3, $alternates[0]->chapter_count);
        $this->assertEquals(139.0, $alternates[0]->max_chapter);
        $this->assertSame([], array_filter($this->calls(SuwayomiSource::MUTATION_FETCH_CHAPTERS), fn($c) => in_array($c['body']['variables']['input']['mangaId'], [21, 31, 42], true)));
        $this->assertSame(self::BASE . '/manga/41', $alternates[1]->series_url);

        // Filled from the preferred alternate.
        $this->assertSame(1, $stats['filled']);
        $this->assertSame('Weeb Central', NovelChapter::where('novel_id', $novel->id)->where('chapter', 139)->value('filled_from'));
    }

    public function testRenamedSeriesMatchesOnTheTitleItsOwnSourceReports(): void
    {
        $novel = $this->series("JoJo's Bizarre Adventure Part 5: Golden Wind", 5);
        $this->stuck($novel, 10);
        $this->server['titles'][5] = "JoJo's Bizarre Adventure Part 5 - Giorno Giovanna: Golden Heritage";
        $this->server['search'][self::WEEB] = [[51, "JoJo's Bizarre Adventure Part 5 - Giorno Giovanna: Golden Heritage"]];
        $this->server['chapters'][51] = [[10, 10]];

        $stats = $this->fill();

        $this->assertSame(1, $stats['filled']);
        $alternate = MangaAlternate::where('novel_id', $novel->id)->sole();
        $this->assertSame(self::BASE . '/manga/51', $alternate->series_url);
        $state = MangaAlternateSearch::where('novel_id', $novel->id)->sole();
        $this->assertSame(["JoJo's Bizarre Adventure Part 5 - Giorno Giovanna: Golden Heritage"], $state->source_titles, 'cached on the refresh');
        // Both titles were searched for.
        $queries = array_unique(array_map(fn($c) => $c['body']['variables']['input']['query'], array_filter($this->calls(SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA), fn($c) => $c['body']['variables']['input']['source'] === self::WEEB)));
        $this->assertEqualsCanonicalizing(["JoJo's Bizarre Adventure Part 5: Golden Wind", "JoJo's Bizarre Adventure Part 5 - Giorno Giovanna: Golden Heritage"], array_values($queries));

        // A second run within the week asks the own source for its title no more.
        $this->stuck($novel, 11);
        $this->server['chapters'][51][] = [11, 11];
        $this->fill();
        $this->assertCount(1, $this->calls(SuwayomiSource::QUERY_MANGA_TITLE));
        $this->assertSame('Weeb Central', NovelChapter::where('novel_id', $novel->id)->where('chapter', 11)->value('filled_from'));
    }

    public function testAlternatesAreCachedAndRefreshedOnTimeOrOnRequest(): void
    {
        config(['novarr.manga.fallback_max_sources' => 2]);
        $novel = $this->series('Kingdom', 2, self::FOX);
        $this->stuck($novel, 1);
        $this->stuck($novel, 2);
        // Weeb Central lists the series but not chapter 2.
        $this->server['search'][self::WEEB] = [[21, 'Kingdom']];
        $this->server['chapters'][21] = [[1, 1]];
        $this->server['search'][self::PILL] = [[41, 'Kingdom']];
        $this->server['chapters'][41] = [[1, 1], [2, 2]];

        // Order without the own MangaFox: MangaDex, Weeb Central, MangaPill, TCB Scans. Two per run.
        $first = $this->fill();
        $this->assertSame(2, $first['searched']);
        $this->assertSame(1, $first['filled'], 'chapter 1 from Weeb Central; chapter 2 nowhere yet');
        $state = MangaAlternateSearch::where('novel_id', $novel->id)->sole();
        $this->assertSame([2, 4], [$state->cursor, $state->sources_total]);
        $this->assertNull($state->cycle_completed_at);

        // The cycle continues with the sources left.
        $second = $this->fill();
        $this->assertSame(2, $second['searched']);
        $this->assertSame(1, $second['filled']);
        $this->assertSame('MangaPill', NovelChapter::where('novel_id', $novel->id)->where('chapter', 2)->value('filled_from'));
        $state->refresh();
        $this->assertNotNull($state->cycle_completed_at);

        // Nothing left to fill: the series is no candidate, nothing is asked.
        $this->stuck($novel, 3);
        $this->server['chapters'][41][] = [3, 3];
        $before = count($this->calls(SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA));
        $third = $this->fill();
        $this->assertSame(0, $third['searched'], 'cached: the cycle is complete and recent');
        $this->assertSame(1, $third['filled']);
        $this->assertSame($before, count($this->calls(SuwayomiSource::MUTATION_FETCH_SOURCE_MANGA)));

        // --refresh searches again at once …
        $this->stuck($novel, 4);
        $this->server['chapters'][41][] = [4, 4];
        $this->assertSame(2, $this->fill(['refresh' => true])['searched']);

        // … and so does a cycle older than fallback_search_days.
        $this->stuck($novel, 5);
        $this->server['chapters'][41][] = [5, 5];
        $this->fill(); // completes the forced cycle
        MangaAlternateSearch::where('novel_id', $novel->id)->update(['cycle_started_at' => now()->subDays(8)]);
        $this->stuck($novel, 6);
        $this->assertSame(2, $this->fill()['searched']);
    }

    public function testNoAlternateAnywhereIsRememberedAndShownOnTheHealthPage(): void
    {
        $novel = $this->series('The Last Human', 7, self::PILL);
        foreach ([1, 2, 3] as $n) {
            $this->stuck($novel, $n, 'no_content', 1);
        }
        SourceUnavailable::park($novel);
        $this->server['search'][self::WEEB] = [[71, 'The Last Human (Colored)'], [72, 'Last Human Resource']];

        $this->get(route('health.index'))->assertOk()->assertSee('Looking on other sources');

        $stats = $this->fill();
        $this->assertSame(0, $stats['filled']);
        $this->assertSame(0, MangaAlternate::count());
        $this->get(route('health.index'))->assertOk()
            ->assertSee('No other source has it (checked ' . now()->format('j M Y') . ')');

        // The week is not over: the next run does not search again.
        $this->assertSame(0, $this->fill()['searched']);
    }

    // ---- Filling ----------------------------------------------------------------------------

    public function testAFilledChapterIsMarkedLikeANormalDownloadAndTheSeriesRecovers(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $novel = $this->series('Attack on Titan');
        $this->chapter($novel, 1, ['status' => 1, 'page_count' => 3]);
        $chapter = $this->stuck($novel, 139, 'partial_pages', 8);
        $url = $chapter->url;
        $novel->forceFill(['scrape_failures' => 4, 'last_scrape_issue' => 'a page image was missing', 'source_fail_streak' => 2, 'source_recheck_at' => now()->addDay()])->save();
        $this->server['search'][self::WEEB] = [[22, 'Attack on Titan']];
        $this->server['chapters'][22] = [[138, 5], [139, 6]];
        $this->server['pages'][22][6] = 4;

        $this->artisan('manga:fill-missing')
            ->expectsOutputToContain('Chapter 139: 4 pages from Weeb Central')
            ->expectsOutputToContain('Filled 1 chapter(s) for 1 series')
            ->assertExitCode(0);

        $chapter->refresh();
        $this->assertTrue($chapter->status);
        $this->assertSame(4, $chapter->page_count);
        $this->assertSame(0, $chapter->attempts);
        $this->assertNull($chapter->last_failure_reason);
        $this->assertNull($chapter->next_attempt_at);
        $this->assertNotNull($chapter->download_date);
        $this->assertSame($url, $chapter->url, 'the row keeps its own source');
        $this->assertSame('Weeb Central', $chapter->filled_from);
        $this->assertSame('2026-11-20 10:00:00', $chapter->filled_at->toDateTimeString());
        $this->assertArrayNotHasKey('filled_from', $chapter->toArray(), 'never in a reader\'s JSON');

        $pages = ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->get();
        $this->assertSame([1, 2, 3, 4], $pages->pluck('page')->all());
        foreach ($pages as $page) {
            Storage::disk('manga')->assertExists($page->path);
        }
        // The pages came from the alternate's chapter, not the series' own.
        $downloads = $this->fetcher->urls('download');
        $this->assertSame(self::BASE . '/api/v1/manga/22/chapter/6/page/0', $downloads[0]);
        $this->assertSame([], array_filter($downloads, fn($u) => str_contains($u, '/manga/1/')));

        $novel->refresh();
        $this->assertSame(0, $novel->scrape_failures);
        $this->assertNull($novel->last_scrape_issue);
        $this->assertSame(0, $novel->source_fail_streak);
        $this->assertNull($novel->source_recheck_at);

        $health = DB::table('source_stats')->where('source', 'suwayomi:' . self::WEEB)->where('kind', 'chapter')->first();
        $this->assertSame(1, (int) $health->ok);

        // The series page tells the admin.
        $this->get(route('novels.show', $novel->id))->assertOk()
            ->assertSee('id="seriesFilledFrom"', false)
            ->assertSee('1 chapter filled from other sources (Weeb Central 1)');
        Carbon::setTestNow();
    }

    public function testTheNextAlternateIsUsedWhenTheFirstLacksTheNumberOrFails(): void
    {
        $novel = $this->series('Kingdom', 2, self::FOX);
        $missing = $this->stuck($novel, 5);
        $broken = $this->stuck($novel, 6);
        $this->server['search'][self::WEEB] = [[21, 'Kingdom']];
        $this->server['chapters'][21] = [[6, 6]];         // no chapter 5
        $this->server['search'][self::PILL] = [[41, 'Kingdom']];
        $this->server['chapters'][41] = [[5, 5], [6, 6]];
        // Weeb Central's chapter 6 has a broken page (also after a fresh page list).
        $this->fetcher->route(self::BASE . '/api/v1/manga/21/chapter/6/page/1', FakeFetcher::fail('http_404', 404));

        $stats = $this->fill();

        $this->assertSame(2, $stats['filled']);
        $this->assertSame('MangaPill', $missing->fresh()->filled_from);
        $this->assertSame('MangaPill', $broken->fresh()->filled_from);
        $weeb = MangaAlternate::where('source_key', 'suwayomi:' . self::WEEB)->sole();
        $this->assertSame(1, $weeb->failures);
        $this->assertNotNull($weeb->last_failed_at);
        $this->assertSame(0, MangaAlternate::where('source_key', 'suwayomi:' . self::PILL)->sole()->failures);
        $this->assertSame(1, (int) DB::table('source_stats')->where('source', 'suwayomi:' . self::WEEB)->where('kind', 'chapter')->sum('failed'));
    }

    public function testAnAlternateIsDroppedAfterFiveFailuresInARow(): void
    {
        $novel = $this->series('Kingdom', 2, self::FOX);
        foreach (range(1, 7) as $n) {
            $this->stuck($novel, $n);
        }
        $this->server['search'][self::WEEB] = [[21, 'Kingdom']];
        $this->server['chapters'][21] = array_map(fn($n) => [$n, $n], range(1, 7));
        $this->fetcher->route(self::BASE . '/api/v1/manga/21/chapter/1/page/0', FakeFetcher::fail('http_5xx', 502));
        foreach (range(1, 7) as $n) {
            $this->fetcher->route(self::BASE . "/api/v1/manga/21/chapter/{$n}/page/0", FakeFetcher::fail('http_5xx', 502));
        }

        $stats = $this->fill();

        $this->assertSame(0, $stats['filled']);
        $this->assertSame(5, MangaAlternate::sole()->failures);
        $tried = array_unique(array_map(fn($u) => preg_replace('#/page/\d+$#', '', $u), $this->fetcher->urls('download')));
        $this->assertCount(5, $tried, 'chapters 6 and 7 were not tried on it');
        // Unusable: the next run searches again at once (cycle complete, every alternate dead).
        $this->assertTrue(MangaFallback::refreshDue(MangaAlternateSearch::sole(), MangaAlternate::all()));
    }

    public function testASuspiciouslyShortChapterIsRejected(): void
    {
        $novel = $this->series('Attack on Titan');
        foreach ([1, 2, 3] as $n) {
            $this->chapter($novel, $n, ['status' => 1, 'page_count' => 45]);
        }
        $chapter = $this->stuck($novel, 4);
        $this->server['search'][self::WEEB] = [[21, 'Attack on Titan']];
        $this->server['chapters'][21] = [[4, 4]];
        $this->server['pages'][21][4] = 2;             // a teaser, not the chapter
        $this->server['search'][self::PILL] = [[41, 'Attack on Titan']];
        $this->server['chapters'][41] = [[4, 4]];
        $this->server['pages'][41][4] = 44;

        $this->fill();

        $chapter->refresh();
        $this->assertSame('MangaPill', $chapter->filled_from);
        $this->assertSame(44, $chapter->page_count);
        $this->assertSame([], array_filter($this->fetcher->urls('download'), fn($u) => str_contains($u, '/manga/21/')), 'the short one was refused before any image');
        $this->assertSame(1, MangaAlternate::where('source_key', 'suwayomi:' . self::WEEB)->sole()->failures);
    }

    public function testDecimalNumbersAndDuplicateEntriesOfANumber(): void
    {
        $novel = $this->series('Attack on Titan');
        $half = $this->stuck($novel, 139.5);
        $whole = $this->stuck($novel, 139);
        // Two scanlations of 139.5 (orders 7 and 8) and 139 itself.
        $this->server['search'][self::WEEB] = [[21, 'Attack on Titan']];
        $this->server['chapters'][21] = [[139, 6, 'Alpha'], [139.5, 7, 'Alpha'], [139.5, 8, 'Beta'], [140, 9, 'Alpha']];

        $this->assertSame(['chapter' => '139.5'], array_intersect_key(MangaFallback::findNumber([['chapter' => '139', 'url' => 'a'], ['chapter' => '139.5', 'url' => 'b'], ['chapter' => '139.5', 'url' => 'c']], 139.5), ['chapter' => 1]));
        $this->assertSame('b', MangaFallback::findNumber([['chapter' => '139', 'url' => 'a'], ['chapter' => '139.5', 'url' => 'b'], ['chapter' => '139.5', 'url' => 'c']], 139.5)['url']);
        $this->assertNull(MangaFallback::findNumber([['chapter' => '139', 'url' => 'a']], 139.5));

        $this->fill();

        $this->assertSame('Weeb Central', $half->fresh()->filled_from);
        $this->assertSame('Weeb Central', $whole->fresh()->filled_from);
        $downloads = $this->fetcher->urls('download');
        $this->assertContains(self::BASE . '/api/v1/manga/21/chapter/7/page/0', $downloads, '139.5 from the Alpha copy');
        $this->assertContains(self::BASE . '/api/v1/manga/21/chapter/6/page/0', $downloads, '139 from 139');
        $this->assertSame([], array_filter($downloads, fn($u) => str_contains($u, '/chapter/8/') || str_contains($u, '/chapter/9/')));
    }

    public function testABusyLaneIsNotUsedAndChaptersOnOneLaneArePaced(): void
    {
        $novel = $this->series('Kingdom', 2, self::FOX);
        $this->stuck($novel, 1);
        $this->stuck($novel, 2);
        $this->server['search'][self::WEEB] = [[21, 'Kingdom']];
        $this->server['chapters'][21] = [[1, 1], [2, 2]];
        $this->server['search'][self::PILL] = [[41, 'Kingdom']];
        $this->server['chapters'][41] = [[1, 1], [2, 2]];

        // Weeb Central's lane is downloading another series right now.
        $lock = DownloadLanes::lock('suwayomi:' . self::WEEB);
        $this->assertTrue($lock->get());
        try {
            $this->fill();
        } finally {
            $lock->release();
        }

        $this->assertSame(['MangaPill', 'MangaPill'], NovelChapter::where('novel_id', $novel->id)->orderBy('chapter')->pluck('filled_from')->all());
        $this->assertSame([], array_filter($this->fetcher->urls('download'), fn($u) => str_contains($u, '/manga/21/')));
        // One wait between the two chapters on MangaPill's lane.
        $this->assertCount(1, app(MangaFallback::class)->paused);
        $this->assertFalse(DownloadLanes::busy('suwayomi:' . self::PILL), 'the lane is free again');
    }

    public function testARateLimitedAlternateIsNotCountedAsBroken(): void
    {
        $novel = $this->series('Kingdom', 2, self::FOX);
        $this->stuck($novel, 1);
        $this->server['search'][self::WEEB] = [[21, 'Kingdom']];
        $this->server['chapters'][21] = [[1, 1]];
        $this->fetcher->route(self::BASE . '/api/v1/manga/21/chapter/1/page/0', FakeFetcher::fail('rate_limited', 429));

        $this->fill();

        $this->assertSame(0, MangaAlternate::sole()->failures);
        $this->assertNull(NovelChapter::where('novel_id', $novel->id)->value('filled_from'));
    }

    public function testMangaDexDirectIsAnAlternateToo(): void
    {
        $uuid = 'aaaaaaaa-1111-4222-8333-444444444444';
        $search = json_encode(['result' => 'ok', 'total' => 2, 'limit' => 40, 'offset' => 0, 'data' => [
            ['id' => $uuid, 'type' => 'manga', 'attributes' => ['title' => ['en' => 'Last Human'], 'altTitles' => [['ko' => '최후의 인류']]], 'relationships' => []],
            ['id' => 'bbbbbbbb-1111-4222-8333-444444444444', 'type' => 'manga', 'attributes' => ['title' => ['en' => 'The Last Human (Colored)']], 'relationships' => []],
        ]]);
        $this->fetcher = new FakeFetcher(
            [
                self::GQL => fn(array $body) => $this->answer($body['query'], $body['variables'] ?? []),
                'https://api.mangadex.org/at-home/server/fdfb18b6-8f34-4971-afa2-0f9d1e750470' => 'mangadex/at-home.json',
            ],
            [
                self::BASE . '/api/v1/manga/' => 'mangadex/page.png',
                'https://api.mangadex.org/manga?' => $search,
                'https://api.mangadex.org/chapter?' => '{"result":"ok","data":[],"total":3,"limit":1,"offset":0}',
                "https://api.mangadex.org/manga/{$uuid}/feed" => 'mangadex/feed-short.json',
                'https://cmdxd98sb0x3yprd.mangadex.network/' => 'mangadex/page.png',
            ]
        );
        $this->app->instance(Fetcher::class, $this->fetcher);

        $novel = $this->series('The Last Human', 7, self::PILL);
        $chapter = $this->stuck($novel, 1, 'no_content', 1);
        SourceUnavailable::park($novel);

        $stats = $this->fill();

        $this->assertSame(1, $stats['filled']);
        $chapter->refresh();
        $this->assertSame('MangaDex', $chapter->filled_from);
        $this->assertSame(3, $chapter->page_count);
        $this->assertSame(['mangadex'], MangaAlternate::pluck('source_key')->all());
        $this->assertSame('https://mangadex.org/title/' . $uuid, MangaAlternate::sole()->series_url);
        $this->assertSame(1, (int) DB::table('source_stats')->where('source', 'mangadex')->where('kind', 'chapter')->value('ok'));
        $this->assertSame(0, $novel->fresh()->source_fail_streak, 'nothing stuck left: released');
        $this->assertSame('1 chapter filled from other sources (MangaDex 1)', MangaFallback::filledSummary($novel->id));
    }

    // ---- Command ------------------------------------------------------------------------------

    public function testDryRunChangesNothing(): void
    {
        $novel = $this->series('Kingdom', 2, self::FOX, ['scrape_failures' => 18]);
        $chapter = $this->stuck($novel, 868, 'fetch_failed', 1);
        $this->server['search'][self::WEEB] = [[21, 'Kingdom']];
        $this->server['chapters'][21] = [[868, 868]];
        $before = $chapter->toArray();

        $this->artisan('manga:fill-missing', ['--dry-run' => true])
            ->expectsOutputToContain('would fetch Chapter 868 from Weeb Central: ' . self::BASE . '/manga/21/chapter/868')
            ->expectsOutputToContain('1 chapter(s) would be fetched for 1 series')
            ->assertExitCode(0);

        $this->assertSame($before, $chapter->fresh()->toArray());
        $this->assertSame(0, MangaAlternate::count());
        $this->assertSame(0, MangaAlternateSearch::count());
        $this->assertSame(0, ChapterPage::count());
        $this->assertSame([], $this->fetcher->urls('download'));
        $this->assertSame(18, $novel->fresh()->scrape_failures);
    }

    public function testRunsNeverOverlapAndTheSwitchTurnsTheHourlyRunOff(): void
    {
        $novel = $this->series('Kingdom', 2, self::FOX);
        $this->stuck($novel, 1);

        $lock = Cache::lock(FillMissingMangaChapters::LOCK, 60);
        $this->assertTrue($lock->get());
        $this->artisan('manga:fill-missing')->expectsOutputToContain('Another manga:fill-missing run is in progress')->assertExitCode(0);
        $lock->release();
        $this->assertSame([], $this->fetcher->calls);

        config(['novarr.manga.fallback_enabled' => false]);
        $this->artisan('manga:fill-missing')->expectsOutputToContain('turned off')->assertExitCode(0);
        $this->assertSame([], $this->fetcher->calls);
    }

    public function testItIsScheduledHourly(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn($e) => str_contains((string) $e->command, 'manga:fill-missing'));
        $this->assertCount(1, $events);
        $this->assertSame('35 * * * *', $events->first()->expression);
    }

    public function testTheHealthNoteNamesWhatWasFilled(): void
    {
        $novel = $this->series('The Last Human', 7, self::PILL);
        foreach ([1, 2] as $n) {
            $this->stuck($novel, $n, 'no_content', 1);
        }
        $this->chapter($novel, 3, ['status' => 1, 'page_count' => 20, 'filled_from' => 'MangaDex', 'filled_at' => now()]);
        SourceUnavailable::park($novel);

        $this->assertSame([$novel->id => 'Filled 1 from MangaDex · Looking on other sources'], MangaFallback::healthNotes([$novel->id]));
        $this->get(route('health.index'))->assertOk()->assertSee('data-fallback-note', false)->assertSee('Filled 1 from MangaDex');
    }
}
