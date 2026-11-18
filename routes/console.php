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
//
// The long-running tasks run in the background so one of them can't hold up
// the other tasks due in the same minute (schedule:run runs foreground tasks
// one after another).
Schedule::command('queue:work --queue=commands,default --stop-when-empty --timeout=3600')
    ->everyMinute()
    ->name('drain_queue')
    ->withoutOverlapping(65)
    ->runInBackground();

// Refresh the table of contents for all active (non-complete) novels once a day.
$dailyToc = Schedule::command('novel:toc')
    ->dailyAt('01:00')
    ->name('daily_toc_check')
    ->withoutOverlapping(90)
    ->runInBackground();
$oneServer($dailyToc);

// Novels flagged "check hourly" (frequent_toc) get their TOC re-checked every
// hour so actively-updating series surface new chapters quickly. The 01:00
// full sweep already covers that hour. The two have different mutexes, so
// the hourly run also skips while the daily one still holds its mutex (a
// long daily run can outlast the 00:30–01:30 window).
$oneServer(Schedule::command('novel:toc --frequent-only')
    ->hourly()
    ->unlessBetween('00:30', '01:30')
    ->skip(fn() => $dailyToc->mutex->exists($dailyToc))
    ->name('frequent_toc_check')
    ->withoutOverlapping(90)
    ->runInBackground());

// Download any pending chapters found by the TOC check. This is the
// dispatcher: it starts one detached `novel:chapter --lane=…` process per
// source with due chapters (one download per source at a time, sources in
// parallel — App\Services\DownloadLanes) and returns within seconds, so
// its mutex is short and it runs often enough to pick up a freed source
// soon. Each lane process guards itself with its lane lock.
$oneServer(Schedule::command('novel:chapter')
    ->everyFiveMinutes()
    ->name('download_new_chapters')
    ->withoutOverlapping(10)
    ->runInBackground());

// Verify novels against NovelUpdates and mark fully-downloaded completed series.
Schedule::command('novel:verify-completion')
    ->dailyAt('06:00')
    ->name('verify_novel_completion')
    ->withoutOverlapping(120);

// Email a summary of the last 24 hours of downloads and completions.
// Send time is configurable from Settings (falls back to 08:00).
Schedule::command('novel:email-summary')
    ->dailyAt(setting('summary_time', '08:00'))
    ->name('email_chapter_summary')
    ->withoutOverlapping(30);

// Hourly: tell the notification webhook when a source has stopped
// downloading (once per new set of stalled sources). Without a webhook
// configured this only keeps the remembered set current.
$oneServer(Schedule::command('novel:email-summary --stalled-only')
    ->hourlyAt(20)
    ->name('stalled_sources_check')
    ->withoutOverlapping(15));

// Backups (config/backup.php): database dump + storage/app, zipped to the
// BACKUP_DISK disk. Clean first so the run never counts against a full disk.
// The package sends no notifications itself; a failed run is logged (the
// command also report()s the exception) and posted to the notification
// webhook, when one is set.
$backupFailed = fn (string $task) => function () use ($task) {
    \Illuminate\Support\Facades\Log::error("Scheduled {$task} failed; see the log above for the cause.");
    notify_webhook("Novarr: scheduled {$task} failed. Check storage/logs on the server.");
};

$oneServer(Schedule::command('backup:clean')
    ->dailyAt('03:30')
    ->name('backup_clean')
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($backupFailed('backup:clean')));

$oneServer(Schedule::command('backup:run')
    ->dailyAt('03:45')
    ->name('backup_run')
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($backupFailed('backup:run')));

// Failed jobs older than two weeks are history, not something to act on:
// dropped so the Health page's Failed jobs list only shows what is recent.
$oneServer(Schedule::command('queue:prune-failed --hours=336')
    ->dailyAt('04:05')
    ->name('prune_failed_jobs')
    ->withoutOverlapping(30));

// Self-heal cover thumbnails (covers/sm, covers/md): generates any that are
// missing, e.g. after a restore or a cover written by an older release.
Schedule::command('covers:thumbs')
    ->dailyAt('04:15')
    ->name('cover_thumbnails')
    ->withoutOverlapping(30)
    ->runInBackground();
