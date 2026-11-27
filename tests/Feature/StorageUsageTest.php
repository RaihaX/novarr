<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateePub;
use App\Console\Commands\MeasureStorage;
use App\Novel;
use App\NovelChapter;
use App\Services\AdminAlert;
use App\Services\StorageUsage;
use App\Support\PublicAddress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Storage usage (App\Services\StorageUsage, `storage:measure`, Health →
 * Storage): measuring against a temporary storage folder with fake files,
 * the stored figures, the page and the low-space alert.
 */
class StorageUsageTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private string $tmp;

    private string $storage;

    private string $manga;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 05:10:00');

        $this->tmp = sys_get_temp_dir() . '/novarr-storage-test-' . bin2hex(random_bytes(5));
        $this->storage = $this->tmp . '/storage';
        // Manga pages on "another mount": outside storage/, as NOVARR_MANGA_PATH allows.
        $this->manga = $this->tmp . '/manga-mount';
        File::makeDirectory($this->storage . '/app/public', 0755, true);
        File::makeDirectory($this->manga, 0755, true);

        $this->app->useStoragePath($this->storage);
        config([
            'filesystems.disks.manga.root' => $this->manga,
            'filesystems.disks.public.root' => $this->storage . '/app/public',
            'filesystems.disks.local.root' => $this->storage . '/app/private',
            'novarr.snapshots.path' => $this->storage . '/app/snapshots',
            'backup.backup.name' => 'Novarr',
            'backup.backup.destination.disks' => ['local'],
            'novarr.storage.low_free_percent' => 0, // nothing is "low" unless a test says so
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ------------------------------------------------------------------------

    private function putFile(string $path, int $bytes): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat('x', $bytes));
    }

    private function novel(string $name, string $medium = Novel::MEDIUM_NOVEL): Novel
    {
        return Novel::create(['name' => $name, 'author' => 'Author', 'status' => 0, 'group_id' => 2,
            'no_of_chapters' => 0, 'medium' => $medium]);
    }

    private function chapter(Novel $novel, int $n, bool $deleted = false): NovelChapter
    {
        $c = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
            'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/{$n}"]);
        if ($deleted) {
            $c->forceFill(['deleted_at' => now()])->saveQuietly();
        }

        return $c;
    }

    /** A chapter whose stored body is exactly $words five-byte words ("word "). */
    private function textChapter(Novel $novel, int $n, int $words, bool $counted = true, bool $deleted = false): NovelChapter
    {
        $c = $this->chapter($novel, $n, $deleted);
        DB::table('chapter_texts')->updateOrInsert(
            ['novel_chapter_id' => $c->id],
            ['content' => str_repeat('word ', $words), 'word_count' => $counted ? $words : null],
        );

        return $c;
    }

    private function series(array $result, int $novelId): array
    {
        foreach ($result['rows'] as $row) {
            if ($row['scope'] === StorageUsage::SCOPE_SERIES && $row['novel_id'] === $novelId) {
                return $row;
            }
        }
        $this->fail("No series row for {$novelId}");
    }

    private function bucket(array $result, string $key): array
    {
        foreach ($result['rows'] as $row) {
            if ($row['scope'] === StorageUsage::SCOPE_BUCKET && $row['key'] === $key) {
                return $row;
            }
        }
        $this->fail("No bucket {$key}");
    }

    /** A library with a bit of everything; returns the novels by role. */
    private function library(): array
    {
        $manga = $this->novel('Big Manga', Novel::MEDIUM_MANGA);
        $c1 = $this->chapter($manga, 1);
        $c2 = $this->chapter($manga, 2);
        $this->putFile("{$this->manga}/{$manga->id}/{$c1->id}/001.jpg", 100);
        $this->putFile("{$this->manga}/{$manga->id}/{$c1->id}/002.jpg", 200);
        $this->putFile("{$this->manga}/{$manga->id}/{$c2->id}/001.webp", 300);

        $novel = $this->novel('Wordy Novel');
        $this->textChapter($novel, 1, 100);
        $this->textChapter($novel, 2, 200);
        $this->textChapter($novel, 3, 50, deleted: true); // soft-deleted: its text still takes space

        // Cover: original on the public disk + both thumbnails.
        DB::table('files')->insert(['file_name' => 'c.jpg', 'file_path' => 'public/cover1.jpg',
            'file_type' => (new Novel())->getMorphClass(), 'file_id' => $novel->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->putFile("{$this->storage}/app/public/cover1.jpg", 50);
        $this->putFile("{$this->storage}/app/public/covers/sm/cover1.jpg", 10);
        $this->putFile("{$this->storage}/app/public/covers/md/cover1.jpg", 20);

        // ePub at GenerateePub's path.
        $this->putFile(GenerateePub::epubPath($novel), 1000);

        $empty = $this->novel('Nothing Yet');

        $gone = $this->novel('Gone Manga', Novel::MEDIUM_MANGA);
        $gc = $this->chapter($gone, 1);
        $this->putFile("{$this->manga}/{$gone->id}/{$gc->id}/001.png", 400);
        $gone->delete(); // soft delete keeps the pages

        // A folder of a series that no longer exists at all.
        $this->putFile("{$this->manga}/99999/1/001.jpg", 70);

        // The other buckets.
        $this->putFile("{$this->storage}/app/private/Novarr/2026-11-20-03-45-00.zip", 5000);
        $this->putFile("{$this->storage}/logs/laravel.log", 700);
        $this->putFile("{$this->storage}/app/snapshots/1/2-empty.html.gz", 30);
        $this->putFile("{$this->storage}/framework/cache/data/ab/cd", 40);
        $this->putFile("{$this->storage}/app/apps/novarr.apk", 900);

        return compact('manga', 'novel', 'empty', 'gone');
    }

    // ---- measuring --------------------------------------------------------------------------

    public function test_measure_attributes_files_and_text_to_each_series(): void
    {
        ['manga' => $manga, 'novel' => $novel, 'empty' => $empty, 'gone' => $gone] = $this->library();

        $result = app(StorageUsage::class)->measure();

        $m = $this->series($result, $manga->id);
        $this->assertSame('manga', $m['medium']);
        $this->assertSame(600, $m['bytes']);
        $this->assertSame(3, $m['files']);
        $this->assertSame(2, $m['chapters']);
        $this->assertSame(600, $m['meta']['pages_bytes']);

        $n = $this->series($result, $novel->id);
        $this->assertSame('novel', $n['medium']);
        $this->assertSame(350 * 5, $n['meta']['text_bytes'], 'every chapter sampled: exact, soft-deleted one included');
        $this->assertFalse($n['meta']['text_estimated']);
        $this->assertSame(80, $n['meta']['cover_bytes']);
        $this->assertSame(1000, $n['meta']['epub_bytes']);
        $this->assertSame(1750 + 80 + 1000, $n['bytes']);
        $this->assertSame(3, $n['chapters']);
        $this->assertSame(4, $n['files'], 'cover + 2 thumbnails + ePub');

        $e = $this->series($result, $empty->id);
        $this->assertSame(0, $e['bytes']);
        $this->assertSame(0, $e['chapters']);

        // Soft-deleted series: its pages are still on disk, so they count.
        $g = $this->series($result, $gone->id);
        $this->assertSame(400, $g['bytes']);
        $this->assertTrue($g['meta']['deleted']);

        $pages = $this->bucket($result, 'manga_pages');
        $this->assertSame(600 + 400 + 70, $pages['bytes']);
        $this->assertSame(70, $pages['meta']['unattributed_bytes']);
        $this->assertSame(80, $this->bucket($result, 'covers')['bytes']);
        $this->assertSame(1000, $this->bucket($result, 'epubs')['bytes']);
        $this->assertSame(5000, $this->bucket($result, 'backups')['bytes']);
        $this->assertSame(700, $this->bucket($result, 'logs')['bytes']);
        $this->assertSame(30, $this->bucket($result, 'snapshots')['bytes']);
        $this->assertSame(40, $this->bucket($result, 'cache')['bytes']);
        // Other: storage/ minus every claimed folder: only the APK.
        $this->assertSame(900, $this->bucket($result, 'other')['bytes']);
        $this->assertSame(1, $this->bucket($result, 'other')['files']);
        $this->assertSame(1750, $this->bucket($result, StorageUsage::NOVEL_TEXT)['bytes']);
        // sqlite in memory locally; CI also runs the suite on MariaDB.
        $this->assertSame(
            in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'information_schema' : 'in-memory',
            $this->bucket($result, 'database')['meta']['source']
        );

        $disks = array_values(array_filter($result['rows'], fn($r) => $r['scope'] === StorageUsage::SCOPE_DISK));
        $this->assertNotEmpty($disks);
        $this->assertGreaterThan(0, $disks[0]['meta']['total']);
        $this->assertContains('Manga pages', array_merge(...array_map(fn($d) => $d['meta']['labels'], $disks)));
    }

    public function test_books_are_measured_as_their_own_medium_with_their_files(): void
    {
        $booksDir = $this->storage . '/app/books';
        $calibreDir = $this->manga . '/novarr-books';
        config(['novarr.books.path' => $booksDir, 'novarr.books.calibre_path' => $calibreDir]);
        $book = Novel::create(['name' => 'A Book', 'status' => 1, 'group_id' => 1, 'medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/abc']);
        $this->putFile(\App\Books\BookLibrary::path($book), 300);
        $this->putFile("{$calibreDir}/calibre.epub", 700);

        $usage = app(StorageUsage::class);
        $usage->store($usage->measure());
        $report = $usage->report();

        $this->assertSame(1, $report['mediums'][Novel::MEDIUM_BOOK]['series']);
        $this->assertSame([$book->id], array_column($report['top'][Novel::MEDIUM_BOOK], 'novel_id'));
        $books = collect($report['buckets'])->firstWhere('key', 'books');
        $this->assertSame(1000, $books['bytes']);
        $this->assertSame(2, $books['files']);
        // Not counted again under "Other" or the manga pages.
        $this->assertSame(0, (int) collect($report['buckets'])->firstWhere('key', 'manga_pages')['bytes']);

        $this->get(route('health.storage'))->assertOk()->assertSee('Web novels, manga, books, comics and audiobooks')->assertSee('Book files');
    }

    public function test_missing_folders_measure_as_zero(): void
    {
        File::deleteDirectory($this->manga);
        File::deleteDirectory($this->storage . '/app/public');
        $novel = $this->novel('Lonely');

        $result = app(StorageUsage::class)->measure();

        $this->assertSame(0, $this->series($result, $novel->id)['bytes']);
        foreach (['manga_pages', 'covers', 'epubs', 'backups', 'logs', 'snapshots'] as $key) {
            $this->assertSame(0, $this->bucket($result, $key)['bytes'], $key);
        }
        $this->assertTrue($this->bucket($result, 'manga_pages')['meta']['missing']);
    }

    public function test_text_is_estimated_from_word_counts_past_the_sample(): void
    {
        $novel = $this->novel('Long Novel');
        $n = StorageUsage::TEXT_SAMPLE + 15;
        for ($i = 1; $i <= $n; $i++) {
            $this->textChapter($novel, $i, 100 + $i);
        }
        // Two chapters without a stored word count: the novel's average chapter.
        $this->textChapter($novel, $n + 1, 120, counted: false);
        $this->textChapter($novel, $n + 2, 121, counted: false);

        $usage = app(StorageUsage::class);
        $estimate = $usage->textUsage($novel->id);
        $exact = $usage->textUsage($novel->id, true);

        $this->assertTrue($estimate['estimated']);
        $this->assertFalse($exact['estimated']);
        $this->assertSame($n + 2, $estimate['chapters']);
        $truth = 5 * (array_sum(range(101, 100 + $n)) + 241);
        $this->assertSame($truth, $exact['bytes']);
        // Counted chapters are exact (5 bytes a word); the two uncounted ones
        // take the sample's average, so the estimate is close, not exact.
        $this->assertEqualsWithDelta($truth, $estimate['bytes'], $truth * 0.01);

        // --exact reaches the series rows.
        $result = $usage->measure(true);
        $this->assertSame($truth, $this->series($result, $novel->id)['meta']['text_bytes']);
        $this->assertTrue($result['exact']);
    }

    public function test_walk_skips_excluded_folders_and_symlinks(): void
    {
        $this->putFile("{$this->tmp}/w/a.bin", 10);
        $this->putFile("{$this->tmp}/w/sub/b.bin", 20);
        $this->putFile("{$this->tmp}/w/skip/c.bin", 40);
        @symlink("{$this->tmp}/w/sub/b.bin", "{$this->tmp}/w/link.bin");

        $walk = app(StorageUsage::class)->walk("{$this->tmp}/w", ["{$this->tmp}/w/skip"]);

        $this->assertSame(30, $walk['bytes']);
        $this->assertSame(2, $walk['files']);
        $this->assertSame(0, $walk['errors']);
        $this->assertTrue(app(StorageUsage::class)->walk("{$this->tmp}/nope")['missing']);
    }

    // ---- the command ----------------------------------------------------------------------

    public function test_the_command_stores_and_replaces_the_figures(): void
    {
        ['manga' => $manga] = $this->library();

        $this->artisan('storage:measure')->assertExitCode(0);
        $count = DB::table('storage_usage')->count();
        $this->assertGreaterThan(0, $count);
        $this->assertSame(600, (int) DB::table('storage_usage')->where('scope', 'series')->where('key', (string) $manga->id)->value('bytes'));

        // A new page, a day later: the same rows, new figures, no duplicates.
        Carbon::setTestNow('2026-11-21 05:10:00');
        $chapter = NovelChapter::where('novel_id', $manga->id)->first();
        $this->putFile("{$this->manga}/{$manga->id}/{$chapter->id}/009.jpg", 1000);
        $this->artisan('storage:measure')->assertExitCode(0);

        $this->assertSame($count, DB::table('storage_usage')->count());
        $this->assertSame(1600, (int) DB::table('storage_usage')->where('scope', 'series')->where('key', (string) $manga->id)->value('bytes'));
        $this->assertSame(1, DB::table('storage_usage')->where('scope', 'run')->count());
        $this->assertSame(0, DB::table('storage_usage')->where('measured_at', '<', '2026-11-21 00:00:00')->count());

        $series = StorageUsage::forSeries($manga->id);
        $this->assertSame(1600, $series['bytes']);
        $this->assertSame(800, $series['per_chapter']);
    }

    public function test_a_running_measurement_is_not_started_twice(): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock(MeasureStorage::LOCK, 60);
        $lock->get();

        $this->artisan('storage:measure')->expectsOutputToContain('already running')->assertExitCode(0);
        $this->assertSame(0, DB::table('storage_usage')->count());
        $lock->release();
    }

    public function test_measure_now_queues_the_command_through_the_commands_runner(): void
    {
        $this->library();

        $response = $this->postJson(route('commands.execute-async'), ['command' => 'storage_measure']);

        $response->assertOk()->assertJson(['success' => true]);
        // The test queue is sync: the job already ran.
        $this->assertSame(1, DB::table('storage_usage')->where('scope', 'run')->count());
        $this->getJson(route('commands.status', $response->json('job_id')))
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.success', true);
    }

    // ---- the page ----------------------------------------------------------------------------

    public function test_the_page_says_never_measured(): void
    {
        $this->get(route('health.storage'))
            ->assertOk()
            ->assertSee('Never measured')
            ->assertSee('id="neverMeasured"', false)
            ->assertSee('Measure now');

        $this->get(route('health.index'))->assertOk()->assertSee(route('health.storage'), false);
    }

    public function test_the_page_shows_the_figures_biggest_first(): void
    {
        ['manga' => $manga, 'novel' => $novel, 'empty' => $empty] = $this->library();
        $this->artisan('storage:measure')->assertExitCode(0);

        $response = $this->get(route('health.storage'));

        $response->assertOk()
            ->assertSee('Last measured')
            ->assertSee('Wordy Novel')
            ->assertSee('Big Manga')
            ->assertSee('2.8 KB')  // Wordy Novel: 1750 + 80 + 1000 = 2830 B
            ->assertSee('4.9 KB')  // backups: 5000 B
            ->assertSee(route('novels.show', $novel->id), false)
            ->assertSee('deleted (files still on disk)')
            ->assertSee('data-bucket="manga_pages"', false)
            ->assertSee('data-medium="manga"', false)
            ->assertSeeInOrder(['data-top-table="all"', 'data-series="' . $novel->id . '"', 'data-series="' . $manga->id . '"'], false)
            ->assertDontSee('data-series="' . $empty->id . '"', false);
    }

    public function test_the_series_page_shows_its_stored_size_to_the_admin(): void
    {
        ['novel' => $novel] = $this->library();
        $this->get(route('novels.show', $novel->id))->assertOk()->assertSee('Not measured yet.');

        $this->artisan('storage:measure')->assertExitCode(0);

        $this->get(route('novels.show', $novel->id))->assertOk()
            ->assertSee('id="seriesStorage"', false)
            ->assertSee('2.8 KB');
    }

    public function test_the_page_is_refused_to_a_reader_and_on_the_public_address(): void
    {
        $this->enableSignIn();
        $token = $this->tokenFor($this->makeReader());
        $this->asSignedIn(\App\ApiToken::where('token_hash', hash('sha256', $token))->sole()->user)->get(route('health.storage'))->assertStatus(403);

        // The admin, on the public address (as cloudflared forwards it).
        config(['novarr.public_hosts' => ['read.example.com'], 'novarr.public_url' => null]);
        $this->asSignedIn($this->owner(), 'http://read.example.com', fn($t) => $t->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7']))
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('http://read.example.com/health/storage')
            ->assertStatus(403)
            ->assertExactJson(['message' => PublicAddress::ADMIN_MESSAGE, 'code' => 'forbidden']);
    }

    // ---- the low-space alert -----------------------------------------------------------------

    /** @return object{sent: array} */
    private function captureAlerts(): object
    {
        $alert = new class extends AdminAlert {
            public array $sent = [];

            public function send(string $subject, string $message, ?string $url = null, array $mail = []): array
            {
                $this->sent[] = compact('subject', 'message', 'url', 'mail');

                return ['webhook' => true, 'email' => true];
            }
        };
        $this->app->instance(AdminAlert::class, $alert);

        return $alert;
    }

    public function test_the_low_space_alert_fires_once_on_the_transition(): void
    {
        $alert = $this->captureAlerts();

        // Plenty of space: nothing.
        $this->artisan('storage:measure')->assertExitCode(0);
        $this->assertSame([], $alert->sent);

        // Under the threshold (every real disk has less than 100% free).
        config(['novarr.storage.low_free_percent' => 100]);
        $this->artisan('storage:measure')->assertExitCode(0);
        $this->assertCount(1, $alert->sent);
        $this->assertSame('Disk space low', $alert->sent[0]['subject']);
        $this->assertStringContainsString('free of', $alert->sent[0]['message']);
        $this->assertStringContainsString('Threshold: 100% free', $alert->sent[0]['message']);
        $this->assertStringEndsWith('/health/storage', $alert->sent[0]['url']);

        // Still low at the next measurement: no repeat.
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->artisan('storage:measure')->assertExitCode(0);
        $this->assertCount(1, $alert->sent);

        // Recovers, then low again within 24 hours of the alert: still no repeat.
        config(['novarr.storage.low_free_percent' => 0]);
        $this->artisan('storage:measure')->assertExitCode(0);
        config(['novarr.storage.low_free_percent' => 100]);
        $this->artisan('storage:measure')->assertExitCode(0);
        $this->assertCount(1, $alert->sent);

        // A day later, a new transition is told again.
        Carbon::setTestNow('2026-11-21 06:00:00');
        config(['novarr.storage.low_free_percent' => 0]);
        $this->artisan('storage:measure')->assertExitCode(0);
        config(['novarr.storage.low_free_percent' => 100]);
        $this->artisan('storage:measure')->assertExitCode(0);
        $this->assertCount(2, $alert->sent);

        // The page shows the warning.
        $this->get(route('health.storage'))->assertOk()->assertSee('Low space')->assertSee('data-low="1"', false);
    }

    // ---- audiobooks ----------------------------------------------------------------------------

    private const MIB = 1048576;

    /** The audiobook library on the big drive (inside the manga mount, as in production) and two inboxes. */
    private function audioPlaces(): array
    {
        $places = [
            'library' => $this->manga . '/novarr-audiobooks',
            'ab_inbox' => $this->tmp . '/share/audiobooks-inbox',
            'books_inbox' => $this->tmp . '/share/books-inbox',
        ];
        config([
            'novarr.audiobooks.path' => $places['library'],
            'novarr.audiobooks.inbox' => $places['ab_inbox'],
            'novarr.books.download_inbox' => $places['books_inbox'],
        ]);
        File::makeDirectory($places['library'], 0755, true);

        return $places;
    }

    /** An audiobook with files on disk: $parts = [[bytes, duration_ms], …]. */
    private function audiobook(string $title, array $parts, ?Novel $novel = null, ?string $cover = null, ?string $narrator = null): \App\Audiobook
    {
        $audiobook = \App\Audiobook::create([
            'novel_id' => $novel?->id, 'title' => $title, 'narrator' => $narrator, 'dir' => 'tmp',
            'duration_ms' => array_sum(array_column($parts, 1)), 'size_bytes' => array_sum(array_column($parts, 0)),
            'status' => $novel ? \App\Audiobook::STATUS_READY : \App\Audiobook::STATUS_UNMATCHED,
            'fingerprint' => sha1($title), 'cover_path' => $cover,
        ]);
        $audiobook->forceFill(['dir' => (string) $audiobook->id])->save();
        $start = 0;
        foreach ($parts as $i => [$bytes, $ms]) {
            $name = sprintf('part-%03d.m4b', $i + 1);
            $this->putFile($audiobook->directory() . '/' . $name, $bytes);
            \App\AudiobookPart::create(['audiobook_id' => $audiobook->id, 'idx' => $i, 'path' => $name, 'mime' => 'audio/mp4',
                'duration_ms' => $ms, 'size_bytes' => $bytes, 'start_ms' => $start]);
            $start += $ms;
        }

        return $audiobook;
    }

    private function audiobookRow(array $result, int $id): array
    {
        foreach ($result['rows'] as $row) {
            if ($row['scope'] === StorageUsage::SCOPE_AUDIOBOOK && $row['key'] === (string) $id) {
                return $row;
            }
        }
        $this->fail("No audiobook row for {$id}");
    }

    /** Transmission's torrent list, faked (null = it cannot be asked). */
    private function fakeTransmission(?array $names): void
    {
        config(['novarr.transmission.url' => 'http://transmission.test:9091']);
        $this->app->instance(\App\Downloads\Transmission::class, new class($names) extends \App\Downloads\Transmission {
            public function __construct(private ?array $names)
            {
            }

            public function all(): array
            {
                if ($this->names === null) {
                    throw new \App\Downloads\DownloadFailed('Transmission could not be reached. Is it running?');
                }

                return array_map(fn($n) => ['hashString' => sha1($n), 'name' => $n, 'downloadDir' => '/downloads/Audiobooks'], $this->names);
            }
        });
    }

    public function test_each_audiobook_is_measured_with_its_parts_and_cover(): void
    {
        $this->audioPlaces();
        $book = $this->novel('Mistborn', Novel::MEDIUM_BOOK);
        $this->putFile(GenerateePub::epubPath($book), 1000);
        // Two parts (1 + 2 MiB), 30 minutes in all; a cover with both thumbnails.
        $this->putFile("{$this->storage}/app/public/audiobook-covers/mb.jpg", 300);
        $this->putFile("{$this->storage}/app/public/covers/sm/mb.jpg", 20);
        $this->putFile("{$this->storage}/app/public/covers/md/mb.jpg", 30);
        $audiobook = $this->audiobook('Mistborn (Unabridged)', [[self::MIB, 600_000], [2 * self::MIB, 1_200_000]], $book, 'audiobook-covers/mb.jpg', 'Michael Kramer');
        // A removed standalone one: its file is still on disk, so it counts (flagged).
        $gone = $this->audiobook('Old Rip', [[self::MIB, 3_600_000]]);
        $gone->delete();
        // A part whose file has gone: counted as missing, not as bytes.
        $broken = $this->audiobook('Broken', [[self::MIB, 1_800_000]]);
        @unlink($broken->directory() . '/part-001.m4b');

        $usage = app(StorageUsage::class);
        $result = $usage->measure();

        $row = $this->audiobookRow($result, $audiobook->id);
        $this->assertSame(StorageUsage::MEDIUM_AUDIOBOOK, $row['medium']);
        $this->assertSame($book->id, $row['novel_id']);
        $this->assertSame(3 * self::MIB + 350, $row['bytes']);
        $this->assertSame(5, $row['files'], 'two parts + the cover and its two thumbnails');
        $this->assertSame(2, $row['meta']['parts']);
        $this->assertSame(3 * self::MIB, $row['meta']['parts_bytes']);
        $this->assertSame(350, $row['meta']['cover_bytes']);
        $this->assertSame(1_800_000, $row['meta']['duration_ms']);
        $this->assertSame('Mistborn', $row['meta']['novel_name']);
        $this->assertTrue($this->audiobookRow($result, $gone->id)['meta']['deleted']);
        $this->assertSame(self::MIB, $this->audiobookRow($result, $gone->id)['bytes']);
        $this->assertSame(1, $this->audiobookRow($result, $broken->id)['meta']['missing_parts']);
        $this->assertSame(0, $this->audiobookRow($result, $broken->id)['bytes']);
        // The library bucket is the folder (no cover: covers are on the public disk), not the manga pages.
        $this->assertSame(4 * self::MIB, $this->bucket($result, 'audiobooks')['bytes']);
        $this->assertSame(0, $this->bucket($result, 'manga_pages')['bytes']);

        $usage->store($result);
        $report = $usage->report();

        // The Audiobooks medium: count, hours, MB per listening hour.
        $audio = $report['mediums'][StorageUsage::MEDIUM_AUDIOBOOK];
        $this->assertSame(3, $audio['series']);
        $this->assertSame(4, $audio['chapters'], 'parts');
        $this->assertSame(4 * self::MIB + 350, $audio['bytes']);
        $this->assertSame(4 * self::MIB, $audio['content_bytes']);
        $this->assertSame(350, $audio['cover_bytes']);
        $this->assertSame(2.0, $audio['hours']); // 0.5 + 1 + 0.5 h
        $this->assertSame(2.7, $audio['mb_per_hour']); // 4 MiB over 1.5 h (the broken one left out)
        $this->assertSame(1, $audio['deleted']);
        $this->assertSame(1, $audio['missing_parts']);
        // Medium totals never count the audiobook twice: Books is the book alone.
        $this->assertSame(1000, $report['mediums'][Novel::MEDIUM_BOOK]['bytes']);

        // The Audiobooks tab: biggest first, the empty one left out.
        $top = $report['top'][StorageUsage::MEDIUM_AUDIOBOOK];
        $this->assertSame([$audiobook->id, $gone->id], array_column($top, 'audiobook_id'));
        $this->assertSame('Michael Kramer', $top[0]['narrator']);
        $this->assertSame(6.0, $top[0]['mb_per_hour']); // 3 MiB in half an hour
        $this->assertSame(0.5, $top[0]['hours']);

        // The book's figures carry its audiobook.
        $series = StorageUsage::forSeries($book->id);
        $this->assertSame(1000, $series['bytes']);
        $this->assertSame(3 * self::MIB + 350, $series['audiobook_bytes']);
        $this->assertSame(3 * self::MIB + 1350, $series['total_bytes']);

        $page = $this->get(route('health.storage'))->assertOk();
        $page->assertSee('Web novels, manga, books, comics and audiobooks')
            ->assertSee('data-medium="audiobook"', false)
            ->assertSee('data-top="audiobook"', false)
            ->assertSee('data-top-table="audiobook"', false)
            ->assertSee('data-audiobook="' . $audiobook->id . '"', false)
            ->assertSee('Michael Kramer')
            ->assertSee('6.0 MB/h')
            ->assertSee('2.7 MB/h')
            ->assertSee('2.0 h of listening', false)
            ->assertSee('standalone (no book)')
            ->assertSee('1 part missing on disk')
            ->assertSee('Audiobook library');

        $this->get(route('novels.show', $book->id))->assertOk()
            ->assertSee('3.0 MB')
            ->assertSee('audiobook 3.0 MB');
    }

    public function test_a_book_and_its_audiobook_are_ranked_together_with_the_split_shown(): void
    {
        $this->audioPlaces();
        // The novel's own files are bigger than the book's, but not than the book + its audiobook.
        $novel = $this->novel('Big Text Novel');
        $this->putFile(GenerateePub::epubPath($novel), 5000);
        $book = $this->novel('Small Book', Novel::MEDIUM_BOOK);
        $this->putFile(GenerateePub::epubPath($book), 1000);
        $this->audiobook('Small Book Audio', [[6000, 60_000], [4000, 60_000]], $book);

        $usage = app(StorageUsage::class);
        $usage->store($usage->measure());
        $report = $usage->report();

        $this->assertSame([$book->id, $novel->id], array_column($report['top']['all'], 'novel_id'));
        $this->assertSame(11000, $report['top']['all'][0]['total_bytes']);
        $this->assertSame(1000, $report['top']['all'][0]['bytes']);
        $this->assertSame(10000, $report['top']['all'][0]['audiobook_bytes']);
        $this->assertSame([$book->id], array_column($report['top'][Novel::MEDIUM_BOOK], 'novel_id'));

        $this->get(route('health.storage'))->assertOk()
            ->assertSeeInOrder(['data-top-table="all"', 'data-series="' . $book->id . '"', 'data-series="' . $novel->id . '"'], false)
            ->assertSee('audiobook 9.8 KB')
            ->assertSee('10.7 KB')
            ->assertSee('Book + audiobook');
    }

    public function test_uploads_and_download_inboxes_are_their_own_buckets_not_double_counted(): void
    {
        $places = $this->audioPlaces();
        config(['novarr.storage.share_external' => true]); // the inboxes are the Unraid share
        $this->audiobook('In The Library', [[1000, 60_000]]);
        // Part 1's uploads waiting for the owner: inside the library folder, not the library.
        $this->putFile("{$places['library']}/_uploads/7/files/0.part", 400);
        // The inboxes: a torrent still seeding, a usenet download, an unreadable file, an ebook torrent.
        $this->putFile("{$places['ab_inbox']}/Torrent Book/part1.mp3", 1500);
        $this->putFile("{$places['ab_inbox']}/Torrent Book/part2.mp3", 500);
        $this->putFile("{$places['ab_inbox']}/Usenet Book.m4b", 700);
        $this->putFile("{$places['ab_inbox']}/_failed/bad.mp3", 50);
        $this->putFile("{$places['books_inbox']}/Some Ebook.epub", 300);
        $this->fakeTransmission(['Torrent Book', ' some ebook.epub ', 'Elsewhere']);

        $usage = app(StorageUsage::class);
        $result = $usage->measure();

        $this->assertSame(1000, $this->bucket($result, 'audiobooks')['bytes'], 'the uploads are not in the library');
        $this->assertSame(1, $this->bucket($result, 'audiobooks')['files']);
        $this->assertSame(400, $this->bucket($result, 'audiobook_uploads')['bytes']);
        $this->assertSame(0, $this->bucket($result, 'manga_pages')['bytes'], 'nor in the manga pages');
        $inboxes = $this->bucket($result, 'download_inboxes');
        $this->assertSame(1500 + 500 + 700 + 50 + 300, $inboxes['bytes']);
        $this->assertSame(5, $inboxes['files']);
        $this->assertTrue($inboxes['meta']['external']);
        $this->assertFalse($inboxes['meta']['partial']);
        $this->assertSame('ok', $inboxes['meta']['seeding']['status']);
        $this->assertSame(2000 + 300, $inboxes['meta']['seeding']['bytes']);
        $this->assertSame(2, $inboxes['meta']['seeding']['entries']);

        $usage->store($result);
        $report = $usage->report();
        // Out of Novarr's buckets and total, in the share's list.
        $this->assertNotContains('download_inboxes', array_column($report['buckets'], 'key'));
        $this->assertSame(['download_inboxes'], array_column($report['share_buckets'], 'key'));
        $this->assertContains('audiobook_uploads', array_column($report['buckets'], 'key'));

        $this->get(route('health.storage'))->assertOk()
            ->assertSee('id="storageShare"', false)
            ->assertSee('data-bucket="audiobook_uploads"', false)
            ->assertSee('Audiobook uploads waiting')
            ->assertSee('kept for seeding')
            ->assertSee('2.2 KB')                // the seeding part
            ->assertSee('On the Unraid Download share (NFS)', false)
            ->assertSeeInOrder(['id="storageBuckets"', 'Where it goes', 'id="storageShare"', 'data-bucket="download_inboxes"'], false);

        // The same inboxes on Novarr's own disk (as in development): a normal bucket, counted.
        config(['novarr.storage.share_external' => false]);
        $usage->store($usage->measure());
        $this->assertContains('download_inboxes', array_column($usage->report()['buckets'], 'key'));
        $this->assertSame([], $usage->report()['share_buckets']);
    }

    public function test_the_seeding_share_is_unknown_when_transmission_cannot_be_asked(): void
    {
        $places = $this->audioPlaces();
        $this->putFile("{$places['ab_inbox']}/Torrent Book.m4b", 900);
        $this->fakeTransmission(null);

        $usage = app(StorageUsage::class);
        $result = $usage->measure();
        $seeding = $this->bucket($result, 'download_inboxes')['meta']['seeding'];

        $this->assertSame('unknown', $seeding['status']);
        $this->assertSame(0, $seeding['bytes']);
        $this->assertSame(900, $this->bucket($result, 'download_inboxes')['bytes'], 'still measured');

        $usage->store($result);
        $this->get(route('health.storage'))->assertOk()
            ->assertSee('data-seeding="unknown"', false)
            ->assertSee('Seeding share unknown');
    }

    public function test_the_inbox_walk_is_time_boxed(): void
    {
        $places = $this->audioPlaces();
        $this->putFile("{$places['ab_inbox']}/A.m4b", 100);
        $this->putFile("{$places['ab_inbox']}/B/b.mp3", 100);

        // A deadline already passed: nothing counted, marked partial.
        $walk = app(StorageUsage::class)->walk($places['ab_inbox'], [], microtime(true) - 1);
        $this->assertTrue($walk['partial']);
        $this->assertSame(0, $walk['files']);
        $this->assertFalse(app(StorageUsage::class)->walk($places['ab_inbox'])['partial']);

        config(['novarr.storage.share_walk_seconds' => 0]);
        $usage = app(StorageUsage::class);
        $usage->store($usage->measure());
        $bucket = collect($usage->report()['share_buckets'] ?: $usage->report()['buckets'])->firstWhere('key', 'download_inboxes');

        $this->assertTrue($bucket['meta']['partial']);
        $this->get(route('health.storage'))->assertOk()->assertSee('data-partial="1"', false)->assertSee('Partial:');
    }

    public function test_the_unraid_share_has_its_own_disk_line_and_is_in_the_low_space_alert(): void
    {
        $places = $this->audioPlaces();
        File::makeDirectory($places['ab_inbox'], 0755, true);
        File::makeDirectory($places['books_inbox'], 0755, true);
        config(['novarr.storage.share_external' => true]);

        $volumes = app(StorageUsage::class)->volumes();
        $shares = array_values(array_filter($volumes, fn($v) => !empty($v['meta']['share'])));
        $this->assertCount(1, $shares, 'both inboxes on the same share: one line');
        $this->assertSame(StorageUsage::SHARE_LABEL, $shares[0]['meta']['title']);
        $this->assertSame([$places['ab_inbox'], $places['books_inbox']], $shares[0]['meta']['paths']);
        $this->assertGreaterThan(0, $shares[0]['meta']['total']);
        $this->assertNotNull($shares[0]['meta']['free']);
        // Novarr's own disks do not name the inboxes.
        foreach (array_filter($volumes, fn($v) => empty($v['meta']['share'])) as $v) {
            $this->assertNotContains('Download inboxes', $v['meta']['labels']);
        }

        // Low on the share: the owner is told about it.
        $alert = $this->captureAlerts();
        config(['novarr.storage.low_free_percent' => 100]);
        $this->artisan('storage:measure')->assertExitCode(0);
        $this->assertCount(1, $alert->sent);
        $this->assertStringContainsString(StorageUsage::SHARE_LABEL . ' has', $alert->sent[0]['message']);
        $this->assertContains(StorageUsage::SHARE_LABEL, array_column($alert->sent[0]['mail']['items'], 'title'));
        $this->assertStringContainsString('downloads fail', $alert->sent[0]['mail']['intro']);

        $this->get(route('health.storage'))->assertOk()
            ->assertSee('data-share="1"', false)
            ->assertSee('Unraid Download share')
            ->assertSee('not one of Novarr\'s disks', false);
    }

    public function test_an_unmounted_share_is_told_and_never_low(): void
    {
        $this->audioPlaces(); // the inbox folders do not exist (the NFS mount is not there)
        config(['novarr.storage.low_free_percent' => 100]);

        $shares = array_values(array_filter(app(StorageUsage::class)->volumes(), fn($v) => !empty($v['meta']['share'])));

        $this->assertCount(1, $shares);
        $this->assertTrue($shares[0]['meta']['missing']);
        $this->assertFalse($shares[0]['meta']['low']);
        $this->assertNull($shares[0]['meta']['total']);

        $this->artisan('storage:measure')->assertExitCode(0);
        $this->get(route('health.storage'))->assertOk()->assertSee('Not mounted');
    }

    public function test_the_big_drive_is_named_by_what_is_on_it(): void
    {
        $this->assertSame('Big drive: manga, audiobooks, Calibre books, backups',
            StorageUsage::volumeTitle(['Manga pages', 'Audiobooks', 'Calibre books', 'Backups']));
        $this->assertSame('App storage + Manga pages', StorageUsage::volumeTitle(['App storage', 'Manga pages']));
        $this->assertSame('Backups', StorageUsage::volumeTitle(['Backups']));
    }
}
