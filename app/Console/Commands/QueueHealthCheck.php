<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Queue + scheduler health: backend reachability, how long the oldest pending
 * job on each worker queue has been waiting, failed jobs, and the scheduler
 * heartbeat (routes/console.php writes `scheduler_last_run` every minute; the
 * queue worker itself is started by the scheduler, so a dead scheduler means
 * nothing gets processed).
 */
class QueueHealthCheck extends Command
{
    /** The queues the scheduled worker drains, in priority order. */
    public const QUEUES = ['commands', 'default'];

    protected $signature = 'queue:health-check
        {--max-age=60 : Minutes a pending job may wait before the queue counts as stuck}
        {--heartbeat=3 : Minutes since the last scheduler heartbeat before the scheduler counts as stalled}';

    protected $description = 'Check the health of the queue system and the scheduler';

    public function handle(): int
    {
        $issues = [];
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");
        $maxAge = max(1, (int) $this->option('max-age'));

        $this->info("Queue connection: {$connection} ({$driver})");

        if ($driver === 'redis') {
            $issues = array_merge($issues, $this->checkRedis($connection));
        }

        foreach (self::QUEUES as $queue) {
            try {
                $size = Queue::connection($connection)->size($queue);
            } catch (Throwable $e) {
                $issues[] = "Could not read queue '{$queue}': " . $e->getMessage();
                $this->error("Queue '{$queue}': UNREADABLE - " . $e->getMessage());
                continue;
            }

            $age = $this->oldestPendingAgeMinutes($connection, $driver, $queue);
            $ageText = $age === null
                ? ($size > 0 && $driver !== 'database' ? 'age unknown for this driver' : 'none waiting')
                : "oldest waiting {$age} min";
            $this->line("Queue '{$queue}': {$size} pending ({$ageText})");

            if ($age !== null && $age > $maxAge) {
                $issues[] = "Queue '{$queue}': oldest pending job has waited {$age} min (limit {$maxAge})";
            }
        }

        $issues = array_merge($issues, $this->checkSchedulerHeartbeat());
        $this->reportFailedJobs();

        $this->newLine();
        if (empty($issues)) {
            $this->info('Queue health check: PASSED');
            return self::SUCCESS;
        }

        $this->error('Queue health check: FAILED');
        foreach ($issues as $issue) {
            $this->error('  - ' . $issue);
        }

        return self::FAILURE;
    }

    /**
     * Minutes the oldest available, unreserved job on $queue has been waiting,
     * or null when nothing is waiting / the driver doesn't record it.
     */
    protected function oldestPendingAgeMinutes(string $connection, ?string $driver, string $queue): ?int
    {
        if ($driver !== 'database') {
            return null; // Redis payloads carry no enqueue time.
        }

        $config = config("queue.connections.{$connection}");
        $now = Carbon::now()->getTimestamp();

        try {
            $oldest = DB::connection($config['connection'] ?? null)
                ->table($config['table'] ?? 'jobs')
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->min('available_at');
        } catch (Throwable $e) {
            $this->warn("Could not read the oldest job on '{$queue}': " . $e->getMessage());
            return null;
        }

        return $oldest === null ? null : intdiv(max(0, $now - (int) $oldest), 60);
    }

    /** @return string[] issues */
    protected function checkRedis(string $connection): array
    {
        try {
            $redis = Redis::connection(config("queue.connections.{$connection}.connection", 'default'));
            $pong = $redis->ping();
        } catch (Throwable $e) {
            $this->error('Redis connection: FAILED - ' . $e->getMessage());
            return ['Redis connection error: ' . $e->getMessage()];
        }

        if (!$pong) {
            $this->error('Redis connection: FAILED');
            return ['Redis ping failed'];
        }

        $this->info('Redis connection: OK');
        return [];
    }

    /** @return string[] issues */
    protected function checkSchedulerHeartbeat(): array
    {
        $limit = max(1, (int) $this->option('heartbeat'));
        $lastRun = Cache::get('scheduler_last_run');

        if (!$lastRun) {
            $this->error('Scheduler heartbeat: never recorded');
            return ['Scheduler heartbeat missing — is `schedule:run` in cron?'];
        }

        $last = Carbon::parse($lastRun);
        $minutes = (int) floor($last->diffInSeconds(Carbon::now(), true) / 60);

        if ($last->lt(Carbon::now()->subMinutes($limit))) {
            $this->error("Scheduler heartbeat: STALE (last {$last->toDateTimeString()}, {$minutes} min ago)");
            return ["Scheduler heartbeat is {$minutes} min old (limit {$limit})"];
        }

        $this->info("Scheduler heartbeat: OK (last {$last->toDateTimeString()})");
        return [];
    }

    protected function reportFailedJobs(): void
    {
        try {
            if (!Schema::hasTable('failed_jobs')) {
                return;
            }
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            $this->warn('Failed jobs table not readable: ' . $e->getMessage());
            return;
        }

        $failed > 0
            ? $this->warn("Failed jobs: {$failed}")
            : $this->info('Failed jobs: 0');
    }
}
