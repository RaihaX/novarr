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
        $schedule->command('novel:novel_scraper')->dailyAt(8)->name('update_all_active_toc')->withoutOverlapping();
        $schedule->command('novel:chapter_scraper')->dailyAt(5)->name('download_new_chapters')->withoutOverlapping();
        $schedule->command('novel:generate_epub')->everyMinute()->name('generate_epub')->withoutOverlapping();
        $schedule->command('novel:calculate_chapter')->everyThirtyMinutes()->name('update_active_novels_chapter_count')->withoutOverlapping();
        $schedule->command('novel:update_metadata')->weekly()->name('update_novel_metadata')->withoutOverlapping();
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
