<?php

namespace App\Services\Requests;

use App\Http\Controllers\Api\V1\DiscoverController;
use App\Novel;
use App\Services\Discover;
use App\Services\SourceHealth;
use App\Sources\PagedDiscover;
use App\Sources\Source;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The Requests page's Discover shelf (GET /api/v1/requests/browse): a
 * source's charts — popular, new & popular, top rated… — for readers to
 * request from, for every medium (books: Open Library's trending and
 * classics through BookCatalogueSource; novels: the first novel source
 * with a chart, NovelPing's popular / new). The source is the first of the medium's request-search
 * sources (RequestSearch::sources(): search order, hidden Discover sources
 * left out) that offers any of LISTS.
 *
 * A page is asked of the source directly (never Discover::page(), whose
 * covers are signed for the admin's Discover page), its items grouped as a
 * request search groups them (RequestSearch::group()), so a request made
 * from the shelf names a group key exactly like one made from a search
 * (Requests::createFromBrowse()). Each group also keeps the item's
 * alt_titles (FindRequestCandidates matches the other sources' results on
 * them).
 *
 * Pages are cached for novarr.requests.browse_cache_minutes per medium +
 * source + list + page; a cold page is fetched under a lock, so several
 * readers opening it at once make one set of source calls (a cold MangaDex
 * page is ~40 paced calls, ~9 s). requests:browse-warm refreshes page 1 of
 * every list. What depends on the reader (in_library, requested, signed
 * covers) is worked out when the page is presented (RequestPresenter).
 */
class RequestBrowse
{
    /**
     * List id => name, in shelf order. A source offers some of them
     * (Source::discoverLists()): MangaDex popular / trending / top, the
     * novel catch-all (NovelPing) popular / new, the book catalogue
     * (Open Library) popular / trending / classics, and Hardcover's
     * trending / popular when a Hardcover token is set.
     */
    public const LISTS = [
        'popular' => 'Popular', 'trending' => 'New & popular', 'new' => 'New', 'top' => 'Top rated', 'classics' => 'Classics',
        // Books, with a Hardcover token (App\Books\Hardcover::trending()).
        'hardcover_trending' => 'Trending on Hardcover', 'hardcover_popular' => 'Popular on Hardcover',
    ];

    /** A medium's own name for a list, where LISTS' would mislead. */
    public const LIST_NAMES = [
        Novel::MEDIUM_BOOK => ['popular' => 'Popular this week', 'trending' => 'Trending today'],
    ];

    /** The media the shelf is offered for (the Requests page's picker order). */
    public const MEDIA = [Novel::MEDIUM_BOOK, Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA, Novel::MEDIUM_COMIC];

    /** The last page a reader may open. */
    public const MAX_PAGE = 10;

    /** Seconds a reader waits for another reader's fetch of the same page. */
    public const LOCK_WAIT_SECONDS = 20;

    /** Seconds the fetch lock lives (a cold page takes ~9 s). */
    public const LOCK_SECONDS = 120;

    /** alt_titles kept per group, and their length. */
    public const MAX_ALT_TITLES = 10;

    public function __construct(private RequestSearch $searches, private Discover $discover)
    {
    }

    public static function enabled(): bool
    {
        return (bool) config('novarr.requests.browse', true);
    }

    public static function cacheMinutes(): int
    {
        return max(1, (int) config('novarr.requests.browse_cache_minutes', 360));
    }

    public static function cacheKey(string $medium, string $sourceKey, string $list, int $page): string
    {
        return 'request_browse_v1_' . sha1("{$medium}|{$sourceKey}|{$list}|{$page}");
    }

    /**
     * The source the shelf shows for a medium, or null (browse off, a medium
     * without a shelf, no source offering a list).
     *
     * @return ?array{key: string, source: Source}
     */
    public function source(string $medium): ?array
    {
        if (!static::enabled() || !in_array($medium, self::MEDIA, true)) {
            return null;
        }
        foreach ($this->searches->sources($this->discover, $medium) as $key => $source) {
            try {
                $offered = $source->discoverLists();
            } catch (\Throwable) {
                continue;
            }
            if (array_intersect(array_keys(self::LISTS), $offered) !== []) {
                return ['key' => (string) $key, 'source' => $source];
            }
        }

        return null;
    }

    /**
     * The lists the shelf offers for a medium, in LISTS order; [] when none.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function lists(string $medium): array
    {
        $chosen = $this->source($medium);

        return $chosen === null ? [] : static::listsOf($chosen['source'], $medium);
    }

    /** @return array<int, array{id: string, name: string}> */
    private static function listsOf(Source $source, string $medium): array
    {
        try {
            $offered = $source->discoverLists();
        } catch (\Throwable) {
            return [];
        }
        $lists = [];
        foreach (self::LISTS as $id => $name) {
            if (in_array($id, $offered, true)) {
                $lists[] = ['id' => $id, 'name' => self::LIST_NAMES[$medium][$id] ?? $name];
            }
        }

        return $lists;
    }

    /**
     * One page of a list: its groups (RequestSearch::group()'s shape plus
     * alt_titles), whether a later page has items, and the source. Empty
     * for a list the source doesn't offer or a page out of 1..MAX_PAGE; null
     * when the source could not be reached (never cached).
     *
     * @return ?array{groups: array, has_more: bool, source: string, source_name: string}
     *
     * @throws LockTimeoutException another reader's fetch of the page took too long
     */
    public function page(string $medium, string $list, int $page): ?array
    {
        $chosen = $this->source($medium);
        if ($chosen === null) {
            return ['groups' => [], 'has_more' => false, 'source' => '', 'source_name' => ''];
        }
        ['key' => $key, 'source' => $source] = $chosen;
        if (!$this->offers($source, $list) || $page < 1 || $page > self::MAX_PAGE) {
            return static::empty($source, $key);
        }

        $cacheKey = static::cacheKey($medium, $key, $list, $page);
        $fetch = fn() => $this->fetch($medium, $source, $key, $list, $page);

        try {
            $cached = static::valid(Cache::get($cacheKey));
            if ($cached !== null) {
                return $cached;
            }

            return Cache::lock($cacheKey . '_lock', self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($cacheKey, $fetch) {
                // Another reader may have fetched it while this one waited.
                $cached = static::valid(Cache::get($cacheKey));
                if ($cached !== null) {
                    return $cached;
                }
                $result = $fetch();
                if ($result !== null) {
                    $this->store($cacheKey, $result);
                }

                return $result;
            });
        } catch (LockTimeoutException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // fetch() catches its own failures: this is the cache store.
            Log::warning('Request browse: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');

            return $fetch();
        }
    }

    /**
     * Fetch a page afresh and replace its cache entry (requests:browse-warm).
     * False when it could not be fetched: the old entry stays.
     */
    public function refresh(string $medium, string $list, int $page = 1): bool
    {
        $chosen = $this->source($medium);
        if ($chosen === null || !$this->offers($chosen['source'], $list) || $page < 1 || $page > self::MAX_PAGE) {
            return false;
        }
        $result = $this->fetch($medium, $chosen['source'], $chosen['key'], $list, $page);
        if ($result === null) {
            return false;
        }

        return $this->store(static::cacheKey($medium, $chosen['key'], $list, $page), $result);
    }

    /**
     * The group with this key on that page of the list (page() — fetched
     * when not cached); failing that, on any other page of the list already
     * cached (the reader's page may have been refreshed since they saw it).
     * Null when it is on none of them.
     *
     * @throws LockTimeoutException
     */
    public function find(string $medium, string $list, int $page, string $key): ?array
    {
        if (!isset(self::LISTS[$list])) {
            return null;
        }
        $result = $this->page($medium, $list, $page);
        $found = static::groupIn($result['groups'] ?? [], $key);
        if ($found !== null) {
            return $found;
        }

        $chosen = $this->source($medium);
        if ($chosen === null) {
            return null;
        }
        for ($other = 1; $other <= self::MAX_PAGE; $other++) {
            if ($other === $page) {
                continue;
            }
            try {
                $cached = static::valid(Cache::get(static::cacheKey($medium, $chosen['key'], $list, $other)));
            } catch (\Throwable) {
                return null;
            }
            $found = static::groupIn($cached['groups'] ?? [], $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function groupIn(array $groups, string $key): ?array
    {
        foreach ($groups as $group) {
            if (is_array($group) && ($group['key'] ?? null) === $key) {
                return $group;
            }
        }

        return null;
    }

    private function offers(Source $source, string $list): bool
    {
        if (!isset(self::LISTS[$list])) {
            return false;
        }
        try {
            return in_array($list, $source->discoverLists(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function empty(Source $source, string $key): array
    {
        return ['groups' => [], 'has_more' => false, 'source' => $key, 'source_name' => RequestSearch::label($source, $key)];
    }

    /** A cached page, or null when the entry is missing or not a page. */
    private static function valid(mixed $cached): ?array
    {
        return is_array($cached) && is_array($cached['groups'] ?? null) && is_bool($cached['has_more'] ?? null)
            && is_string($cached['source'] ?? null) && is_string($cached['source_name'] ?? null)
            ? $cached : null;
    }

    private function store(string $cacheKey, array $result): bool
    {
        try {
            return Cache::put($cacheKey, $result, now()->addMinutes(static::cacheMinutes()));
        } catch (\Throwable $e) {
            Log::warning('Request browse: page not cached: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Ask the source for one page and group it. Null when the source could
     * not be reached (answered null or threw).
     */
    private function fetch(string $medium, Source $source, string $key, string $list, int $page): ?array
    {
        try {
            if ($source instanceof PagedDiscover) {
                $answer = $source->discoverPage($list, null, $page);
                $items = is_array($answer['items'] ?? null) ? $answer['items'] : null;
                $hasMore = (bool) ($answer['has_more'] ?? false);
            } elseif ($page === 1) {
                $items = $source->discover($list);
                $hasMore = false;
            } else {
                return static::empty($source, $key);
            }
        } catch (\Throwable $e) {
            Log::warning("Request browse: {$key} {$list} page {$page} threw: " . $e->getMessage());
            $items = null;
            $hasMore = false;
        }
        SourceHealth::record(SourceHealth::keyForSource($source), SourceHealth::KIND_SEARCH, $items !== null, 'fetch_failed', "Request browse {$list} page {$page}: the source could not be reached.");
        if (!is_array($items)) {
            return null;
        }

        // Hits as RequestSearch::run() builds them (no relevance filter: there is no query).
        $name = RequestSearch::label($source, $key);
        $hits = [];
        $altTitles = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hits[] = ['key' => $key, 'name' => $name, 'item' => DiscoverController::item($item, $key)];
            $groupKey = RequestSearch::groupKey($medium, Discover::normalizeName((string) ($item['name'] ?? '')));
            foreach ((array) ($item['alt_titles'] ?? []) as $alt) {
                if (is_string($alt) && trim($alt) !== '') {
                    $altTitles[$groupKey][] = mb_substr(trim($alt), 0, 255);
                }
            }
        }

        $groups = array_map(function (array $group) use ($altTitles) {
            $group['alt_titles'] = array_slice(array_values(array_unique($altTitles[$group['key']] ?? [])), 0, self::MAX_ALT_TITLES);

            return $group;
        }, RequestSearch::group($hits, $medium, ''));

        return [
            'groups' => $groups,
            'has_more' => $hasMore && $page < self::MAX_PAGE,
            'source' => $key,
            'source_name' => $name,
        ];
    }
}
