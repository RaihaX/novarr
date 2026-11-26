<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Mail\SendToKindle;
use App\Novel;
use App\Services\SendEpub;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/** "Send to my Kindle / email": each reader's own address, a button on every book and novel. */
class SendEpubTest extends TestCase
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
        Http::fake(['*' => Http::response(['items' => [], 'docs' => []])]);
        config(['mail.default' => 'smtp', 'mail.from.address' => 'novarr@example.test']);
        $this->booksDir = sys_get_temp_dir() . '/novarr-books-' . uniqid();
        config(['novarr.books.path' => $this->booksDir]);

        $this->owner = $this->enableSignIn();
        $this->reader = $this->makeReader();
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        File::deleteDirectory($this->booksDir);
        parent::tearDown();
    }

    private function book(): Novel
    {
        return app(BookLibrary::class)->import(EpubBuilder::epub3(), 'orchard.epub', false, false)['novel'];
    }

    private function api(User $user)
    {
        return $this->asToken($this->tokenFor($user));
    }

    public function test_a_reader_saves_their_address_on_the_account_page(): void
    {
        $this->asSignedIn($this->reader)->get('/account')->assertOk()
            ->assertSee('Send to Kindle or email')
            ->assertSee('novarr@example.test');

        $this->asSignedIn($this->reader)->post('/account/send-to', ['send_to_email' => 'sam_42@kindle.com'])
            ->assertRedirect(route('account') . '#send-to');
        $this->assertSame('sam_42@kindle.com', $this->reader->fresh()->send_to_email);

        $this->asSignedIn($this->reader)->post('/account/send-to', ['send_to_email' => 'not an email'])->assertSessionHasErrors('send_to_email');
        $this->asSignedIn($this->reader)->post('/account/send-to', ['send_to_email' => ''])->assertRedirect();
        $this->assertNull($this->reader->fresh()->send_to_email);
    }

    public function test_sending_a_book_emails_its_epub_to_the_readers_address(): void
    {
        $book = $this->book();
        $this->reader->forceFill(['send_to_email' => 'sam_42@kindle.com'])->save();

        $this->api($this->reader)->postJson("/api/v1/novels/{$book->id}/send")
            ->assertStatus(202)
            ->assertJsonPath('send.to', 'sam_42@kindle.com');

        Mail::assertSent(SendToKindle::class, fn($m) => $m->hasTo('sam_42@kindle.com') && $m->novelTitle === $book->name);
        $this->api($this->reader)->getJson("/api/v1/novels/{$book->id}/send")
            ->assertOk()
            ->assertJsonPath('send.status', SendEpub::SENT);
    }

    public function test_no_address_points_to_the_account_page(): void
    {
        $book = $this->book();

        $this->api($this->reader)->postJson("/api/v1/novels/{$book->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_address')
            ->assertJsonPath('account_url', route('account') . '#send-to');
        Mail::assertNothingSent();
    }

    public function test_the_owner_falls_back_to_the_settings_kindle_email(): void
    {
        $book = $this->book();
        \App\Setting::put('kindle_email', 'owner_1@kindle.com');

        $this->api($this->owner)->postJson("/api/v1/novels/{$book->id}/send")->assertStatus(202);
        Mail::assertSent(SendToKindle::class, fn($m) => $m->hasTo('owner_1@kindle.com'));
    }

    public function test_a_missing_book_file_fails_with_a_reason(): void
    {
        $book = $this->book();
        @unlink(\App\Console\Commands\GenerateePub::epubPath($book));
        $this->reader->forceFill(['send_to_email' => 'sam@example.test'])->save();

        $this->api($this->reader)->postJson("/api/v1/novels/{$book->id}/send")->assertStatus(202)
            ->assertJsonPath('send.status', SendEpub::FAILED)
            ->assertJsonPath('send.message', "This book's file is missing from the library.");
        Mail::assertNothingSent();
    }

    public function test_manga_is_refused_and_sends_are_rate_limited(): void
    {
        $manga = Novel::create(['name' => 'A Manga', 'status' => 0, 'group_id' => 1, 'medium' => Novel::MEDIUM_MANGA]);
        $this->reader->forceFill(['send_to_email' => 'sam@example.test'])->save();
        $this->api($this->reader)->postJson("/api/v1/novels/{$manga->id}/send")->assertStatus(422)->assertJsonPath('code', 'manga');

        $book = $this->book();
        \Illuminate\Support\Facades\RateLimiter::increment('send-epub:' . $this->reader->id, 3600, SendEpub::PER_HOUR);
        $this->api($this->reader)->postJson("/api/v1/novels/{$book->id}/send")->assertStatus(429);
    }

    public function test_every_reader_sees_the_button_on_a_book(): void
    {
        $book = $this->book();
        $this->asSignedIn($this->reader)->get(route('novels.show', $book->id))->assertOk()
            ->assertSee('Send to my Kindle / email')
            ->assertSee('data-reader-action="send"', false);
    }
}
