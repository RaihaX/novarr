<?php

namespace Tests\Unit;

use App\Jobs\RunNovelCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Same-novel UI-triggered commands must not run concurrently (audit F17).
 */
class RunNovelCommandOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function testOverlapKeys()
    {
        $this->assertSame('novel:5', RunNovelCommand::overlapKey('novel:chapter', ['novel' => 5]));
        $this->assertSame('novel:5', RunNovelCommand::overlapKey('novel:toc', ['novel' => '5']));
        $this->assertSame('novel:chapter:all', RunNovelCommand::overlapKey('novel:chapter', ['novel' => 0]));
        $this->assertSame('novel:toc:all', RunNovelCommand::overlapKey('novel:toc', []));
        $this->assertSame('chapter:42', RunNovelCommand::overlapKey('novel:chapter', ['--chapter' => 42]));
        $this->assertNull(RunNovelCommand::overlapKey('novel:info', ['name' => 'x']));
    }

    public function testDuplicateIsSkippedWithAResultForThePoller()
    {
        $lock = Cache::lock('laravel-queue-overlap:' . RunNovelCommand::class . ':novel:7', 60);
        $this->assertTrue($lock->get());

        try {
            RunNovelCommand::dispatchSync('novel:chapter', ['novel' => 7], 'job-dup');
        } finally {
            $lock->release();
        }

        $result = Cache::get('command_result_job-dup');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already running', $result['error']);
    }

    public function testRunsWhenNoOtherInstanceHoldsTheLock()
    {
        RunNovelCommand::dispatchSync('novel:chapter', ['novel' => 99999], 'job-ok');

        $result = Cache::get('command_result_job-ok');
        $this->assertTrue($result['success'], json_encode($result));

        // Lock released afterwards: a second run goes through too.
        RunNovelCommand::dispatchSync('novel:chapter', ['novel' => 99999], 'job-ok-2');
        $this->assertTrue(Cache::get('command_result_job-ok-2')['success']);
    }

    public function testQueueRetryAfterExceedsJobTimeout()
    {
        $timeout = (new RunNovelCommand('novel:chapter', [], 'x'))->timeout;

        $this->assertGreaterThan($timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($timeout, config('queue.connections.redis.retry_after'));
    }

    /** A job killed at its timeout never reaches the command's finally: failed() frees the novel lock. */
    public function testFailedReleasesTheNovelLockOfANovelScrape()
    {
        foreach (['novel:chapter', 'novel:toc'] as $command) {
            $held = \App\Console\Commands\ChapterScraper::novelLock(5);
            $this->assertTrue($held->get());

            (new RunNovelCommand($command, ['novel' => 5], 'job-timeout'))
                ->failed(new \Illuminate\Queue\TimeoutExceededException('timed out'));

            $again = \App\Console\Commands\ChapterScraper::novelLock(5);
            $this->assertTrue($again->get(), "{$command}: lock released");
            $again->release();
            $this->assertFalse(Cache::get('command_result_job-timeout')['success']);
        }
    }

    /** Commands that never take the novel lock leave it alone. */
    public function testFailedLeavesTheNovelLockAloneForOtherCommands()
    {
        $held = \App\Console\Commands\ChapterScraper::novelLock(5);
        $this->assertTrue($held->get());

        foreach ([
            ['novel:metadata', ['novel' => 5]],
            ['novel:chapter', ['--chapter' => 5]],
            ['novel:chapter', ['novel' => 0]],
            ['novel:info', ['name' => 'x']],
        ] as [$command, $params]) {
            (new RunNovelCommand($command, $params, 'job-other'))->failed(new \RuntimeException('x'));
            $this->assertFalse(\App\Console\Commands\ChapterScraper::novelLock(5)->get(), "{$command} left the lock held");
        }

        $held->release();
    }
}
