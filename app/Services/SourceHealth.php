<?php

namespace App\Services;

use App\Novel;
use App\Sources\AbstractSource;
use App\Sources\Source;
use App\Sources\SourceResolver;
use App\Sources\SuwayomiSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Source health: how often each source answered a chapter download, a
 * chapter-list (TOC) walk and a search, counted per hour in `source_stats`
 * (one row per day, hour, source, kind and failure reason; reason '' holds
 * the successes), and judged over the last 24 hours for Health → Sources and
 * the hourly alert (sources:health-check).
 *
 * Source identity (the `source` column) is one rule for every kind:
 *   - the adapter's registry key (config novarr.sources): "novelfull",
 *     "royalroad", "mangadex" …;
 *   - a Suwayomi series or Discover entry: "suwayomi:{sourceId}" (plain
 *     "suwayomi" while a series' Suwayomi source is not known yet);
 *   - a novel only the catch-all adapter (the last registered one,
 *     NovelArrowSource) claims, on a host that adapter does not list: the
 *     novel's host (translator URL, else group URL), lower-cased.
 * A search or Discover call is recorded under the Discover key, which is
 * the same key space.
 *
 * Recording never throws: a stats failure must never fail a download.
 */
class SourceHealth
{
    public const KIND_CHAPTER = 'chapter';
    public const KIND_TOC = 'toc';
    public const KIND_SEARCH = 'search';

    public const KINDS = [self::KIND_CHAPTER, self::KIND_TOC, self::KIND_SEARCH];

    public const FAILING = 'failing';
    public const DEGRADED = 'degraded';
    public const HEALTHY = 'healthy';
    public const IDLE = 'idle';

    /** Worst first: the page's sort order and how a source's kinds combine. */
    public const RANK = [self::FAILING => 0, self::DEGRADED => 1, self::HEALTHY => 2, self::IDLE => 3];

    public const LABELS = [
        self::FAILING => 'Failing',
        self::DEGRADED => 'Degraded',
        self::HEALTHY => 'Healthy',
        self::IDLE => 'No recent activity',
    ];

    public const KIND_LABELS = [
        self::KIND_CHAPTER => 'Chapters',
        self::KIND_TOC => 'Chapter lists',
        self::KIND_SEARCH => 'Searches',
    ];

    /** The source a failure is charged to when nothing better is known. */
    public const UNKNOWN = 'unknown';

    private const TABLE = 'source_stats';

    /** Series per source, for the page (database only). */
    private const SERIES_CACHE_KEY = 'source_health_series';

    /** @var array<string, string> novel identity memo (lane processes work many chapters of one novel) */
    private static array $novelKeys = [];

    // ---- Recording -------------------------------------------------------------

    /**
     * Count one call to a source: $ok, or a failure with its category
     * ($reason, e.g. rate_limited / not_found / no_content / fetch_failed)
     * and a short message. One atomic upsert; never throws.
     */
    public static function record(string $source, string $kind, bool $ok, ?string $reason = null, ?string $message = null): void
    {
        try {
            if (!in_array($kind, self::KINDS, true)) {
                return;
            }
            $source = mb_substr(trim($source), 0, 191);
            $source = $source !== '' ? $source : self::UNKNOWN;
            $now = now();
            $bucket = [
                'day' => $now->toDateString(),
                'hour' => (int) $now->hour,
                'source' => $source,
                'kind' => $kind,
                'reason' => $ok ? '' : self::reasonKey($reason),
            ];

            if ($ok) {
                $insert = $bucket + ['ok' => 1, 'failed' => 0, 'last_error' => null, 'last_failed_at' => null, 'last_ok_at' => $now];
                $update = ['ok' => DB::raw('ok + 1'), 'last_ok_at' => $now];
            } else {
                $error = $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 255) : null;
                $insert = $bucket + ['ok' => 0, 'failed' => 1, 'last_error' => $error, 'last_failed_at' => $now, 'last_ok_at' => null];
                $update = ['failed' => DB::raw('failed + 1'), 'last_error' => $error, 'last_failed_at' => $now];
            }

            DB::table(self::TABLE)->upsert([$insert], ['day', 'hour', 'source', 'kind', 'reason'], $update);
        } catch (\Throwable $e) {
            try {
                Log::warning("Source health: could not record a {$kind} result for {$source}: " . $e->getMessage());
            } catch (\Throwable) {
                // Not even the log: give up quietly.
            }
        }
    }

    /**
     * Count a novel's chapter-list walk (NovelScraper): ok when it returned
     * entries and was not cut short (an incremental walk may rightly bring
     * nothing new). Never throws.
     */
    public static function recordToc(Novel $novel, array $toc, bool $partial, bool $incremental = false): void
    {
        $ok = !$partial && ($toc !== [] || $incremental);
        self::record(
            self::keyForNovel($novel),
            self::KIND_TOC,
            $ok,
            $partial ? 'partial' : 'empty_toc',
            $partial
                ? "The chapter list walk of {$novel->name} stopped early (a page failed)."
                : "The chapter list of {$novel->name} came back empty."
        );
    }

    /** A failure category as stored: lower-case letters, digits, '_' and '-', at most 32 characters. */
    public static function reasonKey(?string $reason): string
    {
        $key = substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $reason)), 0, 32);

        return $key !== '' ? $key : 'failed';
    }

    // ---- Identity ---------------------------------------------------------------

    /** The source key of a novel (see the class comment). Never throws. */
    public static function keyForNovel(Novel $novel): string
    {
        $host = null;
        try {
            $host = self::novelHost($novel);
            $memo = $novel->id . '|' . $host . '|' . $novel->source_ref . '|' . $novel->translator_url;
            if (isset(self::$novelKeys[$memo])) {
                return self::$novelKeys[$memo];
            }

            $sources = SourceResolver::all();
            $fallbackKey = array_key_last($sources);
            $key = null;
            foreach ($sources as $sourceKey => $source) {
                if (!$source->matches($novel)) {
                    continue;
                }
                if ($source instanceof SuwayomiSource) {
                    $key = $source->downloadLane($novel) ?? (string) $sourceKey;
                } elseif ($sourceKey === $fallbackKey && $host !== null
                    && $source instanceof AbstractSource && !AbstractSource::hostMatches($host, $source->hosts())) {
                    // Only the catch-all claims it: the site is its own host.
                    $key = $host;
                } else {
                    $key = (string) $sourceKey;
                }
                break;
            }
            $key ??= $host ?? self::UNKNOWN;

            if (count(self::$novelKeys) > 500) {
                self::$novelKeys = [];
            }

            return self::$novelKeys[$memo] = $key;
        } catch (\Throwable $e) {
            return $host ?? self::UNKNOWN;
        }
    }

    /** The source key of an adapter instance (a Discover entry or a registered source). Never throws. */
    public static function keyForSource(Source $source): string
    {
        try {
            if ($source instanceof SuwayomiSource && $source->sourceId() !== null) {
                return SuwayomiSource::LANE_PREFIX . $source->sourceId();
            }
            $key = array_search(get_class($source), SourceResolver::classes(), true);

            return is_string($key) ? $key : $source->name();
        } catch (\Throwable $e) {
            return self::UNKNOWN;
        }
    }

    /** The novel's host: its translator URL's, else its group URL's; lower-cased. */
    private static function novelHost(Novel $novel): ?string
    {
        $host = AbstractSource::urlHost((string) ($novel->translator_url ?? ''));
        if ($host === null || $host === '') {
            // Only now: the group is a query when it isn't loaded.
            $host = AbstractSource::urlHost((string) ($novel->group->url ?? ''));
        }

        return $host !== null && $host !== '' ? strtolower($host) : null;
    }

    // ---- Retention ----------------------------------------------------------------

    public static function retentionDays(): int
    {
        return max(1, (int) config('novarr.source_health.retention_days', 90));
    }

    /** Delete counters older than the retention; returns the rows removed. */
    public static function prune(?int $days = null): int
    {
        $days = max(1, $days ?? self::retentionDays());

        return DB::table(self::TABLE)->where('day', '<', now()->subDays($days)->toDateString())->delete();
    }

    // ---- Judging ------------------------------------------------------------------

    /**
     * The status of one kind of call over 24 hours:
     *   no attempts                                         → idle
     *   fewer than min_attempts                             → healthy (too few to judge)
     *   ok share < failing_below, also with rate-limited
     *     failures left out (and still min_attempts of them) → failing
     *   ok share < degraded_below (or the rate-limit cap)   → degraded
     *   otherwise                                           → healthy
     */
    public static function judge(int $ok, int $failed, int $rateLimited = 0): string
    {
        $attempts = $ok + $failed;
        if ($attempts <= 0) {
            return self::IDLE;
        }

        $min = max(1, (int) config('novarr.source_health.min_attempts', 20));
        if ($attempts < $min) {
            return self::HEALTHY;
        }

        $failingBelow = (float) config('novarr.source_health.failing_below', 0.5);
        $degradedBelow = (float) config('novarr.source_health.degraded_below', 0.85);
        $share = $ok / $attempts;

        if ($share < $failingBelow) {
            // Rate limits are the scraper's pace, not a broken site: they
            // alone never make a source Failing.
            $real = $attempts - min($rateLimited, $failed);

            return $real >= $min && $ok / $real < $failingBelow ? self::FAILING : self::DEGRADED;
        }

        return $share < $degradedBelow ? self::DEGRADED : self::HEALTHY;
    }

    /** The worse of two statuses. */
    public static function worse(string $a, string $b): string
    {
        return (self::RANK[$a] ?? 3) <= (self::RANK[$b] ?? 3) ? $a : $b;
    }

    /** Human words for a failure category. */
    public static function reasonLabel(?string $reason): string
    {
        return match ((string) $reason) {
            'rate_limited' => 'rate limited (HTTP 429/503)',
            'not_found' => 'not found (404)',
            'no_content' => 'empty page',
            'short_content' => 'too short',
            'fetch_failed' => 'fetch failed',
            'cloudflare' => 'Cloudflare challenge',
            'exception' => 'scraper error',
            'partial_pages' => 'page images missing',
            'empty_toc' => 'empty chapter list',
            'partial' => 'chapter list cut short',
            '' => 'none',
            default => str_replace('_', ' ', (string) $reason),
        };
    }

    // ---- The page -----------------------------------------------------------------

    /**
     * Every source worth showing, worst first: registered adapters, sources
     * with recorded calls in the last 7 days and sources series are on.
     * Database and cache only — never a request to a site.
     *
     * @return array<int, array{
     *     key: string, name: string, host: string, medium: string, series: int,
     *     kinds: array<string, array{day: array, week: array, status: string}>,
     *     daily: array<int, array{day: string, ok: int, failed: int}>,
     *     day_attempts: int, day_rate: ?float,
     *     top_reason: ?array{reason: string, label: string, count: int},
     *     last_error: ?array{message: ?string, reason: string, kind: string, at: Carbon},
     *     last_ok_at: ?Carbon, status: string, status_label: string, low_volume: bool
     * }>
     */
    public function overview(): array
    {
        $now = now();
        $weekStart = $now->copy()->startOfDay()->subDays(6);
        $dayStart = $now->copy()->subHours(23)->startOfHour();

        $rows = DB::table(self::TABLE)
            ->where('day', '>=', $weekStart->toDateString())
            ->get(['day', 'hour', 'source', 'kind', 'reason', 'ok', 'failed']);

        $days = [];
        for ($d = $weekStart->copy(); $d->lte($now); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        $blank = fn() => ['ok' => 0, 'failed' => 0, 'rate_limited' => 0];
        $stats = [];
        foreach ($rows as $row) {
            $key = (string) $row->source;
            $kind = (string) $row->kind;
            if (!in_array($kind, self::KINDS, true)) {
                continue;
            }
            $day = substr((string) $row->day, 0, 10);
            $ok = (int) $row->ok;
            $failed = (int) $row->failed;
            $rateLimited = $row->reason === 'rate_limited' ? $failed : 0;
            $at = Carbon::parse($day)->setTime((int) $row->hour, 0);
            $inDay = $at->gte($dayStart);

            $stats[$key] ??= ['week' => [], 'day' => [], 'daily' => [], 'reasons' => []];
            foreach ($inDay ? ['week', 'day'] : ['week'] as $window) {
                $stats[$key][$window][$kind] ??= $blank();
                $stats[$key][$window][$kind]['ok'] += $ok;
                $stats[$key][$window][$kind]['failed'] += $failed;
                $stats[$key][$window][$kind]['rate_limited'] += $rateLimited;
            }
            $stats[$key]['daily'][$day] ??= ['ok' => 0, 'failed' => 0];
            $stats[$key]['daily'][$day]['ok'] += $ok;
            $stats[$key]['daily'][$day]['failed'] += $failed;
            if ($failed > 0 && $row->reason !== '') {
                $stats[$key]['reasons'][$row->reason] = ($stats[$key]['reasons'][$row->reason] ?? 0) + $failed;
            }
        }

        $latest = $this->latest();
        $series = $this->seriesBySource();
        $registered = $this->registered();

        $keys = array_unique(array_merge(
            array_keys($registered),
            array_keys($stats),
            array_keys($series),
            array_keys($latest),
        ));

        $result = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $s = $stats[$key] ?? ['week' => [], 'day' => [], 'daily' => [], 'reasons' => []];
            // A registered parent whose entries are recorded on their own
            // ("suwayomi" → "suwayomi:123") is only listed when it has series
            // or calls of its own.
            if (isset($registered[$key]) && $registered[$key]['expands'] && !isset($stats[$key]) && !isset($series[$key]) && !isset($latest[$key])) {
                continue;
            }

            $kinds = [];
            $status = self::IDLE;
            $dayOk = 0;
            $dayAttempts = 0;
            $lowVolume = false;
            foreach (self::KINDS as $kind) {
                $day = $s['day'][$kind] ?? $blank();
                $week = $s['week'][$kind] ?? $blank();
                $kindStatus = self::judge($day['ok'], $day['failed'], $day['rate_limited']);
                $status = self::worse($status, $kindStatus);
                $dayOk += $day['ok'];
                $dayAttempts += $day['ok'] + $day['failed'];
                $min = max(1, (int) config('novarr.source_health.min_attempts', 20));
                if ($day['failed'] > 0 && $day['ok'] + $day['failed'] < $min) {
                    $lowVolume = true;
                }
                $kinds[$kind] = [
                    'day' => self::window($day),
                    'week' => self::window($week),
                    'status' => $kindStatus,
                ];
            }

            $daily = array_map(fn($d) => ['day' => $d] + ($s['daily'][$d] ?? ['ok' => 0, 'failed' => 0]), $days);

            arsort($s['reasons']);
            $topReason = null;
            if ($s['reasons'] !== []) {
                $reason = (string) array_key_first($s['reasons']);
                $topReason = ['reason' => $reason, 'label' => self::reasonLabel($reason), 'count' => (int) $s['reasons'][$reason]];
            }

            $meta = $registered[$key] ?? $this->describe($key, $series[$key]['medium'] ?? null);

            $result[] = [
                'key' => $key,
                'name' => $meta['name'],
                'host' => $meta['host'],
                'medium' => $series[$key]['medium'] ?? $meta['medium'],
                'series' => (int) ($series[$key]['count'] ?? 0),
                'kinds' => $kinds,
                'daily' => $daily,
                'day_attempts' => $dayAttempts,
                'day_rate' => $dayAttempts > 0 ? $dayOk / $dayAttempts : null,
                'top_reason' => $topReason,
                'last_error' => $latest[$key]['error'] ?? null,
                'last_ok_at' => $latest[$key]['ok_at'] ?? null,
                'status' => $status,
                'status_label' => self::LABELS[$status],
                'low_volume' => $lowVolume && $status === self::HEALTHY,
            ];
        }

        usort($result, fn($a, $b) => (self::RANK[$a['status']] <=> self::RANK[$b['status']])
            ?: (($a['day_rate'] ?? 2) <=> ($b['day_rate'] ?? 2))
            ?: strcasecmp($a['name'], $b['name']));

        return $result;
    }

    /** @param array{ok: int, failed: int, rate_limited: int} $c */
    private static function window(array $c): array
    {
        $attempts = $c['ok'] + $c['failed'];

        return [
            'ok' => $c['ok'],
            'failed' => $c['failed'],
            'rate_limited' => $c['rate_limited'],
            'attempts' => $attempts,
            'rate' => $attempts > 0 ? $c['ok'] / $attempts : null,
        ];
    }

    /**
     * Per source over the whole retention: the last success time and the
     * latest failure (its message, reason, kind and time).
     *
     * @return array<string, array{ok_at: ?Carbon, error: ?array}>
     */
    private function latest(): array
    {
        $maxima = DB::table(self::TABLE)
            ->select('source', DB::raw('MAX(last_ok_at) AS ok_at'), DB::raw('MAX(last_failed_at) AS failed_at'))
            ->groupBy('source')
            ->get();

        $latest = [];
        $failedAt = [];
        foreach ($maxima as $m) {
            $latest[(string) $m->source] = [
                'ok_at' => $m->ok_at ? Carbon::parse($m->ok_at) : null,
                'error' => null,
            ];
            if ($m->failed_at) {
                $failedAt[(string) $m->source] = (string) $m->failed_at;
            }
        }

        if ($failedAt !== []) {
            $errors = DB::table(self::TABLE)
                ->where(function ($q) use ($failedAt) {
                    foreach ($failedAt as $source => $at) {
                        $q->orWhere(fn($w) => $w->where('source', $source)->where('last_failed_at', $at));
                    }
                })
                ->get(['source', 'kind', 'reason', 'last_error', 'last_failed_at']);
            foreach ($errors as $e) {
                $latest[(string) $e->source]['error'] ??= [
                    'message' => $e->last_error,
                    'reason' => (string) $e->reason,
                    'kind' => (string) $e->kind,
                    'at' => Carbon::parse($e->last_failed_at),
                ];
            }
        }

        return $latest;
    }

    /**
     * Series in the library per source key, with their medium (cached ten
     * minutes; database only).
     *
     * @return array<string, array{count: int, medium: string}>
     */
    public function seriesBySource(): array
    {
        try {
            return Cache::remember(self::SERIES_CACHE_KEY, 600, function () {
                $bySource = [];
                Novel::query()->with('group')->chunkById(500, function ($novels) use (&$bySource) {
                    foreach ($novels as $novel) {
                        $key = self::keyForNovel($novel);
                        $bySource[$key] ??= ['count' => 0, 'medium' => (string) ($novel->medium ?: Novel::MEDIUM_NOVEL)];
                        $bySource[$key]['count']++;
                    }
                });

                return $bySource;
            });
        } catch (\Throwable $e) {
            Log::warning('Source health: series per source not counted: ' . $e->getMessage());

            return [];
        }
    }

    /** @return array<string, array{name: string, host: string, medium: string, expands: bool}> */
    private function registered(): array
    {
        $registered = [];
        try {
            foreach (SourceResolver::all() as $key => $source) {
                $registered[(string) $key] = [
                    'name' => $source->discoverLabel(),
                    'host' => $source->discoverHost(),
                    'medium' => $source->medium(),
                    'expands' => $source instanceof \App\Sources\ExpandsDiscover,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Source health: registered sources not read: ' . $e->getMessage());
        }

        return $registered;
    }

    /** Name, host and medium of a key that is not a registered adapter. */
    private function describe(string $key, ?string $medium): array
    {
        if (str_starts_with($key, SuwayomiSource::LANE_PREFIX)) {
            $host = '';
            try {
                $host = (new SuwayomiSource())->discoverHost();
            } catch (\Throwable) {
            }

            return ['name' => DownloadLanes::label($key), 'host' => $host, 'medium' => Novel::MEDIUM_MANGA, 'expands' => false];
        }

        return ['name' => $key, 'host' => $key, 'medium' => $medium ?? Novel::MEDIUM_NOVEL, 'expands' => false];
    }
}
