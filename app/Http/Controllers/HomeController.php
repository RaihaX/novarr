<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use App\Enums\NovelState;
use App\Services\NovelHealth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class HomeController extends Controller
{
    /** Reading pace for minutes-left; the reader uses the same figure (chapters/show WPM). */
    public const WPM = 230;

    /** How far back "New chapters" looks. */
    public const NEW_CHAPTER_DAYS = 7;

    /**
     * GET / — the "Now reading" home: the current book as a hero, the next
     * six in-progress novels, new chapters grouped by novel, and a one-line
     * status strip. The ops tables live on /activity (ActivityController).
     */
    public function index(NovelHealth $health)
    {
        // Stall detection runs a few queries per pending novel — the scheduler
        // re-warms this every 5 minutes (see routes/console.php), so a page
        // hit should never pay for it. TTL is longer than the warm interval.
        $attention = Cache::remember('dashboard_attention', 900, fn() => $health->needingAttention());
        $attentionIds = array_fill_keys(array_column($attention, 'id'), true);

        // Novels snoozed out of Needs attention stay visible as one muted
        // line (uncached: a snooze must show immediately).
        $snoozed = $health->snoozed();

        // First item is the hero; the next six fill the Continue row.
        $continue = Cache::remember('dashboard_continue', 60, fn() => $this->continueReading(8));
        $counts = $this->readingCounts(array_map(fn($item) => $item['novel']->id, $continue));

        $hero = isset($continue[0]) ? $this->hero($continue[0], $counts, $attentionIds) : null;

        $shelf = array_map(function (array $item) use ($counts) {
            $c = $counts[$item['novel']->id] ?? ['total' => 0, 'read' => 0, 'unread' => 0];
            $total = max((int) $item['novel']->last_toc_count, $c['total']);

            return $item + [
                'percent' => $total > 0 ? (int) min(100, round($c['read'] / $total * 100)) : 0,
                'unread' => $c['unread'],
            ];
        }, array_slice($continue, 1, 6));

        return view('home', [
            'hero' => $hero,
            'shelf' => $shelf,
            'new_chapters' => Cache::remember('dashboard_new_chapters', 60, fn() => $this->newChapters()),
            'strip' => $this->statusStrip(count($attention)),
            'attention' => $attention,
            'snoozed' => $snoozed,
        ]);
    }

    /**
     * Library-wide counts, cached briefly (NovelController forgets the key on
     * writes). Shared by the status strip and the Activity page.
     *
     * @return array{active:int, completed:int, pending:int, downloaded_today:int, today:int}
     */
    public static function stats(): array
    {
        return Cache::remember('dashboard_stats', 60, fn() => [
            'active' => Novel::where('status', 0)->count(),
            'completed' => Novel::where('status', 1)->count(),
            'pending' => NovelChapter::where('status', 0)->where('blacklist', 0)->count(),
            // Rolling 24 hours (Activity's "/ 24H" chip)…
            'downloaded_today' => NovelChapter::where('status', 1)
                ->where('download_date', '>=', now()->subDay())->count(),
            // …and since local midnight (the strip's "chapters today").
            'today' => NovelChapter::where('status', 1)
                ->where('download_date', '>=', today())->count(),
        ]);
    }

    /**
     * The status strip: scheduler heartbeat (read-only — the same cache key
     * and 3-minute staleness rule as SystemHealthController), queue depth,
     * attention count and chapters downloaded today.
     */
    private function statusStrip(int $attentionCount): array
    {
        $lastRun = Cache::get('scheduler_last_run');
        $lastRunAt = $lastRun ? Carbon::parse($lastRun) : null;

        // "Queue" means what it means on Activity: chapters waiting to be
        // downloaded. Background jobs on the worker queue are a different,
        // usually empty, number — shown only when something is running.
        try {
            $jobs = (int) Queue::size('commands') + (int) Queue::size('default');
        } catch (\Throwable $e) {
            report($e);
            $jobs = null; // backend unreachable: shown as "—", not as an empty queue
        }

        $stats = static::stats();

        return [
            'scheduler_last_run' => $lastRunAt,
            'scheduler_ok' => $lastRunAt !== null && !$lastRunAt->lt(now()->subMinutes(3)),
            'queue' => (int) ($stats['pending'] ?? 0),
            'jobs' => $jobs,
            'attention' => $attentionCount,
            'today' => $stats['today'] ?? 0,
        ];
    }

    /**
     * Per-novel chapter counts for the hero and the Continue row, in one
     * grouped query: total (non-blacklisted), read, and downloaded-but-unread.
     *
     * @param  int[]  $novelIds
     * @return array<int, array{total:int, read:int, unread:int}>
     */
    private function readingCounts(array $novelIds): array
    {
        if ($novelIds === []) {
            return [];
        }

        return NovelChapter::whereIn('novel_id', $novelIds)
            ->where('blacklist', 0)
            ->selectRaw('novel_id, COUNT(*) as total,
                SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count,
                SUM(CASE WHEN status = 1 AND read_at IS NULL THEN 1 ELSE 0 END) as unread_count')
            ->groupBy('novel_id')
            ->get()
            ->mapWithKeys(fn($row) => [(int) $row->novel_id => [
                'total' => (int) $row->total,
                'read' => (int) $row->read_count,
                'unread' => (int) $row->unread_count,
            ]])
            ->all();
    }

    /**
     * Everything the hero renders for the current book: position in the
     * novel (ranked like the reader's kicker), minutes left in the chapter
     * (words / WPM, scaled by the synced read_progress — the reader's own
     * formula), and the novel's status word.
     */
    private function hero(array $item, array $counts, array $attentionIds): array
    {
        $novel = $item['novel'];
        // The cached item carries a slim column set; the position query needs
        // book/sort_key, and minutes-left needs the body.
        $chapter = NovelChapter::find($item['next']->id) ?? $item['next'];

        $rows = $counts[$novel->id]['total'] ?? 0;
        $total = max((int) $novel->last_toc_count, $rows);
        $index = NovelChapter::where('novel_id', $novel->id)
            ->where('blacklist', 0)
            ->relativeTo($chapter, '<=')
            ->count();

        $chapterPct = $item['resume'] ? (int) max(0, min(100, (int) $chapter->read_progress)) : 0;

        $words = $chapter instanceof NovelChapter
            ? str_word_count(strip_tags((string) $chapter->rawText()))
            : 0;
        $remaining = $words * (1 - $chapterPct / 100);
        $minutesLeft = $words > 0 ? max(1, (int) round($remaining / self::WPM)) : null;

        // Book progress: chapters behind this one plus the part of this one read.
        $bookPct = $total > 0
            ? (int) min(100, round((max(0, $index - 1) + $chapterPct / 100) / $total * 100))
            : 0;

        return [
            'novel' => $novel,
            'chapter' => $chapter,
            'resume' => (bool) $item['resume'],
            'index' => $index,
            'total' => $total,
            'chapter_percent' => $chapterPct,
            'book_percent' => $bookPct,
            'minutes_left' => $minutesLeft,
            'state' => NovelState::forNovel($novel, isset($attentionIds[$novel->id])),
        ];
    }

    /**
     * Chapters indexed in the last NEW_CHAPTER_DAYS days, grouped by novel
     * (newest activity first): range, downloaded/queued split, whether any is
     * an author's note, and the first unread downloaded chapter to read from.
     */
    private function newChapters(int $limit = 10): array
    {
        $groups = NovelChapter::where('blacklist', 0)
            ->where('created_at', '>=', now()->subDays(self::NEW_CHAPTER_DAYS))
            ->selectRaw('novel_id, COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as downloaded,
                SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as notes,
                MIN(chapter) as first_chapter, MAX(chapter) as last_chapter,
                MAX(created_at) as latest', [NovelChapter::KIND_NOTE])
            ->groupBy('novel_id')
            ->orderByDesc('latest')
            ->limit($limit)
            ->get();

        if ($groups->isEmpty()) {
            return [];
        }

        $novels = Novel::whereIn('id', $groups->pluck('novel_id')->all())
            ->get(['id', 'name', 'translator_url'])
            ->keyBy('id');

        $out = [];
        foreach ($groups as $g) {
            $novel = $novels->get($g->novel_id);
            if (!$novel) {
                continue;
            }

            $readFrom = NovelChapter::where('novel_id', $novel->id)
                ->where('status', 1)->where('blacklist', 0)
                ->whereNull('read_at')
                ->ordered()
                ->first(['id', 'chapter', 'kind']);

            $host = parse_url((string) $novel->translator_url, PHP_URL_HOST);

            $out[] = [
                'novel_id' => $novel->id,
                'name' => $novel->name,
                'source' => $host ? preg_replace('/^www\./', '', $host) : null,
                'total' => (int) $g->total,
                'downloaded' => (int) $g->downloaded,
                'queued' => (int) $g->total - (int) $g->downloaded,
                'notes' => (int) $g->notes,
                'first' => (float) $g->first_chapter,
                'last' => (float) $g->last_chapter,
                'read_from_id' => $readFrom?->id,
                'read_from' => $readFrom ? (float) $readFrom->chapter : null,
            ];
        }

        return $out;
    }

    /**
     * GET /continue — the PWA "Continue Reading" shortcut. 302s to the same
     * resume point the dashboard's first Continue-reading card links to (the
     * most recently read novel's in-progress or next unread chapter), or to
     * the dashboard when there is nothing to continue.
     */
    public function continue()
    {
        $first = $this->continueReading(8)[0] ?? null;

        return $first
            ? redirect()->route('chapters.show', $first['next']->id)
            : redirect()->route('home');
    }

    /**
     * Novels you're partway through: most-recently-read first, each with its
     * next unread downloaded chapter. Skips novels you've fully caught up on.
     */
    public function continueReading(int $limit = 8): array
    {
        // Candidate novels, most-recently-read first (ordered novel_id => last_read).
        $recent = NovelChapter::where('status', 1)
            ->where('blacklist', 0)
            ->whereNotNull('read_at')
            ->selectRaw('novel_id, MAX(read_at) as last_read')
            ->groupBy('novel_id')
            ->orderByDesc('last_read')
            ->limit($limit * 2) // over-fetch; some may be fully read
            ->pluck('last_read', 'novel_id');

        if ($recent->isEmpty()) {
            return [];
        }

        // Resolve every candidate novel (with its cover) in one query instead of
        // a Novel::find() per row.
        $novels = Novel::with('file')
            ->whereIn('id', $recent->keys()->all())
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($recent as $novelId => $lastRead) {
            $novel = $novels->get($novelId);
            if (!$novel) {
                continue;
            }

            // Abandoned mid-chapter? Resume it exactly where the reader left
            // off (read_progress syncs from the reader on every device).
            $inProgress = NovelChapter::where('novel_id', $novelId)
                ->where('status', 1)->where('blacklist', 0)
                ->whereNotNull('read_at')
                ->whereNotNull('read_progress')
                ->where('read_progress', '<', 90)
                ->orderByDesc('read_at')
                ->first(['id', 'chapter', 'label', 'read_progress']);

            if ($inProgress) {
                $items[] = ['novel' => $novel, 'next' => $inProgress, 'resume' => true];
            } else {
                // First unread in reading order (idx_novel_book_sort_key).
                $next = NovelChapter::where('novel_id', $novelId)
                    ->where('status', 1)->where('blacklist', 0)
                    ->whereNull('read_at')
                    ->ordered()
                    ->first(['id', 'chapter', 'label']);

                if (!$next) {
                    continue; // caught up — nothing to continue
                }

                $items[] = ['novel' => $novel, 'next' => $next, 'resume' => false];
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
