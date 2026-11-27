<?php

namespace Tests\Feature;

use App\Audiobook;
use App\AudiobookUpload;
use App\Audiobooks\AudiobookLibrary;
use App\Audiobooks\AudiobookUploads;
use App\Jobs\ProcessAudiobookUpload;
use App\Mail\AdminAlertMail;
use App\Mail\AudiobookUploadAvailable;
use App\Mail\AudiobookUploadDeclined;
use App\Novel;
use App\Support\RouteAccess;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAudioProbe;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Readers upload audiobooks (App\Audiobooks\AudiobookUploads, the chunk API
 * /api/v1/audiobook-uploads, the owner's review /audiobooks/uploads/{id}):
 * chunk assembly, resume, idempotent and out-of-order chunks, every limit,
 * checksum and probe refusals, the queued check, approve / decline / use the
 * existing one, the duplicate guard, the admin-only sample stream, readers
 * kept to their own uploads, the nightly tidy-up and the pages.
 */
class AudiobookUploadsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        File::deleteDirectory(dirname(AudiobookLibrary::root()));
        File::makeDirectory(AudiobookLibrary::root(), 0775, true);
        File::makeDirectory(AudiobookLibrary::inbox(), 0775, true);
        AudiobookUploads::$freeSpaceUsing = fn() => 1e15;
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->owner()->forceFill(['email' => 'owner@example.test'])->save();
    }

    protected function tearDown(): void
    {
        AudiobookUploads::$freeSpaceUsing = null;
        File::deleteDirectory(dirname(AudiobookLibrary::root()));
        parent::tearDown();
    }

    // --- Fixtures ------------------------------------------------------------

    private function book(string $name = 'The Final Empire', ?string $author = 'Brandon Sanderson'): Novel
    {
        return Novel::create([
            'name' => $name, 'author' => $author, 'status' => 1, 'group_id' => 1,
            'medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1($name),
        ]);
    }

    private function audio(int $ms = 600000, array $tags = ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson', 'composer' => 'Michael Kramer'], string $salt = '', bool $cover = false): string
    {
        return FakeAudioProbe::audioFile($ms, $tags, [
            ['title' => 'Prologue', 'start_ms' => 0, 'end_ms' => 200000],
            ['title' => 'Chapter 1', 'start_ms' => 200000, 'end_ms' => $ms],
        ], $cover, $salt);
    }

    private function reader(string $username = 'sam', string $name = 'Sam'): User
    {
        $user = $this->makeReader($username, $name);
        $user->forceFill(['email' => "{$username}@example.test"])->save();

        return $user;
    }

    /** As $user with a bearer token (sign-in on). */
    private function asReaderToken(User $user): static
    {
        if (!\App\Support\AuthMode::required()) {
            $this->enableSignIn();
        }

        return $this->asToken($this->tokenFor($user));
    }

    private function sendChunk(int $id, int $index, int $n, string $bytes, array $headers = [])
    {
        return $this->call('PUT', "/api/v1/audiobook-uploads/{$id}/files/{$index}/chunks/{$n}", [], [], [],
            $this->transformHeadersToServerVars(['Content-Type' => 'application/octet-stream', 'Accept' => 'application/json'] + $headers), $bytes);
    }

    /** Create an upload of $contents (name => bytes) through the API and send every chunk. */
    private function uploadAll(array $contents, array $extra = []): array
    {
        $files = [];
        foreach ($contents as $name => $bytes) {
            $files[] = ['name' => $name, 'size' => strlen($bytes)];
        }
        $created = $this->postJson('/api/v1/audiobook-uploads', ['files' => $files] + $extra)->assertCreated()->json('upload');
        foreach (array_values($contents) as $index => $bytes) {
            foreach (str_split($bytes, $created['chunk_size']) as $n => $piece) {
                $this->sendChunk($created['id'], $index, $n, $piece)->assertNoContent();
            }
        }

        return $created;
    }

    /** A pending upload by $user (made through the service, as a reader would). */
    private function pendingUpload(User $user, array $contents, ?int $novelId = null, ?string $note = null): AudiobookUpload
    {
        $uploads = app(AudiobookUploads::class);
        $files = [];
        foreach ($contents as $name => $bytes) {
            $files[] = ['name' => $name, 'size' => strlen($bytes)];
        }
        $upload = $uploads->create($user, $files, $novelId, $note);
        foreach (array_values($contents) as $index => $bytes) {
            foreach (str_split($bytes, $upload->chunk_size) as $n => $piece) {
                $uploads->putChunk($upload->fresh(), $index, $n, $piece);
            }
        }
        $this->assertSame(AudiobookUpload::STATUS_PENDING, $uploads->complete($upload->fresh()));

        return $upload->fresh();
    }

    // --- Create: limits --------------------------------------------------------

    public function test_creating_an_upload_answers_its_id_chunk_size_and_files(): void
    {
        $response = $this->postJson('/api/v1/audiobook-uploads', [
            'files' => [['name' => 'Mistborn/CD2/01.mp3', 'size' => 20_000_000], ['name' => 'Mistborn/CD1/01.mp3', 'size' => 5]],
            'note' => 'The Kramer narration.',
        ])->assertCreated();

        $upload = $response->json('upload');
        $this->assertSame(8 * 1048576, $upload['chunk_size']);
        $this->assertSame('uploading', $upload['status']);
        $this->assertSame(20_000_005, $upload['total_bytes']);
        $this->assertSame(0, $upload['received_bytes']);
        $this->assertSame(['index' => 0, 'name' => 'Mistborn/CD2/01.mp3', 'size' => 20_000_000, 'chunks' => 3, 'received' => [], 'done' => false], $upload['files'][0]);
        $this->assertSame(1, $upload['files'][1]['chunks']);
        foreach (['id', 'status', 'status_label', 'title', 'novel_id', 'novel', 'note', 'chunk_size', 'total_bytes', 'received_bytes', 'files', 'error', 'decline_reason', 'audiobook_id', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $upload);
        }

        // The folder is on the audiobooks drive, exactly {root}/_uploads/{id}.
        $row = AudiobookUpload::findOrFail($upload['id']);
        $this->assertSame("_uploads/{$row->id}", $row->dir);
        $this->assertDirectoryExists(AudiobookLibrary::root() . "/_uploads/{$row->id}/parts");
        // On disk the names are flattened and clean (the shared folder dropped).
        $this->assertSame(['CD2 - 01.mp3', 'CD1 - 01.mp3'], array_column($row->files, 'disk_name'));
    }

    public function test_every_limit_is_enforced_by_the_server(): void
    {
        config(['novarr.audiobooks.uploads.max_file_mb' => 10, 'novarr.audiobooks.uploads.max_total_mb' => 15, 'novarr.audiobooks.uploads.max_files' => 3]);
        $mb = 1048576;

        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'big.m4b', 'size' => 11 * $mb]]])
            ->assertStatus(422)->assertJsonPath('code', 'file_too_big');
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 9 * $mb], ['name' => 'b.mp3', 'size' => 9 * $mb]]])
            ->assertStatus(422)->assertJsonPath('code', 'too_big');
        $this->postJson('/api/v1/audiobook-uploads', ['files' => array_fill(0, 4, ['name' => 'a.mp3', 'size' => 10])])
            ->assertStatus(422)->assertJsonPath('code', 'too_many_files');
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'book.epub', 'size' => 10]]])
            ->assertStatus(422)->assertJsonPath('code', 'bad_type');
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'cover.jpg', 'size' => 10]]])
            ->assertStatus(422)->assertJsonPath('code', 'bad_type');
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => '../../etc/passwd', 'size' => 10]]])
            ->assertStatus(422)->assertJsonPath('code', 'bad_type');
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 0]]])->assertStatus(422);
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 10]], 'novel_id' => 999])
            ->assertStatus(422)->assertJsonPath('code', 'bad_book');

        // Free space: this upload + the margin (5 GB) must fit.
        AudiobookUploads::$freeSpaceUsing = fn() => 5.5 * 1073741824;
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 9 * $mb]]])->assertCreated();
        AudiobookUploads::$freeSpaceUsing = fn() => 5.0 * 1073741824 + 5 * $mb;
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 9 * $mb]]])
            ->assertStatus(507)->assertJsonPath('code', 'no_space');

        $this->assertSame(1, AudiobookUpload::count());
    }

    public function test_a_reader_has_at_most_five_open_uploads(): void
    {
        $sam = $this->reader();
        $this->asReaderToken($sam);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => "a{$i}.mp3", 'size' => 10]]])->assertCreated();
        }
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 10]]])
            ->assertStatus(422)->assertJsonPath('code', 'too_many_pending');

        // A decided one no longer counts.
        AudiobookUpload::where('user_id', $sam->id)->first()->forceFill(['status' => AudiobookUpload::STATUS_DECLINED])->save();
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 10]]])->assertCreated();
    }

    public function test_uploads_can_be_turned_off(): void
    {
        config(['novarr.audiobooks.uploads.enabled' => false]);
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 10]]])->assertNotFound()->assertJsonPath('code', 'uploads_off');
        $this->get('/audiobooks/upload')->assertNotFound();
    }

    // --- Chunks ------------------------------------------------------------------

    public function test_chunks_in_any_order_resent_and_resumed_assemble_the_file(): void
    {
        config(['novarr.audiobooks.uploads.chunk_mb' => 16 / 1048576]); // 16-byte chunks
        $bytes = $this->audio(600000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);
        $chunks = str_split($bytes, 16);
        $this->assertGreaterThan(5, count($chunks));

        $upload = $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'The Final Empire.m4b', 'size' => strlen($bytes)]]])->json('upload');
        $this->assertSame(16, $upload['chunk_size']);
        $last = count($chunks) - 1;

        // Out of order, the last one first, one sent twice.
        $this->sendChunk($upload['id'], 0, $last, $chunks[$last])->assertNoContent();
        $this->sendChunk($upload['id'], 0, 2, $chunks[2])->assertNoContent();
        $this->sendChunk($upload['id'], 0, 2, $chunks[2])->assertNoContent();
        $this->sendChunk($upload['id'], 0, 0, $chunks[0], ['Content-Range' => 'bytes 0-15/' . strlen($bytes)])->assertNoContent();

        // Resume: the server says what is in.
        $state = $this->getJson("/api/v1/audiobook-uploads/{$upload['id']}")->assertOk()->json('upload');
        $this->assertSame([0, 2, $last], $state['files'][0]['received']);
        $this->assertSame(32 + strlen($chunks[$last]), $state['received_bytes']);
        // Counted once although chunk 2 came twice.
        $this->assertSame(32 + strlen($chunks[$last]), AudiobookUpload::find($upload['id'])->received_bytes);

        // Completing too early names what is missing.
        $early = $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete")->assertStatus(422);
        $early->assertJsonPath('code', 'incomplete');
        $this->assertSame(array_values(array_diff(range(0, $last), [0, 2, $last])), $early->json('missing.0'));

        foreach ($chunks as $n => $piece) {
            if (!in_array($n, [0, 2, $last], true)) {
                $this->sendChunk($upload['id'], 0, $n, $piece)->assertNoContent();
            }
        }
        $done = $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete", ['files' => [['index' => 0, 'sha256' => hash('sha256', $bytes)]]]);
        $done->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('upload.status', 'pending');
        $done->assertJsonPath('upload.title', 'The Final Empire');
        $done->assertJsonPath('upload.files.0.done', true);

        $row = AudiobookUpload::find($upload['id']);
        $this->assertSame($bytes, file_get_contents($row->directory() . '/book/The Final Empire.m4b'));
        $this->assertSame(hash('sha256', $bytes), $row->files[0]['sha256']);
        $this->assertSame(600000, $row->probe['duration_ms']);
        $this->assertCount(2, $row->probe['chapters']);
        $this->assertDirectoryDoesNotExist($row->directory() . '/chunks');

        // Completing again is harmless; chunks are no longer taken.
        $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete")->assertOk()->assertJsonPath('status', 'pending');
        $this->sendChunk($upload['id'], 0, 0, $chunks[0])->assertStatus(409)->assertJsonPath('code', 'not_uploading');
    }

    public function test_a_chunk_of_the_wrong_size_or_place_is_refused(): void
    {
        config(['novarr.audiobooks.uploads.chunk_mb' => 10 / 1048576]);
        $upload = $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 25]]])->json('upload');

        $this->sendChunk($upload['id'], 0, 0, str_repeat('x', 9))->assertStatus(422)->assertJsonPath('code', 'chunk_size');
        $this->sendChunk($upload['id'], 0, 0, str_repeat('x', 11))->assertStatus(422)->assertJsonPath('code', 'chunk_size');
        $this->sendChunk($upload['id'], 0, 2, str_repeat('x', 10))->assertStatus(422)->assertJsonPath('code', 'chunk_size'); // the last is 5
        $this->sendChunk($upload['id'], 0, 3, 'x')->assertStatus(422)->assertJsonPath('code', 'bad_chunk');
        $this->sendChunk($upload['id'], 1, 0, 'x')->assertStatus(404);
        $this->sendChunk($upload['id'], 0, 1, str_repeat('x', 10), ['Content-Range' => 'bytes 0-9/25'])->assertStatus(422)->assertJsonPath('code', 'bad_range');
        $this->sendChunk($upload['id'], 0, 1, str_repeat('x', 10), ['Content-Range' => 'bytes 10-19/25'])->assertNoContent();
        $this->sendChunk($upload['id'], 0, 2, str_repeat('x', 5))->assertNoContent();

        $this->assertSame([1, 2], $this->getJson("/api/v1/audiobook-uploads/{$upload['id']}")->json('upload.files.0.received'));
    }

    public function test_a_file_whose_checksum_does_not_match_is_sent_again(): void
    {
        $bytes = $this->audio();
        $upload = $this->uploadAll(['The Final Empire.m4b' => $bytes]);

        $bad = $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete", ['files' => [['index' => 0, 'sha256' => str_repeat('a', 64)]]]);
        $bad->assertStatus(422)->assertJsonPath('code', 'checksum')->assertJsonPath('upload.status', 'uploading');
        $this->assertSame([0], $bad->json('missing.0'));
        $this->assertStringContainsString('arrived damaged', $bad->json('message'));

        $this->sendChunk($upload['id'], 0, 0, $bytes)->assertNoContent();
        $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete", ['files' => [['index' => 0, 'sha256' => hash('sha256', $bytes)]]])
            ->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_files_that_are_not_audio_are_refused_and_deleted(): void
    {
        $upload = $this->uploadAll(['01.mp3' => $this->audio(), '02.mp3' => 'not really audio at all']);

        $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete")
            ->assertStatus(422)->assertJsonPath('code', 'not_audio')->assertJsonPath('upload.status', 'failed');
        $row = AudiobookUpload::find($upload['id']);
        $this->assertSame(AudiobookUpload::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('“02.mp3” is not audio', $row->error);
        $this->assertDirectoryDoesNotExist($row->directory());
        Mail::assertNothingSent();
    }

    public function test_a_large_upload_is_checked_by_a_queued_job(): void
    {
        config(['novarr.audiobooks.uploads.inline_mb' => 0]);
        Bus::fake([ProcessAudiobookUpload::class]);
        $upload = $this->uploadAll(['The Final Empire.m4b' => $this->audio()]);

        $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete")
            ->assertStatus(202)->assertJsonPath('status', 'processing')->assertJsonPath('upload.status', 'processing');
        Bus::assertDispatched(ProcessAudiobookUpload::class, fn($job) => $job->uploadId === $upload['id']);
        // Polling meanwhile; completing again is harmless; it cannot be cancelled mid-check.
        $this->getJson("/api/v1/audiobook-uploads/{$upload['id']}")->assertJsonPath('upload.status', 'processing');
        $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete")->assertStatus(202);
        $this->deleteJson("/api/v1/audiobook-uploads/{$upload['id']}")->assertStatus(409);

        (new ProcessAudiobookUpload($upload['id']))->handle(app(AudiobookUploads::class));
        $this->getJson("/api/v1/audiobook-uploads/{$upload['id']}")->assertJsonPath('upload.status', 'pending');
    }

    // --- Readers and their own uploads -----------------------------------------------

    public function test_a_reader_sees_and_changes_only_their_own_uploads(): void
    {
        $sam = $this->reader();
        $kim = $this->reader('kim', 'Kim');
        $this->asReaderToken($sam);
        $upload = $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 4]]])->assertCreated()->json('upload');

        $this->asReaderToken($kim);
        $this->getJson("/api/v1/audiobook-uploads/{$upload['id']}")->assertNotFound();
        $this->sendChunk($upload['id'], 0, 0, 'abcd')->assertNotFound();
        $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete")->assertNotFound();
        $this->deleteJson("/api/v1/audiobook-uploads/{$upload['id']}")->assertNotFound();

        $this->asReaderToken($sam);
        $this->sendChunk($upload['id'], 0, 0, 'abcd')->assertNoContent();
        $this->deleteJson("/api/v1/audiobook-uploads/{$upload['id']}")->assertNoContent();
        $this->assertNull(AudiobookUpload::find($upload['id']));
        $this->assertDirectoryDoesNotExist(AudiobookLibrary::root() . "/_uploads/{$upload['id']}");

        // Signed out: nothing.
        $this->asNobody();
        $this->postJson('/api/v1/audiobook-uploads', ['files' => [['name' => 'a.mp3', 'size' => 4]]])->assertStatus(401);
    }

    public function test_the_routes_are_classified(): void
    {
        foreach (['audiobooks.upload', 'api.v1.audiobook_uploads.store', 'api.v1.audiobook_uploads.show', 'api.v1.audiobook_uploads.chunk', 'api.v1.audiobook_uploads.complete', 'api.v1.audiobook_uploads.destroy'] as $name) {
            $this->assertSame(RouteAccess::READER, RouteAccess::classify(Route::getRoutes()->getByName($name)), $name);
        }
        foreach (['audiobooks.uploads.review', 'audiobooks.uploads.approve', 'audiobooks.uploads.use_existing', 'audiobooks.uploads.decline', 'audiobooks.uploads.sample'] as $name) {
            $this->assertSame(RouteAccess::ADMIN, RouteAccess::classify(Route::getRoutes()->getByName($name)), $name);
            $this->assertTrue(RouteAccess::isListed($name), $name);
        }
    }

    // --- The owner ------------------------------------------------------------------

    public function test_a_finished_upload_tells_the_owner_and_waits_on_needs_you(): void
    {
        $sam = $this->reader();
        $book = $this->book();
        $this->asReaderToken($sam);
        $upload = $this->uploadAll(['The Final Empire.m4b' => $this->audio(600000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson', 'composer' => 'Michael Kramer'], '', true)], ['note' => 'Kramer!']);
        $this->postJson("/api/v1/audiobook-uploads/{$upload['id']}/complete")->assertOk();

        Mail::assertSent(AdminAlertMail::class, fn($m) => $m->hasTo('owner@example.test')
            && $m->subjectLine === 'Audiobook uploaded: The Final Empire'
            && $m->details['button'] === 'Review'
            && $m->details['title'] === 'The Final Empire'
            && str_contains((string) $m->details['cover'], '/storage/audiobook-uploads/')
            && str_ends_with((string) $m->url, "/audiobooks/uploads/{$upload['id']}"));
        // The suggestion: the library's book with this title and author.
        $this->assertSame($book->id, AudiobookUpload::find($upload['id'])->probe['suggested_novel_id']);

        \App\Support\AuthMode::store(false);
        $this->asNobody();
        $this->get('/downloads')->assertOk()->assertSee('Audiobooks to approve')->assertSee('The Final Empire')
            ->assertSee(route('audiobooks.uploads.review', $upload['id'], false));
        $this->assertSame(1, \App\Http\Controllers\DownloadController::attentionTotal());

        $page = $this->get("/audiobooks/uploads/{$upload['id']}")->assertOk();
        $page->assertSee('Uploaded by Sam')->assertSee('Kramer!')->assertSee('read by Michael Kramer')
            ->assertSee('Novarr suggests')->assertSee('id="uploadSample"', false)->assertSee('Prologue');
    }

    public function test_approving_imports_it_links_the_book_and_tells_the_reader(): void
    {
        $sam = $this->reader();
        $book = $this->book();
        // A folder of MP3s without chapters (one per file), given out of order, with its cover picture.
        $upload = $this->pendingUpload($sam, [
            'Mistborn/02.mp3' => FakeAudioProbe::audioFile(200000, ['album' => 'The Final Empire', 'artist' => 'Brandon Sanderson', 'title' => 'Two']),
            'Mistborn/01.mp3' => FakeAudioProbe::audioFile(300000, ['album' => 'The Final Empire', 'artist' => 'Brandon Sanderson', 'title' => 'One']),
            'Mistborn/cover.jpg' => FakeAudioProbe::jpeg(),
        ]);
        $dir = $upload->directory();

        $this->post("/audiobooks/uploads/{$upload->id}/approve", ['novel_id' => $book->id])->assertRedirect();

        $upload->refresh();
        $this->assertSame(AudiobookUpload::STATUS_APPROVED, $upload->status);
        $audiobook = Audiobook::findOrFail($upload->audiobook_id);
        $this->assertSame($book->id, $audiobook->novel_id);
        $this->assertSame(Audiobook::STATUS_READY, $audiobook->status);
        $this->assertSame(500000, $audiobook->duration_ms);
        $this->assertSame(['One', 'Two'], $audiobook->chapters->pluck('title')->all());
        $this->assertNotNull($audiobook->cover_path);
        $this->assertFileExists($audiobook->directory() . '/part-001.mp3');
        $this->assertFileExists($audiobook->directory() . '/part-002.mp3');
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame($this->owner()->id, $upload->decided_by);

        Mail::assertSent(AudiobookUploadAvailable::class, fn($m) => $m->hasTo('sam@example.test')
            && $m->buttonText === 'Listen now' && str_ends_with($m->buttonUrl, "/audiobooks/{$audiobook->id}")
            && $m->subjectLine() === 'Your audiobook is in the library: The Final Empire');

        // The book page has it to listen to now.
        $this->get("/novels/{$book->id}")->assertOk()->assertSee('id="listenBtn"', false);
    }

    public function test_approving_standalone_or_onto_a_book_that_has_one(): void
    {
        $sam = $this->reader();
        $book = $this->book();
        $old = Audiobook::create(['title' => 'Old recording', 'novel_id' => $book->id, 'status' => Audiobook::STATUS_READY, 'dir' => 'x', 'duration_ms' => 1, 'fingerprint' => 'old']);
        $upload = $this->pendingUpload($sam, ['The Final Empire.m4b' => $this->audio()]);

        $this->get("/audiobooks/uploads/{$upload->id}")->assertOk()->assertSee('already has an audiobook')->assertSee('id="approveReplace"', false);
        $this->post("/audiobooks/uploads/{$upload->id}/approve", ['novel_id' => $book->id])->assertSessionHasErrors('approve');
        $this->assertSame(AudiobookUpload::STATUS_PENDING, $upload->fresh()->status);

        $this->post("/audiobooks/uploads/{$upload->id}/approve", ['novel_id' => $book->id, 'replace' => 1])->assertRedirect();
        $new = Audiobook::findOrFail($upload->fresh()->audiobook_id);
        $this->assertSame($book->id, $new->novel_id);
        $old = Audiobook::withTrashed()->find($old->id);
        $this->assertTrue($old->trashed());
        $this->assertNull($old->novel_id);

        // Standalone.
        $other = $this->pendingUpload($sam, ['Something Else.mp3' => $this->audio(1000, ['title' => 'Something Else'], 'b')]);
        $this->post("/audiobooks/uploads/{$other->id}/approve", ['standalone' => 1])->assertRedirect();
        $this->assertNull(Audiobook::find($other->fresh()->audiobook_id)->novel_id);
        $this->post("/audiobooks/uploads/{$other->id}/approve", ['standalone' => 1])->assertSessionHasErrors('approve');
    }

    public function test_the_same_files_as_an_audiobook_in_the_library_are_flagged_and_can_be_closed_as_it(): void
    {
        $sam = $this->reader();
        $bytes = $this->audio();
        // Already imported from the inbox.
        file_put_contents(AudiobookLibrary::inbox() . '/The Final Empire.m4b', $bytes);
        config(['novarr.audiobooks.settle_seconds' => 0]);
        app(\App\Audiobooks\AudiobookInbox::class)->scan();
        $existing = Audiobook::sole();

        $upload = $this->pendingUpload($sam, ['The Final Empire.m4b' => $bytes]);
        $this->assertSame($existing->id, $upload->probe['duplicate_of']);
        $this->get("/audiobooks/uploads/{$upload->id}")->assertOk()->assertSee('id="uploadDuplicate"', false)->assertSee('Use the existing one');
        // Approving it would import it twice: refused with the importer's reason.
        $this->post("/audiobooks/uploads/{$upload->id}/approve", ['standalone' => 1])->assertSessionHasErrors('approve');

        $this->post("/audiobooks/uploads/{$upload->id}/use-existing", ['audiobook_id' => $existing->id])->assertRedirect();
        $upload->refresh();
        $this->assertSame(AudiobookUpload::STATUS_APPROVED, $upload->status);
        $this->assertSame($existing->id, $upload->audiobook_id);
        $this->assertDirectoryDoesNotExist($upload->directory());
        $this->assertSame(1, Audiobook::count());
        Mail::assertSent(AudiobookUploadAvailable::class, fn($m) => $m->hasTo('sam@example.test'));
    }

    public function test_declining_deletes_the_files_and_tells_the_reader_why(): void
    {
        $sam = $this->reader();
        $upload = $this->pendingUpload($sam, ['The Final Empire.m4b' => $this->audio(600000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson'], '', true)]);
        $cover = $upload->probe['cover_path'];
        Storage::disk('public')->assertExists($cover);

        $this->post("/audiobooks/uploads/{$upload->id}/decline", ['reason' => 'The sound is too poor.'])->assertRedirect();

        $upload->refresh();
        $this->assertSame(AudiobookUpload::STATUS_DECLINED, $upload->status);
        $this->assertSame('The sound is too poor.', $upload->decline_reason);
        $this->assertDirectoryDoesNotExist($upload->directory());
        Storage::disk('public')->assertMissing($cover);
        $this->assertSame(0, Audiobook::count());
        Mail::assertSent(AudiobookUploadDeclined::class, fn($m) => $m->hasTo('sam@example.test') && $m->quote === 'The sound is too poor.');
    }

    public function test_the_sample_streams_with_range_for_the_owner_only(): void
    {
        $sam = $this->reader();
        $bytes = $this->audio();
        $upload = $this->pendingUpload($sam, ['The Final Empire.m4b' => $bytes]);

        $whole = $this->get("/audiobooks/uploads/{$upload->id}/sample/0");
        $whole->assertOk();
        $this->assertSame('bytes', $whole->headers->get('Accept-Ranges'));
        $this->assertSame('audio/mp4', $whole->headers->get('Content-Type'));
        $range = $this->get("/audiobooks/uploads/{$upload->id}/sample/0", ['Range' => 'bytes=2-9']);
        $range->assertStatus(206);
        $this->assertSame(substr($bytes, 2, 8), $range->streamedContent());
        $this->get("/audiobooks/uploads/{$upload->id}/sample/5")->assertNotFound();

        // A reader (even the uploader) is refused the owner's pages.
        $this->enableSignIn();
        $this->asSignedIn($sam);
        $this->get("/audiobooks/uploads/{$upload->id}/sample/0")->assertForbidden();
        $this->get("/audiobooks/uploads/{$upload->id}")->assertForbidden();
        $this->post("/audiobooks/uploads/{$upload->id}/decline", [])->assertForbidden();
        $this->assertSame(AudiobookUpload::STATUS_PENDING, $upload->fresh()->status);
    }

    // --- Tidy-up --------------------------------------------------------------------

    public function test_the_nightly_tidy_up_deletes_abandoned_and_declined_uploads_never_pending_ones(): void
    {
        $sam = $this->reader();
        $uploads = app(AudiobookUploads::class);
        $abandoned = $uploads->create($sam, [['name' => 'a.mp3', 'size' => 10]]);
        $uploads->putChunk($abandoned, 0, 0, str_repeat('x', 10));
        $recent = $uploads->create($sam, [['name' => 'b.mp3', 'size' => 10]]);
        $pending = $this->pendingUpload($sam, ['c.mp3' => $this->audio()]);
        $declined = $this->pendingUpload($sam, ['d.mp3' => $this->audio(1000, [], 'd')]);
        $declined->forceFill(['status' => AudiobookUpload::STATUS_DECLINED])->save(); // its files left behind
        AudiobookUpload::whereKey([$abandoned->id, $pending->id])->update(['updated_at' => now()->subHours(49)]);
        File::makeDirectory(AudiobookUpload::uploadsRoot() . '/999', 0775, true);

        $dry = $uploads->tidy(true);
        $this->assertCount(3, $dry);
        $this->assertDirectoryExists($abandoned->directory());

        $this->artisan('downloads:tidy')->assertSuccessful();

        $this->assertNull(AudiobookUpload::find($abandoned->id));
        $this->assertDirectoryDoesNotExist($abandoned->directory());
        $this->assertDirectoryExists($recent->directory());
        $this->assertDirectoryExists($pending->directory());
        $this->assertSame(AudiobookUpload::STATUS_PENDING, $pending->fresh()->status);
        $this->assertFileExists($pending->directory() . '/book/c.mp3');
        $this->assertNotNull(AudiobookUpload::find($declined->id));
        $this->assertDirectoryDoesNotExist($declined->directory());
        $this->assertDirectoryDoesNotExist(AudiobookUpload::uploadsRoot() . '/999');
    }

    // --- Pages ----------------------------------------------------------------------

    public function test_the_upload_page_the_book_button_and_my_requests(): void
    {
        $sam = $this->reader();
        $book = $this->book();
        $this->enableSignIn();
        $this->asSignedIn($sam);

        $this->get('/audiobooks/upload?novel_id=' . $book->id)->assertOk()
            ->assertSee('Upload an audiobook')->assertSee('The Final Empire')->assertSee('id="audiobookUpload"', false)
            ->assertSee('webkitdirectory', false)->assertSee('"novel_id":' . $book->id, false);
        $this->get("/novels/{$book->id}")->assertOk()->assertSee('id="uploadAudiobookBtn"', false)
            ->assertSee(route('audiobooks.upload', ['novel_id' => $book->id], false), false);
        $this->get('/audiobooks')->assertOk()->assertSee('id="uploadAudiobookLink"', false);

        $upload = $this->pendingUpload($sam, ['The Final Empire.m4b' => $this->audio()]);
        $declined = $this->pendingUpload($sam, ['Other.m4b' => $this->audio(1000, ['title' => 'Other Book'], 'x')]);
        $declined->forceFill(['status' => AudiobookUpload::STATUS_DECLINED, 'decline_reason' => 'Wrong book.'])->save();

        $page = $this->get('/requests')->assertOk();
        $page->assertSee('Audiobooks you uploaded')->assertSee('Waiting for the owner')->assertSee('Declined')->assertSee('Reason: Wrong book.')
            ->assertSee('data-upload-cancel="' . $upload->id . '"', false);
    }
}
