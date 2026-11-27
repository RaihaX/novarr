<?php

namespace App\Downloads;

use Illuminate\Support\Facades\Cache;

/**
 * Prowlarr searches made today (the app's timezone), against the daily cap
 * (novarr.downloads.searches_per_day, default 60): indexers such as NZBgeek
 * and NZBFinder allow only so many API calls a day, and each Prowlarr
 * search is one call per indexer. Every search counts (record()); only the
 * automatic ones are refused once the cap is reached (available()).
 */
final class SearchBudget
{
    private static function key(): string
    {
        return 'downloads.searches.' . now()->format('Y-m-d');
    }

    public static function record(): void
    {
        $key = self::key();
        Cache::add($key, 0, 172800);
        Cache::increment($key);
    }

    public static function used(): int
    {
        return (int) Cache::get(self::key(), 0);
    }

    public static function available(): bool
    {
        return self::used() < DownloadProfile::searchesPerDay();
    }
}
