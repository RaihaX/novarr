<?php

namespace Tests\Feature;

use App\ChapterPage;
use App\Comics\ComicImporter;
use App\Comics\ComicInbox;
use App\Comics\ComicLibrary;
use App\Comics\ComicVine;
use App\Comics\PageExtractor;
use App\DownloadGrab;
use App\Novel;
use App\NovelChapter;
use App\NovelRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePageExtractor;
use Tests\Support\UsesAccounts;
use Tests\TestCase;
use ZipArchive;

/**
 * Comics & graphic novels (medium 'comic', App\Comics): the comics inbox
 * and its importer (CBZ with ComicInfo.xml through the real extractor; CBR /
 * PDF through a fake one: no unar or pdftoppm in tests), series matching,
 * duplicates, _failed, torrents kept seeding, requests approved on arrival;
 * Comic Vine (faked) and the fallbacks without a key; the library, reader,
 * API and pages.
 */
class ComicsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private FakePageExtractor $extractor;

    private string $page;

    /** What the faked HTTP answers (every request): the catalogues find nothing by default. */
    private \Closure $http;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manga');
        Storage::fake('public');
        File::deleteDirectory(dirname(ComicLibrary::inbox()));
        File::ensureDirectoryExists(ComicLibrary::inbox());
        config(['novarr.comics.settle_seconds' => 0]);
        $this->extractor = new FakePageExtractor();
        $this->app->instance(PageExtractor::class, $this->extractor);
        $this->page = (string) file_get_contents(base_path('tests/fixtures/mangadex/page.jpg'));
        // The catalogues answer nothing (no comic there) unless a test says otherwise.
        $this->http = fn() => Http::response(['items' => [], 'docs' => []]);
        Http::fake(fn(\Illuminate\Http\Client\Request $request) => ($this->http)($request));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname(ComicLibrary::inbox()));
        foreach (glob(storage_path('app/cbz-cache') . '/*') ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // --- Fixtures ----------------------------------------------------------------

    /** A real CBZ in the inbox (or at $relative inside it): $pages JPEGs, out of order in the archive, plus junk. */
    private function cbz(string $relative, int $pages = 3, ?string $comicInfo = null): string
    {
        $path = ComicLibrary::inbox() . '/' . $relative;
        File::ensureDirectoryExists(dirname($path));
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        for ($i = $pages; $i >= 1; $i--) {
            $zip->addFromString('Saga/' . $i . '.jpg', $this->page);
        }
        $zip->addFromString('__MACOSX/Saga/._1.jpg', 'resource fork');
        $zip->addFromString('Saga/Thumbs.db', 'junk');
        $zip->addFromString('Saga/notes.txt', 'not a page');
        $zip->addFromString('../escape.jpg', $this->page);
        if ($comicInfo !== null) {
            $zip->addFromString('ComicInfo.xml', $comicInfo);
        }
        $zip->close();

        return $path;
    }

    private function drop(string $relative, string $bytes): string
    {
        $path = ComicLibrary::inbox() . '/' . $relative;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $bytes);

        return $path;
    }

    private function scan(): array
    {
        return app(ComicInbox::class)->scan();
    }

    private function comicInfo(array $fields): string
    {
        $xml = '<?xml version="1.0"?><ComicInfo>';
        foreach ($fields as $k => $v) {
            $xml .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
        }

        return $xml . '</ComicInfo>';
    }

    private function series(string $name, ?int $year = null): Novel
    {
        $novel = Novel::create([
            'name' => $name, 'group_id' => 0, 'status' => 1, 'medium' => Novel::MEDIUM_COMIC,
            'translator_url' => ComicLibrary::newUrl($name),
        ]);
        $novel->forceFill(['start_year' => $year])->save();

        return $novel;
    }

    // --- The importer ------------------------------------------------------------

    public function test_a_cbz_with_comicinfo_becomes_a_series_and_an_issue_with_its_pages_in_order(): void
    {
        $this->cbz('Saga 001 (2012) (Digital).cbz', 3, $this->comicInfo([
            'Series' => 'Saga', 'Number' => '1', 'Volume' => '2012', 'Title' => 'Chapter One', 'Writer' => 'Brian K. Vaughan',
            'Penciller' => 'Fiona Staples', 'Publisher' => 'Image', 'Year' => '2012', 'Summary' => '<p>Two soldiers &amp; a baby.</p>',
            'Manga' => 'No', 'LanguageISO' => 'en',
        ]));

        $counts = $this->scan();

        $this->assertSame(1, $counts['imported']);
        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame('Saga', $novel->name);
        $this->assertSame('Brian K. Vaughan', $novel->author);
        $this->assertSame('Image', $novel->publisher);
        $this->assertSame(2012, $novel->start_year, 'ComicInfo Volume 2012 is the start year');
        $this->assertSame('Two soldiers & a baby.', $novel->description);
        $this->assertTrue((bool) $novel->status, 'complete on arrival: never scraped');
        $this->assertTrue(ComicLibrary::isComicUrl($novel->translator_url));
        $this->assertSame('ltr', $novel->effectiveReadingMode());
        $this->assertNull($novel->reading_mode);

        $chapter = NovelChapter::where('novel_id', $novel->id)->sole();
        $this->assertSame(1.0, $chapter->chapter);
        $this->assertSame('#1 – Chapter One', $chapter->label);
        $this->assertSame('comic:i:1', $chapter->unique_id);
        $this->assertTrue((bool) $chapter->status);
        $this->assertSame(3, (int) $chapter->page_count);
        $this->assertNull($chapter->download_date, 'a new series arrives quietly');

        $pages = ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->get();
        $this->assertSame([1, 2, 3], $pages->pluck('page')->all(), 'junk, __MACOSX and the escaping entry are no pages');
        $this->assertSame("{$novel->id}/{$chapter->id}/001.jpg", $pages[0]->path);
        Storage::disk('manga')->assertExists($pages[2]->path);
        $this->assertSame('image/jpeg', $pages[0]->mime);
        $this->assertFileDoesNotExist(ComicLibrary::inbox() . '/Saga 001 (2012) (Digital).cbz', 'a dropped-in file goes once imported');
        $this->assertFalse(is_file(dirname(ComicLibrary::inbox()) . '/escape.jpg'));
        $this->assertTrue($novel->file()->exists(), 'no Comic Vine: the first page is the cover');
    }

    public function test_comicinfo_manga_right_to_left_reads_right_to_left_and_the_cbz_says_so(): void
    {
        $this->cbz('Akira v01.cbz', 2, $this->comicInfo(['Series' => 'Akira', 'Manga' => 'YesAndRightToLeft']));

        $this->scan();

        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame('rtl', $novel->reading_mode);
        $chapter = $novel->chapters()->sole();
        $this->assertSame('Volume 1', $chapter->label);
        $this->assertSame('comic:v:1', $chapter->unique_id);
        $this->assertStringContainsString('<Manga>YesAndRightToLeft</Manga>', \App\Http\Controllers\Api\V1\MangaCbzController::comicInfo($chapter->load('novel'), 2));
    }

    public function test_a_cbr_and_a_pdf_go_through_the_extractor_and_a_folder_of_files_is_one_issue_each(): void
    {
        $this->drop('Saga (2012)/Saga v01 (2012).cbr', 'FAKE:2');
        $this->drop('Saga (2012)/Saga v02 (2013).pdf', 'FAKE:4');
        $this->drop('Saga (2012)/cover.nfo', 'not a comic');

        $counts = $this->scan();

        $this->assertSame(1, $counts['imported']);
        $this->assertSame(['Saga v01 (2012).cbr', 'Saga v02 (2013).pdf'], $this->extractor->calls, 'natural order');
        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame(2012, $novel->start_year);
        $this->assertSame(['Volume 1', 'Volume 2'], $novel->chapters()->orderBy('chapter')->pluck('label')->all());
        $this->assertSame(4, (int) $novel->chapters()->where('chapter', 2)->value('page_count'));
        $this->assertSame(2, (int) $novel->no_of_chapters);
        $this->assertDirectoryDoesNotExist(ComicLibrary::inbox() . '/Saga (2012)');
    }

    public function test_a_folder_of_page_images_is_one_issue_in_natural_order(): void
    {
        foreach ([10, 2, 1] as $n) {
            $this->drop("Batman 001 (2016)/{$n}.jpg", $this->page);
        }
        $this->drop('Batman 001 (2016)/.DS_Store', 'x');

        $this->scan();

        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame('Batman', $novel->name);
        $this->assertSame(2016, $novel->start_year, 'issue 1\'s year starts the series');
        $chapter = $novel->chapters()->sole();
        $this->assertSame('#1', $chapter->label);
        $this->assertSame(3, (int) $chapter->page_count);
        $this->assertSame([1, 2, 3], ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->pluck('page')->all());
    }

    public function test_a_known_series_gets_the_issue_and_a_different_start_year_is_another_series(): void
    {
        $old = $this->series('Batman', 1940);
        $new = $this->series('Batman', 2016);

        $this->drop('Batman 050 (2018).cbr', 'FAKE:1');
        $this->drop('Batman 001.cbz', 'FAKE:1|' . $this->comicInfo(['Series' => 'Batman', 'Number' => '1', 'Volume' => '1940']));
        $this->drop('Batman 002.cbz', 'FAKE:1|' . $this->comicInfo(['Series' => 'Batman', 'Number' => '2', 'Volume' => '2011']));

        $this->scan();

        $this->assertSame(['#50'], $new->chapters()->pluck('label')->all(), 'the latest series that began before 2018');
        $this->assertSame(['#1'], $old->chapters()->pluck('label')->all(), 'ComicInfo\'s start year picks the series');
        $third = Novel::where('medium', 'comic')->where('start_year', 2011)->sole();
        $this->assertSame(['#2'], $third->chapters()->pluck('label')->all(), 'a start year no series has: a new one');
        $this->assertNotNull($new->chapters()->value('download_date'), 'a new issue of a known series shows as new');
    }

    public function test_an_issue_the_series_has_is_skipped_and_a_whole_entry_of_them_goes_to_failed(): void
    {
        $this->drop('Saga 001.cbz', 'FAKE:2');
        $this->scan();
        $this->drop('Saga #1 (2012).cbr', 'FAKE:2');
        $this->drop('Saga pack/Saga 001.cbz', 'FAKE:2');
        $this->drop('Saga pack/Saga 002.cbz', 'FAKE:2');

        $counts = $this->scan();

        $this->assertSame(1, $counts['imported'], 'the pack: issue 2 in, issue 1 skipped');
        $this->assertSame(1, $counts['failed']);
        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame(['#1', '#2'], $novel->chapters()->orderBy('chapter')->pluck('label')->all());
        $failed = app(ComicInbox::class)->failed();
        $this->assertSame('Saga #1 (2012).cbr', $failed[0]['name']);
        $this->assertStringContainsString('Already in the library: Saga #1', $failed[0]['reason']);
    }

    public function test_unreadable_files_go_to_failed_with_their_reason_and_retry_puts_them_back(): void
    {
        $this->drop('Broken 001.cbr', 'BAD:The CBR (RAR) archive is damaged, incomplete or password-protected.');
        $this->drop('Readme.txt', 'hello');
        $this->drop('Empty/notes.txt', 'nothing');

        $counts = $this->scan();

        $this->assertSame(3, $counts['failed']);
        $this->assertSame(0, Novel::where('medium', 'comic')->count());
        $reasons = collect(app(ComicInbox::class)->failed())->pluck('reason', 'name');
        $this->assertSame('The CBR (RAR) archive is damaged, incomplete or password-protected.', $reasons['Broken 001.cbr']);
        $this->assertStringContainsString('No comic in it', $reasons['Readme.txt']);
        $this->assertStringContainsString('No comic in it', $reasons['Empty']);
        $this->assertFileExists(ComicLibrary::failedDir() . '/Broken 001.cbr.reason.txt');

        $this->assertNull(app(ComicInbox::class)->retry('Broken 001.cbr'));
        $this->assertFileExists(ComicLibrary::inbox() . '/Broken 001.cbr');
        $this->assertNotNull(app(ComicInbox::class)->retry('../etc'), 'only an entry of _failed');
    }

    public function test_a_missing_tool_is_the_reason_and_a_damaged_real_cbz_is_refused(): void
    {
        $this->extractor->missing = 'CBR / CB7 files need unar or 7z on the server (NOVARR_UNAR, NOVARR_7Z).';
        $this->drop('Saga 001.cbr', 'FAKE:1');
        $this->scan();
        $this->assertStringContainsString('need unar or 7z', app(ComicInbox::class)->failed()[0]['reason']);

        // The real extractor: a ".cbz" that is no archive at all.
        $this->app->instance(PageExtractor::class, new \App\Comics\ShellPageExtractor());
        $this->drop('Fake 001.cbz', 'this is not a zip');
        $this->scan();
        $reasons = collect(app(ComicInbox::class)->failed())->pluck('reason', 'name');
        $this->assertStringContainsString('Not a comic archive', $reasons['Fake 001.cbz']);
    }

    public function test_an_entry_still_settling_or_copying_waits(): void
    {
        config(['novarr.comics.settle_seconds' => 120]);
        $this->drop('Saga 001.cbz', 'FAKE:1');
        $this->drop('Saga 002.cbz.part', 'half');

        $counts = $this->scan();

        $this->assertSame(0, $counts['imported']);
        $this->assertSame(2, $counts['waiting']);
        $this->travel(3)->minutes();
        $this->assertSame(1, $this->scan()['imported'], 'settled now; the .part keeps waiting');
    }

    public function test_a_torrent_is_copied_kept_seeding_and_not_taken_twice(): void
    {
        $series = $this->series('Saga', 2012);
        $grab = DownloadGrab::create([
            'kind' => DownloadGrab::KIND_COMIC, 'protocol' => 'torrent', 'client' => 'transmission', 'client_ref' => 'abc',
            'title' => 'Saga v03 (2014) (Digital)', 'status' => DownloadGrab::STATUS_COMPLETED, 'completed_path' => 'Saga v03 (2014) (Digital)',
            'novel_id' => $series->id, 'progress' => 100,
        ]);
        foreach ([1, 2] as $n) {
            $this->drop("Saga v03 (2014) (Digital)/{$n}.jpg", $this->page);
        }

        $this->scan();

        $grab->refresh();
        $this->assertSame(DownloadGrab::STATUS_IMPORTED, $grab->status);
        $this->assertSame(DownloadGrab::IMPORTED_COMIC, $grab->imported_type);
        $this->assertSame($series->id, $grab->imported_id);
        $this->assertSame(['Volume 3'], $series->chapters()->pluck('label')->all());
        $this->assertFileExists(ComicLibrary::inbox() . '/Saga v03 (2014) (Digital)/1.jpg', 'copied: the torrent seeds on');

        $again = [];
        app(ComicInbox::class)->scan(null, false, function ($name, $result) use (&$again) {
            $again[$name] = $result;
        });
        $this->assertSame('skipped', $again['Saga v03 (2014) (Digital)']);
        $this->assertSame(1, $series->chapters()->count());
    }

    public function test_a_download_still_in_its_client_waits_whatever_its_files_say(): void
    {
        DownloadGrab::create([
            'kind' => DownloadGrab::KIND_COMIC, 'protocol' => 'usenet', 'client' => 'nzbget', 'client_ref' => '7',
            'title' => 'Saga 001', 'status' => DownloadGrab::STATUS_DOWNLOADING, 'completed_path' => 'Saga 001', 'progress' => 40,
        ]);
        $this->drop('Saga 001/1.jpg', $this->page);

        $this->assertSame(1, $this->scan()['waiting']);
        $this->assertSame(0, Novel::where('medium', 'comic')->count());
    }

    public function test_a_wanted_comic_request_is_approved_and_available_when_its_series_arrives(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $reader = $this->makeReader();
        $request = NovelRequest::create([
            'user_id' => $reader->id, 'medium' => Novel::MEDIUM_COMIC, 'title' => 'Saga', 'normalized_title' => 'saga',
            'status' => NovelRequest::STATUS_PENDING, 'candidates' => [], 'description' => 'What the reader asked for.',
        ]);
        $this->assertTrue($request->isWanted());

        $this->drop('Saga v01 (2012).cbz', 'FAKE:2');
        $this->scan();

        $request->refresh();
        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame(NovelRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($novel->id, (int) $request->novel_id);
        $this->assertNotNull($request->available_at);
        $this->assertSame('What the reader asked for.', $novel->description, 'the request fills what the files leave out');
    }

    public function test_a_comic_request_is_not_approved_from_a_candidate_but_may_be_with_a_series(): void
    {
        $request = NovelRequest::create([
            'user_id' => $this->owner()->id, 'medium' => Novel::MEDIUM_COMIC, 'title' => 'Saga', 'normalized_title' => 'saga',
            'status' => NovelRequest::STATUS_PENDING, 'candidates' => [['source' => 'comic-catalogue', 'source_name' => 'Comic Vine', 'url' => 'https://comicvine.gamespot.com/saga/4050-1/', 'title' => 'Saga']],
        ]);
        $requests = app(\App\Services\Requests\Requests::class);
        try {
            $requests->approve($request, $this->owner(), ['candidate' => 0]);
            $this->fail('approved without its comic');
        } catch (\App\Services\Requests\RequestRefused $e) {
            $this->assertSame('comic_import', $e->errorCode);
        }
        $series = $this->series('Saga');
        $outcome = $requests->approve($request->refresh(), $this->owner(), ['novel_id' => $series->id]);
        $this->assertSame($series->id, (int) $outcome['request']->novel_id);
    }

    public function test_the_scan_command_and_the_manage_page(): void
    {
        $this->drop('Saga 001.cbz', 'FAKE:1');
        $this->drop('Broken.cbr', 'BAD:damaged');

        $this->artisan('comics:scan --dry-run')->expectsOutputToContain('would_import')->assertSuccessful();
        $this->assertSame(0, Novel::where('medium', 'comic')->count());
        $this->artisan('comics:scan')->expectsOutputToContain('Imported 1, failed 1')->assertSuccessful();

        $this->get(route('comics.manage'))->assertOk()
            ->assertSee('Manage comics')->assertSee('Broken.cbr')->assertSee('damaged')
            ->assertSee('NOVARR_COMICVINE_API_KEY')->assertSee('Saga');
        $this->post(route('comics.manage.retry'), ['name' => 'Broken.cbr'])->assertRedirect(route('comics.manage'));
        $this->assertFileExists(ComicLibrary::inbox() . '/Broken.cbr');
        \Illuminate\Support\Facades\Bus::fake();
        $this->post(route('comics.manage.scan'))->assertRedirect(route('comics.manage'));
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ScanComics::class);
    }

    public function test_the_manage_routes_are_the_owners(): void
    {
        foreach (['comics.manage', 'comics.manage.scan', 'comics.manage.retry', 'comics.lookup'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertSame(\App\Support\RouteAccess::ADMIN, \App\Support\RouteAccess::classify($route), $name);
        }
        $this->enableSignIn();
        $this->asSignedIn($this->makeReader())->get(route('comics.manage'))->assertForbidden();
    }

    // --- Metadata ----------------------------------------------------------------

    public function test_comic_vine_fills_the_details_and_cover_never_over_what_is_there(): void
    {
        config(['novarr.comics.comicvine.api_key' => 'cv-key']);
        $calls = [];
        $this->http = function (\Illuminate\Http\Client\Request $request) use (&$calls) {
            $url = $request->url();
            if (str_starts_with($url, 'https://comicvine.gamespot.com/api/search/')) {
                $calls[] = $request;

                return Http::response(['status_code' => 1, 'error' => 'OK', 'results' => [
                    ['id' => 99, 'name' => 'Saga', 'start_year' => '1999', 'publisher' => ['name' => 'Other'], 'count_of_issues' => 3, 'site_detail_url' => 'https://comicvine.gamespot.com/saga/4050-99/'],
                    ['id' => 48081, 'name' => 'Saga', 'start_year' => '2012', 'publisher' => ['name' => 'Image'], 'count_of_issues' => 66,
                        'description' => '<p>An epic space opera.</p><table><tr><td>junk</td></tr></table>',
                        'image' => ['original_url' => 'https://comicvine.gamespot.com/a/uploads/original/saga.jpg'],
                        'site_detail_url' => 'https://comicvine.gamespot.com/saga/4050-48081/'],
                ]]);
            }
            if ($url === 'https://comicvine.gamespot.com/a/uploads/original/saga.jpg') {
                return Http::response($this->page, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(['items' => [], 'docs' => []]);
        };
        $this->drop('Saga v01 (2012).cbz', 'FAKE:1|' . $this->comicInfo(['Series' => 'Saga', 'Writer' => 'Brian K. Vaughan', 'Volume' => '2012']));

        $this->scan();

        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame('48081', $novel->comicvine_id);
        $this->assertSame('An epic space opera.', $novel->description);
        $this->assertSame('Image', $novel->publisher);
        $this->assertSame('Brian K. Vaughan', $novel->author, 'ComicInfo first: never overwritten');
        $this->assertNotNull($novel->comicvine_checked_at);
        $this->assertSame('cover', $novel->file()->value('file_name'), 'Comic Vine\'s cover');
        $this->assertSame('cv-key', $calls[0]['api_key']);
        $this->assertSame('json', $calls[0]['format']);
        $this->assertStringContainsString('Novarr', $calls[0]->header('User-Agent')[0]);

        // An edit stays; a second import of the series does not ask again.
        $novel->forceFill(['description' => 'Mine.'])->save();
        $this->drop('Saga v02 (2013).cbz', 'FAKE:1');
        $this->scan();
        $this->assertSame('Mine.', $novel->refresh()->description);
        $this->assertCount(1, $calls, 'asked once per series (and cached)');
    }

    public function test_comic_vine_answers_are_parsed_and_a_failure_is_nothing(): void
    {
        $this->assertNull(ComicVine::volumeFrom(['id' => 0, 'name' => 'x']));
        $volume = ComicVine::volumeFrom(['id' => 5, 'name' => ' Watchmen ', 'start_year' => '1986', 'deck' => 'A deck.', 'image' => ['original_url' => 'https://comicvine.gamespot.com/a/uploads/original/6373148-blank.png', 'medium_url' => 'https://x.test/w.jpg']]);
        $this->assertSame('Watchmen', $volume['name']);
        $this->assertSame(1986, $volume['start_year']);
        $this->assertSame('A deck.', $volume['description']);
        $this->assertSame('https://x.test/w.jpg', $volume['cover_url'], 'the "blank" placeholder is no cover');
        $this->assertSame("One\n\nTwo & three", ComicVine::plainText('<h2>One</h2><p>Two &amp; three</p><figure>img</figure>'));

        $this->assertSame([], app(ComicVine::class)->searchVolumes('Saga'), 'no key: nothing asked');
        config(['novarr.comics.comicvine.api_key' => 'bad']);
        $this->http = fn() => Http::response(['status_code' => 100, 'error' => 'Invalid API Key'], 401);
        $this->assertSame([], app(ComicVine::class)->searchVolumes('Saga'));
        $this->assertNull(app(ComicVine::class)->volume(5));
    }

    public function test_without_a_key_a_graphic_novel_takes_the_catalogues_details(): void
    {
        $this->http = function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'googleapis.com')) {
                return Http::response(['items' => [
                    ['id' => 'n1', 'volumeInfo' => ['title' => 'Watchmen', 'authors' => ['Someone Else'], 'description' => 'A novel, not this.', 'categories' => ['Fiction / Thrillers']]],
                    ['id' => 'g1', 'volumeInfo' => ['title' => 'Watchmen', 'authors' => ['Alan Moore', 'Dave Gibbons'], 'description' => 'Who watches the watchmen?', 'categories' => ['Comics & Graphic Novels / Superheroes'], 'publisher' => 'DC Comics']],
                ]]);
            }

            return Http::response(['docs' => []]);
        };
        $this->drop('Watchmen (1987) TPB.pdf', 'FAKE:2');

        $this->scan();

        $novel = Novel::where('medium', 'comic')->sole();
        $this->assertSame('Watchmen', $novel->name);
        $this->assertSame('Watchmen', $novel->chapters()->value('label'), 'neither issue nor volume: its title');
        $this->assertSame('Who watches the watchmen?', $novel->description, 'only a comics result is taken');
        $this->assertSame('Alan Moore, Dave Gibbons', $novel->author);
        $this->assertTrue($novel->file()->exists(), 'the first page');
    }

    // --- Library, reader and API ---------------------------------------------------

    private function importedSeries(): Novel
    {
        $this->drop('Saga 001.cbz', 'FAKE:3');
        $this->drop('Saga 002.cbz', 'FAKE:2');
        $this->scan();

        return Novel::where('medium', 'comic')->sole();
    }

    public function test_the_comics_library_series_page_and_reader(): void
    {
        $novel = $this->importedSeries();
        $chapter = $novel->chapters()->orderBy('chapter')->first();

        $this->get(route('novels.index', ['medium' => 'comic']))->assertOk()
            ->assertSee('Comics')->assertSee('Saga')->assertSee('Manage comics')->assertSee('data-medium="comic"', false);
        $this->get(route('novels.show', $novel->id))->assertOk()->assertSee('Comic')->assertDontSee('comic://library')->assertDontSee('id="offlineBtn"', false);
        $this->get(route('chapters.show', $chapter->id))->assertOk()->assertSee('id="manga-reader"', false);
        $this->getJson(route('chapters.pages', $chapter->id))->assertOk()
            ->assertJsonPath('reading_mode', 'ltr')->assertJsonCount(3, 'pages');
        $this->get(route('novels.download_epub', $novel->id))->assertNotFound();
        $this->get(route('novels.create', ['medium' => 'comic']))->assertRedirect(route('comics.manage'));
        $this->get(route('novels.discover', ['medium' => 'comic']))->assertRedirect(route('requests.index', ['medium' => 'comic']));
    }

    public function test_the_api_lists_comics_on_request_and_serves_their_pages_and_cbz(): void
    {
        $novel = $this->importedSeries();
        Novel::create(['name' => 'A Novel', 'group_id' => 1, 'translator_url' => 'https://novelfull.test/a-novel', 'medium' => 'novel']);
        $chapter = $novel->chapters()->orderBy('chapter')->first();

        $this->getJson('/api/v1/meta')->assertJsonPath('comics', true);
        $this->assertSame(['Saga'], array_column($this->getJson('/api/v1/novels?medium=comic')->assertOk()->json('novels'), 'name'));
        $this->assertSame('comic', $this->getJson('/api/v1/novels?medium=comic')->json('novels.0.medium'));
        $this->assertNotContains('Saga', array_column($this->getJson('/api/v1/novels?medium=text')->json('novels'), 'name'));
        $this->assertNotContains('Saga', array_column($this->getJson('/api/v1/novels')->json('novels'), 'name'));
        $this->assertContains('Saga', array_column($this->getJson('/api/v1/novels?medium=all')->json('novels'), 'name'));

        $this->getJson("/api/v1/chapters/{$chapter->id}")->assertStatus(422)->assertJsonPath('medium', 'comic')->assertJsonPath('pages', true);
        $this->getJson("/api/v1/chapters/{$chapter->id}/pages")->assertOk()->assertJsonPath('reading_mode', 'ltr')->assertJsonCount(3, 'pages');

        $response = $this->get("/api/v1/chapters/{$chapter->id}/cbz")->assertOk();
        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'cbz');
        file_put_contents($tmp, $response->baseResponse->getFile()->getContent());
        $zip->open($tmp);
        $this->assertStringContainsString('<Manga>No</Manga>', (string) $zip->getFromName('ComicInfo.xml'), 'a comic reads left to right');
        $this->assertNotFalse($zip->getFromName('003.jpg'));
        $zip->close();
        @unlink($tmp);
    }

    public function test_storage_and_stats_count_comics_as_their_own_medium(): void
    {
        $novel = $this->importedSeries();
        $chapter = $novel->chapters()->orderBy('chapter')->first();
        $this->markRead($chapter);

        $by = collect(app(\App\Services\ReaderStats::class)->fresh($this->owner()->id, 30)['by_medium'])->keyBy('medium');
        $this->assertSame('Comics', $by['comic']['label']);
        $this->assertSame(1, $by['comic']['chapters']);
        $this->assertEquals(\App\Services\ReaderStats::COMIC_ISSUE_MINUTES, $by['comic']['minutes']);

        Artisan::call('storage:measure');
        $report = app(\App\Services\StorageUsage::class)->report();
        $this->assertSame(1, $report['mediums']['comic']['series']);
        $this->assertArrayHasKey('comic', $report['top']);
    }

    public function test_the_medium_helpers_know_comics(): void
    {
        $this->assertSame('Comics', \App\Http\Helpers\LibraryMedium::title('comic'));
        $this->assertSame('comics', \App\Http\Helpers\LibraryMedium::plural('comic'));
        $this->assertSame('1 comic', '1 ' . \App\Http\Helpers\LibraryMedium::countNoun('comic', 1));
        $this->assertSame('comics', \App\Http\Helpers\LibraryMedium::countNoun('comic', 2));
        $comic = new Novel(['medium' => 'comic']);
        $this->assertTrue($comic->hasPages());
        $this->assertTrue($comic->isComic());
        $this->assertFalse($comic->isManga());
        $this->assertInstanceOf(\App\Sources\ComicSource::class, \App\Sources\SourceResolver::forUrl('comic://library/saga-1234'));
        $this->assertSame([], (new \App\Sources\ComicSource())->tableOfContents($comic));
    }

    public function test_identify_reads_the_unit_from_its_name_folder_and_comicinfo(): void
    {
        $this->assertSame('issue', ComicImporter::identify('001.cbz', 'Saga (2012)', null)['kind']);
        $this->assertSame('Saga', ComicImporter::identify('001.cbz', 'Saga (2012)', null)['series']);
        $this->assertSame(2012, ComicImporter::identify('001.cbz', 'Saga (2012)', null)['start_year']);
        $tpb = ComicImporter::identify('Saga v01 (2012).cbz', null, ['number' => 1.0, 'series' => 'Saga']);
        $this->assertSame('volume', $tpb['kind'], 'a "v01" name is a volume even when ComicInfo numbers it 1');
        $single = ComicImporter::identify('Maus.pdf', null, null);
        $this->assertSame(['single', null, 'Maus'], [$single['kind'], $single['number'], $single['label']]);
    }
}
