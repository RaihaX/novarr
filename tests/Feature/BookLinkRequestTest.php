<?php

namespace Tests\Feature;

use App\Books\BookLinkFailed;
use App\Books\BookLinkReader;
use App\Downloads\DownloadSearch;
use App\Novel;
use App\NovelRequest;
use App\Scraping\Fetcher;
use App\Services\Requests\RequestSearch;
use App\User;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeFetcher;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Requesting a book from a pasted link (App\Books\BookLinkReader): the
 * Requests page's book search reads a Goodreads / Amazon / Hardcover /
 * Google Books / Open Library page for ONE result; any other host is
 * refused without a fetch.
 */
class BookLinkRequestTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const GOODREADS = 'https://www.goodreads.com/book/show/248244611-blightfall';

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([\App\Jobs\RunNovelCommand::class]);
        Mail::fake();
        $this->enableSignIn();
        $this->reader = $this->makeReader();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/book-links/' . $name));
    }

    private function search(string $q, string $medium = 'book'): \Illuminate\Testing\TestResponse
    {
        return $this->asToken($this->tokenFor($this->reader))->postJson('/api/v1/requests/searches', ['q' => $q, 'medium' => $medium]);
    }

    /** A finished search's record, as the reader polls it. */
    private function searchDone(string $q): array
    {
        $id = $this->search($q)->assertStatus(202)->json('search.id');

        return $this->asToken($this->tokenFor($this->reader))->getJson("/api/v1/requests/searches/{$id}")->assertOk()->json('search');
    }

    // ---- Reading pages ------------------------------------------------------------

    public function test_a_goodreads_page_gives_title_series_authors_isbn_and_a_full_size_cover(): void
    {
        Http::fake(['www.goodreads.com/*' => Http::response(self::fixture('goodreads-blightfall.html'))]);

        $book = app(BookLinkReader::class)->read(self::GOODREADS . '?from_search=true');

        $this->assertSame('Blightfall', $book['title'], 'the " (Riftwake, #1)" suffix is the series');
        $this->assertSame(['Brandon Sanderson', 'Janci Patterson'], $book['authors']);
        $this->assertSame('Riftwake', $book['series']);
        $this->assertSame('1', $book['series_index']);
        $this->assertSame('9780593707050', $book['isbn']);
        $this->assertSame('0593707052', $book['isbn10']);
        $this->assertSame(2026, $book['year']);
        $this->assertSame('en', $book['language']);
        $this->assertSame('https://m.media-amazon.com/images/S/compressed.photo.goodreads.com/books/1782831365i/248244611.jpg', $book['cover_url'], 'no ._SX318_ size token');
        $this->assertStringStartsWith('From #1 New York Times bestselling author', $book['description']);
        $this->assertStringContainsString('After decades of war', $book['description'], "the page data's full description, not og:description's cut");
        $this->assertSame('https://www.goodreads.com/book/show/248244611', $book['url']);
        $this->assertSame('Goodreads', $book['site_name']);

        // Fetched once, at the canonical URL, as a browser; then cached for a day.
        app(BookLinkReader::class)->read('https://m.goodreads.com/book/show/248244611');
        Http::assertSentCount(1);
        Http::assertSent(fn(HttpRequest $r) => $r->url() === 'https://www.goodreads.com/book/show/248244611'
            && str_contains($r->header('User-Agent')[0] ?? '', 'Mozilla/5.0'));
    }

    public function test_opengraph_alone_is_enough_and_goodreads_thumbnails_are_upscaled(): void
    {
        Http::fake(['www.goodreads.com/*' => Http::response(self::fixture('goodreads-scion-og-only.html'))]);

        $book = app(BookLinkReader::class)->read('https://www.goodreads.com/book/show/247756858-scion');

        $this->assertSame('Scion', $book['title']);
        $this->assertSame('Scion', $book['series']);
        $this->assertSame('1', $book['series_index']);
        $this->assertSame([], $book['authors'], 'books:author is a link, not a name');
        $this->assertSame('9781668239247', $book['isbn'], 'the ISBN-10 completed to 13');
        $this->assertSame('https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/1788797128i/247756858.jpg', $book['cover_url']);
        $this->assertSame('John Wick meets Blade Runner in this fast-paced cyberpunk thriller.', $book['description']);
    }

    public function test_an_amazon_page_gives_its_byline_series_widget_and_detail_bullets(): void
    {
        Http::fake(['www.amazon.com/*' => Http::response(self::fixture('amazon-blightfall.html'))]);

        $book = app(BookLinkReader::class)->read('https://www.amazon.com/Blightfall-Riftwake-Brandon-Sanderson/dp/0593707052/ref=sr_1_1?crid=X');

        $this->assertSame('Blightfall', $book['title'], 'the "(Riftwake)" suffix only repeats the series');
        $this->assertSame(['Brandon Sanderson', 'Janci Patterson'], $book['authors'], 'the narrator is not an author');
        $this->assertSame('Riftwake', $book['series']);
        $this->assertSame('1', $book['series_index']);
        $this->assertSame('9780593707050', $book['isbn']);
        $this->assertSame('0593707052', $book['isbn10']);
        $this->assertSame(2026, $book['year']);
        $this->assertSame('en', $book['language']);
        $this->assertSame('https://m.media-amazon.com/images/I/81zauj7NMhL.jpg', $book['cover_url']);
        $this->assertStringContainsString('the first book of a new trilogy', $book['description']);
        $this->assertSame('https://www.amazon.com/dp/0593707052', $book['url']);
        Http::assertSent(fn(HttpRequest $r) => $r->url() === 'https://www.amazon.com/dp/0593707052');
    }

    public function test_amazon_refusing_falls_back_to_the_isbn_on_open_library(): void
    {
        Http::fake([
            'www.amazon.co.uk/*' => Http::response(self::fixture('amazon-captcha.html')),
            'openlibrary.org/search.json*' => Http::response(['docs' => [[
                'key' => '/works/OL1W', 'title' => 'Blightfall', 'author_name' => ['Brandon Sanderson', 'Janci Patterson'],
                'first_publish_year' => 2026, 'cover_i' => 123, 'language' => ['eng'],
            ]]]),
        ]);

        $book = app(BookLinkReader::class)->read('https://www.amazon.co.uk/gp/product/0593707052');

        $this->assertSame('Blightfall', $book['title']);
        $this->assertSame(['Brandon Sanderson', 'Janci Patterson'], $book['authors']);
        $this->assertSame('9780593707050', $book['isbn'], 'the ASIN of a book is its ISBN-10');
        $this->assertSame('https://covers.openlibrary.org/b/id/123-L.jpg', $book['cover_url']);
        $this->assertSame('https://www.amazon.co.uk/dp/0593707052', $book['url']);
        Http::assertSent(fn(HttpRequest $r) => str_starts_with($r->url(), 'https://openlibrary.org/search.json')
            && ($r->data()['q'] ?? null) === 'isbn:9780593707050');
    }

    public function test_amazon_refusing_with_nothing_found_says_to_paste_another_link(): void
    {
        Http::fake([
            'www.amazon.com/*' => Http::response('Service Unavailable', 503),
            'openlibrary.org/*' => Http::response(['docs' => []]),
            'www.googleapis.com/*' => Http::response(['totalItems' => 0]),
        ]);

        $search = $this->searchDone('https://www.amazon.com/dp/0593707052');

        $this->assertSame('failed', $search['status']);
        $this->assertSame(BookLinkReader::AMAZON_REFUSED, $search['message']);
        $this->assertSame('Amazon refused; paste the Goodreads or Hardcover link instead.', $search['message']);
    }

    public function test_a_kindle_asin_is_no_isbn_so_a_refusal_is_not_looked_up(): void
    {
        Http::fake(['www.amazon.com/*' => Http::response(self::fixture('amazon-captcha.html'))]);

        try {
            app(BookLinkReader::class)->read('https://www.amazon.com/dp/B0GRVC6298');
            $this->fail('refused');
        } catch (BookLinkFailed $e) {
            $this->assertSame(BookLinkReader::AMAZON_REFUSED, $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_audible_needs_a_book_on_the_page_not_its_home_page(): void
    {
        // An unknown ASIN, or a visitor from another country, lands on a home page that calls itself og:type "book".
        Http::fake(['www.audible.com/*' => Http::response('<html><head><meta property="og:type" content="book" />'
            . '<meta property="og:title" content="Audible | Listen to Audiobooks, Podcasts &amp; Originals" /></head><body></body></html>')]);

        try {
            app(BookLinkReader::class)->read('https://www.audible.com/pd/The-Way-of-Kings-Audiobook/B003P2WO5E');
            $this->fail('refused');
        } catch (BookLinkFailed $e) {
            $this->assertSame(BookLinkReader::AUDIBLE_REFUSED, $e->getMessage());
        }

        Http::fake(['www.audible.co.uk/*' => Http::response('<html><head><script type="application/ld+json">[{"@context":"https://schema.org","@type":"Audiobook",'
            . '"name":"The Way of Kings","author":[{"@type":"Person","name":"Brandon Sanderson"}],"readBy":[{"name":"Michael Kramer"}],"datePublished":"2010-08-31","inLanguage":"english"}]</script></head>'
            . '<body><ul><li class="bc-list-item seriesLabel">Series: <a href="/series/x">The Stormlight Archive</a>, Book 1</li></ul></body></html>')]);
        $book = app(BookLinkReader::class)->read('https://www.audible.co.uk/pd/B003P2WO5E');
        $this->assertSame('The Way of Kings', $book['title']);
        $this->assertSame(['Brandon Sanderson'], $book['authors']);
        $this->assertSame('The Stormlight Archive', $book['series']);
        $this->assertSame('1', $book['series_index']);
        $this->assertSame('en', $book['language']);
    }

    public function test_an_open_library_edition_has_its_own_isbn_and_the_works_authors(): void
    {
        Http::fake([
            'openlibrary.org/books/OL7353617M.json' => Http::response(['title' => 'Fantastic Mr. Fox', 'isbn_10' => ['0140328726'], 'isbn_13' => ['9780140328721'],
                'publish_date' => 'October 1, 1988', 'covers' => [8739161], 'languages' => [['key' => '/languages/eng']], 'works' => [['key' => '/works/OL45804W']],
                'series' => ['Puffin Books ; 2']]),
            'openlibrary.org/search.json*' => Http::response(['docs' => [['author_name' => ['Roald Dahl'], 'first_publish_year' => 1970]]]),
        ]);

        $book = app(BookLinkReader::class)->read('https://openlibrary.org/books/OL7353617M/Fantastic_Mr._Fox');

        $this->assertSame('Fantastic Mr. Fox', $book['title']);
        $this->assertSame(['Roald Dahl'], $book['authors']);
        $this->assertSame('9780140328721', $book['isbn']);
        $this->assertSame(1988, $book['year'], "the edition's own date");
        $this->assertSame('en', $book['language']);
        $this->assertSame(['Puffin Books', '2'], [$book['series'], $book['series_index']]);
        Http::assertSent(fn(HttpRequest $r) => str_starts_with($r->url(), 'https://openlibrary.org/search.json') && ($r->data()['q'] ?? null) === 'key:/works/OL45804W');
    }

    public function test_hardcover_behind_cloudflare_is_read_through_flaresolverr(): void
    {
        config(['novarr.flaresolverr_url' => 'http://flaresolverr.test/v1', 'novarr.clearance_minutes' => 0]);
        Http::fake(['hardcover.app/*' => Http::response('<html><head><title>Just a moment...</title></head></html>', 403)]);
        $page = '<html><head><meta property="og:title" content="Blightfall by Brandon Sanderson | Hardcover">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Book","name":"Blightfall (Riftwake #1)",'
            . '"author":{"@type":"Person","name":"Brandon Sanderson"},"isbn":"978-0-593-70705-0","datePublished":"2026-09-01"}</script></head><body></body></html>';
        app()->instance(Fetcher::class, (new FakeFetcher())->route('https://hardcover.app/books/blightfall', $page));

        $book = app(BookLinkReader::class)->read('https://hardcover.app/books/blightfall/editions');

        $this->assertSame('Blightfall', $book['title']);
        $this->assertSame(['Brandon Sanderson'], $book['authors']);
        $this->assertSame('Riftwake', $book['series']);
        $this->assertSame('1', $book['series_index']);
        $this->assertSame('9780593707050', $book['isbn']);
        $this->assertSame(2026, $book['year']);
    }

    public function test_google_books_and_open_library_links_are_read_through_their_apis(): void
    {
        Http::fake([
            'www.googleapis.com/books/v1/volumes/abcDEF12345*' => Http::response(['id' => 'abcDEF12345', 'volumeInfo' => [
                'title' => 'Scion (Scion, #1)', 'authors' => ['James Islington'], 'publishedDate' => '2026-06-02', 'language' => 'en',
                'industryIdentifiers' => [['type' => 'ISBN_10', 'identifier' => '1668239248'], ['type' => 'ISBN_13', 'identifier' => '9781668239247']],
                'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id=abcDEF12345&zoom=1'],
            ]]),
            'openlibrary.org/works/OL27448W.json' => Http::response(['title' => 'The Lord of the Rings', 'covers' => [14625765], 'description' => ['value' => 'One ring.']]),
            'openlibrary.org/search.json*' => Http::response(['docs' => [['author_name' => ['J.R.R. Tolkien'], 'first_publish_year' => 1954, 'isbn' => ['9780618260584']]]]),
        ]);

        $google = app(BookLinkReader::class)->read('https://books.google.com.au/books?id=abcDEF12345&printsec=frontcover');
        $this->assertSame('Scion', $google['title']);
        $this->assertSame('Scion', $google['series']);
        $this->assertSame(['James Islington'], $google['authors']);
        $this->assertSame('9781668239247', $google['isbn']);
        $this->assertSame('https://books.google.com/books/content?id=abcDEF12345&zoom=1', $google['cover_url']);
        $this->assertSame('https://books.google.com/books?id=abcDEF12345', $google['url']);

        $work = app(BookLinkReader::class)->read('https://openlibrary.org/works/OL27448W/The_Lord_of_the_Rings');
        $this->assertSame('The Lord of the Rings', $work['title']);
        $this->assertSame(['J.R.R. Tolkien'], $work['authors']);
        $this->assertSame('One ring.', $work['description']);
        $this->assertSame(1954, $work['year']);
        $this->assertSame('9780618260584', $work['isbn']);
        $this->assertSame('https://covers.openlibrary.org/b/id/14625765-L.jpg', $work['cover_url']);
    }

    public function test_links_are_parsed_into_canonical_book_pages_on_allowed_hosts_only(): void
    {
        $canonical = fn(string $url) => BookLinkReader::canonical($url);

        $this->assertSame('https://www.goodreads.com/book/show/247756858', $canonical('https://www.goodreads.com/book/show/247756858-scion'));
        $this->assertSame('https://www.goodreads.com/book/show/247756858', $canonical('goodreads.com/book/show/247756858.Scion'));
        $this->assertSame('https://www.amazon.com.au/dp/0593707052', $canonical('https://www.amazon.com.au/gp/product/0593707052?psc=1'));
        $this->assertSame('https://www.amazon.de/dp/B0GRVC6298', $canonical('http://amazon.de/Blightfall/dp/b0grvc6298/'));
        $this->assertSame('https://www.audible.com/pd/B0CF4DJ1HZ', $canonical('https://www.audible.com/pd/Blightfall-Audiobook/B0CF4DJ1HZ?qid=1'));
        $this->assertSame('https://hardcover.app/books/blightfall', $canonical('https://hardcover.app/books/blightfall'));
        $this->assertSame('https://books.google.com/books?id=abcDEF12345', $canonical('https://books.google.co.uk/books/edition/Scion/abcDEF12345?hl=en'));
        $this->assertSame('https://openlibrary.org/books/OL7353617M', $canonical('https://openlibrary.org/books/OL7353617M/Fantastic_Mr._Fox'));

        // Never fetched: other hosts, look-alikes, internal addresses, other schemes, credentials, ports.
        foreach ([
            'https://evil.example/book/show/1',
            'https://www.goodreads.com.evil.example/book/show/1',
            'https://evilgoodreads.com/book/show/1',
            'http://169.254.169.254/latest/meta-data',
            'http://localhost/book/show/1',
            'ftp://www.goodreads.com/book/show/1',
            'file:///etc/passwd',
            'https://user:pass@www.goodreads.com/book/show/1',
            'https://www.goodreads.com:8443/book/show/1',
            'https://www.goodreads.com/series/432367-riftwake',
            'https://www.amazon.com/s?k=blightfall',
            'javascript:alert(1)',
            'Blightfall Sanderson',
        ] as $url) {
            $this->assertNull($canonical($url), $url);
        }
        $this->assertSame(BookLinkReader::OTHER_HOST, BookLinkReader::refusal('https://evil.example/x'));
        $this->assertStringContainsString('not a book', BookLinkReader::refusal('https://www.goodreads.com/series/432367-riftwake'));
    }

    public function test_a_redirect_may_not_leave_the_allowed_hosts(): void
    {
        $onRedirect = BookLinkReader::redirectRules()['on_redirect'];

        $onRedirect(null, null, new Uri('https://www.goodreads.com/book/show/1-x')); // fine
        $onRedirect(null, null, new Uri('https://www.googleapis.com/books/v1/volumes/x'));
        $this->expectException(BookLinkFailed::class);
        $onRedirect(null, null, new Uri('http://169.254.169.254/latest'));
    }

    // ---- The Requests search ---------------------------------------------------------

    public function test_a_pasted_goodreads_link_is_one_requestable_result_with_its_series(): void
    {
        Http::fake(['www.goodreads.com/*' => Http::response(self::fixture('goodreads-blightfall.html'))]);

        $search = $this->searchDone(self::GOODREADS);

        $this->assertSame('done', $search['status']);
        $this->assertCount(1, $search['results']);
        $result = $search['results'][0];
        $this->assertSame('Blightfall', $result['title']);
        $this->assertSame('Brandon Sanderson, Janci Patterson', $result['author']);
        $this->assertSame('Riftwake', $result['series']);
        $this->assertSame('1', $result['series_index']);
        $this->assertSame('book', $result['medium']);
        $this->assertSame([['source' => 'book-link', 'source_name' => 'Goodreads', 'chapters' => null, 'status' => '2026']], $result['sources']);
        $this->assertSame('https://m.media-amazon.com/images/S/compressed.photo.goodreads.com/books/1782831365i/248244611.jpg', $result['cover_url']);
        $this->assertFalse($result['in_library']);
        $this->assertNull($result['requested']);
        // The same key as the catalogue's hit for this book (title + author): Me too works across both.
        $this->assertSame(RequestSearch::groupKey('book', 'blightfall|brandon sanderson janci patterson'), $result['key']);
        Http::assertSentCount(1);

        // Remembered under the link's own key, apart from a text search for its words.
        $this->assertNotSame(RequestSearch::cacheKey('book', self::GOODREADS), RequestSearch::cacheKey('book', 'blightfall'));
        $this->assertSame(RequestSearch::cacheKey('book', self::GOODREADS), RequestSearch::cacheKey('book', 'https://www.goodreads.com/book/show/248244611'));
        $this->assertNotNull(Cache::get(RequestSearch::cacheKey('book', self::GOODREADS)));
    }

    public function test_a_request_made_from_the_link_is_a_wanted_book_carrying_series_and_isbn(): void
    {
        Http::fake(['www.goodreads.com/*' => Http::response(self::fixture('goodreads-blightfall.html'))]);
        $search = $this->searchDone(self::GOODREADS);

        $this->asToken($this->tokenFor($this->reader))->postJson('/api/v1/requests', [
            'search_id' => $search['id'], 'key' => $search['results'][0]['key'],
        ])->assertCreated();

        $request = NovelRequest::sole();
        $this->assertSame(Novel::MEDIUM_BOOK, $request->medium);
        $this->assertTrue($request->isWanted());
        $this->assertSame('Blightfall', $request->title);
        $this->assertSame('Brandon Sanderson, Janci Patterson', $request->author);
        $candidate = $request->candidateList()[0];
        $this->assertSame([
            'source' => 'book-link',
            'source_name' => 'Goodreads',
            'url' => 'https://www.goodreads.com/book/show/248244611',
            'title' => 'Blightfall',
            'cover_url' => 'https://m.media-amazon.com/images/S/compressed.photo.goodreads.com/books/1782831365i/248244611.jpg',
            'chapters' => null,
            'status' => '2026',
            'isbn' => '9780593707050',
            'isbn10' => '0593707052',
            'series' => 'Riftwake',
            'series_index' => '1',
            'language' => 'en',
            'year' => 2026,
        ], $candidate);

        // The downloads rank releases against book 1 of the series, in English.
        $wanted = DownloadSearch::wanted($request, null);
        $this->assertSame('1', $wanted['series_index']);
        $this->assertSame('9780593707050', $wanted['isbn']);
        $this->assertSame('en', $wanted['language']);

        // A second reader pasting the Amazon link of the same book joins it (Me too).
        Http::fake(['www.amazon.com/*' => Http::response(self::fixture('amazon-blightfall.html'))]);
        $other = $this->makeReader('robin');
        $token = $this->tokenFor($other);
        $id = $this->asToken($token)->postJson('/api/v1/requests/searches', ['q' => 'https://www.amazon.com/dp/0593707052', 'medium' => 'book'])->assertStatus(202)->json('search.id');
        $theirs = $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->assertOk()->json('search.results.0');
        $this->assertSame(['status' => 'pending', 'by_me' => false], $theirs['requested']);
    }

    public function test_links_of_other_hosts_or_for_other_media_are_refused_without_a_fetch(): void
    {
        Http::fake();

        $this->search('https://evil.example/book/123')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paste a link from Goodreads, Amazon, Hardcover, Google Books or Open Library.')
            ->assertJsonValidationErrors('q');
        $this->search('http://169.254.169.254/latest/meta-data/')->assertStatus(422);
        $this->search('https://www.goodreads.com/series/432367-riftwake')->assertStatus(422)
            ->assertJsonPath('message', fn($m) => str_contains($m, 'not a book'));
        $this->search(self::GOODREADS, 'manga')->assertStatus(422);
        $this->search(self::GOODREADS, 'novel')->assertStatus(422);

        Http::assertNothingSent();
        $this->assertNull(Cache::get(RequestSearch::userSlotKey((int) $this->reader->id)), 'no search started');

        // A long text that is not a link is still limited to 100 characters.
        $this->search(str_repeat('x', 101))->assertStatus(422)->assertJsonValidationErrors('q');
        // A long book link is fine.
        Http::fake(['www.amazon.com/*' => Http::response(self::fixture('amazon-blightfall.html'))]);
        $this->search('https://www.amazon.com/Blightfall-Riftwake-Brandon-Sanderson/dp/0593707052/ref=sr_1_1?crid=' . str_repeat('A', 200))
            ->assertStatus(202);
    }

    public function test_an_isbn_typed_in_the_book_search_is_looked_up_as_one(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [['id' => 'g1', 'volumeInfo' => [
                'title' => 'The Quiet Orchard', 'authors' => ['Ada Wren'],
                'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']],
                'canonicalVolumeLink' => 'https://books.google.com/books/about/x.html?id=g1',
            ]]]]),
            'openlibrary.org/*' => Http::response(['docs' => []]),
        ]);

        $search = $this->searchDone('978-0-306-40615-7');

        $this->assertSame('done', $search['status']);
        $this->assertSame('The Quiet Orchard', $search['results'][0]['title']);
        $this->assertArrayNotHasKey('series', $search['results'][0], 'a catalogue result keeps its shape');
        Http::assertSent(fn(HttpRequest $r) => str_contains($r->url(), 'googleapis.com') && str_contains(urldecode($r->url()), 'isbn:9780306406157'));
    }

    public function test_the_requests_page_offers_pasting_a_link_for_books(): void
    {
        $this->asSignedIn($this->reader)->get('/requests')
            ->assertOk()
            ->assertSee('Title, author, ISBN — or paste a Goodreads / Amazon link', false)
            ->assertSee('id="reqSearchHint"', false)
            ->assertSee('req-series', false);
    }

    public function test_pure_helpers(): void
    {
        $this->assertSame(['Blightfall', 'Riftwake', '1'], BookLinkReader::splitSeries('Blightfall (Riftwake, #1)'));
        $this->assertSame(['Oathbringer', 'The Stormlight Archive', '3'], BookLinkReader::splitSeries('Oathbringer (The Stormlight Archive, #3)'));
        $this->assertSame(['Edgedancer', 'The Stormlight Archive', '2.5'], BookLinkReader::splitSeries('Edgedancer (The Stormlight Archive #2.5)'));
        $this->assertSame(['The Way of Kings', 'The Stormlight Archive', '1'], BookLinkReader::splitSeries('The Way of Kings (The Stormlight Archive Book 1)'));
        $this->assertSame(['Dune', null, null], BookLinkReader::splitSeries('Dune'));
        $this->assertSame(['Collected (Books 1-3)', null, null], BookLinkReader::splitSeries('Collected (Books 1-3)'));

        $this->assertSame('https://m.media-amazon.com/images/I/81zauj7NMhL.jpg', BookLinkReader::upscaleCover('https://m.media-amazon.com/images/I/81zauj7NMhL._AC_SX331_BO1,204,203,200_.jpg'));
        $this->assertSame('https://images-na.ssl-images-amazon.com/images/I/51x.jpg', BookLinkReader::upscaleCover('https://images-na.ssl-images-amazon.com/images/I/51x._SY475_.jpg'));
        $this->assertSame('https://example.test/a._SX98_.jpg', BookLinkReader::upscaleCover('https://example.test/a._SX98_.jpg'), 'other hosts as they are');
        $this->assertNull(BookLinkReader::upscaleCover('javascript:alert(1)'));

        $this->assertSame('9780306406157', BookLinkReader::isbn13('0-306-40615-2'));
        $this->assertSame('0306406152', BookLinkReader::isbn10('978-0-306-40615-7'));
        $this->assertNull(BookLinkReader::isbn13('0306406153'), 'a wrong check digit');
        $this->assertNull(BookLinkReader::isbn13('B0GRVC6298'));
        $this->assertSame('2.5', BookLinkReader::seriesIndex('2.50'));
        $this->assertNull(BookLinkReader::seriesIndex('1-3'));
        $this->assertTrue(BookLinkReader::looksLikeUrl('www.goodreads.com/book/show/1'));
        $this->assertTrue(BookLinkReader::looksLikeUrl('goodreads.com/book/show/1'));
        $this->assertTrue(BookLinkReader::looksLikeUrl('file:///etc/passwd'));
        $this->assertFalse(BookLinkReader::looksLikeUrl('Dr.Stone/Reboot'), 'a title, not a link');
        $this->assertFalse(BookLinkReader::looksLikeUrl('Mr. Fox'));
        $this->assertFalse(BookLinkReader::looksLikeUrl('978-0-306-40615-7'));
    }
}
