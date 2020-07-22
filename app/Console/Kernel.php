<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.p
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->call('App\Http\Controllers\NovelChapterController@all_novels_scraper')->dailyAt(8)->name('update_all_active_toc')->withoutOverlapping();
        $schedule->call('App\Http\Controllers\NovelChapterController@all_novels_scraper_reverse')->dailyAt(1)->name('update_all_active_toc_reverse')->withoutOverlapping();
        $schedule->call('App\Http\Controllers\NovelChapterController@all_new_chapters_scraper')->dailyAt(5)->name('download_new_chapters')->withoutOverlapping();
        $schedule->call('App\Http\Controllers\NovelController@generate_all_epub')->everyMinute()->name('generate_epub')->withoutOverlapping();
        $schedule->call('App\Http\Controllers\NovelController@calculate_chapters')->everyThirtyMinutes()->name('update_active_novels_chapter_count')->withoutOverlapping();
        $schedule->call('App\Http\Controllers\NovelController@update_all_metadata')->weekly()->name('update_novel_metadata')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
