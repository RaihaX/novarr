<?php

namespace App\Http\Middleware;

use Closure;
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
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Enable query logging only in development/debugging
        if (config('app.debug')) {
            DB::enableQueryLog();
        }

        $response = $next($request);

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // Convert to MB

        // Log slow requests
        if ($executionTime > $this->slowRequestThreshold) {
            Log::warning('Slow request detected', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'execution_time_ms' => round($executionTime, 2),
                'memory_used_mb' => round($memoryUsed, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);
        }

        // Log slow queries in debug mode
        if (config('app.debug')) {
            $queries = DB::getQueryLog();
            foreach ($queries as $query) {
                if ($query['time'] > $this->slowQueryThreshold) {
                    Log::warning('Slow query detected', [
                        'sql' => $query['query'],
                        'bindings' => $query['bindings'],
                        'time_ms' => $query['time'],
                        'url' => $request->fullUrl(),
                    ]);
                }
            }
            DB::disableQueryLog();
        }

        return $response;
    }
}
