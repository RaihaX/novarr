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
            ->assertSee('Match this book')
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
        $response->assertRedirect("/novels/{$book->id}");
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

    public function test_the_owners_own_upload_goes_straight_to_the_review(): void
    {
        $response = $this->upload($this->owner, EpubBuilder::epub3());

        $request = NovelRequest::sole();
        $this->assertStringEndsWith("/books/review/{$request->id}", (string) $response->headers->get('Location'));
        // The owner is not emailed about their own upload.
        Mail::assertNothingSent();
    }
}
