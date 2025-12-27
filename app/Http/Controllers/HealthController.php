<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Exception;

class HealthController extends Controller
{
    /**
     * Comprehensive health check for all services.
     */
    public function index(): JsonResponse
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'app' => config('app.name'),
            'version' => config('app.version', '1.0.0'),
            'services' => [],
        ];

        $allHealthy = true;

        // Check database
        $dbHealth = $this->checkDatabase();
        $health['services']['database'] = $dbHealth;
        if ($dbHealth['status'] !== 'healthy') {
            $allHealthy = false;
        }

        // Check Redis
        $redisHealth = $this->checkRedis();
        $health['services']['redis'] = $redisHealth;
        if ($redisHealth['status'] !== 'healthy') {
            $allHealthy = false;
        }

        // Check storage
        $storageHealth = $this->checkStorage();
        $health['services']['storage'] = $storageHealth;
        if ($storageHealth['status'] !== 'healthy') {
            $allHealthy = false;
        }

        // Check Voyager
        $voyagerHealth = $this->checkVoyager();
        $health['services']['voyager'] = $voyagerHealth;
        if ($voyagerHealth['status'] !== 'healthy') {
            $allHealthy = false;
        }

        if (!$allHealthy) {
            $health['status'] = 'unhealthy';
            return response()->json($health, 503);
        }

        return response()->json($health, 200);
    }

    /**
     * Database connectivity check.
     */
    public function database(): JsonResponse
    {
        $health = $this->checkDatabase();
        $statusCode = $health['status'] === 'healthy' ? 200 : 503;

        return response()->json([
            'status' => $health['status'],
            'timestamp' => now()->toIso8601String(),
            'service' => 'database',
            'details' => $health,
        ], $statusCode);
    }

    /**
     * Redis/Cache connectivity check.
     */
    public function cache(): JsonResponse
    {
        $health = $this->checkRedis();
        $statusCode = $health['status'] === 'healthy' ? 200 : 503;

        return response()->json([
            'status' => $health['status'],
            'timestamp' => now()->toIso8601String(),
            'service' => 'redis',
            'details' => $health,
        ], $statusCode);
    }

    /**
     * Check database connectivity.
     */
    private function checkDatabase(): array
    {
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
                'connection' => config('database.default'),
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'connection' => config('database.default'),
            ];
        }
    }

    /**
     * Check Redis connectivity.
     */
    private function checkRedis(): array
    {
        try {
            $startTime = microtime(true);
            $response = Redis::connection()->ping();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            $isHealthy = $response === true || $response === 'PONG' || (is_object($response) && method_exists($response, 'getPayload') && $response->getPayload() === 'PONG');

            if ($isHealthy) {
                return [
                    'status' => 'healthy',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status' => 'unhealthy',
                'error' => 'Unexpected ping response',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check storage directory is writable.
     */
    private function checkStorage(): array
    {
        $storagePath = storage_path();

        if (!is_dir($storagePath)) {
            return [
                'status' => 'unhealthy',
                'error' => 'Storage directory does not exist',
            ];
        }

        if (!is_writable($storagePath)) {
            return [
                'status' => 'unhealthy',
                'error' => 'Storage directory is not writable',
            ];
        }

        return [
            'status' => 'healthy',
            'path' => $storagePath,
        ];
    }

    /**
     * Check Voyager admin panel status.
     */
    private function checkVoyager(): array
    {
        try {
            // Check if Voyager tables exist
            $tablesExist = DB::getSchemaBuilder()->hasTable('data_types')
                && DB::getSchemaBuilder()->hasTable('data_rows')
                && DB::getSchemaBuilder()->hasTable('menus')
                && DB::getSchemaBuilder()->hasTable('roles');

            if (!$tablesExist) {
                return [
                    'status' => 'unhealthy',
                    'error' => 'Voyager tables not found. Run: php artisan voyager:install',
                ];
            }

            // Check if admin user exists
            $adminExists = DB::table('users')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('user_roles')
                        ->whereColumn('user_roles.user_id', 'users.id')
                        ->where('user_roles.role_id', 1);
                })
                ->exists();

            if (!$adminExists) {
                return [
                    'status' => 'healthy',
                    'warning' => 'No admin user found. Create one with: php artisan voyager:admin your@email.com --create',
                    'tables_exist' => true,
                ];
            }

            return [
                'status' => 'healthy',
                'tables_exist' => true,
                'admin_exists' => true,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }
}
