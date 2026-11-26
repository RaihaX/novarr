<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Books\BookLookup;
use App\Books\Epub;
use App\Books\EpubException;
use App\Console\Commands\GenerateePub;
use App\Novel;
use App\NovelChapter;
use App\Sources\BookSource;
use App\Sources\SourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\TestCase;

class BooksTest extends TestCase
{
    use RefreshDatabase;

    private string $booksDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Bus::fake();
        $this->booksDir = sys_get_temp_dir() . '/novarr-books-' . uniqid();
        config(['novarr.books.path' => $this->booksDir]);
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        \Illuminate\Support\Facades\File::deleteDirectory($this->booksDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Reading an ePub
    // ------------------------------------------------------------------

    public function test_epub3_metadata(): void
    {
        $epub = Epub::open(EpubBuilder::epub3());
        $meta = $epub->metadata();

        $this->assertSame('The Quiet Orchard', $meta['title']);
        // The illustrator is a creator but not an author.
        $this->assertSame(['Ada Wren'], $meta['authors']);
        $this->assertSame('en', $meta['language']);
        $this->assertSame('9780306406157', $meta['isbn']);
        $this->assertSame("A quiet story.\n\nSecond line.", $meta['description']);
        $this->assertSame(2019, $meta['year']);
        $this->assertSame('Small Press', $meta['publisher']);
        $this->assertSame('Orchard Cycle', $meta['series']);
        $this->assertSame(2.0, $meta['series_index']);

        $cover = $epub->cover();
        $this->assertSame('image/png', $cover['type']);
        $this->assertNotEmpty($cover['bytes']);
    }

    public function test_epub3_chapters_follow_the_nav_contents(): void
    {
        $chapters = Epub::open(EpubBuilder::epub3())->chapters();

        // The title page is dropped, the near-empty part page joins the
        // chapter after it, a bare "1" is named after its part, a file split
        // by fragments gives two chapters, and the non-linear notes are left out.
        $this->assertSame(['Part One — 1', 'Two', 'Three'], array_column($chapters, 'label'));

        $first = $chapters[0]['html'];
        $this->assertStringStartsWith('<p><strong>Part One</strong></p><p><strong>1</strong></p>', $first);
        $this->assertStringContainsString('<p>First <em>chapter</em> word', $first);
        $this->assertStringContainsString('More<br/>lines', $first);
        $this->assertStringNotContainsString('alert', $first);
        $this->assertStringNotContainsString('<img', $first);

        $this->assertStringContainsString('Second word', $chapters[1]['html']);
        $this->assertStringNotContainsString('Third', $chapters[1]['html']);
        $this->assertStringContainsString('Third word', $chapters[2]['html']);
        $this->assertStringNotContainsString('Unlisted note', implode('', array_column($chapters, 'html')));
    }

    public function test_epub2_ncx_contents(): void
    {
        $epub = Epub::open(EpubBuilder::epub2());
        $meta = $epub->metadata();

        $this->assertSame(['Bo Brown'], $meta['authors']);
        $this->assertSame('en', $meta['language']);
        $this->assertSame('0306406152', $meta['isbn']);
        $this->assertNull($epub->cover());
        // The nested "2" points at a fragment that is not there: it starts at
        // the top of the file, where "Book I" starts too, and names it.
        $this->assertSame(['Prologue', 'Book I — 2'], array_column($epub->chapters(), 'label'));
    }

    public function test_without_contents_each_document_is_a_chapter(): void
    {
        $chapters = Epub::open(EpubBuilder::epub3(['nav' => false]))->chapters();

        // One chapter per document, named by its first heading; the short
        // title and part pages join the first chapter.
        $this->assertSame(['Chapter 1', 'Two'], array_column($chapters, 'label'));
        $this->assertStringStartsWith('<p><strong>The Quiet Orchard</strong></p>', $chapters[0]['html']);
    }

    public function test_a_file_that_is_not_an_epub_is_refused(): void
    {
        $this->expectException(EpubException::class);
        Epub::open(EpubBuilder::notAnEpub());
    }

    public function test_labels_and_isbn_rules(): void
    {
        $this->assertSame('Chapter IV', Epub::label('IV', null));
        $this->assertSame('Chapter 3', Epub::label('3.', 'Table of Contents'));
        $this->assertSame('Book Two — 3', Epub::label('3', 'Book Two'));
        $this->assertSame('Interlude', Epub::label('Interlude', 'Book Two'));
        $this->assertTrue(Epub::validIsbn('9780306406157'));
        $this->assertFalse(Epub::validIsbn('9780306406158'));
        $this->assertSame('fr', Epub::languageCode('fre'));
        $this->assertNull(Epub::languageCode('und'));
    }

    // ------------------------------------------------------------------
    // Adding a book
    // ------------------------------------------------------------------

    public function test_import_creates_a_complete_book_with_its_chapters_and_cover(): void
    {
        $file = EpubBuilder::epub3();
        $outcome = app(BookLibrary::class)->import($file, 'orchard.epub');

        $this->assertSame(BookLibrary::CREATED, $outcome['result']);
        $book = $outcome['novel']->fresh(['file']);
        $this->assertSame(Novel::MEDIUM_BOOK, $book->medium);
        $this->assertTrue($book->isBook());
        $this->assertSame('The Quiet Orchard', $book->name);
        $this->assertSame('Ada Wren', $book->author);
        $this->assertTrue($book->status);
        $this->assertNotNull($book->completed_at);
        $this->assertSame(3, $book->no_of_chapters);
        $this->assertStringStartsWith(BookLibrary::URL_PREFIX, $book->translator_url);
        // Library headings ("England--Fiction") are not tags; a bracketed note is dropped.
        $this->assertSame(['Fantasy', 'Coming Of Age'], $book->tags()->pluck('name')->all());

        $chapters = NovelChapter::where('novel_id', $book->id)->orderBy('sort_key')->get();
        $this->assertSame(['Part One — 1', 'Two', 'Three'], $chapters->pluck('label')->all());
        // Readable, but never "new chapters": no download date.
        $this->assertTrue($chapters->every(fn($c) => $c->status && $c->download_date === null));
        $this->assertStringContainsString('Second word', $chapters[1]->description);

        // The original is kept and is the book's ePub.
        $this->assertFileEquals($file, BookLibrary::path($book));
        $this->assertSame(BookLibrary::path($book), GenerateePub::epubPath($book));
        $this->assertNotNull($book->file);
        Storage::disk('public')->assertExists(\App\Services\CoverThumbnails::relative($book->file->file_path));

        // The catalogue lookup runs in the background.
        Bus::assertDispatched(\App\Jobs\RunNovelCommand::class);

        $this->assertInstanceOf(BookSource::class, SourceResolver::for($book));
    }

    public function test_the_same_file_twice_is_the_same_book_and_a_same_title_is_a_duplicate(): void
    {
        $library = app(BookLibrary::class);
        $file = EpubBuilder::epub3();
        $first = $library->import($file, 'a.epub');

        $again = $library->import($file, 'a.epub');
        $this->assertSame(BookLibrary::EXISTING, $again['result']);
        $this->assertSame($first['novel']->id, $again['novel']->id);

        // Another edition (another file) with the same title.
        $other = EpubBuilder::epub3(['description' => 'Another edition.']);
        $duplicate = $library->import($other, 'b.epub');
        $this->assertSame(BookLibrary::DUPLICATE, $duplicate['result']);

        $forced = $library->import($other, 'b.epub', force: true);
        $this->assertSame(BookLibrary::CREATED, $forced['result']);
        $this->assertSame(2, Novel::books()->count());
    }

    public function test_a_web_novel_of_the_same_title_is_not_a_duplicate_of_a_book(): void
    {
        Novel::create(['name' => 'The Quiet Orchard', 'translator_url' => 'https://novelfull.com/quiet-orchard.html', 'group_id' => 1]);

        $outcome = app(BookLibrary::class)->import(EpubBuilder::epub3(), 'a.epub');

        $this->assertSame(BookLibrary::CREATED, $outcome['result']);
    }

    public function test_a_book_without_a_title_is_named_after_its_file(): void
    {
        $outcome = app(BookLibrary::class)->import(EpubBuilder::epub3(['title' => '', 'author' => null]), 'the_left-hand_of darkness.epub');

        $this->assertSame('The Left Hand Of Darkness', $outcome['novel']->name);
        $this->assertNull($outcome['novel']->author);
    }

    public function test_hard_deleting_a_book_removes_its_file(): void
    {
        $book = app(BookLibrary::class)->import(EpubBuilder::epub3(), 'a.epub')['novel'];
        $path = BookLibrary::path($book);

        $book->delete();
        $this->assertFileExists($path);

        $book->forceDelete();
        $this->assertFileDoesNotExist($path);
    }

    public function test_books_are_never_swept_or_generated(): void
    {
        $book = app(BookLibrary::class)->import(EpubBuilder::epub3(), 'a.epub')['novel'];
        $before = filemtime(BookLibrary::path($book));

        $this->artisan('novel:epub', ['novel' => $book->id])->assertSuccessful();

        clearstatcache();
        $this->assertSame($before, filemtime(BookLibrary::path($book)));
        $this->assertSame(0, Novel::text()->whereKey($book->id)->count());
        $this->assertSame(1, Novel::readable()->whereKey($book->id)->count());
    }

    // ------------------------------------------------------------------
    // Offline and the apps
    // ------------------------------------------------------------------

    public function test_a_book_can_be_saved_for_offline_reading_and_read_by_the_apps(): void
    {
        $book = app(BookLibrary::class)->import(EpubBuilder::epub3(), 'a.epub', lookup: false)['novel'];

        // The PWA's "Download for offline" list holds every chapter of the book.
        $manifest = $this->getJson("/novels/{$book->id}/offline-manifest")->assertOk()->json();
        $this->assertSame(3, $manifest['chapterCount']);
        $this->assertSame(['Part One — 1', 'Two', 'Three'], array_column($manifest['chapters'], 'label'));
        $this->get($manifest['chapters'][1]['url'])->assertOk()->assertSee('Second word');

        // The apps (Android, iOS, KOReader) list it with medium=all and read its chapters as text.
        $listed = collect($this->getJson('/api/v1/novels?medium=all')->assertOk()->json('novels'))->firstWhere('id', $book->id);
        $this->assertSame('book', $listed['medium']);
        $chapterId = $manifest['chapters'][0]['id'];
        $this->getJson("/api/v1/chapters/{$chapterId}")->assertOk()->assertJsonFragment(['id' => $chapterId]);
    }

    // ------------------------------------------------------------------
    // Catalogue lookup
    // ------------------------------------------------------------------

    public function test_lookup_fills_description_genres_and_covers(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [['volumeInfo' => [
                'title' => 'The Quiet Orchard',
                'authors' => ['Ada Wren'],
                'description' => '<p>From <i>Google</i>.</p>',
                'categories' => ['Fiction / Fantasy / General'],
                'publisher' => 'Small Press',
                'publishedDate' => '2019-04-01',
                'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']],
                'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id=x&zoom=1&edge=curl'],
            ]]]]),
            'openlibrary.org/search.json*' => Http::response(['docs' => [
                ['title' => 'Something Else', 'author_name' => ['Nobody'], 'cover_i' => 1],
                ['title' => 'The Quiet Orchard', 'author_name' => ['Ada Wren'], 'cover_i' => 42, 'first_publish_year' => 2018],
            ]]),
        ]);

        $found = app(BookLookup::class)->find(['title' => 'The Quiet Orchard', 'authors' => ['Ada Wren'], 'isbn' => null]);

        $this->assertSame('From Google.', $found['description']);
        $this->assertSame(['Fiction', 'Fantasy'], $found['genres']);
        $this->assertSame(2019, $found['year']);
        $this->assertSame('9780306406157', $found['isbn']);
        $this->assertSame([
            BookLookup::OPEN_LIBRARY_COVERS . '/isbn/9780306406157-L.jpg?default=false',
            BookLookup::OPEN_LIBRARY_COVERS . '/id/42-L.jpg',
            'https://books.google.com/books/content?id=x&zoom=1',
        ], $found['cover_candidates']);
    }

    public function test_lookup_ignores_a_different_book_and_survives_failures(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [['volumeInfo' => ['title' => 'A Different Book', 'description' => 'no']]]]),
            'openlibrary.org/*' => Http::response('down', 503),
        ]);

        $found = app(BookLookup::class)->find(['title' => 'The Quiet Orchard', 'authors' => ['Ada Wren'], 'isbn' => null]);

        $this->assertNull($found['description']);
        $this->assertSame([], $found['cover_candidates']);
    }

    public function test_lookup_can_be_switched_off(): void
    {
        config(['novarr.books.lookup' => false]);

        $found = app(BookLookup::class)->find(['title' => 'The Quiet Orchard', 'authors' => [], 'isbn' => '9780306406157']);

        $this->assertNull($found['description']);
        Http::assertNothingSent();
    }

    public function test_book_metadata_keeps_its_own_description_and_adds_catalogue_genres(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [['volumeInfo' => ['title' => 'The Quiet Orchard', 'description' => 'Catalogue text', 'categories' => ['Fiction / Literary']]]]]),
            'openlibrary.org/*' => Http::response(['docs' => []]),
        ]);
        $book = app(BookLibrary::class)->import(EpubBuilder::epub3(), 'a.epub')['novel'];

        $metadata = SourceResolver::for($book)->metadata($book);

        $this->assertSame("A quiet story.\n\nSecond line.", $metadata['description']);
        $this->assertSame('Ada Wren', $metadata['author']);
        $this->assertSame(['Fantasy', 'Coming Of Age', 'Fiction', 'Literary'], $metadata['genres']);
        $this->assertSame(3, $metadata['no_of_chapters']);
    }
}
