<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Empire Novel incremental TOC walk: between weekly full walks a sweep stops
 * at the first list page whose chapters are all stored, and that short
 * result is never taken for the whole TOC (no shrink, no parking, no
 * fingerprint / count update). Fixtures: tests/fixtures/empirenovel.
 */
class EmpireNovelIncrementalTocTest extends TestCase
{
    use RefreshDatabase;

    private const NOVEL = 'https://www.empirenovel.com/novel/taming-the-villainesses';

    private FakeFetcher $fake;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Pages 1, 2, 6, 12 are real; $routes override (e.g. page 3 => page 6's list). */
    private function fakeToc(array $routes = []): FakeFetcher
    {
        $this->fake = new FakeFetcher($routes + [
            self::NOVEL . '?page=1' => 'empirenovel/toc-taming-the-villainesses-page-1.html',
            self::NOVEL . '?page=2' => 'empirenovel/toc-taming-the-villainesses-page-2.html',
            self::NOVEL . '?page=6' => 'empirenovel/toc-taming-the-villainesses-page-6.html',
            self::NOVEL . '?page=12' => 'empirenovel/toc-taming-the-villainesses-page-12.html',
        ], [
            self::NOVEL . '?page=' => 'empirenovel/toc-page-not-captured.html',
        ]);
        app()->instance(Fetcher::class, $this->fake);

        return $this->fake;
    }

    /** Chapter URLs on one fixture list page (parsed by the walk itself). */
    private function pageUrls(string $fixture): array
    {
        $fake = new FakeFetcher([self::NOVEL . '?page=1' => $fixture], [self::NOVEL . '?page=' => 'empirenovel/toc-page-not-captured.html']);
        app()->instance(Fetcher::class, $fake);
        $urls = array_column(empireNovelToc(self::NOVEL), 'url');
        app()->instance(Fetcher::class, $this->fake ?? $fake);

        return $urls;
    }

    private function set(array $urls): callable
    {
        return fn() => array_fill_keys($urls, true);
    }

    private function novel(array $attributes = []): Novel
    {
        $group = new Group();
        $group->label = 'Empire Novel';
        $group->url = 'https://www.empirenovel.com';
        $group->save();

        $novel = Novel::create([
            'name' => 'Taming The Villainesses',
            'status' => 0,
            'group_id' => $group->id,
            'no_of_chapters' => 0,
            'translator_url' => self::NOVEL,
        ]);
        $novel->forceFill($attributes)->save();

        return $novel->fresh();
    }

    private function storeRows(Novel $novel, array $urls): void
    {
        foreach ($urls as $i => $url) {
            NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 0, 'book' => 0, 'label' => "Stored {$i}", 'url' => $url]);
        }
    }

    private function pages(string $method): array
    {
        return array_map(fn($u) => (int) substr($u, strrpos($u, '=') + 1), $this->fake->urls($method));
    }

    // ---- the walk -----------------------------------------------------------

    public function testUnchangedNovelFetchesOnePage(): void
    {
        $page1 = $this->pageUrls('empirenovel/toc-taming-the-villainesses-page-1.html');
        $this->fakeToc();

        markTocRunIncremental(false);
        $toc = empireNovelToc(self::NOVEL, $this->set($page1));

        $this->assertSame([1], $this->pages('session'));
        $this->assertSame([], $this->fake->urls('plain'), 'no further pages');
        $this->assertTrue(tocRunWasIncremental());
        $this->assertFalse(tocRunWasPartial());
        $this->assertCount(count(array_unique($page1)), $toc);
    }

    public function testNewChaptersOnPagesOneAndTwoStopAtTheFirstFullyStoredPage(): void
    {
        $older = $this->pageUrls('empirenovel/toc-taming-the-villainesses-page-6.html');
        // Page 3 serves page 6's (stored) list: pages 1 and 2 are new.
        $this->fakeToc([self::NOVEL . '?page=3' => 'empirenovel/toc-taming-the-villainesses-page-6.html']);

        markTocRunIncremental(false);
        $toc = empireNovelToc(self::NOVEL, $this->set($older));

        $this->assertSame([2, 3], $this->pages('plain'), 'stops at page 3, never reaches 4..12');
        $this->assertTrue(tocRunWasIncremental());
        $urls = array_column($toc, 'url');
        foreach ([...$this->pageUrls('empirenovel/toc-taming-the-villainesses-page-2.html'), ...$older] as $url) {
            $this->assertContains($url, $urls);
        }
    }

    public function testWalkWithoutStoredUrlsIsFull(): void
    {
        $this->fakeToc();

        markTocRunIncremental(false);
        empireNovelToc(self::NOVEL, $this->set([]));

        $this->assertSame(range(2, 12), $this->pages('plain'));
        $this->assertFalse(tocRunWasIncremental(), 'reached the last page: an ordinary full walk');
    }

    public function testGeneratorPassesTheResolverAndResetsIt(): void
    {
        $page1 = $this->pageUrls('empirenovel/toc-taming-the-villainesses-page-1.html');
        $this->fakeToc();
        $novel = $this->novel();

        tableOfContentGenerator($novel, $this->set($page1));
        $this->assertTrue(tocRunWasIncremental());
        $this->assertNull(tocKnownUrlsResolver(), 'resolver cleared after the call');

        tableOfContentGenerator($novel);
        $this->assertFalse(tocRunWasIncremental());
        $this->assertContains(12, $this->pages('plain'));
    }

    // ---- full-walk policy -----------------------------------------------------

    public function testIncrementalPolicy(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $scraper = new NovelScraper();
        $ok = ['toc_full_walk_at' => '2026-11-10 10:00:00', 'last_toc_count' => 343, 'no_of_chapters' => 370, 'toc_failures' => 0];

        $this->assertTrue($scraper->allowsIncrementalWalk($this->novel($ok)));
        $this->assertFalse($scraper->allowsIncrementalWalk($this->novel($ok), explicit: true), 'one explicit novel');
        $this->assertFalse($scraper->allowsIncrementalWalk($this->novel(['toc_full_walk_at' => null] + $ok)), 'never fully walked');
        $this->assertFalse($scraper->allowsIncrementalWalk($this->novel(['toc_full_walk_at' => '2026-11-07 10:00:00'] + $ok)), '7 days old');
        $this->assertFalse($scraper->allowsIncrementalWalk($this->novel(['no_of_chapters' => 843] + $ok)), 'source advertises more');
        $this->assertFalse($scraper->allowsIncrementalWalk($this->novel(['toc_failures' => 1] + $ok)), 'last run failed');
        $this->assertFalse($scraper->allowsIncrementalWalk($this->novel(['last_toc_count' => null] + $ok)));
    }

    // ---- through novel:toc ----------------------------------------------------

    public function testSweepWalksIncrementallyWithoutTouchingTocHealth(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $page1 = $this->pageUrls('empirenovel/toc-taming-the-villainesses-page-1.html');
        $this->fakeToc();

        $novel = $this->novel([
            'toc_full_walk_at' => '2026-11-12 10:00:00', 'last_toc_count' => 103, 'no_of_chapters' => 103,
            'toc_fingerprint' => str_repeat('f', 64), 'last_toc_issue' => null,
        ]);
        // Everything on page 1 is stored already, and one older pending
        // row has not been listed for 10 days.
        $this->storeRows($novel, array_values(array_unique($page1)));
        $old = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 5, 'book' => 0, 'label' => 'Chapter 5', 'url' => self::NOVEL . '/5']);
        NovelChapter::whereKey($old->id)->update(['last_seen_in_toc_at' => Carbon::now()->subDays(10)]);

        $this->artisan('novel:toc')->assertExitCode(0);

        $this->assertSame([1], $this->pages('session'));
        $this->assertSame([], $this->fake->urls('plain'));

        $novel->refresh();
        $this->assertSame('2026-11-14 10:00:00', (string) $novel->last_toc_at);
        $this->assertSame(103, $novel->last_toc_count, 'a 31-entry incremental list is no shrink');
        $this->assertSame(0, $novel->toc_failures);
        $this->assertNull($novel->last_toc_issue);
        $this->assertSame('2026-11-12 10:00:00', (string) $novel->toc_full_walk_at, 'not a full walk');
        $this->assertNull($old->fresh()->last_failure_reason, 'unlisted rows are not parked');
        $this->assertNull($novel->toc_fingerprint, 'rows were relabelled by the sync: fingerprint invalidated');
    }

    public function testSweepWithNewChaptersCreatesThemAndKeepsOldRows(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $older = $this->pageUrls('empirenovel/toc-taming-the-villainesses-page-6.html');
        $this->fakeToc([self::NOVEL . '?page=3' => 'empirenovel/toc-taming-the-villainesses-page-6.html']);

        $novel = $this->novel(['toc_full_walk_at' => '2026-11-12 10:00:00', 'last_toc_count' => 300, 'no_of_chapters' => 300]);
        $this->storeRows($novel, $older);
        $before = NovelChapter::count();

        $this->artisan('novel:toc')->assertExitCode(0);

        $this->assertSame([2, 3], $this->pages('plain'));
        $this->assertGreaterThan($before, NovelChapter::count(), 'new chapters from pages 1-2 created');
        $this->assertSame($before, NovelChapter::whereIn('url', $older)->count(), 'stored rows kept, none adopted');
        $this->assertSame(300, $novel->fresh()->last_toc_count);
    }

    public function testFullWalkAfterSevenDays(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $page1 = $this->pageUrls('empirenovel/toc-taming-the-villainesses-page-1.html');
        $this->fakeToc();

        $novel = $this->novel(['toc_full_walk_at' => '2026-11-06 10:00:00', 'last_toc_count' => 103, 'no_of_chapters' => 103]);
        $this->storeRows($novel, array_values(array_unique($page1)));

        $this->artisan('novel:toc')->assertExitCode(0);

        $this->assertSame(range(2, 12), $this->pages('plain'), 'every page walked');
        $novel->refresh();
        $this->assertSame('2026-11-14 10:00:00', (string) $novel->toc_full_walk_at);
        $this->assertSame(103, $novel->last_toc_count);
        $this->assertNotNull($novel->toc_fingerprint);
    }

    public function testExplicitNovelIsAlwaysWalkedFully(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $page1 = $this->pageUrls('empirenovel/toc-taming-the-villainesses-page-1.html');
        $this->fakeToc();

        $novel = $this->novel(['toc_full_walk_at' => '2026-11-13 10:00:00', 'last_toc_count' => 103, 'no_of_chapters' => 103]);
        $this->storeRows($novel, array_values(array_unique($page1)));

        $this->artisan('novel:toc', ['novel' => $novel->id])->assertExitCode(0);

        $this->assertSame(range(2, 12), $this->pages('plain'));
    }
}
