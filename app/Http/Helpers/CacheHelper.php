<?php

namespace App\Http\Helpers;

use Illuminate\Support\Facades\Cache;

/**
 * Cache keys and invalidation for the library's derived data.
 *
 * Two kinds of entries:
 *  - global (chapter counts, downloads): one key, forgotten when a novel's
 *    chapters change (clearNovelCache());
 *  - per reader (anything built from read state): key suffix `_u{userId}`,
 *    stored with the epoch it was computed under (rememberForReader()). A
 *    download moves the epoch, so every reader's entry goes stale at once
 *    without listing readers; a reader's own read only forgets their keys
 *    (clearReaderCache()).
 */
class CacheHelper
{
    /**
     * Per-novel chapter counts behind the Library's counts and sorts
     * (NovelController::libraryAggregates()). Global; v2 dropped last_read,
     * which is per reader now (libraryReadingKey()).
     */
    public const LIBRARY_AGGREGATES_KEY = 'library_aggregates_v2';

    /** Library-wide epoch: moves whenever any novel's chapters change. */
    public const EPOCH_KEY = 'read_cache_epoch';

    /**
     * The Library's reading index for one reader, with their last read per
     * novel (NovelController::libraryReading() / libraryAggregates()).
     */
    public static function libraryReadingKey(int $userId): string
    {
        return "library_reading_v1_u{$userId}";
    }

    /** Home's Continue items for one reader (HomeController::continueShelf()). */
    public static function dashboardContinueKey(int $userId): string
    {
        return "dashboard_continue_u{$userId}";
    }

    /** Home's New chapters for one reader (their `reading` flag and read-from). Expires only. */
    public static function dashboardNewChaptersKey(int $userId): string
    {
        return "dashboard_new_chapters_u{$userId}";
    }

    /** Each novel's resume chapter for the native app's API (Api\V1\Presenter::resumeChapterIds()). */
    public static function apiResumeKey(int $userId): string
    {
        return "api_resume_v1_u{$userId}";
    }

    /** /stats for one reader (StatsController). Expires only. */
    public static function readingStatsKey(int $userId): string
    {
        return "reading_stats_v2_u{$userId}";
    }

    /**
     * The admin's Readers dashboard aggregates for all accounts (tiles,
     * chart, per-reader figures, most popular — App\Services\ReaderActivity::
     * overview()). Global; expires only (60 s), so it is never stale for long.
     */
    public static function readerActivityKey(): string
    {
        return 'reader_activity_v1';
    }

    /** Cache key for NovelController::novelStats(), per reader. */
    public static function novelStatsKey(int $novelId, int $userId): string
    {
        // v2: counts instead of chapter-number arrays.
        // v3: failed / needs-review counts and the continue chapter's position.
        return "novel_stats_v3_{$novelId}_u{$userId}";
    }

    /** Per-novel epoch: moves when that novel's chapters change. */
    public static function novelEpochKey(int $novelId): string
    {
        return "novel_cache_epoch_{$novelId}";
    }

    /**
     * An EPUB build started through the native app's API
     * (Api\V1\EpubController): {job_id, started_at}. Expires after 30 minutes
     * so a crashed worker can't leave the novel "building" forever.
     */
    public static function apiEpubBuildKey(int $novelId): string
    {
        return "api_epub_build_{$novelId}";
    }

    /** The outcome of the last API-started EPUB build, kept after its build record expires. */
    public static function apiEpubLastBuildKey(int $novelId): string
    {
        return "api_epub_last_build_{$novelId}";
    }

    public static function epoch(): string
    {
        return (string) Cache::rememberForever(static::EPOCH_KEY, fn() => static::newEpoch());
    }

    public static function novelEpoch(int $novelId): string
    {
        return (string) Cache::rememberForever(static::novelEpochKey($novelId), fn() => static::newEpoch());
    }

    /**
     * Cache::remember() for a per-reader entry: the value is reused only
     * while it was computed under $epoch (see the class comment). An entry
     * computed while the epoch moved is stored under the old epoch and so is
     * never served.
     */
    public static function rememberForReader(string $key, string $epoch, $ttl, \Closure $callback): mixed
    {
        $hit = Cache::get($key);
        if (is_array($hit) && ($hit['epoch'] ?? null) === $epoch && array_key_exists('value', $hit)) {
            return $hit['value'];
        }

        $value = $callback();
        Cache::put($key, ['epoch' => $epoch, 'value' => $value], $ttl);

        return $value;
    }

    /**
     * A novel's chapters changed for everyone (download, edit, delete, added
     * novel): its stats and every library-wide aggregate, for every reader.
     *
     * @param int $novelId
     * @return void
     */
    public static function clearNovelCache(int $novelId)
    {
        Cache::forever(static::novelEpochKey($novelId), static::newEpoch());
        Cache::forever(static::EPOCH_KEY, static::newEpoch());
        Cache::forget(static::LIBRARY_AGGREGATES_KEY);
    }

    /**
     * One reader's read state changed in these novels: only their entries.
     *
     * @param int[] $novelIds
     */
    public static function clearReaderCache(int $userId, array $novelIds): void
    {
        static::clearResumeCache($userId, $novelIds);
        Cache::forget(static::libraryReadingKey($userId));
    }

    /**
     * One reader's position moved in these novels without a read mark: only
     * the entries holding a resume point (App\Services\ResumePoint reads
     * positions — the chapter read last may now be unfinished, or finished):
     * the novel page's stats, Home's Continue, the API's resume map.
     *
     * @param int[] $novelIds
     */
    public static function clearResumeCache(int $userId, array $novelIds): void
    {
        foreach (array_unique(array_map('intval', $novelIds)) as $novelId) {
            Cache::forget(static::novelStatsKey($novelId, $userId));
        }
        Cache::forget(static::dashboardContinueKey($userId));
        Cache::forget(static::apiResumeKey($userId));
    }

    private static function newEpoch(): string
    {
        return bin2hex(random_bytes(6));
    }
}
