<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Public (unauthenticated) health probes under /api/health.
 *
 * Responses only carry a status per service and a latency — never exception
 * messages, connection names or filesystem paths. Failures are logged with
 * the detail instead. Redis is only checked when a configured cache store,
 * the session driver or the queue connection actually uses it.
 */
class HealthController extends Controller
{
    /**
     * Bare liveness probe. A controller method (not a route closure) so the
     * route table stays cacheable via route:cache.
     */
    public function ping()
    {
        return response('pong', 200);
    }

    /**
     * Health of every service the app depends on.
     */
    public function index(): JsonResponse
    {
        $services = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
        ];

        if (self::redisInUse()) {
            $services['redis'] = $this->checkRedis();
        }

        $healthy = collect($services)->every(fn ($s) => $s['status'] === 'healthy');

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'app' => config('app.name'),
            'version' => config('app.version', '1.0.0'),
            'services' => $services,
        ], $healthy ? 200 : 503);
    }

    /**
     * Database connectivity check.
     */
    public function database(): JsonResponse
    {
        return $this->single('database', $this->checkDatabase());
    }

    /**
     * Cache connectivity check (plus Redis when it backs anything).
     */
    public function cache(): JsonResponse
    {
        $health = $this->checkCache();

        if (self::redisInUse() && $health['status'] === 'healthy') {
            $redis = $this->checkRedis();
            if ($redis['status'] !== 'healthy') {
                $health = $redis;
            }
        }

        return $this->single('cache', $health);
    }

    /**
     * Whether Redis backs the default cache store, the session store or the
     * default queue connection.
     */
    public static function redisInUse(): bool
    {
        $cacheStore = config('cache.default');
        $queueConnection = config('queue.default');

        return config("cache.stores.{$cacheStore}.driver") === 'redis'
            || config('session.driver') === 'redis'
            || config("queue.connections.{$queueConnection}.driver") === 'redis';
    }

    private function single(string $service, array $health): JsonResponse
    {
        return response()->json([
            'status' => $health['status'],
            'timestamp' => now()->toIso8601String(),
            'service' => $service,
            'details' => $health,
        ], $health['status'] === 'healthy' ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        return $this->timed('database', function () {
            DB::connection()->getPdo();
            return true;
        });
    }

    /** Round-trip a short-lived key through the default cache store. */
    private function checkCache(): array
    {
        return $this->timed('cache', function () {
            $key = 'health:' . Str::random(12);
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);
            return $ok;
        });
    }

    private function checkRedis(): array
    {
        return $this->timed('redis', function () {
            $response = Redis::connection()->ping();

            return $response === true
                || $response === 'PONG'
                || $response === '+PONG'
                || (is_object($response) && method_exists($response, 'getPayload') && $response->getPayload() === 'PONG');
        });
    }

    private function checkStorage(): array
    {
        $path = storage_path();
        $ok = is_dir($path) && is_writable($path);

        if (!$ok) {
            Log::warning('Health check: storage directory is missing or not writable.');
        }

        return ['status' => $ok ? 'healthy' : 'unhealthy'];
    }

    /**
     * Run a probe, timing it. Any exception (or a false result) is logged in
     * full and reported publicly only as "unhealthy".
     */
    private function timed(string $service, callable $probe): array
    {
        $start = microtime(true);

        try {
            $ok = (bool) $probe();
        } catch (Throwable $e) {
            Log::warning("Health check: {$service} failed: " . $e->getMessage());
            return ['status' => 'unhealthy'];
        }

        if (!$ok) {
            Log::warning("Health check: {$service} returned an unexpected response.");
            return ['status' => 'unhealthy'];
        }

        return [
            'status' => 'healthy',
            'latency_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }
}
