<?php

namespace App\Http\Controllers\Api\V1;

use App\Console\Commands\GenerateePub;
use App\Http\Controllers\Controller;
use App\Http\Helpers\CacheHelper;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A novel's EPUB for the native app: its state (GET) and an on-demand build
 * (POST). Builds run `novel:epub {id}` through RunNovelCommand on the
 * `commands` queue, exactly like the web UI's background commands; the
 * file itself is downloaded from the web route novels.download_epub.
 *
 * Only builds started here are tracked ("building" / "failed"): a per-novel
 * build record {job_id, started_at} in the cache for 30 minutes, resolved
 * through the job's command_result_{jobId}.
 */
class EpubController extends Controller
{
    private const NO_CHAPTERS = 'This novel has no downloaded chapters yet.';

    /** ePubs are made from chapter text; a manga has page images. */
    public const MANGA = 'This is a manga: EPUBs are made from chapter text only.';

    /** How long a started build counts as running without a result. */
    private const BUILD_TTL_MINUTES = 30;

    /** How long the outcome of the last build is remembered. */
    private const LAST_BUILD_TTL_DAYS = 7;

    /** GET /api/v1/novels/{id}/epub */
    public function show($id): JsonResponse
    {
        $novel = Novel::findOrFail($id);
        if ($novel->hasPages()) {
            return response()->json(['message' => self::MANGA], 422);
        }

        return response()->json(['epub' => $this->state($novel)]);
    }

    /**
     * POST /api/v1/novels/{id}/epub, optional {"force": true}. Starts a build
     * when the EPUB is missing, stale or failed (or forced) and none is
     * running: 202. Nothing to do: 200. No downloaded chapters: 422.
     */
    public function build(Request $request, $id): JsonResponse
    {
        $novel = Novel::findOrFail($id);
        if ($novel->hasPages()) {
            return response()->json(['message' => self::MANGA], 422);
        }

        if (static::downloadedChapters((int) $novel->id)['count'] === 0) {
            return response()->json(['message' => self::NO_CHAPTERS], 422);
        }

        $force = $request->boolean('force');

        // The decision and the build record are written under one lock, so
        // two requests arriving together cannot both start a build. The job
        // is dispatched after the lock is released: on the sync queue it runs
        // inside dispatch, far longer than the lock should be held.
        try {
            $jobId = Cache::lock('api_epub_build_lock_' . (int) $novel->id, 10)
                ->block(5, function () use ($novel, $force) {
                    $state = $this->state($novel);
                    if ($state['status'] === 'building') {
                        return null;
                    }
                    if (!$force && $state['status'] === 'ready' && !$state['stale']) {
                        return false;
                    }

                    $jobId = uniqid('cmd_', true);
                    Cache::put(
                        CacheHelper::apiEpubBuildKey((int) $novel->id),
                        ['job_id' => $jobId, 'started_at' => now()->toIso8601String()],
                        now()->addMinutes(self::BUILD_TTL_MINUTES)
                    );

                    return $jobId;
                });
        } catch (LockTimeoutException) {
            $jobId = null; // another request is deciding right now
        }

        if (is_string($jobId)) {
            $this->dispatchBuild($novel, $jobId);
            $novel->refresh(); // a sync-queue build has already updated epub_generated

            return response()->json(['epub' => $this->state($novel)], 202);
        }

        $state = $this->state($novel);

        return response()->json(['epub' => $state], $state['status'] === 'building' ? 202 : 200);
    }

    private function dispatchBuild(Novel $novel, string $jobId): void
    {
        Log::info('Queuing EPUB build from the API', ['job_id' => $jobId, 'novel' => (int) $novel->id]);

        try {
            Bus::dispatch(
                (new RunNovelCommand('novel:epub', ['novel' => (int) $novel->id], $jobId))->onQueue('commands')
            );
        } catch (\Throwable $e) {
            // On the sync queue the job runs right here and rethrows after
            // recording its result: that result is the build's outcome.
            // Without one the job never reached the queue, so nothing is
            // running and the build record must not claim otherwise.
            if (!Cache::has("command_result_{$jobId}")) {
                Cache::forget(CacheHelper::apiEpubBuildKey((int) $novel->id));
                throw $e;
            }
            Log::warning("EPUB build for novel {$novel->id} threw: " . $e->getMessage());
        }
    }

    /**
     * The `epub` object of both endpoints.
     *
     * @return array{status: string, stale: bool, generated_at: ?string, size: ?int, filename: ?string, download_url: ?string, message: ?string}
     */
    private function state(Novel $novel): array
    {
        $id = (int) $novel->id;

        $path = GenerateePub::epubPath($novel);
        clearstatcache(true, $path);
        $exists = is_file($path);
        // epub_generated is set by every successful build; a file without it
        // (copied in by hand, or an old build) falls back to its mtime.
        $generatedAt = $exists
            ? ($novel->epub_generated ?? Carbon::createFromTimestamp(filemtime($path)))
            : null;

        $build = Cache::get(CacheHelper::apiEpubBuildKey($id));
        $result = $build ? Cache::get("command_result_{$build['job_id']}") : null;
        $building = $build !== null && $result === null;

        if ($build !== null && $result !== null) {
            // Remember the outcome beyond the build record's 30 minutes (and
            // the job result's hour). Same content for the same job, so a
            // repeated or concurrent write is harmless.
            Cache::put(CacheHelper::apiEpubLastBuildKey($id), [
                'job_id' => $build['job_id'],
                'started_at' => $build['started_at'],
                'success' => (bool) ($result['success'] ?? false),
                'message' => ($result['success'] ?? false) ? null : static::failureMessage($result),
            ], now()->addDays(self::LAST_BUILD_TTL_DAYS));
        }

        $last = $building ? null : Cache::get(CacheHelper::apiEpubLastBuildKey($id));
        // A failed build no longer matters once a file newer than it exists
        // (e.g. a later build from the web UI).
        $failed = $last !== null && !$last['success']
            && !($generatedAt !== null && $generatedAt->gte(Carbon::parse($last['started_at'])));

        $status = match (true) {
            $building => 'building',
            $failed => 'failed',
            $exists => 'ready',
            default => 'missing',
        };

        $chapters = static::downloadedChapters($id);
        $stale = $status === 'ready'
            && $chapters['last_download'] !== null
            && Carbon::parse($chapters['last_download'], config('app.timezone'))->gt($generatedAt);

        $message = match (true) {
            $status === 'failed' => $last['message'],
            $status === 'missing' && $chapters['count'] === 0 => self::NO_CHAPTERS,
            default => null,
        };

        return [
            'status' => $status,
            'stale' => $stale,
            'generated_at' => Presenter::timestamp($generatedAt),
            'size' => $exists ? (int) filesize($path) : null,
            'filename' => $exists ? GenerateePub::epubFilename($novel) : null,
            'download_url' => $exists ? Presenter::url(route('novels.download_epub', $id, false)) : null,
            'message' => $message,
        ];
    }

    /**
     * The chapters an EPUB contains (downloaded, not blacklisted, not
     * deleted, as GenerateePub selects them): how many, and the newest
     * download date. A direct query rather than the Library aggregates,
     * which are cached for a minute and count every row.
     *
     * @return array{count: int, last_download: ?string}
     */
    public static function downloadedChapters(int $novelId): array
    {
        $row = NovelChapter::where('novel_id', $novelId)
            ->where('status', 1)
            ->where('blacklist', 0)
            ->selectRaw('COUNT(*) as downloaded, MAX(download_date) as last_download')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->downloaded ?? 0),
            'last_download' => $row?->last_download !== null ? (string) $row->last_download : null,
        ];
    }

    /** One plain sentence from a RunNovelCommand failure result. */
    public static function failureMessage(array $result): string
    {
        $detail = trim((string) ($result['error'] ?? ''));
        if ($detail === '' && preg_match('/Error:\s*(.+)/', (string) ($result['output'] ?? ''), $m)) {
            $detail = trim($m[1]);
        }

        if ($detail === '') {
            return 'The EPUB build failed (exit code ' . (int) ($result['exit_code'] ?? 1) . ').';
        }

        return 'The EPUB build failed: ' . rtrim(Str::limit($detail, 300), '.') . '.';
    }
}
