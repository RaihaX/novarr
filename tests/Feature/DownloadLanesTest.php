<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use App\Group;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Scraping\PageDownloadResult;
use App\Services\DownloadLanes;
use App\Services\LaneProcessStarter;
use App\Services\NovelCheck;
use App\Setting;
use App\Sources\SuwayomiSource;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Support\FakeFetcher;
use Tests\Support\InlineLaneStarter;
use Tests\TestCase;

/**
 * Download lanes: one download per source at a time, sources in parallel.
 * The dispatcher (`novel:chapter` without a novel) starts one lane process
 * per source with due chapters; `--lane=` runs one lane under its lock;
 * explicit runs share the lock; the reader's single-chapter download does
 * not. Network-free: fetches are faked by a ChapterScraper subclass (text,
 * manga pages) and FakeFetcher (Suwayomi's GraphQL).
 */
class DownloadLanesTest extends TestCase
{
    use RefreshDatabase;

    private const SUWAYOMI = 'http://suwayomi.test:4567';
    private const GQL = self::SUWAYOMI . '/api/graphql';
    private const SOURCE_A = '1111111111111111111';
    private const SOURCE_B = '2222222222222222222';

    private Group $group;

    /** @var ChapterScraper&object{fetches: array, states: array, now: float, onPause: ?\Closure} */
    private $scraper;

    private InlineLaneStarter $starter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 12:00:00');
        config(['novarr.suwayomi.url' => self::SUWAYOMI]);
        Setting::put('scrape_adaptive', '0');
        Setting::put('scrape_min_delay', 5);
        Setting::put('scrape_max_delay', 5);

        // Away from the install-specific group ids 1, 3 and 6.
        $this->group = new Group();
        $this->group->forceFill(['id' => 9200, 'label' => 'Lanes', 'url' => 'https://group.test'])->save();

        $this->scraper = new class extends ChapterScraper {
            public array $fetches = [];
            public array $states = [];
            public float $now = 1_000_000.0;
            public ?\Closure $onPause = null;

            protected function generateChapterDescription($chapter, ?string &$failureReason = null)
            {
                $this->record($chapter);

                return '<p>' . str_repeat('word ', 400) . '</p>';
            }

            protected function downloadChapterPages(NovelChapter $chapter): PageDownloadResult
            {
                $this->record($chapter);

                return PageDownloadResult::success(3, 300);
            }

            private function record($chapter): void
            {
                $lane = app(DownloadLanes::class)->laneFor($chapter->novel, $chapter, false);
                $this->fetches[] = ['novel' => (int) $chapter->novel_id, 'chapter' => (int) $chapter->id, 'lane' => $lane];
                $this->states[] = DownloadLanes::state($lane);
            }

            protected function pause(int $seconds): void
            {
                if ($this->onPause) {
                    ($this->onPause)($seconds);
                }
                $this->now += $seconds;
            }

            protected function clock(): float
            {
                return $this->now;
            }
        };
        $this->app[Kernel::class]->registerCommand($this->scraper);

        $this->starter = new InlineLaneStarter();
        $this->app->instance(LaneProcessStarter::class, $this->starter);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- Helpers ---------------------------------------------------------------

    /** A text novel with $due due and $later not-yet-due pending chapters on $host. */
    private function novel(string $name, string $host, int $due, int $later = 0, array $attrs = []): Novel
    {
        $novel = Novel::create(['name' => $name, 'status' => 0, 'group_id' => $this->group->id, 'no_of_chapters' => 100]);
        if ($attrs) {
            $novel->forceFill($attrs)->save();
        }
        for ($n = 1; $n <= $due + $later; $n++) {
            $chapter = NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'label' => "Chapter {$n}",
                'url' => "https://{$host}/{$novel->id}/chapter-{$n}",
            ]);
            if ($n > $due) {
                $chapter->forceFill(['attempts' => 1, 'next_attempt_at' => now()->addHour(), 'last_failure_reason' => 'not_found'])->saveQuietly();
            }
        }

        return $novel;
    }

    /** A Suwayomi manga (series $mangaId) with $count pending chapters. */
    private function suwayomi(string $name, int $mangaId, ?string $sourceRef, int $count = 1): Novel
    {
        $novel = Novel::create([
            'name' => $name, 'status' => 0, 'group_id' => $this->group->id,
            'translator_url' => SuwayomiSource::seriesUrl($mangaId), 'source_ref' => $sourceRef,
        ]);
        $novel->forceFill(['medium' => 'manga'])->save();
        for ($n = 1; $n <= $count; $n++) {
            NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'label' => "Chapter {$n}",
                'url' => SuwayomiSource::chapterUrl($mangaId, $n),
            ]);
        }

        return $novel;
    }

    private function pending(Novel $novel): int
    {
        return NovelChapter::where('novel_id', $novel->id)->where('status', 0)->count();
    }

    private function fetchedNovels(): array
    {
        return array_values(array_unique(array_column($this->scraper->fetches, 'novel')));
    }

    /** A FakeFetcher whose GraphQL endpoint answers $answers by query text. */
    private function gql(array $answers): FakeFetcher
    {
        $fake = new class([self::GQL => fn(array $body) => $answers[$body['query']] ?? null]) extends FakeFetcher {
            // novel:create downloads the cover with the raw client: a 404.
            public function client(): HttpClientInterface
            {
                return new MockHttpClient(fn() => new MockResponse('', ['http_code' => 404]));
            }
        };
        $this->app->instance(Fetcher::class, $fake);

        return $fake;
    }

    private static function mangaSource(string $id, string $name): string
    {
        return json_encode(['data' => ['manga' => ['sourceId' => $id, 'source' => ['name' => $name]]]]);
    }

    // ---- Lane resolution -----------------------------------------------------

    public function testATextNovelsLaneIsItsChapterHost(): void
    {
        $novel = $this->novel('Text', 'NovelFull.com', 1);
        $chapter = NovelChapter::where('novel_id', $novel->id)->first();

        $this->assertSame('novelfull.com', app(DownloadLanes::class)->laneFor($novel, $chapter));
        $this->assertSame('group.test', app(DownloadLanes::class)->laneFor($novel), 'the group URL without a chapter');
        $this->assertSame('novelfull.com', DownloadLanes::label('novelfull.com'));
        // The pace / cooldown keys of a host lane are the old per-host ones.
        $this->assertSame('scrape:host-cooldown:novelfull.com', ChapterScraper::hostCooldownKey('novelfull.com'));
    }

    public function testAMangaDexNovelsLaneIsTheMangaDexHost(): void
    {
        $novel = Novel::create(['name' => 'MD', 'status' => 0, 'group_id' => $this->group->id, 'translator_url' => 'https://mangadex.org/title/9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2']);
        $novel->forceFill(['medium' => 'manga'])->save();
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://mangadex.org/chapter/0f6bd8ab-6f6b-4b33-9cb6-7a4e1f2ef3ae']);

        $this->assertSame('mangadex.org', app(DownloadLanes::class)->laneFor($novel, $chapter));
    }

    public function testASuwayomiNovelWithAKnownSourceIsThatSourcesLane(): void
    {
        $fake = $this->gql([]);
        $novel = $this->suwayomi('Known', 10, self::SOURCE_A);

        $this->assertSame('suwayomi:' . self::SOURCE_A, app(DownloadLanes::class)->laneFor($novel, $novel->chapters()->first()));
        $this->assertSame([], $fake->calls, 'no request');
        $this->assertSame('Suwayomi · #' . self::SOURCE_A, DownloadLanes::label('suwayomi:' . self::SOURCE_A));

        Cache::put(SuwayomiSource::sourcesCacheKey(), [['id' => self::SOURCE_A, 'name' => 'MangaPill', 'lang' => 'en']], 60);
        $this->assertSame('Suwayomi · MangaPill', DownloadLanes::label('suwayomi:' . self::SOURCE_A));
        $this->assertSame([], $fake->calls, 'labels only read the cached source list');
    }

    public function testAnUnknownSuwayomiSourceIsLookedUpOnceAndSaved(): void
    {
        $fake = $this->gql([SuwayomiSource::QUERY_MANGA_SOURCE => self::mangaSource(self::SOURCE_B, 'Weeb Central')]);
        $novel = $this->suwayomi('Unknown', 11, null);

        $this->assertSame('suwayomi:' . self::SOURCE_B, app(DownloadLanes::class)->laneFor($novel, $novel->chapters()->first()));
        $this->assertSame(self::SOURCE_B, $novel->fresh()->source_ref);

        $calls = array_values(array_filter($fake->calls, fn($c) => $c['method'] === 'post_json_for_json'));
        $this->assertCount(1, $calls);
        $this->assertSame(['id' => 11], $calls[0]['body']['variables']);
        $this->assertLessThanOrEqual(10, $calls[0]['timeout'], 'a short timeout');

        // Known from now on: no second request.
        app(DownloadLanes::class)->laneFor($novel->fresh());
        $this->assertCount(1, $fake->calls);
    }

    public function testAFailedSuwayomiLookupFallsBackToTheServersLane(): void
    {
        $fake = $this->gql([SuwayomiSource::QUERY_MANGA_SOURCE => FakeFetcher::fail('fetch_failed', 500)]);
        $novel = $this->suwayomi('Unreachable', 12, null);

        $this->assertSame('suwayomi.test', app(DownloadLanes::class)->laneFor($novel, $novel->chapters()->first()));
        $this->assertNull($novel->fresh()->source_ref);
        $this->assertSame('Suwayomi (suwayomi.test:4567)', DownloadLanes::label('suwayomi.test'));

        // Not asked again for a while, even by a fresh resolver.
        app(DownloadLanes::class)->laneFor($novel->fresh(), $novel->chapters()->first());
        $this->assertCount(1, $fake->calls);
        // Without backfill (the Activity page) it never asks at all.
        Cache::flush();
        app(DownloadLanes::class)->laneFor($novel->fresh(), null, false);
        $this->assertCount(1, $fake->calls);
    }

    public function testNovelCreateStoresTheSuwayomiSource(): void
    {
        $this->gql([SuwayomiSource::MUTATION_FETCH_MANGA => 'suwayomi/fetch-manga.json']);

        $this->assertSame(0, Artisan::call('novel:create', ['name' => 'Hajime no Ippo', 'url' => SuwayomiSource::seriesUrl(2132)]), Artisan::output());

        $novel = Novel::where('name', 'Hajime no Ippo')->firstOrFail();
        $this->assertSame('8448310129093543312', $novel->source_ref);
        $this->assertSame('suwayomi:8448310129093543312', (new SuwayomiSource())->downloadLane($novel));
        $this->assertSame('8448310129093543312', SuwayomiSource::seriesMetadata(['id' => 1, 'sourceId' => '8448310129093543312'])['source_ref']);
        $this->assertArrayNotHasKey('source_ref', SuwayomiSource::seriesMetadata(['id' => 1, 'sourceId' => 'nope']));
    }

    // ---- Dispatcher ----------------------------------------------------------

    public function testTheDispatcherStartsOneProcessPerLaneWithDueWork(): void
    {
        $this->starter->inline = false;
        $this->novel('A1', 'a.test', 2);
        $this->novel('A2', 'a.test', 1);
        $this->novel('B1', 'b.test', 1);
        $this->novel('C later', 'c.test', 0, 2); // nothing due
        $this->novel('D paused', 'd.test', 1, 0, ['paused_at' => now()]);

        $this->assertSame(0, Artisan::call('novel:chapter'));

        $this->assertSame([['a.test', 'b.test']], $this->starter->started);
        $this->assertSame([], $this->scraper->fetches, 'the dispatcher downloads nothing itself');
        $this->assertFalse(DownloadLanes::busy('a.test'), 'nor does it hold a lane');
    }

    public function testTheProductionStarterRunsADetachedLaneCommand(): void
    {
        $line = LaneProcessStarter::commandLine('suwayomi:' . self::SOURCE_A);

        $this->assertStringContainsString("novel:chapter --lane='suwayomi:" . self::SOURCE_A . "'", $line);
        $this->assertStringContainsString(escapeshellarg(base_path('artisan')), $line);
        $this->assertStringEndsWith(' &)', $line);
        $this->assertStringContainsString('< /dev/null', $line);
    }

    public function testTheDispatcherSkipsABusyLane(): void
    {
        $this->starter->inline = false;
        $this->novel('A1', 'a.test', 1);
        $this->novel('B1', 'b.test', 1);
        $lock = DownloadLanes::lock('a.test');
        $this->assertTrue($lock->get());

        Artisan::call('novel:chapter');

        $this->assertSame([['b.test']], $this->starter->started);
        $this->assertStringContainsString('a.test: already downloading', Artisan::output());
        $lock->release();
    }

    public function testTheDispatcherRespectsMaxParallelLanesLongestWaitingFirst(): void
    {
        $this->starter->inline = false;
        $this->novel('A1', 'a.test', 1);
        $this->novel('B1', 'b.test', 1);
        $this->novel('C1', 'c.test', 1);
        Setting::put('max_parallel_lanes', 2);

        Artisan::call('novel:chapter');
        $this->assertSame([['a.test', 'b.test']], $this->starter->started);
        $this->assertStringContainsString('c.test: waits for a free slot', Artisan::output());

        // Next tick: c has never run, so it goes first; then the one that
        // started longest ago.
        $this->travel(5)->minutes();
        DownloadLanes::markStarted('b.test');
        Artisan::call('novel:chapter');
        $this->assertSame(['c.test', 'a.test'], $this->starter->started[1]);

        // A running lane takes a slot.
        $lock = DownloadLanes::lock('c.test');
        $lock->get();
        Artisan::call('novel:chapter');
        $this->assertCount(1, $this->starter->started[2]);
        $this->assertNotContains('c.test', $this->starter->started[2]);
        $lock->release();
    }

    public function testTheDispatcherStartsNothingWhenNothingIsDue(): void
    {
        $this->starter->inline = false;
        $this->novel('Later', 'a.test', 0, 3);

        $this->assertSame(0, Artisan::call('novel:chapter'));

        $this->assertSame([], $this->starter->started);
        $this->assertStringContainsString('Nothing is due', Artisan::output());
    }

    // ---- Lane runs -----------------------------------------------------------

    public function testALaneRunDownloadsOnlyThatLanesNovels(): void
    {
        $a1 = $this->novel('A1', 'a.test', 2);
        $a2 = $this->novel('A2', 'a.test', 1);
        $b = $this->novel('B1', 'b.test', 2);

        $this->assertSame(0, Artisan::call('novel:chapter', ['--lane' => ['a.test']]));

        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $this->fetchedNovels());
        $this->assertSame(0, $this->pending($a1));
        $this->assertSame(2, $this->pending($b));
        $this->assertFalse(DownloadLanes::busy('a.test'), 'lock released');
        $this->assertNull(DownloadLanes::state('a.test'), 'state cleared');
    }

    public function testALaneRunExitsWhenTheLaneIsBusy(): void
    {
        $a = $this->novel('A1', 'a.test', 2);
        $lock = DownloadLanes::lock('a.test');
        $lock->get();

        $this->assertSame(0, Artisan::call('novel:chapter', ['--lane' => ['a.test']]));

        $this->assertSame([], $this->scraper->fetches);
        $this->assertSame(2, $this->pending($a));
        $this->assertTrue(DownloadLanes::busy('a.test'), 'the other holder keeps it');
        $lock->release();
    }

    public function testALaneRunReleasesItsLockAfterAnException(): void
    {
        $this->novel('A1', 'a.test', 3);
        $this->scraper->onPause = fn() => throw new \RuntimeException('killed mid-run');

        try {
            Artisan::call('novel:chapter', ['--lane' => ['a.test']]);
            $this->fail('the exception escapes');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed mid-run', $e->getMessage());
        }

        $this->assertCount(1, $this->scraper->fetches);
        $this->assertFalse(DownloadLanes::busy('a.test'));
        $this->assertNull(DownloadLanes::state('a.test'));
        $this->assertTrue(ChapterScraper::novelLock($this->scraper->fetches[0]['novel'])->get(), 'the novel lock too');
    }

    public function testTheLaneLockIsRenewedDuringALongRun(): void
    {
        $this->novel('A1', 'a.test', 3);
        Setting::put('scrape_min_delay', 400);
        Setting::put('scrape_max_delay', 400);
        $renewals = 0;
        $this->scraper->onPause = function () use (&$renewals) {
            // Another process trying to take the lane mid-run is refused.
            $this->assertTrue(DownloadLanes::busy('a.test'));
            $renewals++;
        };

        Artisan::call('novel:chapter', ['--lane' => ['a.test']]);

        $this->assertCount(3, $this->scraper->fetches, 'all three, past the renewal point');
        $this->assertSame(2, $renewals);
        $this->assertFalse(DownloadLanes::busy('a.test'));
    }

    public function testTheLaneStateIsWrittenDuringARunAndClearedAfter(): void
    {
        $a = $this->novel('A1', 'a.test', 2);

        Artisan::call('novel:chapter'); // dispatcher, run inline

        $this->assertCount(2, $this->scraper->states);
        [$first, $second] = $this->scraper->states;
        $this->assertSame('a.test', $first['lane']);
        $this->assertSame($a->id, $first['novel_id']);
        $this->assertSame('A1', $first['novel_name']);
        $this->assertSame('Chapter 1', $first['chapter_label']);
        $this->assertSame('sweep', $first['mode']);
        $this->assertSame(0, $first['done_this_run']);
        $this->assertSame('Chapter 2', $second['chapter_label']);
        $this->assertSame(1, $second['done_this_run']);
        $this->assertSame($first['started_at'], $second['started_at']);
        $this->assertNull(DownloadLanes::state('a.test'));
    }

    public function testSuwayomiSourcesAreSeparateLanes(): void
    {
        $this->gql([]);
        $a1 = $this->suwayomi('Pill 1', 21, self::SOURCE_A);
        $a2 = $this->suwayomi('Pill 2', 22, self::SOURCE_A);
        $b = $this->suwayomi('Weeb', 23, self::SOURCE_B);
        $this->starter->inline = false;

        Artisan::call('novel:chapter');

        $this->assertEqualsCanonicalizing(['suwayomi:' . self::SOURCE_A, 'suwayomi:' . self::SOURCE_B], $this->starter->started[0]);
        $lanes = app(DownloadLanes::class);
        $this->assertSame($lanes->laneFor($a1), $lanes->laneFor($a2), 'same source, same lane');
        $this->assertNotSame($lanes->laneFor($a1), $lanes->laneFor($b));
    }

    public function testACooldownOnOneSuwayomiSourceDoesNotSkipAnother(): void
    {
        $this->gql([]);
        $cooled = $this->suwayomi('Pill', 31, self::SOURCE_A);
        $other = $this->suwayomi('Weeb', 32, self::SOURCE_B);
        Cache::put(ChapterScraper::hostCooldownKey('suwayomi:' . self::SOURCE_A), now()->addMinutes(15)->getTimestamp(), now()->addMinutes(15));

        Artisan::call('novel:chapter');

        $this->assertSame([$other->id], $this->fetchedNovels());
        $this->assertSame(1, $this->pending($cooled));
        $this->assertSame(0, $this->pending($other));
        $this->assertFalse(ChapterScraper::hostCoolingDown('suwayomi.test'), 'the server itself is not cooled down');
        $this->assertStringContainsString('Suwayomi · #' . self::SOURCE_A . ' is cooling down', Artisan::output());
    }

    // ---- Explicit runs -------------------------------------------------------

    public function testAnExplicitRunOnABusyLaneQueuesItsChapters(): void
    {
        $novel = $this->novel('Shadow Slave', 'a.test', 2);
        $lock = DownloadLanes::lock('a.test');
        $lock->get();

        $this->assertSame(0, Artisan::call('novel:chapter', ['novel' => $novel->id]));

        $line = 'Another download from a.test is running; 2 chapters of Shadow Slave are queued and will start when it finishes.';
        $this->assertStringContainsString($line, Artisan::output());
        $this->assertSame([], $this->scraper->fetches);
        $this->assertSame(2, $this->pending($novel));

        // Through the queued job and the API check: done, with that sentence.
        Cache::put(NovelCheck::key($novel->id), [
            'check_id' => 'c1', 'toc_job_id' => 'toc1', 'chapter_job_id' => 'chap1',
            'started_at' => now()->toIso8601String(), 'chapters_before' => 2, 'status' => 'running',
        ], now()->addDay());
        Cache::put('command_result_toc1', ['success' => true, 'exit_code' => 0, 'output' => ''], now()->addHour());
        (new RunNovelCommand('novel:chapter', ['novel' => $novel->id], 'chap1'))->handle();

        $result = Cache::get('command_result_chap1');
        $this->assertTrue($result['success']);
        $check = app(NovelCheck::class)->state($novel->id);
        $this->assertSame('done', $check['status']);
        $this->assertSame($line, $check['message']);
        $lock->release();
    }

    public function testAnExplicitRunHoldsTheLaneAndCanBeSeen(): void
    {
        $novel = $this->novel('Manual', 'a.test', 2);
        $busy = [];
        $this->scraper->onPause = function () use (&$busy) {
            // What the dispatcher and lane processes see meanwhile.
            $busy[] = DownloadLanes::busy('a.test');
        };

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame(0, $this->pending($novel));
        $this->assertSame('manual', $this->scraper->states[0]['mode']);
        $this->assertSame([true, true], $busy, 'the lane was held through the run');
        $this->assertFalse(DownloadLanes::busy('a.test'));
        $this->assertNull(DownloadLanes::state('a.test'));
    }

    public function testASingleChapterDownloadIgnoresTheLaneLock(): void
    {
        $novel = $this->novel('Reader', 'a.test', 2);
        $chapter = NovelChapter::where('novel_id', $novel->id)->orderBy('chapter')->first();
        $lock = DownloadLanes::lock('a.test');
        $lock->get();

        $this->assertSame(0, Artisan::call('novel:chapter', ['--chapter' => $chapter->id]));

        $this->assertTrue((bool) $chapter->fresh()->status);
        $this->assertTrue(DownloadLanes::busy('a.test'), 'the lane holder is untouched');
        $lock->release();
    }

    // ---- Activity page -------------------------------------------------------

    public function testTheActivityPageListsDownloadsBySource(): void
    {
        $fake = $this->gql([]);
        $this->novel('A1', 'a.test', 2, 1);
        $this->novel('A2', 'a.test', 1);
        $active = $this->novel('<script>alert(1)</script>', 'b.test', 1);
        $this->novel('Paused', 'c.test', 4, 0, ['paused_at' => now()]);
        $this->novel('Retry only', 'd.test', 0, 2);
        $this->suwayomi('Unknown source', 41, null, 2);
        $this->suwayomi('Known source', 42, self::SOURCE_A, 1);
        Cache::put(ChapterScraper::hostCooldownKey('d.test'), Carbon::parse('2026-11-17 12:30:00')->getTimestamp(), now()->addMinutes(30));
        DownloadLanes::putState('b.test', [
            'novel_id' => $active->id, 'novel_name' => $active->name, 'chapter_id' => 1,
            'chapter_label' => 'Chapter <b>1</b>', 'mode' => 'sweep', 'started_at' => now()->subMinutes(3)->toIso8601String(), 'done_this_run' => 0,
        ]);

        $json = $this->getJson(route('activity.downloads'))->assertOk()->json();

        $rows = collect($json['rows'])->keyBy('lane');
        $this->assertSame('b.test', $json['rows'][0]['lane'], 'downloading first');
        $this->assertSame(['b.test', 'a.test', 'suwayomi.test', 'suwayomi:' . self::SOURCE_A, 'd.test'], array_column($json['rows'], 'lane'));
        $this->assertSame('downloading', $rows['b.test']['state']);
        $this->assertSame('Chapter <b>1</b>', $rows['b.test']['chapter_label']);
        $this->assertSame(['queued' => 3, 'retrying' => 1, 'series' => 2], array_intersect_key($rows['a.test'], array_flip(['queued', 'retrying', 'series'])));
        $this->assertSame('waiting', $rows['a.test']['state']);
        $this->assertSame('novel', $rows['a.test']['medium']);
        $this->assertSame('cooling', $rows['d.test']['state']);
        $this->assertSame(0, $rows['d.test']['queued']);
        $this->assertSame(2, $rows['d.test']['retrying']);
        $this->assertSame(Carbon::parse('2026-11-17 12:30:00')->setTimezone(config('app.timezone'))->format('H:i'), $rows['d.test']['cooldown_until']);
        $this->assertSame('Suwayomi (suwayomi.test:4567)', $rows['suwayomi.test']['label']);
        $this->assertSame('manga', $rows['suwayomi.test']['medium']);
        $this->assertSame(2, $rows['suwayomi.test']['queued']);
        $this->assertArrayNotHasKey('c.test', $rows->all(), 'paused novels are not queued');
        $this->assertSame(['downloading' => 1, 'queued' => 7, 'sources' => 4], $json['summary']);

        $page = $this->get(route('activity.index'))->assertOk();
        $page->assertSee('Downloads by source');
        $page->assertSee('1 source downloading now · 7 chapters queued across 4 sources');
        $page->assertSee('Cooling down until ' . $rows['d.test']['cooldown_until']);
        $page->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $page->assertDontSee('<script>alert(1)</script>', false);
        $page->assertDontSee('Chapter <b>1</b>', false);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $json['html']);
        $this->assertStringContainsString('Downloading', $json['html']);

        $this->assertSame([], $fake->calls, 'the page never asks a source');
    }

    public function testTheActivitySectionIsEmptyWhenNothingIsQueued(): void
    {
        $json = $this->getJson(route('activity.downloads'))->assertOk()->json();

        $this->assertSame([], $json['rows']);
        $this->assertSame(['downloading' => 0, 'queued' => 0, 'sources' => 0], $json['summary']);
        $this->get(route('activity.index'))->assertOk()
            ->assertSee('0 sources downloading now · 0 chapters queued across 0 sources')
            ->assertSee('No downloads queued');
    }

    // ---- Stalled lanes -------------------------------------------------------

    /** Make every pending chapter of $novel due since $hours ago. */
    private function dueFor(Novel $novel, int $hours): void
    {
        NovelChapter::where('novel_id', $novel->id)->where('status', 0)->whereNull('next_attempt_at')
            ->update(['created_at' => now()->subHours($hours)]);
    }

    /** A chapter of $novel downloaded $hoursAgo hours ago. */
    private function downloaded(Novel $novel, int $hoursAgo): void
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => 900 + $hoursAgo, 'label' => 'Done',
            'url' => "https://done.test/{$novel->id}/{$hoursAgo}",
        ]);
        $chapter->forceFill(['status' => 1, 'download_date' => now()->subHours($hoursAgo), 'created_at' => now()->subDays(30)])->saveQuietly();
    }

    private function overviewRows(): \Illuminate\Support\Collection
    {
        return collect(app(DownloadLanes::class)->overview()['rows'])->keyBy('lane');
    }

    public function testALaneWithOldDueChaptersAndNoRecentDownloadIsStalled(): void
    {
        $novel = $this->novel('Stuck', 'a.test', 3);
        $this->dueFor($novel, 7);

        $row = $this->overviewRows()['a.test'];
        $this->assertTrue($row['stalled']);
        $this->assertSame(7, $row['stalled_hours'], 'no download on record: hours since the oldest became due');

        // A download older than that does not stretch it: the chapters have waited 7 hours.
        $this->downloaded($novel, 30);
        $this->assertSame(7, $this->overviewRows()['a.test']['stalled_hours']);

        // Chapters waiting longer than the last download: counted from that download.
        $this->dueFor($novel, 50);
        $this->assertSame(30, $this->overviewRows()['a.test']['stalled_hours']);
    }

    public function testARecentDownloadMeansTheLaneIsNotStalled(): void
    {
        $novel = $this->novel('Working', 'a.test', 3);
        $this->dueFor($novel, 7);
        $this->downloaded($novel, 1);

        $row = $this->overviewRows()['a.test'];
        $this->assertFalse($row['stalled']);
        $this->assertNull($row['stalled_hours']);
    }

    public function testADownloadOfAnotherNovelOnTheLaneCounts(): void
    {
        $stuck = $this->novel('Stuck', 'a.test', 3);
        $this->dueFor($stuck, 7);
        $other = $this->novel('Other', 'a.test', 1);
        $this->downloaded($other, 2);

        $this->assertFalse($this->overviewRows()['a.test']['stalled']);
    }

    public function testChaptersDueOnlyBrieflyDoNotStallALane(): void
    {
        $novel = $this->novel('Fresh', 'a.test', 3);
        $this->dueFor($novel, 1);

        $this->assertFalse($this->overviewRows()['a.test']['stalled']);
        $this->assertSame([], app(DownloadLanes::class)->stalled());
    }

    public function testADownloadingLaneThatProducesNothingIsStillStalled(): void
    {
        $novel = $this->novel('Busy', 'a.test', 2);
        $this->dueFor($novel, 8);
        DownloadLanes::putState('a.test', ['novel_id' => $novel->id, 'novel_name' => 'Busy', 'mode' => 'sweep', 'started_at' => now()->toIso8601String()]);

        $row = $this->overviewRows()['a.test'];
        $this->assertSame('downloading', $row['state']);
        $this->assertTrue($row['stalled']);
    }

    public function testPausedParkedAndBlacklistedChaptersDoNotStallALane(): void
    {
        $paused = $this->novel('Paused', 'p.test', 2, 0, ['paused_at' => now()]);
        $this->dueFor($paused, 10);

        // Parked: old, but next_attempt_at is still ahead.
        $parked = $this->novel('Parked', 'q.test', 0, 2);
        NovelChapter::where('novel_id', $parked->id)->update(['created_at' => now()->subDays(2)]);

        $blacklisted = $this->novel('Blacklisted', 'r.test', 2);
        $this->dueFor($blacklisted, 10);
        NovelChapter::where('novel_id', $blacklisted->id)->update(['blacklist' => 1]);

        $rows = $this->overviewRows();
        $this->assertFalse($rows['q.test']['stalled']);
        $this->assertArrayNotHasKey('p.test', $rows->all());
        $this->assertArrayNotHasKey('r.test', $rows->all());
        $this->assertSame([], app(DownloadLanes::class)->stalled());
    }

    public function testARetriedChapterIsDueSinceItsNextAttempt(): void
    {
        $novel = $this->novel('Retried', 'a.test', 1);
        // Created long ago, but its retry only came due 2 hours ago.
        NovelChapter::where('novel_id', $novel->id)->update(['created_at' => now()->subDays(3), 'next_attempt_at' => now()->subHours(2)]);
        $this->assertFalse($this->overviewRows()['a.test']['stalled']);

        NovelChapter::where('novel_id', $novel->id)->update(['next_attempt_at' => now()->subHours(6)]);
        $this->assertTrue($this->overviewRows()['a.test']['stalled']);
    }

    public function testLanesAreJudgedIndependently(): void
    {
        $stuck = $this->novel('Stuck', 'a.test', 2);
        $this->dueFor($stuck, 9);
        $fine = $this->novel('Fine', 'b.test', 2);
        $this->dueFor($fine, 9);
        $this->downloaded($fine, 3);

        $rows = $this->overviewRows();
        $this->assertTrue($rows['a.test']['stalled']);
        $this->assertFalse($rows['b.test']['stalled']);
        $this->assertSame(['a.test'], array_column(app(DownloadLanes::class)->stalled(), 'lane'));
    }

    public function testStalledListsTheStalledLanesMostQueuedFirst(): void
    {
        foreach ([['b.test', 2], ['a.test', 2], ['c.test', 5]] as [$host, $due]) {
            $this->dueFor($this->novel("Novel {$host}", $host, $due), 7);
        }
        $this->dueFor($this->novel('Second c', 'c.test', 1), 12);

        $stalled = app(DownloadLanes::class)->stalled();

        $this->assertSame(['c.test', 'a.test', 'b.test'], array_column($stalled, 'lane'));
        $this->assertSame(['lane' => 'c.test', 'label' => 'c.test', 'queued' => 6, 'series' => 2, 'hours' => 12], $stalled[0]);
        foreach ($stalled as $row) {
            $this->assertSame(['lane', 'label', 'queued', 'series', 'hours'], array_keys($row));
        }
    }

    public function testOverviewKeepsItsKeysAndAddsTheStallFields(): void
    {
        $this->novel('A', 'a.test', 1);

        $row = app(DownloadLanes::class)->overview()['rows'][0];
        $this->assertSame([
            'lane', 'label', 'medium', 'state', 'novel_id', 'novel_name', 'chapter_label', 'mode', 'since',
            'cooldown_until', 'done_this_run', 'queued', 'retrying', 'series', 'stalled', 'stalled_hours',
        ], array_keys($row));
    }

    public function testTheActivityPageNotesAStalledLaneOnly(): void
    {
        $this->dueFor($this->novel('Stuck', 'a.test', 2), 7);
        $this->novel('Fresh', 'b.test', 1);

        $json = $this->getJson(route('activity.downloads'))->assertOk()->json();
        $this->assertSame(1, substr_count($json['html'], 'nothing downloaded for'));
        $this->assertSame(1, substr_count($json['html'], 'data-stalled'));
        $row = fn(string $lane) => preg_match('/<tr data-lane="' . preg_quote($lane, '/') . '">.*?<\/tr>/s', $json['html'], $m) ? $m[0] : '';
        $this->assertStringContainsString('nothing downloaded for 7 h', $row('a.test'));
        $this->assertNotSame('', $row('b.test'));
        $this->assertStringNotContainsString('nothing downloaded', $row('b.test'));

        $this->get(route('activity.index'))->assertOk()->assertSee('nothing downloaded for 7 h');
    }

    public function testMaxParallelLanesIsASetting(): void
    {
        $this->post('/settings', ['max_parallel_lanes' => '3'])->assertSessionHasNoErrors();
        $this->assertSame('3', setting('max_parallel_lanes'));

        $this->post('/settings', ['max_parallel_lanes' => '0'])->assertSessionHasErrors('max_parallel_lanes');
    }
}
