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

// Reader requests: an approved request becomes "available" once its series
// has a downloaded chapter, and the requester is told (once). Also prunes
// old notification-feed items.
$oneServer(Schedule::command('requests:availability')
    ->everyTenMinutes()
    ->name('request_availability')
    ->withoutOverlapping(10));

// Reader requests: page 1 of each Discover shelf list (the Requests page's
// popular / new & popular / top-rated manga) is refreshed before its cache
// runs out, so no reader waits for a cold page.
$oneServer(Schedule::command('requests:browse-warm')
    ->everyThreeHours()
    ->name('request_browse_warm')
    ->withoutOverlapping(30));

// Self-heal cover thumbnails (covers/sm, covers/md): generates any that are
// missing, e.g. after a restore or a cover written by an older release.
Schedule::command('covers:thumbs')
    ->dailyAt('04:15')
    ->name('cover_thumbnails')
    ->withoutOverlapping(30)
    ->runInBackground();

// Source health (App\Services\SourceHealth): hourly, tell the admin (webhook
// + owner email) when a source turns Failing — once per transition, not
// again within 24 hours. Daily, drop counters older than 90 days.
$oneServer(Schedule::command('sources:health-check')
    ->hourlyAt(25)
    ->name('source_health_check')
    ->withoutOverlapping(15));

$oneServer(Schedule::command('sources:health-check --prune')
    ->dailyAt('04:25')
    ->name('source_health_prune')
    ->withoutOverlapping(15));

// Storage usage (Health → Storage): walk the manga pages, covers, ePubs,
// backups and logs and size the database, once a day after the backup and
// cover jobs. Tells the admin once when free space first drops under
// novarr.storage.low_free_percent.
$oneServer(Schedule::command('storage:measure')
    ->dailyAt('05:10')
    ->name('storage_measure')
    ->withoutOverlapping(180)
    ->runInBackground());

// Sign-in history (Settings → Users → Sign-in history): drop entries older
// than novarr.auth_log_days (default 180; 0 keeps everything), and clear the
// addresses recorded with reads (Readers pages) after the same time.
$oneServer(Schedule::command('novarr:prune-auth-log')
    ->dailyAt('04:35')
    ->name('prune_auth_log')
    ->withoutOverlapping(30));

// Manga fallback (App\Scraping\MangaFallback): hourly at :35 (clear of the
// :00 TOC check and the :20 / :25 checks), download chapters a series' own
// source keeps failing on from the same series on another installed source.
// The command takes its own lock and stops starting work after
// novarr.manga.fallback_max_minutes (default 20), so it ends before :00.
$oneServer(Schedule::command('manga:fill-missing')
    ->hourlyAt(35)
    ->when(fn() => (bool) config('novarr.manga.fallback_enabled', true))
    ->name('manga_fill_missing')
    ->withoutOverlapping(40)
    ->runInBackground());

// Books added to the owner's Calibre-Web since the last run (App\Books\CalibreImport);
// off until NOVARR_CALIBRE_WEB_URL is set, or with NOVARR_CALIBRE_WEB_SYNC=false.
$oneServer(Schedule::command('books:calibre-import')
    ->hourlyAt(50)
    ->when(fn() => \App\Books\CalibreWeb::enabled() && (bool) config('novarr.calibre_web.sync', true))
    ->name('books_calibre_import')
    ->withoutOverlapping(120)
    ->runInBackground());
