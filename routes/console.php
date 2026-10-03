<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->describe('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Laravel 11 registers the schedule here (app/Console/Kernel.php is no
| longer consulted). The Docker scheduler service runs `schedule:run`
| every minute. Times are in the app timezone (Australia/Perth).
|
*/

// withoutOverlapping() defaults to a 24h mutex: a scheduler killed mid-run
// (container restart, OOM) blocked that task for a whole day. Scraping tasks
// get an explicit expiry a bit past their realistic worst-case run instead.
//
// onOneServer() only helps when the cache store is shared between servers and
// supports atomic locks (redis/database/memcached). On a file/array store it
// is skipped — there is only ever one server reading that cache anyway.
$oneServer = function ($event) {
    try {
        $store = cache()->store()->getStore();
        $shared = $store instanceof \Illuminate\Contracts\Cache\LockProvider
            && !$store instanceof \Illuminate\Cache\FileStore
            && !$store instanceof \Illuminate\Cache\ArrayStore;
    } catch (\Throwable $e) {
        $shared = false;
    }

    return $shared ? $event->onOneServer() : $event;
};

// Heartbeat: record that the scheduler ran, for the Health page to detect
// a stalled scheduler/cron.
Schedule::call(fn() => cache()->put('scheduler_last_run', now()->toDateTimeString(), now()->addDay()))
    ->everyMinute()
    ->name('scheduler_heartbeat');

// Keep the dashboard's "Needs Attention" panel pre-computed — it's the one
// remaining expensive piece of a cold dashboard load.
Schedule::call(fn() => cache()->put(
    'dashboard_attention',
    app(\App\Services\NovelHealth::class)->needingAttention(),
    900
))
    ->everyFiveMinutes()
    ->name('warm_dashboard_attention');

// Drain queued jobs (background commands from the web UI) without needing a
// dedicated worker process: the cron-driven scheduler starts a worker every
// minute and it exits as soon as the queue is empty. withoutOverlapping
// prevents pile-up while a long command (e.g. a full chapter scrape) runs;
// the mutex expires just past the worker's 60-minute job timeout.
Schedule::command('queue:work --queue=commands,default --stop-when-empty --timeout=3600')
    ->everyMinute()
    ->name('drain_queue')
    ->withoutOverlapping(65);

// Refresh the table of contents for all active (non-complete) novels once a day.
$oneServer(Schedule::command('novel:toc')
    ->dailyAt('01:00')
    ->name('daily_toc_check')
    ->withoutOverlapping(90));

// Novels flagged "check hourly" (frequent_toc) get their TOC re-checked every
// hour so actively-updating series surface new chapters quickly. The 01:00
// full sweep already covers that hour.
$oneServer(Schedule::command('novel:toc --frequent-only')
    ->hourly()
    ->unlessBetween('00:30', '01:30')
    ->name('frequent_toc_check')
    ->withoutOverlapping(90));

// Download any pending chapters found by the TOC check.
$oneServer(Schedule::command('novel:chapter')
    ->everyTenMinutes()
    ->name('download_new_chapters')
    ->withoutOverlapping(150));

// Verify novels against NovelUpdates and mark fully-downloaded completed series.
Schedule::command('novel:verify-completion')
    ->dailyAt('06:00')
    ->name('verify_novel_completion')
    ->withoutOverlapping();

// Email a summary of the last 24 hours of downloads and completions.
// Send time is configurable from Settings (falls back to 08:00).
Schedule::command('novel:email-summary')
    ->dailyAt(setting('summary_time', '08:00'))
    ->name('email_chapter_summary')
    ->withoutOverlapping();
