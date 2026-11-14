<?php

namespace App\Http\Helpers;

use Illuminate\Support\Facades\Cache;

class CacheHelper
{
    /** Per-novel chapter aggregates behind the Library's counts and sorts (NovelController::libraryAggregates). */
    public const LIBRARY_AGGREGATES_KEY = 'library_aggregates_v1';

    /** Cache key for NovelController::novelStats(). */
    public static function novelStatsKey(int $novelId): string
    {
        // v2: counts instead of chapter-number arrays.
        // v3: failed / needs-review counts and the continue chapter's position.
        return "novel_stats_v3_{$novelId}";
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
    }
}
