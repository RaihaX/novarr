<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Services\ChapterNumberResolver;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * novel:toc run mechanics: the shared per-novel lock, per-novel error
 * isolation, the unchanged-TOC fingerprint skip and the end-matter
 * collision lookup. TOCs come from the Novel Arrow chapter API, faked.
 */
class TocRunTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'test-novel';
    private const API = 'https://novelping.com/api-web/novels/test-novel/chapters?sort=asc';

    private ?FakeFetcher $fake = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.novelarrow_host' => 'novelping.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function novel(string $name = 'Test Novel', array $attributes = []): Novel
    {
        $group = Group::first() ?? tap(new Group(), function ($g) {
            $g->label = 'Novel Arrow';
            $g->url = 'https://novelping.com';
            $g->save();
        });

        $novel = Novel::create([
            'name' => $name,
            'status' => 0,
            'group_id' => $group->id,
            'no_of_chapters' => 0,
            'translator_url' => 'https://novelping.com/novel/' . self::SLUG,
        ]);
        $novel->forceFill($attributes)->save();

        return $novel->fresh();
    }

    /** Serve chapters $from..$to (plus extra raw items) from the chapter API. */
    private function serveToc(int $from, int $to, array $extra = []): FakeFetcher
    {
        $items = array_map(fn($n) => ['chapter_id' => "chapter-{$n}", 'chapter_name' => "Chapter {$n}"], range($from, $to));
        $json = json_encode(['items' => array_merge($items, $extra)]);

        if ($this->fake === null) {
            $this->fake = new FakeFetcher([self::API => $json]);
            app()->instance(Fetcher::class, $this->fake);
        } else {
            $this->fake->route(self::API, $json);
        }

        return $this->fake;
    }

    private function url(int $n): string
    {
        return 'https://novelping.com/chapter/' . self::SLUG . "/chapter-{$n}";
    }

    private function scraper(): NovelScraper
    {
        $command = new NovelScraper();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        return $command;
    }

    public function testMigrationAddsAndDropsTheColumns(): void
    {
        $columns = ['toc_fingerprint', 'toc_full_walk_at', 'novelupdates_checked_at'];
        $migration = require database_path('migrations/2026_11_14_000001_add_toc_fingerprint_and_nu_checked_at_to_novels.php');

        $this->assertTrue(\Schema::hasColumns('novels', $columns));
        $migration->down();
        foreach ($columns as $column) {
            $this->assertFalse(\Schema::hasColumn('novels', $column), $column);
        }
        $migration->up();
        $migration->up(); // guarded: a re-run is a no-op
        $this->assertTrue(\Schema::hasColumns('novels', $columns));
    }

    // ---- lock + isolation ----------------------------------------------------

    public function testNovelLockedByAChapterScrapeIsSkipped(): void
    {
        $fake = $this->serveToc(1, 3);
        $novel = $this->novel();

        $held = Cache::lock("novel-scrape:{$novel->id}", 1800);
        $this->assertTrue($held->get());

        $this->artisan('novel:toc', ['novel' => $novel->id])
            ->expectsOutputToContain('another scrape of this novel is running')
            ->assertExitCode(0);

        $this->assertSame([], $fake->calls, 'nothing fetched for a locked novel');
        $this->assertSame(0, NovelChapter::count());
        $held->release();
    }

    public function testLockIsReleasedAfterTheRun(): void
    {
        $this->serveToc(1, 3);
        $novel = $this->novel();

        $this->artisan('novel:toc', ['novel' => $novel->id])->assertExitCode(0);

        $this->assertSame(3, NovelChapter::count());
        $lock = Cache::lock("novel-scrape:{$novel->id}", 10);
        $this->assertTrue($lock->get(), 'lock released');
        $lock->release();
    }

    public function testOneNovelsFailureDoesNotStopTheSweep(): void
    {
        $this->serveToc(1, 3);
        $broken = $this->novel('A Broken Novel');
        $healthy = $this->novel('B Healthy Novel');

        $this->app->bind(NovelScraper::class, fn() => new class extends NovelScraper {
            public function processNovel(Novel $novel, bool $explicit = false): void
            {
                if ($novel->name === 'A Broken Novel') {
                    throw new \Error('boom');
                }
                parent::processNovel($novel, $explicit);
            }
        });

        $this->artisan('novel:toc')
            ->expectsOutputToContain('TOC run failed for A Broken Novel: boom')
            ->assertExitCode(0);

        $this->assertSame(0, NovelChapter::where('novel_id', $broken->id)->count());
        $this->assertSame(3, NovelChapter::where('novel_id', $healthy->id)->count(), 'sweep went on to the next novel');

        $lock = Cache::lock("novel-scrape:{$broken->id}", 10);
        $this->assertTrue($lock->get(), 'lock released after a failure too');
        $lock->release();
    }

    /** tableOfContentGenerator catches \Throwable: an \Error inside a source is logged, not fatal. */
    public function testTocGeneratorSurvivesAnError(): void
    {
        $fake = new FakeFetcher(); // every fetch throws UnexpectedFetch (an \Error)
        app()->instance(Fetcher::class, $fake);

        $this->assertSame([], tableOfContentGenerator($this->novel()));
        $this->assertNotEmpty($fake->unexpected);
    }

    // ---- fingerprint ---------------------------------------------------------

    public function testFingerprintIsOrderSensitiveOverUrlAndLabel(): void
    {
        $a = ['url' => 'https://x.test/1', 'label' => 'Chapter 1', 'chapter' => '1'];
        $b = ['url' => 'https://x.test/2', 'label' => 'Chapter 2', 'chapter' => '2'];

        $this->assertSame(64, strlen(NovelScraper::tocFingerprint([$a, $b])));
        $this->assertSame(NovelScraper::tocFingerprint([$a, $b]), NovelScraper::tocFingerprint([$a, $b + ['book' => 3]]));
        $this->assertNotSame(NovelScraper::tocFingerprint([$a, $b]), NovelScraper::tocFingerprint([$b, $a]));
        $this->assertNotSame(NovelScraper::tocFingerprint([$a, $b]), NovelScraper::tocFingerprint([$a, ['label' => 'Chapter 2!'] + $b]));
    }

    /** Bumping TOC_SYNC_VERSION invalidates every stored fingerprint, so a parser change re-syncs unchanged TOCs. */
    public function testFingerprintChangesWithTheSyncVersion(): void
    {
        $toc = [['url' => 'https://x.test/1', 'label' => 'Chapter 1']];
        $bumped = new class extends NovelScraper {
            public const TOC_SYNC_VERSION = NovelScraper::TOC_SYNC_VERSION + 1;
        };

        $this->assertSame(1, NovelScraper::TOC_SYNC_VERSION);
        $this->assertNotSame(NovelScraper::tocFingerprint($toc), $bumped::tocFingerprint($toc));
        $this->assertSame($bumped::tocFingerprint($toc), $bumped::tocFingerprint($toc));
    }

    public function testUnchangedTocSkipsTheRowSyncButStampsAndRecordsHealth(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->serveToc(1, 5);
        $novel = $this->novel();

        $this->scraper()->processNovel($novel);
        $novel->refresh();
        $this->assertNotNull($novel->toc_fingerprint);
        $this->assertSame(5, $novel->last_toc_count);
        $this->assertSame('2026-11-01 10:00:00', (string) $novel->toc_full_walk_at);

        // Rows the sync would not stamp: one the TOC never listed, and a
        // second row sharing a listed URL (the url map takes the first id).
        $extra = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 99, 'book' => 0, 'label' => 'Chapter 99', 'url' => $this->url(99)]);
        $dupe = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 3, 'book' => 0, 'label' => 'Chapter 3', 'url' => $this->url(3)]);
        NovelChapter::whereIn('id', [$extra->id, $dupe->id])->update(['last_seen_in_toc_at' => '2026-10-01 00:00:00']);
        // A local change the row sync would overwrite — proves it did not run.
        NovelChapter::where('url', $this->url(2))->update(['title' => 'Locally edited']);
        $novel->forceFill(['toc_failures' => 1, 'last_toc_issue' => 'stale issue'])->saveQuietly();

        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->scraper()->processNovel($novel->fresh());

        $this->assertSame('Locally edited', NovelChapter::where('url', $this->url(2))->orderBy('id')->value('title'), 'no per-row sync');
        $stamped = NovelChapter::where('last_seen_in_toc_at', '2026-11-02 10:00:00')->orderBy('id')->pluck('id')->all();
        $this->assertSame(
            NovelChapter::where('novel_id', $novel->id)->whereNotIn('id', [$extra->id, $dupe->id])->orderBy('id')->pluck('id')->all(),
            $stamped,
            'exactly the five matched rows stamped'
        );

        $novel->refresh();
        $this->assertSame('2026-11-02 10:00:00', (string) $novel->last_toc_at);
        $this->assertSame(5, $novel->last_toc_count);
        $this->assertSame(0, $novel->toc_failures);
        $this->assertNull($novel->last_toc_issue);
        $this->assertSame('2026-11-02 10:00:00', (string) $novel->toc_full_walk_at);
    }

    public function testUnchangedTocStillParksStaleRows(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->serveToc(1, 3);
        $novel = $this->novel();
        $this->scraper()->processNovel($novel);

        $gone = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 9, 'book' => 0, 'label' => 'Chapter 9', 'url' => $this->url(9)]);
        NovelChapter::whereKey($gone->id)->update(['last_seen_in_toc_at' => '2026-10-20 00:00:00']);

        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->scraper()->processNovel($novel->fresh());

        $this->assertSame(NovelScraper::SOURCE_MISSING, $gone->fresh()->last_failure_reason);
    }

    public function testChangedTocRunsTheFullSyncAndStoresTheNewFingerprint(): void
    {
        $this->serveToc(1, 3);
        $novel = $this->novel();
        $this->scraper()->processNovel($novel);
        $first = $novel->fresh()->toc_fingerprint;

        $this->serveToc(1, 4);
        $this->scraper()->processNovel($novel->fresh());

        $this->assertSame(4, NovelChapter::count());
        $this->assertNotSame($first, $novel->fresh()->toc_fingerprint);
        $this->assertSame(NovelScraper::tocFingerprint(tableOfContentGenerator($novel)), $novel->fresh()->toc_fingerprint);
    }

    public function testUnhealthyRunStoresNoFingerprint(): void
    {
        $this->serveToc(1, 3);
        $novel = $this->novel('Test Novel', ['last_toc_count' => 100, 'toc_fingerprint' => str_repeat('a', 64)]);

        $this->scraper()->processNovel($novel);

        $novel->refresh();
        $this->assertSame('TOC shrank from 100 to 3', $novel->last_toc_issue);
        $this->assertNull($novel->toc_fingerprint, 'cleared before the sync, not stored after a failed run');
        $this->assertNull($novel->toc_full_walk_at);
    }

    public function testDeletedRowFallsBackToTheFullSync(): void
    {
        $this->serveToc(1, 3);
        $novel = $this->novel();
        $this->scraper()->processNovel($novel);

        NovelChapter::where('url', $this->url(2))->forceDelete();
        $this->scraper()->processNovel($novel->fresh());

        $this->assertSame(1, NovelChapter::where('url', $this->url(2))->count(), 'row recreated by the sync');
    }

    // ---- end-matter collisions (precomputed candidates) ------------------------

    /**
     * Several unnumbered end-matter rows (per book), then new chapters: each
     * resolver-numbered end-matter row is moved past the newcomers, numbered
     * rows and the other book's end matter are left alone.
     */
    public function testSeveralEndMatterRowsMovePastNewChapters(): void
    {
        $novel = $this->novel();
        $entry = fn($chapter, $slug, $label, $book = 0) => ['chapter' => $chapter, 'label' => $label, 'url' => "https://novelping.com/chapter/x/{$slug}", 'book' => $book];

        $toc = [];
        foreach (range(1, 5) as $n) {
            $toc[] = $entry($n, "c{$n}", "Chapter {$n}");
        }
        $toc[] = $entry(0, 'afterword', 'Afterword');
        $toc[] = $entry(0, 'epilogue', 'Epilogue');
        $toc[] = $entry(0, 'notes', "Author's Notes");
        $toc[] = $entry(1, 'b2c1', 'Chapter One', 2);
        $toc[] = $entry(0, 'b2-afterword', 'Afterword', 2);

        $scraper = $this->scraper();
        $scraper->syncTableOfContents($novel, $toc);
        ChapterNumberResolver::fixUnnumbered($novel->id);

        $snapshot = fn() => NovelChapter::where('novel_id', $novel->id)->orderBy('id')->get()
            ->mapWithKeys(fn($r) => [$r->url => [(float) $r->chapter, $r->sort_key]])->all();
        $before = $snapshot();

        $toc[] = $entry(6, 'c6', 'Chapter 6');
        $toc[] = $entry(7, 'c7', 'Chapter 7');
        $scraper->syncTableOfContents($novel, $toc);
        $after = $snapshot();

        // Values as produced by the pre-refactor nested loop (checked
        // side by side over randomised TOCs when the lookup was introduced).
        $base = 'https://novelping.com/chapter/x/';
        $this->assertSame([[6.0, 5.9], [7.0, 6.9]], [$before[$base . 'afterword'], $before[$base . 'epilogue']]);
        $this->assertSame([8.0, 7.9], $after[$base . 'afterword'], 'afterword moved past chapter 7');
        $this->assertSame([9.0, 8.9], $after[$base . 'epilogue'], 'epilogue moved past it');
        $this->assertSame([0.0, null], $after[$base . 'notes'], 'never numbered, never a candidate');
        foreach (range(1, 5) as $n) {
            $this->assertSame($before[$base . "c{$n}"], $after[$base . "c{$n}"], "chapter {$n} untouched");
        }
        $this->assertSame($before[$base . 'b2-afterword'], $after[$base . 'b2-afterword'], 'other book untouched');
        $this->assertSame([6.0, 7.0], [$after[$base . 'c6'][0], $after[$base . 'c7'][0]]);
    }
}
