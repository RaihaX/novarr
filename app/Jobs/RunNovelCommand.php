<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /** Set once handle() runs; tells the overlap guard the job wasn't skipped. */
    private bool $ran = false;

    public function __construct(
        public string $artisanCommand,
        public array $params,
        public string $jobId,
    ) {}

    /**
     * The same novel's scrape must never run twice at once (double-clicks,
     * or a TOC check while its chapter download is still going). A skipped
     * duplicate is dropped — not released, since $tries = 1 would turn a
     * release into a failure — and records a result so the UI's polling
     * ends with an explanation instead of spinning forever. The lock
     * expires a little after the job timeout so a killed worker can't
     * leave it held.
     */
    public function middleware(): array
    {
        $key = self::overlapKey($this->artisanCommand, $this->params);
        if ($key === null) {
            return [];
        }

        return [
            function ($job, $next) use ($key) {
                $next($job);
                if (!$job->ran) {
                    $job->store([
                        'success' => false,
                        'exit_code' => 1,
                        'error' => "Skipped: the same command is already running ({$key}).",
                        'output' => '',
                    ]);
                }
            },
            (new WithoutOverlapping($key))
                ->dontRelease()
                ->expireAfter($this->timeout + 100),
        ];
    }

    /**
     * Lock key for overlapping runs: per novel when one is given, per
     * command for a full sweep, per chapter for single-chapter downloads.
     * Null (no guard) for other commands. Pure — unit tested.
     */
    public static function overlapKey(string $command, array $params): ?string
    {
        if (!empty($params['--chapter'])) {
            return 'chapter:' . (int) $params['--chapter'];
        }

        $novel = (int) ($params['novel'] ?? 0);
        if ($novel > 0) {
            // The two scrapers share one lock per novel (a TOC refresh and a
            // chapter download must not interleave). Maintenance commands
            // lock per command so a dry-run normalize isn't dropped just
            // because a scrape is running.
            return in_array($command, ['novel:toc', 'novel:chapter'], true)
                ? "novel:{$novel}"
                : "{$command}:novel:{$novel}";
        }

        if (in_array($command, ['novel:toc', 'novel:chapter'], true)) {
            return "{$command}:all";
        }

        return null;
    }

    public function handle(): void
    {
        $this->ran = true;

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
