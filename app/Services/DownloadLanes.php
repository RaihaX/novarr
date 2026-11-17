<?php

namespace App\Services;

use App\Console\Commands\ChapterScraper;
use App\Novel;
use App\NovelChapter;
use App\Sources\SourceResolver;
use App\Sources\SuwayomiSource;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Download lanes: at most one chapter download runs per source at any
 * moment, and different sources download at the same time.
 *
 * A novel's lane is the host its chapters are fetched from (exactly the
 * host the sweep always grouped, paced and cooled down by), unless its
 * Source names a finer one (Source::downloadLane()): every Suwayomi series
 * lives on the user's one server, but each Suwayomi source behind it is an
 * independent site, so those novels get "suwayomi:{sourceId}". A lane string
 * is also the key of the lane's pace (HostPace) and cooldown / failure
 * counters (ChapterScraper), so a dead site only cools its own lane down.
 *
 * Whoever downloads chapters of a lane holds its lock (lock()): the lane
 * processes the dispatcher starts (`novel:chapter --lane=…`) and explicit
 * `novel:chapter {id}` runs. While it works, the holder keeps a small state
 * record in the cache (putState()) for the Activity page; both expire on
 * their own, so a killed process frees its lane.
 */
class DownloadLanes
{
    /** Lane of novels whose chapters have no usable host (junk URLs). */
    public const NO_HOST = 'no-host';

    /**
     * Lane lock lifetime. Short, so a killed process frees its lane soon;
     * a live holder renews it (release + take back, like the novel lock)
     * once RENEW_AFTER_SECONDS have passed, checked before every chapter.
     * The longest gap between two checks is one politeness wait (at most
     * HostPace::MAX_PACE x 1.5) plus one chapter (a manga chapter's pages),
     * far below the TTL.
     */
    public const LOCK_SECONDS = 1800;
    public const RENEW_AFTER_SECONDS = 300;

    /** The lane state outlives a killed process by at most this long. */
    public const STATE_SECONDS = 900;

    /** A failed Suwayomi source lookup is not repeated for this long. */
    public const SOURCE_REF_RETRY_SECONDS = 1800;

    /** Set once a Suwayomi lookup failed in this process: the server is not asked again. */
    private bool $suwayomiDown = false;

    // ---- Lanes ---------------------------------------------------------------

    /**
     * The lane of a novel. $chapter is the chapter fetched next (the lane is
     * its resolved URL's host, else the group URL's — ChapterScraper's host
     * rule). With $backfill, a Suwayomi novel whose source is not known yet
     * is looked up once (one short request, saved quietly); without it, or
     * when that fails, the novel stays on its host's lane. Never throws.
     */
    public function laneFor(Novel $novel, $chapter = null, bool $backfill = true): string
    {
        $host = self::hostOf($chapter, $novel);

        try {
            $source = SourceResolver::for($novel);
            $lane = $source->downloadLane($novel);
            if ($lane === null && $backfill && $source instanceof SuwayomiSource && blank($novel->source_ref)) {
                $lane = $this->backfillSuwayomi($novel, $source);
            }
        } catch (\Throwable $e) {
            Log::warning("Download lane of {$novel->name} (ID {$novel->id}) fell back to its host: " . $e->getMessage());
            $lane = null;
        }

        return $lane ?? $host ?? self::NO_HOST;
    }

    /**
     * Host of a chapter's resolved source URL (the novel's group URL without
     * a chapter), lower-cased; null when there is none.
     */
    public static function hostOf($chapter, $novel): ?string
    {
        $url = $chapter ? (string) chapterSourceUrl($chapter) : (string) ($novel->group->url ?? "");
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== "" ? strtolower($host) : null;
    }

    /** Ask Suwayomi which source a series is on; save it. Null when it could not say. */
    private function backfillSuwayomi(Novel $novel, SuwayomiSource $source): ?string
    {
        $missKey = "download-lane:source-ref-miss:{$novel->id}";
        if ($this->suwayomiDown || Cache::has($missKey)) {
            return null;
        }

        $ref = $source->fetchSourceRef($novel);
        if ($ref === null) {
            $this->suwayomiDown = true;
            Cache::put($missKey, true, self::SOURCE_REF_RETRY_SECONDS);
            Log::info("Could not look up the Suwayomi source of {$novel->name} (ID {$novel->id}); it downloads on the server's lane for now.");
            return null;
        }

        $novel->forceFill(['source_ref' => $ref['id']])->saveQuietly();

        return $source->downloadLane($novel);
    }

    /**
     * What a lane is called on screen and in logs: the host for a host lane
     * ("novelfull.com"), "Suwayomi · MangaPill" for a Suwayomi source (from
     * the cached source list only, else "Suwayomi · #id"), and
     * "Suwayomi (host:port)" for a Suwayomi novel whose source is not known
     * yet. Never makes a request.
     */
    public static function label(string $lane): string
    {
        if ($lane === self::NO_HOST) {
            return 'No source host';
        }

        if (str_starts_with($lane, SuwayomiSource::LANE_PREFIX)) {
            $id = substr($lane, strlen(SuwayomiSource::LANE_PREFIX));

            return 'Suwayomi · ' . (SuwayomiSource::cachedSourceName($id) ?? "#{$id}");
        }

        $base = SuwayomiSource::baseUrl();
        if ($base !== '' && strtolower((string) parse_url($base, PHP_URL_HOST)) === $lane) {
            return 'Suwayomi (' . (new SuwayomiSource())->discoverHost() . ')';
        }

        return $lane;
    }

    // ---- Lock and state ------------------------------------------------------

    /** The lane's one-download-at-a-time lock (take with a non-blocking get()). */
    public static function lock(string $lane): Lock
    {
        return Cache::lock("download-lane:{$lane}", self::LOCK_SECONDS);
    }

    /** Is a download running on the lane right now? (Takes and frees the lock.) */
    public static function busy(string $lane): bool
    {
        $lock = self::lock($lane);
        if ($lock->get()) {
            $lock->release();
            return false;
        }

        return true;
    }

    public static function stateKey(string $lane): string
    {
        return "download-lane-state:{$lane}";
    }

    /**
     * Record what a lane is doing: {lane, novel_id, novel_name, chapter_id,
     * chapter_label, mode: sweep|manual, started_at, updated_at,
     * done_this_run}. Written once per chapter by the lane's holder.
     */
    public static function putState(string $lane, array $state): void
    {
        try {
            Cache::put(self::stateKey($lane), ['lane' => $lane, 'updated_at' => now()->toIso8601String()] + $state, self::STATE_SECONDS);
        } catch (\Throwable $e) {
            // The Activity page is a view; a failed write never stops a download.
        }
    }

    public static function state(string $lane): ?array
    {
        $state = Cache::get(self::stateKey($lane));

        return is_array($state) ? $state : null;
    }

    public static function clearState(string $lane): void
    {
        try {
            Cache::forget(self::stateKey($lane));
        } catch (\Throwable $e) {
            // Expires by itself.
        }
    }

    /** Remember when the dispatcher last started a lane (for fair ordering). */
    public static function markStarted(string $lane): void
    {
        Cache::put("download-lane-started:{$lane}", now()->getTimestamp(), now()->addDays(7));
    }

    /**
     * $lanes ordered by how long they have waited: never started first,
     * then the longest since their last start; ties keep the given order.
     *
     * @param  string[]  $lanes
     * @return string[]
     */
    public static function longestWaitingFirst(array $lanes): array
    {
        $position = array_flip($lanes);
        $started = [];
        foreach ($lanes as $lane) {
            $at = Cache::get("download-lane-started:{$lane}");
            $started[$lane] = is_numeric($at) ? (int) $at : 0;
        }
        usort($lanes, fn($a, $b) => [$started[$a], $position[$a]] <=> [$started[$b], $position[$b]]);

        return $lanes;
    }

    // ---- Activity page -------------------------------------------------------

    /**
     * The Activity page's "Downloads by source": one row per lane that is
     * downloading or has pending chapters, downloading first, then by
     * chapters queued. Counts come from one grouped query over the pending
     * chapters of the novels the sweep works (active, not paused — a paused
     * novel only shows while an explicit run downloads it); lanes are
     * worked out in PHP from one representative chapter per novel, without
     * any request (a Suwayomi novel whose source is not known yet shows on
     * the server's lane).
     *
     * @return array{rows: list<array>, summary: array{downloading: int, queued: int, sources: int}}
     */
    public function overview(): array
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');

        // Per novel: due / not-yet-due pending chapters and one chapter id
        // to resolve the host from.
        $counts = NovelChapter::query()
            ->join('novels', 'novels.id', '=', 'novel_chapters.novel_id')
            ->where('novels.status', 0)
            ->where('novels.group_id', '!=', 37)
            ->whereNull('novels.deleted_at')
            ->where('novel_chapters.status', 0)
            ->where('novel_chapters.blacklist', 0)
            ->groupBy('novel_chapters.novel_id')
            ->selectRaw(
                'novel_chapters.novel_id as novel_id, MIN(novel_chapters.id) as sample_id, '
                . 'SUM(CASE WHEN novel_chapters.next_attempt_at IS NULL OR novel_chapters.next_attempt_at <= ? THEN 1 ELSE 0 END) as due, '
                . 'COUNT(*) as pending, '
                . 'MAX(CASE WHEN novels.paused_at IS NULL THEN 0 ELSE 1 END) as paused',
                [$now]
            )
            ->toBase()
            ->get();

        $novels = $counts->isEmpty() ? collect() : Novel::with('group:id,url')
            ->whereIn('id', $counts->pluck('novel_id')->all())
            ->get(['id', 'name', 'translator_url', 'alternative_url', 'source_ref', 'group_id', 'medium'])
            ->keyBy('id');
        $samples = $counts->isEmpty() ? collect() : NovelChapter::whereIn('id', $counts->pluck('sample_id')->all())
            ->get(['id', 'novel_id', 'url', 'chapter'])
            ->keyBy('novel_id');

        $lanes = [];
        foreach ($counts as $row) {
            $novel = $novels->get((int) $row->novel_id);
            if (!$novel) {
                continue;
            }
            $sample = $samples->get((int) $row->novel_id);
            $sample?->setRelation('novel', $novel);
            $lane = $this->laneFor($novel, $sample, false);

            $entry = $lanes[$lane] ??= ['queued' => 0, 'retrying' => 0, 'series' => 0, 'media' => []];
            if ((int) $row->paused === 0) {
                $entry['queued'] += (int) $row->due;
                $entry['retrying'] += (int) $row->pending - (int) $row->due;
                $entry['series']++;
                $entry['media'][$novel->isManga() ? 'manga' : 'novel'] = true;
            }
            $lanes[$lane] = $entry;
        }

        $rows = [];
        foreach ($lanes as $lane => $entry) {
            $lane = (string) $lane;
            $state = self::state($lane);
            if ($state === null && $entry['queued'] === 0 && $entry['retrying'] === 0) {
                continue; // only paused novels, and nothing running
            }
            $rows[] = $this->row($lane, $entry, $state);
        }

        usort($rows, fn($a, $b) => [$a['state'] === 'downloading' ? 0 : 1, -$a['queued'], -$a['retrying'], $a['label']]
            <=> [$b['state'] === 'downloading' ? 0 : 1, -$b['queued'], -$b['retrying'], $b['label']]);

        return [
            'rows' => $rows,
            'summary' => [
                'downloading' => count(array_filter($rows, fn($r) => $r['state'] === 'downloading')),
                'queued' => array_sum(array_column($rows, 'queued')),
                'sources' => count(array_filter($rows, fn($r) => $r['queued'] > 0)),
            ],
        ];
    }

    /** One Activity row. */
    private function row(string $lane, array $entry, ?array $state): array
    {
        $media = array_keys($entry['media']);
        $row = [
            'lane' => $lane,
            'label' => self::label($lane),
            'medium' => count($media) === 1 ? $media[0] : null,
            'state' => 'waiting',
            'novel_id' => null,
            'novel_name' => null,
            'chapter_label' => null,
            'mode' => null,
            'since' => null,
            'cooldown_until' => null,
            'done_this_run' => 0,
            'queued' => $entry['queued'],
            'retrying' => $entry['retrying'],
            'series' => $entry['series'],
        ];

        if ($state !== null) {
            $started = isset($state['started_at']) ? Carbon::parse($state['started_at']) : null;

            return array_merge($row, [
                'state' => 'downloading',
                'novel_id' => isset($state['novel_id']) ? (int) $state['novel_id'] : null,
                'novel_name' => isset($state['novel_name']) ? (string) $state['novel_name'] : null,
                'chapter_label' => isset($state['chapter_label']) ? (string) $state['chapter_label'] : null,
                'mode' => $state['mode'] ?? null,
                'since' => $started?->toIso8601String(),
                'done_this_run' => (int) ($state['done_this_run'] ?? 0),
            ]);
        }

        $cooldown = $lane === self::NO_HOST ? null : Cache::get(ChapterScraper::hostCooldownKey($lane));
        if ($cooldown) {
            // Stored as the expiry's Unix time (older entries: just true).
            $row['state'] = 'cooling';
            $row['cooldown_until'] = is_int($cooldown) && $cooldown > 1_000_000_000
                ? Carbon::createFromTimestamp($cooldown)->setTimezone(config('app.timezone'))->format('H:i')
                : null;
        }

        return $row;
    }
}
