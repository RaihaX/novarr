<?php

namespace Tests\Feature;

use App\Comics\ComicLibrary;
use App\DownloadGrab;
use App\DownloadWanted;
use App\Downloads\DownloadProfile;
use App\Downloads\DownloadSearch;
use App\Downloads\Grabber;
use App\Downloads\InboxTidy;
use App\Downloads\Prowlarr;
use App\Downloads\ReleaseRanking;
use App\Downloads\WantedList;
use App\Downloads\WantedSearcher;
use App\Novel;
use App\NovelRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Comics in the downloads (App\Downloads, kind comic) and the requests:
 * Prowlarr's Books/Comics category, the comic ranking (formats, numbers,
 * packs), the automation's profile, NZBGet's Novarr-Comics category and
 * Transmission's comics dir, the wanted list of comic requests, graphic
 * novels in ebook searches, the tidy-up of the comics inbox, and the
 * request search / free text of medium comic. Every service is faked.
 */
class ComicDownloadsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private array $prowlarr = [];

    /** @var list<HttpRequest> */
    private array $sent = [];

    private array $torrents = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('manga');
        Mail::fake();
        File::deleteDirectory(dirname(ComicLibrary::inbox()));
        File::ensureDirectoryExists(ComicLibrary::inbox());
        config([
            'novarr.prowlarr.url' => 'http://prowlarr.test',
            'novarr.prowlarr.api_key' => 'secret-key',
            'novarr.nzbget.url' => 'http://nzbget.test:6789',
            'novarr.transmission.url' => 'http://transmission.test:9091',
            'novarr.downloads.auto_search' => false,
            'novarr.downloads.auto_grab' => true,
        ]);
        Http::fake(function (HttpRequest $request) {
            $this->sent[] = $request;
            $url = $request->url();
            if (str_starts_with($url, 'http://prowlarr.test/api/v1/search')) {
                return Http::response($this->prowlarr);
            }
            if ($url === 'http://nzbget.test:6789/jsonrpc') {
                $body = $request->data();

                return Http::response(['version' => '1.1', 'id' => $body['id'], 'result' => $body['method'] === 'append' ? 101 : []]);
            }
            if ($url === 'http://transmission.test:9091/transmission/rpc') {
                if (($request->header('X-Transmission-Session-Id')[0] ?? null) !== 's1') {
                    return Http::response('', 409, ['X-Transmission-Session-Id' => 's1']);
                }
                $body = json_decode($request->body(), true);
                if ($body['method'] === 'torrent-add') {
                    return Http::response(['result' => 'success', 'arguments' => ['torrent-added' => ['id' => 1, 'hashString' => 'h1', 'name' => 'Saga v01']]]);
                }
                if ($body['method'] === 'torrent-get') {
                    return Http::response(['result' => 'success', 'arguments' => ['torrents' => $this->torrents]]);
                }

                return Http::response(['result' => 'success', 'arguments' => []]);
            }
            if (str_contains($url, 'googleapis.com')) {
                return Http::response(['items' => [
                    ['id' => 'g1', 'volumeInfo' => ['title' => 'Saga, Volume 1', 'authors' => ['Brian K. Vaughan'], 'categories' => ['Comics & Graphic Novels / Science Fiction'], 'canonicalVolumeLink' => 'https://books.google.com/books/about/s.html?id=g1']],
                    ['id' => 'g2', 'volumeInfo' => ['title' => 'Saga of the Swamp', 'authors' => ['A Novelist'], 'categories' => ['Fiction'], 'canonicalVolumeLink' => 'https://books.google.com/books/about/n.html?id=g2']],
                ]]);
            }

            return Http::response(['docs' => []]);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname(ComicLibrary::inbox()));
        parent::tearDown();
    }

    private function row(string $title, array $extra = []): array
    {
        $protocol = $extra['protocol'] ?? 'usenet';

        return array_merge([
            'guid' => 'guid-' . md5($title . $protocol), 'indexer' => 'NZBgeek', 'protocol' => $protocol, 'title' => $title,
            'size' => 80 * 1024 * 1024, 'age' => 10, 'seeders' => $protocol === 'torrent' ? 10 : null,
            'downloadUrl' => 'http://prowlarr.test/1/download?link=' . md5($title), 'categories' => [['id' => 7030]],
        ], $extra);
    }

    private function comicRequest(string $title = 'Saga'): NovelRequest
    {
        return NovelRequest::create([
            'user_id' => $this->makeReader()->id, 'medium' => Novel::MEDIUM_COMIC, 'title' => $title,
            'normalized_title' => mb_strtolower($title), 'status' => NovelRequest::STATUS_PENDING, 'candidates' => [],
        ]);
    }

    private function searchUrl(): string
    {
        return collect($this->sent)->first(fn($r) => str_contains($r->url(), '/api/v1/search'))->url();
    }

    public function test_comics_are_searched_in_books_comics_and_ranked_cbz_first_with_numbers_and_packs(): void
    {
        $this->prowlarr = [
            $this->row('Saga v01 (2012) (digital) (pdf)'),
            $this->row('Saga v01 (2012) (Digital) (Zone-Empire) cbr'),
            $this->row('Saga v01 (2012) (Digital) cbz'),
            $this->row('Saga v02 (2013) (Digital) cbz'),
            $this->row('Saga Vol 1-9 (2012-2018) cbz', ['size' => 6 * 1024 * 1024 * 1024]),
            $this->row('Saga v01 epub'),
        ];

        $results = app(Prowlarr::class)->search('Saga', 'comic', ['title' => 'Saga', 'number' => 1, 'number_kind' => 'volume', 'year' => 2012]);

        $this->assertStringContainsString('categories=7030&categories=7000', $this->searchUrl());
        $titles = array_column($results, 'title');
        $this->assertSame('Saga v01 (2012) (Digital) cbz', $titles[0], 'cbz > cbr > pdf');
        $this->assertLessThan(array_search('Saga v01 (2012) (digital) (pdf)', $titles), array_search('Saga v01 (2012) (Digital) (Zone-Empire) cbr', $titles));
        $byTitle = collect($results)->keyBy('title');
        $this->assertContains('says volume 2 (wanted 1)', $byTitle['Saga v02 (2013) (Digital) cbz']['warnings']);
        $this->assertTrue($byTitle['Saga Vol 1-9 (2012-2018) cbz']['pack']);
        $this->assertSame([], $byTitle['Saga Vol 1-9 (2012-2018) cbz']['warnings'], 'a big pack is a plausible size');
        $this->assertContains('not a comic archive', $byTitle['Saga v01 epub']['warnings']);
        $this->assertSame('cbz', $results[0]['format']);
    }

    public function test_the_profile_grabs_a_comic_without_an_author_and_a_single_volume_before_a_pack(): void
    {
        $wanted = ['kind' => 'comic', 'title' => 'Saga', 'number' => 1, 'number_kind' => 'volume'];
        $single = ReleaseRanking::score(Prowlarr::normalise($this->row('Saga v01 (2012) (Digital) cbz')), $wanted);
        $pack = ReleaseRanking::score(Prowlarr::normalise($this->row('Saga Vol 1-9 (Digital) cbz', ['size' => 5 * 1024 ** 3])), $wanted);
        $tiny = ReleaseRanking::score(Prowlarr::normalise($this->row('Saga v01 cbz', ['size' => 1024])), $wanted);
        $other = ReleaseRanking::score(Prowlarr::normalise($this->row('Saga v02 cbz')), $wanted);

        $this->assertNull(DownloadProfile::refusal($single, 'comic', $wanted), 'no author needed for a comic');
        $this->assertNull(DownloadProfile::refusal($pack, 'comic', $wanted));
        $this->assertSame('its size is unknown or unusual', DownloadProfile::refusal($tiny, 'comic', $wanted));
        $this->assertNotNull(DownloadProfile::refusal($other, 'comic', $wanted));
        $this->assertSame([$single['title'], $pack['title']], array_column(DownloadProfile::picks([$pack, $single], 'comic', $wanted), 'title'));
        $this->assertSame(['cbz', 'cbr', 'cb7', 'pdf'], DownloadProfile::formats('comic'));
        // An ebook still needs its author.
        $this->assertSame('the wanted book has no author to compare', DownloadProfile::refusal($single, 'ebook', ['title' => 'Saga']));
    }

    public function test_a_comic_grab_goes_to_nzbget_novarr_comics_or_transmission_downloads_comics(): void
    {
        $request = $this->comicRequest();
        $grabber = app(Grabber::class);

        $grab = $grabber->grab(Prowlarr::normalise($this->row('Saga v01 (2012) cbz')) + ['why' => 'cbz'], 'comic', $request);
        $append = collect($this->sent)->first(fn($r) => str_contains($r->url(), 'nzbget') && ($r->data()['method'] ?? null) === 'append');
        $this->assertSame('Novarr-Comics', $append->data()['params'][2]);
        $this->assertSame(DownloadGrab::KIND_COMIC, $grab->kind);
        $this->assertSame($request->id, $grab->novel_request_id);

        $this->sent = [];
        $series = Novel::create(['name' => 'Saga', 'group_id' => 0, 'medium' => 'comic', 'status' => 1, 'translator_url' => ComicLibrary::newUrl('Saga')]);
        $grabber->grab(Prowlarr::normalise($this->row('Saga v02 cbz', ['protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:X'])), 'comic', null, $series);
        $add = collect($this->sent)->first(fn($r) => str_contains($r->url(), 'transmission') && str_contains($r->body(), 'torrent-add'));
        $this->assertSame('/downloads/Comics', json_decode($add->body(), true)['arguments']['download-dir']);

        $this->expectException(\App\Downloads\DownloadFailed::class);
        $book = Novel::create(['name' => 'A Book', 'group_id' => 0, 'medium' => 'book', 'status' => 1, 'translator_url' => 'book://library/x']);
        $grabber->grab(Prowlarr::normalise($this->row('A Book cbz')), 'comic', null, $book);
    }

    public function test_a_comic_request_is_on_the_wanted_list_and_grabbed_by_itself(): void
    {
        $request = $this->comicRequest();
        app(WantedList::class)->sync();
        $row = DownloadWanted::where('kind', 'comic')->sole();
        $this->assertSame($request->id, $row->novel_request_id);
        $this->assertSame('Saga', $row->title());
        $this->assertSame(route('requests.index'), $row->pageUrl());

        $this->prowlarr = [$this->row('Saga v01 (2012) (Digital) cbz'), $this->row('Saga v02 (2013) cbz')];
        $outcome = app(WantedSearcher::class)->search($row, false);

        $this->assertSame('grabbed', $outcome['status']);
        $this->assertSame(DownloadGrab::KIND_COMIC, $outcome['grab']->kind);
        $this->assertStringContainsString('query=Saga&', $this->searchUrl(), 'searched by the series, no author');
        $this->assertSame($row->id, $outcome['grab']->wanted_id);
        $this->assertSame('busy', app(WantedSearcher::class)->search($row->refresh(), false)['status']);

        // Arrived (approved by the comics import): off the list.
        $request->forceFill(['status' => NovelRequest::STATUS_APPROVED])->save();
        app(WantedList::class)->sync();
        $this->assertSame(0, DownloadWanted::where('kind', 'comic')->count());
    }

    public function test_an_ebook_search_for_a_graphic_novel_asks_books_comics_too_and_takes_its_comic_archive(): void
    {
        $request = NovelRequest::create([
            'user_id' => $this->makeReader()->id, 'medium' => Novel::MEDIUM_BOOK, 'title' => 'Persepolis', 'normalized_title' => 'persepolis',
            'author' => 'Marjane Satrapi', 'status' => NovelRequest::STATUS_PENDING, 'candidates' => [],
            'description' => 'The graphic novel memoir of a childhood in Iran.',
        ]);
        $this->assertTrue(DownloadSearch::graphicNovel($request, null));
        $this->prowlarr = [$this->row('Marjane Satrapi - Persepolis (cbr)'), $this->row('Marjane Satrapi - Persepolis (pdf)')];

        $search = app(DownloadSearch::class)->run('ebook', '', $request);

        $this->assertStringContainsString('categories=7000&categories=7020&categories=7030', $this->searchUrl());
        $this->assertSame('cbr', $search['results'][0]['format'], 'its comic archive is the book');
        $this->assertSame([], $search['results'][0]['warnings']);

        $plain = NovelRequest::create([
            'user_id' => $request->user_id, 'medium' => Novel::MEDIUM_BOOK, 'title' => 'Mistborn', 'normalized_title' => 'mistborn',
            'author' => 'Brandon Sanderson', 'status' => NovelRequest::STATUS_PENDING, 'candidates' => [],
        ]);
        $this->assertFalse(DownloadSearch::graphicNovel($plain, null));
        $book = Novel::create(['name' => 'Blankets', 'group_id' => 0, 'medium' => 'book', 'status' => 1, 'translator_url' => 'book://library/b']);
        $book->tags()->attach(\App\Tag::create(['name' => 'Graphic Novels'])->id);
        $this->assertTrue(DownloadSearch::graphicNovel(null, $book));
    }

    public function test_the_tidy_up_keeps_seeding_comic_torrents_and_removes_imported_leftovers(): void
    {
        foreach (['Seeding', 'Done torrent', 'Done usenet', 'Dropped in'] as $name) {
            File::ensureDirectoryExists(ComicLibrary::inbox() . '/' . $name);
            file_put_contents(ComicLibrary::inbox() . "/{$name}/1.jpg", 'x');
        }
        $base = ['kind' => 'comic', 'client_ref' => 'r', 'status' => DownloadGrab::STATUS_IMPORTED, 'progress' => 100];
        DownloadGrab::create($base + ['protocol' => 'torrent', 'client' => 'transmission', 'title' => 'a', 'completed_path' => 'Seeding']);
        DownloadGrab::create($base + ['protocol' => 'torrent', 'client' => 'transmission', 'title' => 'b', 'completed_path' => 'Done torrent']);
        DownloadGrab::create($base + ['protocol' => 'usenet', 'client' => 'nzbget', 'title' => 'c', 'completed_path' => 'Done usenet']);
        $this->torrents = [['hashString' => 'h', 'name' => 'Seeding']];
        File::ensureDirectoryExists(ComicLibrary::failedDir());
        file_put_contents(ComicLibrary::failedDir() . '/Old.cbr', 'x');
        touch(ComicLibrary::failedDir() . '/Old.cbr', now()->subDays(40)->getTimestamp());

        $result = app(InboxTidy::class)->run();

        $this->assertNull($result['problem']);
        $this->assertDirectoryExists(ComicLibrary::inbox() . '/Seeding', 'a torrent still in Transmission');
        $this->assertDirectoryExists(ComicLibrary::inbox() . '/Dropped in', 'no grab accounts for it');
        $this->assertDirectoryDoesNotExist(ComicLibrary::inbox() . '/Done torrent');
        $this->assertDirectoryDoesNotExist(ComicLibrary::inbox() . '/Done usenet');
        $this->assertFileDoesNotExist(ComicLibrary::failedDir() . '/Old.cbr');
    }

    public function test_a_comic_request_search_asks_the_catalogues_for_comics_only_and_takes_book_links(): void
    {
        $owner = $this->owner();
        $sources = app(\App\Services\Requests\RequestSearch::class)->sources(app(\App\Services\Discover::class), 'comic');
        $this->assertSame([\App\Sources\ComicCatalogueSource::KEY], array_keys($sources));

        $items = (new \App\Sources\ComicCatalogueSource())->discover('search', 'Saga');
        $this->assertSame(['Saga, Volume 1'], array_column($items, 'name'), 'the novel is no comic');

        $this->postJson('/api/v1/requests/searches', ['q' => 'https://www.goodreads.com/book/show/15704307-saga-volume-1', 'medium' => 'comic'])
            ->assertStatus(202);
        $this->postJson('/api/v1/requests/searches', ['q' => 'https://example.com/saga', 'medium' => 'comic'])
            ->assertStatus(422);

        $made = $this->postJson('/api/v1/requests', ['title' => 'Saga', 'medium' => 'comic'])->assertCreated();
        $this->assertSame('comic', $made->json('request.medium'));
        $this->assertTrue((bool) $made->json('request.wanted'));
        $this->assertSame(1, DownloadWanted::where('kind', 'comic')->count(), 'on the wanted list at once');

        $this->getJson('/api/v1/requests/browse?medium=comic')->assertOk()->assertJsonPath('lists', []);
        $this->get(route('requests.index'))->assertOk()->assertSee('data-medium="comic"', false);
        $this->assertNotNull($owner);
    }

    public function test_the_downloads_page_and_series_widget_know_comics(): void
    {
        $series = Novel::create(['name' => 'Saga', 'group_id' => 0, 'medium' => 'comic', 'status' => 1, 'translator_url' => ComicLibrary::newUrl('Saga')]);
        DownloadGrab::create(['kind' => 'comic', 'protocol' => 'usenet', 'client' => 'nzbget', 'client_ref' => '1', 'title' => 'Saga v02', 'status' => 'downloading', 'progress' => 10, 'novel_id' => $series->id]);

        $this->get(route('novels.show', $series->id))->assertOk()->assertSee('Find more issues')->assertSee('data-kind="comic"', false);
        $this->get(route('downloads.index'))->assertOk()->assertSee('Comic');
        $this->postJson(route('downloads.search'), ['kind' => 'comic', 'novel_id' => $series->id])->assertOk()->assertJsonPath('kind', 'comic');
        $this->assertSame('Saga', DownloadSearch::defaultQuery(null, $series));
    }
}
