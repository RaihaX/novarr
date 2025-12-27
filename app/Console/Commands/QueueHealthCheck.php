<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class QueueHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:health-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the health of the queue system';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $healthy = true;
        $issues = [];

        // Check Redis connection (if using Redis queue)
        if (config('queue.default') === 'redis') {
            try {
                $pong = Redis::ping();
                if ($pong) {
                    $this->info('Redis connection: OK');
                } else {
                    $healthy = false;
                    $issues[] = 'Redis ping failed';
                    $this->error('Redis connection: FAILED');
                }
            } catch (\Exception $e) {
                $healthy = false;
                $issues[] = 'Redis connection error: ' . $e->getMessage();
                $this->error('Redis connection: FAILED - ' . $e->getMessage());
            }

            // Check queue size
            try {
                $queueName = config('queue.connections.redis.queue', 'default');
                $size = Redis::llen('queues:' . $queueName);
                $this->info("Queue '{$queueName}' size: {$size} jobs pending");

                // Check failed jobs queue
                $failedSize = Redis::llen('queues:' . $queueName . ':failed');
                if ($failedSize > 0) {
                    $this->warn("Failed jobs: {$failedSize}");
                }
            } catch (\Exception $e) {
                $this->warn('Could not check queue size: ' . $e->getMessage());
            }
        }

        // Check database connection for failed jobs table
        try {
            $failedCount = \DB::table('failed_jobs')->count();
            if ($failedCount > 0) {
                $this->warn("Failed jobs in database: {$failedCount}");
            } else {
                $this->info('Failed jobs in database: 0');
            }
        } catch (\Exception $e) {
            // Failed jobs table might not exist, which is fine
            $this->line('Failed jobs table not available (this is normal if not using database driver)');
        }

        // Summary
        if ($healthy) {
            $this->newLine();
            $this->info('Queue health check: PASSED');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->error('Queue health check: FAILED');
        foreach ($issues as $issue) {
            $this->error('  - ' . $issue);
        }

        return Command::FAILURE;
    }
}
