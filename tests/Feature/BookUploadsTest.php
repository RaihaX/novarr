<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Books\BookUploads;
use App\Mail\RequestCreated;
use App\Novel;
use App\NovelRequest;
use App\RequestNotification;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * A reader uploads an ePub → the owner is emailed → the owner matches it to
 * the right book on the review page → approve adds it (or decline drops it).
 */
class BookUploadsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private User $owner;
    private User $reader;
    private string $booksDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Bus::fake([\App\Jobs\RunNovelCommand::class]);
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->booksDir = sys_get_temp_dir() . '/novarr-books-' . uniqid();
        config(['novarr.books.path' => $this->booksDir]);

        $this->owner = $this->enableSignIn();
        $this->owner->forceFill(['email' => 'owner@example.test'])->save();
        $this->reader = $this->makeReader();

        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [
                ['id' => 'g1', 'volumeInfo' => [
                    'title' => 'The Real Title',
                    'authors' => ['Real Author'],
                    'description' => 'The catalogue description.',
                    'categories' => ['Fiction / Thrillers'],
                    'publishedDate' => '2011',
                    'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']],
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

    private function upload(User $user, string $file, string $name = 'dump.epub', ?string $note = null)
    {
        return $this->asSignedIn($user)->post('/books/upload', [
            'epub' => new UploadedFile($file, $name, 'application/epub+zip', null, true),
            'note' => $note,
        ]);
    }

    /** A reader's upload of a "dump" ePub (junk title) as a pending request. */
    private function readerUpload(): NovelRequest
    {
        $this->upload($this->reader, EpubBuilder::epub3(['title' => 'ebook_final_v2', 'author' => 'Unknown', 'isbn' => null]), 'dump.epub', 'It is The Real Title')
            ->assertRedirect('/books/upload');

        return NovelRequest::where('medium', Novel::MEDIUM_BOOK)->latest('id')->firstOrFail();
    }

    public function test_a_reader_uploads_and_the_owner_is_emailed_a_link_to_the_review(): void
    {
        $request = $this->readerUpload();

        $this->assertTrue($request->isPending());
        $this->assertSame((int) $this->reader->id, (int) $request->user_id);
        $this->assertSame('ebook_final_v2', $request->title);
        $this->assertSame('It is The Real Title', $request->note);
        $this->assertSame(3, $request->upload_meta['chapters']);
        $this->assertFileExists(BookUploads::pendingPath($request));
        $this->assertNotNull($request->upload_meta['cover_path']);
        // Nothing reaches the library before the owner approves.
        $this->assertSame(0, Novel::books()->count());

        Mail::assertSent(RequestCreated::class, function (RequestCreated $mail) use ($request) {
            return $mail->hasTo('owner@example.test')
                && str_contains($mail->subjectLine(), 'uploaded ebook_final_v2')
                && str_ends_with($mail->buttonUrl, "/books/review/{$request->id}");
        });
    }

    public function test_a_file_that_is_not_an_epub_is_refused_at_once(): void
    {
        $this->upload($this->reader, EpubBuilder::notAnEpub(), 'x.epub')
            ->assertSessionHasErrors('epub');

        $this->assertSame(0, NovelRequest::count());
        Mail::assertNothingSent();
    }

    public function test_a_file_already_in_the_library_is_refused(): void
    {
        $file = EpubBuilder::epub3();
        app(BookLibrary::class)->import($file, 'a.epub', lookup: false);

        $this->upload($this->reader, $file)->assertSessionHasErrors('epub');
        $this->assertSame(0, NovelRequest::count());
    }

    public function test_the_same_file_cannot_wait_twice(): void
    {
        $file = EpubBuilder::epub3();
        $this->upload($this->reader, $file)->assertSessionHasNoErrors();
        $this->upload($this->reader, $file)->assertSessionHasErrors('epub');

        $this->assertSame(1, NovelRequest::count());
    }

    public function test_only_the_owner_reviews(): void
    {
        $request = $this->readerUpload();

        $this->asSignedIn($this->reader)->get("/books/review/{$request->id}")->assertForbidden();
        $this->asSignedIn($this->owner)->get("/books/review/{$request->id}")
            ->assertOk()
            ->assertSee('Which book is it?')
            ->assertSee('The Real Title')
            ->assertSee('It is The Real Title');
    }

    public function test_the_owner_searches_matches_and_approves_with_the_matched_details(): void
    {
        $request = $this->readerUpload();
        $this->asSignedIn($this->owner);

        $this->post("/books/review/{$request->id}/search", ['q_title' => 'The Real Title', 'q_author' => 'Real Author'])
            ->assertRedirect("/books/review/{$request->id}");
        Http::assertSent(fn($r) => str_contains(urldecode($r->url()), 'intitle:"The Real Title"'));

        $this->get("/books/review/{$request->id}?match=0")
            ->assertOk()
            ->assertSee('value="The Real Title"', false)
            ->assertSee('The catalogue description.');

        $response = $this->post("/books/review/{$request->id}/approve", [
            'match' => '0',
            'title' => 'The Real Title',
            'author' => 'Real Author',
            'description' => 'The catalogue description.',
            'genres' => 'Fiction, Thrillers',
            'cover' => 'file',
        ]);

        $book = Novel::books()->sole();
        // Nothing else waiting: back to the Requests page, with a link to the book.
        $response->assertRedirect('/requests')->assertSessionHas('success_link', route('novels.show', $book->id));
        $this->assertSame('The Real Title', $book->name);
        $this->assertSame('Real Author', $book->author);
        $this->assertSame('The catalogue description.', $book->description);
        $this->assertEqualsCanonicalizing(['Fiction', 'Thrillers'], $book->tags()->pluck('name')->all());
        $this->assertNotNull($book->file()->first());
        $this->assertFileExists(BookLibrary::path($book));

        $request->refresh();
        $this->assertSame(NovelRequest::STATUS_AVAILABLE, $request->displayStatus());
        $this->assertSame((int) $book->id, (int) $request->novel_id);
        $this->assertNull($request->upload_path);
        $this->assertCount(0, File::glob($this->booksDir . '/pending/*.epub'));
        // The reader hears it is there.
        $this->assertTrue(RequestNotification::where('user_id', $this->reader->id)->where('novel_request_id', $request->id)->exists());
    }

    public function test_a_duplicate_title_needs_add_anyway(): void
    {
        app(BookLibrary::class)->import(EpubBuilder::epub3(['title' => 'The Real Title', 'description' => 'other']), 'a.epub', lookup: false);
        $request = $this->readerUpload();
        $this->asSignedIn($this->owner);
        $fields = ['match' => 'file', 'title' => 'The Real Title', 'author' => '', 'description' => '', 'genres' => '', 'cover' => 'none'];

        $this->post("/books/review/{$request->id}/approve", $fields)
            ->assertSessionHasErrors('approve')
            ->assertSessionHas('duplicate', true);
        $this->assertTrue($request->fresh()->isPending());

        $this->post("/books/review/{$request->id}/approve", $fields + ['force' => '1'])->assertRedirect();
        $this->assertSame(2, Novel::books()->count());
    }

    public function test_declining_removes_the_file(): void
    {
        $request = $this->readerUpload();
        $path = BookUploads::pendingPath($request);

        $this->asSignedIn($this->owner)->post("/books/review/{$request->id}/decline", ['reason' => 'Wrong book'])
            ->assertRedirect('/requests');

        $request->refresh();
        $this->assertSame(NovelRequest::STATUS_DECLINED, $request->status);
        $this->assertSame('Wrong book', $request->decline_reason);
        $this->assertFileDoesNotExist($path);
        Storage::disk('public')->assertMissing((string) $request->upload_meta['cover_path']);
    }

    public function test_a_cancelled_upload_removes_the_file(): void
    {
        $request = $this->readerUpload();
        $path = BookUploads::pendingPath($request);

        $this->asToken($this->tokenFor($this->reader))->deleteJson("/api/v1/requests/{$request->id}")->assertSuccessful();

        $this->assertNull(NovelRequest::find($request->id));
        $this->assertFileDoesNotExist($path);
    }

    public function test_the_requests_api_sends_the_owner_to_the_review_page(): void
    {
        $request = $this->readerUpload();
        $token = $this->tokenFor($this->owner);

        $this->asToken($token)->postJson("/api/v1/requests/{$request->id}/approve", ['candidate' => 0])
            ->assertStatus(422)
            ->assertJsonPath('code', 'review_upload');

        $listed = collect($this->asToken($token)->getJson('/api/v1/requests?scope=all')->json('requests'))->firstWhere('id', $request->id);
        $this->assertStringEndsWith("/books/review/{$request->id}", (string) ($listed['review_url'] ?? ''));
    }

    public function test_several_files_at_once_each_become_a_request_and_a_bad_one_is_reported(): void
    {
        $response = $this->asSignedIn($this->reader)->post('/books/upload', [
            'epub' => [
                new UploadedFile(EpubBuilder::epub3(), 'one.epub', 'application/epub+zip', null, true),
                new UploadedFile(EpubBuilder::notAnEpub(), 'broken.epub', 'application/epub+zip', null, true),
                new UploadedFile(EpubBuilder::epub2(), 'two.epub', 'application/epub+zip', null, true),
            ],
        ]);

        $response->assertRedirect('/books/upload')->assertSessionHas('success')->assertSessionHasErrors('epub');
        $this->assertStringContainsString('broken.epub', session('errors')->first('epub'));
        $this->assertSame(2, NovelRequest::where('medium', Novel::MEDIUM_BOOK)->count());
        Mail::assertSent(RequestCreated::class, 2);
    }

    public function test_uploads_have_their_own_limit_and_do_not_use_up_title_requests(): void
    {
        config(['novarr.books.max_pending_uploads' => 2, 'novarr.requests.max_pending_per_reader' => 1]);
        $this->asSignedIn($this->reader);

        $this->upload($this->reader, EpubBuilder::epub3())->assertSessionHasNoErrors();
        $this->upload($this->reader, EpubBuilder::epub2())->assertSessionHasNoErrors();
        $this->upload($this->reader, EpubBuilder::singleFile())->assertSessionHasErrors('epub');
        $this->assertStringContainsString('2 books waiting', session('errors')->first('epub'));

        // Two uploads waiting, yet the one title request is still free.
        $this->asToken($this->tokenFor($this->reader))->postJson('/api/v1/requests', ['title' => 'Some Novel', 'medium' => 'novel'])->assertStatus(201);
    }

    public function test_after_a_decision_the_next_waiting_book_opens(): void
    {
        $this->upload($this->reader, EpubBuilder::epub3())->assertSessionHasNoErrors();
        $this->upload($this->reader, EpubBuilder::epub2())->assertSessionHasNoErrors();
        [$first, $second] = NovelRequest::where('medium', Novel::MEDIUM_BOOK)->orderBy('id')->get()->all();

        $this->asSignedIn($this->owner)->post("/books/review/{$first->id}/decline", ['reason' => 'No'])
            ->assertRedirect("/books/review/{$second->id}")
            ->assertSessionHas('queue_left', 1);
    }

    public function test_the_review_page_puts_the_decision_beside_the_matches(): void
    {
        $request = $this->readerUpload();

        $html = $this->asSignedIn($this->owner)->get("/books/review/{$request->id}")->assertOk()->getContent();

        $this->assertStringContainsString('book-review-decide', $html);
        $this->assertStringContainsString('data-match-details', $html);
        // The decision panel comes after the file strip and is a sibling of the matches.
        $this->assertLessThan(strpos($html, 'book-review-decide'), strpos($html, 'Which book is it?'));
    }

    public function test_the_owners_own_upload_goes_straight_to_the_review(): void
    {
        $response = $this->upload($this->owner, EpubBuilder::epub3());

        $request = NovelRequest::sole();
        $this->assertStringEndsWith("/books/review/{$request->id}", (string) $response->headers->get('Location'));
        // The owner is not emailed about their own upload.
        Mail::assertNothingSent();
    }

    public function test_an_upload_of_a_book_already_in_the_library_is_refused_with_a_link(): void
    {
        $have = app(BookLibrary::class)->import(EpubBuilder::epub3(['title' => 'Star Wars: Thrawn', 'author' => 'Timothy Zahn']), 'a.epub', false, false)['novel'];

        // Another file (different bytes) of the same book, titled a little differently.
        $this->upload($this->reader, EpubBuilder::epub3(['title' => 'Star Wars - Thrawn', 'author' => 'Timothy Zahn', 'description' => 'other file']))
            ->assertSessionHasErrors('epub')
            ->assertSessionHas('in_library', route('novels.show', $have->id));
        $this->assertStringContainsString('“Star Wars: Thrawn” by Timothy Zahn is already in the library.', session('errors')->first('epub'));
        $this->assertSame(0, NovelRequest::count());

        // The author written the other way round is the same author.
        $this->upload($this->reader, EpubBuilder::epub3(['title' => 'Star Wars: Thrawn', 'author' => 'Zahn, Timothy', 'description' => 'reversed']))
            ->assertSessionHasErrors('epub');

        // The same title by someone else is another book.
        $this->upload($this->reader, EpubBuilder::epub3(['title' => 'Star Wars: Thrawn', 'author' => 'Someone Else', 'description' => 'third']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_review_page_offers_the_book_already_in_the_library_and_marks_the_request_ready(): void
    {
        $this->reader->forceFill(['email' => 'sam@example.test'])->save();
        // Waiting first, then the same book arrives from elsewhere (Calibre).
        $this->upload($this->reader, EpubBuilder::epub3(['title' => 'Star Wars: Thrawn', 'author' => 'Timothy Zahn']));
        $request = NovelRequest::sole();
        $have = app(BookLibrary::class)->import(EpubBuilder::epub3(['title' => 'Star Wars: Thrawn', 'author' => 'Timothy Zahn', 'description' => 'calibre copy']), 'b.epub', false, false)['novel'];

        $this->asSignedIn($this->owner)->get(route('books.review', $request->id))
            ->assertOk()
            ->assertSee('Already in the library')
            ->assertSee('mark the request ready')
            ->assertSee('add it anyway');

        $this->asSignedIn($this->owner)->post(route('books.review.use_existing', $request->id), ['novel_id' => $have->id])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame((int) $have->id, (int) $request->novel_id);
        $this->assertNotNull($request->available_at);
        $this->assertNull($request->upload_path);
        $this->assertSame(1, Novel::books()->count(), 'no second copy');
        Mail::assertSent(\App\Mail\RequestAvailable::class, fn($m) => $m->hasTo('sam@example.test'));
    }
}
