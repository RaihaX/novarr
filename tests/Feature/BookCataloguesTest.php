<?php

namespace Tests\Feature;

use App\Books\AppleBooks;
use App\Books\BookLookup;
use App\Books\BookSeries;
use App\Books\Hardcover;
use App\Novel;
use App\Services\Requests\RequestBrowse;
use App\Sources\BookCatalogueSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The newer book catalogues beside Google Books and Open Library:
 * Hardcover (App\Books\Hardcover: GraphQL, a token, series, ratings, the
 * Discover shelves) and Apple Books (App\Books\AppleBooks: 1200 px
 * covers), merged into BookLookup::candidates(); every source switchable
 * and failure-tolerant. No request leaves the test (Http::fake).
 */
class BookCataloguesTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\UsesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
        config([
            'novarr.books.sources' => ['google', 'openlibrary', 'hardcover', 'itunes'],
            'novarr.books.hardcover_token' => 'Bearer test-token',
            'novarr.books.hardcover_interval_ms' => 0,
        ]);
    }

    // --- Fixtures --------------------------------------------------------------

    private const DUNE_ISBN = '9780441013593';

    private static function hardcoverDoc(array $overrides = []): array
    {
        return array_merge([
            'id' => 312460,
            'title' => 'Dune',
            'subtitle' => null,
            'author_names' => ['Frank Herbert'],
            'release_year' => 1965,
            'description' => '<p>Set on the desert planet Arrakis.</p>',
            'image' => ['url' => 'https://assets.hardcover.app/edition/dune.jpg'],
            'isbns' => [self::DUNE_ISBN, '0441013597'],
            'featured_series' => ['position' => 1, 'series' => ['name' => 'Dune', 'books_count' => 6]],
            'genres' => ['Science Fiction', 'Classics'],
            'rating' => 4.271,
            'ratings_count' => 9000,
            'users_count' => 52000,
            'slug' => 'dune',
        ], $overrides);
    }

    private static function hardcoverSearch(array $docs): array
    {
        return ['data' => ['search' => ['results' => ['found' => count($docs), 'hits' => array_map(fn($d) => ['document' => $d], $docs)]]]];
    }

    private static function itunesResult(array $overrides = []): array
    {
        return array_merge([
            'trackId' => 953141184,
            'trackName' => 'Dune',
            'artistName' => 'Frank Herbert',
            'description' => 'Apple text.',
            'releaseDate' => '2005-08-02T07:00:00Z',
            'genres' => ['Sci-Fi & Fantasy', 'Books'],
            'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/Publication/v4/aa/dune.jpg/100x100bb.jpg',
            'trackViewUrl' => 'https://books.apple.com/us/book/dune/id953141184',
        ], $overrides);
    }

    private function fakeAll(array $hardcover = [], array $itunes = []): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [['id' => 'g1', 'volumeInfo' => [
                'title' => 'Dune', 'authors' => ['Frank Herbert'], 'description' => 'Google text.',
                'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => self::DUNE_ISBN]],
                'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id=g1&printsec=frontcover&img=1&zoom=1&edge=curl'],
            ]]]]),
            'openlibrary.org/*' => Http::response(['docs' => [['key' => '/works/OL1W', 'title' => 'Dune', 'author_name' => ['Frank Herbert'], 'cover_i' => 42, 'isbn' => [self::DUNE_ISBN]]]]),
            'api.hardcover.app/*' => Http::response(self::hardcoverSearch($hardcover ?: [self::hardcoverDoc()])),
            'itunes.apple.com/*' => Http::response(['resultCount' => 1, 'results' => $itunes ?: [self::itunesResult()]]),
        ]);
    }

    private function book(string $name, ?string $author, array $extra = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name, 'author' => $author, 'status' => 1, 'group_id' => 1,
            'medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1($name . $author),
        ], $extra));
    }

    // --- Hardcover -------------------------------------------------------------

    public function test_the_token_is_sent_with_one_bearer_prefix_however_it_was_pasted(): void
    {
        foreach (['Bearer abc123', 'bearer   abc123', 'abc123', '  abc123  '] as $pasted) {
            config(['novarr.books.hardcover_token' => $pasted]);
            $this->assertSame('abc123', Hardcover::token());
            $this->assertSame('Bearer abc123', Hardcover::authorization());
        }

        Http::fake(['api.hardcover.app/*' => Http::response(self::hardcoverSearch([self::hardcoverDoc()]))]);
        config(['novarr.books.hardcover_token' => 'Bearer abc123']);
        app(Hardcover::class)->searchBooks('Dune Frank Herbert');
        Http::assertSent(fn(HttpRequest $r) => $r->url() === Hardcover::ENDPOINT
            && $r->method() === 'POST'
            && $r->header('authorization') === ['Bearer abc123']
            && str_contains($r['query'], 'search(query: $q, query_type: "Book"')
            && $r['variables']['q'] === 'Dune Frank Herbert');
    }

    public function test_without_a_token_hardcover_is_off_and_asks_nothing(): void
    {
        config(['novarr.books.hardcover_token' => '']);
        Http::fake();
        $this->assertFalse(Hardcover::enabled());
        $this->assertNotContains('hardcover', BookLookup::sources());
        $this->assertNull(app(Hardcover::class)->searchBooks('Dune'));
        $this->assertSame([], app(Hardcover::class)->candidates('Dune', 'Frank Herbert', null));
        $this->assertNull(app(Hardcover::class)->seriesFor('Dune', 'Frank Herbert'));
        $this->assertNotContains('hardcover_trending', (new BookCatalogueSource())->discoverLists());
        Http::assertNothingSent();
    }

    public function test_a_hardcover_search_document_becomes_a_candidate(): void
    {
        $c = Hardcover::candidate(self::hardcoverDoc(['subtitle' => 'Deluxe Edition']), null);

        $this->assertSame('hardcover', $c['source']);
        $this->assertSame('Hardcover', $c['source_name']);
        $this->assertSame('hardcover:312460', $c['key']);
        $this->assertSame('Dune: Deluxe Edition', $c['title']);
        $this->assertSame(['Frank Herbert'], $c['authors']);
        $this->assertSame('Set on the desert planet Arrakis.', $c['description']);
        $this->assertSame(1965, $c['year']);
        $this->assertSame(self::DUNE_ISBN, $c['isbn']);
        $this->assertSame([self::DUNE_ISBN], $c['isbns'], 'ISBN-13s only');
        $this->assertSame('https://assets.hardcover.app/edition/dune.jpg', $c['cover_url']);
        $this->assertSame('https://hardcover.app/books/dune', $c['url']);
        $this->assertSame('Dune', $c['series']);
        $this->assertSame(1.0, $c['series_index']);
        $this->assertSame(4.27, $c['rating']);
        $this->assertSame(52000, $c['users_count']);
        $this->assertContains('Science Fiction', $c['genres']);

        // The other shapes: contributions instead of author_names, series_names, a JSON-string results blob.
        $other = Hardcover::candidate(['title' => 'Children of Dune', 'contributions' => [['author' => ['name' => 'Frank Herbert']], ['author' => ['name' => 'Narrator Person'], 'contribution' => 'Narrator']],
            'series_names' => ['Dune'], 'featured_series_position' => 3, 'slug' => 'children-of-dune']);
        $this->assertSame(['Frank Herbert'], $other['authors']);
        $this->assertSame(['Dune', 3.0], [$other['series'], $other['series_index']]);

        Http::fake(['api.hardcover.app/*' => Http::response(['data' => ['search' => ['results' => json_encode(['hits' => [['document' => self::hardcoverDoc()]]])]]])]);
        $this->assertSame('Dune', app(Hardcover::class)->searchBooks('dune')[0]['title']);
    }

    public function test_hardcover_errors_are_empty_answers(): void
    {
        foreach ([Http::response(['error' => 'invalid_token'], 401), Http::response(['error' => 'Too Many Requests'], 429),
            Http::response(['errors' => [['message' => 'field "search" not found']]], 200)] as $answer) {
            Cache::flush();
            Http::fake(['api.hardcover.app/*' => $answer]);
            $this->assertNull(app(Hardcover::class)->searchBooks('Dune'));
            $this->assertNull(app(Hardcover::class)->seriesFor('Dune', 'Frank Herbert'), 'a failure is "ask again", not "no series"');
        }
    }

    public function test_hardcover_answers_are_cached(): void
    {
        Http::fake(['api.hardcover.app/*' => Http::response(self::hardcoverSearch([self::hardcoverDoc()]))]);
        app(Hardcover::class)->searchBooks('Dune');
        app(Hardcover::class)->searchBooks('Dune');
        Http::assertSentCount(1);
    }

    // --- Series from Hardcover -------------------------------------------------

    public function test_books_without_a_series_get_hardcovers_and_stronger_sources_are_never_touched(): void
    {
        Http::fake(function (HttpRequest $r) {
            $q = (string) ($r['variables']['q'] ?? '');

            return Http::response(self::hardcoverSearch(match (true) {
                str_starts_with($q, 'Dune') => [self::hardcoverDoc()],
                str_starts_with($q, 'Lonely Book') => [self::hardcoverDoc(['title' => 'Lonely Book', 'author_names' => ['A. Writer'], 'featured_series' => null, 'series_names' => []])],
                str_starts_with($q, 'Wrong Author') => [self::hardcoverDoc(['title' => 'Wrong Author', 'author_names' => ['Somebody Else']])],
                default => [self::hardcoverDoc(['title' => 'Something', 'featured_series' => ['position' => 2, 'series' => ['name' => 'Some Saga']]])],
            }));
        });
        $plain = $this->book('Dune', 'Frank Herbert');
        $guessed = $this->book('Dune Messiah', 'Frank Herbert', ['series' => 'Guess', 'series_index' => 9, 'series_source' => BookSeries::INFERRED]);
        $lonely = $this->book('Lonely Book', 'A. Writer');
        $wrong = $this->book('Wrong Author', 'Frank Herbert');
        $epub = $this->book('Something', 'X', ['series' => 'Mine', 'series_source' => BookSeries::EPUB]);
        $calibre = $this->book('Something', 'Y', ['series' => 'Cal', 'series_source' => BookSeries::CALIBRE]);
        $manual = $this->book('Something', 'Z', ['series' => '', 'series_source' => BookSeries::MANUAL]);

        $counts = BookSeries::backfillFromHardcover(app(Hardcover::class), 150);

        $this->assertSame(['asked' => 4, 'found' => 1, 'updated' => 1, 'failed' => 0], $counts);
        $this->assertSame(['Dune', 1.0, 'hardcover'], [$plain->fresh()->series, (float) $plain->fresh()->series_index, $plain->fresh()->series_source]);
        // "Dune Messiah" searched, Hardcover's first hit is "Dune": not the same title, so the guess stays.
        $this->assertSame(['Guess', BookSeries::INFERRED], [$guessed->fresh()->series, $guessed->fresh()->series_source]);
        $this->assertNull($lonely->fresh()->series);
        $this->assertNull($wrong->fresh()->series, 'another author is another book');
        foreach ([$plain, $guessed, $lonely, $wrong] as $asked) {
            $this->assertNotNull($asked->fresh()->hardcover_checked_at, 'asked once');
        }
        foreach ([$epub, $calibre, $manual] as $kept) {
            $this->assertNull($kept->fresh()->hardcover_checked_at);
        }
        $this->assertSame(['Mine', 'Cal', ''], [$epub->fresh()->series, $calibre->fresh()->series, $manual->fresh()->series]);

        // Asked once: a second run asks nothing.
        $sent = count(Http::recorded());
        $this->assertSame(0, BookSeries::backfillFromHardcover(app(Hardcover::class))['asked']);
        $this->assertCount($sent, Http::recorded());

        // The inference treats Hardcover's series as an anchor and never clears it.
        BookSeries::infer();
        $this->assertSame(['Dune', 'hardcover'], [$plain->fresh()->series, $plain->fresh()->series_source]);
    }

    public function test_a_hardcover_series_replaces_a_guess_and_a_failure_leaves_the_book_for_later(): void
    {
        Http::fake(['api.hardcover.app/*' => Http::sequence()
            ->push('down', 503)
            ->push(self::hardcoverSearch([self::hardcoverDoc(['title' => 'Dune Messiah', 'featured_series' => ['position' => 2, 'series' => ['name' => 'Dune']]])]))]);
        $book = $this->book('Dune Messiah', 'Frank Herbert', ['series' => 'Guess', 'series_index' => 9, 'series_source' => BookSeries::INFERRED]);

        $this->assertSame(1, BookSeries::backfillFromHardcover(app(Hardcover::class))['failed']);
        $this->assertNull($book->fresh()->hardcover_checked_at);

        $this->artisan('books:series --hardcover --limit=5')->expectsOutputToContain('Hardcover: 1 books asked, 1 in a series, 1 series set')->assertSuccessful();
        $this->assertSame(['Dune', 2.0, 'hardcover'], [$book->fresh()->series, (float) $book->fresh()->series_index, $book->fresh()->series_source]);
    }

    public function test_books_series_hardcover_without_a_token_says_so(): void
    {
        config(['novarr.books.hardcover_token' => '']);
        Http::fake();
        $this->artisan('books:series --hardcover')->expectsOutputToContain('Hardcover is not set up')->assertSuccessful();
        Http::assertNothingSent();
    }

    // --- Discover shelves ------------------------------------------------------

    public function test_the_book_shelf_offers_hardcovers_lists_with_a_token(): void
    {
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), 'openlibrary.org')) {
                return Http::response(['works' => []]);
            }
            $query = (string) $r['query'];
            if (str_contains($query, 'books_trending')) {
                return Http::response(['data' => ['books_trending' => ['ids' => [2, 1], 'error' => null]]]);
            }

            return Http::response(['data' => ['books' => [
                ['id' => 1, 'title' => 'Dune', 'slug' => 'dune', 'release_year' => 1965, 'image' => ['url' => 'https://assets.hardcover.app/dune.jpg'], 'contributions' => [['author' => ['name' => 'Frank Herbert']]]],
                ['id' => 2, 'title' => 'Project Hail Mary', 'slug' => 'project-hail-mary', 'release_year' => 2021, 'image' => null, 'contributions' => [['author' => ['name' => 'Andy Weir']]]],
            ]]]);
        });
        $browse = app(RequestBrowse::class);

        $this->assertSame([
            ['id' => 'popular', 'name' => 'Popular this week'],
            ['id' => 'trending', 'name' => 'Trending today'],
            ['id' => 'classics', 'name' => 'Classics'],
            ['id' => 'hardcover_trending', 'name' => 'Trending on Hardcover'],
            ['id' => 'hardcover_popular', 'name' => 'Popular on Hardcover'],
        ], $browse->lists('book'));

        $page = $browse->page('book', 'hardcover_trending', 1);
        // In Hardcover's ranked order (ids 2, 1), not the books query's.
        $this->assertSame(['Project Hail Mary', 'Dune'], array_column($page['groups'], 'title'));
        $this->assertFalse($page['has_more'], 'fewer ids than a page');
        Http::assertSent(fn(HttpRequest $r) => str_contains((string) $r['query'], 'books_trending(duration: week') && $r['variables']['limit'] === 24 && $r['variables']['offset'] === 0);
        Http::assertSent(fn(HttpRequest $r) => str_contains((string) $r['query'], 'books(where: {id: {_in: $ids}})') && $r['variables']['ids'] === [2, 1]);

        $items = (new BookCatalogueSource())->discoverPage('hardcover_popular', null, 2)['items'];
        $this->assertSame('https://hardcover.app/books/dune', $items[1]['url']);
        $this->assertSame('Frank Herbert', $items[1]['author']);
        Http::assertSent(fn(HttpRequest $r) => str_contains((string) $r['query'], 'books_trending(duration: all') && $r['variables']['offset'] === 24);

        // Without a token, the lists are gone.
        config(['novarr.books.hardcover_token' => '']);
        $this->assertNotContains('hardcover_trending', array_column($browse->lists('book'), 'id'));
    }

    public function test_a_hardcover_shelf_that_fails_is_unavailable_not_empty(): void
    {
        Http::fake(['api.hardcover.app/*' => Http::response(['error' => 'Too Many Requests'], 429)]);
        $this->assertNull((new BookCatalogueSource())->discoverPage('hardcover_trending', null, 1));
    }

    // --- Apple Books -----------------------------------------------------------

    public function test_itunes_artwork_is_asked_at_1200_pixels(): void
    {
        $this->assertSame('https://is1-ssl.mzstatic.com/image/thumb/x/9781.jpg/1200x1200bb.jpg', AppleBooks::bigCover('https://is1-ssl.mzstatic.com/image/thumb/x/9781.jpg/100x100bb.jpg'));
        $this->assertSame('https://is1-ssl.mzstatic.com/image/thumb/x/a.jpg/600x600bb.jpg', AppleBooks::bigCover('http://is1-ssl.mzstatic.com/image/thumb/x/a.jpg/60x60bb.jpg', 600));
        $this->assertSame('https://x.test/a/1200x1200bb.png', AppleBooks::bigCover('https://x.test/a/200x300bb.png'));
        $this->assertNull(AppleBooks::bigCover('https://x.test/cover.jpg'));
        $this->assertNull(AppleBooks::bigCover(null));

        $c = AppleBooks::candidate(self::itunesResult());
        $this->assertSame(['itunes', 'Apple Books', 'itunes:953141184', 'Dune', ['Frank Herbert'], 2005],
            [$c['source'], $c['source_name'], $c['key'], $c['title'], $c['authors'], $c['year']]);
        $this->assertSame('https://is1-ssl.mzstatic.com/image/thumb/Publication/v4/aa/dune.jpg/1200x1200bb.jpg', $c['cover_url']);
        $this->assertSame(['Sci-Fi & Fantasy'], $c['genres'], '"Books" is no genre');
        $this->assertSame('https://books.apple.com/us/book/dune/id953141184', $c['url']);
    }

    // --- Merging ---------------------------------------------------------------

    public function test_every_catalogue_joins_one_entry_per_book(): void
    {
        $this->fakeAll([], [self::itunesResult(), self::itunesResult(['trackId' => 2, 'trackName' => 'Dune Messiah', 'artworkUrl100' => 'https://is1.test/m.jpg/100x100bb.jpg'])]);

        $list = app(BookLookup::class)->candidates(['title' => 'Dune', 'author' => 'Frank Herbert']);

        $dune = $list[0];
        $this->assertSame('google', $dune['source'], 'the first source keeps the entry');
        $this->assertSame('Google Books', $dune['source_name']);
        $this->assertSame(['google', 'openlibrary', 'hardcover', 'itunes'], $dune['sources']);
        $this->assertSame('Google text.', $dune['description'], 'Google first');
        // The largest cover: Apple's 1200 px, then Open Library's L, Hardcover's, Google's thumbnail.
        $this->assertSame('https://is1-ssl.mzstatic.com/image/thumb/Publication/v4/aa/dune.jpg/1200x1200bb.jpg', $dune['cover_url']);
        $this->assertSame(['itunes', 'openlibrary', 'hardcover', 'google'], array_column($dune['covers'], 'source'));
        $this->assertSame(['Dune', 1.0, 4.27, 52000], [$dune['series'], $dune['series_index'], $dune['rating'], $dune['users_count']]);
        // The book that is not Dune stays its own entry.
        $this->assertSame(['Dune', 'Dune Messiah'], array_column($list, 'title'));
        $this->assertSame(['itunes'], $list[1]['sources']);

        // The keys the review page and the request search always read, in order, then the new ones.
        $this->assertSame(['source', 'source_name', 'key', 'title', 'authors', 'description', 'genres', 'publisher', 'year', 'isbn', 'cover_url', 'url',
            'language', 'languages', 'sources', 'covers', 'series', 'series_index', 'rating', 'ratings_count', 'users_count', 'isbns'], array_keys($dune));
    }

    public function test_the_description_falls_back_through_hardcover_then_itunes(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [['id' => 'g1', 'volumeInfo' => ['title' => 'Dune', 'authors' => ['Frank Herbert'],
                'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => self::DUNE_ISBN]]]]]]),
            'openlibrary.org/*' => Http::response(['docs' => []]),
            'api.hardcover.app/*' => Http::response(self::hardcoverSearch([self::hardcoverDoc()])),
            'itunes.apple.com/*' => Http::response(['results' => [self::itunesResult()]]),
        ]);
        $this->assertSame('Set on the desert planet Arrakis.', app(BookLookup::class)->candidates(['title' => 'Dune'])[0]['description']);

        config(['novarr.books.sources' => ['google', 'itunes']]);
        $this->assertSame('Apple text.', app(BookLookup::class)->candidates(['title' => 'Dune', 'author' => 'Frank Herbert'])[0]['description']);
    }

    public function test_a_catalogue_that_is_down_never_fails_the_lookup(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response('quota', 429),
            'openlibrary.org/*' => fn() => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
            'api.hardcover.app/*' => Http::response('<html>bad gateway</html>', 502),
            'itunes.apple.com/*' => Http::response(['results' => [self::itunesResult()]]),
        ]);
        $list = app(BookLookup::class)->candidates(['title' => 'Dune', 'author' => 'Frank Herbert']);
        $this->assertSame([['itunes']], array_column($list, 'sources'));

        // find() too: Apple's cover among the candidates, Apple's description.
        $found = app(BookLookup::class)->find(['title' => 'Dune', 'authors' => ['Frank Herbert'], 'isbn' => null]);
        $this->assertSame('Apple text.', $found['description']);
        $this->assertContains('https://is1-ssl.mzstatic.com/image/thumb/Publication/v4/aa/dune.jpg/1200x1200bb.jpg', $found['cover_candidates']);
    }

    public function test_each_catalogue_can_be_switched_off(): void
    {
        $this->fakeAll();
        config(['novarr.books.sources' => ['hardcover']]);
        $this->assertSame(['hardcover'], BookLookup::sources());
        $list = app(BookLookup::class)->candidates(['title' => 'Dune', 'author' => 'Frank Herbert']);
        $this->assertSame([['hardcover']], array_column($list, 'sources'));
        Http::assertNotSent(fn(HttpRequest $r) => !str_contains($r->url(), 'hardcover'));

        // A comma separated string works too (the env form), case and spaces ignored.
        config(['novarr.books.sources' => ' Google , ITUNES ']);
        $this->assertSame(['google', 'itunes'], BookLookup::sources());

        // None: nothing is asked.
        config(['novarr.books.sources' => []]);
        Http::fake();
        $this->assertSame([], app(BookLookup::class)->candidates(['title' => 'Dune']));
        Http::assertNothingSent();
    }

    public function test_the_test_environment_keeps_the_new_catalogues_off(): void
    {
        // phpunit.xml: tests that fake only Google Books and Open Library never reach the others.
        $this->refreshApplication();
        $this->assertSame(['google', 'openlibrary'], BookLookup::sources());
        $this->assertSame([], \App\Audiobooks\AudiobookEnricher::sources());
    }

    public function test_the_review_page_offers_the_other_catalogues_covers(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Mail::fake();
        config(['novarr.books.path' => sys_get_temp_dir() . '/novarr-books-' . uniqid()]);
        $this->fakeAll();
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $this->asSignedIn($reader)->post('/books/upload', [
            'epub' => new \Illuminate\Http\UploadedFile(\Tests\Support\EpubBuilder::epub3(['title' => 'Dune', 'author' => 'Frank Herbert', 'isbn' => null]), 'dune.epub', 'application/epub+zip', null, true),
        ])->assertRedirect('/books/upload');
        $request = \App\NovelRequest::where('medium', Novel::MEDIUM_BOOK)->latest('id')->firstOrFail();

        $page = $this->asSignedIn($owner)->get("/books/review/{$request->id}?match=0")->assertOk();
        $page->assertSee('value="alt-1"', false);
        $page->assertSee('Open Library’s', false);
        $page->assertSee('(Apple Books)', false);

        // An alt-N choice takes that cover; the owner's choice is marked manual.
        $alt = $request->fresh()->candidateList()[0]['covers'][1]['url'];
        \Illuminate\Support\Facades\Http::fake([$alt => Http::response(\Tests\Support\FakeAudioProbe::jpeg(400, 600), 200, ['Content-Type' => 'image/jpeg'])]);
        $this->post("/books/review/{$request->id}/approve", [
            'match' => '0', 'title' => 'Dune', 'author' => 'Frank Herbert', 'description' => '', 'genres' => '', 'cover' => 'alt-1',
        ])->assertRedirect();
        $book = Novel::books()->sole();
        $this->assertNotNull($book->file()->first());
        $this->assertSame('manual', $book->fresh()->cover_source);
        $this->post("/books/review/{$request->id}/approve", ['match' => '0', 'title' => 'x', 'cover' => 'alt-x'])->assertSessionHasErrors('cover');
        \Tests\Support\EpubBuilder::cleanup();
    }

    public function test_hardcover_is_throttled_between_calls(): void
    {
        config(['novarr.books.hardcover_interval_ms' => 150]);
        Http::fake(['api.hardcover.app/*' => Http::response(self::hardcoverSearch([]))]);
        $start = microtime(true);
        app(Hardcover::class)->searchBooks('one');
        app(Hardcover::class)->searchBooks('two');
        $this->assertGreaterThanOrEqual(0.12, microtime(true) - $start);
        // Cached answers are not throttled (nothing is sent).
        $start = microtime(true);
        app(Hardcover::class)->searchBooks('one');
        $this->assertLessThan(0.1, microtime(true) - $start);
    }

    public function test_hardcover_series_marker_subtitles_are_dropped(): void
    {
        $this->assertSame('', \App\Books\Hardcover::realSubtitle('Book 1', null));
        $this->assertSame('', \App\Books\Hardcover::realSubtitle('Skyward Legacy 1', null));
        $this->assertSame('', \App\Books\Hardcover::realSubtitle('Mistborn #1', null));
        $this->assertSame('', \App\Books\Hardcover::realSubtitle('A Riftwake Novel', ['series' => 'Riftwake']));
        $this->assertSame('A Novel', \App\Books\Hardcover::realSubtitle('A Novel', null));
        $this->assertSame('The Story of a Lost Sea', \App\Books\Hardcover::realSubtitle('The Story of a Lost Sea', null));
    }
}
