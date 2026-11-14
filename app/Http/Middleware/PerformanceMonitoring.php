<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceMonitoring
{
    /**
     * Threshold in milliseconds for slow queries.
     */
    protected int $slowQueryThreshold = 100;

    /**
     * Threshold in milliseconds for slow requests.
     */
    protected int $slowRequestThreshold = 1000;

    /**
     * The request currently being handled, for the slow-query listener (which
     * is registered once per application, not once per request).
     */
    protected static ?Request $current = null;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        // Peak for this request alone (matters under Octane / long-lived workers).
        memory_reset_peak_usage();

        // Debug only: log slow queries as they happen instead of holding a
        // full query log in memory for the whole request.
        if (config('app.debug')) {
            static::$current = $request;
            $this->listenForSlowQueries();
        }

        try {
            $response = $next($request);
        } finally {
            static::$current = null;
        }

        $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        $peakMemory = memory_get_peak_usage();
        $memoryUsed = max(0, $peakMemory - $startMemory) / 1024 / 1024; // Convert to MB

        // Log slow requests
        if ($executionTime > $this->slowRequestThreshold) {
            Log::warning('Slow request detected', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'execution_time_ms' => round($executionTime, 2),
                'memory_used_mb' => round($memoryUsed, 2),
                'peak_memory_mb' => round($peakMemory / 1024 / 1024, 2),
            ]);
        }

        return $response;
    }

    protected function listenForSlowQueries(): void
    {
        // Once per application instance (each test boots a fresh one).
        if (app()->bound('perf.slow_query_listener')) {
            return;
        }
        app()->instance('perf.slow_query_listener', true);

        $threshold = $this->slowQueryThreshold;

        DB::listen(function (QueryExecuted $query) use ($threshold) {
            if (static::$current === null || $query->time <= $threshold) {
                return;
            }

            Log::warning('Slow query detected', [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time_ms' => $query->time,
                'url' => static::$current->fullUrl(),
            ]);
        });
    }
}
