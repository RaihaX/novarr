<?php

namespace Tests\Feature;

use App\Audiobook;
use App\AudiobookBookmark;
use App\Audiobooks\AudiobookInbox;
use App\Audiobooks\AudiobookLibrary;
use App\Audiobooks\AudiobookMatcher;
use App\Audiobooks\AudioProbe;
use App\Audiobooks\FfmpegProbe;
use App\Audiobooks\ProbeFailed;
use App\Jobs\ScanAudiobooks;
use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAudioProbe;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Audiobooks (App\Audiobooks, the audiobooks contract v1): the inbox scan
 * (single files, folders, still-copying, failures, duplicates), matching
 * to a book, the API's shapes, progress (last write wins, device stamps),
 * bookmarks, streaming (Range, signed URLs, refusals), the `audiobook` on
 * novel payloads and the web pages. The files are fake audio (their bytes
 * are the probe's answer: Tests\Support\FakeAudioProbe); sign-in is off
 * (every call is the owner) unless a test turns it on.
 */
class AudiobooksTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const SUMMARY_KEYS = ['id', 'novel_id', 'title', 'author', 'narrator', 'cover_url', 'duration_ms', 'progress', 'series', 'series_index'];

    private const DETAIL_KEYS = [
        'id', 'novel_id', 'title', 'author', 'narrator', 'cover_url', 'duration_ms', 'progress',
        'description', 'year', 'size_bytes', 'parts', 'chapters', 'bookmarks', 'series', 'series_index', 'asin',
    ];

    private const PART_KEYS = ['index', 'url', 'mime', 'duration_ms', 'start_ms', 'size_bytes'];

    private const CHAPTER_KEYS = ['index', 'title', 'start_ms', 'end_ms'];

    private const BOOKMARK_KEYS = ['id', 'position_ms', 'note', 'created_at'];

    private const PROGRESS_KEYS = ['position_ms', 'finished', 'updated_at'];

    private FakeAudioProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::put('dashboard_attention', [], 900);
        File::deleteDirectory(dirname(AudiobookLibrary::inbox()));
        File::makeDirectory(AudiobookLibrary::inbox(), 0775, true);
        config(['novarr.audiobooks.settle_seconds' => 0]);
        $this->probe = app(AudioProbe::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname(AudiobookLibrary::inbox()));
        parent::tearDown();
    }

    // --- Fixtures ------------------------------------------------------------

    private function drop(string $relative, string $bytes): string
    {
        $path = AudiobookLibrary::inbox() . '/' . $relative;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $bytes);

        return $path;
    }

    private function book(string $name, ?string $author = 'Brandon Sanderson'): Novel
    {
        return Novel::create([
            'name' => $name, 'author' => $author, 'status' => 1, 'group_id' => 1,
            'medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1($name),
        ]);
    }

    private function scan(): array
    {
        return app(AudiobookInbox::class)->scan();
    }

    /** A ready audiobook in the library, made through the real import (one m4b with chapters). */
    private function imported(string $title = 'The Final Empire', ?string $author = 'Brandon Sanderson', string $salt = ''): Audiobook
    {
        $this->drop("{$title}{$salt}.m4b", FakeAudioProbe::audioFile(600000, ['title' => $title, 'artist' => $author, 'composer' => 'Michael Kramer'], [
            ['title' => 'Prologue', 'start_ms' => 0, 'end_ms' => 200000],
            ['title' => 'Chapter 1', 'start_ms' => 200000, 'end_ms' => 600000],
        ], false, $salt));
        $this->scan();

        return Audiobook::where('source_name', "{$title}{$salt}.m4b")->sole();
    }

    // --- Scanning ------------------------------------------------------------

    public function test_a_single_m4b_with_chapters_is_imported_moved_and_matched(): void
    {
        $book = $this->book('The Final Empire');
        $this->drop('Mistborn 1 - The Final Empire (Unabridged).m4b', FakeAudioProbe::audioFile(3600000, [
            'title' => 'The Final Empire (Unabridged)', 'album' => 'The Final Empire (Unabridged)', 'artist' => 'Brandon Sanderson',
            'composer' => 'Michael Kramer', 'date' => '2006-07-17', 'comment' => 'The first book of the Mistborn trilogy, read in full.',
        ], [
            ['title' => 'Prologue', 'start_ms' => 0, 'end_ms' => 600000],
            ['title' => 'Chapter 1', 'start_ms' => 600000, 'end_ms' => 2000000],
            ['title' => null, 'start_ms' => 2000000, 'end_ms' => 3600000],
        ], true));

        $counts = $this->scan();

        $this->assertSame(['imported' => 1, 'failed' => 0, 'waiting' => 0, 'problem' => null, 'busy' => false], $counts);
        $audiobook = Audiobook::sole();
        $this->assertSame('The Final Empire', $audiobook->title);
        $this->assertSame('Brandon Sanderson', $audiobook->author);
        $this->assertSame('Michael Kramer', $audiobook->narrator);
        $this->assertSame(2006, $audiobook->year);
        $this->assertSame(3600000, $audiobook->duration_ms);
        $this->assertSame(Audiobook::STATUS_READY, $audiobook->status);
        $this->assertSame($book->id, $audiobook->novel_id);
        $this->assertNull($audiobook->match_note);
        $this->assertSame((string) $audiobook->id, $audiobook->dir);
        $this->assertNotNull($audiobook->added_at);
        $this->assertSame(40, strlen($audiobook->fingerprint));

        // Moved (not copied) into {root}/{id}/part-001.m4b; the inbox is empty.
        $this->assertFileExists(AudiobookLibrary::root() . "/{$audiobook->id}/part-001.m4b");
        $this->assertSame([AudiobookInbox::FAILED_DIR], array_values(array_diff(scandir(AudiobookLibrary::inbox()), ['.', '..'])));

        $part = $audiobook->parts()->sole();
        $this->assertSame(['part-001.m4b', 'audio/mp4', 0, 3600000], [$part->path, $part->mime, $part->start_ms, $part->duration_ms]);
        $this->assertSame((int) filesize(AudiobookLibrary::root() . "/{$audiobook->id}/part-001.m4b"), $part->size_bytes);

        $this->assertSame([
            ['Prologue', 0, 600000],
            ['Chapter 1', 600000, 2000000],
            ['Chapter 3', 2000000, 3600000],
        ], $audiobook->chapters->map(fn($c) => [$c->title, $c->start_ms, $c->end_ms])->all());

        // The embedded picture is its cover, with thumbnails, on the public disk.
        $this->assertNotNull($audiobook->cover_path);
        Storage::disk('public')->assertExists($audiobook->cover_path);
        Storage::disk('public')->assertExists('covers/md/' . pathinfo($audiobook->cover_path, PATHINFO_FILENAME) . '.jpg');
        // The API's cover is the thumbnail, absolute on the request's origin.
        $this->assertSame(
            'http://localhost/storage/covers/md/' . pathinfo($audiobook->cover_path, PATHINFO_FILENAME) . '.jpg',
            $this->getJson("/api/v1/audiobooks/{$audiobook->id}")->json('audiobook.cover_url')
        );
    }

    public function test_a_folder_of_mp3s_is_one_audiobook_with_a_chapter_per_file_in_natural_order(): void
    {
        $tags = fn(string $title) => ['album' => 'Words of Radiance', 'artist' => 'Brandon Sanderson', 'title' => $title];
        $this->drop('Words of Radiance/CD2/01.mp3', FakeAudioProbe::audioFile(30000, $tags('Disc two')));
        $this->drop('Words of Radiance/CD1/10 - Ten.mp3', FakeAudioProbe::audioFile(20000, ['album' => 'Words of Radiance', 'artist' => 'Brandon Sanderson']));
        $this->drop('Words of Radiance/CD1/2 - Two.mp3', FakeAudioProbe::audioFile(10000, $tags('Second')));
        $this->drop('Words of Radiance/cover.jpg', FakeAudioProbe::jpeg());
        $this->drop('Words of Radiance/info.nfo', 'release notes');

        $this->scan();

        $audiobook = Audiobook::sole();
        $this->assertSame('Words of Radiance', $audiobook->title);
        $this->assertSame(60000, $audiobook->duration_ms);
        // Natural order of the paths: CD1/2, CD1/10, CD2/01.
        $this->assertSame([
            [0, 'part-001.mp3', 0, 10000],
            [1, 'part-002.mp3', 10000, 20000],
            [2, 'part-003.mp3', 30000, 30000],
        ], $audiobook->parts->map(fn($p) => [$p->idx, $p->path, $p->start_ms, $p->duration_ms])->all());
        $this->assertSame('audio/mpeg', $audiobook->parts->first()->mime);
        // One chapter per file: its title tag, else its file name.
        $this->assertSame([
            ['Second', 0, 10000],
            ['10 - Ten', 10000, 30000],
            ['Disc two', 30000, 60000],
        ], $audiobook->chapters->map(fn($c) => [$c->title, $c->start_ms, $c->end_ms])->all());

        // The folder's cover.jpg is the cover; everything else went along to extras/.
        $this->assertNotNull($audiobook->cover_path);
        $dir = AudiobookLibrary::root() . '/' . $audiobook->id;
        $this->assertFileExists("{$dir}/extras/cover.jpg");
        $this->assertFileExists("{$dir}/extras/info.nfo");
        $this->assertDirectoryDoesNotExist(AudiobookLibrary::inbox() . '/Words of Radiance');
        // No book of that name: unmatched, with why.
        $this->assertSame(Audiobook::STATUS_UNMATCHED, $audiobook->status);
        $this->assertSame(AudiobookMatcher::NOTE_NO_BOOK, $audiobook->match_note);
    }

    public function test_a_single_file_without_chapters_is_one_chapter_called_part_1(): void
    {
        $this->drop('short_story.mp3', FakeAudioProbe::audioFile(90000));

        $this->scan();

        $audiobook = Audiobook::sole();
        $this->assertSame('short story', $audiobook->title);
        $this->assertSame([['Part 1', 0, 90000]], $audiobook->chapters->map(fn($c) => [$c->title, $c->start_ms, $c->end_ms])->all());
        $this->assertNull($audiobook->cover_path);
    }

    public function test_an_entry_still_being_copied_waits_until_it_has_settled(): void
    {
        config(['novarr.audiobooks.settle_seconds' => 120]);
        $path = $this->drop('Growing/part1.mp3', FakeAudioProbe::audioFile(1000));

        // First sight: settling.
        $this->assertSame(1, $this->scan()['waiting']);
        $this->assertSame(AudiobookInbox::SETTLING, app(AudiobookInbox::class)->status()['pending'][0]['state']);

        // A minute later another file arrives: it changed, so it starts over.
        $this->travel(61)->seconds();
        $this->drop('Growing/part2.mp3', FakeAudioProbe::audioFile(1000, [], [], false, 'b'));
        $this->assertSame(1, $this->scan()['waiting']);
        $this->travel(119)->seconds();
        $this->assertSame(1, $this->scan()['waiting']);
        $this->assertSame(0, Audiobook::count());

        // Two minutes unchanged: imported.
        $this->travel(2)->seconds();
        $this->assertSame(1, $this->scan()['imported']);
        $this->assertSame(2, Audiobook::sole()->parts()->count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_download_leftovers_keep_an_entry_waiting(): void
    {
        $this->drop('Downloading/part1.mp3', FakeAudioProbe::audioFile(1000));
        $this->drop('Downloading/part2.mp3.part', 'half');
        $this->drop('loose.m4b.crdownload', 'half');

        $counts = $this->scan();

        $this->assertSame(2, $counts['waiting']);
        $this->assertSame(0, Audiobook::count());
        $this->assertSame([AudiobookInbox::COPYING, AudiobookInbox::COPYING], array_column(app(AudiobookInbox::class)->status()['pending'], 'state'));
    }

    public function test_an_unreadable_entry_goes_to_failed_with_its_reason_and_is_never_deleted(): void
    {
        $this->drop('Broken/01.mp3', FakeAudioProbe::audioFile(1000));
        $this->drop('Broken/02.mp3', 'not really audio');
        $this->drop('notes.txt', 'hello');

        $counts = $this->scan();

        $this->assertSame(2, $counts['failed']);
        $this->assertSame(0, Audiobook::count());
        $failed = AudiobookLibrary::failedDir();
        $this->assertFileExists("{$failed}/Broken/01.mp3");
        $this->assertFileExists("{$failed}/Broken/02.mp3");
        $this->assertFileExists("{$failed}/notes.txt");
        $this->assertStringContainsString('“02.mp3”', file_get_contents("{$failed}/Broken.reason.txt"));
        $this->assertStringContainsString('not an audio file', file_get_contents("{$failed}/notes.txt.reason.txt"));
        // Nothing was half-imported.
        $this->assertSame([], glob(AudiobookLibrary::root() . '/*/part-*') ?: []);

        $list = app(AudiobookInbox::class)->failed();
        $this->assertEqualsCanonicalizing(['Broken', 'notes.txt'], array_column($list, 'name'));
        $broken = collect($list)->firstWhere('name', 'Broken');
        $this->assertStringContainsString('could not be read', $broken['reason']);
        $this->assertSame('folder', $broken['kind']);
    }

    public function test_the_same_files_dropped_in_again_are_recognised(): void
    {
        $bytes = FakeAudioProbe::audioFile(5000, ['title' => 'Once']);
        $this->drop('Once.m4b', $bytes);
        $this->scan();
        $first = Audiobook::sole();

        // The same file under another name.
        $this->drop('Once again.m4b', $bytes);
        $counts = $this->scan();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, Audiobook::count());
        $reason = file_get_contents(AudiobookLibrary::failedDir() . '/Once again.m4b.reason.txt');
        $this->assertStringContainsString("already in the library as “Once” (#{$first->id})", $reason);
    }

    public function test_without_ffprobe_nothing_is_touched(): void
    {
        $this->probe->problem = 'ffprobe was not found (ffprobe): install ffmpeg on the server, or set NOVARR_FFPROBE to its path.';
        $path = $this->drop('Waiting.m4b', FakeAudioProbe::audioFile(1000));

        $this->artisan('audiobooks:scan')->expectsOutputToContain('ffprobe was not found')->assertFailed();

        $this->assertFileExists($path);
        $this->assertSame(0, Audiobook::count());
        $this->assertSame([], $this->probe->probed);
    }

    public function test_the_command_scans_one_entry_and_has_a_dry_run(): void
    {
        $this->drop('One.m4b', FakeAudioProbe::audioFile(1000, [], [], false, '1'));
        $this->drop('Two.m4b', FakeAudioProbe::audioFile(1000, [], [], false, '2'));

        $this->artisan('audiobooks:scan --dry-run')->expectsOutputToContain('would_import')->assertSuccessful();
        $this->assertSame(0, Audiobook::count());
        $this->assertFileExists(AudiobookLibrary::inbox() . '/One.m4b');

        $this->artisan('audiobooks:scan', ['--path' => AudiobookLibrary::inbox() . '/Two.m4b'])->expectsOutputToContain('Imported 1')->assertSuccessful();
        $this->assertSame(['Two.m4b'], Audiobook::pluck('source_name')->all());
        $this->assertFileExists(AudiobookLibrary::inbox() . '/One.m4b');

        $this->artisan('audiobooks:scan', ['--path' => 'Nope.m4b'])->expectsOutputToContain('Nothing called “Nope.m4b”')->assertFailed();
        $this->artisan('audiobooks:scan', ['--path' => '/etc/passwd'])->assertFailed();
    }

    public function test_retry_puts_a_failed_entry_back_for_the_next_scan(): void
    {
        $this->drop('Later.mp3', 'garbage');
        $this->scan();
        $this->assertFileExists(AudiobookLibrary::failedDir() . '/Later.mp3');

        // Fixed by the owner, then Retry.
        file_put_contents(AudiobookLibrary::failedDir() . '/Later.mp3', FakeAudioProbe::audioFile(2000));
        $this->post(route('audiobooks.manage.retry'), ['name' => 'Later.mp3'])->assertRedirect(route('audiobooks.manage'));
        $this->assertFileExists(AudiobookLibrary::inbox() . '/Later.mp3');
        $this->assertFileDoesNotExist(AudiobookLibrary::failedDir() . '/Later.mp3.reason.txt');

        config(['novarr.audiobooks.settle_seconds' => 120]);
        $this->assertSame(1, $this->scan()['imported']); // counted as settled already

        // Only names in the failed folder.
        $this->assertSame('That is not an entry of the failed folder.', app(AudiobookInbox::class)->retry('../x'));
        $this->assertNotNull(app(AudiobookInbox::class)->retry('missing.mp3'));
    }

    // --- Matching ------------------------------------------------------------

    public function test_titles_are_cleaned_of_what_audiobook_releases_add(): void
    {
        $this->assertSame('Mistborn', AudiobookMatcher::cleanTitle('Mistborn [Dramatized] (Unabridged) - Audiobook, read by Michael Kramer'));
        $this->assertSame('The Way of Kings', AudiobookMatcher::cleanTitle('The_Way_of_Kings (Unabridged)'));
        $this->assertSame('Dune', AudiobookMatcher::cleanTitle('Dune: An Audiobook'));
        $this->assertSame('Dune', AudiobookMatcher::cleanTitle('Dune Audiobook'));
        $this->assertSame('Dune', AudiobookMatcher::cleanTitle('Dune (narrated by Scott Brick)'));
        $this->assertSame('The Audiobook Murders', AudiobookMatcher::cleanTitle('The Audiobook Murders'));
        $this->assertSame('Project Hail Mary', AudiobookMatcher::cleanTitle('Project Hail Mary Unabridged'));
    }

    public function test_matching_needs_the_title_and_an_agreeing_author(): void
    {
        $book = $this->book('Elantris', 'Brandon Sanderson');
        $other = $this->book('Warbreaker', null);

        // Title and an author agree (names compared as BookLibrary::existing() does): linked.
        $this->drop('elantris.m4b', FakeAudioProbe::audioFile(1000, ['title' => 'Elantris (Unabridged)', 'album_artist' => 'Brandon Sanderson, Jack Garrett'], [], false, 'a'));
        // The book has no author to compare: unmatched.
        $this->drop('warbreaker.m4b', FakeAudioProbe::audioFile(1000, ['title' => 'Warbreaker', 'artist' => 'Brandon Sanderson'], [], false, 'b'));
        // A different author: not this book.
        $this->drop('elantris-other.m4b', FakeAudioProbe::audioFile(1000, ['title' => 'Elantris', 'artist' => 'Someone Else'], [], false, 'c'));
        $this->scan();

        $this->assertSame($book->id, Audiobook::where('source_name', 'elantris.m4b')->value('novel_id'));
        $warbreaker = Audiobook::where('source_name', 'warbreaker.m4b')->sole();
        $this->assertNull($warbreaker->novel_id);
        $this->assertSame(AudiobookMatcher::NOTE_NO_AUTHOR, $warbreaker->match_note);
        $this->assertNull(Audiobook::where('source_name', 'elantris-other.m4b')->value('novel_id'));
        $this->assertNotNull($other);
    }

    public function test_a_book_that_already_has_an_audiobook_leaves_the_second_unmatched(): void
    {
        $book = $this->book('The Final Empire');
        $first = $this->imported('The Final Empire', 'Brandon Sanderson', ' (1)');
        $second = $this->imported('The Final Empire', 'Brandon Sanderson', ' (2)');

        $this->assertSame($book->id, $first->novel_id);
        $this->assertNull($second->novel_id);
        $this->assertSame(Audiobook::STATUS_UNMATCHED, $second->status);
        $this->assertSame(AudiobookMatcher::NOTE_ALREADY_LINKED, $second->match_note);
    }

    public function test_an_author_dash_title_folder_without_tags_is_matched(): void
    {
        $book = $this->book('The Hobbit', 'J. R. R. Tolkien');
        $this->drop('J. R. R. Tolkien - The Hobbit/01.mp3', FakeAudioProbe::audioFile(1000));
        $this->scan();

        $audiobook = Audiobook::sole();
        $this->assertSame($book->id, $audiobook->novel_id);
        // Its own title and author read from the name.
        $this->assertSame(['The Hobbit', 'J. R. R. Tolkien'], [$audiobook->title, $audiobook->author]);
    }

    public function test_a_book_moved_to_novels_is_matched_too(): void
    {
        $book = Novel::create(['name' => 'Cradle', 'author' => 'Will Wight', 'status' => 1, 'group_id' => 1, 'medium' => Novel::MEDIUM_NOVEL, 'translator_url' => 'book://library/cradle']);
        $this->drop('Cradle.m4b', FakeAudioProbe::audioFile(1000, ['album' => 'Cradle', 'artist' => 'Will Wight']));
        $this->scan();

        $this->assertSame($book->id, Audiobook::sole()->novel_id);
    }

    public function test_the_owner_matches_an_audiobook_through_the_api(): void
    {
        $audiobook = $this->imported('Unknown Thing', null);
        $this->assertSame(Audiobook::STATUS_UNMATCHED, $audiobook->status);
        $book = $this->book('The Real Title');

        $json = $this->postJson("/api/v1/audiobooks/{$audiobook->id}/match", ['novel_id' => $book->id])->assertOk()->json('audiobook');
        $this->assertSame(self::SUMMARY_KEYS, array_keys($json));
        $this->assertSame($book->id, $json['novel_id']);
        $this->assertSame('The Real Title', $json['title']); // the book's
        $this->assertSame([$book->id, Audiobook::STATUS_READY], [$audiobook->fresh()->novel_id, $audiobook->fresh()->status]);

        // Another audiobook cannot take the same book.
        $other = $this->imported('Other Thing', null, 'x');
        $this->postJson("/api/v1/audiobooks/{$other->id}/match", ['novel_id' => $book->id])->assertStatus(422)
            ->assertJsonPath('message', 'That book already has an audiobook (“Unknown Thing”).');
        // Not a book.
        $novel = Novel::create(['name' => 'A Web Novel', 'status' => 0, 'group_id' => 1]);
        $this->postJson("/api/v1/audiobooks/{$other->id}/match", ['novel_id' => $novel->id])->assertStatus(422);
        // Standalone.
        $this->postJson("/api/v1/audiobooks/{$other->id}/match", ['novel_id' => null])->assertOk()->assertJsonPath('audiobook.novel_id', null);
        $this->assertSame(Audiobook::STATUS_READY, $other->fresh()->status);
        // novel_id must be sent.
        $this->postJson("/api/v1/audiobooks/{$other->id}/match", [])->assertStatus(422);
        $this->postJson('/api/v1/audiobooks/999/match', ['novel_id' => null])->assertNotFound();
    }

    public function test_admin_routes_are_the_owners(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $audiobook = $this->imported();
        $token = $this->tokenFor($reader);

        $this->asToken($token)->getJson('/api/v1/audiobooks/inbox')->assertForbidden();
        $this->asToken($token)->postJson('/api/v1/audiobooks/scan')->assertForbidden();
        $this->asToken($token)->postJson("/api/v1/audiobooks/{$audiobook->id}/match", ['novel_id' => null])->assertForbidden();
        $this->asToken($token)->getJson('/api/v1/audiobooks')->assertOk();
        $this->asSignedIn($reader)->get(route('audiobooks.manage'))->assertForbidden();
    }

    // --- The owner's page and inbox API -------------------------------------

    public function test_the_inbox_api_lists_waiting_and_failed_entries(): void
    {
        config(['novarr.audiobooks.settle_seconds' => 120]);
        $this->drop('Waiting/01.mp3', FakeAudioProbe::audioFile(1000));
        $this->drop('_failed/Bad.mp3', 'x');
        file_put_contents(AudiobookLibrary::failedDir() . '/Bad.mp3.reason.txt', "Not imported on Monday:\nThe audio could not be read.\n");

        $json = $this->getJson('/api/v1/audiobooks/inbox')->assertOk()->json();

        $this->assertSame(['pending', 'failed', 'settle_seconds', 'last_scan', 'problem', 'unmatched_count'], array_keys($json));
        $this->assertSame([['name' => 'Waiting', 'kind' => 'folder', 'audio_files' => 1, 'size_bytes' => strlen(FakeAudioProbe::audioFile(1000)), 'state' => 'settling', 'stable_seconds' => 0]], $json['pending']);
        $this->assertSame('Bad.mp3', $json['failed'][0]['name']);
        $this->assertSame('The audio could not be read.', $json['failed'][0]['reason']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['failed'][0]['failed_at']);
        $this->assertSame(120, $json['settle_seconds']);
    }

    public function test_scan_now_is_queued(): void
    {
        Bus::fake();

        $this->postJson('/api/v1/audiobooks/scan')->assertStatus(202)->assertJsonPath('queued', true);
        $this->post(route('audiobooks.manage.scan'))->assertRedirect(route('audiobooks.manage'));

        Bus::assertDispatchedTimes(ScanAudiobooks::class, 2);
    }

    public function test_the_manage_page_shows_the_inbox_and_matches_with_a_book_search(): void
    {
        $book = $this->book('Oathbringer');
        $audiobook = $this->imported('Oathbringer', null);
        $this->drop('_failed/Bad.mp3', 'x');
        file_put_contents(AudiobookLibrary::failedDir() . '/Bad.mp3.reason.txt', "Not imported:\nIt has no audio in it.\n");
        config(['novarr.audiobooks.settle_seconds' => 120]);
        $this->drop('Arriving.m4b', FakeAudioProbe::audioFile(1000, [], [], false, 'z'));

        $this->get(route('audiobooks.manage'))->assertOk()
            ->assertSee('Manage audiobooks')
            ->assertSee('Arriving.m4b')
            ->assertSee('Settling')
            ->assertSee('It has no audio in it.')
            ->assertSee('Unmatched')
            ->assertSee(ucfirst(AudiobookMatcher::NOTE_NO_AUTHOR));

        // The search for it is prefilled with its title and finds the book.
        $this->get(route('audiobooks.manage', ['for' => $audiobook->id]))->assertOk()
            ->assertSee('Which book is “Oathbringer”?', false)
            ->assertSee('It’s this one', false);

        $this->post(route('audiobooks.manage.match', $audiobook->id), ['novel_id' => $book->id])->assertRedirect(route('audiobooks.manage'));
        $this->assertSame($book->id, $audiobook->fresh()->novel_id);
        $this->post(route('audiobooks.manage.match', $audiobook->id), ['standalone' => 1])->assertRedirect(route('audiobooks.manage'));
        $this->assertNull($audiobook->fresh()->novel_id);
        $this->post(route('audiobooks.manage.match', $audiobook->id), [])->assertRedirect(route('audiobooks.manage', ['for' => $audiobook->id]));

        // Linked from the Health page and the Books library.
        $this->get(route('health.index'))->assertSee(route('audiobooks.manage'), false);
        $this->get(route('novels.index', ['medium' => 'book']))->assertSee(route('audiobooks.manage'), false)->assertSee(route('audiobooks.index'), false);
    }

    // --- API shapes ----------------------------------------------------------

    public function test_the_list_detail_and_continue_shapes(): void
    {
        $book = $this->book('The Final Empire');
        $audiobook = $this->imported();
        $standalone = $this->imported('Lone Story', null, 's');

        $json = $this->getJson('/api/v1/audiobooks')->assertOk()->json();
        $this->assertSame(['audiobooks', 'page', 'has_more'], array_keys($json));
        $this->assertSame([1, false], [$json['page'], $json['has_more']]);
        $this->assertCount(2, $json['audiobooks']);
        foreach ($json['audiobooks'] as $summary) {
            $this->assertSame(self::SUMMARY_KEYS, array_keys($summary));
        }
        // By title; a matched one reads as its book.
        $this->assertSame(['Lone Story', 'The Final Empire'], array_column($json['audiobooks'], 'title'));
        $this->assertSame([null, $book->id], array_column($json['audiobooks'], 'novel_id'));
        $this->assertSame([null, 'Brandon Sanderson'], array_column($json['audiobooks'], 'author'));
        $this->assertSame('Michael Kramer', $json['audiobooks'][1]['narrator']);
        $this->assertSame(600000, $json['audiobooks'][1]['duration_ms']);
        $this->assertNull($json['audiobooks'][1]['progress']);

        // search and status filter.
        $this->assertSame(['Lone Story'], array_column($this->getJson('/api/v1/audiobooks?search=lone')->json('audiobooks'), 'title'));
        $this->assertSame(['Lone Story'], array_column($this->getJson('/api/v1/audiobooks?status=unmatched')->json('audiobooks'), 'title'));
        $this->getJson('/api/v1/audiobooks?status=nope')->assertStatus(422);
        // A soft-deleted (removed) one is gone for readers.
        $standalone->delete();
        $this->assertSame(['The Final Empire'], array_column($this->getJson('/api/v1/audiobooks')->json('audiobooks'), 'title'));
        $this->getJson("/api/v1/audiobooks/{$standalone->id}")->assertNotFound();

        // Detail.
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 250000, 'speed' => 1.25])->assertOk();
        $this->postJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks", ['position_ms' => 300000, 'note' => 'Kelsier'])->assertCreated();
        $this->postJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks", ['position_ms' => 1000])->assertCreated();
        $detail = $this->getJson("/api/v1/audiobooks/{$audiobook->id}")->assertOk()->json();
        $this->assertSame(['audiobook'], array_keys($detail));
        $detail = $detail['audiobook'];
        $this->assertSame(self::DETAIL_KEYS, array_keys($detail));
        $this->assertSame(self::PROGRESS_KEYS, array_keys($detail['progress']));
        $this->assertSame(250000, $detail['progress']['position_ms']);
        $this->assertFalse($detail['progress']['finished']);
        $this->assertCount(1, $detail['parts']);
        $this->assertSame(self::PART_KEYS, array_keys($detail['parts'][0]));
        $this->assertSame([0, 'audio/mp4', 600000, 0], [$detail['parts'][0]['index'], $detail['parts'][0]['mime'], $detail['parts'][0]['duration_ms'], $detail['parts'][0]['start_ms']]);
        $this->assertStringStartsWith('http://localhost/api/v1/audiobooks/' . $audiobook->id . '/parts/0', $detail['parts'][0]['url']);
        $this->assertSame(self::CHAPTER_KEYS, array_keys($detail['chapters'][0]));
        $this->assertSame([[0, 'Prologue', 0, 200000], [1, 'Chapter 1', 200000, 600000]], array_map(fn($c) => array_values($c), $detail['chapters']));
        $this->assertSame(self::BOOKMARK_KEYS, array_keys($detail['bookmarks'][0]));
        $this->assertSame([[1000, null], [300000, 'Kelsier']], array_map(fn($b) => [$b['position_ms'], $b['note']], $detail['bookmarks']));
        $this->assertNull($detail['year']);
        $this->assertGreaterThan(0, $detail['size_bytes']);

        // Continue listening.
        $continue = $this->getJson('/api/v1/audiobooks/continue')->assertOk()->json();
        $this->assertSame(['audiobooks'], array_keys($continue));
        $this->assertSame([$audiobook->id], array_column($continue['audiobooks'], 'id'));
        $this->assertSame(self::SUMMARY_KEYS, array_keys($continue['audiobooks'][0]));
    }

    public function test_paging_has_more(): void
    {
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = ['title' => sprintf('Book %02d', $i), 'fingerprint' => sha1((string) $i), 'status' => 'ready', 'dir' => (string) ($i + 1), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('audiobooks')->insert($rows);

        $first = $this->getJson('/api/v1/audiobooks')->json();
        $this->assertCount(50, $first['audiobooks']);
        $this->assertTrue($first['has_more']);
        $second = $this->getJson('/api/v1/audiobooks?page=2')->json();
        $this->assertSame(['Book 50'], array_column($second['audiobooks'], 'title'));
        $this->assertSame([2, false], [$second['page'], $second['has_more']]);
    }

    public function test_continue_lists_started_unfinished_newest_first(): void
    {
        $a = $this->imported('Alpha', null, 'a');
        $b = $this->imported('Beta', null, 'b');
        $c = $this->imported('Gamma', null, 'c');
        $d = $this->imported('Delta', null, 'd');

        $this->putJson("/api/v1/audiobooks/{$a->id}/progress", ['position_ms' => 1000, 'speed' => 1, 'recorded_at' => now()->subHours(3)->toIso8601String()]);
        $this->putJson("/api/v1/audiobooks/{$b->id}/progress", ['position_ms' => 1000, 'speed' => 1, 'recorded_at' => now()->subHour()->toIso8601String()]);
        $this->putJson("/api/v1/audiobooks/{$c->id}/progress", ['position_ms' => 1000, 'speed' => 1, 'finished' => true]);
        $this->putJson("/api/v1/audiobooks/{$d->id}/progress", ['position_ms' => 0, 'speed' => 1]);

        $this->assertSame([$b->id, $a->id], array_column($this->getJson('/api/v1/audiobooks/continue')->json('audiobooks'), 'id'));
    }

    // --- Progress ------------------------------------------------------------

    public function test_progress_is_last_write_wins_and_stamps_the_device(): void
    {
        $audiobook = $this->imported();
        $headers = ['X-Novarr-Device' => 'phone_abcdef12', 'X-Novarr-Device-Name' => 'Pixel 8'];

        $json = $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 120000, 'speed' => 1.5, 'recorded_at' => now()->subMinutes(10)->toIso8601String()], $headers)
            ->assertOk()->json();
        $this->assertSame(['progress', 'stale'], array_keys($json));
        $this->assertSame(self::PROGRESS_KEYS, array_keys($json['progress']));
        $this->assertSame([120000, false, false], [$json['progress']['position_ms'], $json['progress']['finished'], $json['stale']]);
        $row = DB::table('audiobook_progress')->sole();
        $this->assertSame('Pixel 8', $row->device);
        $this->assertSame(1.5, (float) $row->speed);
        $this->assertNotNull($row->ip);

        // An older write (an offline queue replayed late) is ignored.
        $json = $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 5000, 'speed' => 1, 'recorded_at' => now()->subMinutes(20)->toIso8601String()])->assertOk()->json();
        $this->assertTrue($json['stale']);
        $this->assertSame(120000, $json['progress']['position_ms']);
        $this->assertSame('Pixel 8', DB::table('audiobook_progress')->value('device'));

        // A newer one (no recorded_at = now) wins; past the end is kept within the book.
        $json = $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 99999999, 'speed' => 1, 'finished' => true])->assertOk()->json();
        $this->assertSame([600000, true, false], [$json['progress']['position_ms'], $json['progress']['finished'], $json['stale']]);
        // finished: false clears it; leaving it out keeps it.
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 1000, 'speed' => 1])->assertJsonPath('progress.finished', true);
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 1000, 'speed' => 1, 'finished' => false])->assertJsonPath('progress.finished', false);

        // A clock in the future is taken as now (it cannot pin the position).
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 2000, 'speed' => 1, 'recorded_at' => now()->addDays(3)->toIso8601String()])->assertJsonPath('stale', false);
        $this->travel(5)->seconds();
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 3000, 'speed' => 1])->assertJsonPath('stale', false)->assertJsonPath('progress.position_ms', 3000);
    }

    public function test_progress_is_validated(): void
    {
        $audiobook = $this->imported();

        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 1000, 'speed' => 3.5])->assertStatus(422);
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 1000, 'speed' => 0.4])->assertStatus(422);
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => -1, 'speed' => 1])->assertStatus(422);
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['speed' => 1])->assertStatus(422);
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 1, 'speed' => 1, 'recorded_at' => 'yesterday-ish'])->assertStatus(422);
        $this->putJson('/api/v1/audiobooks/999/progress', ['position_ms' => 1, 'speed' => 1])->assertNotFound();
    }

    public function test_each_reader_has_their_own_progress_and_bookmarks(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $audiobook = $this->imported();
        $ownerToken = $this->tokenFor($owner);
        $readerToken = $this->tokenFor($reader);

        $this->asToken($ownerToken)->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 9000, 'speed' => 1])->assertOk();
        $bookmark = $this->asToken($ownerToken)->postJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks", ['position_ms' => 9000])->json('bookmark');

        $detail = $this->asToken($readerToken)->getJson("/api/v1/audiobooks/{$audiobook->id}")->assertOk()->json('audiobook');
        $this->assertNull($detail['progress']);
        $this->assertSame([], $detail['bookmarks']);
        // Not theirs to delete.
        $this->asToken($readerToken)->deleteJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks/{$bookmark['id']}")->assertNotFound();
        $this->assertSame(1, AudiobookBookmark::count());

        // A deleted reader's rows go with them.
        $this->asToken($readerToken)->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 1, 'speed' => 1])->assertOk();
        $reader->delete();
        $this->assertSame([$owner->id], DB::table('audiobook_progress')->pluck('user_id')->map(fn($i) => (int) $i)->all());
    }

    // --- Bookmarks -----------------------------------------------------------

    public function test_bookmarks_are_added_and_deleted(): void
    {
        $audiobook = $this->imported();

        $json = $this->postJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks", ['position_ms' => 42000, 'note' => '  The well  '])->assertCreated()->json();
        $this->assertSame(['bookmark'], array_keys($json));
        $this->assertSame(self::BOOKMARK_KEYS, array_keys($json['bookmark']));
        $this->assertSame([42000, 'The well'], [$json['bookmark']['position_ms'], $json['bookmark']['note']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['bookmark']['created_at']);

        $this->postJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks", ['position_ms' => 1, 'note' => str_repeat('x', 501)])->assertStatus(422);
        $this->postJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks", ['note' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/audiobooks/999/bookmarks', ['position_ms' => 1])->assertNotFound();

        $this->deleteJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks/{$json['bookmark']['id']}")->assertNoContent();
        $this->assertSame(0, AudiobookBookmark::count());
        $this->deleteJson("/api/v1/audiobooks/{$audiobook->id}/bookmarks/{$json['bookmark']['id']}")->assertNotFound();
    }

    // --- Streaming -----------------------------------------------------------

    private function streamed(\Illuminate\Testing\TestResponse $response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    public function test_a_part_streams_whole_and_by_range(): void
    {
        $audiobook = $this->imported();
        $file = AudiobookLibrary::root() . "/{$audiobook->id}/part-001.m4b";
        $bytes = file_get_contents($file);
        $size = strlen($bytes);

        $whole = $this->get("/api/v1/audiobooks/{$audiobook->id}/parts/0")->assertOk();
        $this->assertSame('audio/mp4', $whole->headers->get('Content-Type'));
        $this->assertSame('bytes', $whole->headers->get('Accept-Ranges'));
        $this->assertNotEmpty($whole->headers->get('ETag'));
        $this->assertSame((string) $size, $whole->headers->get('Content-Length'));
        $this->assertSame($bytes, $this->streamed($whole));

        $range = $this->get("/api/v1/audiobooks/{$audiobook->id}/parts/0", ['Range' => 'bytes=2-9']);
        $range->assertStatus(206);
        $this->assertSame("bytes 2-9/{$size}", $range->headers->get('Content-Range'));
        $this->assertSame('8', $range->headers->get('Content-Length'));
        $this->assertSame(substr($bytes, 2, 8), $this->streamed($range));

        // An open-ended range (what <audio> asks for first).
        $open = $this->get("/api/v1/audiobooks/{$audiobook->id}/parts/0", ['Range' => 'bytes=10-']);
        $open->assertStatus(206);
        $this->assertSame('bytes 10-' . ($size - 1) . "/{$size}", $open->headers->get('Content-Range'));

        $this->get("/api/v1/audiobooks/{$audiobook->id}/parts/0", ['Range' => "bytes={$size}-" . ($size + 10)])->assertStatus(416);
        $this->get("/api/v1/audiobooks/{$audiobook->id}/parts/5")->assertNotFound();

        // A file gone from disk is a 404, not a 500.
        unlink($file);
        $this->getJson("/api/v1/audiobooks/{$audiobook->id}/parts/0")->assertNotFound();
    }

    public function test_with_sign_in_on_a_part_needs_a_token_or_its_signed_url(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $audiobook = $this->imported();
        $token = $this->tokenFor($reader);

        // The detail's URL is signed for the reader and expires in about a day.
        $url = $this->asToken($token)->getJson("/api/v1/audiobooks/{$audiobook->id}")->assertOk()->json('audiobook.parts.0.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame((string) $reader->id, (string) $query['reader']);
        $this->assertArrayHasKey('signature', $query);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, (int) $query['expires'], 3700);

        // No headers, no cookies: the signed URL alone.
        $this->asNobody();
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        $this->get($path, ['Range' => 'bytes=0-3'])->assertStatus(206);

        // Tampered, expired, or no credentials: refused.
        $this->getJson(str_replace('reader=' . $reader->id, 'reader=' . $owner->id, $path))->assertStatus(401);
        $this->getJson("/api/v1/audiobooks/{$audiobook->id}/parts/0")->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->travel(26)->hours();
        $this->getJson($path)->assertStatus(401);
        // A disabled reader's URL stops working.
        $this->travelBack();
        $reader->forceFill(['disabled_at' => now()])->save();
        $this->getJson($path)->assertStatus(401);

        // The bearer header works on it too.
        $this->asToken($this->tokenFor($owner))->get("/api/v1/audiobooks/{$audiobook->id}/parts/0")->assertOk();
    }

    // --- Novel payloads --------------------------------------------------------

    public function test_novel_payloads_carry_the_audiobook_at_the_end(): void
    {
        $book = $this->book('The Final Empire');
        $plain = $this->book('No Audio Here');
        $audiobook = $this->imported();

        $novels = collect($this->getJson('/api/v1/novels?medium=book')->assertOk()->json('novels'))->keyBy('id');
        // Right before `favourite`, the summary's last key.
        $this->assertSame(['audiobook', 'favourite'], array_slice(array_keys($novels[$book->id]), -2));
        $this->assertSame(['id' => $audiobook->id, 'duration_ms' => 600000], $novels[$book->id]['audiobook']);
        $this->assertNull($novels[$plain->id]['audiobook']);

        $this->getJson("/api/v1/novels/{$book->id}")->assertOk()->assertJsonPath('novel.audiobook', ['id' => $audiobook->id, 'duration_ms' => 600000]);

        // Unmatching updates it at once (the cached map is dropped).
        $this->postJson("/api/v1/audiobooks/{$audiobook->id}/match", ['novel_id' => null])->assertOk();
        $this->getJson("/api/v1/novels/{$book->id}")->assertJsonPath('novel.audiobook', null);

        $this->getJson('/api/v1/meta')->assertJsonPath('audiobooks', true);
    }

    public function test_a_book_deleted_for_good_leaves_its_audiobook_standalone(): void
    {
        $book = $this->book('The Final Empire');
        $audiobook = $this->imported();
        $this->assertSame($book->id, $audiobook->novel_id);

        // Soft-deleted: the audiobook reads as standalone meanwhile.
        $book->delete();
        $this->getJson("/api/v1/audiobooks/{$audiobook->id}")->assertJsonPath('audiobook.novel_id', null)->assertJsonPath('audiobook.title', 'The Final Empire');

        $book->forceDelete();
        $this->assertNull($audiobook->fresh()->novel_id);
        $this->assertTrue(Audiobook::whereKey($audiobook->id)->exists());
    }

    // --- Web pages -------------------------------------------------------------

    public function test_the_web_pages(): void
    {
        $book = $this->book('The Final Empire');
        $audiobook = $this->imported();
        $this->imported('Lone Story', null, 's');

        // The book page's Listen panel.
        $this->get(route('novels.show', $book->id))->assertOk()
            ->assertSee('id="listenPanel"', false)->assertSee('read by Michael Kramer')->assertSee(route('audiobooks.show', $audiobook->id), false)->assertSee('Listen');
        $this->get(route('novels.show', $this->book('Silent Book')->id))->assertOk()->assertDontSee('id="listenPanel"', false);

        // The player.
        $page = $this->get(route('audiobooks.show', $audiobook->id))->assertOk()
            ->assertSee('id="audiobookPlayer"', false)
            ->assertSee('data-ab-scrubber', false)
            ->assertSee('Sleep timer')
            ->assertSee('Prologue');
        preg_match('#<script type="application/json" id="audiobookData">(.*?)</script>#s', $page->getContent(), $m);
        $data = json_decode($m[1] ?? '', true);
        $this->assertSame(self::DETAIL_KEYS, array_keys($data));
        $this->get(route('audiobooks.show', 999))->assertNotFound();

        // Not started: no Continue listening on Home yet.
        $this->get(route('home'))->assertOk()->assertDontSee('Continue listening');
        $this->putJson("/api/v1/audiobooks/{$audiobook->id}/progress", ['position_ms' => 300000, 'speed' => 1])->assertOk();
        $this->get(route('home'))->assertOk()->assertSee('Continue listening')->assertSee(route('audiobooks.show', $audiobook->id), false);
        $this->get(route('novels.show', $book->id))->assertSee('Continue listening')->assertSee('50% listened');

        // The shelf: both, the started one under Continue listening too.
        $this->get(route('audiobooks.index'))->assertOk()->assertSee('The Final Empire')->assertSee('Lone Story')->assertSee('Continue listening');
        $this->get(route('audiobooks.index', ['q' => 'lone']))->assertOk()->assertSee('Lone Story')->assertDontSee('id="continue-listening"', false);

        // The Books library's Audiobooks chip: books with an audiobook.
        $this->get(route('novels.index', ['medium' => 'book', 'filter' => 'audiobooks']))->assertOk()
            ->assertSee('data-chip="audiobooks"', false)->assertSee('The Final Empire')->assertDontSee('Silent Book');
    }

    // --- Storage, schedule, probe ---------------------------------------------

    public function test_storage_counts_audiobook_files_as_their_own_bucket(): void
    {
        $audiobook = $this->imported();
        $size = (int) filesize(AudiobookLibrary::root() . "/{$audiobook->id}/part-001.m4b");

        $usage = app(\App\Services\StorageUsage::class);
        $this->assertSame([AudiobookLibrary::root()], $usage->paths()['audiobooks']);
        $usage->store($usage->measure());
        $bucket = collect($usage->report()['buckets'])->firstWhere('key', 'audiobooks');

        $this->assertSame('Audiobook library', $bucket['label']);
        $this->assertSame($size, $bucket['bytes']);
        $this->assertSame(1, $bucket['files']);
    }

    public function test_the_scan_is_scheduled_every_ten_minutes(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();
        $event = collect($this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->events())->firstWhere('description', 'audiobooks_scan');

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertTrue($event->runInBackground);
        $this->assertStringContainsString('audiobooks:scan', $event->command);
    }

    public function test_ffprobe_output_is_read_into_the_probe_answer(): void
    {
        $answer = FfmpegProbe::parse([
            'streams' => [
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
                ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'disposition' => ['attached_pic' => 1]],
            ],
            'chapters' => [
                ['start_time' => '5.5', 'end_time' => '100.25', 'tags' => ['title' => 'Two']],
                ['start_time' => '0.000000', 'end_time' => '5.500000', 'tags' => ['title' => 'One']],
                ['start_time' => '100.25', 'end_time' => '100.25'],
            ],
            'format' => ['duration' => '100.250000', 'bit_rate' => '64000', 'tags' => ['Title' => 'Book', 'ARTIST' => ' Author ', 'album_artist' => '', 'composer' => 'Reader']],
        ]);

        $this->assertSame(100250, $answer['duration_ms']);
        $this->assertSame(64000, $answer['bitrate']);
        $this->assertSame(['title' => 'Book', 'artist' => 'Author', 'composer' => 'Reader'], $answer['tags']);
        $this->assertSame([
            ['title' => 'One', 'start_ms' => 0, 'end_ms' => 5500],
            ['title' => 'Two', 'start_ms' => 5500, 'end_ms' => 100250],
        ], $answer['chapters']);
        $this->assertTrue($answer['has_cover']);

        // Ogg keeps its tags on the stream.
        $ogg = FfmpegProbe::parse(['streams' => [['codec_type' => 'audio', 'duration' => '3', 'tags' => ['ALBUM' => 'Ogg Book']]], 'format' => []]);
        $this->assertSame(['album' => 'Ogg Book'], $ogg['tags']);
        $this->assertSame(3000, $ogg['duration_ms']);

        foreach ([['streams' => [['codec_type' => 'video']], 'format' => ['duration' => '3']], ['streams' => [['codec_type' => 'audio']], 'format' => []]] as $bad) {
            try {
                FfmpegProbe::parse($bad);
                $this->fail('Expected ProbeFailed');
            } catch (ProbeFailed $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_a_missing_ffprobe_is_reported_in_plain_words(): void
    {
        config(['novarr.audiobooks.ffprobe' => '/nonexistent/ffprobe']);

        $this->assertStringContainsString('ffprobe was not found (/nonexistent/ffprobe)', (string) (new FfmpegProbe())->problem());
    }
}
