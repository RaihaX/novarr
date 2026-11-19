<?php

namespace App\Services;

use App\ChapterRead;
use App\Http\Helpers\CacheHelper;
use App\NovelChapter;
use Illuminate\Support\Facades\DB;

/**
 * Where "Continue" / "Start" opens a series for a reader — the one rule every
 * surface uses (the web Home, the novel page, /continue, the summary email,
 * and through the API's resume_chapter_id the Android and iOS apps and the
 * KOReader plugin), so every device picks the same chapter.
 *
 * Over the series' downloaded, non-blacklisted, not soft-deleted chapters in
 * reading order (NovelChapter::scopeOrdered()):
 *  1. The anchor is the chapter with the reader's most recent activity: the
 *     later of read_at and progress_at on their chapter_reads row (ties go to
 *     the furthest in reading order). Only real reading is activity: a row
 *     counts when the chapter is read (opened in the web reader, finished in
 *     an app), or unread with a position of at least 1 % (an app or the manga
 *     reader part-way through). A read mark set by hand — a single mark read,
 *     "Mark to here", "Mark all read", a bulk read — or by a look-up (a
 *     chapter opened from Highlights or search) is not activity
 *     (chapter_reads.manual) until the reader reads in that chapter; nor is a
 *     row left by "mark unread" or a chapter opened at 0 %.
 *  2. An unfinished anchor is resumed (RESUME): unread with progress 1–99
 *     (the apps, the Kindle and the manga reader mark read only at the end),
 *     or read with progress below 90 (the web reader marks read on open, then
 *     the position follows the scroll). Read with no position is finished.
 *  3. Otherwise the first unread chapter after the anchor (NEXT).
 *  4. No activity at all: the first unread chapter (START), so chapters
 *     marked read by hand are skipped.
 *  5. Nothing unread after a finished anchor: CAUGHT_UP (the API answers the
 *     last chapter; the web's Continue list leaves the series out).
 * Older half-read chapters and earlier skipped chapters never pull back.
 */
class ResumePoint
{
    public const RESUME = 'resume';
    public const NEXT = 'next';
    public const START = 'start';
    public const CAUGHT_UP = 'caught_up';

    /** Read chapters below this position are still being read (the web marks read on open). */
    public const READ_UNFINISHED_BELOW = 90;

    /** Chapter columns the anchor query always selects: reading order (relativeTo()) and the key. */
    private const ANCHOR_COLUMNS = ['id', 'novel_id', 'book', 'sort_key', 'chapter'];

    public function __construct(private ReadState $readState)
    {
    }

    /**
     * SQL for a chapter_reads row's activity time (NULL = not activity), on
     * the row aliased $cr: a manual row (marked by hand or by a look-up) is
     * none. MariaDB's GREATEST() is NULL when either side is, so the later
     * of the two is spelled out.
     *
     * $withManual counts manual rows too: "has the reader touched this
     * series, and when" (which series Home's Continue shelf lists and in
     * what order), not where to resume inside it.
     */
    public static function activitySql(string $cr = 'cr', bool $withManual = false): string
    {
        return 'CASE' . ($withManual ? '' : " WHEN {$cr}.manual = 1 THEN NULL")
            . " WHEN {$cr}.read_at IS NULL AND ({$cr}.progress IS NULL OR {$cr}.progress < 1) THEN NULL"
            . " WHEN {$cr}.progress_at IS NULL OR ({$cr}.read_at IS NOT NULL AND {$cr}.read_at > {$cr}.progress_at) THEN {$cr}.read_at"
            . " ELSE {$cr}.progress_at END";
    }

    /** SQL: is the row aliased $cr unfinished (rule 2)? */
    public static function unfinishedSql(string $cr = 'cr'): string
    {
        return "(({$cr}.read_at IS NULL AND {$cr}.progress >= 1 AND {$cr}.progress <= 99)"
            . " OR ({$cr}.read_at IS NOT NULL AND {$cr}.progress < " . self::READ_UNFINISHED_BELOW . '))';
    }

    /** Rule 2 for one row (the PHP twin of unfinishedSql()). */
    public static function isUnfinished(?ChapterRead $state): bool
    {
        if ($state === null || $state->progress === null) {
            return false;
        }
        $progress = (int) $state->progress;

        return $state->read_at === null
            ? $progress >= 1 && $progress <= 99
            : $progress < self::READ_UNFINISHED_BELOW;
    }

    /**
     * Every series' resume chapter for a reader (default: the current one),
     * keyed by novel id — the API's resume_chapter_id: caught up = the last
     * downloaded chapter; series with nothing downloaded are absent. Cached
     * per reader (CacheHelper::apiResumeKey(), forgotten by every read-state
     * write that can move it).
     *
     * @return array<int, int>
     */
    public function libraryChapterIds(?int $userId = null): array
    {
        $userId = $this->readState->userId($userId);

        return CacheHelper::rememberForReader(CacheHelper::apiResumeKey($userId), CacheHelper::epoch(), 60, fn() => array_map(
            fn(array $point) => $point['id'],
            $this->libraryPoints($userId)
        ));
    }

    /**
     * Uncached: every series' resume point, keyed by novel id, as
     * ['id' => chapter id, 'kind' => RESUME|NEXT|START|CAUGHT_UP].
     *
     * One statement for the whole library: each downloaded chapter joined to
     * the reader's chapter_reads row by primary key, numbered in reading
     * order (ROW_NUMBER over exactly scopeOrdered()'s keys; NULL sort_keys
     * first on SQLite and MariaDB alike), the anchor found with FIRST_VALUE
     * over activity time, then one row picked per series.
     *
     * @return array<int, array{id: int, kind: string}>
     */
    public function libraryPoints(?int $userId = null): array
    {
        $userId = $this->readState->userId($userId);
        $activity = self::activitySql('cr');
        $unfinished = self::unfinishedSql('cr');

        $ranked = DB::table('novel_chapters as c')
            ->leftJoin(ReadState::TABLE . ' as cr', function ($j) use ($userId) {
                $j->on('cr.novel_chapter_id', '=', 'c.id')->where('cr.user_id', '=', $userId);
            })
            ->where('c.status', 1)
            ->where('c.blacklist', 0)
            ->whereNull('c.deleted_at')
            ->selectRaw("c.id as id, c.novel_id as novel_id, cr.read_at as read_at,
                {$activity} as act,
                CASE WHEN {$unfinished} THEN 1 ELSE 0 END as unfinished,
                ROW_NUMBER() OVER (PARTITION BY c.novel_id ORDER BY c.book, c.sort_key, c.chapter, c.id) as pos");

        // The anchor: latest activity first, ties to the furthest chapter.
        $window = 'OVER (PARTITION BY novel_id ORDER BY CASE WHEN act IS NULL THEN 1 ELSE 0 END, act DESC, pos DESC)';
        $anchored = DB::query()->fromSub($ranked, 'r')
            ->selectRaw("id, novel_id, read_at, pos,
                FIRST_VALUE(CASE WHEN act IS NOT NULL THEN pos END) {$window} as anchor,
                FIRST_VALUE(CASE WHEN act IS NOT NULL THEN unfinished END) {$window} as anchor_unfinished");

        // 0 = the unfinished anchor itself; 1 = unread after the anchor (or
        // any chapter when there is none); 2 = anything else (caught up: the
        // last of them).
        $prio = 'CASE WHEN pos = anchor AND anchor_unfinished = 1 THEN 0
            WHEN read_at IS NULL AND (anchor IS NULL OR pos > anchor) THEN 1
            ELSE 2 END';

        $picked = DB::query()->fromSub($anchored, 'a')
            ->selectRaw("id, novel_id, anchor, {$prio} as prio,
                ROW_NUMBER() OVER (
                    PARTITION BY novel_id
                    ORDER BY {$prio}, CASE WHEN {$prio} = 1 THEN pos ELSE 0 END, pos DESC
                ) as rn");

        $out = [];
        foreach (DB::query()->fromSub($picked, 'p')->where('rn', 1)->get() as $row) {
            $kind = match ((int) $row->prio) {
                0 => self::RESUME,
                1 => $row->anchor === null ? self::START : self::NEXT,
                default => self::CAUGHT_UP,
            };
            $out[(int) $row->novel_id] = ['id' => (int) $row->id, 'kind' => $kind];
        }

        return $out;
    }

    /**
     * The resume point of each of $novelIds (a handful: Home's Continue
     * shelf, the novel page, the summary email), keyed by novel id, as
     * ['chapter' => NovelChapter|null, 'kind' => RESUME|NEXT|START|CAUGHT_UP].
     * A RESUME chapter carries the reader's state (NovelChapter::readState()),
     * so its position can be shown. CAUGHT_UP has no chapter unless
     * $lastWhenCaughtUp. Series with nothing downloaded are absent.
     *
     * Index-driven instead of the library-wide window statement:
     *  1. the anchors — one UNION ALL member per series (NovelHealth::
     *     firstPerNovel()) over the reader's chapter_reads rows of that series
     *     (user_id, novel_id range), each chapter by primary key;
     *  2. the targets — one member per series walking idx_reading_order from
     *     the anchor or from the start (NOT EXISTS probe on chapter_reads'
     *     primary key: the first unread); skipped when every anchor is
     *     unfinished;
     *  3. only with $lastWhenCaughtUp and a caught-up series: its last chapter;
     *  4. only for a series with no activity and nothing unread (every
     *     chapter marked read by hand): its last chapter, telling caught up
     *     from nothing downloaded — and with $startFilter, the first unread
     *     without it, telling caught up from a series the filter emptied.
     *
     * @param  int[]  $novelIds
     * @param  string[]  $columns  novel_chapters columns the caller reads
     * @param  \Closure|null  $startFilter  extra constraint for a START
     *         member (fn($query, int $novelId)); a series it leaves empty is
     *         absent from the result, for the caller's own fallback
     * @return array<int, array{chapter: NovelChapter|null, kind: string}>
     */
    public function forNovels(array $novelIds, array $columns, ?int $userId = null, bool $lastWhenCaughtUp = false, ?\Closure $startFilter = null): array
    {
        $novelIds = array_values(array_unique(array_map('intval', $novelIds)));
        if ($novelIds === []) {
            return [];
        }
        $userId = $this->readState->userId($userId);
        $columns = array_values(array_unique(array_merge(self::ANCHOR_COLUMNS, $columns)));
        $activity = self::activitySql('cr');

        $anchors = NovelHealth::firstPerNovel($novelIds, function ($q, int $novelId) use ($columns, $userId, $activity) {
            $this->readState->join($q->where('status', 1)->where('blacklist', 0), $columns, $userId, 'inner')
                ->where('cr.novel_id', $novelId)
                ->whereRaw("({$activity}) IS NOT NULL")
                ->orderByRaw("{$activity} DESC")
                ->orderByDesc('novel_chapters.book')
                ->orderByDesc('novel_chapters.sort_key')
                ->orderByDesc('novel_chapters.chapter')
                ->orderByDesc('novel_chapters.id');
        });
        $this->readState->attachJoined($anchors);

        $out = [];
        $pending = [];
        foreach ($novelIds as $novelId) {
            $anchor = $anchors->get($novelId);
            if ($anchor !== null && self::isUnfinished($anchor->readState())) {
                $out[$novelId] = ['chapter' => $anchor, 'kind' => self::RESUME];
            } else {
                $pending[] = $novelId;
            }
        }

        $targets = NovelHealth::firstPerNovel($pending, function ($q, int $novelId) use ($anchors, $columns, $userId, $startFilter) {
            $q->where('status', 1)->where('blacklist', 0)->select($columns);
            // No anchor: the first unread (chapters marked read by hand are
            // not activity, but they are read).
            $this->readState->whereUnread($q, $userId);
            if ($anchor = $anchors->get($novelId)) {
                $q->relativeTo($anchor, '>');
            } elseif ($startFilter !== null) {
                $startFilter($q, $novelId);
            }
            // Exactly NovelChapter::scopeOrdered().
            $q->ordered();
        });

        $caughtUp = [];
        $unanchored = [];
        foreach ($pending as $novelId) {
            $anchor = $anchors->get($novelId);
            if ($chapter = $targets->get($novelId)) {
                $out[$novelId] = ['chapter' => $chapter, 'kind' => $anchor ? self::NEXT : self::START];
            } elseif ($anchor !== null) {
                $out[$novelId] = ['chapter' => null, 'kind' => self::CAUGHT_UP];
                $caughtUp[] = $novelId;
            } else {
                $unanchored[] = $novelId;
            }
        }

        // No activity and no (filtered) unread chapter: every chapter marked
        // read by hand (caught up), or nothing downloaded / $startFilter left
        // it empty (absent).
        if ($unanchored !== []) {
            $last = NovelHealth::firstPerNovel($unanchored, fn($q) => $q->where('status', 1)->where('blacklist', 0)
                ->select($columns)
                ->orderedDesc());
            $unread = $startFilter === null || $last->isEmpty() ? collect() : NovelHealth::firstPerNovel(
                $last->keys()->all(),
                fn($q) => $this->readState->whereUnread($q->where('status', 1)->where('blacklist', 0)->select(['id', 'novel_id']), $userId)
            );
            foreach ($unanchored as $novelId) {
                if ($last->has($novelId) && !$unread->has($novelId)) {
                    $out[$novelId] = ['chapter' => $lastWhenCaughtUp ? $last->get($novelId) : null, 'kind' => self::CAUGHT_UP];
                }
            }
        }

        if ($lastWhenCaughtUp && $caughtUp !== []) {
            $last = NovelHealth::firstPerNovel($caughtUp, fn($q) => $q->where('status', 1)->where('blacklist', 0)
                ->select($columns)
                ->orderedDesc());
            foreach ($caughtUp as $novelId) {
                $out[$novelId]['chapter'] = $last->get($novelId);
            }
        }

        // In the order asked for.
        return array_replace(array_intersect_key(array_flip($novelIds), $out), $out);
    }

    /** One series' resume point (forNovels() for one id), or null when nothing is downloaded. */
    public function forNovel(int $novelId, array $columns, ?int $userId = null, bool $lastWhenCaughtUp = false): ?array
    {
        return $this->forNovels([$novelId], $columns, $userId, $lastWhenCaughtUp)[$novelId] ?? null;
    }
}
