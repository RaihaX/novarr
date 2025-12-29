<?php

namespace App\Http\Helpers;

use App\Group;
use App\Language;
use Illuminate\Support\Facades\Cache;

class CacheHelper
{
    /**
     * Get cached groups ordered by label.
     * Cache expires after 1 hour.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getCachedGroups()
    {
        return Cache::remember('groups_all', 3600, function () {
            return Group::orderBy('label')->get();
        });
    }

    /**
     * Get cached languages ordered by label.
     * Cache expires after 1 hour.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getCachedLanguages()
    {
        return Cache::remember('languages_all', 3600, function () {
            return Language::orderBy('label')->get();
        });
    }

    /**
     * Clear all reference data caches.
     *
     * @return void
     */
    public static function clearReferenceDataCache()
    {
        Cache::forget('groups_all');
        Cache::forget('languages_all');
    }

    /**
     * Clear novel-specific cache.
     * Clears the stable cache key used by NovelController::get_novel() and show().
     *
     * @param int $novelId
     * @return void
     */
    public static function clearNovelCache(int $novelId)
    {
        Cache::forget("novel_stats_{$novelId}");
    }

    /**
     * Clear manga-specific cache.
     * Clears the stable cache key used by MangaController::get_manga().
     *
     * @param int $mangaId
     * @return void
     */
    public static function clearMangaCache(int $mangaId)
    {
        Cache::forget("manga_{$mangaId}");
    }

    /**
     * Clear all DataTables caches.
     * Call this when novel/manga/chapter data changes.
     *
     * @return void
     */
    public static function clearDataTablesCaches()
    {
        Cache::forget('datatables_novels');
        Cache::forget('datatables_mangas');
        Cache::forget('datatables_latest_chapters');
        Cache::forget('datatables_missing_chapters');
    }

    /**
     * Clear novel-related DataTables caches.
     *
     * @return void
     */
    public static function clearNovelDataTablesCache()
    {
        Cache::forget('datatables_novels');
        Cache::forget('datatables_latest_chapters');
        Cache::forget('datatables_missing_chapters');
    }

    /**
     * Clear manga-related DataTables cache.
     *
     * @return void
     */
    public static function clearMangaDataTablesCache()
    {
        Cache::forget('datatables_mangas');
    }
}
