<?php

namespace App\Http\Helpers;

use Illuminate\Support\Facades\Cache;

class CacheHelper
{
    /** Per-novel chapter aggregates behind the Library's counts and sorts (NovelController::libraryAggregates). */
    public const LIBRARY_AGGREGATES_KEY = 'library_aggregates_v1';

    /** Each novel's resume chapter for the native app's API (Api\V1\Presenter::resumeChapterIds). */
    public const API_RESUME_KEY = 'api_resume_v1';

    /** Cache key for NovelController::novelStats(). */
    public static function novelStatsKey(int $novelId): string
    {
        // v2: counts instead of chapter-number arrays.
        // v3: failed / needs-review counts and the continue chapter's position.
        return "novel_stats_v3_{$novelId}";
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

    /**
     * Clear novel-specific cache: the novel page's stats, plus library-wide
     * aggregates that depend on this novel's read/download state.
     *
     * @param int $novelId
     * @return void
     */
    public static function clearNovelCache(int $novelId)
    {
        Cache::forget(static::novelStatsKey($novelId));
        // Library-wide aggregates (grid sublines, chip counts) and the home
        // Continue row depend on this novel's read/download state.
        Cache::forget('library_reading_v1');
        Cache::forget(static::LIBRARY_AGGREGATES_KEY);
        Cache::forget('dashboard_continue');
        Cache::forget(static::API_RESUME_KEY);
    }
}
