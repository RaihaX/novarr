<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs an artisan command in the background for the web UI. A real job class
 * (rather than a queued Closure) means the failed_jobs payload is readable —
 * the command and params show up in the Health failed-job detail — and the
 * generous timeout lets long scrapes finish.
 */
class RunNovelCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Long enough for a full TOC/chapter scrape. */
    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public string $artisanCommand,
        public array $params,
        public string $jobId,
    ) {}

    public function handle(): void
    {
        try {
            $exitCode = Artisan::call($this->artisanCommand, $this->params);
            $output = Artisan::output();

            // Keep the tail only — some commands (novel:info) emit megabytes.
            if (strlen($output) > 65536) {
                $output = "… (output truncated, showing last 64 KB)\n" . substr($output, -65536);
            }

            $this->store([
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            $this->store([
                'success' => false,
                'exit_code' => 1,
                'error' => $e->getMessage(),
                'output' => '',
            ]);
            throw $e; // let it land in failed_jobs too
        }
    }

    /**
     * Record the failure for the polling status endpoint when the job is
     * abandoned (e.g. timeout) so the UI doesn't poll forever.
     */
    public function failed(\Throwable $e): void
    {
        $this->store([
            'success' => false,
            'exit_code' => 1,
            'error' => $e->getMessage(),
            'output' => '',
        ]);
    }

    private function store(array $result): void
    {
        cache()->put(
            "command_result_{$this->jobId}",
            $result + ['command' => $this->artisanCommand, 'completed_at' => now()->toIso8601String()],
            now()->addHours(1)
        );
    }
}
