<?php

namespace App\Services;

use App\Audiobook;
use App\Audiobooks\ListeningTime;
use App\Http\Controllers\Api\V1\Presenter;
use App\Novel;
use App\Support\StatsMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stats 2.0: one reader's personal statistics across reading AND listening,
 * for /stats and GET /api/v1/stats (same array; the API returns it as is).
 * The shape is documented on App\Http\Controllers\Api\V1\StatsController.
 *
 * Sources: chapter_reads (App\Services\ReadState; read_at, the reader's
 * own rows only), chapter_texts.word_count (estimated from text length
 * where the backfill hasn't reached), novel_tag/tags (genres),
 * audiobook_progress.finished_at and listening_activity
 * (App\Audiobooks\ListeningTime: seconds per day and hour).
 *
 * Rules:
 *  - Chapters marked read in bulk (chapter_reads.bulk) count in the
 *    all-time totals only, never as activity (days, streaks, charts), as
 *    on the old page (App\Services\ReadingStats).
 *  - Days and hours are in the app timezone (config app.timezone): stored
 *    times are already in it, so DATE()/hour of a stored time is local.
 *  - "Minutes" for reading are an estimate (READING_WPM words a minute; a
 *    manga chapter MANGA_CHAPTER_MINUTES), so reading and listening can
 *    share one scale (heatmap, by medium, genres); listening minutes are
 *    real.
 *  - A series is finished when every one of its (non-blacklisted, not
 *    deleted) chapters is read and it is complete (novels.status) or a
 *    book; it was finished when its last chapter was read.
 *  - A streak day has a non-bulk read or at least STREAK_LISTEN_SECONDS of
 *    listening.
 *
 * Cached per reader and range for CACHE_SECONDS (expires only).
 */
class ReaderStats
{
    public const RANGES = [30, 90, 365];

    public const CACHE_SECONDS = 300;

    public const READING_WPM = 250;

    public const MANGA_CHAPTER_MINUTES = 5;

    public const STREAK_LISTEN_SECONDS = 60;

    private const AVG_WORD_LEN = 6;

    private const WORDS_SQL = 'COALESCE(chapter_texts.word_count, LENGTH(chapter_texts.content) / ' . self::AVG_WORD_LEN . ', 0)';

    public const MEDIUM_LABELS = [
        Novel::MEDIUM_NOVEL => 'Web novels',
        Novel::MEDIUM_MANGA => 'Manga',
        Novel::MEDIUM_BOOK => 'Books',
        'audiobook' => 'Audiobooks',
    ];

    public function __construct(private readonly ReadState $readState)
    {
    }

    public static function normaliseRange(mixed $range): int
    {
        $range = (int) $range;

        return in_array($range, self::RANGES, true) ? $range : self::RANGES[0];
    }

    public static function cacheKey(int $userId, int $range): string
    {
        return "reader_stats_v1_u{$userId}_r{$range}";
    }

    public function for(int $userId, int $range = 30): array
    {
        $range = self::normaliseRange($range);

        return Cache::remember(self::cacheKey($userId, $range), self::CACHE_SECONDS, fn() => $this->fresh($userId, $range));
    }

    /** Reading minutes (estimate) for chapters of a medium with these words. */
    public static function readingMinutes(string $medium, int $chapters, float $words): float
    {
        return $medium === Novel::MEDIUM_MANGA
            ? $chapters * self::MANGA_CHAPTER_MINUTES
            : $words / self::READING_WPM;
    }

    public function fresh(int $userId, int $range = 30, ?CarbonImmutable $now = null): array
    {
        $range = self::normaliseRange($range);
        $tz = (string) config('app.timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $today = $now->toDateString();
        $rangeStart = $now->subDays($range - 1)->toDateString();
        $heatStart = $now->subWeeks(52)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $yearStart = $now->startOfYear()->toDateString();
        $weekStart = $now->subDays(6)->toDateString();
        $year = (int) $now->format('Y');

        // ---- Reads (not bulk) since the heatmap's start, per day, hour and series.
        $hourSql = $this->hourSql('cr.read_at');
        $reads = $this->readState->readChapters($userId)
            ->where('cr.bulk', false)
            ->where('cr.read_at', '>=', $heatStart . ' 00:00:00')
            ->join('novels', 'novels.id', '=', 'novel_chapters.novel_id')
            ->leftJoin('chapter_texts', 'chapter_texts.novel_chapter_id', '=', 'novel_chapters.id')
            ->selectRaw("DATE(cr.read_at) as d, {$hourSql} as h, novel_chapters.novel_id as novel_id, novels.medium as medium,
                COUNT(*) as chapters, SUM(" . self::WORDS_SQL . ') as words, MAX(cr.read_at) as last_read')
            ->groupBy('d', 'h', 'novel_chapters.novel_id', 'novels.medium')
            ->toBase()
            ->get();

        // ---- Listening since the heatmap's start, per day, hour and audiobook.
        $listening = DB::table(ListeningTime::TABLE)
            ->where('user_id', $userId)
            ->where('day', '>=', $heatStart)
            ->selectRaw('day as d, hour as h, audiobook_id, SUM(seconds) as seconds')
            ->groupBy('day', 'hour', 'audiobook_id')
            ->get();

        // Per-day figures over the heatmap window (covers the range and the year).
        $days = [];
        $blank = ['chapters' => 0, 'words' => 0.0, 'listening_seconds' => 0, 'minutes' => 0.0];
        $hours = array_fill(0, 24, ['chapters' => 0, 'minutes' => 0.0]);
        $weekdays = array_fill(1, 7, ['chapters' => 0, 'minutes' => 0.0]);
        $media = [];
        foreach (array_keys(self::MEDIUM_LABELS) as $m) {
            $media[$m] = ['chapters' => 0, 'words' => 0.0, 'listening_seconds' => 0, 'minutes' => 0.0];
        }
        $seriesRange = [];
        $seriesYear = [];
        $months = [];

        foreach ($reads as $r) {
            $d = (string) $r->d;
            $medium = in_array($r->medium, [Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK], true) ? $r->medium : Novel::MEDIUM_NOVEL;
            $chapters = (int) $r->chapters;
            $words = (float) $r->words;
            $minutes = self::readingMinutes($medium, $chapters, $words);
            $days[$d] ??= $blank;
            $days[$d]['chapters'] += $chapters;
            $days[$d]['words'] += $words;
            $days[$d]['minutes'] += $minutes;
            $nid = (int) $r->novel_id;
            if ($d >= $rangeStart) {
                $h = (int) $r->h;
                $hours[$h]['chapters'] += $chapters;
                $hours[$h]['minutes'] += $minutes;
                $wd = (int) CarbonImmutable::parse($d, $tz)->format('N');
                $weekdays[$wd]['chapters'] += $chapters;
                $weekdays[$wd]['minutes'] += $minutes;
                $media[$medium]['chapters'] += $chapters;
                $media[$medium]['words'] += $words;
                $media[$medium]['minutes'] += $minutes;
                $seriesRange[$nid] ??= ['chapters' => 0, 'words' => 0.0, 'minutes' => 0.0, 'medium' => $medium, 'last_read' => null];
                $seriesRange[$nid]['chapters'] += $chapters;
                $seriesRange[$nid]['words'] += $words;
                $seriesRange[$nid]['minutes'] += $minutes;
                $seriesRange[$nid]['last_read'] = max((string) $seriesRange[$nid]['last_read'], (string) $r->last_read);
            }
            if ($d >= $yearStart) {
                $seriesYear[$nid] ??= ['chapters' => 0, 'minutes' => 0.0];
                $seriesYear[$nid]['chapters'] += $chapters;
                $seriesYear[$nid]['minutes'] += $minutes;
                $month = substr($d, 0, 7);
                $months[$month] = ($months[$month] ?? 0) + $chapters;
            }
        }

        $booksRange = [];
        $booksYear = [];
        foreach ($listening as $l) {
            $d = (string) $l->d;
            $seconds = (int) $l->seconds;
            $minutes = $seconds / 60;
            $days[$d] ??= $blank;
            $days[$d]['listening_seconds'] += $seconds;
            $days[$d]['minutes'] += $minutes;
            $bid = (int) $l->audiobook_id;
            if ($d >= $rangeStart) {
                $h = (int) $l->h;
                $hours[$h]['minutes'] += $minutes;
                $wd = (int) CarbonImmutable::parse($d, $tz)->format('N');
                $weekdays[$wd]['minutes'] += $minutes;
                $media['audiobook']['listening_seconds'] += $seconds;
                $media['audiobook']['minutes'] += $minutes;
                $booksRange[$bid] = ($booksRange[$bid] ?? 0) + $seconds;
            }
            if ($d >= $yearStart) {
                $booksYear[$bid] = ($booksYear[$bid] ?? 0) + $seconds;
            }
        }

        // ---- Daily / weekly series over the range.
        $daily = [];
        for ($i = $range - 1; $i >= 0; $i--) {
            $date = $now->subDays($i)->toDateString();
            $day = $days[$date] ?? $blank;
            $daily[] = [
                'date' => $date,
                'chapters' => $day['chapters'],
                'words' => (int) round($day['words']),
                'listening_seconds' => $day['listening_seconds'],
            ];
        }
        $weekly = [];
        $cursor = CarbonImmutable::parse($rangeStart, $tz)->startOfWeek(CarbonImmutable::MONDAY);
        while ($cursor->toDateString() <= $today) {
            $w = ['start' => $cursor->toDateString(), 'chapters' => 0, 'words' => 0, 'listening_seconds' => 0];
            for ($k = 0; $k < 7; $k++) {
                $day = $days[$cursor->addDays($k)->toDateString()] ?? null;
                if ($day) {
                    $w['chapters'] += $day['chapters'];
                    $w['words'] += (int) round($day['words']);
                    $w['listening_seconds'] += $day['listening_seconds'];
                }
            }
            $weekly[] = $w;
            $cursor = $cursor->addWeek();
        }

        // ---- Heatmap: every day from a Monday ~52 weeks ago to today.
        $heat = [];
        $maxMinutes = 0.0;
        for ($c = CarbonImmutable::parse($heatStart, $tz); $c->toDateString() <= $today; $c = $c->addDay()) {
            $day = $days[$c->toDateString()] ?? $blank;
            $maxMinutes = max($maxMinutes, $day['minutes']);
            $heat[] = ['date' => $c->toDateString(), 'chapters' => $day['chapters'], 'minutes' => (int) round($day['minutes'])];
        }
        foreach ($heat as &$cell) {
            $cell['level'] = StatsMath::level($cell['minutes'] > 0 ? $cell['minutes'] : ($cell['chapters'] > 0 ? 1 : 0), max(1, round($maxMinutes)));
        }
        unset($cell);

        // ---- All-time totals (bulk marks included), listening, finished.
        $totals = $this->readState->readChapters($userId)
            ->leftJoin('chapter_texts', 'chapter_texts.novel_chapter_id', '=', 'novel_chapters.id')
            ->selectRaw('COUNT(*) as chapters, SUM(' . self::WORDS_SQL . ') as words')
            ->toBase()
            ->first();
        $listenedTotal = (int) DB::table(ListeningTime::TABLE)->where('user_id', $userId)->sum('seconds');

        $finished = $this->finishedSeries($userId);
        $seriesFinished = count($finished);
        $booksFinished = count(array_filter($finished, fn($f) => $f['medium'] === Novel::MEDIUM_BOOK));
        $finishedThisYear = array_filter($finished, fn($f) => $f['at'] >= $yearStart);
        $audiobooksFinished = DB::table('audiobook_progress as p')
            ->join('audiobooks as a', 'a.id', '=', 'p.audiobook_id')
            ->whereNull('a.deleted_at')
            ->where('p.user_id', $userId)
            ->whereNotNull('p.finished_at')
            ->pluck('p.finished_at')
            ->map(fn($at) => substr((string) $at, 0, 10))
            ->all();

        // ---- Streaks: any non-bulk read, or a minute of listening.
        $activeDates = $this->readState->readChapters($userId)
            ->where('cr.bulk', false)
            ->selectRaw('DISTINCT DATE(cr.read_at) as d')
            ->toBase()
            ->pluck('d')
            ->map(fn($d) => (string) $d)
            ->all();
        $listenDates = DB::table(ListeningTime::TABLE)
            ->where('user_id', $userId)
            ->groupBy('day')
            ->havingRaw('SUM(seconds) >= ?', [self::STREAK_LISTEN_SECONDS])
            ->pluck('day')
            ->map(fn($d) => substr((string) $d, 0, 10))
            ->all();
        $streak = StatsMath::streaks(array_merge($activeDates, $listenDates), $today);
        $activeSet = array_flip(array_merge($activeDates, $listenDates));

        // ---- Range and "today / week" figures.
        $rangeDays = array_filter($daily, fn($d) => $d['date'] >= $rangeStart);
        $rangeChapters = array_sum(array_column($rangeDays, 'chapters'));
        $rangeWords = array_sum(array_column($rangeDays, 'words'));
        $rangeListen = array_sum(array_column($rangeDays, 'listening_seconds'));
        $rangeActive = count(array_filter(array_keys($activeSet), fn($d) => $d >= $rangeStart && $d <= $today));
        $todayDay = $days[$today] ?? $blank;
        $weekChapters = 0;
        $weekListen = 0;
        for ($i = 0; $i < 7; $i++) {
            $day = $days[$now->subDays($i)->toDateString()] ?? null;
            $weekChapters += $day['chapters'] ?? 0;
            $weekListen += $day['listening_seconds'] ?? 0;
        }

        // ---- By medium (range), shares by minutes.
        $mediumMinutes = array_sum(array_column($media, 'minutes'));
        $byMedium = [];
        foreach ($media as $m => $v) {
            $byMedium[] = [
                'medium' => $m,
                'label' => self::MEDIUM_LABELS[$m],
                'chapters' => $v['chapters'],
                'words' => (int) round($v['words']),
                'listening_seconds' => $v['listening_seconds'],
                'minutes' => (int) round($v['minutes']),
                'share' => $mediumMinutes > 0 ? round($v['minutes'] / $mediumMinutes, 4) : 0,
            ];
        }

        // ---- Names, genres, audiobooks.
        $novelIds = array_values(array_unique(array_merge(array_keys($seriesRange), array_keys($seriesYear))));
        $audiobookIds = array_values(array_unique(array_merge(array_keys($booksRange), array_keys($booksYear))));
        $audiobooks = $audiobookIds === [] ? collect() : Audiobook::withTrashed()->whereIn('id', $audiobookIds)
            ->get(['id', 'title', 'author', 'novel_id'])->keyBy('id');
        $tagNovelIds = array_values(array_unique(array_merge($novelIds, $audiobooks->pluck('novel_id')->filter()->map(fn($id) => (int) $id)->all())));
        $novels = $tagNovelIds === [] ? collect() : Novel::whereIn('id', $tagNovelIds)->get(['id', 'name', 'medium'])->keyBy('id');
        $tags = $this->tagsOf($tagNovelIds);

        $genre = function (array $series, array $books) use ($tags, $audiobooks): array {
            $g = [];
            foreach ($series as $nid => $s) {
                foreach ($tags[$nid] ?? [] as $name) {
                    $g[$name] ??= ['name' => $name, 'chapters' => 0, 'minutes' => 0.0];
                    $g[$name]['chapters'] += $s['chapters'];
                    $g[$name]['minutes'] += $s['minutes'];
                }
            }
            foreach ($books as $bid => $seconds) {
                $nid = (int) ($audiobooks->get($bid)?->novel_id ?? 0);
                foreach ($tags[$nid] ?? [] as $name) {
                    $g[$name] ??= ['name' => $name, 'chapters' => 0, 'minutes' => 0.0];
                    $g[$name]['minutes'] += $seconds / 60;
                }
            }
            usort($g, fn($a, $b) => [$b['minutes'], $b['chapters'], $a['name']] <=> [$a['minutes'], $a['chapters'], $b['name']]);

            return array_map(fn($x) => ['name' => $x['name'], 'chapters' => $x['chapters'], 'minutes' => (int) round($x['minutes'])], $g);
        };

        $topGenres = array_slice($genre($seriesRange, $booksRange), 0, 8);

        uasort($seriesRange, fn($a, $b) => [$b['chapters'], $b['last_read']] <=> [$a['chapters'], $a['last_read']]);
        $topSeries = [];
        foreach ($seriesRange as $nid => $s) {
            $novel = $novels->get($nid);
            if ($novel === null) {
                continue;
            }
            $topSeries[] = [
                'id' => $nid,
                'name' => $novel->name,
                'medium' => $s['medium'],
                'chapters' => $s['chapters'],
                'words' => (int) round($s['words']),
                'last_read_at' => Presenter::timestamp($s['last_read']),
            ];
            if (count($topSeries) === 10) {
                break;
            }
        }

        arsort($booksRange);
        $topAudiobooks = [];
        foreach ($booksRange as $bid => $seconds) {
            $book = $audiobooks->get($bid);
            if ($book === null) {
                continue;
            }
            $topAudiobooks[] = [
                'id' => $bid,
                'title' => $book->title,
                'author' => $book->author,
                'novel_id' => $book->novel_id === null ? null : (int) $book->novel_id,
                'listening_seconds' => $seconds,
            ];
            if (count($topAudiobooks) === 10) {
                break;
            }
        }

        // ---- Year in review.
        $yearGenres = $genre($seriesYear, $booksYear);
        arsort($months);
        $busiest = array_key_first($months);
        uasort($seriesYear, fn($a, $b) => $b['chapters'] <=> $a['chapters']);
        $yearTopId = array_key_first($seriesYear);
        $yearDays = array_filter($days, fn($d, $date) => $date >= $yearStart, ARRAY_FILTER_USE_BOTH);
        $yearSummary = [
            'year' => $year,
            'chapters' => array_sum(array_column($yearDays, 'chapters')),
            'words' => (int) round(array_sum(array_column($yearDays, 'words'))),
            'listening_seconds' => array_sum(array_column($yearDays, 'listening_seconds')),
            'series_finished' => count($finishedThisYear),
            'books_finished' => count(array_filter($finishedThisYear, fn($f) => $f['medium'] === Novel::MEDIUM_BOOK)),
            'audiobooks_finished' => count(array_filter($audiobooksFinished, fn($d) => $d >= $yearStart)),
            'active_days' => count(array_filter(array_keys($activeSet), fn($d) => $d >= $yearStart && $d <= $today)),
            'top_genre' => $yearGenres[0]['name'] ?? null,
            'top_series' => $yearTopId !== null && $novels->has($yearTopId)
                ? ['id' => $yearTopId, 'name' => $novels->get($yearTopId)->name]
                : null,
            'busiest_month' => $busiest === null || $months[$busiest] === 0 ? null : [
                'month' => $busiest,
                'label' => CarbonImmutable::parse($busiest . '-01', $tz)->format('F'),
                'chapters' => $months[$busiest],
            ],
        ];

        $chaptersTotal = (int) ($totals->chapters ?? 0);
        $wordsTotal = (int) round((float) ($totals->words ?? 0));
        $headline = [
            'chapters_read' => $chaptersTotal,
            'words_read' => $wordsTotal,
            'series_finished' => $seriesFinished,
            'books_finished' => $booksFinished,
            'audiobooks_finished' => count($audiobooksFinished),
            'listening_seconds' => $listenedTotal,
            'current_streak' => $streak['current'],
            'best_streak' => $streak['best'],
            'active_today' => isset($activeSet[$today]),
            'chapters_today' => $todayDay['chapters'],
            'words_today' => (int) round($todayDay['words']),
            'listening_seconds_today' => $todayDay['listening_seconds'],
            'chapters_week' => $weekChapters,
            'listening_seconds_week' => $weekListen,
            'chapters_per_day' => round($rangeChapters / $range, 1),
            'words_per_day' => round($rangeWords / $range, 1),
            'listening_minutes_per_day' => round($rangeListen / 60 / $range, 1),
        ];

        return [
            'generated_at' => Presenter::timestamp($now),
            'timezone' => $tz,
            'today' => $today,
            'range' => $range,
            'headline' => $headline,
            'range_totals' => [
                'days' => $range,
                'chapters' => $rangeChapters,
                'words' => $rangeWords,
                'listening_seconds' => $rangeListen,
                'active_days' => $rangeActive,
            ],
            'daily' => $daily,
            'weekly' => $weekly,
            'heatmap' => [
                'start' => $heatStart,
                'end' => $today,
                'max_minutes' => (int) round($maxMinutes),
                'days' => $heat,
            ],
            'by_medium' => $byMedium,
            'top_genres' => $topGenres,
            'top_series' => $topSeries,
            'top_audiobooks' => $topAudiobooks,
            'hours' => array_map(fn($h, $v) => ['hour' => $h, 'chapters' => $v['chapters'], 'minutes' => (int) round($v['minutes'])], array_keys($hours), $hours),
            'weekdays' => array_map(fn($d, $v) => [
                'day' => $d,
                'label' => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$d],
                'chapters' => $v['chapters'],
                'minutes' => (int) round($v['minutes']),
            ], array_keys($weekdays), $weekdays),
            'year' => $yearSummary,
            'milestones' => self::milestones($headline),
        ];
    }

    /**
     * Badges from the headline figures: key, title, detail, earned,
     * progress (0..1), current, target.
     *
     * @return list<array<string, mixed>>
     */
    public static function milestones(array $h): array
    {
        $defs = [
            ['first_chapter', 'First page', 'Finish your first chapter', $h['chapters_read'], 1],
            ['chapters_100', 'Century', 'Read 100 chapters', $h['chapters_read'], 100],
            ['chapters_1000', 'Thousand chapters', 'Read 1,000 chapters', $h['chapters_read'], 1000],
            ['words_1m', 'Million words', 'Read a million words', $h['words_read'], 1_000_000],
            ['streak_7', 'Week streak', 'Read or listen 7 days in a row', $h['best_streak'], 7],
            ['streak_30', 'Month streak', 'Read or listen 30 days in a row', $h['best_streak'], 30],
            ['series_1', 'The end', 'Finish a whole series or book', $h['series_finished'], 1],
            ['series_10', 'Completionist', 'Finish 10 series or books', $h['series_finished'], 10],
            ['audiobook_1', 'All ears', 'Finish an audiobook', $h['audiobooks_finished'], 1],
            ['listening_10h', 'Ten hours in', 'Listen for 10 hours', intdiv($h['listening_seconds'], 3600), 10],
            ['listening_100h', 'Marathon listener', 'Listen for 100 hours', intdiv($h['listening_seconds'], 3600), 100],
        ];

        return array_map(fn($d) => [
            'key' => $d[0],
            'title' => $d[1],
            'detail' => $d[2],
            'earned' => $d[3] >= $d[4],
            'progress' => round(min(1, $d[3] / $d[4]), 3),
            'current' => (int) $d[3],
            'target' => $d[4],
        ], $defs);
    }

    /**
     * The reader's finished series: novel id => [medium, at (Y-m-d of the
     * last read)]. A series is finished when all its chapters (not
     * blacklisted, not deleted) are read and it is complete or a book.
     *
     * @return array<int, array{medium:string, at:string}>
     */
    private function finishedSeries(int $userId): array
    {
        $started = DB::table(ReadState::TABLE)->where('user_id', $userId)->whereNotNull('read_at')
            ->distinct()->pluck('novel_id')->map(fn($id) => (int) $id)->all();
        if ($started === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($started, 500) as $ids) {
            $rows = DB::table('novel_chapters as c')
                ->join('novels as n', 'n.id', '=', 'c.novel_id')
                ->leftJoin(ReadState::TABLE . ' as cr', function ($j) use ($userId) {
                    $j->on('cr.novel_chapter_id', '=', 'c.id')->where('cr.user_id', '=', $userId);
                })
                ->whereIn('c.novel_id', $ids)
                ->whereNull('c.deleted_at')
                ->where('c.blacklist', 0)
                ->whereNull('n.deleted_at')
                ->groupBy('c.novel_id', 'n.medium', 'n.status')
                ->selectRaw('c.novel_id as novel_id, n.medium as medium, n.status as status, COUNT(*) as total,
                    SUM(CASE WHEN cr.read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count, MAX(cr.read_at) as last_read')
                ->get();
            foreach ($rows as $r) {
                $complete = (bool) $r->status || $r->medium === Novel::MEDIUM_BOOK;
                if ($complete && (int) $r->total > 0 && (int) $r->read_count >= (int) $r->total) {
                    $out[(int) $r->novel_id] = ['medium' => (string) $r->medium, 'at' => substr((string) $r->last_read, 0, 10)];
                }
            }
        }

        return $out;
    }

    /**
     * Tag names per novel id.
     *
     * @param  int[]  $novelIds
     * @return array<int, string[]>
     */
    private function tagsOf(array $novelIds): array
    {
        if ($novelIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($novelIds, 500) as $ids) {
            foreach (DB::table('novel_tag')->join('tags', 'tags.id', '=', 'novel_tag.tag_id')
                ->whereIn('novel_tag.novel_id', $ids)->get(['novel_tag.novel_id', 'tags.name']) as $row) {
                $out[(int) $row->novel_id][] = (string) $row->name;
            }
        }

        return $out;
    }

    private function hourSql(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', {$column}) AS INTEGER)",
            'pgsql' => "EXTRACT(HOUR FROM {$column})::int",
            default => "HOUR({$column})",
        };
    }
}
