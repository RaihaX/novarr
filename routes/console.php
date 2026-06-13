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

// Drain queued jobs (background commands from the web UI) without needing a
// dedicated worker process: the cron-driven scheduler starts a worker every
// minute and it exits as soon as the queue is empty. withoutOverlapping
// prevents pile-up while a long command (e.g. a full chapter scrape) runs.
Schedule::command('queue:work --queue=commands,default --stop-when-empty')
    ->everyMinute()
    ->name('drain_queue')
    ->withoutOverlapping();

// Refresh the table of contents for all active (non-complete) novels once a day.
Schedule::command('novel:toc')
    ->dailyAt('01:00')
    ->name('daily_toc_check')
    ->withoutOverlapping();

// Download any pending chapters found by the TOC check.
Schedule::command('novel:chapter')
    ->everyTenMinutes()
    ->name('download_new_chapters')
    ->withoutOverlapping();

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
