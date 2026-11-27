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

        $this->get(route('health.storage'))->assertOk()->assertSee('Web novels, manga and books')->assertSee('Book files');
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
}
