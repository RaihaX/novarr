<?php

namespace Tests\Feature;

use App\Jobs\FinishNovelCheck;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use App\Services\NovelCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * POST/GET /api/v1/novels/{id}/check: "check for new chapters now".
 */
class ApiV1CheckTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['status', 'started_at', 'finished_at', 'chapters_before', 'chapters_now', 'new_chapters', 'message'];

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        if ($this->name() !== 'testTheChainRunsEndToEndOnTheSyncQueue') {
            Bus::fake();
        }
    }

    private function novel(int $chapters = 0): Novel
    {
        $novel = Novel::create(['name' => 'Check Test Novel', 'status' => 0, 'group_id' => 1]);
        $this->addChapters($novel, $chapters);

        return $novel;
    }

    private function addChapters(Novel $novel, int $count, array $attrs = []): void
    {
        $from = NovelChapter::withTrashed()->where('novel_id', $novel->id)->count() + 1;
        for ($n = $from; $n < $from + $count; $n++) {
            $chapter = NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
                'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/c-{$n}",
            ]);
            if ($attrs) {
                $chapter->forceFill($attrs)->save();
            }
        }
    }

    private function check(Novel $novel): array
    {
        $json = $this->getJson("/api/v1/novels/{$novel->id}/check")->assertOk()->json('check');
        $this->assertSame(self::KEYS, array_keys($json));

        return $json;
    }

    private function record(Novel $novel): array
    {
        return Cache::get(NovelCheck::key($novel->id));
    }

    private function finish(string $jobId, bool $success = true, string $error = ''): void
    {
        Cache::put("command_result_{$jobId}", [
            'success' => $success, 'exit_code' => $success ? 0 : 1, 'output' => '', 'error' => $error,
            'command' => 'x', 'completed_at' => now()->toIso8601String(),
        ], now()->addHour());
    }

    public function testIdleWithoutARecord(): void
    {
        $novel = $this->novel(3);

        $this->assertSame([
            'status' => 'idle', 'started_at' => null, 'finished_at' => null,
            'chapters_before' => null, 'chapters_now' => 3, 'new_chapters' => 0, 'message' => null,
        ], $this->check($novel));
    }

    public function testStartQueuesTheTocAndChapterScrapesAsTheNovelPageDoes(): void
    {
        $novel = $this->novel(2);

        $response = $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);

        $check = $response->json('check');
        $this->assertSame(self::KEYS, array_keys($check));
        $this->assertSame('running', $check['status']);
        $this->assertMatchesRegularExpression(self::TIMESTAMP, $check['started_at']);
        $this->assertNull($check['finished_at']);
        $this->assertSame(2, $check['chapters_before']);
        $this->assertSame(0, $check['new_chapters']);

        $record = $this->record($novel);
        Bus::assertChained([
            fn(RunNovelCommand $job) => $job->artisanCommand === 'novel:toc' && $job->params === ['novel' => $novel->id] && $job->jobId === $record['toc_job_id'],
            fn(RunNovelCommand $job) => $job->artisanCommand === 'novel:chapter' && $job->params === ['novel' => $novel->id, '--max-minutes' => \App\Services\NovelCheck::DOWNLOAD_MINUTES] && $job->jobId === $record['chapter_job_id'],
            fn(FinishNovelCheck $job) => $job->novelId === $novel->id && $job->checkId === $record['check_id'],
        ]);
        Bus::assertDispatched(RunNovelCommand::class, fn($job) => $job->queue === 'commands');
    }

    public function testASecondTapReturnsTheRunningCheck(): void
    {
        $novel = $this->novel();

        $first = $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202)->json('check');
        $this->travel(5)->seconds();
        $second = $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202)->json('check');

        $this->assertSame($first['started_at'], $second['started_at']);
        $this->assertSame('running', $second['status']);
        Bus::assertDispatchedTimes(RunNovelCommand::class, 1);
    }

    public function testDoneCountsTheNewChapters(): void
    {
        $novel = $this->novel(2);
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
        $record = $this->record($novel);

        // The TOC run found three chapters; the download is still running.
        $this->addChapters($novel, 3);
        $this->addChapters($novel, 1, ['blacklist' => 1]); // not in the API's list
        $this->finish($record['toc_job_id']);
        $check = $this->check($novel);
        $this->assertSame('running', $check['status']);
        $this->assertSame(5, $check['chapters_now']);
        $this->assertSame(3, $check['new_chapters']);

        $this->finish($record['chapter_job_id']);
        $check = $this->check($novel);
        $this->assertSame('done', $check['status']);
        $this->assertMatchesRegularExpression(self::TIMESTAMP, $check['finished_at']);
        $this->assertSame(2, $check['chapters_before']);
        $this->assertSame(5, $check['chapters_now']);
        $this->assertSame(3, $check['new_chapters']);
        $this->assertNull($check['message']);
    }

    public function testFinishJobRecordsTheOutcomeBeyondTheJobResults(): void
    {
        $novel = $this->novel(1);
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
        $record = $this->record($novel);
        $this->finish($record['toc_job_id']);
        $this->finish($record['chapter_job_id']);

        (new FinishNovelCheck($novel->id, $record['check_id']))->handle(app(NovelCheck::class));

        // The command results expire after an hour; the check stays done.
        Cache::forget("command_result_{$record['toc_job_id']}");
        Cache::forget("command_result_{$record['chapter_job_id']}");
        $this->travel(5)->hours();
        $this->assertSame('done', $this->check($novel)['status']);

        // ...for about a day.
        $this->travel(25)->hours();
        $this->assertSame('idle', $this->check($novel)['status']);
    }

    public function testAFinishedCheckCanBeStartedAgain(): void
    {
        $novel = $this->novel();
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
        $record = $this->record($novel);
        $this->finish($record['toc_job_id']);
        $this->finish($record['chapter_job_id']);
        $this->assertSame('done', $this->check($novel)['status']);

        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202)->assertJsonPath('check.status', 'running');
        $this->assertNotSame($record['check_id'], $this->record($novel)['check_id']);
        Bus::assertDispatchedTimes(RunNovelCommand::class, 2);
    }

    public function testAFailedTocRunFailsTheCheck(): void
    {
        $novel = $this->novel();
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
        $this->finish($this->record($novel)['toc_job_id'], false, 'Source returned HTTP 500');

        $check = $this->check($novel);
        $this->assertSame('failed', $check['status']);
        $this->assertSame('The check failed: Source returned HTTP 500.', $check['message']);
        $this->assertNotNull($check['finished_at']);
    }

    public function testASkippedRunSaysAnotherTaskIsUpdatingTheNovel(): void
    {
        $novel = $this->novel();
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
        $this->finish($this->record($novel)['toc_job_id'], false, "Skipped: the same command is already running (novel:{$novel->id}).");

        $check = $this->check($novel);
        $this->assertSame('failed', $check['status']);
        $this->assertSame(NovelCheck::ALREADY_RUNNING, $check['message']);
    }

    public function testAFailedDownloadFailsTheCheck(): void
    {
        $novel = $this->novel();
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);
        $record = $this->record($novel);
        $this->finish($record['toc_job_id']);
        $this->finish($record['chapter_job_id'], false);

        $check = $this->check($novel);
        $this->assertSame('failed', $check['status']);
        $this->assertSame('The check failed (exit code 1).', $check['message']);
    }

    public function testAStaleRunningCheckBecomesFailed(): void
    {
        $novel = $this->novel();
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202);

        $this->travel(NovelCheck::STALE_AFTER_MINUTES - 1)->minutes();
        $this->assertSame('running', $this->check($novel)['status']);

        $this->travel(2)->minutes();
        $check = $this->check($novel);
        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('did not finish', $check['message']);

        // And a new tap starts a fresh run.
        $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202)->assertJsonPath('check.status', 'running');
        Bus::assertDispatchedTimes(RunNovelCommand::class, 2);
    }

    /**
     * The real chain on the sync queue, with nothing to fetch (a completed
     * novel, every chapter downloaded) and the network layer faked: the
     * scrapes run and FinishNovelCheck records "done" inside the request.
     */
    public function testTheChainRunsEndToEndOnTheSyncQueue(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $fetcher = new \Tests\Support\FakeFetcher();
        app()->instance(\App\Scraping\Fetcher::class, $fetcher);

        $novel = Novel::create(['name' => 'Finished Novel', 'status' => 1, 'group_id' => 1]);
        $this->addChapters($novel, 2, ['status' => 1, 'download_date' => now()]);

        $check = $this->postJson("/api/v1/novels/{$novel->id}/check")->assertStatus(202)->json('check');

        $this->assertSame('done', $check['status']);
        $this->assertSame(0, $check['new_chapters']);
        $this->assertSame([], $fetcher->unexpected);
        $this->assertSame('done', $this->record($novel)['status']);
    }

    public function testUnknownNovelIs404(): void
    {
        $this->getJson('/api/v1/novels/999/check')->assertNotFound();
        $this->postJson('/api/v1/novels/999/check')->assertNotFound();
        Bus::assertNothingDispatched();
    }

    public function testChecksArePerNovel(): void
    {
        $a = $this->novel();
        $b = Novel::create(['name' => 'Other', 'status' => 0, 'group_id' => 1]);

        $this->postJson("/api/v1/novels/{$a->id}/check")->assertStatus(202);

        $this->assertSame('idle', $this->check($b)['status']);
        $this->postJson("/api/v1/novels/{$b->id}/check")->assertStatus(202)->assertJsonPath('check.status', 'running');
        Bus::assertDispatchedTimes(RunNovelCommand::class, 2);
    }
}
