<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateePub;
use App\Http\Helpers\CacheHelper;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The native app's EPUB endpoints: GET/POST /api/v1/novels/{id}/epub.
 */
class ApiV1EpubTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['status', 'stale', 'generated_at', 'size', 'filename', 'download_url', 'message'];

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /** @var string[] EPUB files a test wrote (fake or real), removed in tearDown. */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                File::delete($path);
            }
        }
        parent::tearDown();
    }

    private function novel(string $name = 'Api Epub Test Novel', array $attrs = []): Novel
    {
        $novel = Novel::create(['name' => $name, 'author' => 'Someone', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);
        if ($attrs) {
            $novel->forceFill($attrs)->save();
        }
        $this->written[] = GenerateePub::epubPath($novel);

        return $novel;
    }

    private function chapter(Novel $novel, float $n, array $attrs = [], ?string $content = null): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
            'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/c-{$n}",
        ]);
        $chapter->forceFill(array_merge(['status' => 1, 'download_date' => now()->subDay()], $attrs));
        if ($content !== null) {
            $chapter->description = $content;
        }
        $chapter->save();

        return $chapter;
    }

    /** A fake EPUB on disk, as a finished build leaves it. */
    private function fakeEpub(Novel $novel, string $bytes = 'epub-bytes'): string
    {
        File::ensureDirectoryExists(storage_path('app/ePub'));
        $path = GenerateePub::epubPath($novel);
        File::put($path, $bytes);
        $novel->forceFill(['epub_generated' => now()])->save();

        return $path;
    }

    private function epub(Novel $novel): array
    {
        $json = $this->getJson("/api/v1/novels/{$novel->id}/epub")->assertOk()->json('epub');
        $this->assertEqualsCanonicalizing(self::KEYS, array_keys($json));

        return $json;
    }

    /** The job id of the single RunNovelCommand pushed so far. */
    private function pushedJobId(): string
    {
        $jobs = Queue::pushed(RunNovelCommand::class);
        $this->assertCount(1, $jobs);

        return $jobs->first()->jobId;
    }

    private function finish(string $jobId, bool $success, array $extra = []): void
    {
        Cache::put("command_result_{$jobId}", array_merge([
            'success' => $success,
            'exit_code' => $success ? 0 : 1,
            'output' => '',
            'command' => 'novel:epub',
            'completed_at' => now()->toIso8601String(),
        ], $extra), now()->addHour());
    }

    public function test_routes_are_named_in_the_v1_group(): void
    {
        $this->assertSame(url('/api/v1/novels/5/epub'), route('api.v1.novels.epub', 5));
        $this->assertSame(url('/api/v1/novels/5/epub'), route('api.v1.novels.epub.build', 5));
    }

    public function test_unknown_novel_is_404_on_both_verbs(): void
    {
        $missing = (int) Novel::max('id') + 1000;

        $this->getJson("/api/v1/novels/{$missing}/epub")->assertNotFound();
        $this->postJson("/api/v1/novels/{$missing}/epub")->assertNotFound();
    }

    public function test_get_reports_missing_when_no_file_exists(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $this->assertSame([
            'status' => 'missing', 'stale' => false, 'generated_at' => null, 'size' => null,
            'filename' => null, 'download_url' => null, 'message' => null,
        ], $this->epub($novel));
    }

    public function test_missing_explains_when_nothing_is_downloaded(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1, ['status' => 0, 'download_date' => null]);
        $this->chapter($novel, 2, ['blacklist' => 1]); // blacklisted chapters are never in the EPUB

        $epub = $this->epub($novel);
        $this->assertSame('missing', $epub['status']);
        $this->assertSame('This novel has no downloaded chapters yet.', $epub['message']);
    }

    public function test_post_without_downloaded_chapters_is_422(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1, ['status' => 0, 'download_date' => null]);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This novel has no downloaded chapters yet.']);

        Queue::assertNothingPushed();
    }

    public function test_post_starts_one_build_and_a_second_post_does_not_dispatch_again(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $epub = $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202)->json('epub');
        $this->assertSame('building', $epub['status']);
        $this->assertNull($epub['download_url']);

        Queue::assertPushedOn('commands', RunNovelCommand::class, function (RunNovelCommand $job) use ($novel) {
            return $job->artisanCommand === 'novel:epub' && $job->params === ['novel' => (int) $novel->id];
        });
        Queue::assertPushed(RunNovelCommand::class, 1);

        // The build record holds the job id and start time.
        $record = Cache::get(CacheHelper::apiEpubBuildKey($novel->id));
        $this->assertSame($this->pushedJobId(), $record['job_id']);
        $this->assertNotEmpty($record['started_at']);

        $this->assertSame('building', $this->epub($novel)['status']);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202)->assertJsonPath('epub.status', 'building');
        $this->postJson("/api/v1/novels/{$novel->id}/epub", ['force' => true])->assertStatus(202);
        Queue::assertPushed(RunNovelCommand::class, 1);
    }

    public function test_finished_build_reports_ready_with_the_file(): void
    {
        Queue::fake();
        $novel = $this->novel('Api Epub Ready', ['author' => 'Writer']);
        $this->chapter($novel, 1);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202);
        $this->fakeEpub($novel, str_repeat('x', 1234));
        $this->finish($this->pushedJobId(), true);

        $epub = $this->epub($novel);
        $this->assertSame('ready', $epub['status']);
        $this->assertFalse($epub['stale']);
        $this->assertSame(1234, $epub['size']);
        $this->assertSame('Api Epub Ready - Writer.epub', $epub['filename']);
        $this->assertMatchesRegularExpression(self::TIMESTAMP, $epub['generated_at']);
        $this->assertSame(url("/novels/{$novel->id}/epub"), $epub['download_url']);
        $this->assertNull($epub['message']);

        // The URL is on the host the request came in on, not APP_URL.
        $other = $this->getJson("http://tablet.example.ts.net:8443/api/v1/novels/{$novel->id}/epub")->json('epub');
        $this->assertSame("http://tablet.example.ts.net:8443/novels/{$novel->id}/epub", $other['download_url']);

        // Up to date: a POST has nothing to do.
        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertOk()->assertJsonPath('epub.status', 'ready');
        Queue::assertPushed(RunNovelCommand::class, 1);
    }

    public function test_failed_build_reports_failed_and_post_starts_again(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202);
        $this->finish($this->pushedJobId(), false, [
            'output' => "[1/1] Processing: X\n  Error: Disk full\nePub generation finished with 1 failure(s) (novel id: 1).",
        ]);

        $epub = $this->epub($novel);
        $this->assertSame('failed', $epub['status']);
        $this->assertSame('The EPUB build failed: Disk full.', $epub['message']);
        $this->assertNull($epub['download_url']);

        // Still failed after the 30-minute build record has expired.
        Cache::forget(CacheHelper::apiEpubBuildKey($novel->id));
        $this->assertSame('failed', $this->epub($novel)['status']);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202)->assertJsonPath('epub.status', 'building');
        Queue::assertPushed(RunNovelCommand::class, 2);
    }

    public function test_a_thrown_build_reports_its_error(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202);
        $this->finish($this->pushedJobId(), false, ['error' => 'Something exploded']);

        $this->assertSame('The EPUB build failed: Something exploded.', $this->epub($novel)['message']);
    }

    public function test_failed_rebuild_keeps_offering_the_old_file_and_a_newer_file_clears_it(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1);
        $this->fakeEpub($novel);
        $novel->forceFill(['epub_generated' => now()->subHour()])->save();

        $this->postJson("/api/v1/novels/{$novel->id}/epub", ['force' => true])->assertStatus(202);
        // A rebuild in progress still offers the existing file.
        $building = $this->epub($novel);
        $this->assertSame('building', $building['status']);
        $this->assertNotNull($building['download_url']);
        $this->assertSame(10, $building['size']);

        $this->finish($this->pushedJobId(), false);
        $failed = $this->epub($novel);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('The EPUB build failed (exit code 1).', $failed['message']);
        $this->assertNotNull($failed['download_url']);

        // A newer file (e.g. built from the web UI) supersedes the failure.
        $this->travel(1)->minutes();
        $novel->forceFill(['epub_generated' => now()])->save();
        $this->assertSame('ready', $this->epub($novel)['status']);
    }

    public function test_chapter_downloaded_after_the_build_makes_it_stale_and_post_rebuilds(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1, ['download_date' => now()->subDays(2)]);
        $this->fakeEpub($novel);
        $novel->forceFill(['epub_generated' => now()->subDay()])->save();

        $this->assertFalse($this->epub($novel)['stale']);

        // Not downloaded / blacklisted chapters don't count.
        $this->chapter($novel, 2, ['status' => 0, 'download_date' => now()]);
        $this->chapter($novel, 3, ['blacklist' => 1, 'download_date' => now()]);
        $this->assertFalse($this->epub($novel)['stale']);

        $this->chapter($novel, 4, ['download_date' => now()]);
        $epub = $this->epub($novel);
        $this->assertSame('ready', $epub['status']);
        $this->assertTrue($epub['stale']);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202)->assertJsonPath('epub.status', 'building');
        Queue::assertPushed(RunNovelCommand::class, 1);
    }

    public function test_force_rebuilds_an_up_to_date_epub(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1);
        $this->fakeEpub($novel);

        $this->postJson("/api/v1/novels/{$novel->id}/epub", ['force' => false])->assertOk();
        Queue::assertNothingPushed();

        $this->postJson("/api/v1/novels/{$novel->id}/epub", ['force' => true])->assertStatus(202)->assertJsonPath('epub.status', 'building');
        Queue::assertPushed(RunNovelCommand::class, 1);
    }

    public function test_an_expired_build_record_no_longer_reports_building(): void
    {
        Queue::fake();
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202);
        $this->travel(31)->minutes();

        $this->assertSame('missing', $this->epub($novel)['status']);
    }

    public function test_end_to_end_build_on_the_sync_queue_then_download(): void
    {
        config(['queue.default' => 'sync']);
        $novel = $this->novel('Api Epub End To End', ['author' => 'Real Author']);
        $this->chapter($novel, 1, [], '<p>First chapter text.</p>');
        $this->chapter($novel, 2, [], '<p>Second chapter text.</p>');

        // The sync queue runs the job inside the request: done by the response.
        $epub = $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertStatus(202)->json('epub');
        $this->assertSame('ready', $epub['status'], (string) json_encode($epub));
        $this->assertFalse($epub['stale']);
        $this->assertSame('Api Epub End To End - Real Author.epub', $epub['filename']);
        $this->assertGreaterThan(0, $epub['size']);
        $this->assertNotNull($novel->fresh()->epub_generated);
        $this->assertTrue(Cache::get('command_result_' . Cache::get(CacheHelper::apiEpubBuildKey($novel->id))['job_id'])['success']);

        $download = $this->get($epub['download_url'])->assertOk()->assertDownload('Api Epub End To End - Real Author.epub');
        $this->assertSame($epub['size'], (int) filesize($download->baseResponse->getFile()->getPathname()));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(GenerateePub::epubPath($novel)) === true);
        $this->assertSame('application/epub+zip', $zip->getFromName('mimetype'));
        $zip->close();

        // Now up to date.
        $this->postJson("/api/v1/novels/{$novel->id}/epub")->assertOk()->assertJsonPath('epub.status', 'ready');
    }
}
