<?php

namespace App\Services;

use App\Jobs\FinishNovelCheck;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Check for new chapters now" for one novel, started from the API: the
 * novel page's two buttons in a row — `novel:toc {id}` (Scrape TOC) then
 * `novel:chapter {id}` (Download Chapters) — as RunNovelCommand jobs on the
 * `commands` queue, followed by FinishNovelCheck, which records the outcome.
 *
 * One record per novel in the cache: while running {check_id, toc_job_id,
 * chapter_job_id, started_at, chapters_before}; once resolved also status,
 * finished_at and message, kept for a day. A running record is resolved
 * from the jobs' command_result_{jobId} entries (by FinishNovelCheck, or by
 * whichever status read sees them first), and counts as failed once it has
 * run longer than STALE_AFTER_MINUTES (a worker died, the job was lost).
 */
class NovelCheck
{
    /**
     * A running check older than this is given up as failed: each of the two
     * scrapes may run for an hour (RunNovelCommand::$timeout), and the queue
     * may be busy with other commands first.
     */
    public const STALE_AFTER_MINUTES = 180;

    /** Minutes the check's own download runs at most; the scheduled downloader takes the rest. */
    public const DOWNLOAD_MINUTES = 10;

    /** How long a finished (done / failed) check is remembered. */
    public const KEEP_HOURS = 24;

    public const ALREADY_RUNNING = 'This novel was already being updated by another task, so this check was skipped. Try again in a few minutes.';

    public static function key(int $novelId): string
    {
        return "api_novel_check_{$novelId}";
    }

    /**
     * Start a check unless one is running. Returns the check object and
     * whether this call started it.
     *
     * @return array{0: array, 1: bool}
     */
    public function start(Novel $novel): array
    {
        $id = (int) $novel->id;

        // The decision and the record are written under one lock, so two
        // taps cannot both start a run. The jobs are dispatched after the
        // lock is released: on the sync queue they run inside dispatch.
        try {
            $record = Cache::lock("api_novel_check_lock_{$id}", 10)->block(5, function () use ($id) {
                if ($this->state($id)['status'] === 'running') {
                    return null;
                }

                $record = [
                    'check_id' => uniqid('check_', true),
                    'toc_job_id' => uniqid('cmd_', true),
                    'chapter_job_id' => uniqid('cmd_', true),
                    'started_at' => now()->toIso8601String(),
                    'chapters_before' => static::chapterCount($id),
                    'status' => 'running',
                ];
                Cache::put(static::key($id), $record, now()->addMinutes(self::STALE_AFTER_MINUTES)->addHours(self::KEEP_HOURS));

                return $record;
            });
        } catch (LockTimeoutException) {
            $record = null; // another request is deciding right now
        }

        if ($record === null) {
            return [$this->state($id), false];
        }

        $this->dispatch($id, $record);

        return [$this->state($id), true];
    }

    private function dispatch(int $novelId, array $record): void
    {
        Log::info('Queuing a chapter check from the API', ['novel' => $novelId, 'check' => $record['check_id']]);

        try {
            Bus::chain([
                new RunNovelCommand('novel:toc', ['novel' => $novelId], $record['toc_job_id']),
                // A check answers "anything new?": a short download, then the
                // scheduled downloader takes over (a big new series would
                // otherwise hold the queue's only worker for an hour).
                new RunNovelCommand('novel:chapter', ['novel' => $novelId, '--max-minutes' => self::DOWNLOAD_MINUTES], $record['chapter_job_id']),
                new FinishNovelCheck($novelId, $record['check_id']),
            ])->onQueue('commands')->dispatch();
        } catch (\Throwable $e) {
            // On the sync queue the jobs run right here and a throwing one
            // rethrows after recording its result: that result is the
            // check's outcome. Without one nothing reached the queue, so the
            // record must not claim a running check.
            if (!Cache::has("command_result_{$record['toc_job_id']}") && !Cache::has("command_result_{$record['chapter_job_id']}")) {
                Cache::forget(static::key($novelId));
                throw $e;
            }
            Log::warning("Chapter check for novel {$novelId} threw: " . $e->getMessage());
        }
    }

    /**
     * The `check` object, resolving (and storing) a running record whose
     * jobs have finished or that has gone stale.
     *
     * @return array{status: string, started_at: ?string, finished_at: ?string, chapters_before: ?int, chapters_now: int, new_chapters: int, message: ?string}
     */
    public function state(int $novelId, ?string $checkId = null): array
    {
        $record = Cache::get(static::key($novelId));

        if (is_array($record) && ($record['status'] ?? 'running') === 'running'
            && ($checkId === null || $record['check_id'] === $checkId)) {
            if ($resolved = $this->resolve($record)) {
                $record = $resolved;
                Cache::put(static::key($novelId), $record, now()->addHours(self::KEEP_HOURS));
            }
        }

        $now = static::chapterCount($novelId);

        if (!is_array($record)) {
            return [
                'status' => 'idle',
                'started_at' => null,
                'finished_at' => null,
                'chapters_before' => null,
                'chapters_now' => $now,
                'new_chapters' => 0,
                'message' => null,
            ];
        }

        $before = (int) $record['chapters_before'];

        return [
            'status' => $record['status'] ?? 'running',
            'started_at' => static::timestamp($record['started_at']),
            'finished_at' => static::timestamp($record['finished_at'] ?? null),
            'chapters_before' => $before,
            'chapters_now' => $now,
            'new_chapters' => max(0, $now - $before),
            'message' => $record['message'] ?? null,
        ];
    }

    /** The finished record for a running one, or null while it still runs. */
    private function resolve(array $record): ?array
    {
        $toc = Cache::get("command_result_{$record['toc_job_id']}");
        $chapters = Cache::get("command_result_{$record['chapter_job_id']}");

        // A failed TOC run ends the check (a throwing job also stops the chain).
        if (is_array($toc) && !($toc['success'] ?? false)) {
            return array_merge($record, ['status' => 'failed', 'finished_at' => $toc['completed_at'] ?? now()->toIso8601String(), 'message' => static::failureMessage($toc)]);
        }

        if (is_array($chapters)) {
            $ok = (bool) ($chapters['success'] ?? false);

            return array_merge($record, [
                'status' => $ok ? 'done' : 'failed',
                'finished_at' => $chapters['completed_at'] ?? now()->toIso8601String(),
                'message' => $ok ? static::queuedMessage($chapters) : static::failureMessage($chapters),
            ]);
        }

        if (Carbon::parse($record['started_at'])->lte(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            return array_merge($record, [
                'status' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'message' => 'The check did not finish within ' . intdiv(self::STALE_AFTER_MINUTES, 60) . ' hours and was given up. Try again.',
            ]);
        }

        return null;
    }

    /** Chapters the API's chapter list shows (not blacklisted, not deleted). */
    public static function chapterCount(int $novelId): int
    {
        return NovelChapter::where('novel_id', $novelId)->where('blacklist', 0)->count();
    }

    /**
     * A download that did not start because another download from the same
     * source was running (ChapterScraper's lanes): the command succeeded and
     * its chapters wait in the queue — that sentence, else null.
     */
    public static function queuedMessage(array $result): ?string
    {
        return preg_match('/^.*Another download from .+ is running; .+ queued and will start when it finishes\.$/m', (string) ($result['output'] ?? ''), $m)
            ? trim($m[0])
            : null;
    }

    /** One plain sentence from a RunNovelCommand failure result. */
    public static function failureMessage(array $result): string
    {
        $detail = trim((string) ($result['error'] ?? ''));
        if (str_starts_with($detail, 'Skipped:')) {
            return self::ALREADY_RUNNING;
        }
        if ($detail === '' && preg_match('/Error:\s*(.+)/', (string) ($result['output'] ?? ''), $m)) {
            $detail = trim($m[1]);
        }

        if ($detail === '') {
            return 'The check failed (exit code ' . (int) ($result['exit_code'] ?? 1) . ').';
        }

        return 'The check failed: ' . rtrim(Str::limit($detail, 300), '.') . '.';
    }

    private static function timestamp(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
