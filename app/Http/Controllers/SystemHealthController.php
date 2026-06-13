<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'queue_depth' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failed_jobs' => $failed,
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
