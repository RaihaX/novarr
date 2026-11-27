<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Books\WebNovelCheck;
use App\Console\Commands\GenerateePub;
use App\File;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Sources\BookSource;
use App\Sources\SourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Support\EpubBuilder;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * books:webnovel-check (App\Books\WebNovelCheck): a book NovelUpdates lists
 * as a web novel moves to Novels with the NovelUpdates metadata, keeping
 * its chapters and its ePub; anything less than a confident match stays a
 * book. NovelUpdates is faked (FakeFetcher): its search, the series pages
 * and the cover download.
 */
class BookWebNovelCheckTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://www.novelupdates.com/wp-admin/admin-ajax.php';
    private const SERIES = 'https://www.novelupdates.com/series/';
    private const COVER = 'https://cdn.novelupdates.com/images/cover.png';
    private const RR_SEARCH = 'https://www.royalroad.com/fictions/search?title=';
    private const RR_FICTION = 'https://www.royalroad.com/fiction/21220/mother-of-learning';

    private string $booksDir;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->booksDir = sys_get_temp_dir() . '/novarr-webnovel-' . uniqid();
        config([
            'novarr.books.path' => $this->booksDir,
            'novarr.books.webnovel_check_delay' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        // Covers are written to the real public disk (downloadCoverImage).
        foreach (File::all() as $file) {
            Storage::disk('public')->delete(\App\Services\CoverThumbnails::relative((string) $file->file_path));
            app(\App\Services\CoverThumbnails::class)->delete((string) $file->file_path);
        }
        foreach (Novel::withTrashed()->get() as $novel) {
            @unlink(storage_path('app/ePub/' . GenerateePub::epubFilename($novel)));
        }
        EpubBuilder::cleanup();
        \Illuminate\Support\Facades\File::deleteDirectory($this->booksDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function book(string $title, ?string $author): Novel
    {
        return app(BookLibrary::class)
            ->import(EpubBuilder::epub3(['title' => $title, 'author' => $author]), 'book.epub', lookup: false)['novel']
            ->fresh(['file']);
    }

    /** A NovelUpdates series page. */
    private static function page(array $o = []): string
    {
        $o += [
            'title' => 'Lord of the Mysteries',
            'type' => 'Web Novel',
            'lang' => 'Chinese',
            'authors' => ['Cuttlefish That Loves Diving', '爱潜水的乌贼'],
            'associated' => ['LOTM', '诡秘之主'],
            'genres' => ['Mystery', 'Xuanhuan'],
            'description' => '<p>Waking up to face a string of mysteries, Zhou Mingrui finds himself reincarnated.</p>',
        ];
        $authors = implode('<br>', array_map(fn($a) => '<a class="genre" id="authtag" href="#">' . $a . '</a>', $o['authors']));
        $genres = implode(' ', array_map(fn($g) => '<a class="genre" href="#">' . $g . '</a>', $o['genres']));

        return '<html><body>'
            . '<div class="seriestitlenu">' . $o['title'] . '</div>'
            . '<div class="seriesimg"><img src="' . self::COVER . '"></div>'
            . '<div id="showtype"><a class="genre type" href="#">' . $o['type'] . '</a> <span>(CN)</span></div>'
            . '<div id="seriesgenre">' . $genres . '</div>'
            . '<div id="showlang"><a class="genre lang" href="#">' . $o['lang'] . '</a></div>'
            . '<div id="showauthors">' . $authors . '</div>'
            . '<div id="editstatus">1432 Chapters (Completed)</div>'
            . '<div id="editdescription">' . $o['description'] . '</div>'
            . '<div id="editassociated">' . implode('<br>', $o['associated']) . '</div>'
            . '</body></html>';
    }

    private static function search(string ...$series): string
    {
        return '<ul>' . implode('', array_map(
            fn($title) => '<li><a href="' . self::SERIES . novelSlug($title) . '/">' . $title . '</a></li>',
            $series
        )) . '</ul>';
    }

    /**
     * NovelUpdates answers: the search, series pages by slug, and the cover.
     * Royal Road searches find nothing unless $royalRoad routes them
     * (URL => fixture/body, exact URLs or RR_SEARCH-prefixed).
     */
    private function fake(string|array $search, array $pages = [], array $royalRoad = []): FakeFetcher
    {
        $routes = [self::SEARCH => $search] + $royalRoad;
        foreach ($pages as $slug => $page) {
            $routes[self::SERIES . $slug . '/'] = $page;
        }
        $fake = new class($routes, [self::RR_SEARCH => 'royalroad/search-no-results.html']) extends FakeFetcher {
            public function client(): HttpClientInterface
            {
                return new MockHttpClient(function (string $method, string $url) {
                    $this->calls[] = ['method' => 'client', 'url' => $url];
                    // Only the cover answers (a direct GET of a page that
                    // failed through FlareSolverr fails too).
                    if ($url !== 'https://cdn.novelupdates.com/images/cover.png') {
                        return new MockResponse('', ['http_code' => 503]);
                    }
                    $image = imagecreatetruecolor(300, 450);
                    ob_start();
                    imagepng($image);

                    return new MockResponse((string) ob_get_clean(), ['http_code' => 200]);
                });
            }
        };
        app()->instance(Fetcher::class, $fake);

        return $fake;
    }

    // ------------------------------------------------------------------
    // Matching and converting
    // ------------------------------------------------------------------

    public function test_a_web_novel_moves_to_novels_with_the_novelupdates_metadata_and_keeps_its_chapters_and_file(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $chaptersBefore = NovelChapter::where('novel_id', $book->id)->orderBy('id')->get(['id', 'label', 'url'])->toArray();
        $textBefore = NovelChapter::where('novel_id', $book->id)->orderBy('id')->get()->map->rawText()->all();
        $url = $book->translator_url;
        $file = BookLibrary::path($book);
        $oldCover = $book->file->id;

        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check')
            ->expectsOutputToContain('Lord of Mysteries → Lord of the Mysteries')
            ->expectsOutputToContain('Checked 1: 1 moved to Novels, 0 not web novels, 0 already in Novels; 0 failed.')
            ->assertSuccessful();

        $novel = Novel::with('file')->find($book->id);
        $this->assertSame(Novel::MEDIUM_NOVEL, $novel->medium);
        $this->assertSame('book', $novel->converted_from);
        $this->assertTrue($novel->wasConvertedFromBook());
        $this->assertSame('Lord of the Mysteries', $novel->name);
        $this->assertSame('Cuttlefish That Loves Diving', $novel->author);
        $this->assertStringContainsString('Zhou Mingrui', $novel->description);
        $this->assertSame(['LOTM', '诡秘之主'], $novel->alternative_titles);
        $this->assertSame(self::SERIES . 'lord-of-the-mysteries/', $novel->novelupdates_url);
        $this->assertGreaterThanOrEqual(0.85, (float) $novel->novelupdates_match_score);
        $this->assertTrue($novel->hasConfidentNovelUpdatesMatch());
        $this->assertSame('translated', $novel->origin);
        $this->assertSame('zh', $novel->origin_language);
        $this->assertSame('novelupdates', $novel->origin_source);
        $this->assertSame(['Mystery', 'Xuanhuan'], $novel->tags()->orderBy('name')->pluck('name')->all());
        $this->assertNotNull($novel->webnovel_checked_at);
        // The NovelUpdates cover is the cover now; the book's own is kept for undo.
        $this->assertNotSame($oldCover, $novel->file->id);
        $this->assertNotNull(File::find($oldCover));

        // The chapters, the file and the identities are untouched.
        $this->assertSame($chaptersBefore, NovelChapter::where('novel_id', $book->id)->orderBy('id')->get(['id', 'label', 'url'])->toArray());
        $this->assertSame($textBefore, NovelChapter::where('novel_id', $book->id)->orderBy('id')->get()->map->rawText()->all());
        $this->assertSame($url, $novel->translator_url);
        $this->assertSame($file, BookLibrary::path($novel));
        $this->assertFileExists($file);
        $this->assertTrue($novel->hasLibraryFile());

        // Still claimed by BookSource: no TOC, nothing scraped.
        $this->assertInstanceOf(BookSource::class, SourceResolver::for($novel));
        $this->assertSame([], SourceResolver::for($novel)->tableOfContents($novel));
        $this->assertTrue((bool) $novel->status, 'complete: the TOC and chapter sweeps skip it');

        // Its ePub is built from the chapters (under the new title), never over the original.
        $this->assertNull($novel->epub_generated);
        $this->assertNotSame($file, GenerateePub::epubPath($novel));
    }

    public function test_a_converted_book_is_in_novels_not_books(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $other = $this->book('The Quiet Orchard', 'Ada Wren');
        $other->forceFill(['webnovel_checked_at' => now()])->saveQuietly();
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check')->assertSuccessful();

        $this->assertSame([$other->id], Novel::books()->pluck('id')->all());
        $this->assertSame([$book->id], Novel::text()->pluck('id')->all());

        $this->get('/novels?medium=novel&view=list')->assertOk()->assertSee('Lord of the Mysteries')->assertDontSee('The Quiet Orchard');
        $this->get('/novels?medium=book&view=list')->assertOk()->assertSee('The Quiet Orchard')->assertDontSee('Lord of the Mysteries');
        // Found by its other names too.
        $this->get('/novels?medium=novel&view=list&search=LOTM')->assertOk()->assertSee('Lord of the Mysteries');

        $ids = fn(string $medium) => collect($this->getJson("/api/v1/novels?medium={$medium}")->assertOk()->json('novels'))->pluck('id')->all();
        $this->assertSame([$other->id], $ids('book'));
        $this->assertSame([$book->id], $ids('novel'));

        // The page shows no link to the book:// identity; editing it keeps it a novel.
        $this->get("/novels/{$book->id}")->assertOk()->assertDontSee('book://library', false);
        $this->put("/novels/{$book->id}", ['name' => 'Lord of the Mysteries', 'translator_url' => 'https://example.com/x'])->assertRedirect();
        $fresh = Novel::find($book->id);
        $this->assertSame(Novel::MEDIUM_NOVEL, $fresh->medium);
        $this->assertStringStartsWith(BookLibrary::URL_PREFIX, $fresh->translator_url);
    }

    public function test_a_converted_book_gets_an_epub_built_from_its_chapters(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $original = BookLibrary::path($book);
        $before = md5_file($original);
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);
        $this->artisan('books:webnovel-check')->assertSuccessful();
        $novel = Novel::find($book->id);

        $this->artisan('novel:epub', ['novel' => $novel->id])->assertSuccessful();

        $this->assertFileExists(GenerateePub::epubPath($novel));
        $this->assertSame($before, md5_file($original), 'the original ePub is never rebuilt');
        $this->get("/novels/{$novel->id}/epub")->assertOk()->assertDownload(GenerateePub::epubFilename($novel));
    }

    public function test_refreshing_a_converted_books_metadata_reads_novelupdates(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $fake = $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);
        $this->artisan('books:webnovel-check')->assertSuccessful();
        $fake->calls = [];

        $this->artisan('novel:metadata', ['novel' => $book->id])->assertSuccessful();

        $novel = Novel::find($book->id);
        $this->assertSame(Novel::MEDIUM_NOVEL, $novel->medium);
        $this->assertSame('Cuttlefish That Loves Diving', $novel->author);
        $this->assertSame(NovelChapter::where('novel_id', $book->id)->count(), $novel->no_of_chapters);
        // The saved series page only: no search, no book catalogue.
        $this->assertSame([self::SERIES . 'lord-of-the-mysteries/'], $fake->urls('html'));
        $this->assertSame([], $fake->urls('post'));
    }

    public function test_hard_deleting_a_converted_book_removes_its_file(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);
        $this->artisan('books:webnovel-check')->assertSuccessful();
        $path = BookLibrary::path($book);

        Novel::find($book->id)->forceDelete();

        $this->assertFileDoesNotExist($path);
    }

    public function test_another_author_stays_a_book(): void
    {
        $book = $this->book('Lord of the Mysteries', 'Jane Austen');
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check')
            ->expectsOutputToContain('Checked 1: 0 moved to Novels, 1 not web novels')
            ->assertSuccessful();

        $book = $book->fresh();
        $this->assertSame(Novel::MEDIUM_BOOK, $book->medium);
        $this->assertSame('Lord of the Mysteries', $book->name);
        $this->assertNull($book->novelupdates_url);
        $this->assertNotNull($book->webnovel_checked_at, 'checked once');
    }

    public function test_a_light_or_published_novel_on_novelupdates_stays_a_book(): void
    {
        $book = $this->book('Lord of the Mysteries', 'Cuttlefish That Loves Diving');
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page(['type' => 'Published Novel'])]);

        $this->artisan('books:webnovel-check')->assertSuccessful();

        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
        $this->assertNotNull($book->fresh()->webnovel_checked_at);
    }

    public function test_without_an_author_only_the_exact_title_converts(): void
    {
        // Close (0.9+) but not the same title: stays a book.
        $near = $this->book('Lord of the Mysteries!', null);
        $near->forceFill(['name' => 'Lords of the Mysteries'])->saveQuietly();
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page(['authors' => []])]);
        $this->artisan('books:webnovel-check', ['--id' => [$near->id]])->assertSuccessful();
        $this->assertSame(Novel::MEDIUM_BOOK, $near->fresh()->medium);

        // The exact title (an associated name counts): converts.
        $exact = $this->book('LOTM', null);
        $exact->forceFill(['name' => 'Lord of the Mysteries'])->saveQuietly();
        $this->artisan('books:webnovel-check', ['--id' => [$exact->id]])->assertSuccessful();
        $this->assertSame(Novel::MEDIUM_NOVEL, $exact->fresh()->medium);
    }

    public function test_a_book_without_an_author_against_a_named_author_needs_the_exact_title_too(): void
    {
        $book = $this->book('Lord of the Mystery', null);
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check')->assertSuccessful();

        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
    }

    public function test_a_failed_lookup_leaves_the_book_unchecked(): void
    {
        $book = $this->book('Lord of the Mysteries', 'Cuttlefish That Loves Diving');

        // The search fails (FlareSolverr / Cloudflare).
        $this->fake(FakeFetcher::fail('flaresolverr_error', null));
        $this->artisan('books:webnovel-check')
            ->expectsOutputToContain('Checked 0: 0 moved to Novels, 0 not web novels, 0 already in Novels; 1 failed.')
            ->assertSuccessful();
        $this->assertNull($book->fresh()->webnovel_checked_at);

        // A Cloudflare challenge page instead of results.
        $this->fake('<html><head><title>Just a moment...</title></head></html>');
        $this->artisan('books:webnovel-check')->assertSuccessful();
        $this->assertNull($book->fresh()->webnovel_checked_at);

        // The series page fails.
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => FakeFetcher::fail('http_5xx', 503)]);
        $this->artisan('books:webnovel-check')->assertSuccessful();
        $this->assertNull($book->fresh()->webnovel_checked_at);
        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
    }

    public function test_a_run_stops_after_failures_in_a_row(): void
    {
        config(['novarr.books.webnovel_check_max_failures' => 2]);
        foreach (['A', 'B', 'C'] as $title) {
            $this->book("Book {$title} of Things", 'Someone');
        }
        $fake = $this->fake(FakeFetcher::fail('flaresolverr_error', null));

        $this->artisan('books:webnovel-check')->expectsOutputToContain('2 failed.')->assertSuccessful();

        $this->assertCount(2, $fake->urls('post'));
    }

    public function test_not_on_novelupdates_is_checked_and_stays_a_book(): void
    {
        $book = $this->book('The Quiet Orchard', 'Ada Wren');
        $this->fake('<div class="search_noresults">No results</div>');

        $this->artisan('books:webnovel-check')->expectsOutputToContain('1 not web novels')->assertSuccessful();

        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
        $this->assertNotNull($book->fresh()->webnovel_checked_at);
    }

    public function test_a_series_already_in_novels_is_not_duplicated(): void
    {
        $existing = Novel::create(['name' => 'Lord of the Mysteries', 'translator_url' => 'https://novelfull.com/lotm.html', 'group_id' => 1]);
        $book = $this->book('Lord of the Mysteries', 'Cuttlefish That Loves Diving');
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check')->expectsOutputToContain('1 already in Novels')->assertSuccessful();

        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
        $this->assertNotNull($book->fresh()->webnovel_checked_at);
        $this->assertSame(Novel::MEDIUM_NOVEL, $existing->fresh()->medium);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $other = $this->book('The Quiet Orchard', 'Ada Wren');
        $before = Novel::orderBy('id')->get()->map->getAttributes()->all();
        // Both searches answer with the same series; only one book is it.
        $fake = $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check', ['--dry-run' => true])
            ->expectsOutputToContain('Lord of Mysteries → Lord of the Mysteries (' . self::SERIES . 'lord-of-the-mysteries/, score')
            ->expectsOutputToContain('[dry run] Checked 2: 1 would move to Novels')
            ->assertSuccessful();

        $this->assertSame($before, Novel::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(2, Novel::books()->count());
        $this->assertSame(2, File::count(), 'no cover downloaded');
        $this->assertSame([], $fake->urls('client'));
    }

    public function test_undo_restores_the_book_exactly(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $book->forceFill(['description' => 'The ePub description.', 'origin' => 'original', 'origin_language' => 'en', 'origin_source' => 'inferred', 'origin_confidence' => 'low'])->saveQuietly();
        $before = Novel::find($book->id)->getAttributes();
        $tagsBefore = $book->tags()->orderBy('tags.id')->pluck('tags.id')->all();
        $coverBefore = $book->file->id;
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);
        $this->artisan('books:webnovel-check')->assertSuccessful();
        $converted = Novel::with('file')->find($book->id);
        $addedCover = $converted->file;
        $this->artisan('novel:epub', ['novel' => $book->id])->assertSuccessful();
        $generated = GenerateePub::epubPath($converted);
        $this->assertFileExists($generated);

        $this->artisan('books:webnovel-check', ['--undo' => $book->id])
            ->expectsOutputToContain('is back in Books as "Lord of Mysteries"')
            ->assertSuccessful();

        $after = Novel::with('file')->find($book->id);
        $ignore = ['updated_at', 'webnovel_checked_at', 'webfiction_checked_at', 'webnovel_kept_at'];
        $this->assertEquals(
            array_diff_key($before, array_flip($ignore)),
            array_diff_key($after->getAttributes(), array_flip($ignore))
        );
        $this->assertSame(Novel::MEDIUM_BOOK, $after->medium);
        $this->assertNull($after->converted_from);
        $this->assertNull($after->converted_snapshot);
        $this->assertNotNull($after->webnovel_checked_at, 'never converted again');
        $this->assertSame($tagsBefore, $after->tags()->orderBy('tags.id')->pluck('tags.id')->all());
        $this->assertSame($coverBefore, $after->file->id);
        $this->assertNull(File::find($addedCover->id));
        $this->assertFileDoesNotExist($generated);
        $this->assertFileExists(BookLibrary::path($after));
        $this->assertSame(BookLibrary::path($after), GenerateePub::epubPath($after));

        // The next run leaves it alone.
        $this->artisan('books:webnovel-check')->expectsOutputToContain('No books left to check.')->assertSuccessful();
        $this->assertSame(Novel::MEDIUM_BOOK, $after->fresh()->medium);

        // Undo of something that was never converted is refused.
        $this->artisan('books:webnovel-check', ['--undo' => $book->id])->assertFailed();
    }

    public function test_rerunning_is_idempotent(): void
    {
        $book = $this->book('Lord of Mysteries', 'Cuttlefish That Loves Diving');
        $fake = $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);
        $this->artisan('books:webnovel-check')->assertSuccessful();
        $once = Novel::find($book->id)->getAttributes();
        $calls = count($fake->calls);

        $this->artisan('books:webnovel-check')->expectsOutputToContain('No books left to check.')->assertSuccessful();

        $this->assertSame($once, Novel::find($book->id)->getAttributes());
        $this->assertSame($calls, count($fake->calls), 'nothing looked up again');
        // --id only takes books: a converted one is not checked again either.
        $this->artisan('books:webnovel-check', ['--id' => [$book->id]])->expectsOutputToContain('None of those IDs is a book.')->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // Rule A: web-fiction evidence in the book's own ePub (no network)
    // ------------------------------------------------------------------

    /** A book imported from an ePub with these provenance overrides (EpubBuilder::epub3). */
    private function bookWith(string $title, ?string $author, array $epub): Novel
    {
        return app(BookLibrary::class)
            ->import(EpubBuilder::epub3(['title' => $title, 'author' => $author] + $epub), 'book.epub', lookup: false)['novel']
            ->fresh(['file']);
    }

    /** A FanFicFare download of a storiesonline.net story, as found in production. */
    private function fanFicFareBook(string $title = 'The Long Summer', ?string $author = 'Lazlo Zalezac'): Novel
    {
        return $this->bookWith($title, $author, [
            'publisher' => 'storiesonline.net',
            'sources' => ['http://storiesonline.net/s/68384'],
            'contributors' => [['FanFicFare [https://github.com/JimmXinu/FanFicFare]', 'bkp']],
            'identifiers' => [
                ['fanficfare-uid:storiesonline.net-u1234-s68384', null, 'fanficfare-uid'],
                ['http://storiesonline.net/s/68384', 'URL', null],
            ],
        ]);
    }

    /** A Fetcher that answers nothing: any request is recorded and fails the test. */
    private function noNetwork(): FakeFetcher
    {
        $fake = new FakeFetcher();
        app()->instance(Fetcher::class, $fake);

        return $fake;
    }

    public function test_epub_metadata_reads_sources_producers_and_identifiers(): void
    {
        $epub = \App\Books\Epub::open(EpubBuilder::epub3([
            'publisher' => 'storiesonline.net',
            'sources' => ['http://storiesonline.net/s/68384'],
            'contributors' => [['FanFicFare [https://github.com/JimmXinu/FanFicFare]', 'bkp'], ['Some Editor', 'edt']],
            'identifiers' => [['fanficfare-uid:storiesonline.net-u1-s2', null, 'fanficfare-uid'], ['https://storiesonline.net/s/2', 'URL', null]],
        ]));
        $meta = $epub->metadata();
        $epub->close();

        $this->assertSame(['http://storiesonline.net/s/68384', 'https://storiesonline.net/s/2'], $meta['source_urls']);
        $this->assertSame(['FanFicFare [https://github.com/JimmXinu/FanFicFare]'], $meta['producers']);
        $this->assertSame('storiesonline.net', $meta['publisher']);
        $this->assertContains(['value' => 'fanficfare-uid:storiesonline.net-u1-s2', 'scheme' => null, 'id' => 'fanficfare-uid'], $meta['identifiers']);
        $this->assertContains(['value' => 'https://storiesonline.net/s/2', 'scheme' => 'URL', 'id' => null], $meta['identifiers']);
    }

    public function test_web_fiction_evidence_rules(): void
    {
        $evidence = fn(array $meta) => \App\Books\WebFictionEvidence::fromMetadata($meta + ['source_urls' => [], 'identifiers' => [], 'producers' => [], 'publisher' => null]);

        $this->assertSame('web fiction from spacebattles.com', $evidence(['source_urls' => ['https://forums.spacebattles.com/threads/x.123/']])['reason']);
        $this->assertSame('web fiction from archiveofourown.org', $evidence(['source_urls' => ['https://archiveofourown.org/works/1']])['reason']);
        $this->assertSame('royalroad.com', $evidence(['source_urls' => ['https://www.royalroad.com/fiction/21220']])['host']);
        $this->assertFalse($evidence(['source_urls' => ['https://www.royalroad.com/fiction/21220']])['translated']);
        $this->assertTrue($evidence(['source_urls' => ['https://www.webnovel.com/book/1']])['translated']);
        // Novarr's own novel sources count too.
        $this->assertSame('novelfire.net', $evidence(['source_urls' => ['https://novelfire.net/book/x']])['host']);
        $this->assertSame('royalroad.com', $evidence(['publisher' => 'Royal Road'])['host']);
        $this->assertSame('scribblehub.com', $evidence(['publisher' => 'www.scribblehub.com'])['host']);
        $this->assertSame('fanfiction.net', $evidence(['identifiers' => [['value' => 'fanficfare-uid:www.fanfiction.net-u1-s2', 'scheme' => null, 'id' => null]]])['host']);
        $this->assertSame('web fiction (FicHub download)', $evidence(['producers' => ['FicHub [https://fichub.net]']])['reason']);

        // Not evidence: Calibre, a publisher, an unrelated URL, a look-alike host.
        $this->assertNull($evidence(['producers' => ['calibre (7.1.0) [https://calibre-ebook.com]'], 'identifiers' => [['value' => 'urn:uuid:1', 'scheme' => 'uuid', 'id' => 'uuid_id']]]));
        $this->assertNull($evidence(['publisher' => 'Tor Books']));
        $this->assertNull($evidence(['source_urls' => ['https://www.amazon.com/dp/1']]));
        $this->assertNull($evidence(['source_urls' => ['https://notroyalroad.com/x']]));
    }

    public function test_a_fanficfare_storiesonline_download_moves_to_novels_by_its_epub_without_a_lookup(): void
    {
        $book = $this->fanFicFareBook();
        $tags = $book->tags()->orderBy('tags.id')->pluck('tags.id')->all();
        $cover = $book->file->id;
        $fake = $this->noNetwork();

        $this->artisan('books:webnovel-check')
            ->expectsOutputToContain('The Long Summer (web fiction from storiesonline.net: http://storiesonline.net/s/68384)')
            ->expectsOutputToContain('Checked 1: 1 moved to Novels, 0 not web novels, 0 already in Novels; 0 failed. Moved 1 by their ePub.')
            ->assertSuccessful();

        $this->assertSame([], $fake->calls, 'no network');
        $novel = Novel::with('file')->find($book->id);
        $this->assertSame(Novel::MEDIUM_NOVEL, $novel->medium);
        $this->assertTrue($novel->wasConvertedFromBook());
        $this->assertSame('web fiction from storiesonline.net', $novel->converted_reason);
        $this->assertSame('http://storiesonline.net/s/68384', $novel->web_source_url);
        // Its own metadata stays.
        $this->assertSame('The Long Summer', $novel->name);
        $this->assertSame('Lazlo Zalezac', $novel->author);
        $this->assertSame($tags, $novel->tags()->orderBy('tags.id')->pluck('tags.id')->all());
        $this->assertSame($cover, $novel->file->id);
        $this->assertNull($novel->novelupdates_url);
        $this->assertStringStartsWith(BookLibrary::URL_PREFIX, $novel->translator_url);
        // English original web fiction.
        $this->assertSame('original', $novel->origin);
        $this->assertSame('en', $novel->origin_language);
        $this->assertSame('source', $novel->origin_source);
        $this->assertNotNull($novel->webnovel_checked_at);
        $this->assertNotNull($novel->webfiction_checked_at);
        $this->assertSame([$book->id], Novel::text()->pluck('id')->all());

        // The page says why, links the source, and offers the owner Undo.
        $this->get("/novels/{$book->id}")->assertOk()
            ->assertSee('Moved from Books: web fiction from storiesonline.net')
            ->assertSee('href="http://storiesonline.net/s/68384"', false)
            ->assertSee(route('novels.undo_conversion', $book->id), false);
    }

    public function test_world_literature_company_sol_ids_convert_in_evidence_only_mode(): void
    {
        $book = $this->bookWith('A Storiesonline Mirror', 'Some Author', [
            'publisher' => 'World Literature Company',
            'identifiers' => [['68384', 'WLC-SOL-ID', null]],
        ]);
        $fake = $this->noNetwork();

        $this->artisan('books:webnovel-check', ['--evidence-only' => true])
            ->expectsOutputToContain('Checked 1: 1 moved to Novels')
            ->assertSuccessful();

        $this->assertSame([], $fake->calls);
        $novel = Novel::find($book->id);
        $this->assertSame(Novel::MEDIUM_NOVEL, $novel->medium);
        $this->assertSame('web fiction from storiesonline.net (World Literature Company)', $novel->converted_reason);
        $this->assertNull($novel->web_source_url);
    }

    public function test_a_calibre_only_book_is_no_evidence_and_evidence_only_mode_needs_no_network(): void
    {
        $calibre = $this->bookWith('An Ordinary Novel', 'Ada Wren', [
            'contributors' => [['calibre (7.1.0) [https://calibre-ebook.com]', 'bkp']],
            'identifiers' => [['0f1e2d3c-1111-2222-3333-444455556666', 'calibre', 'calibre_id']],
        ]);
        // Looked up on NovelUpdates before the evidence pass existed.
        $older = $this->fanFicFareBook('An Older Download');
        $older->forceFill(['webnovel_checked_at' => now()->subDay()])->saveQuietly();
        $fake = $this->noNetwork();

        $this->artisan('books:webnovel-check', ['--evidence-only' => true])
            ->expectsOutputToContain('Checked 2: 1 moved to Novels, 1 not web novels')
            ->assertSuccessful();

        $this->assertSame([], $fake->calls, 'no network in --evidence-only');
        $calibre = $calibre->fresh();
        $this->assertSame(Novel::MEDIUM_BOOK, $calibre->medium);
        $this->assertNotNull($calibre->webfiction_checked_at, 'read once');
        $this->assertNull($calibre->webnovel_checked_at, 'still to be looked up');
        $this->assertSame(Novel::MEDIUM_NOVEL, $older->fresh()->medium);

        // Nothing left for the evidence pass; the lookup still has the Calibre book.
        $this->artisan('books:webnovel-check', ['--evidence-only' => true])->expectsOutputToContain('No books left to check.')->assertSuccessful();
        $this->assertSame([$calibre->id], WebNovelCheck::pending()->pluck('id')->all());
        // --all reads every book's ePub again (still no network).
        $this->artisan('books:webnovel-check', ['--evidence-only' => true, '--all' => true])
            ->expectsOutputToContain('Checked 1: 0 moved to Novels, 1 not web novels')
            ->assertSuccessful();
        $this->assertSame([], $fake->calls);
    }

    public function test_a_book_looked_up_before_still_gets_the_evidence_pass_in_the_nightly_run(): void
    {
        $book = $this->fanFicFareBook();
        $book->forceFill(['webnovel_checked_at' => now()->subDay()])->saveQuietly();
        $fake = $this->noNetwork();

        $this->artisan('books:webnovel-check')->expectsOutputToContain('1 moved to Novels')->assertSuccessful();

        $this->assertSame([], $fake->calls, 'already looked up: only the ePub is read');
        $this->assertSame(Novel::MEDIUM_NOVEL, $book->fresh()->medium);
    }

    public function test_web_fiction_already_in_novels_is_not_duplicated(): void
    {
        $existing = Novel::create(['name' => 'The Long Summer', 'author' => 'Lazlo Zalezac', 'translator_url' => 'https://novelfull.com/long-summer.html', 'group_id' => 1]);
        $book = $this->fanFicFareBook();
        // Same title, another author: not the same story.
        $other = $this->fanFicFareBook('Placeholder Summer', 'Someone Else Entirely');
        $other->forceFill(['name' => 'The Long Summer'])->saveQuietly();
        $this->noNetwork();

        $this->artisan('books:webnovel-check', ['--evidence-only' => true])
            ->expectsOutputToContain('already in Novels as "The Long Summer" (#' . $existing->id . ')')
            ->expectsOutputToContain('Checked 2: 1 moved to Novels, 0 not web novels, 1 already in Novels; 0 failed.')
            ->assertSuccessful();

        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
        $this->assertNotNull($book->fresh()->webfiction_checked_at);
        $this->assertNotNull($book->fresh()->webnovel_checked_at, 'no lookup needed either');
        $this->assertSame(Novel::MEDIUM_NOVEL, $other->fresh()->medium);
    }

    public function test_a_missing_epub_is_tried_again(): void
    {
        $book = $this->fanFicFareBook();
        @unlink(BookLibrary::path($book));
        $this->noNetwork();

        $this->artisan('books:webnovel-check', ['--evidence-only' => true])
            ->expectsOutputToContain('its ePub file is missing')
            ->expectsOutputToContain('1 failed.')
            ->assertSuccessful();

        $this->assertNull($book->fresh()->webfiction_checked_at);
        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
    }

    public function test_a_translation_hosts_download_takes_the_novelupdates_metadata_when_listed(): void
    {
        $book = $this->bookWith('Lord of Mysteries', 'Cuttlefish That Loves Diving', ['sources' => ['https://www.webnovel.com/book/lord-of-the-mysteries_11022733006234505']]);
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check')->expectsOutputToContain('1 moved to Novels')->assertSuccessful();

        $novel = Novel::find($book->id);
        $this->assertSame('Lord of the Mysteries', $novel->name);
        $this->assertSame('web fiction from webnovel.com, listed on NovelUpdates', $novel->converted_reason);
        $this->assertSame('https://www.webnovel.com/book/lord-of-the-mysteries_11022733006234505', $novel->web_source_url);
        $this->assertSame('zh', $novel->origin_language);

        // Without the network (or unlisted): converted as a translation by its ePub alone.
        $other = $this->bookWith('Some Cultivation Story', 'Er Gen', ['sources' => ['https://www.wuxiaworld.com/novel/some']]);
        $this->noNetwork();
        $this->artisan('books:webnovel-check', ['--evidence-only' => true])->assertSuccessful();
        $other = $other->fresh();
        $this->assertSame(Novel::MEDIUM_NOVEL, $other->medium);
        $this->assertSame('translated', $other->origin);
        $this->assertSame('web fiction from wuxiaworld.com', $other->converted_reason);
    }

    public function test_undo_from_the_page_puts_an_evidence_conversion_back_for_good(): void
    {
        $book = $this->fanFicFareBook();
        $before = Novel::find($book->id)->getAttributes();
        $this->noNetwork();
        $this->artisan('books:webnovel-check', ['--evidence-only' => true])->assertSuccessful();
        $this->assertSame(Novel::MEDIUM_NOVEL, $book->fresh()->medium);

        $this->post(route('novels.undo_conversion', $book->id))
            ->assertRedirect(route('novels.show', $book->id))
            ->assertSessionHas('status');

        $after = Novel::find($book->id);
        $ignore = array_flip(['updated_at', 'webnovel_checked_at', 'webfiction_checked_at', 'webnovel_kept_at']);
        $this->assertEquals(array_diff_key($before, $ignore), array_diff_key($after->getAttributes(), $ignore));
        $this->assertSame(Novel::MEDIUM_BOOK, $after->medium);
        $this->assertNull($after->converted_reason);
        $this->assertNull($after->web_source_url);
        $this->assertNotNull($after->webnovel_kept_at);
        $this->get("/novels/{$book->id}")->assertOk()->assertDontSee('Moved from Books');

        // Never moved again, not even by --all.
        $this->artisan('books:webnovel-check', ['--evidence-only' => true, '--all' => true])->expectsOutputToContain('No books left to check.')->assertSuccessful();
        $this->assertSame(Novel::MEDIUM_BOOK, $after->fresh()->medium);
        // A second undo is refused.
        $this->post(route('novels.undo_conversion', $book->id))->assertRedirect()->assertSessionHas('error');
    }

    public function test_the_undo_route_is_admin_only(): void
    {
        $this->assertSame(\App\Support\RouteAccess::ADMIN, \App\Support\RouteAccess::classify(
            \Illuminate\Support\Facades\Route::getRoutes()->getByName('novels.undo_conversion')
        ));
        $this->assertSame(['POST'], \Illuminate\Support\Facades\Route::getRoutes()->getByName('novels.undo_conversion')->methods());
    }

    // ------------------------------------------------------------------
    // Rule B: NovelUpdates title variants
    // ------------------------------------------------------------------

    public function test_title_variants(): void
    {
        $this->assertSame(['Lord of the Mysteries'], WebNovelCheck::titleVariants('Lord of the Mysteries Vol. 1'));
        $this->assertSame(['Lord of the Mysteries'], WebNovelCheck::titleVariants('Lord of the Mysteries (Light Novel)'));
        $this->assertSame(['Mother of Learning'], WebNovelCheck::titleVariants('Mother of Learning, Book 2'));
        $this->assertSame(['Solo Leveling'], WebNovelCheck::titleVariants('Solo Leveling Volume II (Novel)'));
        $this->assertSame(['12 Hours After'], WebNovelCheck::titleVariants('12 Hours After: The Beginning'));
        $this->assertSame(['Overgeared'], WebNovelCheck::titleVariants('Overgeared - Part One'));
        $this->assertSame([], WebNovelCheck::titleVariants('The Quiet Orchard'));
        $this->assertSame([], WebNovelCheck::titleVariants('Book of Things'));
    }

    public function test_a_title_variant_finds_the_web_novel_on_novelupdates(): void
    {
        $book = $this->book('Lord of the Mysteries Vol. 1', 'Cuttlefish That Loves Diving');
        $results = self::search('Lord of the Mysteries');
        // Only the plain title finds the series.
        $fake = new class([self::SERIES . 'lord-of-the-mysteries/' => self::page()], [self::RR_SEARCH => 'royalroad/search-no-results.html']) extends FakeFetcher {
            public string $results = '';
            public array $queries = [];

            public function post(string $url, string $postData, ?string &$reason = null): ?string
            {
                parse_str($postData, $form);
                $this->queries[] = $form['strOne'] ?? '';
                $this->calls[] = ['method' => 'post', 'url' => $url];

                return ($form['strOne'] ?? '') === 'Lord of the Mysteries' ? $this->results : '<div class="search_noresults">No results</div>';
            }

            public function client(): HttpClientInterface
            {
                return new MockHttpClient(fn() => new MockResponse('', ['http_code' => 404]));
            }
        };
        $fake->results = $results;
        app()->instance(Fetcher::class, $fake);

        $this->artisan('books:webnovel-check')->expectsOutputToContain('1 moved to Novels')->assertSuccessful();

        $this->assertSame(['Lord of the Mysteries Vol. 1', 'Lord of the Mysteries'], $fake->queries);
        $novel = Novel::find($book->id);
        $this->assertSame(Novel::MEDIUM_NOVEL, $novel->medium);
        $this->assertSame('Lord of the Mysteries', $novel->name);
        $this->assertSame(WebNovelCheck::REASON_NOVELUPDATES, $novel->converted_reason);
        $this->get("/novels/{$book->id}")->assertOk()->assertSee('Moved from Books: listed on NovelUpdates');
    }

    public function test_a_title_variant_still_needs_the_author(): void
    {
        $book = $this->book('Lord of the Mysteries Vol. 1', 'Jane Austen');
        $this->fake(self::search('Lord of the Mysteries'), ['lord-of-the-mysteries' => self::page()]);

        $this->artisan('books:webnovel-check')->expectsOutputToContain('1 not web novels')->assertSuccessful();

        $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
    }

    public function test_a_korean_web_novel_type_with_its_suffix_converts(): void
    {
        $this->assertTrue(WebNovelCheck::isWebType('web novel (kr)'));
        $this->assertFalse(WebNovelCheck::isWebType('light novel (jp)'));
        $this->assertFalse(WebNovelCheck::isWebType('published novel (kr)'));
    }

    // ------------------------------------------------------------------
    // Rule C: Royal Road
    // ------------------------------------------------------------------

    public function test_a_royal_road_fiction_by_the_same_author_moves_to_novels(): void
    {
        $book = $this->book('Mother of Learning', 'nobody103');
        $fake = $this->fake('<div class="search_noresults">No results</div>', [], [
            self::RR_SEARCH . 'Mother+of+Learning' => 'royalroad/search-mother-of-learning.html',
            self::RR_FICTION => 'royalroad/fiction-21220-mother-of-learning.html',
        ]);

        $this->artisan('books:webnovel-check')
            ->expectsOutputToContain('Mother of Learning (on Royal Road: ' . self::RR_FICTION . ')')
            ->expectsOutputToContain('Moved 1 by Royal Road.')
            ->assertSuccessful();

        $novel = Novel::find($book->id);
        $this->assertSame(Novel::MEDIUM_NOVEL, $novel->medium);
        $this->assertSame(WebNovelCheck::REASON_ROYALROAD, $novel->converted_reason);
        $this->assertSame(self::RR_FICTION, $novel->web_source_url);
        $this->assertSame('Mother of Learning', $novel->name);
        $this->assertSame('nobody103', $novel->author);
        $this->assertSame('original', $novel->origin);
        $this->assertStringStartsWith(BookLibrary::URL_PREFIX, $novel->translator_url);
        $this->assertContains(self::RR_FICTION, $fake->urls());
    }

    public function test_a_royal_road_near_miss_stays_a_book(): void
    {
        $routes = [
            self::RR_SEARCH . 'Mother+of+Learning' => 'royalroad/search-mother-of-learning.html',
            self::RR_SEARCH . 'Mother+of+Learn' => 'royalroad/search-mother-of-learning.html',
            self::RR_FICTION => 'royalroad/fiction-21220-mother-of-learning.html',
        ];
        // The same title by another author.
        $other = $this->book('Mother of Learning', 'Jane Doe');
        // Not the same title (the search still lists Mother of Learning).
        $near = $this->book('Mother of Learn', 'nobody103');
        // No author: never matched on Royal Road.
        $anonymous = $this->book('Anonymous Placeholder', null);
        $anonymous->forceFill(['name' => 'Mother of Learning'])->saveQuietly();
        $this->fake('<div class="search_noresults">No results</div>', [], $routes);

        $this->artisan('books:webnovel-check')
            ->expectsOutputToContain('Checked 3: 0 moved to Novels, 3 not web novels')
            ->assertSuccessful();

        foreach ([$other, $near, $anonymous] as $book) {
            $this->assertSame(Novel::MEDIUM_BOOK, $book->fresh()->medium);
            $this->assertNotNull($book->fresh()->webnovel_checked_at);
        }
    }

    public function test_a_failed_royal_road_search_is_tried_again(): void
    {
        $book = $this->book('Mother of Learning', 'nobody103');
        $this->fake('<div class="search_noresults">No results</div>', [], [self::RR_SEARCH . 'Mother+of+Learning' => FakeFetcher::fail('http_5xx', 503)]);

        $this->artisan('books:webnovel-check')->expectsOutputToContain('Royal Road search failed')->assertSuccessful();

        $this->assertNull($book->fresh()->webnovel_checked_at);
        $this->assertNotNull($book->fresh()->webfiction_checked_at, 'the ePub was read');
    }

    // ------------------------------------------------------------------
    // Author names
    // ------------------------------------------------------------------

    public function test_author_names_match_loosely_but_not_another_person(): void
    {
        $this->assertTrue(WebNovelCheck::authorsMatch('Er Gen', ['Ergen']));
        $this->assertTrue(WebNovelCheck::authorsMatch('Gen, Er', ['Er Gen']));
        $this->assertTrue(WebNovelCheck::authorsMatch('Mòxiāng Tóngxiù', ['Mo Xiang Tong Xiu']));
        $this->assertTrue(WebNovelCheck::authorsMatch('Tang Jia San Shao', ['Tangjia Sanshao']));
        $this->assertTrue(WebNovelCheck::authorsMatch('Smith, John', ['John Smith']));
        $this->assertTrue(WebNovelCheck::authorsMatch('爱潜水的乌贼', ['Cuttlefish That Loves Diving', '爱潜水的乌贼']));
        $this->assertTrue(WebNovelCheck::authorsMatch('Er Gen (耳根)', ['Er Gen']));
        $this->assertTrue(WebNovelCheck::authorsMatch('Ada Wren, Er Gen', ['Er Gen']));
        $this->assertTrue(WebNovelCheck::authorsMatch('Cuttlefish That Loves Diving', ['Cuttlefish that loves Diving']));

        $this->assertFalse(WebNovelCheck::authorsMatch('Joan Smith', ['John Smith']));
        $this->assertFalse(WebNovelCheck::authorsMatch('Jane Austen', ['Cuttlefish That Loves Diving']));
        $this->assertFalse(WebNovelCheck::authorsMatch('耳根', ['爱潜水的乌贼']));
        $this->assertFalse(WebNovelCheck::authorsMatch('', ['Er Gen']));
    }
}
