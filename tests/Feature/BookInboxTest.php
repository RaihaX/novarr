<?php

namespace Tests\Feature;

use App\Books\BookInbox;
use App\Mail\BookInboxReply;
use App\Mail\RequestCreated;
use App\Novel;
use App\NovelRequest;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\Support\EpubBuilder;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Sending books without the upload page: the books email address
 * (App\Books\BookInbox, posted by the Cloudflare Email Worker) and the
 * Android app's "Share to Novarr" (POST /api/v1/books/uploads).
 */
class BookInboxTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const SECRET = 'test-secret-test-secret-test-secret-0123';

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
        config([
            'novarr.books.path' => $this->booksDir,
            'novarr.books.inbox.address' => 'books@example.test',
            'novarr.books.inbox.secret' => self::SECRET,
        ]);

        $this->owner = $this->enableSignIn();
        $this->owner->forceFill(['email' => 'owner@example.test'])->save();
        $this->reader = $this->makeReader();
        $this->reader->forceFill(['email' => 'Sam@Example.test'])->save();

        Http::fake(['*' => Http::response(['items' => [], 'docs' => []])]);
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        File::deleteDirectory($this->booksDir);
        parent::tearDown();
    }

    private function email(string $from = 'sam@example.test', array $attachments = [], string $subject = 'Fwd: a book for you'): Email
    {
        $email = (new Email())->from($from)->to('books@example.test')->subject($subject)->text('Here it is.');
        foreach ($attachments as $name => $path) {
            $email->attachFromPath($path, $name, str_ends_with($name, '.epub') ? 'application/octet-stream' : 'image/png');
        }

        return $email;
    }

    private function inbox(string $raw, ?string $secret = self::SECRET, ?string $envelope = null)
    {
        return $this->call('POST', '/api/v1/books/inbox', [], [], [], array_filter([
            'CONTENT_TYPE' => 'message/rfc822',
            'HTTP_X_NOVARR_INBOX_SECRET' => $secret,
            'HTTP_X_NOVARR_ENVELOPE_FROM' => $envelope,
        ]), $raw);
    }

    public function test_a_readers_email_becomes_pending_book_requests_and_gets_a_reply(): void
    {
        $raw = $this->email('Sam <sam@example.test>', [
            'Orchard.epub' => EpubBuilder::epub3(),
            'Second.epub' => EpubBuilder::epub2(),
            'logo.png' => EpubBuilder::notAnEpub(),
        ])->toString();

        $this->inbox($raw)
            ->assertOk()
            ->assertJsonPath('code', BookInbox::ACCEPTED)
            ->assertJsonCount(2, 'sent')
            ->assertJsonCount(0, 'refused');

        $requests = NovelRequest::where('medium', Novel::MEDIUM_BOOK)->get();
        $this->assertCount(2, $requests);
        $this->assertTrue($requests->every(fn($r) => (int) $r->user_id === (int) $this->reader->id && $r->isPending()));
        $this->assertSame('a book for you', $requests->first()->note, 'the subject is the note, without "Fwd:"');

        Mail::assertSent(BookInboxReply::class, fn($m) => $m->hasTo('Sam@Example.test') && count($m->sentTitles) === 2 && !$m->forOwner);
        Mail::assertSent(RequestCreated::class, fn($m) => $m->hasTo('owner@example.test'));
    }

    public function test_a_forwarded_email_with_the_epub_inside_is_read(): void
    {
        $inner = $this->email('shop@store.test', ['Bought.epub' => EpubBuilder::epub3()], 'Your purchase');
        $outer = (new Email())->from('sam@example.test')->to('books@example.test')->subject('Fwd: Your purchase')
            ->text('see attached')
            ->attach($inner->toString(), 'forwarded.eml', 'message/rfc822');

        $this->inbox($outer->toString())->assertOk()->assertJsonCount(1, 'sent');
    }

    public function test_unknown_senders_and_disabled_readers_are_refused_for_a_bounce(): void
    {
        $epub = EpubBuilder::epub3();
        $this->inbox($this->email('stranger@else.test', ['a.epub' => $epub])->toString())
            ->assertStatus(422)
            ->assertJsonPath('code', BookInbox::UNKNOWN_SENDER);

        $this->reader->forceFill(['disabled_at' => now()])->save();
        $this->inbox($this->email('sam@example.test', ['a.epub' => $epub])->toString())->assertStatus(422);

        $this->assertSame(0, NovelRequest::count());
        Mail::assertNothingSent();
    }

    public function test_the_envelope_sender_is_used_when_from_is_not_an_account(): void
    {
        $raw = $this->email('Sam via list <noreply@forwarder.test>', ['a.epub' => EpubBuilder::epub3()])->toString();

        $this->inbox($raw, self::SECRET, 'sam@example.test')->assertOk()->assertJsonCount(1, 'sent');
    }

    public function test_wrong_secret_and_inbox_off(): void
    {
        $raw = $this->email('sam@example.test', ['a.epub' => EpubBuilder::epub3()])->toString();
        $this->inbox($raw, 'nope')->assertStatus(403);
        $this->inbox($raw, null)->assertStatus(403);

        config(['novarr.books.inbox.secret' => 'short']);
        $this->inbox($raw, 'short')->assertStatus(404);
        $this->assertSame(0, NovelRequest::count());
    }

    public function test_no_epub_and_bad_epub_get_an_explaining_reply(): void
    {
        $this->inbox($this->email('sam@example.test', ['logo.png' => EpubBuilder::notAnEpub()])->toString())
            ->assertOk()->assertJsonCount(0, 'sent');
        Mail::assertSent(BookInboxReply::class, fn($m) => $m->sentTitles === [] && $m->refusedLines === []
            && str_contains($m->intro(), 'no ePub attached'));

        $this->inbox($this->email('sam@example.test', ['broken.epub' => EpubBuilder::notAnEpub()])->toString())
            ->assertOk()->assertJsonPath('refused.0.file', 'broken.epub');
        Mail::assertSent(BookInboxReply::class, fn($m) => count($m->refusedLines) === 1 && str_starts_with($m->refusedLines[0], 'broken.epub: '));
    }

    public function test_automatic_mail_is_dropped_without_a_reply(): void
    {
        $email = $this->email('sam@example.test', ['a.epub' => EpubBuilder::epub3()]);
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');

        $this->inbox($email->toString())->assertOk()->assertJsonPath('code', BookInbox::IGNORED);
        $this->inbox($this->email('MAILER-DAEMON@example.test')->toString())->assertOk()->assertJsonPath('code', BookInbox::IGNORED);
        $this->assertSame(0, NovelRequest::count());
        Mail::assertNothingSent();
    }

    public function test_the_owners_email_reply_links_to_the_review_page(): void
    {
        $this->inbox($this->email('owner@example.test', ['a.epub' => EpubBuilder::epub3()])->toString())->assertOk();

        $request = NovelRequest::firstOrFail();
        Mail::assertSent(BookInboxReply::class, fn($m) => $m->forOwner && str_ends_with($m->buttonUrl, "/books/review/{$request->id}"));
        Mail::assertNotSent(RequestCreated::class);
    }

    public function test_the_route_is_public_but_needs_the_secret_even_with_sign_in_on(): void
    {
        $raw = $this->email('sam@example.test', ['a.epub' => EpubBuilder::epub3()])->toString();
        $this->asNobody();
        $this->inbox($raw, 'wrong')->assertStatus(403);
        $this->inbox($raw)->assertOk();
    }

    // --- The app's "Share to Novarr" ---------------------------------------

    private function appUpload(array $files, ?string $note = null)
    {
        $token = $this->tokenFor($this->reader);

        return $this->asToken($token)->post('/api/v1/books/uploads', [
            'epub' => array_map(fn($path, $name) => new UploadedFile($path, $name, 'application/epub+zip', null, true), $files, array_keys($files)),
            'note' => $note,
        ], ['Accept' => 'application/json']);
    }

    public function test_the_app_uploads_books_for_approval(): void
    {
        $this->appUpload(['Orchard.epub' => EpubBuilder::epub3(), 'Second.epub' => EpubBuilder::epub2()], 'from my phone')
            ->assertCreated()
            ->assertJsonCount(2, 'sent')
            ->assertJsonPath('sent.0.title', 'The Quiet Orchard')
            ->assertJsonPath('message', '2 books were sent to the owner.');

        $this->assertSame(2, NovelRequest::where('user_id', $this->reader->id)->where('note', 'from my phone')->count());
    }

    public function test_the_app_hears_why_each_file_was_refused(): void
    {
        $epub = EpubBuilder::epub3();
        $this->appUpload(['a.epub' => $epub])->assertCreated();

        $this->appUpload(['again.epub' => $epub, 'broken.epub' => EpubBuilder::notAnEpub()])
            ->assertStatus(422)
            ->assertJsonPath('refused.0.code', 'already_requested')
            ->assertJsonPath('refused.1.code', 'unreadable')
            ->assertJsonPath('message', 'None of the books could be added.');

        $this->appUpload(['a.txt' => EpubBuilder::notAnEpub()])->assertStatus(422)->assertJsonValidationErrors('epub.0');
    }

    public function test_the_app_upload_needs_a_signed_in_reader(): void
    {
        $this->asNobody()->post('/api/v1/books/uploads', [], ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_meta_tells_the_app_the_limits_and_the_email_address(): void
    {
        $this->getJson('/api/v1/meta')
            ->assertJsonPath('book_uploads.email', 'books@example.test')
            ->assertJsonPath('book_uploads.max_files', 20);

        config(['novarr.books.inbox.secret' => '']);
        $this->getJson('/api/v1/meta')->assertJsonPath('book_uploads.email', null);
    }

    public function test_the_upload_page_shows_the_email_address_and_the_readers_own_address(): void
    {
        $this->asSignedIn($this->reader)->get('/books/upload')
            ->assertOk()
            ->assertSee('books@example.test')
            ->assertSee('Sam@Example.test');
    }
}
