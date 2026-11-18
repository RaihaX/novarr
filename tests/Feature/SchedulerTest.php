<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * The registered schedule (routes/console.php): background runs, mutex
 * expiries and the hourly TOC check yielding to the daily one.
 */
class SchedulerTest extends TestCase
{
    /** @return array<string, Event> keyed by name */
    private function events(): array
    {
        $this->app->make(Kernel::class)->bootstrap();
        $this->app->make(Kernel::class)->all(); // loads routes/console.php

        $events = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            $events[$event->description] = $event;
        }

        return $events;
    }

    /**
     * The schedule's time windows (unlessBetween) read the clock when
     * routes/console.php is loaded, which is while the app boots: fix the
     * time first, or the suite fails for an hour a day.
     */
    protected function setUp(): void
    {
        Carbon::setTestNow('2026-11-14 05:00:00');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testLongTasksRunInTheBackground(): void
    {
        $events = $this->events();

        foreach (['drain_queue', 'daily_toc_check', 'frequent_toc_check', 'download_new_chapters'] as $name) {
            $this->assertArrayHasKey($name, $events);
            $this->assertTrue($events[$name]->runInBackground, "{$name} runs in the background");
        }
    }

    public function testEveryTaskHasAnExplicitOverlapExpiry(): void
    {
        $expected = [
            'drain_queue' => 65,
            'daily_toc_check' => 90,
            'frequent_toc_check' => 90,
            // The dispatcher only starts lane processes: a short mutex.
            'download_new_chapters' => 10,
            'verify_novel_completion' => 120,
            'email_chapter_summary' => 30,
        ];

        $events = $this->events();
        foreach ($expected as $name => $minutes) {
            $this->assertTrue($events[$name]->withoutOverlapping, "{$name} has an overlap guard");
            $this->assertSame($minutes, $events[$name]->expiresAt, "{$name} mutex expiry");
        }
    }

    public function testTheChapterDispatcherRunsEveryFiveMinutes(): void
    {
        $this->assertSame('*/5 * * * *', $this->events()['download_new_chapters']->expression);
    }

    public function testHourlyTocSkipsWhileTheDailyRunHoldsItsMutex(): void
    {
        Carbon::setTestNow('2026-11-14 05:00:00');
        $events = $this->events();
        $daily = $events['daily_toc_check'];
        $hourly = $events['frequent_toc_check'];

        $this->assertNotSame($daily->mutexName(), $hourly->mutexName());
        $this->assertTrue($hourly->filtersPass($this->app));

        $daily->mutex->create($daily);
        try {
            $this->assertFalse($hourly->filtersPass($this->app), 'skipped while the daily TOC run is going');
        } finally {
            $daily->mutex->forget($daily);
        }

        $this->assertTrue($hourly->filtersPass($this->app));
    }
}
