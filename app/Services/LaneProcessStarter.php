<?php

namespace App\Services;

use Illuminate\Console\Application;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Starts one download lane as its own detached OS process:
 *
 *   php artisan novel:chapter --lane=<lane>
 *
 * The same way the scheduler's runInBackground() does it: a subshell that
 * backgrounds the command and returns at once, so the dispatcher
 * (`novel:chapter` without a novel) is done in seconds while every lane
 * downloads in parallel. The lane process takes the lane lock itself and
 * exits quietly when another one holds it. Its standard output (one line
 * per chapter, already in the Laravel log) is dropped; standard error
 * (fatal errors) is appended to storage/logs/lanes.log.
 *
 * Bound in the container so tests can run lanes in-process or just record
 * them instead.
 */
class LaneProcessStarter
{
    /**
     * Start these lanes. $inline runs lanes inside the calling process
     * (fn(string[] $lanes)); this implementation never uses it — a test
     * double may.
     *
     * @param  string[]  $lanes
     */
    public function startAll(array $lanes, callable $inline): void
    {
        foreach ($lanes as $lane) {
            $this->start($lane);
        }
    }

    public function start(string $lane): void
    {
        $command = self::commandLine($lane);

        try {
            Process::fromShellCommandline($command, base_path(), null, null, 30)->run();
            Log::info("Started the download lane {$lane}: {$command}");
        } catch (\Throwable $e) {
            Log::error("Could not start the download lane {$lane}: " . $e->getMessage());
        }
    }

    /** The shell line that starts a lane in the background. */
    public static function commandLine(string $lane): string
    {
        $artisan = Application::phpBinary() . ' ' . escapeshellarg(base_path('artisan'))
            . ' novel:chapter --lane=' . escapeshellarg($lane);

        return '(' . $artisan . ' < /dev/null > /dev/null 2>> ' . escapeshellarg(storage_path('logs/lanes.log')) . ' &)';
    }
}
