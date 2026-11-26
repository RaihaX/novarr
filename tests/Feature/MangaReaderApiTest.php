<?php

namespace Tests\Feature;

use App\ChapterPage;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * The manga reader's server contract (pages.json, page images, reading
 * mode, progress), the API v1 additions and default exclusions, the
 * creation paths that set `medium`, and the text-only features that skip a
 * manga.
 */
class MangaReaderApiTest extends TestCase
{
    use RefreshDatabase;

    private Novel $manga;
    private Novel $text;
    /** @var NovelChapter[] */
    private array $chapters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = time() - 1;
        Storage::fake('manga');

        $this->text = Novel::create(['name' => 'A Text Novel', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/a-text-novel']);
        $this->manga = Novel::create(['name' => 'A Manga', 'group_id' => 1, 'translator_url' => 'https://mangadex.org/title/9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2']);
        $this->manga->forceFill(['medium' => 'manga'])->save();

        foreach ([1, 2, 3] as $n) {
            $c = NovelChapter::create(['novel_id' => $this->manga->id, 'chapter' => $n, 'label' => "Chapter {$n}", 'url' => "https://mangadex.org/chapter/00000000-0000-4000-8000-00000000000{$n}"]);
            $c->syncStructuredNumber();
            $c->save();
            $this->chapters[$n] = $c;
        }
        // Chapter 2 is downloaded with two pages; 3 is still pending.
        foreach ([1, 2] as $n) {
            $this->chapters[$n]->forceFill(['status' => 1, 'page_count' => $n === 2 ? 2 : 1, 'download_date' => now()])->save();
        }
        $this->page($this->chapters[2], 1, 'image/png', 800, 1200);
        $this->page($this->chapters[2], 2, 'image/jpeg', null, null);
        $this->page($this->chapters[1], 1, 'image/png', 8, 12);
    }

    protected function tearDown(): void
    {
        // downloadCoverImage() writes straight into storage/app/public (not
        // through the faked disk): remove what the creation tests saved.
        foreach (\App\File::pluck('file_path') as $path) {
            $file = storage_path('app/public/' . basename((string) $path));
            if (is_file($file) && filemtime($file) >= $this->startedAt) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private int $startedAt = 0;

    private function page(NovelChapter $chapter, int $page, string $mime, ?int $w, ?int $h): ChapterPage
    {
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $path = "{$chapter->novel_id}/{$chapter->id}/" . ChapterPage::fileName($page, $ext);
        Storage::disk('manga')->put($path, file_get_contents(base_path("tests/fixtures/mangadex/page.{$ext}")));

        return ChapterPage::create(['novel_chapter_id' => $chapter->id, 'page' => $page, 'path' => $path, 'mime' => $mime, 'width' => $w, 'height' => $h, 'bytes' => Storage::disk('manga')->size($path)]);
    }

    // ---- pages.json / page images / reading mode ---------------------------------

    public function testPagesJsonShape(): void
    {
        $c = $this->chapters[2];
        $json = $this->getJson(route('chapters.pages', $c->id))->assertOk()->json();

        // latest_position: the web reader's copy only (see ReadingPositionTest).
        $this->assertSame(['chapter', 'pages', 'prev', 'next', 'reading_mode', 'latest_position'], array_keys($json));
        $this->assertSame([
            'id' => $c->id, 'novel_id' => $this->manga->id, 'label' => 'Chapter 2', 'number' => 2,
            'page_count' => 2, 'read' => false, 'progress' => null,
        ], $json['chapter']);
        $this->assertSame([
            ['page' => 1, 'url' => route('chapters.page', ['id' => $c->id, 'page' => 1]), 'width' => 800, 'height' => 1200],
            ['page' => 2, 'url' => route('chapters.page', ['id' => $c->id, 'page' => 2]), 'width' => null, 'height' => null],
        ], $json['pages']);
        $this->assertSame(['id' => $this->chapters[1]->id, 'label' => 'Chapter 1'], $json['prev']);
        $this->assertSame(['id' => $this->chapters[3]->id, 'label' => 'Chapter 3', 'downloaded' => false], $json['next']);
        $this->assertSame('ltr', $json['reading_mode'], 'no mode chosen: ltr');

        $this->manga->forceFill(['reading_mode' => 'rtl'])->save();
        $first = $this->getJson(route('chapters.pages', $this->chapters[1]->id))->json();
        $this->assertNull($first['prev']);
        $this->assertSame('rtl', $first['reading_mode']);

        // A pending chapter: no pages yet.
        $this->assertSame([], $this->getJson(route('chapters.pages', $this->chapters[3]->id))->json('pages'));

        // A text chapter has no pages.json.
        $textChapter = NovelChapter::create(['novel_id' => $this->text->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://novelping.com/chapter/a/1']);
        $this->getJson(route('chapters.pages', $textChapter->id))->assertNotFound();
    }

    public function testPageStreaming(): void
    {
        $c = $this->chapters[2];
        $response = $this->get(route('chapters.page', ['id' => $c->id, 'page' => 1]));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $cache = $response->headers->get('Cache-Control');
        foreach (['public', 'max-age=31536000', 'immutable'] as $directive) {
            $this->assertStringContainsString($directive, $cache);
        }
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertNotEmpty($response->headers->get('Last-Modified'));
        $this->assertSame(file_get_contents(base_path('tests/fixtures/mangadex/page.png')), $response->baseResponse->getFile()->getContent());
        // No session cookie for an image.
        $this->assertEmpty(array_filter($response->headers->getCookies(), fn($cookie) => str_contains($cookie->getName(), 'session')));

        // Revalidation.
        $this->get(route('chapters.page', ['id' => $c->id, 'page' => 1]), ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);

        $this->get(route('chapters.page', ['id' => $c->id, 'page' => 9]))->assertNotFound();
        $this->get(route('chapters.page', ['id' => $this->chapters[3]->id, 'page' => 1]))->assertNotFound();
        $this->get('/chapters/' . $c->id . '/pages/..%2F..%2Fetc')->assertNotFound();

        // A row whose file is gone is a 404, not a 500.
        Storage::disk('manga')->delete(ChapterPage::where('novel_chapter_id', $c->id)->where('page', 2)->value('path'));
        $this->get(route('chapters.page', ['id' => $c->id, 'page' => 2]))->assertNotFound();

        $this->assertNull($this->readAtOf($c), 'serving pages never marks read');
    }

    public function testReadingModeValidationAndSave(): void
    {
        $this->postJson(route('novels.reading_mode', $this->manga->id), ['mode' => 'vertical'])
            ->assertOk()->assertExactJson(['reading_mode' => 'vertical']);
        $this->assertSame('vertical', $this->manga->fresh()->reading_mode);

        $this->postJson(route('novels.reading_mode', $this->manga->id), ['mode' => 'sideways'])->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->postJson(route('novels.reading_mode', $this->manga->id), [])->assertStatus(422);
        $this->postJson(route('novels.reading_mode', 999999), ['mode' => 'rtl'])->assertNotFound();
        $this->assertSame('vertical', $this->manga->fresh()->reading_mode);
    }

    public function testProgressWorksForAMangaChapter(): void
    {
        $c = $this->chapters[2];
        $this->postJson(route('chapters.progress', $c->id), ['progress' => 50])->assertOk()->assertJson(['success' => true, 'read' => false]);
        $this->assertSame(50, (int) $this->progressOf($c));

        $this->postJson(route('chapters.progress', $c->id), ['progress' => 100, 'read' => true])->assertOk()->assertJson(['read' => true]);
        $this->assertNotNull($this->readAtOf($c));
        $this->assertSame(100, $this->getJson(route('chapters.pages', $c->id))->json('chapter.progress'));
        $this->assertTrue($this->getJson(route('chapters.pages', $c->id))->json('chapter.read'));
    }

    public function testTheMangaViewIsRenderedAndTheChapterIsNotMarkedRead(): void
    {
        $c = $this->chapters[2];
        $html = $this->get(route('chapters.show', $c->id))->assertOk()->getContent();

        $this->assertStringContainsString('id="manga-reader"', $html);
        $this->assertStringContainsString('data-chapter-id="' . $c->id . '"', $html);
        $this->assertStringContainsString('data-pages-url="' . route('chapters.pages', $c->id) . '"', $html);
        $this->assertStringContainsString('data-progress-url="' . route('chapters.progress', $c->id) . '"', $html);
        $this->assertStringContainsString('data-mode-url="' . route('novels.reading_mode', $this->manga->id) . '"', $html);
        $this->assertStringContainsString('data-novel-url="' . route('novels.show', $this->manga->id) . '"', $html);
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString(e(route('chapters.page', ['id' => $c->id, 'page' => 2])), $html);
        $this->assertNull($this->readAtOf($c));

        // The text reader is unchanged: opening a downloaded text chapter marks it read.
        $textChapter = NovelChapter::create(['novel_id' => $this->text->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://novelping.com/chapter/a/1']);
        $textChapter->description = '<p>Hello there.</p>';
        $textChapter->status = 1;
        $textChapter->save();
        $this->assertStringNotContainsString('manga-reader', $this->get(route('chapters.show', $textChapter->id))->assertOk()->getContent());
        $this->assertNotNull($this->readAtOf($textChapter));
    }

    // ---- API v1 --------------------------------------------------------------------

    public function testApiListsExcludeMangaUnlessAsked(): void
    {
        $names = fn(string $query) => array_column($this->getJson('/api/v1/novels' . $query)->assertOk()->json('novels'), 'name');

        $this->assertSame(['A Text Novel'], $names(''));
        $this->assertSame(['A Text Novel'], $names('?medium=novel'));
        $this->assertSame(['A Manga'], $names('?medium=manga'));
        $this->assertEqualsCanonicalizing(['A Manga', 'A Text Novel'], $names('?medium=all'));
        $this->getJson('/api/v1/novels?medium=comics')->assertStatus(422);

        $all = collect($this->getJson('/api/v1/novels?medium=all')->json('novels'))->keyBy('name');
        $this->assertSame('manga', $all['A Manga']['medium']);
        $this->assertSame('novel', $all['A Text Novel']['medium']);

        // Home: the manga being read only shows with the parameter.
        $this->markRead($this->chapters[1], null, now()->subHour(), 100);
        $home = $this->getJson('/api/v1/home')->assertOk()->json();
        $this->assertSame([], array_column(array_column($home['continue'], 'novel'), 'name'));
        $this->assertSame(1, $home['stats']['novels']);
        $home = $this->getJson('/api/v1/home?medium=all')->json();
        $this->assertSame(['A Manga'], array_column(array_column($home['continue'], 'novel'), 'name'));
        $this->assertSame(2, $home['stats']['novels']);
    }

    public function testApiChapterFieldsAndEndpoints(): void
    {
        $c = $this->chapters[2];

        $list = collect($this->getJson("/api/v1/novels/{$this->manga->id}/chapters")->assertOk()->json('chapters'))->keyBy('id');
        $this->assertSame(2, $list[$c->id]['page_count']);
        $this->assertNull($list[$this->chapters[3]->id]['page_count']);

        $this->assertSame('manga', $this->getJson("/api/v1/novels/{$this->manga->id}")->assertOk()->json('novel.medium'));

        // The text endpoint answers a clear 4xx for a manga chapter.
        $this->getJson("/api/v1/chapters/{$c->id}")->assertStatus(422)->assertJson(['medium' => 'manga'])->assertJsonStructure(['message']);

        // The pages endpoint: pages.json with absolute URLs.
        $pages = $this->getJson("/api/v1/chapters/{$c->id}/pages")->assertOk()->json();
        // The same body as the web's pages.json, less the web reader's latest_position.
        $this->assertSame(array_diff_key($this->getJson(route('chapters.pages', $c->id))->json(), ['latest_position' => 1]), $pages);
        $this->assertMatchesRegularExpression('#^https?://[^/]+/chapters/' . $c->id . '/pages/1$#', $pages['pages'][0]['url']);

        $textChapter = NovelChapter::create(['novel_id' => $this->text->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://novelping.com/chapter/a/1']);
        $this->getJson("/api/v1/chapters/{$textChapter->id}/pages")->assertNotFound();
        $this->assertNull($this->getJson("/api/v1/chapters/{$textChapter->id}")->assertOk()->json('chapter.page_count'));
    }

    public function testApiDiscoverHidesMangaDexUnlessAsked(): void
    {
        $ids = fn(string $q) => array_column($this->getJson('/api/v1/discover' . $q)->assertOk()->json('sources'), 'id');

        $this->assertNotContains('mangadex', $ids(''));
        $this->assertSame(['mangadex'], $ids('?medium=manga'));
        $this->assertContains('mangadex', $ids('?medium=all'));

        $this->getJson('/api/v1/discover/browse?type=popular&source=mangadex')->assertStatus(422)->assertJsonValidationErrors('source');
    }

    public function testApiEpubIsRefusedForAManga(): void
    {
        $this->getJson("/api/v1/novels/{$this->manga->id}/epub")->assertStatus(422);
        $this->postJson("/api/v1/novels/{$this->manga->id}/epub")->assertStatus(422);
    }

    // ---- creation sets medium ---------------------------------------------------------

    /** A FakeFetcher whose raw client (downloadCoverImage()) answers the MangaDex cover with an image. */
    private function coverFetcher(array $routes): FakeFetcher
    {
        $fake = new class($routes) extends FakeFetcher {
            public array $coverRequests = [];
            public function client(): \Symfony\Contracts\HttpClient\HttpClientInterface
            {
                return new \Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url, array $options) {
                    $this->coverRequests[] = ['url' => $url, 'headers' => $options['headers'] ?? []];
                    if (!str_starts_with($url, 'https://uploads.mangadex.org/covers/')) {
                        throw new \Tests\Support\UnexpectedFetch("unexpected raw GET {$url}");
                    }
                    return new \Symfony\Component\HttpClient\Response\MockResponse(str_repeat(file_get_contents(base_path('tests/fixtures/mangadex/page.jpg')), 1));
                });
            }
        };
        $this->app->instance(Fetcher::class, $fake);

        return $fake;
    }

    public function testNovelCreateSetsMediumAndReadingMode(): void
    {
        $id = '9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2';
        Storage::fake('public');
        config(['filesystems.disks.public.root' => Storage::disk('public')->path('')]);
        $fake = $this->coverFetcher([
            "https://api.mangadex.org/manga/{$id}?includes[]=cover_art&includes[]=author&includes[]=artist" => 'mangadex/manga.json',
        ]);
        Novel::whereKey($this->manga->id)->forceDelete();

        $exit = Artisan::call('novel:create', ['name' => 'Giving Love Advice', 'url' => "https://mangadex.org/title/{$id}/giving-love-advice"]);

        $this->assertSame(0, $exit, Artisan::output());
        $novel = Novel::where('name', 'Giving Love Advice')->firstOrFail();
        $this->assertSame('manga', $novel->medium);
        $this->assertSame('rtl', $novel->reading_mode);
        $this->assertSame('Yorima Riyo', $novel->author);
        // No text inference for a manga: its origin is MangaDex's originalLanguage.
        $this->assertSame(['translated', 'ja', 'source', 'high'], [$novel->origin, $novel->origin_language, $novel->origin_source, $novel->origin_confidence]);
        $this->assertNotContains('https://www.novelupdates.com', array_map(fn($u) => substr($u, 0, 27), $fake->urls()));
        // The cover came from uploads.mangadex.org with the honest User-Agent.
        $this->assertNotEmpty($fake->coverRequests);
        $this->assertContains('User-Agent: Novarr/1.0', $fake->coverRequests[0]['headers']);
    }

    public function testNovelCreateQueuesTheFirstChapterCheck(): void
    {
        $id = '9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2';
        Storage::fake('public');
        config(['filesystems.disks.public.root' => Storage::disk('public')->path('')]);
        $this->coverFetcher([
            "https://api.mangadex.org/manga/{$id}?includes[]=cover_art&includes[]=author&includes[]=artist" => 'mangadex/manga.json',
        ]);
        Novel::whereKey($this->manga->id)->forceDelete();
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Bus::fake();

        $this->assertSame(0, Artisan::call('novel:create', ['name' => 'Giving Love Advice', 'url' => "https://mangadex.org/title/{$id}"]), Artisan::output());

        $novel = Novel::where('name', 'Giving Love Advice')->firstOrFail();
        \Illuminate\Support\Facades\Bus::assertChained([
            fn(\App\Jobs\RunNovelCommand $job) => true,
            fn(\App\Jobs\RunNovelCommand $job) => true,
            \App\Jobs\FinishNovelCheck::class,
        ]);
        $this->assertSame('running', app(\App\Services\NovelCheck::class)->state($novel->id)['status']);
    }

    public function testMetadataNeverOverwritesAChosenReadingMode(): void
    {
        $id = '9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2';
        Storage::fake('public');
        config(['filesystems.disks.public.root' => Storage::disk('public')->path('')]);
        $this->coverFetcher([
            "https://api.mangadex.org/manga/{$id}?includes[]=cover_art&includes[]=author&includes[]=artist" => 'mangadex/manga.json',
        ]);
        $this->manga->forceFill(['reading_mode' => 'vertical'])->save();

        $command = new class extends \App\Console\Commands\UpdateMetadata {
            protected function pause(int $seconds): void {}
        };
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

        Artisan::call('novel:metadata', ['novel' => $this->manga->id]);

        $this->assertSame('vertical', $this->manga->fresh()->reading_mode);
        $this->manga->forceFill(['reading_mode' => null])->save();
        Artisan::call('novel:metadata', ['novel' => $this->manga->id]);
        $this->assertSame('rtl', $this->manga->fresh()->reading_mode);
    }

    public function testApiAddSetsMedium(): void
    {
        Bus::fake();
        Novel::whereKey($this->manga->id)->forceDelete();

        $response = $this->postJson('/api/v1/novels', ['title' => 'Chainsaw Man', 'url' => 'https://mangadex.org/title/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b'])->assertCreated();
        $this->assertSame('manga', $response->json('novel.medium'));
        $this->assertSame('manga', Novel::where('name', 'Chainsaw Man')->value('medium'));

        $response = $this->postJson('/api/v1/novels', ['title' => 'Shadow Slave', 'url' => 'https://novelping.com/novel/shadow-slave'])->assertCreated();
        $this->assertSame('novel', $response->json('novel.medium'));
    }

    public function testApiAddDoesNotMatchANovelOfTheSameName(): void
    {
        Bus::fake();
        $this->postJson('/api/v1/novels', ['title' => 'A Text Novel', 'url' => 'https://mangadex.org/title/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b'])
            ->assertCreated()->assertJson(['already_in_library' => false]);
    }

    public function testWebStoreSetsMedium(): void
    {
        $this->post(route('novels.store'), ['name' => 'Web Manga', 'translator_url' => 'https://mangadex.org/title/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b'])->assertRedirect();
        $this->assertSame('manga', Novel::where('name', 'Web Manga')->value('medium'));
    }

    /** (A separate test: the router keeps one controller — and its injected Novel — per test.) */
    public function testWebStoreOfANovelIsANovel(): void
    {
        $this->post(route('novels.store'), ['name' => 'Web Novel', 'translator_url' => 'https://novelping.com/novel/web-novel'])->assertRedirect();
        $this->assertSame('novel', Novel::where('name', 'Web Novel')->value('medium'));
    }

    // ---- text-only features skip a manga ------------------------------------------------

    public function testTextOnlyCommandsSkipAManga(): void
    {
        $this->manga->forceFill(['status' => 1])->save();

        $this->assertSame(0, Artisan::call('novel:epub', ['novel' => $this->manga->id]));
        $this->assertStringContainsString('is a manga', Artisan::output());
        $this->assertNull($this->manga->fresh()->epub_generated);

        $this->assertSame(1, Artisan::call('novel:send-to-kindle', ['novel' => $this->manga->id, '--to' => 'me@kindle.com']));
        $this->assertStringContainsString('is a manga', Artisan::output());

        // chaptercleaner would reset every textless chapter: it must not.
        Artisan::call('novel:chaptercleaner', ['novel' => $this->manga->id]);
        $this->assertTrue($this->chapters[2]->fresh()->status);
        Artisan::call('novel:clean_chapter_content', ['novel' => $this->manga->id]);
        $this->assertStringContainsString('is a manga', Artisan::output());
        $this->assertTrue($this->chapters[2]->fresh()->status);
    }

    public function testVerifyCompletionAndOriginSkipAManga(): void
    {
        $command = new class extends \App\Console\Commands\VerifyCompletion {
            public array $checked = [];
            protected function fetchMetadata(Novel $novel): array
            {
                $this->checked[] = $novel->id;
                return emptyNovelUpdatesMetadata();
            }
            protected function pause(int $seconds): void {}
        };
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);
        Artisan::call('novel:verify-completion', ['novel' => $this->manga->id]);
        $this->assertSame([], $command->checked);
        $this->assertStringContainsString('No active novels', Artisan::output());

        Artisan::call('novel:origin', ['novel' => $this->manga->id, '--no-fetch' => true]);
        $this->assertNull($this->manga->fresh()->origin);
    }

    public function testWebTextOnlyEndpointsRefuseAManga(): void
    {
        $this->get(route('novels.download_epub', $this->manga->id))->assertNotFound();
        $this->getJson(route('novels.metadata_candidates', $this->manga->id))->assertStatus(422);
        $this->postJson(route('novels.metadata_choose', $this->manga->id), ['url' => 'https://www.novelupdates.com/series/x/'])->assertStatus(422);

        // OPDS lists ePubs of text novels only.
        $this->manga->forceFill(['epub_generated' => now()])->save();
        $this->assertStringNotContainsString('A Manga', $this->get(route('opds.index'))->assertOk()->getContent());
    }

    public function testNovelPageHidesTextActionsAndShowsPages(): void
    {
        $html = $this->get(route('novels.show', $this->manga->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Download ePub', $html);
        $this->assertStringNotContainsString('Send to my Kindle', $html);
        $this->assertStringNotContainsString('Search the text of this novel', $html);
        $this->assertStringContainsString('2 pages', $html);
        $this->assertStringContainsString('Manga', $html);

        $html = $this->get(route('novels.show', $this->text->id))->assertOk()->getContent();
        $this->assertStringContainsString('Download ePub', $html);
        $this->assertStringContainsString('Send to my Kindle', $html);

        $json = collect($this->getJson(route('novels.chapters_json', $this->manga->id))->json('chapters'))->keyBy('id');
        $this->assertSame(2, $json[$this->chapters[2]->id]['pages']);

        // Library grid: the manga chip.
        // Library grid (the Manga library): the manga chip.
        $library = $this->get(route('novels.index', ['view' => 'grid', 'medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="cover-chip">MANGA</span>', $library);
        $this->assertSame(1, substr_count($library, '<span class="cover-chip">MANGA</span>'));
    }

    public function testDiscoverWebPageOffersMangaDex(): void
    {
        // Separate libraries: manga sources are offered on "Add manga" only.
        $this->get(route('novels.discover', ['medium' => 'manga']))->assertOk()->assertSee('<option value="mangadex">MangaDex (manga)</option>', false);
        $this->get(route('novels.discover', ['medium' => 'novel']))->assertOk()->assertDontSee('<option value="mangadex">', false);
    }

    public function testEmailSummaryCallsAMangaAManga(): void
    {
        $rows = (function () {
            return $this->novelRows(now()->subDay());
        })->call(new \App\Console\Commands\EmailSummary());

        $row = collect($rows)->firstWhere('id', $this->manga->id);
        // Its own "Manga" section says so; the row carries the medium, not an origin tag.
        $this->assertSame('manga', $row['medium']);
        $this->assertNull($row['origin']);
    }

    public function testStatsCountMangaChaptersButNoWords(): void
    {
        $this->markRead($this->chapters[1]);
        $html = $this->get(route('stats.index'))->assertOk();
        // Read chapters count; a manga chapter adds no words (it has no text row).
        $stats = app(\App\Services\ReadingStats::class)->fresh(\App\Support\CurrentReader::ownerId());
        $this->assertSame(1, $stats['read_total']);
        $this->assertSame(0, $stats['words_total']);
    }

    public function testMigrationDefaultsExistingRowsToNovel(): void
    {
        $id = \Illuminate\Support\Facades\DB::table('novels')->insertGetId(['name' => 'Raw Insert', 'group_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('novel', Novel::find($id)->medium);
        $this->assertNull(Novel::find($id)->reading_mode);
        $this->assertFalse(Novel::find($id)->isManga());
        $this->assertSame([$this->manga->id], Novel::manga()->pluck('id')->all());
        $this->assertNotContains($this->manga->id, Novel::text()->pluck('id')->all());
    }
}
