<?php

namespace Tests\Feature;

use App\Console\Commands\QueueHealthCheck;
use App\Http\Controllers\HealthController;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * /api/health (L2), the /health page's queue depth (L6), queue:health-check
 * (L12), the single CSRF exception list (M15) and the removed GET metadata
 * route (M13).
 */
class HealthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    // ---- L2: /api/health ----

    public function test_api_health_is_healthy_without_redis_when_nothing_uses_it(): void
    {
        config([
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'database',
        ]);
        $this->assertFalse(HealthController::redisInUse());

        Redis::shouldReceive('connection')->never();

        $response = $this->getJson('/api/health')->assertOk();

        $response->assertJsonPath('status', 'healthy');
        $this->assertArrayNotHasKey('redis', $response->json('services'));
        $this->assertSame('healthy', $response->json('services.database.status'));
        $this->assertSame('healthy', $response->json('services.cache.status'));
        $this->assertSame(['status' => 'healthy'], $response->json('services.storage'));
    }

    public function test_api_health_hides_exception_messages_and_paths(): void
    {
        config(['session.driver' => 'redis']);
        $this->assertTrue(HealthController::redisInUse());

        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('secret-host:6379 refused SECRET'));

        $response = $this->getJson('/api/health')->assertStatus(503);

        $response->assertJsonPath('status', 'unhealthy');
        $response->assertJsonPath('services.redis', ['status' => 'unhealthy']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET', $body);
        $this->assertStringNotContainsString('secret-host', $body);
        $this->assertStringNotContainsString(str_replace('/', '\/', storage_path()), $body);
        $this->assertStringNotContainsString(storage_path(), $body);
    }

    public function test_api_health_db_hides_the_connection_error(): void
    {
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('SQLSTATE password=hunter2'));

        $response = $this->getJson('/api/health/db')->assertStatus(503);

        $response->assertJsonPath('details', ['status' => 'unhealthy']);
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    // ---- L6: /health queue depth ----

    private function pushJob(string $queue, int $availableAt, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }

    public function test_health_page_counts_commands_and_default_queues(): void
    {
        config(['queue.default' => 'database']);

        $this->pushJob('commands', now()->getTimestamp());
        $this->pushJob('commands', now()->getTimestamp());
        $this->pushJob('default', now()->getTimestamp());
        $this->pushJob('elsewhere', now()->getTimestamp());

        $this->get('/health')->assertOk()->assertViewHas('queue_depth', 3);
    }

    // ---- L12: queue:health-check ----

    public function test_queue_health_check_passes_with_a_fresh_heartbeat_and_young_jobs(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('scheduler_last_run', now()->subMinute()->toDateTimeString());
        $this->pushJob('commands', now()->subMinutes(5)->getTimestamp());

        $this->artisan('queue:health-check')
            ->expectsOutputToContain("Queue 'commands': 1 pending (oldest waiting 5 min)")
            ->assertExitCode(QueueHealthCheck::SUCCESS);
    }

    public function test_queue_health_check_fails_on_an_old_pending_job(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('scheduler_last_run', now()->toDateTimeString());
        $this->pushJob('default', now()->subMinutes(90)->getTimestamp());
        // Reserved (running) and delayed jobs are not "waiting".
        $this->pushJob('commands', now()->subMinutes(500)->getTimestamp(), now()->getTimestamp());
        $this->pushJob('commands', now()->addHour()->getTimestamp());

        $this->artisan('queue:health-check', ['--max-age' => 60])
            ->expectsOutputToContain("Queue 'commands': 2 pending (none waiting)")
            ->expectsOutputToContain("oldest pending job has waited 90 min")
            ->assertExitCode(QueueHealthCheck::FAILURE);
    }

    public function test_queue_health_check_fails_on_a_stale_or_missing_heartbeat(): void
    {
        config(['queue.default' => 'database']);

        $this->artisan('queue:health-check')
            ->expectsOutputToContain('Scheduler heartbeat missing')
            ->assertExitCode(QueueHealthCheck::FAILURE);

        Cache::put('scheduler_last_run', now()->subMinutes(10)->toDateTimeString());

        $this->artisan('queue:health-check')
            ->expectsOutputToContain('Scheduler heartbeat is 10 min old')
            ->assertExitCode(QueueHealthCheck::FAILURE);
    }

    // ---- M15: one CSRF exception list, no duplicated web middleware ----

    /** The framework skips CSRF checks in unit tests; turn that off here. */
    private function enforceCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_csrf_exempt_read_state_endpoint_accepts_a_tokenless_post(): void
    {
        $this->enforceCsrf();

        $novel = Novel::create(['name' => 'CSRF', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 1]);
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => 1,
            'book' => 0,
            'label' => 'Chapter 1',
            'url' => 'https://example.test/csrf/1',
        ]);

        $this->postJson("/chapters/{$chapter->id}/progress", ['progress' => 40])->assertOk();
        $this->assertSame(40, (int) $chapter->fresh()->read_progress);
    }

    public function test_protected_post_without_a_token_is_rejected(): void
    {
        $this->enforceCsrf();

        $novel = Novel::create(['name' => 'CSRF', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 1]);

        $this->post("/novels/{$novel->id}/metadata/choose", ['url' => 'https://example.test'])
            ->assertStatus(419);
    }

    public function test_web_middleware_group_has_no_duplicates(): void
    {
        $web = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertSame(array_values(array_unique($web)), array_values($web));
        $this->assertNotContains(\App\Http\Middleware\VerifyCsrfToken::class, $web);
    }

    // ---- M13 ----

    public function test_get_metadata_route_is_gone(): void
    {
        $this->assertFalse(Route::has('novels.get_metadata'));
    }
}
