<?php

namespace Tests\Feature;

use App\Audiobook;
use App\Audiobooks\AudiobookInbox;
use App\Audiobooks\AudiobookLibrary;
use App\Books\BookLibrary;
use App\DownloadBlock;
use App\DownloadEvent;
use App\DownloadGrab;
use App\DownloadWanted;
use App\Downloads\DownloadPoller;
use App\Downloads\DownloadProfile;
use App\Downloads\Grabber;
use App\Downloads\Prowlarr;
use App\Downloads\ReleaseRanking;
use App\Downloads\WantedList;
use App\Mail\AdminAlertMail;
use App\Mail\RequestAvailable;
use App\Novel;
use App\NovelRequest;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\Support\FakeAudioProbe;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Searching Usenet & torrents (App\Downloads): Prowlarr's search and the
 * ranking, grabbing to NZBGet / Transmission (their RPC payloads, the
 * session-id handshake), `downloads:poll` (status, failures, stalls), a
 * finished ebook into the review queue or the library (auto-approve), a
 * finished audiobook imported for its book (copied for torrents), the
 * automation (wanted list, backoff, daily cap, confident auto-grab,
 * blocklist and the next best release), the pages and who may open them.
 *
 * Every service is faked here (Http::fake): no request leaves the test.
 */
class DownloadsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private string $tmp;

    /** @var list<array>|null Prowlarr's answer (null: 500). */
    private ?array $prowlarr = [];

    /** NZBGet's state: queue and history by NZBID, the next NZBID. */
    private array $nzbQueue = [];
    private array $nzbHistory = [];
    private int $nzbNext = 101;

    /** Transmission's torrents by hash; its session id. */
    private array $torrents = [];
    private string $session = 'sess-1';

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        Mail::fake();
        Bus::fake([\App\Jobs\RunNovelCommand::class, \App\Jobs\PushBookToCalibre::class]);
        $this->tmp = sys_get_temp_dir() . '/novarr-downloads-' . uniqid();
        config([
            'mail.default' => 'smtp',
            'novarr.books.path' => $this->tmp . '/books',
            'novarr.books.download_inbox' => $this->tmp . '/books-inbox',
            'novarr.books.lookup' => true,
            'novarr.audiobooks.settle_seconds' => 0,
            'novarr.prowlarr.url' => 'http://prowlarr.test',
            'novarr.prowlarr.api_key' => 'secret-key',
            'novarr.nzbget.url' => 'http://nzbget.test:6789',
            'novarr.nzbget.username' => 'nzb',
            'novarr.nzbget.password' => 'nzbpass',
            'novarr.transmission.url' => 'http://transmission.test:9091',
            'novarr.transmission.username' => 'tr',
            'novarr.transmission.password' => 'trpass',
            // Each test turns on the automation it is about.
            'novarr.downloads.auto_search' => false,
            'novarr.downloads.auto_grab' => true,
            'novarr.downloads.auto_approve' => false,
        ]);
        File::ensureDirectoryExists($this->tmp . '/books-inbox');
        File::deleteDirectory(dirname(AudiobookLibrary::inbox()));
        File::ensureDirectoryExists(AudiobookLibrary::inbox());
        $this->owner()->forceFill(['email' => 'owner@example.test'])->save();
        $this->reader = $this->makeReader();
        $this->reader->forceFill(['email' => 'sam@example.test'])->save();
        $this->fakeServices();
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        File::deleteDirectory($this->tmp);
        File::deleteDirectory(dirname(AudiobookLibrary::inbox()));
        parent::tearDown();
    }

    // --- Fakes -----------------------------------------------------------------

    private function fakeServices(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, 'http://prowlarr.test/api/v1/search')) {
                return $this->prowlarr === null ? Http::response('boom', 500) : Http::response($this->prowlarr);
            }
            if (str_starts_with($url, 'http://prowlarr.test/2/download')) {
                return Http::response('', 302, ['Location' => 'magnet:?xt=urn:btih:REDIRECTED']);
            }
            if ($url === 'http://nzbget.test:6789/jsonrpc') {
                $body = $request->data();
                $result = match ($body['method']) {
                    'append' => $this->nzbNext++,
                    'listgroups' => array_values($this->nzbQueue),
                    'history' => array_values($this->nzbHistory),
                    'status' => ['DownloadRate' => 1048576],
                    default => null,
                };

                return Http::response(['version' => '1.1', 'id' => $body['id'], 'result' => $result]);
            }
            if ($url === 'http://transmission.test:9091/transmission/rpc') {
                if (($request->header('X-Transmission-Session-Id')[0] ?? null) !== $this->session) {
                    return Http::response('<h1>409: Conflict</h1>', 409, ['X-Transmission-Session-Id' => $this->session]);
                }
                $body = json_decode($request->body(), true);
                if ($body['method'] === 'torrent-add') {
                    $hash = 'abc' . count($this->torrents);
                    $this->torrents[$hash] = ['hashString' => $hash, 'name' => 'Torrent ' . count($this->torrents), 'percentDone' => 0, 'leftUntilDone' => 100, 'status' => 3, 'error' => 0, 'errorString' => '', 'eta' => -1];

                    return Http::response(['result' => 'success', 'arguments' => ['torrent-added' => ['id' => 1, 'hashString' => $hash, 'name' => $this->torrents[$hash]['name']]]]);
                }
                if ($body['method'] === 'torrent-set') {
                    return Http::response(['result' => 'success', 'arguments' => []]);
                }
                if ($body['method'] === 'torrent-get') {
                    return Http::response(['result' => 'success', 'arguments' => ['torrents' => array_values(array_intersect_key($this->torrents, array_flip($body['arguments']['ids'])))]]);
                }
            }
            if (str_contains($url, 'googleapis.com')) {
                return Http::response(['items' => [
                    ['id' => 'g1', 'volumeInfo' => [
                        'title' => 'The Quiet Orchard', 'authors' => ['Ada Wren'], 'description' => 'The catalogue description.',
                        'canonicalVolumeLink' => 'https://books.google.com/books/about/x.html?id=g1',
                    ]],
                ]]);
            }
            if (str_contains($url, 'openlibrary.org')) {
                return Http::response(['docs' => []]);
            }

            return Http::response('unexpected ' . $url, 599);
        });
    }

    private function row(string $title, array $extra = []): array
    {
        $protocol = $extra['protocol'] ?? 'usenet';

        return array_merge([
            'guid' => 'guid-' . md5($title . $protocol . ($extra['indexer'] ?? '')),
            'indexer' => $protocol === 'torrent' ? 'TorrentDay' : 'NZBgeek',
            'indexerId' => $protocol === 'torrent' ? 5 : 2,
            'protocol' => $protocol,
            'title' => $title,
            'size' => 1_200_000,
            'age' => 30,
            'publishDate' => now()->subDays(30)->toIso8601String(),
            'grabs' => 4,
            'seeders' => $protocol === 'torrent' ? 12 : null,
            'leechers' => $protocol === 'torrent' ? 1 : null,
            'downloadUrl' => 'http://prowlarr.test/1/download?apikey=secret-key&link=' . md5($title),
            'infoUrl' => 'https://indexer.test/details/' . md5($title),
            'categories' => [['id' => 7020, 'name' => 'Books/EBook']],
        ], $extra);
    }

    private function wantedRequest(string $title = 'The Quiet Orchard', ?string $author = 'Ada Wren'): NovelRequest
    {
        return NovelRequest::create([
            'user_id' => $this->reader->id, 'medium' => Novel::MEDIUM_BOOK, 'title' => $title,
            'normalized_title' => mb_strtolower($title), 'author' => $author, 'status' => NovelRequest::STATUS_PENDING,
            'candidates' => [], 'upload_path' => null, 'upload_meta' => null,
        ]);
    }

    private function book(string $name = 'The Final Empire', ?string $author = 'Brandon Sanderson'): Novel
    {
        return Novel::create([
            'name' => $name, 'author' => $author, 'status' => 1, 'group_id' => 1,
            'medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1($name),
        ]);
    }

    private function grab(array $attrs): DownloadGrab
    {
        return DownloadGrab::create(array_merge([
            'kind' => 'ebook', 'protocol' => 'usenet', 'client' => 'nzbget', 'client_ref' => '101',
            'title' => 'Ada.Wren-The.Quiet.Orchard.RETAIL.EPUB', 'size' => 1_200_000, 'indexer' => 'NZBgeek',
            'status' => 'queued', 'guid' => 'g-1', 'user_id' => $this->owner()->id, 'progress_at' => now(),
        ], $attrs));
    }

    /** A finished usenet download of an ePub in the books inbox. */
    private function finishedEpub(string $folder, array $epub = []): string
    {
        $dir = $this->tmp . '/books-inbox/' . $folder;
        File::ensureDirectoryExists($dir);
        copy(EpubBuilder::epub3($epub), $dir . '/book.epub');
        file_put_contents($dir . '/book.sample.epub', 'x');

        return $dir;
    }

    // --- Prowlarr and the ranking --------------------------------------------------

    public function test_prowlarr_is_asked_across_all_indexers_in_the_book_categories_and_results_are_ranked(): void
    {
        $this->prowlarr = [
            $this->row('Brandon Sanderson - The Final Empire (retail) (pdf)'),
            $this->row('Brandon.Sanderson-The.Final.Empire.2006.RETAIL.EPUB.eBook'),
            $this->row('Brandon Sanderson - The Well of Ascension (epub)'),
            $this->row('The Final Empire - Brandon Sanderson [EPUB]', ['protocol' => 'torrent', 'seeders' => 0]),
            ['title' => 'no link at all', 'protocol' => 'usenet'],
        ];

        $results = app(Prowlarr::class)->search('The Final Empire Brandon Sanderson', 'ebook', ['title' => 'The Final Empire', 'author' => 'Brandon Sanderson']);

        $this->assertCount(4, $results, 'a result without a link is dropped');
        $titles = array_column($results, 'title');
        $this->assertSame('Brandon.Sanderson-The.Final.Empire.2006.RETAIL.EPUB.eBook', $titles[0], 'epub beats pdf');
        $this->assertLessThan(array_search('Brandon Sanderson - The Final Empire (retail) (pdf)', $titles), 0);
        $this->assertSame('Brandon Sanderson - The Well of Ascension (epub)', end($titles), 'the wrong book ranks last');
        $zero = collect($results)->firstWhere('protocol', 'torrent');
        $this->assertContains('no seeders', $zero['warnings']);
        $this->assertLessThan(collect($results)->firstWhere('format', 'pdf')['score'], $zero['score'], 'a zero-seed torrent is penalised');
        $this->assertSame('epub · 1.1 MB · NZBgeek', $results[0]['why']);
        $this->assertSame(['guid', 'indexer', 'indexer_id', 'protocol', 'title', 'size', 'age_days', 'publish_date', 'seeders', 'leechers', 'grabs', 'download_url', 'magnet_url', 'info_url', 'categories'],
            array_slice(array_keys($results[0]), 0, 15));

        Http::assertSent(fn(HttpRequest $r) => str_starts_with($r->url(), 'http://prowlarr.test/api/v1/search?')
            && str_contains($r->url(), 'query=The%20Final%20Empire%20Brandon%20Sanderson')
            && str_contains($r->url(), 'categories=7000&categories=7020') && str_contains($r->url(), 'type=search')
            && !str_contains($r->url(), 'indexerIds') && $r->header('X-Api-Key')[0] === 'secret-key');
    }

    public function test_audiobooks_prefer_m4b_and_skip_other_languages_and_abridged(): void
    {
        $wanted = ['kind' => 'audiobook', 'title' => 'The Final Empire', 'author' => 'Brandon Sanderson'];
        $big = ['size' => 900 * 1024 * 1024, 'protocol' => 'usenet', 'indexer' => 'NZBgeek'];
        $ranked = ReleaseRanking::rank([
            $big + ['title' => 'Brandon Sanderson - The Final Empire (Unabridged) MP3'],
            $big + ['title' => 'Brandon Sanderson - The Final Empire (Unabridged) [M4B]'],
            $big + ['title' => 'Brandon Sanderson - The Final Empire GERMAN Hörbuch M4B'],
            $big + ['title' => 'Brandon Sanderson - The Final Empire (Abridged) M4B'],
            ['size' => 1_000_000] + $big + ['title' => 'Brandon Sanderson - The Final Empire epub'],
        ], $wanted);

        $this->assertSame('Brandon Sanderson - The Final Empire (Unabridged) [M4B]', $ranked[0]['title']);
        $this->assertSame('Brandon Sanderson - The Final Empire (Unabridged) MP3', $ranked[1]['title']);
        $german = collect($ranked)->first(fn($r) => str_contains($r['title'], 'GERMAN'));
        $this->assertContains('in German', $german['warnings']);
        $this->assertContains('abridged', collect($ranked)->first(fn($r) => str_contains($r['title'], '(Abridged)'))['warnings']);
        $this->assertContains('an ebook, not audio', end($ranked)['warnings']);

        // A sample, and a summary, of the right book.
        $junk = ReleaseRanking::score(['title' => 'Summary of The Final Empire by Brandon Sanderson epub', 'size' => 500_000], ['kind' => 'ebook'] + $wanted);
        $this->assertContains('summary', $junk['warnings']);
    }

    public function test_a_prowlarr_failure_is_said_in_plain_words(): void
    {
        $this->prowlarr = null;
        $this->postJson('/downloads/search', ['kind' => 'ebook', 'q' => 'Orchard'])
            ->assertStatus(422)->assertJsonPath('message', 'Prowlarr answered 500 to the search.');
    }

    // --- Grabbing --------------------------------------------------------------------

    public function test_a_usenet_result_is_appended_to_nzbget_in_the_books_category(): void
    {
        $request = $this->wantedRequest();
        $this->prowlarr = [$this->row('Ada.Wren-The.Quiet.Orchard.RETAIL.EPUB')];

        $search = $this->postJson('/downloads/search', ['kind' => 'ebook', 'request_id' => $request->id])->assertOk()->json();
        $this->assertSame('The Quiet Orchard Ada Wren', $search['query']);
        $this->assertSame('ok', $search['results'][0]['auto']);

        $grab = $this->postJson('/downloads/grab', ['search_id' => $search['search_id'], 'guid' => $search['results'][0]['guid']])
            ->assertCreated()->json('grab');

        Http::assertSent(fn(HttpRequest $r) => $r->url() === 'http://nzbget.test:6789/jsonrpc' && $r['method'] === 'append'
            && $r['params'][0] === 'Ada.Wren-The.Quiet.Orchard.RETAIL.EPUB.nzb'
            && $r['params'][1] === $this->row('Ada.Wren-The.Quiet.Orchard.RETAIL.EPUB')['downloadUrl']
            && $r['params'][2] === 'Novarr-Books' && $r['params'][8] === 'SCORE'
            && $r->header('Authorization')[0] === 'Basic ' . base64_encode('nzb:nzbpass'));
        $row = DownloadGrab::sole();
        $this->assertSame(['ebook', 'usenet', 'nzbget', '101', 'queued', $request->id], [$row->kind, $row->protocol, $row->client, $row->client_ref, $row->status, $row->novel_request_id]);
        $this->assertSame('Queued in NZBGet', $grab['status_line']);
        $this->assertFalse($row->auto);
        $this->assertSame(DownloadEvent::GRABBED, DownloadEvent::sole()->event);

        // The browser can only name a result of the server's own search.
        $this->postJson('/downloads/grab', ['search_id' => $search['search_id'], 'guid' => 'made-up'])->assertNotFound();
        $this->postJson('/downloads/grab', ['search_id' => (string) \Illuminate\Support\Str::uuid(), 'guid' => 'x'])->assertStatus(410);
    }

    public function test_transmission_gets_the_session_id_handshake_and_the_download_dir(): void
    {
        $result = Prowlarr::normalise($this->row('Ada Wren - The Quiet Orchard [EPUB]', ['protocol' => 'torrent', 'magnetUrl' => 'magnet:?xt=urn:btih:ORCHARD']));
        $grab = app(Grabber::class)->grab($result, 'ebook');

        $calls = collect(Http::recorded())->map(fn($pair) => $pair[0])->filter(fn($r) => str_contains($r->url(), 'transmission'))->values();
        // The 409 is answered once with the session id; then the new torrent is set to seed without limit.
        $this->assertCount(3, $calls);
        $this->assertSame('torrent-set', $calls[2]['method']);
        $this->assertSame(['ids' => ['abc0'], 'seedRatioMode' => 2, 'seedIdleMode' => 2], json_decode($calls[2]->body(), true)['arguments']);
        $this->assertSame([], $calls[0]->header('X-Transmission-Session-Id'));
        $this->assertSame('sess-1', $calls[1]->header('X-Transmission-Session-Id')[0]);
        $this->assertSame('torrent-add', $calls[1]['method']);
        $this->assertSame(['download-dir' => '/downloads/Novarr-Books', 'paused' => false, 'filename' => 'magnet:?xt=urn:btih:ORCHARD'], json_decode($calls[1]->body(), true)['arguments']);
        $this->assertSame('Basic ' . base64_encode('tr:trpass'), $calls[1]->header('Authorization')[0]);
        $this->assertSame(['transmission', 'abc0', 'Torrent 0'], [$grab->client, $grab->client_ref, $grab->completed_path]);

        // An audiobook goes to the audiobooks folder; Prowlarr's link that redirects to a magnet is followed by Novarr.
        $other = Prowlarr::normalise($this->row('Brandon Sanderson - The Final Empire M4B', ['protocol' => 'torrent', 'downloadUrl' => 'http://prowlarr.test/2/download?x=1']));
        app(Grabber::class)->grab($other, 'audiobook');
        Http::assertSent(fn(HttpRequest $r) => str_contains($r->url(), 'transmission') && ($r['method'] ?? null) === 'torrent-add'
            && json_decode($r->body(), true)['arguments']['download-dir'] === '/downloads/Audiobooks'
            && json_decode($r->body(), true)['arguments']['filename'] === 'magnet:?xt=urn:btih:REDIRECTED');
    }

    public function test_a_result_without_its_client_cannot_be_grabbed(): void
    {
        config(['novarr.transmission.url' => '']);
        $this->expectException(\App\Downloads\DownloadFailed::class);
        $this->expectExceptionMessage('Transmission is not set up');
        app(Grabber::class)->grab(Prowlarr::normalise($this->row('X', ['protocol' => 'torrent'])), 'ebook');
    }

    // --- Polling ----------------------------------------------------------------------

    public function test_poll_moves_a_usenet_grab_from_queued_to_downloading_to_completed(): void
    {
        $grab = $this->grab(['kind' => 'audiobook', 'title' => 'Some.Audiobook']);
        $this->nzbQueue[101] = ['NZBID' => 101, 'NZBName' => 'Some.Audiobook', 'Status' => 'DOWNLOADING', 'FileSizeMB' => 200, 'RemainingSizeMB' => 114];

        app(DownloadPoller::class)->poll();
        $grab->refresh();
        $this->assertSame(['downloading', 43], [$grab->status, $grab->progress]);
        $this->assertSame(114, $grab->eta_seconds, '114 MB at 1 MB/s');
        $this->assertSame('Downloading 43%', $this->getJson("/downloads/grabs/{$grab->id}")->json('grab.status_line'));

        unset($this->nzbQueue[101]);
        $this->nzbHistory[101] = ['NZBID' => 101, 'Name' => 'Some.Audiobook', 'Status' => 'SUCCESS/ALL', 'DestDir' => '/downloads/Audiobooks/Some.Audiobook', 'FinalDir' => ''];
        app(DownloadPoller::class)->poll();
        $grab->refresh();
        $this->assertSame(['completed', 100, 'Some.Audiobook'], [$grab->status, $grab->progress, $grab->completed_path]);
        $this->assertNotNull($grab->completed_at);
    }

    public function test_a_failed_download_is_recorded_and_blocklisted(): void
    {
        $grab = $this->grab([]);
        $this->nzbHistory[101] = ['NZBID' => 101, 'Name' => 'x', 'Status' => 'FAILURE/PAR', 'DestDir' => '/downloads/Novarr-Books/x'];

        $counts = app(DownloadPoller::class)->poll();

        $this->assertSame(1, $counts['failed']);
        $grab->refresh();
        $this->assertSame('failed', $grab->status);
        $this->assertSame('NZBGet could not finish the download (FAILURE/PAR).', $grab->error);
        $this->assertSame('Failed: NZBGet could not finish the download (FAILURE/PAR).', $grab->present()['status_line']);
        $block = DownloadBlock::sole();
        $this->assertSame([$grab->title, 'NZBgeek', 'g-1'], [$block->title, $block->indexer, $block->guid]);
        $this->assertSame(['failed', 'blocklisted'], DownloadEvent::orderBy('id')->pluck('event')->all());
    }

    public function test_a_torrent_with_a_local_error_fails_and_a_stalled_download_fails(): void
    {
        $torrent = $this->grab(['protocol' => 'torrent', 'client' => 'transmission', 'client_ref' => 'abc0']);
        $this->torrents['abc0'] = ['hashString' => 'abc0', 'name' => 'x', 'percentDone' => 0.4, 'leftUntilDone' => 5, 'status' => 4, 'error' => 3, 'errorString' => 'No space left on device'];
        $stalled = $this->grab(['client_ref' => '102', 'title' => 'Stalled', 'guid' => 'g-2', 'progress' => 10, 'progress_at' => now()->subHours(7)]);
        $this->nzbQueue[102] = ['NZBID' => 102, 'NZBName' => 'Stalled', 'Status' => 'DOWNLOADING', 'FileSizeMB' => 100, 'RemainingSizeMB' => 90];

        $counts = app(DownloadPoller::class)->poll();

        $this->assertSame([], $counts['problems']);
        $this->assertSame('Transmission: No space left on device', $torrent->fresh()->error);
        $this->assertSame('failed', $stalled->fresh()->status);
        $this->assertSame('No progress for 6 hours (stuck at 10%).', $stalled->fresh()->error);
    }

    // --- A finished ebook ---------------------------------------------------------------

    public function test_a_finished_ebook_is_attached_to_the_wanted_request_and_the_owner_is_emailed(): void
    {
        $request = $this->wantedRequest();
        $grab = $this->grab(['novel_request_id' => $request->id]);
        $dir = $this->finishedEpub('Ada.Wren-The.Quiet.Orchard.RETAIL.EPUB');
        $this->nzbHistory[101] = ['NZBID' => 101, 'Name' => 'x', 'Status' => 'SUCCESS/UNPACK', 'DestDir' => '/downloads/Novarr-Books/Ada.Wren-The.Quiet.Orchard.RETAIL.EPUB'];

        $counts = app(DownloadPoller::class)->poll();

        $this->assertSame(1, $counts['imported']);
        $request->refresh();
        $this->assertFalse($request->isWanted(), 'the request has its ePub now');
        $this->assertTrue($request->isPending(), 'the owner reviews it (auto-approve is off)');
        $this->assertSame('book.epub', $request->upload_meta['original_name'], 'the largest epub, not the sample');
        $grab->refresh();
        $this->assertSame(['imported', 'request', $request->id], [$grab->status, $grab->imported_type, $grab->imported_id]);
        $this->assertSame('Finished — ready to review', $grab->present()['status_line']);
        $this->assertStringEndsWith("/books/review/{$request->id}", $grab->present()['next_url']);
        Mail::assertSent(AdminAlertMail::class, fn($m) => $m->hasTo('owner@example.test')
            && str_contains($m->body, 'Your download of “The Quiet Orchard” finished') && str_ends_with((string) $m->url, "/books/review/{$request->id}"));
        $this->assertDirectoryDoesNotExist($dir, 'a usenet download leaves the inbox');
    }

    public function test_a_finished_torrent_ebook_is_copied_and_left_seeding(): void
    {
        $grab = $this->grab(['protocol' => 'torrent', 'client' => 'transmission', 'client_ref' => 'abc0', 'completed_path' => 'Orchard']);
        $this->torrents['abc0'] = ['hashString' => 'abc0', 'name' => 'Orchard', 'percentDone' => 1, 'leftUntilDone' => 0, 'status' => 6, 'error' => 0];
        $dir = $this->finishedEpub('Orchard');

        app(DownloadPoller::class)->poll();
        app(DownloadPoller::class)->poll();

        $this->assertSame('imported', $grab->fresh()->status);
        $this->assertFileExists($dir . '/book.epub', 'the torrent keeps its files: it keeps seeding');
        $this->assertSame(1, NovelRequest::whereNotNull('upload_path')->count(), 'not taken twice');
        $upload = NovelRequest::whereNotNull('upload_path')->sole();
        $this->assertSame($this->owner()->id, $upload->user_id, 'a standalone search lands in the review queue as the owner');
        Http::assertNotSent(fn(HttpRequest $r) => str_contains($r->url(), 'transmission') && in_array($r['method'] ?? null, ['torrent-remove'], true));
    }

    public function test_a_download_without_an_epub_fails_with_what_it_holds(): void
    {
        $grab = $this->grab(['status' => 'completed', 'completed_path' => 'Only.Pdf', 'completed_at' => now()]);
        File::ensureDirectoryExists($this->tmp . '/books-inbox/Only.Pdf');
        file_put_contents($this->tmp . '/books-inbox/Only.Pdf/book.pdf', '%PDF');

        app(DownloadPoller::class)->poll();

        $this->assertSame('There is no ePub in this download (it has book.pdf).', $grab->fresh()->error);
    }

    public function test_a_clear_match_is_added_to_the_library_at_once_and_the_reader_told(): void
    {
        config(['novarr.downloads.auto_approve' => true]);
        $request = $this->wantedRequest();
        $grab = $this->grab(['novel_request_id' => $request->id, 'status' => 'completed', 'completed_path' => 'Orchard', 'completed_at' => now()]);
        $this->finishedEpub('Orchard');

        app(DownloadPoller::class)->poll();

        $novel = Novel::where('medium', Novel::MEDIUM_BOOK)->sole();
        $this->assertSame('The Quiet Orchard', $novel->name);
        $this->assertSame('The catalogue description.', $novel->description, "the request's catalogue details");
        $request->refresh();
        $this->assertSame(NovelRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($novel->id, $request->novel_id);
        Mail::assertSent(RequestAvailable::class, fn($m) => $m->hasTo('sam@example.test'));
        Mail::assertSent(AdminAlertMail::class, fn($m) => str_starts_with($m->body, 'Added automatically: “The Quiet Orchard”'));
        Mail::assertSent(AdminAlertMail::class, fn($m) => $m->details['title'] === 'The Quiet Orchard' && $m->details['button'] === 'Open the book'
            && $m->details['tone'] === 'success' && str_contains($m->render(), 'Open the book'));
        $this->assertSame(['imported', 'book', $novel->id], [$grab->fresh()->status, $grab->fresh()->imported_type, $grab->fresh()->imported_id]);
        $this->assertTrue(DownloadEvent::where('event', DownloadEvent::AUTO_APPROVED)->exists());
    }

    public function test_an_automatically_added_book_keeps_the_series_its_request_knew(): void
    {
        $request = $this->wantedRequest();
        $request->forceFill(['candidates' => [['source' => 'book-link', 'source_name' => 'Goodreads', 'url' => 'https://www.goodreads.com/book/show/1', 'title' => 'The Quiet Orchard', 'series' => 'Orchard Cycle', 'series_index' => 1.0]]])->save();

        $details = \App\Downloads\BookDownloads::details($request->fresh());
        $this->assertSame('Orchard Cycle', $details['series']);
        $this->assertSame(1.0, (float) $details['series_index']);
    }

    public function test_a_mismatched_epub_waits_in_review(): void
    {
        config(['novarr.downloads.auto_approve' => true]);
        $request = $this->wantedRequest('The Quiet Orchard', 'Bo Lark');
        $this->grab(['novel_request_id' => $request->id, 'status' => 'completed', 'completed_path' => 'Orchard', 'completed_at' => now()]);
        $this->finishedEpub('Orchard');

        app(DownloadPoller::class)->poll();

        $this->assertSame(0, Novel::where('medium', Novel::MEDIUM_BOOK)->count());
        $this->assertTrue($request->fresh()->isPending());
        $this->assertNotNull($request->fresh()->upload_path);
        Mail::assertSent(AdminAlertMail::class, fn($m) => str_contains($m->body, "check it's the right book"));
        Mail::assertSent(AdminAlertMail::class, fn($m) => $m->details['button'] === 'Review and match' && $m->details['tone'] === 'warning');
    }

    public function test_a_book_already_in_the_library_closes_the_request_as_it(): void
    {
        $existing = app(BookLibrary::class)->import(EpubBuilder::epub3(), 'orchard.epub', false, false)['novel'];
        $request = $this->wantedRequest();
        $grab = $this->grab(['novel_request_id' => $request->id, 'status' => 'completed', 'completed_path' => 'Orchard', 'completed_at' => now()]);
        $this->finishedEpub('Orchard');

        app(DownloadPoller::class)->poll();

        $request->refresh();
        $this->assertSame(NovelRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($existing->id, $request->novel_id);
        $this->assertSame(['book', $existing->id], [$grab->fresh()->imported_type, $grab->fresh()->imported_id]);
        $this->assertSame(1, Novel::where('medium', Novel::MEDIUM_BOOK)->count());
    }

    // --- A finished audiobook --------------------------------------------------------------

    public function test_a_grabbed_torrent_audiobook_is_copied_and_linked_to_its_book(): void
    {
        $book = $this->book('The Final Empire');
        // Tags that would never match the book by themselves.
        $file = AudiobookLibrary::inbox() . '/Mistborn.1.m4b';
        file_put_contents($file, FakeAudioProbe::audioFile(600000, ['title' => 'TFE Unabr', 'artist' => 'Someone']));
        $grab = $this->grab(['kind' => 'audiobook', 'protocol' => 'torrent', 'client' => 'transmission', 'client_ref' => 'abc0', 'novel_id' => $book->id, 'completed_path' => 'Mistborn.1.m4b']);
        app(WantedList::class)->monitorAudiobook($book);
        $this->assertNotNull(WantedList::audiobookFor($book->id));

        // Still downloading: the scan leaves it alone whatever its sizes say.
        $this->assertSame(0, app(AudiobookInbox::class)->scan()['imported']);
        $this->assertSame('downloading', app(AudiobookInbox::class)->status()['pending'][0]['state']);

        $grab->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->assertSame(1, app(AudiobookInbox::class)->scan()['imported']);

        $audiobook = Audiobook::sole();
        $this->assertSame($book->id, $audiobook->novel_id, 'the expected book for this inbox entry');
        $this->assertSame(Audiobook::STATUS_READY, $audiobook->status);
        $this->assertFileExists($file, 'copied, not moved: the torrent keeps seeding');
        $this->assertFileExists(AudiobookLibrary::root() . "/{$audiobook->id}/part-001.m4b");
        $this->assertSame(['imported', 'audiobook', $audiobook->id], [$grab->fresh()->status, $grab->fresh()->imported_type, $grab->fresh()->imported_id]);
        $this->assertNull(WantedList::audiobookFor($book->id), 'a book that gets its audiobook is no longer wanted');

        // The next scans skip it (not "already in the library" → _failed, which would break the seeding).
        $counts = app(AudiobookInbox::class)->scan();
        $this->assertSame([0, 0], [$counts['imported'], $counts['failed']]);
        $this->assertFileExists($file);
        $this->assertSame('seeding', app(AudiobookInbox::class)->status()['pending'][0]['state']);
    }

    public function test_a_grab_whose_files_are_another_library_book_is_linked_to_that_book(): void
    {
        $wanted = $this->book('A Court of Thorns and Roses', 'Sarah J. Maas');
        $actual = $this->book('A Court of Splintered Harmony', 'Sarah J. Maas');
        $file = AudiobookLibrary::inbox() . '/06 - A Court of Splintered Harmony.m4b';
        file_put_contents($file, FakeAudioProbe::audioFile(600000, ['title' => 'A Court of Splintered Harmony', 'album' => 'A Court of Splintered Harmony', 'artist' => 'Sarah J. Maas']));
        $this->grab(['kind' => 'audiobook', 'protocol' => 'torrent', 'client' => 'transmission', 'client_ref' => 'abc0', 'novel_id' => $wanted->id,
            'completed_path' => '06 - A Court of Splintered Harmony.m4b', 'status' => 'completed', 'completed_at' => now()]);

        $this->assertSame(1, app(AudiobookInbox::class)->scan()['imported']);
        $this->assertSame($actual->id, Audiobook::sole()->novel_id);
    }

    public function test_a_release_naming_another_book_of_the_series_is_never_taken_by_itself(): void
    {
        $wanted = ['kind' => 'audiobook', 'title' => 'A Court of Thorns and Roses', 'author' => 'Sarah J. Maas', 'series_index' => 1.0];
        $other = \App\Downloads\ReleaseRanking::score(['title' => 'Sarah J Maas (Narrated by Elizabeth Evans) - A Court of Splintered Harmony - A Court of Thorns and Roses Book 6 [m4b]', 'size' => 700 * 1048576, 'protocol' => 'torrent', 'seeders' => 18], $wanted);
        $right = \App\Downloads\ReleaseRanking::score(['title' => 'Sarah J. Maas - A Court of Thorns and Roses (Book 1) [m4b]', 'size' => 700 * 1048576, 'protocol' => 'torrent', 'seeders' => 18], $wanted);

        $this->assertContains('says book 6 (this is book 1)', $other['warnings']);
        $this->assertSame([], $right['warnings']);
        $this->assertGreaterThan($other['score'], $right['score']);
        $this->assertSame([6.0], \App\Downloads\ReleaseRanking::bookNumbers('ACOTAR Book Six'));
        $this->assertSame([], \App\Downloads\ReleaseRanking::bookNumbers('Dune 1965 Retail 1080p'));
    }

    public function test_a_reader_asks_for_an_audiobook_from_the_app(): void
    {
        $book = $this->book('The Final Empire');
        $reader = $this->makeReader('kim', 'Kim');

        $this->asToken($this->tokenFor($reader))->postJson("/api/v1/novels/{$book->id}/audiobook/ask")
            ->assertOk()->assertJsonPath('asked', true);
        $this->assertNotNull(WantedList::audiobookFor($book->id));
        $this->asToken($this->tokenFor($reader))->postJson('/api/v1/novels/999999/audiobook/ask')->assertNotFound();
    }

    public function test_needs_you_and_seeding_tabs(): void
    {
        $book = $this->book('The Final Empire');
        \App\Audiobook::create(['title' => 'Mystery Tape', 'status' => \App\Audiobook::STATUS_UNMATCHED, 'match_note' => 'no book in the library has this title and author', 'dir' => 'x', 'fingerprint' => 'fp1', 'duration_ms' => 1, 'size_bytes' => 1, 'added_at' => now()]);
        $this->grab(['kind' => 'ebook', 'protocol' => 'usenet', 'client' => 'nzbget', 'client_ref' => '9', 'status' => 'failed', 'error' => 'Wrong book']);
        $this->grab(['kind' => 'audiobook', 'protocol' => 'torrent', 'client' => 'transmission', 'client_ref' => 'abc0', 'novel_id' => $book->id, 'status' => 'imported']);
        $this->torrents['abc0'] = ['hashString' => 'abc0', 'name' => 'Mistborn', 'percentDone' => 1, 'leftUntilDone' => 0, 'status' => 6, 'error' => 0, 'errorString' => '', 'uploadRatio' => 1.25, 'uploadedEver' => 1048576, 'secondsSeeding' => 90000, 'peersGettingFromUs' => 2, 'seedRatioMode' => 2];

        $this->asSignedIn($this->owner())->get(route('downloads.index'))->assertOk()
            ->assertSee('Needs you')->assertSee('Audiobooks to match')->assertSee('Mystery Tape')->assertSee('Failed this week')->assertSee('Wrong book');
        $this->asSignedIn($this->owner())->get(route('downloads.index', ['tab' => 'seeding']))->assertOk()
            ->assertSee('Mistborn')->assertSee('1.25')->assertSee('Seeding')->assertSee('None');
    }

    public function test_a_usenet_audiobook_is_moved_as_usual_and_a_failed_import_is_recorded(): void
    {
        $book = $this->book('The Final Empire');
        file_put_contents(AudiobookLibrary::inbox() . '/Bad.mp3', FakeAudioProbe::audioFile(1000) . 'unreadable');
        $bad = $this->grab(['kind' => 'audiobook', 'status' => 'completed', 'completed_path' => 'Bad.mp3', 'novel_id' => $book->id]);
        file_put_contents(AudiobookLibrary::inbox() . '/Good.m4b', FakeAudioProbe::audioFile(5000, ['title' => 'x']));
        $this->grab(['kind' => 'audiobook', 'status' => 'completed', 'completed_path' => 'Good.m4b', 'client_ref' => '102', 'title' => 'Good', 'guid' => 'g-2', 'novel_id' => $book->id]);

        app(AudiobookInbox::class)->scan();

        $this->assertFileDoesNotExist(AudiobookLibrary::inbox() . '/Good.m4b', 'usenet: moved into the library');
        $this->assertSame($book->id, Audiobook::sole()->novel_id);
        $this->assertSame('failed', $bad->fresh()->status);
        $this->assertStringStartsWith('Not imported:', $bad->fresh()->error);
    }

    // --- Automation ------------------------------------------------------------------------------

    public function test_the_backoff_and_the_daily_cap(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->assertEquals(now()->addHour(), WantedList::nextSearch(1));
        $this->assertEquals(now()->addHours(6), WantedList::nextSearch(2));
        $this->assertEquals(now()->addHours(24), WantedList::nextSearch(3));
        $this->assertEquals(now()->addHours(72), WantedList::nextSearch(4));
        $this->assertEquals(now()->addHours(72), WantedList::nextSearch(9), 'then every three days');

        config(['novarr.downloads.auto_search' => true, 'novarr.downloads.searches_per_day' => 2]);
        $this->prowlarr = [];
        Bus::fake([\App\Jobs\SearchWanted::class]);
        foreach (['One Book', 'Two Book', 'Three Book'] as $title) {
            $this->wantedRequest($title);
        }
        $this->assertSame(3, DownloadWanted::count(), 'every wanted book is on the list');
        Bus::assertDispatchedTimes(\App\Jobs\SearchWanted::class, 3);

        Artisan::call('downloads:search-wanted');

        $this->assertSame(2, collect(Http::recorded())->filter(fn($p) => str_contains($p[0]->url(), 'prowlarr.test/api/v1/search'))->count());
        $this->assertSame(1, DownloadWanted::whereNull('last_searched_at')->count(), 'the cap leaves the third for tomorrow');
        $searched = DownloadWanted::whereNotNull('last_searched_at')->first();
        $this->assertSame(1, $searched->searches);
        $this->assertEquals(now()->addHour(), $searched->next_search_at);

        // Not due again yet: nothing more is searched, even with the cap lifted.
        config(['novarr.downloads.searches_per_day' => 60]);
        Artisan::call('downloads:search-wanted');
        $this->assertSame(0, DownloadWanted::whereNull('last_searched_at')->count());
        $this->assertSame(3, collect(Http::recorded())->filter(fn($p) => str_contains($p[0]->url(), 'prowlarr.test/api/v1/search'))->count());
    }

    public function test_a_confident_result_is_grabbed_automatically_at_once(): void
    {
        config(['novarr.downloads.auto_search' => true]);
        $this->prowlarr = [
            $this->row('Ada Wren - The Quiet Orchard (pdf)'),
            $this->row('Ada Wren - The Quiet Orchard (retail) (epub)'),
            $this->row('Ada Wren - The Quiet Orchard [EPUB]', ['protocol' => 'torrent', 'seeders' => 40]),
        ];

        $request = $this->wantedRequest();

        $grab = DownloadGrab::sole();
        $this->assertTrue($grab->auto);
        $this->assertSame('Ada Wren - The Quiet Orchard (retail) (epub)', $grab->title, 'usenet first, epub, never pdf');
        $this->assertSame($request->id, $grab->novel_request_id);
        $this->assertSame(DownloadWanted::sole()->id, $grab->wanted_id);
        $this->assertSame(DownloadEvent::AUTO_GRABBED, DownloadEvent::sole()->event);

        // While it downloads, the item is not searched again.
        DownloadWanted::query()->update(['next_search_at' => now()->subMinute()]);
        Artisan::call('downloads:search-wanted');
        $this->assertSame(1, collect(Http::recorded())->filter(fn($p) => str_contains($p[0]->url(), 'prowlarr.test/api/v1/search'))->count());
    }

    public function test_without_a_confident_result_it_waits_for_the_owner_who_is_told_once(): void
    {
        config(['novarr.downloads.auto_search' => true]);
        $this->prowlarr = [
            $this->row('Ada Wren - The Quiet Orchard (pdf)'),
            $this->row('The Quiet Orchard [EPUB]'), // no author in the name
            $this->row('Ada Wren - The Quiet Orchard [EPUB]', ['protocol' => 'torrent', 'seeders' => 1]),
        ];

        $this->wantedRequest();

        $this->assertSame(0, DownloadGrab::count());
        $row = DownloadWanted::sole();
        $this->assertTrue($row->needs_choice);
        $this->assertSame(3, $row->results_count);

        Artisan::call('downloads:search-wanted');
        Artisan::call('downloads:search-wanted');
        Mail::assertSent(AdminAlertMail::class, 1);
        Mail::assertSent(AdminAlertMail::class, fn($m) => str_contains($m->body, '“The Quiet Orchard”') && str_ends_with((string) $m->url, '/downloads?tab=wanted'));
        Mail::assertSent(AdminAlertMail::class, fn($m) => $m->details['button'] === 'Pick a result' && count($m->details['items']) === 1
            && $m->details['items'][0]['title'] === 'The Quiet Orchard' && str_contains($m->render(), 'data-mail-item'));

        // The owner's manual grab overrides the profile.
        $search = $this->postJson('/downloads/search', ['kind' => 'ebook', 'request_id' => $row->novel_request_id])->json();
        $pdf = collect($search['results'])->firstWhere('format', 'pdf');
        $this->assertSame('pdf is not an allowed format', $pdf['auto']);
        $this->postJson('/downloads/grab', ['search_id' => $search['search_id'], 'guid' => $pdf['guid']])->assertCreated();
        $this->assertFalse($row->fresh()->needs_choice);
    }

    public function test_a_failed_grab_is_blocklisted_and_the_next_best_release_grabbed(): void
    {
        config(['novarr.downloads.auto_search' => true]);
        $this->prowlarr = [
            $this->row('Ada Wren - The Quiet Orchard (retail) (epub)'),
            $this->row('Ada.Wren.The.Quiet.Orchard.EPUB', ['indexer' => 'NZBFinder', 'age' => 900]),
        ];
        $this->wantedRequest();
        $first = DownloadGrab::sole();
        $this->assertSame('Ada Wren - The Quiet Orchard (retail) (epub)', $first->title);

        $this->nzbHistory[$first->client_ref] = ['NZBID' => (int) $first->client_ref, 'Name' => 'x', 'Status' => 'FAILURE/HEALTH', 'DestDir' => ''];
        app(DownloadPoller::class)->poll();

        $this->assertSame('failed', $first->fresh()->status);
        $this->assertTrue(DownloadBlock::where('title', $first->title)->exists());
        $next = DownloadGrab::where('status', 'queued')->sole();
        $this->assertSame('Ada.Wren.The.Quiet.Orchard.EPUB', $next->title, 'the next best, not the blocklisted one');
        $this->assertTrue($next->auto);

        // The owner takes it off the blocklist.
        $block = DownloadBlock::sole();
        $this->delete(route('downloads.blocklist.destroy', $block->id))->assertRedirect();
        $this->assertSame(0, DownloadBlock::count());
    }

    public function test_mark_failed_and_search_again(): void
    {
        $request = $this->wantedRequest();
        app(WantedList::class)->addRequest($request);
        $grab = $this->grab(['novel_request_id' => $request->id, 'status' => 'downloading']);
        config(['novarr.downloads.auto_search' => true]);
        $this->prowlarr = [];

        $this->post(route('downloads.grabs.fail', $grab->id))->assertRedirect()->assertSessionHas('success');

        $this->assertSame('Marked failed by the owner.', $grab->fresh()->error);
        $this->assertSame(1, DownloadBlock::count());
        $this->assertNotNull(DownloadWanted::sole()->last_searched_at, 'searched again at once');
    }

    public function test_a_book_audiobook_is_monitored_by_the_owner_and_asked_for_by_a_reader(): void
    {
        $book = $this->book();
        $this->enableSignIn();

        // A reader asks: wanted, and the owner is told once.
        $this->asSignedIn($this->reader)->get(route('novels.show', $book->id))->assertOk()->assertSee('Ask for the audiobook')->assertDontSee('Find the audiobook');
        $this->asSignedIn($this->reader)->post(route('novels.audiobook_ask', $book->id))->assertRedirect()->assertSessionHas('success');
        $this->asSignedIn($this->reader)->post(route('novels.audiobook_ask', $book->id))->assertRedirect();
        $row = WantedList::audiobookFor($book->id);
        $this->assertSame($this->reader->id, $row->requested_by);
        Mail::assertSent(AdminAlertMail::class, 1);
        Mail::assertSent(AdminAlertMail::class, fn($m) => $m->hasTo('owner@example.test') && str_contains($m->body, 'Sam asked for the audiobook of “The Final Empire”'));
        $this->asSignedIn($this->reader)->get(route('novels.show', $book->id))->assertSee('Audiobook asked for');
        // The reader cannot reach the owner's switch.
        $this->asSignedIn($this->reader)->post(route('novels.audiobook_monitor', $book->id))->assertForbidden();

        // The owner sees it wanted, and can stop and start looking for it.
        $owner = $this->owner();
        $this->asSignedIn($owner)->get(route('novels.show', $book->id))->assertOk()->assertSee('Find the audiobook')->assertSee('asked for by Sam')->assertSee('Stop looking for it');
        $this->asSignedIn($owner)->post(route('novels.audiobook_monitor', $book->id), ['want' => 0])->assertRedirect();
        $this->assertNull(WantedList::audiobookFor($book->id));
        $this->asSignedIn($owner)->post(route('novels.audiobook_monitor', $book->id), ['want' => 1])->assertRedirect();
        $this->assertNotNull(WantedList::audiobookFor($book->id));

        // It arrives (matched by hand): no longer wanted, and no more buttons.
        $audiobook = Audiobook::create(['title' => 'x', 'fingerprint' => sha1('x'), 'status' => 'unmatched', 'dir' => '1']);
        app(\App\Audiobooks\AudiobookMatcher::class)->match($audiobook, $book->id);
        $this->assertNull(WantedList::audiobookFor($book->id));
        $this->asSignedIn($this->reader)->get(route('novels.show', $book->id))->assertDontSee('Ask for the audiobook');
    }

    // --- Pages, access, off ------------------------------------------------------------------------

    public function test_the_widget_is_on_the_wanted_page_for_the_owner_only(): void
    {
        $request = $this->wantedRequest();
        $this->enableSignIn();

        $this->asSignedIn($this->owner())->get(route('books.review', $request->id))
            ->assertOk()->assertSee('data-download-finder', false)->assertSee('Search Usenet &amp; torrents', false)
            ->assertSee('value="The Quiet Orchard Ada Wren"', false);
        $this->asSignedIn($this->reader)->get(route('books.review', $request->id))->assertForbidden();
        $this->asSignedIn($this->reader)->get(route('downloads.index'))->assertForbidden();
        $this->asSignedIn($this->reader)->postJson('/downloads/search', ['kind' => 'ebook', 'q' => 'x'])->assertForbidden();
    }

    public function test_the_downloads_page_tabs(): void
    {
        $request = $this->wantedRequest();
        app(WantedList::class)->addRequest($request);
        $grab = $this->grab(['novel_request_id' => $request->id, 'status' => 'downloading', 'progress' => 43]);
        DownloadEvent::log(DownloadEvent::GRABBED, 'Some release', 'epub · 1.1 MB');
        DownloadBlock::create(['kind' => 'ebook', 'title' => 'Bad release', 'indexer' => 'NZBgeek', 'reason' => 'FAILURE/PAR']);

        $this->get(route('downloads.index'))->assertOk()->assertSee('Needs you');
        $this->get(route('downloads.index', ['tab' => 'queue']))->assertOk()->assertSee('Downloading 43%')->assertSee('Mark failed &amp; search again', false)->assertSee('Wanted: The Quiet Orchard');
        $this->get(route('downloads.index', ['tab' => 'history']))->assertOk()->assertSee('Some release');
        $this->get(route('downloads.index', ['tab' => 'wanted']))->assertOk()->assertSee('The Quiet Orchard')->assertSee('Search now')->assertSee('Unmonitor')->assertSee('Interactive search');
        $this->get(route('downloads.index', ['tab' => 'blocklist']))->assertOk()->assertSee('Bad release');
        $this->get(route('downloads.index', ['tab' => 'settings']))->assertOk()->assertSee('Searches a day');

        $this->post(route('downloads.settings'), ['auto_grab' => '1', 'min_seeders' => 5, 'prefer_protocol' => 'torrent', 'searches_per_day' => 30])->assertRedirect();
        $this->assertSame([false, true, false, 5, 'torrent', 30], [
            filter_var(DownloadProfile::setting('auto_search'), FILTER_VALIDATE_BOOLEAN), DownloadProfile::autoGrab(), DownloadProfile::autoApprove(),
            DownloadProfile::minSeeders(), DownloadProfile::preferProtocol(), DownloadProfile::searchesPerDay(),
        ]);

        $row = DownloadWanted::sole();
        $this->post(route('downloads.wanted.unmonitor', $row->id))->assertRedirect();
        $this->assertFalse($row->fresh()->monitored);
        $this->assertTrue($grab->fresh()->isActive());
    }

    public function test_the_audiobooks_page_has_the_search_box(): void
    {
        $this->get(route('audiobooks.manage'))->assertOk()->assertSee('Search for an audiobook')->assertSee('data-kind="audiobook"', false);
    }

    public function test_off_without_prowlarr(): void
    {
        config(['novarr.prowlarr.url' => '', 'novarr.prowlarr.api_key' => '']);
        $request = $this->wantedRequest();

        $this->assertSame(0, DownloadWanted::count(), 'nothing is put on the wanted list');
        $this->get(route('books.review', $request->id))->assertOk()->assertDontSee('data-download-finder', false);
        $this->get(route('audiobooks.manage'))->assertOk()->assertDontSee('Search for an audiobook');
        $this->get(route('novels.show', $this->book()->id))->assertOk()->assertDontSee('Find the audiobook');
        $this->get(route('downloads.index'))->assertOk()->assertSee('Searching is off');
        $this->postJson('/downloads/search', ['kind' => 'ebook', 'q' => 'x'])->assertStatus(422)->assertJsonPath('message', 'Prowlarr is not set up (NOVARR_PROWLARR_URL and NOVARR_PROWLARR_API_KEY).');
        $this->assertSame(0, Artisan::call('downloads:poll'));
        $this->assertSame(0, Artisan::call('downloads:search-wanted'));
        Http::assertNothingSent();
    }

    public function test_poll_and_search_are_scheduled(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();
        $events = collect($this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $poll = $events->firstWhere('description', 'downloads_poll');
        $this->assertSame('*/2 * * * *', $poll->expression);
        $this->assertTrue($poll->withoutOverlapping);
        $search = $events->firstWhere('description', 'downloads_search_wanted');
        $this->assertSame('*/15 * * * *', $search->expression);
        $this->assertStringContainsString('downloads:search-wanted', $search->command);
    }
}
