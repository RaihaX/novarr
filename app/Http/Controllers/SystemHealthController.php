<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Web-facing system status page: scheduler heartbeat, queue depth, failed
 * jobs (with retry/forget), and FlareSolverr reachability.
 */
class SystemHealthController extends Controller
{
    public function index()
    {
        $lastRun = Cache::get('scheduler_last_run');

        $failed = collect();
        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(50)
                ->get()
                ->map(fn($j) => (object) [
                    'uuid' => $j->uuid,
                    'queue' => $j->queue,
                    'failed_at' => $j->failed_at,
                    'exception' => strtok($j->exception, "\n"),
                ]);
        }

        return view('health.index', [
            'scheduler_last_run' => $lastRun,
            'scheduler_stale' => $lastRun ? \Carbon\Carbon::parse($lastRun)->lt(now()->subMinutes(3)) : true,
            'queue_depth' => $this->queueDepth(),
            'failed_jobs' => $failed,
        ]);
    }

    /**
     * Pending jobs on the two queues the worker drains (`commands`, then
     * `default`), via the configured queue connection so it is right for the
     * database and Redis drivers alike. An unreachable backend is reported
     * and shown as 0 (the view formats an int).
     */
    private function queueDepth(): int
    {
        try {
            return (int) Queue::size('commands') + (int) Queue::size('default');
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    /**
     * Full detail for one failed job: decoded command/params and the complete
     * exception trace.
     */
    public function failedJob(string $uuid)
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found'], 404);
        }

        $payload = json_decode($job->payload, true);
        $command = '(unknown)';
        $params = null;

        // RunNovelCommand serialises its public props into the payload's
        // command data; pull the artisan command + params out for display.
        $raw = $payload['data']['command'] ?? '';
        if (preg_match('/RunNovelCommand/', $raw)) {
            if (preg_match('/artisanCommand";s:\d+:"([^"]+)"/', $raw, $m)) {
                $command = $m[1];
            }
            // Reconstruct and unserialize just the params array for a clean
            // "key=value" display instead of raw serialized tokens.
            if (preg_match('/s:6:"params";(a:\d+:\{.*?\})s:5:"jobId"/s', $raw, $m)) {
                $arr = @unserialize($m[1], ['allowed_classes' => false]);
                if (is_array($arr)) {
                    $params = collect($arr)
                        ->map(fn($v, $k) => "{$k}=" . (is_scalar($v) ? $v : json_encode($v)))
                        ->implode(', ');
                }
            }
        }

        return response()->json([
            'success' => true,
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'connection' => $job->connection,
            'failed_at' => $job->failed_at,
            'display_name' => $payload['displayName'] ?? null,
            'command' => $command,
            'params' => $params,
            'exception' => $job->exception,
        ]);
    }

    public function retry(string $uuid)
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        return response()->json(['success' => true]);
    }

    public function retryAll()
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        return response()->json(['success' => true]);
    }

    public function forget(string $uuid)
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        return response()->json(['success' => true]);
    }

    public function flush()
    {
        Artisan::call('queue:flush');
        return response()->json(['success' => true]);
    }
}
