<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Books\CalibreImport;
use App\Books\CalibreWeb;
use App\Novel;
use App\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\TestCase;

class CalibreImportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://calibre.test';

    private string $booksDir;

    /** offset => feed XML, read by the one Http fake (fakes stack, so it is set up once). */
    private array $pages = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
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
        ]);
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        File::deleteDirectory($this->booksDir);
        parent::tearDown();
    }

    /** A Calibre-Web /opds/new page (its feed.xml), newest first. */
    private static function feed(array $books, ?int $next = null): string
    {
        $entries = '';
        foreach ($books as $b) {
            $lang = isset($b['lang']) ? "<dcterms:language>{$b['lang']}</dcterms:language>" : '';
            $tags = implode('', array_map(fn($t) => "<category scheme=\"x\" term=\"{$t}\" label=\"{$t}\"/>", $b['tags'] ?? []));
            $epub = ($b['epub'] ?? true) ? "<link rel=\"http://opds-spec.org/acquisition\" href=\"/opds/download/{$b['id']}/epub/\" length=\"1\" title=\"EPUB\" type=\"application/epub+zip\"/>" : '';
            $entries .= "<entry><title>{$b['title']}</title><id>urn:uuid:uuid-{$b['id']}</id><updated>2026-11-20T00:00:00+00:00</updated>"
                . "<author><name>{$b['author']}</name></author><published>2021-05-01T00:00:00+00:00</published>{$lang}{$tags}"
                . "<content type=\"xhtml\"><div xmlns=\"http://www.w3.org/1999/xhtml\">TAGS: x<br/>SERIES: Cycle [2.00]<br/><p>{$b['title']} description.</p></div></content>"
                . "<link type=\"image/jpeg\" href=\"/opds/cover/{$b['id']}\" rel=\"http://opds-spec.org/image\"/>"
                . "<link rel=\"http://opds-spec.org/acquisition\" href=\"/opds/download/{$b['id']}/mobi/\" title=\"MOBI\" type=\"application/x-mobipocket-ebook\"/>{$epub}</entry>";
        }
        $nextLink = $next !== null ? "<link rel=\"next\" href=\"/opds/new?offset={$next}\" type=\"application/atom+xml\"/>" : '';

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><feed xmlns=\"http://www.w3.org/2005/Atom\" xmlns:dcterms=\"http://purl.org/dc/terms/\"><id>x</id>{$nextLink}{$entries}</feed>";
    }

    private function fakeCalibre(array $pages): void
    {
        $this->pages = $pages;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        $png = (function () {
            $i = imagecreatetruecolor(20, 30);
            ob_start();
            imagepng($i);

            return ob_get_clean();
        })();
        Http::fake(function ($request) use ($png) {
            $pages = $this->pages;
            $url = $request->url();
            if (!$request->hasHeader('Authorization')) {
                return Http::response('', 401);
            }
            if (preg_match('#/opds/new(?:\?offset=(\d+))?#', $url, $m)) {
                return Http::response($pages[(int) ($m[1] ?? 0)] ?? self::feed([]));
            }
            if (preg_match('#/opds/download/(\d+)/epub/#', $url, $m)) {
                return Http::response(file_get_contents(EpubBuilder::epub3(['title' => "File {$m[1]}", 'isbn' => null, 'description' => "d{$m[1]}"])));
            }
            if (str_contains($url, '/opds/cover/')) {
                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            }
            if (str_ends_with($url, '/opds')) {
                return Http::response(self::feed([]));
            }

            return Http::response('', 404);
        });
    }

    public function test_the_feed_is_parsed_and_other_languages_left_out(): void
    {
        $page = CalibreWeb::parse(self::feed([
            ['id' => 9, 'title' => 'Dune', 'author' => 'Frank Herbert', 'lang' => 'eng', 'tags' => ['Science Fiction']],
            ['id' => 8, 'title' => 'Ningen Shikkaku', 'author' => 'Dazai', 'lang' => 'jpn'],
            ['id' => 7, 'title' => 'No Language', 'author' => 'Someone', 'epub' => false],
        ], 60));

        $this->assertSame(60, $page['next']);
        $this->assertSame(['Dune', 'No Language'], array_column($page['entries'], 'title'));
        $dune = $page['entries'][0];
        $this->assertSame(9, $dune['id']);
        $this->assertSame('uuid-9', $dune['uuid']);
        $this->assertSame(['Frank Herbert'], $dune['authors']);
        $this->assertSame(['Science Fiction'], $dune['tags']);
        $this->assertSame('Dune description.', $dune['description']);
        $this->assertSame('Cycle', $dune['series']);
        $this->assertSame(2021, $dune['year']);
        $this->assertSame('/opds/download/9/epub/', $dune['epub_href']);
        $this->assertSame('/opds/cover/9', $dune['cover_href']);
        $this->assertNull($page['entries'][1]['epub_href']);
    }

    public function test_import_uses_calibres_metadata_and_keeps_the_original_outside_the_uploads(): void
    {
        $this->fakeCalibre([0 => self::feed([['id' => 9, 'title' => 'Dune', 'author' => 'Frank Herbert', 'lang' => 'eng', 'tags' => ['Science Fiction']]])]);
        $entry = app(CalibreWeb::class)->recent()['entries'][0];

        $outcome = app(CalibreImport::class)->import($entry);

        $this->assertSame(CalibreImport::IMPORTED, $outcome['result']);
        $book = $outcome['novel']->fresh(['file']);
        $this->assertSame('Dune', $book->name);
        $this->assertSame('Frank Herbert', $book->author);
        $this->assertSame('Dune description.', $book->description);
        $this->assertSame('calibre:uuid-9', $book->source_ref);
        $this->assertSame(['Science Fiction'], $book->tags()->pluck('name')->all());
        $this->assertNotNull($book->file);
        $this->assertStringStartsWith($this->booksDir . '/calibre/', BookLibrary::path($book));
        $this->assertFileExists(BookLibrary::path($book));
        Http::assertSent(fn($r) => $r->hasHeader('Authorization', 'Basic ' . base64_encode('novarr:secret')));

        // Never twice.
        $this->assertSame(CalibreImport::SKIPPED, app(CalibreImport::class)->import($entry)['result']);
    }

    public function test_sync_starts_from_now_then_imports_only_new_books(): void
    {
        $old = [['id' => 5, 'title' => 'Old Book', 'author' => 'A', 'lang' => 'eng']];
        $this->fakeCalibre([0 => self::feed($old)]);

        $first = app(CalibreImport::class)->sync();
        $this->assertTrue($first['mark_set']);
        $this->assertSame(0, $first['imported']);
        $this->assertSame('5', (string) Setting::get(CalibreImport::MARK));

        $this->fakeCalibre([
            0 => self::feed([['id' => 7, 'title' => 'Newer', 'author' => 'B', 'lang' => 'eng'], ['id' => 6, 'title' => 'New', 'author' => 'C']], 2),
            2 => self::feed($old),
        ]);
        $second = app(CalibreImport::class)->sync();

        $this->assertSame(2, $second['imported']);
        $this->assertSame(['New', 'Newer'], Novel::books()->orderBy('name')->pluck('name')->all());
        $this->assertSame('7', (string) Setting::get(CalibreImport::MARK));
    }

    public function test_all_walks_every_page_and_skips_what_is_in(): void
    {
        $this->fakeCalibre([
            0 => self::feed([['id' => 3, 'title' => 'Three', 'author' => 'A', 'lang' => 'eng'], ['id' => 2, 'title' => 'Two', 'author' => 'A', 'epub' => false]], 2),
            2 => self::feed([['id' => 1, 'title' => 'One', 'author' => 'A', 'lang' => 'eng']]),
        ]);

        $this->artisan('books:calibre-import', ['--all' => true])->assertSuccessful();
        $this->assertSame(['One', 'Three'], Novel::books()->orderBy('name')->pluck('name')->all());

        $this->artisan('books:calibre-import', ['--all' => true])->expectsOutputToContain('Imported 0')->assertSuccessful();
    }

    public function test_a_web_novel_already_in_the_library_is_not_imported_as_a_book(): void
    {
        Novel::create(['name' => 'Shadow Slave', 'translator_url' => 'https://novelfull.com/shadow-slave.html', 'group_id' => 1]);
        Novel::create(['name' => 'Lord of the Mysteries', 'translator_url' => 'https://novelfull.com/lotm.html', 'group_id' => 1]);
        $this->fakeCalibre([0 => self::feed([
            ['id' => 6, 'title' => 'Shadow Slave Vol. 3', 'author' => 'Guiltythree', 'lang' => 'eng'],
            ['id' => 5, 'title' => 'Book 2: The Fool', 'author' => 'Cuttlefish', 'lang' => 'eng'],
            ['id' => 4, 'title' => 'Dune', 'author' => 'Frank Herbert', 'lang' => 'eng'],
        ])]);
        // Entry 5's Calibre series is the web novel's title.
        $page = app(CalibreWeb::class)->recent();
        $page['entries'][1]['series'] = 'Lord of the Mysteries';

        $import = app(CalibreImport::class);
        $results = array_map(fn($e) => $import->import($e), $page['entries']);

        $this->assertSame([CalibreImport::SKIPPED, CalibreImport::SKIPPED, CalibreImport::IMPORTED], array_column($results, 'result'));
        $this->assertStringContainsString('web novel in the library: Shadow Slave', $results[0]['message']);
        $this->assertSame(['Dune'], Novel::books()->pluck('name')->all());
        // Nothing was downloaded for the skipped ones.
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/opds/download/6/') || str_contains($r->url(), '/opds/download/5/'));
    }

    public function test_near_misses_of_a_web_novel_are_caught_but_short_words_are_not(): void
    {
        Novel::create(['name' => 'Sacrificial Artifact System From Bell-Ringer to the Ancestral Patriarch of the Sect', 'translator_url' => 'https://novelfull.com/a.html', 'group_id' => 1]);
        Novel::create(['name' => 'Lord of Mysteries 2 Circle of Inevitability', 'translator_url' => 'https://novelfull.com/b.html', 'group_id' => 1]);
        Novel::create(['name' => 'Xyrin Empire', 'translator_url' => 'https://novelfull.com/c.html', 'group_id' => 1]);
        $import = app(CalibreImport::class);

        $this->assertNotNull($import->webNovelFor(['title' => 'Sacrificial Artifact System From Bell-Ringer to the Ancestral Patriach of the Sect']));
        $this->assertNotNull($import->webNovelFor(['title' => 'Circle of Inevitability']));
        $this->assertNull($import->webNovelFor(['title' => 'Empire']));
        $this->assertNull($import->webNovelFor(['title' => 'The Lord of the Rings']));
    }

    public function test_title_variants_drop_volume_numbering_but_keep_real_titles(): void
    {
        $this->assertContains('Shadow Slave', CalibreImport::titleVariants(['title' => 'Shadow Slave Vol. 3']));
        $this->assertContains('Solo Leveling', CalibreImport::titleVariants(['title' => 'Solo Leveling, Volume Two']));
        $this->assertContains('Overgeared', CalibreImport::titleVariants(['title' => 'Overgeared (Chapters 1-200)']));
        $this->assertContains('Mother of Learning', CalibreImport::titleVariants(['title' => 'Mother of Learning: Arc 1']));
        $this->assertContains('The Wandering Inn', CalibreImport::titleVariants(['title' => 'The Wandering Inn #4']));
        $this->assertSame(['The Book Thief'], CalibreImport::titleVariants(['title' => 'The Book Thief']));
    }

    public function test_dry_run_lists_without_importing(): void
    {
        Setting::put(CalibreImport::MARK, '1');
        $this->fakeCalibre([0 => self::feed([['id' => 3, 'title' => 'Three', 'author' => 'A', 'lang' => 'eng']])]);

        $this->artisan('books:calibre-import', ['--all' => true, '--dry-run' => true])->expectsOutputToContain('Three')->assertSuccessful();

        $this->assertSame(0, Novel::books()->count());
        $this->assertSame('1', (string) Setting::get(CalibreImport::MARK));
    }

    public function test_wrong_credentials_are_reported(): void
    {
        config(['novarr.calibre_web.username' => '']);
        $this->fakeCalibre([]);

        $this->artisan('books:calibre-import')->expectsOutputToContain('refused the username or password')->assertFailed();
    }

    public function test_off_without_a_url(): void
    {
        config(['novarr.calibre_web.url' => '']);

        $this->artisan('books:calibre-import')->expectsOutputToContain('not set up')->assertSuccessful();
        Http::assertNothingSent();
    }
}
