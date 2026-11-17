<?php

namespace Tests\Feature;

use App\ChapterPage;
use App\ChapterText;
use App\Console\Commands\ChapterScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Scraping\HostPace;
use App\Scraping\PageDownloader;
use App\Scraping\PageDownloadResult;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Manga chapter downloads: PageDownloader (all or nothing, validation, the
 * previous download kept on failure) and ChapterScraper's manga branch
 * (the same bookkeeping as a text chapter, no chapter_texts row). Network
 * faked; the manga disk is a fake disk.
 */
class MangaDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const CHAPTER_UUID = 'fdfb18b6-8f34-4971-afa2-0f9d1e750470';
    private const AT_HOME = 'https://api.mangadex.org/at-home/server/' . self::CHAPTER_UUID;
    private const NODE = 'https://cmdxd98sb0x3yprd.mangadex.network/data/246a64f8b990f7f329ea1ef6430524d3/';

    private array $pageUrls;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manga');
        config(['novarr.manga.page_delay_ms' => 0]);

        $atHome = json_decode(file_get_contents(base_path('tests/fixtures/mangadex/at-home.json')), true);
        $this->pageUrls = array_map(fn($f) => self::NODE . rawurlencode($f), $atHome['chapter']['data']);
    }

    private function manga(): array
    {
        $group = new Group();
        $group->forceFill(['id' => 9100, 'label' => 'Default', 'url' => 'https://novelping.com'])->save();
        $novel = Novel::create(['name' => 'Giving Love Advice', 'status' => 0, 'group_id' => 9100, 'translator_url' => 'https://mangadex.org/title/9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2']);
        $novel->forceFill(['medium' => 'manga'])->save();
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://mangadex.org/chapter/' . self::CHAPTER_UUID]);

        return [$novel, $chapter];
    }

    private function fetcher(array $images): FakeFetcher
    {
        $fake = new FakeFetcher([self::AT_HOME => 'mangadex/at-home.json'] + array_combine($this->pageUrls, $images));
        $this->app->instance(Fetcher::class, $fake);

        return $fake;
    }

    private function download(NovelChapter $chapter): PageDownloadResult
    {
        return app(PageDownloader::class)->download($chapter->fresh());
    }

    private function leftovers(): array
    {
        return array_values(array_filter(Storage::disk('manga')->allFiles('.tmp')));
    }

    // ---- PageDownloader ------------------------------------------------------

    public function testDownloadsEveryPage(): void
    {
        [$novel, $chapter] = $this->manga();
        $fake = $this->fetcher(['mangadex/page.png', 'mangadex/page.jpg', 'mangadex/page.webp']);

        $result = $this->download($chapter);

        $this->assertTrue($result->ok);
        $this->assertSame(3, $result->pages);
        $this->assertSame(filesize(base_path('tests/fixtures/mangadex/page.png')) + filesize(base_path('tests/fixtures/mangadex/page.jpg')) + filesize(base_path('tests/fixtures/mangadex/page.webp')), $result->bytes);

        $pages = ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->get();
        $this->assertSame([1, 2, 3], $pages->pluck('page')->all());
        $this->assertSame(["{$novel->id}/{$chapter->id}/001.png", "{$novel->id}/{$chapter->id}/002.jpg", "{$novel->id}/{$chapter->id}/003.webp"], $pages->pluck('path')->all());
        $this->assertSame(['image/png', 'image/jpeg', 'image/webp'], $pages->pluck('mime')->all());
        $this->assertSame([8, 12], [$pages[0]->width, $pages[0]->height]);
        foreach ($pages as $page) {
            Storage::disk('manga')->assertExists($page->path);
        }
        $this->assertSame([], $this->leftovers());

        // Every image came with MangaDex's User-Agent and was reported (an @Home node).
        foreach (array_filter($fake->calls, fn($c) => $c['method'] === 'download') as $call) {
            $this->assertSame(['User-Agent' => 'Novarr/1.0'], $call['headers']);
        }
        $this->assertCount(3, array_filter($fake->calls, fn($c) => $c['method'] === 'post_json'));
    }

    public function testAFailingPageLeavesNothingAndKeepsThePreviousDownload(): void
    {
        [$novel, $chapter] = $this->manga();
        $this->fetcher(['mangadex/page.png', 'mangadex/page.png', 'mangadex/page.png']);
        $this->assertTrue($this->download($chapter)->ok);
        $before = ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->get(['id', 'path', 'bytes'])->toArray();

        // Re-download: page 2 is gone on the image server (also after the
        // page list is asked for again).
        $fake = $this->fetcher(['mangadex/page.jpg', FakeFetcher::fail('http_404', 404), 'mangadex/page.jpg']);
        $result = $this->download($chapter);

        $this->assertFalse($result->ok);
        $this->assertSame('partial_pages', $result->category);
        $this->assertStringContainsString('page 2 of 3', $result->message);
        $this->assertSame(2, count(array_filter($fake->calls, fn($c) => $c['url'] === self::AT_HOME)), 'the page list was asked for again once');
        $this->assertSame($before, ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->get(['id', 'path', 'bytes'])->toArray());
        $this->assertSame(["{$novel->id}/{$chapter->id}/001.png", "{$novel->id}/{$chapter->id}/002.png", "{$novel->id}/{$chapter->id}/003.png"], Storage::disk('manga')->files("{$novel->id}/{$chapter->id}"));
        $this->assertSame([], $this->leftovers());
    }

    public function testASecondDownloadReplacesThePages(): void
    {
        [$novel, $chapter] = $this->manga();
        $this->fetcher(['mangadex/page.png', 'mangadex/page.png', 'mangadex/page.png']);
        $this->download($chapter);
        $this->fetcher(['mangadex/page.jpg', 'mangadex/page.jpg', 'mangadex/page.jpg']);

        $this->assertTrue($this->download($chapter)->ok);
        $this->assertSame(["{$novel->id}/{$chapter->id}/001.jpg", "{$novel->id}/{$chapter->id}/002.jpg", "{$novel->id}/{$chapter->id}/003.jpg"], Storage::disk('manga')->files("{$novel->id}/{$chapter->id}"));
        $this->assertSame(3, ChapterPage::where('novel_chapter_id', $chapter->id)->count());
        $this->assertSame([], $this->leftovers());
    }

    public function testAnInvalidImageIsRejected(): void
    {
        [, $chapter] = $this->manga();
        $this->fetcher(['mangadex/page.png', '<html><body>Not an image</body></html>', 'mangadex/page.png']);

        $result = $this->download($chapter);

        $this->assertFalse($result->ok);
        $this->assertSame('partial_pages', $result->category);
        $this->assertStringContainsString('not_an_image', $result->message);
        $this->assertSame(0, ChapterPage::count());
        $this->assertSame([], Storage::disk('manga')->allFiles());
    }

    public function testThePageSizeCap(): void
    {
        [, $chapter] = $this->manga();
        config(['novarr.manga.max_page_bytes' => 300]); // page.png is 98 bytes, page.jpg 694
        $this->fetcher(['mangadex/page.png', 'mangadex/page.jpg', 'mangadex/page.png']);

        $result = $this->download($chapter);

        $this->assertFalse($result->ok);
        $this->assertSame('partial_pages', $result->category);
        $this->assertStringContainsString('too_large', $result->message);
        $this->assertSame(0, ChapterPage::count());
    }

    public function testARateLimitedImageIsRateLimitedWithoutAnotherPageList(): void
    {
        [, $chapter] = $this->manga();
        $fake = $this->fetcher(['mangadex/page.png', FakeFetcher::fail('rate_limited', 429), 'mangadex/page.png']);

        $result = $this->download($chapter);

        $this->assertSame('rate_limited', $result->category);
        $this->assertSame(1, count(array_filter($fake->calls, fn($c) => $c['url'] === self::AT_HOME)));
    }

    public function testPageListFailuresPassThrough(): void
    {
        [, $chapter] = $this->manga();
        $this->app->instance(Fetcher::class, new FakeFetcher([self::AT_HOME => FakeFetcher::fail('http_404', 404)]));
        $this->assertSame('not_found', $this->download($chapter)->category);

        $this->app->instance(Fetcher::class, new FakeFetcher([self::AT_HOME => FakeFetcher::fail('rate_limited', 429)]));
        $this->assertSame('rate_limited', $this->download($chapter)->category);
    }

    public function testATransientImageFailureRetriesWithAFreshPageList(): void
    {
        [, $chapter] = $this->manga();
        $fresh = 'https://fresh.mangadex.network/data/h/';
        $fake = new class([], [], [], $this->pageUrls, $fresh) extends FakeFetcher {
            public int $lists = 0;
            public function __construct(array $r, array $p, array $s, private array $first, private string $fresh)
            {
                parent::__construct($r, $p);
            }
            public function json(string $url, array $headers = [], &$status = null): ?array
            {
                $this->lists++;
                $status = 200;
                $base = $this->lists === 1 ? 'https://cmdxd98sb0x3yprd.mangadex.network' : 'https://fresh.mangadex.network';
                $hash = $this->lists === 1 ? '246a64f8b990f7f329ea1ef6430524d3' : 'h';
                return ['result' => 'ok', 'baseUrl' => $base, 'chapter' => ['hash' => $hash, 'data' => ['a.png', 'b.png'], 'dataSaver' => []]];
            }
        };
        $fake->route(self::NODE . 'a.png', 'mangadex/page.png')
            ->route(self::NODE . 'b.png', FakeFetcher::fail('exception', null))
            ->route($fresh . 'b.png', 'mangadex/page.png');
        $this->app->instance(Fetcher::class, $fake);

        $result = $this->download($chapter);

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertSame(2, $fake->lists);
        $this->assertSame(2, $result->pages);
    }

    // ---- ChapterScraper manga branch -----------------------------------------

    private function scraper(?\Closure $responder = null): object
    {
        $scraper = new class extends ChapterScraper {
            public ?\Closure $responder = null;
            public array $downloads = [];
            public array $texts = [];

            protected function downloadChapterPages(NovelChapter $chapter): PageDownloadResult
            {
                $this->downloads[] = $chapter->id;
                return ($this->responder)($chapter);
            }

            protected function generateChapterDescription($chapter, ?string &$failureReason = null)
            {
                $this->texts[] = $chapter->id;
                return '<p>' . str_repeat('word ', 400) . '</p>';
            }

            protected function pause(int $seconds): void
            {
            }
        };
        $scraper->responder = $responder ?? fn() => PageDownloadResult::success(12, 34567);
        $this->app[Kernel::class]->registerCommand($scraper);

        return $scraper;
    }

    public function testMangaSuccessBookkeeping(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->app->instance(HostPace::class, new HostPace(fn(int $lo, int $hi) => $lo));
        [$novel, $chapter] = $this->manga();
        $chapter->forceFill(['attempts' => 2, 'last_failure_reason' => 'fetch_failed', 'next_attempt_at' => now()->subMinute()])->save();
        Cache::put(ChapterScraper::hostFailuresKey('mangadex.org'), 2, now()->addDay());
        $scraper = $this->scraper();

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $chapter->refresh();
        $this->assertSame([$chapter->id], $scraper->downloads);
        $this->assertSame([], $scraper->texts, 'no text fetch for a manga');
        $this->assertTrue($chapter->status);
        $this->assertSame(12, $chapter->page_count);
        $this->assertNotNull($chapter->download_date);
        $this->assertSame(0, $chapter->attempts);
        $this->assertNull($chapter->last_failure_reason);
        $this->assertNull($chapter->next_attempt_at);
        $this->assertNull($chapter->kind);
        $this->assertSame(0, ChapterText::count(), 'no chapter_texts row');
        $this->assertNull(Cache::get(ChapterScraper::hostFailuresKey('mangadex.org')));
        $this->assertSame(1, (app(HostPace::class)->state('mangadex.org')['clean']));
        $this->assertStringContainsString('(12 pages)', Artisan::output());
        $this->assertSame(0, (int) $novel->fresh()->scrape_failures);
        Carbon::setTestNow();
    }

    public function testMangaFailureBookkeeping(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        [$novel, $chapter] = $this->manga();
        $second = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 2, 'label' => 'Chapter 2', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e93721c']);
        $third = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 3, 'label' => 'Chapter 3', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e93721d']);
        $scraper = $this->scraper(fn() => PageDownloadResult::failure('not_found', 'the source gave no page list (not_found)'));

        Artisan::call('novel:chapter', ['novel' => $novel->id]);
        $output = Artisan::output();

        // Three content failures in a row: the content circuit parks them, as for text.
        $this->assertSame([$chapter->id, $second->id, $third->id], $scraper->downloads);
        foreach ([$chapter, $second, $third] as $row) {
            $row->refresh();
            $this->assertFalse($row->status);
            $this->assertSame('not_found', $row->last_failure_reason);
            $this->assertNotNull($row->next_attempt_at);
        }
        $novel->refresh();
        $this->assertGreaterThan(0, (int) $novel->source_fail_streak, 'content circuit parked the chapters');
        $this->assertStringContainsString("pages could not all be downloaded", $output);
        $this->assertStringNotContainsString('words', $output);
        Carbon::setTestNow();
    }

    public function testMangaRateLimitCoolsTheHostDown(): void
    {
        [$novel, $chapter] = $this->manga();
        NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 2, 'label' => 'Chapter 2', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e93721c']);
        $scraper = $this->scraper(fn() => PageDownloadResult::failure('rate_limited'));

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertTrue(ChapterScraper::hostCoolingDown('mangadex.org'));
        $this->assertSame([$chapter->id], $scraper->downloads, 'stopped after the host went on cooldown');
        $this->assertSame('rate_limited', $chapter->fresh()->last_failure_reason);
        $this->assertSame(1, $chapter->fresh()->attempts, 'blocking failure: attempts never past 1');
    }

    public function testPartialPagesIsSummarisedInPagesNotWords(): void
    {
        $this->assertSame(
            'some page images of the pending chapters could not be downloaded (missing, not an image, or too large)',
            ChapterScraper::summarizeScrapeIssue(['partial_pages' => 2], 250, true)
        );
        $this->assertSame('the source lists no page images for the pending chapters', ChapterScraper::summarizeScrapeIssue(['no_content' => 1], 250, true));
        $this->assertStringContainsString('no chapter text', ChapterScraper::summarizeScrapeIssue(['no_content' => 1], 250));
    }

    public function testSingleChapterDownloadOfAManga(): void
    {
        [, $chapter] = $this->manga();
        $scraper = $this->scraper();

        $this->assertSame(0, Artisan::call('novel:chapter', ['--chapter' => $chapter->id]));
        $this->assertSame(12, $chapter->fresh()->page_count);
        $this->assertSame([], $scraper->texts);
    }

    public function testTextChaptersStillGoThroughTheTextPath(): void
    {
        $novel = Novel::create(['name' => 'A Text Novel', 'status' => 0, 'group_id' => 9101]);
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://text.test/1']);
        $scraper = $this->scraper();

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame([$chapter->id], $scraper->texts);
        $this->assertSame([], $scraper->downloads);
        $this->assertNull($chapter->fresh()->page_count);
        $this->assertSame(1, ChapterText::count());
        $this->assertStringContainsString('(400 words)', Artisan::output());
    }

    // ---- deletion --------------------------------------------------------------

    public function testHardDeleteRemovesPagesSoftDeleteKeepsThem(): void
    {
        [$novel, $chapter] = $this->manga();
        $this->fetcher(['mangadex/page.png', 'mangadex/page.png', 'mangadex/page.png']);
        $this->download($chapter);

        $novel->delete();
        $this->assertSame(3, ChapterPage::count());
        Storage::disk('manga')->assertExists("{$novel->id}/{$chapter->id}/001.png");

        $novel->forceDelete();
        $this->assertSame(0, ChapterPage::count());
        Storage::disk('manga')->assertMissing("{$novel->id}/{$chapter->id}/001.png");
    }

    public function testAChapterMovedToANewUrlLosesItsPages(): void
    {
        [$novel, $chapter] = $this->manga();
        $this->fetcher(['mangadex/page.png', 'mangadex/page.png', 'mangadex/page.png']);
        $this->download($chapter);
        $chapter->forceFill(['status' => 1, 'page_count' => 3])->save();
        $suffixes = [2 => 'd', 3 => 'e', 4 => 'f', 5 => '20', 6 => '21'];
        foreach ($suffixes as $n => $suffix) {
            NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $n, 'label' => "Chapter {$n}", 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e9372' . str_pad($suffix, 2, '1', STR_PAD_LEFT)]);
        }

        $command = new \App\Console\Commands\NovelScraper();
        $command->setLaravel($this->app);
        $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput()));
        $command->syncTableOfContents($novel->fresh(), [
            ['label' => 'Chapter 1', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e93721c', 'chapter' => '1', 'book' => 0],
            ['label' => 'Chapter 2', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e93721d', 'chapter' => '2', 'book' => 0],
            ['label' => 'Chapter 3', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e93721e', 'chapter' => '3', 'book' => 0],
            ['label' => 'Chapter 4', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e93721f', 'chapter' => '4', 'book' => 0],
            ['label' => 'Chapter 5', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e937220', 'chapter' => '5', 'book' => 0],
            ['label' => 'Chapter 6', 'url' => 'https://mangadex.org/chapter/6d04ccd5-14be-41c9-910f-28522e937221', 'chapter' => '6', 'book' => 0],
        ]);

        $chapter->refresh();
        $this->assertFalse($chapter->status, 'queued for re-download');
        $this->assertNull($chapter->page_count);
        $this->assertSame(0, ChapterPage::where('novel_chapter_id', $chapter->id)->count());
        Storage::disk('manga')->assertMissing("{$novel->id}/{$chapter->id}/001.png");
    }
}
