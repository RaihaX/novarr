<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Books\CalibreImport;
use App\Books\CalibrePush;
use App\Books\CalibreWebWriter;
use App\Jobs\PushBookToCalibre;
use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\TestCase;

/**
 * Two-way Calibre sync, Novarr → Calibre (App\Books\CalibrePush): a book
 * added in Novarr is uploaded to Calibre-Web with its matched details, an
 * edit updates them, and the hourly import never brings it back.
 */
class CalibreSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://calibre.test';

    private string $booksDir;

    /** What the fake Calibre-Web received. */
    private array $posts = [];

    private bool $signedIn = false;

    private array $editForm = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        Bus::fake([\App\Jobs\RunNovelCommand::class]);
        $this->booksDir = sys_get_temp_dir() . '/novarr-books-' . uniqid();
        config([
            'novarr.books.path' => $this->booksDir . '/uploads',
            'novarr.books.calibre_path' => $this->booksDir . '/calibre',
            'novarr.calibre_web.url' => self::BASE,
            'novarr.calibre_web.username' => 'novarr',
            'novarr.calibre_web.password' => 'secret',
            'novarr.calibre_web.languages' => ['eng'],
            'novarr.books.lookup' => false,
        ]);
        $this->fakeCalibreWeb();
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        File::deleteDirectory($this->booksDir);
        parent::tearDown();
    }

    private static function editPage(): string
    {
        // As Calibre-Web draws it: the fields Novarr changes, and some it must keep.
        return '<html><body><form id="book_edit_frm" method="post" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf_token" value="tok-edit">'
            . '<input type="text" name="title" value="dump_file_title">'
            . '<input type="text" name="authors" value="Unknown">'
            . '<textarea name="comments"></textarea>'
            . '<input type="text" name="tags" value="">'
            . '<input type="text" name="series" value="">'
            . '<input type="number" name="series_index" value="1">'
            . '<input type="text" name="languages" value="English">'
            . '<input type="text" name="identifier-type-isbn" value="isbn"><input type="text" name="identifier-val-isbn" value="9780306406157">'
            . '<select name="rating"><option value="0">0</option><option value="4" selected>4</option></select>'
            . '<input type="checkbox" name="custom_column_1" checked>'
            . '<input type="file" name="btn-upload-cover"><button type="submit" name="submit">Save</button>'
            . '</form></body></html>';
    }

    private function fakeCalibreWeb(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '/login' && $request->method() === 'GET') {
                return Http::response('<form><input type="hidden" name="csrf_token" value="tok-login"><input name="username"><input name="password"><input type="checkbox" name="remember_me"></form>');
            }
            if ($path === '/login') {
                $this->posts[] = ['login', $request->data()];
                $this->signedIn = ($request->data()['password'] ?? '') === 'secret' && ($request->data()['csrf_token'] ?? '') === 'tok-login';

                return Http::response('', 302, ['Location' => '/', 'Set-Cookie' => 'session=abc; Path=/']);
            }
            if ($path === '/' ) {
                return Http::response('home');
            }
            // OPDS: basic auth, not the web session.
            if (str_starts_with($path, '/opds/search')) {
                return Http::response('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:dcterms="http://purl.org/dc/terms/"><id>x</id>'
                    . '<entry><title>The Quiet Orchard</title><id>urn:uuid:uuid-77</id><author><name>Ada Wren</name></author><dcterms:language>eng</dcterms:language>'
                    . '<link rel="http://opds-spec.org/acquisition" href="/opds/download/77/epub/" type="application/epub+zip"/></entry></feed>');
            }
            if (!$this->signedIn) {
                return Http::response('<form><input name="username"><input type="checkbox" name="remember_me"></form>');
            }
            if ($path === '/me') {
                return Http::response('<form><input type="hidden" name="csrf_token" value="tok-me"><input name="password"></form>');
            }
            if ($path === '/upload') {
                $this->posts[] = ['upload', $request->body()];

                return Http::response(['location' => '/admin/book/77']);
            }
            if ($path === '/admin/book/77' && $request->method() === 'GET') {
                return Http::response(self::editPage());
            }
            if ($path === '/admin/book/77') {
                $fields = [];
                foreach ($request->data() as $part) {
                    if (is_array($part) && isset($part['name'])) {
                        $fields[$part['name']] = is_string($part['contents']) ? $part['contents'] : '(file)';
                    }
                }
                $this->editForm = $fields;
                $this->posts[] = ['edit', $fields];

                return Http::response('saved');
            }

            return Http::response('', 404);
        });
    }

    private function book(): Novel
    {
        $novel = app(BookLibrary::class)->import(EpubBuilder::epub3(['title' => 'dump_file_title']), 'dump.epub', false, false, [
            'title' => 'The Quiet Orchard',
            'author' => 'Ada Wren, Bo Lark',
            'description' => "First line.\n\nSecond paragraph & more.",
            'genres' => ['Fantasy', 'Coming of age'],
        ])['novel'];

        return $novel->fresh();
    }

    public function test_a_book_added_in_novarr_is_uploaded_with_its_matched_details(): void
    {
        $novel = $this->book();

        $this->assertSame('uploaded', app(CalibrePush::class)->push($novel));

        $novel->refresh();
        $this->assertSame(77, (int) $novel->calibre_id);
        $this->assertSame('uuid-77', $novel->calibre_uuid);
        $this->assertNotNull($novel->calibre_synced_at);
        $this->assertNull($novel->calibre_sync_error);

        $this->assertSame(['login', 'upload', 'edit'], array_column($this->posts, 0));
        $this->assertStringContainsString('name="btn-upload"; filename="The Quiet Orchard.epub"', $this->posts[1][1]);

        $form = $this->editForm;
        $this->assertSame('The Quiet Orchard', $form['title']);
        $this->assertSame('Ada Wren & Bo Lark', $form['authors']);
        $this->assertSame('<p>First line.</p><p>Second paragraph &amp; more.</p>', $form['comments']);
        $this->assertSame('tok-edit', $form['csrf_token']);
        // What Calibre had and Novarr does not set is sent back unchanged.
        $this->assertSame('English', $form['languages']);
        $this->assertSame('9780306406157', $form['identifier-val-isbn']);
        $this->assertSame('4', $form['rating']);
        $this->assertSame('on', $form['custom_column_1']);
        $this->assertArrayNotHasKey('submit', $form);
    }

    public function test_the_session_is_kept_so_calibre_web_is_signed_into_once(): void
    {
        app(CalibrePush::class)->push($this->book());
        $other = $this->book();
        $other->forceFill(['calibre_id' => 77])->save();

        // A new writer (another job): the saved session is used, no second sign-in.
        app()->forgetInstance(CalibreWebWriter::class);
        $this->assertSame('updated', app(CalibrePush::class)->push($other->fresh()));
        $this->assertSame(1, count(array_filter($this->posts, fn($p) => $p[0] === 'login')));
    }

    public function test_the_import_never_brings_a_sent_book_back(): void
    {
        $novel = $this->book();
        app(CalibrePush::class)->push($novel);

        $import = app(CalibreImport::class);
        $byUuid = $import->import(['id' => 999, 'uuid' => 'uuid-77', 'title' => 'The Quiet Orchard', 'authors' => ['Ada Wren'], 'epub_href' => self::BASE . '/opds/download/77/epub/']);
        $this->assertSame(CalibreImport::SKIPPED, $byUuid['result']);

        $novel->forceFill(['calibre_uuid' => null])->save();
        $byId = app(CalibreImport::class)->import(['id' => 77, 'uuid' => 'other', 'title' => 'The Quiet Orchard', 'authors' => ['Ada Wren'], 'epub_href' => self::BASE . '/opds/download/77/epub/']);
        $this->assertSame('sent to Calibre from Novarr', $byId['message']);
    }

    public function test_a_book_moved_to_novels_stays_known_to_the_import(): void
    {
        $novel = $this->book();
        $novel->forceFill(['source_ref' => BookLibrary::CALIBRE_REF . 'uuid-5', 'medium' => Novel::MEDIUM_NOVEL])->save();

        $this->assertArrayHasKey('uuid-5', CalibreImport::importedUuids());
    }

    public function test_a_failure_is_kept_on_the_book_and_retried_by_the_command(): void
    {
        $novel = $this->book();
        config(['novarr.calibre_web.password' => 'wrong']);

        $this->assertNull(app(CalibrePush::class)->pushQuietly($novel));
        $this->assertStringContainsString('did not accept', (string) $novel->fresh()->calibre_sync_error);

        config(['novarr.calibre_web.password' => 'secret']);
        $this->artisan('books:calibre-push')->expectsOutputToContain('Uploaded 1, updated 0, failed 0.')->assertSuccessful();
        $this->assertNull($novel->fresh()->calibre_sync_error);
        $this->assertSame(77, (int) $novel->fresh()->calibre_id);
    }

    public function test_approving_an_upload_and_editing_a_book_queue_a_send(): void
    {
        Bus::fake([PushBookToCalibre::class]);
        $novel = $this->book();

        PushBookToCalibre::for($novel);
        Bus::assertDispatched(PushBookToCalibre::class, fn($job) => $job->novelId === $novel->id);

        // Off without the setting, and never for a web novel.
        config(['novarr.calibre_web.push' => false]);
        $this->assertFalse(CalibrePush::enabled());
        config(['novarr.calibre_web.push' => true]);
        $web = Novel::create(['name' => 'A Web Novel', 'status' => 0, 'group_id' => 1]);
        $this->assertFalse(CalibrePush::applies($web));
    }

    public function test_a_book_from_calibre_is_found_by_uuid_and_only_updated(): void
    {
        $novel = $this->book();
        $novel->forceFill(['source_ref' => BookLibrary::CALIBRE_REF . 'uuid-77'])->save();

        $this->assertSame('updated', app(CalibrePush::class)->push($novel->fresh()));
        $this->assertNotContains('upload', array_column($this->posts, 0));
        $this->assertSame(77, (int) $novel->fresh()->calibre_id);
    }

    public function test_form_values_keep_selects_and_skip_unchecked_boxes(): void
    {
        $values = CalibreWebWriter::formValues('<form id="book_edit_frm"><input type="checkbox" name="a"><input type="checkbox" name="b" value="yes" checked>'
            . '<select name="langs" multiple><option value="en" selected>en</option><option value="fr" selected>fr</option></select><textarea name="t">x</textarea></form>');

        $this->assertSame(['b' => 'yes', 'langs' => ['en', 'fr'], 't' => 'x'], $values);
    }
}
