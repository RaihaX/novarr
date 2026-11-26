<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Mail\RequestAvailable;
use App\Mail\RequestCreated;
use App\Novel;
use App\NovelRequest;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Requesting a book by title (Google Books / Open Library search): the
 * owner finds the ePub, uploads it on the request's page, matches it and
 * adds it; the reader hears it is ready.
 */
class BookRequestsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private User $owner;
    private User $reader;
    private string $booksDir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        Bus::fake([\App\Jobs\RunNovelCommand::class]);
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->booksDir = sys_get_temp_dir() . '/novarr-books-' . uniqid();
        config(['novarr.books.path' => $this->booksDir]);

        $this->owner = $this->enableSignIn();
        $this->owner->forceFill(['email' => 'owner@example.test'])->save();
        $this->reader = $this->makeReader();
        $this->reader->forceFill(['email' => 'sam@example.test'])->save();

        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [
                ['id' => 'g1', 'volumeInfo' => [
                    'title' => 'The Quiet Orchard',
                    'authors' => ['Ada Wren'],
                    'description' => 'A quiet story.',
                    'publishedDate' => '2011',
                    'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id=g1&edge=curl'],
                    'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']],
                    'canonicalVolumeLink' => 'https://books.google.com/books/about/The_Quiet_Orchard.html?id=g1',
                ]],
                ['id' => 'g2', 'volumeInfo' => [
                    'title' => 'The Quiet Orchard',
                    'authors' => ['Someone Else'],
                    'canonicalVolumeLink' => 'https://books.google.com/books/about/x.html?id=g2',
                ]],
            ]]),
            'openlibrary.org/*' => Http::response(['docs' => []]),
        ]);
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        File::deleteDirectory($this->booksDir);
        parent::tearDown();
    }

    private function search(User $user, string $q = 'quiet orchard'): array
    {
        $token = $this->tokenFor($user);
        $id = $this->asToken($token)->postJson('/api/v1/requests/searches', ['q' => $q, 'medium' => 'book'])->assertStatus(202)->json('search.id');

        return $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->assertOk()->json('search');
    }

    private function requestBook(): NovelRequest
    {
        $search = $this->search($this->reader);
        $result = collect($search['results'])->firstWhere('author', 'Ada Wren');
        $this->asToken($this->tokenFor($this->reader))->postJson('/api/v1/requests', [
            'search_id' => $search['id'], 'key' => $result['key'], 'note' => 'please!',
        ])->assertCreated();

        return NovelRequest::sole();
    }

    public function test_a_book_search_asks_the_catalogues_and_keeps_authors_apart(): void
    {
        $search = $this->search($this->reader);

        $this->assertSame('done', $search['status']);
        $this->assertSame('book', $search['medium']);
        $this->assertCount(2, $search['results'], 'the same title by two authors is two books');
        $ada = collect($search['results'])->firstWhere('author', 'Ada Wren');
        $this->assertSame('The Quiet Orchard', $ada['title']);
        $this->assertSame('Book catalogues', $ada['sources'][0]['source_name']);
    }

    public function test_requesting_a_book_tells_the_owner_to_find_and_upload_it(): void
    {
        $request = $this->requestBook();

        $this->assertSame(Novel::MEDIUM_BOOK, $request->medium);
        $this->assertTrue($request->isPending());
        $this->assertTrue($request->isWanted());
        $this->assertSame('Ada Wren', $request->author);
        Mail::assertSent(RequestCreated::class, fn($m) => $m->hasTo('owner@example.test') && $m->wantedBook
            && $m->buttonText === 'Upload the ePub' && str_contains($m->subjectLine(), 'requested')
            && str_ends_with($m->buttonUrl, "/books/review/{$request->id}"));

        // The owner's request list links to the upload page.
        $this->asSignedIn($this->owner)->getJson('/api/v1/requests?scope=all')
            ->assertJsonPath('requests.0.wanted', true)
            ->assertJsonPath('requests.0.review_url', fn($url) => str_ends_with($url, "/books/review/{$request->id}"));
    }

    public function test_the_generic_approve_is_refused_until_the_epub_is_uploaded(): void
    {
        $request = $this->requestBook();

        $this->asSignedIn($this->owner)->postJson("/api/v1/requests/{$request->id}/approve", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'review_upload');
    }

    public function test_the_owner_uploads_the_epub_matches_and_adds_it(): void
    {
        $request = $this->requestBook();

        $this->asSignedIn($this->owner)->get(route('books.review', $request->id))
            ->assertOk()
            ->assertSee('Upload the ePub')
            ->assertSee('Requested by Sam')
            ->assertSee('please!');

        $this->asSignedIn($this->owner)->post(route('books.review.upload', $request->id), [
            'epub' => new UploadedFile(EpubBuilder::epub3(['title' => 'quiet_orchard_scan']), 'scan.epub', 'application/epub+zip', null, true),
        ])->assertRedirect(route('books.review', [$request->id, 'match' => 0]));

        $request->refresh();
        $this->assertFalse($request->isWanted());
        $this->assertNotNull($request->upload_path);
        $this->assertSame('The Quiet Orchard', $request->title, "the reader's title stays");
        $this->assertSame('The Quiet Orchard', $request->candidateList()[0]['title'], 'the requested book is the first match');

        // The usual review page now.
        $this->asSignedIn($this->owner)->get(route('books.review', [$request->id, 'match' => 0]))
            ->assertOk()->assertSee('Requested by Sam')->assertSee('Add to the library');

        $this->asSignedIn($this->owner)->post(route('books.review.approve', $request->id), [
            'match' => '0', 'title' => 'The Quiet Orchard', 'author' => 'Ada Wren', 'cover' => 'none',
        ])->assertRedirect();

        $novel = Novel::where('medium', Novel::MEDIUM_BOOK)->sole();
        $this->assertSame('The Quiet Orchard', $novel->name);
        $this->assertNotNull($request->fresh()->available_at);
        Mail::assertSent(RequestAvailable::class, fn($m) => $m->hasTo('sam@example.test'));
    }

    public function test_a_bad_file_is_refused_and_the_request_stays_wanted(): void
    {
        $request = $this->requestBook();

        $this->asSignedIn($this->owner)->from(route('books.review', $request->id))->post(route('books.review.upload', $request->id), [
            'epub' => new UploadedFile(EpubBuilder::notAnEpub(), 'bad.epub', 'application/epub+zip', null, true),
        ])->assertRedirect(route('books.review', $request->id))->assertSessionHasErrors('epub');

        $this->assertTrue($request->fresh()->isWanted());
    }

    public function test_declining_when_it_cannot_be_found(): void
    {
        $request = $this->requestBook();

        $this->asSignedIn($this->owner)->post(route('books.review.decline', $request->id), ['reason' => 'No ePub anywhere.'])->assertRedirect();
        $this->assertSame(NovelRequest::STATUS_DECLINED, $request->fresh()->status);
    }

    public function test_a_book_already_in_the_library_cannot_be_requested(): void
    {
        app(BookLibrary::class)->import(EpubBuilder::epub3(), 'orchard.epub', false, false);
        $search = $this->search($this->reader);
        $result = collect($search['results'])->firstWhere('author', 'Ada Wren');
        $this->assertTrue($result['in_library']);

        $this->asToken($this->tokenFor($this->reader))->postJson('/api/v1/requests', ['search_id' => $search['id'], 'key' => $result['key']])
            ->assertStatus(409)->assertJsonPath('code', 'in_library');
    }

    public function test_requested_books_count_as_title_requests_not_uploads(): void
    {
        config(['novarr.requests.max_pending_per_reader' => 1]);
        $this->requestBook();

        // A second title request is over the limit…
        $this->asToken($this->tokenFor($this->reader))->postJson('/api/v1/requests', ['title' => 'Another Book', 'medium' => 'book'])
            ->assertStatus(429)->assertJsonPath('code', 'pending_limit');
        // …but an upload is not.
        $this->asSignedIn($this->reader)->post('/books/upload', [
            'epub' => new UploadedFile(EpubBuilder::epub2(), 'b.epub', 'application/epub+zip', null, true),
        ])->assertRedirect(route('books.upload'))->assertSessionHasNoErrors();
    }

    public function test_the_requests_page_offers_books(): void
    {
        $this->asSignedIn($this->reader)->get(route('requests.index'))
            ->assertOk()
            ->assertSee('data-medium="book"', false)
            ->assertSee('value="book"', false);
    }
}
