<?php

namespace App\Services;

use App\ApiToken;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\NovelChapter;
use App\ReadingPosition;
use App\Support\CurrentReader;
use App\Support\DeviceDescription;
use App\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What every account reads, for the admin's Readers pages (Tautulli-style):
 * who is reading right now, each reader's figures, what is popular, the
 * reading history (collapsed into sessions) and one reader's devices.
 *
 * Everything is derived from existing tables, no schema of its own:
 *  - "activity" = chapter_reads rows with read_at set and bulk = false, on
 *    chapters that are not soft-deleted — the same rule as /stats
 *    (App\Services\ReadingStats). All-time totals include bulk rows;
 *  - "reading now" = a reading_positions row written in the last
 *    NOW_MINUTES (a little in the future counts too: clocks drift), whose
 *    chapter still exists; one per reader, their newest;
 *  - a "session" = a reader's consecutive reads of one series on one device
 *    with gaps of at most SESSION_GAP_MINUTES (sessions());
 *  - where a read came from: chapter_reads.ip / device / client / public,
 *    stamped when the chapter is marked read (App\Support\ReadingClient;
 *    empty on reads from before they were recorded), and the same on the
 *    reading position (device_name for the device). The address is cleared
 *    after novarr.auth_log_days by novarr:prune-auth-log.
 *
 * Every chapter_reads query is constrained by user_id (whereIn over the
 * accounts) plus a read_at range or IS NOT NULL, so the (user_id, read_at)
 * index serves it; the dashboard is a fixed number of grouped queries
 * whatever the number of readers. Days use the app timezone and DATE(),
 * as /stats does (portable: sqlite and MariaDB).
 *
 * Highlights (bookmarks) are private to each reader and never read here.
 * Stateless (no current reader, no request): Octane-safe.
 */
class ReaderActivity
{
    /** A position this recent (minutes) means the reader is reading now. */
    public const NOW_MINUTES = 10;

    /** Reads of one series further apart than this (minutes) are separate sessions. */
    public const SESSION_GAP_MINUTES = 30;

    /** The dashboard's aggregates are cached this long (seconds). */
    public const CACHE_SECONDS = 60;

    /** Days in the dashboard's window (chart, 30-day figures, most popular). */
    public const WINDOW_DAYS = 30;

    /** Days of reads looked at for streaks (a longer streak counts as this long). */
    public const STREAK_DAYS = 400;

    public const POPULAR_LIMIT = 10;

    /** Recent activity: this many sessions, collapsed from at most RECENT_ROWS reads. */
    public const RECENT_SESSIONS = 15;

    public const RECENT_ROWS = 300;

    public const HISTORY_PER_PAGE = 100;

    /** Colours of the chart's series (CSS classes reader-series-1…N), cycled. */
    public const SERIES_COLOURS = 6;

    /** Columns of a history row (historyQuery()), what sessions() reads. */
    private const ROW_COLUMNS = [
        'cr.user_id', 'cr.novel_id', 'cr.novel_chapter_id as chapter_id', 'cr.read_at', 'cr.bulk', 'cr.manual',
        'c.chapter', 'c.book', 'c.number', 'c.part', 'c.label', 'n.name as novel_name',
        'cr.ip', 'cr.device', 'cr.client', 'cr.public',
    ];

    /**
     * Every account: the owner first, then by name (and id). Disabled
     * accounts included (the views mark them "Turned off").
     *
     * @return Collection<int, User>
     */
    public function accounts(): Collection
    {
        return User::query()
            ->orderByRaw('id = ? DESC', [CurrentReader::ownerId()])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** CSS series class number (1…SERIES_COLOURS) of the account at $index in accounts(). */
    public static function seriesClass(int $index): string
    {
        return 'reader-series-' . (($index % self::SERIES_COLOURS) + 1);
    }

    // ---------------------------------------------------------------------
    // Reading now
    // ---------------------------------------------------------------------

    /**
     * Who is reading right now: at most one entry per reader (their newest
     * position), newest first. Each entry:
     *   user_id, user (User|null when $users is given), novel (Novel, cover
     *   relation loaded), chapter (NovelChapter), number (display chapter
     *   number, '' when none), progress (?int 0–100), device_name (?string),
     *   client (?string), ip (?string), public (bool), at (Carbon), minutes
     *   (int, never negative).
     * Three queries (positions, novels, chapters).
     *
     * @param  int[]|null  $userIds  limit to these readers (null = everyone)
     * @return Collection<int, object>
     */
    public function readingNow(?array $userIds = null): Collection
    {
        if ($userIds === []) {
            return collect();
        }
        $since = now()->subMinutes(self::NOW_MINUTES);

        $positions = ReadingPosition::query()
            ->join('novel_chapters as c', 'c.id', '=', 'reading_positions.novel_chapter_id')
            ->whereColumn('c.novel_id', 'reading_positions.novel_id')
            ->whereNull('c.deleted_at')
            ->join('novels as n', 'n.id', '=', 'reading_positions.novel_id')
            ->whereNull('n.deleted_at')
            ->when($userIds !== null, fn($q) => $q->whereIn('reading_positions.user_id', $userIds))
            ->where('reading_positions.recorded_at', '>=', $since)
            ->orderByDesc('reading_positions.recorded_at')
            ->orderByDesc('reading_positions.id')
            ->get(['reading_positions.*'])
            ->unique('user_id')
            ->values();
        if ($positions->isEmpty()) {
            return collect();
        }

        $novels = Novel::with('file')->whereIn('id', $positions->pluck('novel_id')->unique())->get()->keyBy('id');
        $chapters = NovelChapter::whereIn('id', $positions->pluck('novel_chapter_id')->unique())
            ->get(['id', 'novel_id', 'chapter', 'book', 'number', 'part', 'label'])
            ->keyBy('id');

        return $positions->map(function (ReadingPosition $p) use ($novels, $chapters) {
            $novel = $novels->get($p->novel_id);
            $chapter = $chapters->get($p->novel_chapter_id);
            if ($novel === null || $chapter === null) {
                return null;
            }
            $at = $p->recorded_at;

            return (object) [
                'user_id' => (int) $p->user_id,
                'novel' => $novel,
                'chapter' => $chapter,
                'number' => $chapter->displayNumber(),
                'progress' => $p->progress === null ? null : (int) $p->progress,
                'device_name' => $p->device_name,
                'client' => $p->client,
                'ip' => $p->ip,
                'public' => (bool) $p->public,
                'at' => $at,
                // A position written slightly in the future is "now".
                'minutes' => max(0, (int) floor($at->diffInSeconds(now(), false) / 60)),
            ];
        })->filter()->values();
    }

    // ---------------------------------------------------------------------
    // Dashboard aggregates
    // ---------------------------------------------------------------------

    /**
     * The dashboard's figures for $userIds (default: every account),
     * cached CACHE_SECONDS under CacheHelper::readerActivityKey() when for
     * everyone. Plain arrays only (cache-safe):
     *
     *   window_days  int
     *   tiles        {active_today, accounts, today, week, month}
     *   daily        list of {date 'Y-m-d', label 'j M', total, by: [user_id => chapters]} oldest first
     *   readers      [user_id => {today, week, month, total, streak, last: ?{novel_id, novel_name, chapter_id, number, label, at 'Y-m-d H:i:s', device ?string}}]
     *   popular      list of {novel_id, name, readers, chapters, last_read 'Y-m-d H:i:s'} (top POPULAR_LIMIT)
     *
     * Five grouped queries whatever the number of readers.
     *
     * @param  int[]|null  $userIds
     */
    public function overview(?array $userIds = null): array
    {
        if ($userIds !== null) {
            return $this->buildOverview(array_values(array_map('intval', $userIds)));
        }

        return Cache::remember(CacheHelper::readerActivityKey(), self::CACHE_SECONDS, fn() => $this->buildOverview(
            User::query()->pluck('id')->map(fn($id) => (int) $id)->all()
        ));
    }

    private function buildOverview(array $ids): array
    {
        $days = self::WINDOW_DAYS;
        $today = now()->toDateString();
        $weekFrom = now()->subDays(6)->toDateString();
        $monthFrom = now()->subDays($days - 1)->toDateString();

        $readers = [];
        foreach ($ids as $id) {
            $readers[$id] = ['today' => 0, 'week' => 0, 'month' => 0, 'total' => 0, 'streak' => 0, 'last' => null];
        }

        // 1. Activity per reader per day over the streak window — feeds the
        // day / week / month figures, the chart and the streaks at once.
        $perDay = $ids === [] ? collect() : $this->activityRows($ids, now()->subDays(self::STREAK_DAYS - 1)->startOfDay())
            ->selectRaw('cr.user_id, DATE(cr.read_at) as d, COUNT(*) as chapters')
            ->groupBy('cr.user_id', 'd')
            ->get();

        $dates = [];   // user_id => [Y-m-d => true]
        $byDay = [];   // Y-m-d => [user_id => chapters]
        foreach ($perDay as $row) {
            $uid = (int) $row->user_id;
            $d = (string) $row->d;
            $n = (int) $row->chapters;
            if (!isset($readers[$uid])) {
                continue;
            }
            $dates[$uid][$d] = true;
            // ">= day" like /stats: a read stamped a little in the future is today's.
            if ($d >= $today) {
                $readers[$uid]['today'] += $n;
            }
            if ($d >= $weekFrom) {
                $readers[$uid]['week'] += $n;
            }
            if ($d >= $monthFrom) {
                $readers[$uid]['month'] += $n;
                $key = $d > $today ? $today : $d;
                $byDay[$key][$uid] = ($byDay[$key][$uid] ?? 0) + $n;
            }
        }

        // Streak: consecutive days ending today (or yesterday, while today
        // is still untouched) with at least one read — as /stats counts it.
        foreach ($dates as $uid => $set) {
            $cursor = now()->startOfDay();
            if (!isset($set[$cursor->toDateString()])) {
                $cursor->subDay();
            }
            $streak = 0;
            while (isset($set[$cursor->toDateString()])) {
                $streak++;
                $cursor->subDay();
            }
            $readers[$uid]['streak'] = $streak;
        }

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $by = $byDay[$day->toDateString()] ?? [];
            $daily[] = ['date' => $day->toDateString(), 'label' => $day->format('j M'), 'total' => array_sum($by), 'by' => $by];
        }

        if ($ids !== []) {
            // 2. All-time chapters per reader (bulk marks included).
            $totals = $this->readRows($ids)
                ->selectRaw('cr.user_id, COUNT(*) as chapters')
                ->groupBy('cr.user_id')
                ->pluck('chapters', 'user_id');
            foreach ($totals as $uid => $n) {
                if (isset($readers[(int) $uid])) {
                    $readers[(int) $uid]['total'] = (int) $n;
                }
            }

            // 3 + 4. Each reader's newest activity read (the newest time per
            // reader, then the row(s) at that time; ties → lowest chapter id).
            // Series that were deleted are left out, as in the history.
            $newest = $this->activityRows($ids)
                ->join('novels as n', 'n.id', '=', 'cr.novel_id')
                ->whereNull('n.deleted_at')
                ->selectRaw('cr.user_id, MAX(cr.read_at) as last_at')
                ->groupBy('cr.user_id');
            $lastRows = $this->activityRows($ids)
                ->joinSub($newest, 'l', fn($j) => $j->on('l.user_id', '=', 'cr.user_id')->on('l.last_at', '=', 'cr.read_at'))
                ->join('novels as n', 'n.id', '=', 'cr.novel_id')
                ->whereNull('n.deleted_at')
                ->orderBy('c.id')
                ->get(['cr.user_id', 'cr.novel_id', 'cr.read_at', 'c.id as chapter_id', 'c.chapter', 'c.book', 'c.number', 'c.part', 'c.label', 'n.name as novel_name', 'cr.device', 'cr.client']);
            foreach ($lastRows as $row) {
                $uid = (int) $row->user_id;
                if (!isset($readers[$uid]) || $readers[$uid]['last'] !== null) {
                    continue;
                }
                $readers[$uid]['last'] = [
                    'novel_id' => (int) $row->novel_id,
                    'novel_name' => (string) $row->novel_name,
                    'chapter_id' => (int) $row->chapter_id,
                    'number' => self::chapterNumber($row),
                    'label' => (string) $row->label,
                    'at' => Carbon::parse($row->read_at)->format('Y-m-d H:i:s'),
                    'device' => self::deviceLabel($row),
                ];
            }
        }

        // 5. Most popular in the window: distinct readers, then chapters.
        $popular = $ids === [] ? collect() : $this->activityRows($ids, now()->subDays($days - 1)->startOfDay())
            ->join('novels as n', 'n.id', '=', 'cr.novel_id')
            ->whereNull('n.deleted_at')
            ->groupBy('cr.novel_id', 'n.name')
            ->selectRaw('cr.novel_id, n.name, COUNT(DISTINCT cr.user_id) as readers, COUNT(*) as chapters, MAX(cr.read_at) as last_read')
            ->orderByDesc('readers')
            ->orderByDesc('chapters')
            ->orderBy('n.name')
            ->limit(self::POPULAR_LIMIT)
            ->get();

        $sum = fn(string $key) => array_sum(array_column($readers, $key));

        return [
            'window_days' => $days,
            'tiles' => [
                'active_today' => count(array_filter($readers, fn($r) => $r['today'] > 0)),
                'accounts' => count($readers),
                'today' => $sum('today'),
                'week' => $sum('week'),
                'month' => $sum('month'),
            ],
            'daily' => $daily,
            'readers' => $readers,
            'popular' => $popular->map(fn($r) => [
                'novel_id' => (int) $r->novel_id,
                'name' => (string) $r->name,
                'readers' => (int) $r->readers,
                'chapters' => (int) $r->chapters,
                'last_read' => Carbon::parse($r->last_read)->format('Y-m-d H:i:s'),
            ])->all(),
        ];
    }

    // ---------------------------------------------------------------------
    // History and sessions
    // ---------------------------------------------------------------------

    /**
     * The newest $limit sessions of $userIds (default: every account),
     * collapsed from their newest RECENT_ROWS activity reads.
     *
     * @param  int[]|null  $userIds
     * @return list<array>  see sessions()
     */
    public function recentSessions(?array $userIds = null, int $limit = self::RECENT_SESSIONS): array
    {
        $ids = $userIds ?? User::query()->pluck('id')->map(fn($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $rows = $this->historyQuery($ids, null, false)->limit(self::RECENT_ROWS)->get();

        return array_slice(self::sessions($rows), 0, $limit);
    }

    /**
     * One page of raw history rows (HISTORY_PER_PAGE, newest first), for
     * /readers/history; the caller collapses the page with sessions().
     * $bulk = true also includes chapters marked read in bulk; $ip keeps
     * the reads stamped with exactly that address.
     *
     * @param  int[]  $userIds
     */
    public function historyPage(array $userIds, ?int $novelId, bool $bulk, ?string $ip = null): Paginator
    {
        return $this->historyQuery($userIds, $novelId, $bulk)
            ->when($ip !== null, fn($q) => $q->where('cr.ip', $ip))
            ->simplePaginate(self::HISTORY_PER_PAGE)
            ->withQueryString();
    }

    /**
     * Read rows (read_at set, chapter not soft-deleted, series exists) of
     * $userIds, newest first, with what sessions() needs.
     *
     * @param  int[]  $userIds
     */
    private function historyQuery(array $userIds, ?int $novelId, bool $bulk): Builder
    {
        return ($bulk ? $this->readRows($userIds) : $this->activityRows($userIds))
            ->join('novels as n', 'n.id', '=', 'cr.novel_id')
            ->whereNull('n.deleted_at')
            ->when($novelId !== null, fn($q) => $q->where('cr.novel_id', $novelId))
            ->orderByDesc('cr.read_at')
            ->orderByDesc('cr.novel_chapter_id')
            ->select(self::ROW_COLUMNS);
    }

    /**
     * Collapse reads (newest first) into sessions — a pure function.
     *
     * A reader's consecutive reads of the same series whose read_at gaps
     * are at most SESSION_GAP_MINUTES are one session. "Consecutive" is
     * within that reader's own reads: another reader's reads in between
     * (two people reading at once) do not split it; the reader's read of
     * another series, a longer gap, or a change of how the rows were
     * marked (bulk / by hand) does, and so does a change of device (label
     * = device ?? client: the phone, then the Kindle, is two sessions).
     *
     * Each row (array or object): user_id, novel_id, chapter_id, read_at
     * (Carbon or string) and optionally novel_name, label, display_number
     * (else derived from the chapter / book / number / part columns), bulk,
     * manual, ip, device, client, public. Each session, newest first:
     *   user_id, novel_id, novel_name, chapters (count),
     *   first {id, number, label} (the earliest read), last {…} (the newest),
     *   started_at, ended_at (Carbon), bulk, manual (bool),
     *   label (device ?? client), device, client (?string, the newest
     *   read's), public (bool: any read on the public address), ip (the
     *   newest read's address that has one), ips (distinct addresses,
     *   newest first)
     *
     * @param  iterable<array|object>  $rows
     * @return list<array>
     */
    public static function sessions(iterable $rows): array
    {
        $sessions = [];
        $open = []; // user_id => index into $sessions of their newest-built (oldest so far) session
        $gap = self::SESSION_GAP_MINUTES * 60;

        foreach ($rows as $row) {
            $row = (object) $row;
            $uid = (int) $row->user_id;
            $at = $row->read_at instanceof CarbonInterface ? Carbon::instance($row->read_at) : Carbon::parse($row->read_at);
            $chapter = [
                'id' => (int) $row->chapter_id,
                'number' => isset($row->display_number) ? (string) $row->display_number : self::chapterNumber($row),
                'label' => (string) ($row->label ?? ''),
            ];
            $bulk = (bool) ($row->bulk ?? false);
            $manual = (bool) ($row->manual ?? false);
            $label = self::deviceLabel($row);
            $ip = isset($row->ip) && $row->ip !== '' ? (string) $row->ip : null;
            $public = (bool) ($row->public ?? false);

            $i = $open[$uid] ?? null;
            if ($i !== null) {
                $s = &$sessions[$i];
                if ($s['novel_id'] === (int) $row->novel_id
                    && $s['bulk'] === $bulk
                    && $s['manual'] === $manual
                    && $s['label'] === $label
                    && $s['started_at']->getTimestamp() - $at->getTimestamp() <= $gap) {
                    $s['chapters']++;
                    $s['first'] = $chapter;
                    $s['started_at'] = $at;
                    $s['public'] = $s['public'] || $public;
                    if ($ip !== null && !in_array($ip, $s['ips'], true)) {
                        $s['ips'][] = $ip;
                    }
                    $s['ip'] ??= $ip;
                    unset($s);
                    continue;
                }
                unset($s);
            }

            $sessions[] = [
                'user_id' => $uid,
                'novel_id' => (int) $row->novel_id,
                'novel_name' => (string) ($row->novel_name ?? ''),
                'chapters' => 1,
                'first' => $chapter,
                'last' => $chapter,
                'started_at' => $at,
                'ended_at' => $at,
                'bulk' => $bulk,
                'manual' => $manual,
                'label' => $label,
                'device' => isset($row->device) && $row->device !== '' ? (string) $row->device : null,
                'client' => isset($row->client) && $row->client !== '' ? (string) $row->client : null,
                'public' => $public,
                'ip' => $ip,
                'ips' => $ip === null ? [] : [$ip],
            ];
            $open[$uid] = count($sessions) - 1;
        }

        return $sessions;
    }

    /** "Chapter 12" / "Chapters 12–31 (20 chapters)" for a session from sessions(). */
    public static function sessionChapters(array $session): string
    {
        $first = $session['first']['number'] !== '' ? $session['first']['number'] : $session['first']['label'];
        if ($session['chapters'] === 1) {
            return $session['first']['number'] !== '' ? 'Chapter ' . $first : ($first !== '' ? $first : 'A chapter');
        }
        $last = $session['last']['number'] !== '' ? $session['last']['number'] : $session['last']['label'];

        return "Chapters {$first}–{$last} ({$session['chapters']} chapters)";
    }

    // ---------------------------------------------------------------------
    // One reader
    // ---------------------------------------------------------------------

    /**
     * $userId's newest $limit reading positions whose chapter still exists:
     * {novel_id, novel_name, chapter_id, number, label, progress, device_name,
     * client, ip, public, at}.
     *
     * @return Collection<int, object>
     */
    public function inProgress(int $userId, int $limit = 20): Collection
    {
        return ReadingPosition::query()
            ->join('novel_chapters as c', 'c.id', '=', 'reading_positions.novel_chapter_id')
            ->whereNull('c.deleted_at')
            ->join('novels as n', 'n.id', '=', 'reading_positions.novel_id')
            ->whereNull('n.deleted_at')
            ->where('reading_positions.user_id', $userId)
            ->orderByRaw('COALESCE(reading_positions.recorded_at, reading_positions.updated_at) DESC')
            ->orderByDesc('reading_positions.id')
            ->limit($limit)
            ->toBase()
            ->get([
                'reading_positions.novel_id', 'n.name as novel_name', 'c.id as chapter_id', 'c.chapter', 'c.book', 'c.number', 'c.part', 'c.label',
                'reading_positions.progress', 'reading_positions.device_name', 'reading_positions.recorded_at', 'reading_positions.updated_at',
                'reading_positions.client', 'reading_positions.ip', 'reading_positions.public',
            ])
            ->map(fn($r) => (object) [
                'novel_id' => (int) $r->novel_id,
                'novel_name' => (string) $r->novel_name,
                'chapter_id' => (int) $r->chapter_id,
                'number' => self::chapterNumber($r),
                'label' => (string) $r->label,
                'progress' => $r->progress === null ? null : (int) $r->progress,
                'device_name' => $r->device_name,
                'client' => $r->client,
                'ip' => $r->ip,
                'public' => (bool) $r->public,
                'at' => ($r->recorded_at ?? $r->updated_at) ? Carbon::parse($r->recorded_at ?? $r->updated_at) : null,
            ]);
    }

    /**
     * $user's devices, read-only: live app tokens {name, last_used_at} and
     * live browsers {device, ip, last_active_at, public}. The admin sees the
     * browsers' addresses here (as on the sign-in history); apps have none
     * stored.
     *
     * @return array{apps: Collection, browsers: Collection}
     */
    public function devices(User $user): array
    {
        $apps = ApiToken::where('user_id', $user->id)
            ->where('kind', ApiToken::KIND_APP)
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        $browsers = DB::table('browser_sessions')
            ->where('user_id', $user->id)
            ->where('last_active_at', '>=', BrowserSessions::liveSince())
            ->orderByDesc('last_active_at')->orderByDesc('id')
            ->get(['user_agent', 'ip', 'public', 'last_active_at'])
            ->map(fn($row) => (object) [
                'device' => DeviceDescription::from($row->user_agent),
                'ip' => $row->ip,
                'last_active_at' => $row->last_active_at ? Carbon::parse($row->last_active_at) : null,
                'public' => (bool) $row->public,
            ]);

        return ['apps' => $apps, 'browsers' => $browsers];
    }

    /**
     * The addresses $userId read from in the last WINDOW_DAYS, newest first:
     * {ip, public, chapters, last_at (Carbon)} per (address, public), over
     * their read rows that carry one (bulk marks included: they came from
     * somewhere too). One grouped query on (user_id, read_at).
     *
     * @return Collection<int, object>
     */
    public function addresses(int $userId, int $limit = 10): Collection
    {
        return $this->readRows([$userId], now()->subDays(self::WINDOW_DAYS - 1)->startOfDay())
            ->whereNotNull('cr.ip')
            ->groupBy('cr.ip', 'cr.public')
            ->selectRaw('cr.ip, cr.public, COUNT(*) as chapters, MAX(cr.read_at) as last_at')
            ->orderByDesc('last_at')
            ->orderBy('cr.ip')
            ->limit($limit)
            ->get()
            ->map(fn($r) => (object) [
                'ip' => (string) $r->ip,
                'public' => (bool) $r->public,
                'chapters' => (int) $r->chapters,
                'last_at' => Carbon::parse($r->last_at),
            ]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** The device a read or position is shown as: its declared device, else the client description; null when neither. */
    public static function deviceLabel(object|array $row): ?string
    {
        $row = (object) $row;
        foreach (['device', 'device_name', 'client'] as $key) {
            if (isset($row->{$key}) && trim((string) $row->{$key}) !== '') {
                return (string) $row->{$key};
            }
        }

        return null;
    }

    /** Display chapter number ("12", "Vol 2 · 3") of a row with chapter / book / number / part; '' when none. */
    public static function chapterNumber(object|array $row): string
    {
        $row = (object) $row;

        return (new NovelChapter())->forceFill([
            'chapter' => $row->chapter ?? 0,
            'book' => $row->book ?? 0,
            'number' => $row->number ?? null,
            'part' => $row->part ?? 0,
        ])->displayNumber();
    }

    /**
     * Read rows of $userIds (read_at set, bulk included) on chapters that are
     * not soft-deleted, joined as `c`; from $since when given.
     *
     * @param  int[]  $userIds
     */
    private function readRows(array $userIds, ?CarbonInterface $since = null): Builder
    {
        return DB::table(ReadState::TABLE . ' as cr')
            ->join('novel_chapters as c', 'c.id', '=', 'cr.novel_chapter_id')
            ->whereIn('cr.user_id', $userIds)
            ->when($since !== null, fn($q) => $q->where('cr.read_at', '>=', $since), fn($q) => $q->whereNotNull('cr.read_at'))
            ->whereNull('c.deleted_at');
    }

    /** readRows() without bulk marks: reading activity. */
    private function activityRows(array $userIds, ?CarbonInterface $since = null): Builder
    {
        return $this->readRows($userIds, $since)->where('cr.bulk', false);
    }
}
