<?php

namespace App\Services\Requests;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Asks a search's sources concurrently: one PHP process per lane
 *
 *   php artisan requests:search-lane <medium> <key>... --deadline=<epoch ms>
 *
 * (App\Console\Commands\RequestSearchLane), at most $parallelism lanes at a
 * time; a lane's sources are asked one after another inside its process.
 * The query and a nonce go in the child's environment; source keys and
 * the medium are the only arguments, and each child rebuilds its sources itself (nothing is
 * serialised but strings). The child prints one line per event —
 * "start" before a source is called, then "done" (with its items),
 * "failed" or "timeout" — each tagged with a random nonce, so nothing else a
 * source happens to print can pass for a result.
 *
 * Why not Illuminate\Support\Facades\Concurrency (process driver): it runs
 * every task at once (no limit), has no per-task deadline (a pool waits for
 * the slowest child, then Laravel's 60 s process default throws for all),
 * and one child that exits non-zero throws away every other child's
 * answer. Here a lane that dies only fails its unanswered sources, and the
 * lanes still running at the deadline are stopped and their unanswered
 * sources reported as timeouts.
 *
 * Runs wherever RunRequestSearch runs (after the response, in the php-fpm
 * worker or the Octane worker): the children are ordinary CLI processes,
 * found with Symfony's PhpExecutableFinder (under php-fpm PHP_BINARY is
 * the fpm binary, so the finder looks for PHP_BINDIR/php and PATH).
 */
class ProcessSearchRunner implements SearchRunner
{
    /** Pause between two looks at the running children. */
    public const POLL_MICROSECONDS = 20_000;

    /** Prefix of every result line a child prints. */
    public const MARKER = '@@NOVARR-SEARCH@@';

    public function __construct(private int $parallelism, private ?string $php = null)
    {
        $this->parallelism = max(1, $parallelism);
    }

    /** The PHP CLI binary to start children with; null when none can be found or proc_open is off. */
    public static function phpBinary(): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $php = (new PhpExecutableFinder())->find(false);

        return is_string($php) && $php !== '' ? $php : null;
    }

    public function run(string $medium, string $query, array $lanes, array $sources, int $deadlineMs): array
    {
        $results = [];
        $queue = array_values(array_filter(array_map(fn($lane) => array_values(array_map('strval', (array) $lane)), $lanes)));
        $running = [];

        while ($queue !== [] || $running !== []) {
            if ($this->nowMs() >= $deadlineMs) {
                foreach ($running as $lane) {
                    $this->stop($lane);
                    // Anything it finished just before it was stopped still counts.
                    $this->collect($lane, $results);
                    foreach ($lane['keys'] as $key) {
                        if (!isset($lane['reported'][$key])) {
                            $results[$key] = ['items' => null, 'reason' => 'timeout', 'asked' => isset($lane['started'][$key])];
                        }
                    }
                }
                foreach ($queue as $keys) {
                    $this->settle($keys, $results, 'timeout');
                }
                break;
            }

            while ($queue !== [] && count($running) < $this->parallelism) {
                $keys = array_shift($queue);
                $lane = $this->start($medium, $query, $keys, $deadlineMs);
                if ($lane === null) {
                    $this->settle($keys, $results, 'failed');
                    continue;
                }
                $running[] = $lane;
            }

            foreach ($running as $i => $lane) {
                $alive = $lane['process']->isRunning();
                $this->collect($running[$i], $results);
                if (!$alive) {
                    $this->finished($running[$i], $results);
                    unset($running[$i]);
                }
            }
            $running = array_values($running);

            if ($running !== [] || $queue !== []) {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        return $results;
    }

    /** The child's command line (an argv array: no shell). */
    protected function command(string $medium, array $keys, int $deadlineMs): array
    {
        return array_merge(
            [$this->php ?? static::phpBinary() ?? 'php', base_path('artisan'), 'requests:search-lane', $medium],
            $keys,
            ['--deadline=' . $deadlineMs],
        );
    }

    /** @return ?array{process: Process, keys: string[], nonce: string, buffer: string, reported: array<string, bool>, started: array<string, bool>} */
    private function start(string $medium, string $query, array $keys, int $deadlineMs): ?array
    {
        $nonce = bin2hex(random_bytes(8));
        try {
            $process = new Process($this->command($medium, $keys, $deadlineMs), base_path(), ['NOVARR_SEARCH_NONCE' => $nonce, 'NOVARR_SEARCH_QUERY' => $query], null, null);
            $process->start();
        } catch (\Throwable $e) {
            Log::warning('Request search: a lane (' . implode(', ', $keys) . ') did not start: ' . $e->getMessage());

            return null;
        }

        return ['process' => $process, 'keys' => $keys, 'nonce' => $nonce, 'buffer' => '', 'reported' => [], 'started' => []];
    }

    /** Read what the child printed so far and record every complete result line. */
    private function collect(array &$lane, array &$results): void
    {
        try {
            $lane['buffer'] .= $lane['process']->getIncrementalOutput();
        } catch (\Throwable) {
            return;
        }
        while (($end = strpos($lane['buffer'], "\n")) !== false) {
            $line = substr($lane['buffer'], 0, $end);
            $lane['buffer'] = (string) substr($lane['buffer'], $end + 1);
            $event = static::parseLine($line, $lane['nonce']);
            if ($event === null || !in_array($event['key'], $lane['keys'], true) || isset($lane['reported'][$event['key']])) {
                continue;
            }
            $key = $event['key'];
            switch ($event['event']) {
                case 'start':
                    $lane['started'][$key] = true;
                    break;
                case 'done':
                    $results[$key] = ['items' => $event['items'], 'reason' => null, 'asked' => true];
                    $lane['reported'][$key] = true;
                    break;
                case 'failed':
                case 'timeout':
                    $results[$key] = ['items' => null, 'reason' => $event['event'], 'asked' => $event['event'] === 'failed' || isset($lane['started'][$key])];
                    $lane['reported'][$key] = true;
                    break;
            }
        }
    }

    /** A child ended: whatever it did not answer failed (it died or exited early). */
    private function finished(array $lane, array &$results): void
    {
        $missing = array_values(array_filter($lane['keys'], fn($k) => !isset($lane['reported'][$k])));
        if ($missing === []) {
            return;
        }
        $error = '';
        try {
            $error = trim((string) $lane['process']->getErrorOutput());
        } catch (\Throwable) {
        }
        Log::warning('Request search: the lane for ' . implode(', ', $missing) . ' ended (exit ' . var_export($lane['process']->getExitCode(), true) . ') without answering'
            . ($error !== '' ? ': ' . mb_substr($error, 0, 500) : '.'));
        foreach ($missing as $key) {
            $results[$key] = ['items' => null, 'reason' => 'failed', 'asked' => isset($lane['started'][$key])];
        }
    }

    /** Give every key of $keys without an answer yet this reason. */
    private function settle(array $keys, array &$results, string $reason): void
    {
        foreach ($keys as $key) {
            $results[$key] ??= ['items' => null, 'reason' => $reason, 'asked' => false];
        }
    }

    /** Stop a child at once (SIGTERM, then SIGKILL). */
    private function stop(array $lane): void
    {
        try {
            $lane['process']->stop(0);
        } catch (\Throwable) {
            // Already gone.
        }
    }

    /**
     * One result line → {event, key, items}; null for anything else (a
     * line some source printed, another run's nonce, broken JSON).
     *
     * @return ?array{event: string, key: string, items: ?array}
     */
    public static function parseLine(string $line, string $nonce): ?array
    {
        $prefix = self::MARKER . $nonce . '@@';
        $at = strpos($line, $prefix);
        if ($at === false) {
            return null;
        }
        $decoded = json_decode((string) base64_decode(trim(substr($line, $at + strlen($prefix))), true), true);
        if (!is_array($decoded) || !is_string($decoded['key'] ?? null) || !in_array($decoded['event'] ?? null, ['start', 'done', 'failed', 'timeout'], true)) {
            return null;
        }
        $items = null;
        if ($decoded['event'] === 'done') {
            if (!is_array($decoded['items'] ?? null)) {
                return null;
            }
            $items = array_values(array_filter($decoded['items'], 'is_array'));
        }

        return ['event' => $decoded['event'], 'key' => $decoded['key'], 'items' => $items];
    }

    /** One result line as a child prints it. */
    public static function formatLine(string $nonce, string $event, string $key, ?array $items = null): string
    {
        $payload = ['event' => $event, 'key' => $key];
        if ($event === 'done') {
            $payload['items'] = array_values(array_filter((array) $items, 'is_array'));
        }
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return "\n" . self::MARKER . $nonce . '@@' . base64_encode((string) $json) . "\n";
    }

    private function nowMs(): int
    {
        return (int) now()->getPreciseTimestamp(3);
    }
}
