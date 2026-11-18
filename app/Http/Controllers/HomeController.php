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
     * GET / — the "Now reading" home: the current book as a hero beside a
     * status card (needs attention, downloaded today, queued, scheduler), the
     * next six in-progress novels, and new chapters split into novels you are
     * reading and novels not started yet. The ops tables live on /activity
     * (ActivityController).
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
        $continue = $this->continueShelf();
        $counts = $this->readingCounts(array_map(fn($item) => $item['novel']->id, $continue));

        $hero = isset($continue[0]) ? $this->hero($continue[0], $counts, $attentionIds) : null;

        $shelf = array_map(function (array $item) use ($counts) {
            $c = $counts[$item['novel']->id] ?? ['total' => 0, 'read' => 0, 'unread' => 0];
            $total = max((int) $item['novel']->last_toc_count, $c['total']);

            return $item + [
                'percent' => $total > 0 ? (int) min(100, round($c['read'] / $total * 100)) : 0,
                'unread' => $c['unread'],
                'manga' => $item['novel']->isManga(),
                'page_text' => $item['novel']->isManga() ? self::pageText($item['next'], (bool) $item['resume']) : null,
            ];
        }, array_slice($continue, 1, 6));

        // New chapters, split by whether you have started the novel.
        $newReading = [];
        $newFresh = [];
        foreach ($this->newChapterGroups($continue) as $g) {
            if ($g['reading']) {
                $newReading[] = $g;
            } else {
                $newFresh[] = $g;
            }
        }

        return view('home', [
            'hero' => $hero,
            'shelf' => $shelf,
            'new_reading' => $newReading,
            'new_fresh' => $newFresh,
            'strip' => $this->statusStrip(count($attention)),
            'attention' => $attention,
            'snoozed' => $snoozed,
        ]);
    }

    /** The Continue items (hero first, then the shelf), cached briefly. */
    public function continueShelf(): array
    {
        return Cache::remember('dashboard_continue', 60, fn() => $this->continueReading(8));
    }

    /**
     * Home's "New chapters" groups (newChapters(), cached), each flagged
     * `reading`. A novel that is also on the Continue shelf ($continue, from
     * continueReading()) continues where the shelf does (an abandoned chapter
     * resumes), so the two never disagree; its cover comes from the shelf's
     * already-loaded novel (no extra query).
     */
    public function newChapterGroups(array $continue): array
    {
        $onShelf = [];
        foreach ($continue as $item) {
            $onShelf[$item['novel']->id] = $item;
        }

        $out = [];
        foreach (Cache::remember('dashboard_new_chapters', 60, fn() => $this->newChapters()) as $g) {
            // ?? false: an entry cached by the previous release has no flag.
            $g['reading'] = (bool) ($g['reading'] ?? false);
            if ($g['reading'] && ($item = $onShelf[$g['novel_id']] ?? null)) {
                $g['read_from_id'] = $item['next']->id;
                $g['read_from'] = (float) $item['next']->chapter;
                $g['novel'] = $item['novel'];
            }
            $out[] = $g;
        }

        return $out;
    }

    /**
     * Library-wide counts, cached briefly (NovelController forgets the key on
     * writes). Shared by Home's status card and the Activity page.
     *
     * @return array{active:int, completed:int, pending:int, downloaded_today:int, today:int}
     */
    public static function stats(): array
    {
        return Cache::remember('dashboard_stats', 60, function () {
            // Active / completed per library (Novels, Manga): Activity links
            // each figure to the library that lists exactly those series.
            $byMedium = [];
            foreach ([Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA] as $medium) {
                $byMedium[$medium] = ['active' => 0, 'completed' => 0];
            }
            $rows = Novel::query()
                ->selectRaw('medium, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as completed')
                ->groupBy('medium')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $medium = $row->medium === Novel::MEDIUM_MANGA ? Novel::MEDIUM_MANGA : Novel::MEDIUM_NOVEL;
                $byMedium[$medium]['active'] += (int) $row->active;
                $byMedium[$medium]['completed'] += (int) $row->completed;
            }

            // Rolling 24 hours (Activity's "/ 24H" chip) and since local
            // midnight (the strip's "chapters today") in one pass: midnight
            // today always falls inside the last 24 hours.
            $downloads = NovelChapter::where('status', 1)
                ->where('download_date', '>=', now()->subDay())
                ->selectRaw('COUNT(*) as downloaded_today,
                    SUM(CASE WHEN download_date >= ? THEN 1 ELSE 0 END) as today', [today()])
                ->toBase()
                ->first();

            return [
                'active' => array_sum(array_column($byMedium, 'active')),
                'completed' => array_sum(array_column($byMedium, 'completed')),
                'by_medium' => $byMedium,
                'pending' => NovelChapter::where('status', 0)->where('blacklist', 0)->count(),
                'downloaded_today' => (int) ($downloads->downloaded_today ?? 0),
                'today' => (int) ($downloads->today ?? 0),
            ];
        });
    }

    /**
     * The status card's numbers: scheduler heartbeat (read-only — the same cache key
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
        // book/sort_key, and minutes-left the stored word count.
        $chapter = NovelChapter::leftJoin('chapter_texts', 'chapter_texts.novel_chapter_id', '=', 'novel_chapters.id')
            ->whereKey($item['next']->id)
            ->first([
                'novel_chapters.id', 'novel_chapters.novel_id', 'novel_chapters.chapter', 'novel_chapters.book',
                'novel_chapters.sort_key', 'novel_chapters.label', 'novel_chapters.read_progress', 'novel_chapters.page_count',
                'chapter_texts.word_count', 'chapter_texts.novel_chapter_id as text_id',
            ]) ?? $item['next'];

        $rows = $counts[$novel->id]['total'] ?? 0;
        $total = max((int) $novel->last_toc_count, $rows);
        $index = NovelChapter::where('novel_id', $novel->id)
            ->where('blacklist', 0)
            ->relativeTo($chapter, '<=')
            ->count();

        $chapterPct = $item['resume'] ? (int) max(0, min(100, (int) $chapter->read_progress)) : 0;

        $words = (int) $chapter->word_count;
        if ($chapter->word_count === null && $chapter->text_id !== null) {
            // Not backfilled yet (chapters:word-counts): count the body.
            $words = \App\ChapterText::countWords(
                \App\ChapterText::whereKey($chapter->id)->value('content')
            );
        }
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
            // Chapters after this one ("79 to go").
            'to_go' => max(0, $total - $index),
            'source' => self::host($novel->translator_url),
            'chapter_percent' => $chapterPct,
            'book_percent' => $bookPct,
            'minutes_left' => $minutesLeft,
            'state' => NovelState::forNovel($novel, isset($attentionIds[$novel->id])),
            // Manga: where in the chapter, in pages ("Page 12 of 34").
            'manga' => $novel->isManga(),
            'page_text' => $novel->isManga() ? self::pageText($chapter, (bool) $item['resume']) : null,
        ];
    }

    /**
     * A manga chapter's resume page — the manga reader's own mapping
     * (resources/js/manga-reader/logic.js percentToPage): progress 1–99 of
     * N pages is page max(1, min(N, round(progress / 100 × N))). Null when
     * there is no page count or nothing in progress.
     */
    public static function mangaPage($progress, $pageCount): ?int
    {
        $n = (int) $pageCount;
        $p = $progress === null ? null : (int) $progress;
        if ($n < 1 || $p === null || $p < 1 || $p > 99) {
            return null;
        }

        return max(1, min($n, (int) round($p / 100 * $n)));
    }

    /**
     * Continue-card text for a manga chapter: "Page 12 of 34" while in
     * progress, "34 pages" for one not started; null without a page count.
     */
    public static function pageText($chapter, bool $resume): ?string
    {
        $n = (int) ($chapter->page_count ?? 0);
        if ($n < 1) {
            return null;
        }
        $page = $resume ? self::mangaPage($chapter->read_progress, $n) : null;

        return $page !== null
            ? 'Page ' . number_format($page) . ' of ' . number_format($n)
            : number_format($n) . ' ' . ($n === 1 ? 'page' : 'pages');
    }

    /**
     * Chapters indexed in the last NEW_CHAPTER_DAYS days, grouped by novel
     * (newest activity first): range, downloaded/queued split, whether any is
     * an author's note, whether you have started the novel, and where to read
     * from.
     *
     * Where to read from: in a novel you are reading, its first unread
     * downloaded chapter; in one you have not started, its first downloaded
     * real chapter (numbered 1 or later, not an author's note), so "Start"
     * never opens a chapter 0 notice when a chapter 1 is there. Both kinds
     * share one UNION statement (one member per novel); the fallback query
     * only runs for a not-started novel with no such chapter downloaded.
     *
     * Statements: the grouped window aggregate, the novels (with an EXISTS
     * probe on idx_novel_read for "started"), the read-from UNION, and rarely
     * the fallback: the same three as before the split.
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

        // "Started" = any chapter carries a read_at: a correlated EXISTS per
        // novel (at most $limit), each an idx_novel_read probe that stops at
        // its first row, folded into the novels query.
        $novels = Novel::whereIn('id', $groups->pluck('novel_id')->all())
            ->select(['id', 'name', 'translator_url'])
            ->selectSub(
                NovelChapter::query()
                    ->selectRaw('1')
                    ->whereColumn('novel_chapters.novel_id', 'novels.id')
                    ->whereNotNull('read_at')
                    ->limit(1),
                'is_reading'
            )
            ->get()
            ->keyBy('id');

        $readingSet = $novels->filter(fn($n) => (bool) $n->is_reading)->map(fn() => true)->all();
        $columns = ['id', 'novel_id', 'chapter', 'kind'];

        $readFrom = NovelHealth::firstPerNovel($novels->keys()->all(), function ($q, int $novelId) use ($readingSet, $columns) {
            $q->where('status', 1)->where('blacklist', 0)->whereNull('read_at')->select($columns);
            if (!isset($readingSet[$novelId])) {
                self::realChapter($q);
            }
            // Exactly NovelChapter::scopeOrdered().
            $q->ordered();
        });

        // Not started, and no real chapter downloaded yet (only a chapter 0
        // or a note): fall back to the first unread downloaded chapter.
        $unresolved = array_values(array_diff($novels->keys()->all(), array_keys($readingSet), $readFrom->keys()->all()));
        if ($unresolved !== []) {
            $readFrom = $readFrom->union($this->firstUnread($unresolved, $columns));
        }

        $out = [];
        foreach ($groups as $g) {
            $novel = $novels->get($g->novel_id);
            if (!$novel) {
                continue;
            }

            $first = $readFrom->get($novel->id);

            $out[] = [
                'novel_id' => $novel->id,
                'name' => $novel->name,
                'source' => self::host($novel->translator_url),
                'reading' => isset($readingSet[$novel->id]),
                'total' => (int) $g->total,
                'downloaded' => (int) $g->downloaded,
                'queued' => (int) $g->total - (int) $g->downloaded,
                'notes' => (int) $g->notes,
                'first' => (float) $g->first_chapter,
                'last' => (float) $g->last_chapter,
                'read_from_id' => $first?->id,
                'read_from' => $first ? (float) $first->chapter : null,
            ];
        }

        return $out;
    }

    /**
     * Constrain to "real" chapters, where a not-started novel's "Start"
     * should open: numbered 1 or later and not an author's note.
     */
    private static function realChapter($query): void
    {
        $query->where('chapter', '>=', 1)
            ->where(fn($k) => $k->whereNull('kind')->orWhere('kind', '!=', NovelChapter::KIND_NOTE));
    }

    /** A source URL's host without "www." ("novelping.com"), or null. */
    private static function host(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return $host ? preg_replace('/^www\./', '', $host) : null;
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
        // Manga: a chapter is only marked read once its last page has been
        // shown, so a manga being read has a chapter with progress and no
        // read_at yet; its last progress write counts as reading activity
        // (same statement: text novels only ever match on read_at).
        $recent = NovelChapter::where('status', 1)
            ->where('blacklist', 0)
            ->where(fn($q) => $q->whereNotNull('read_at')
                ->orWhere(fn($m) => $m->whereIn('novel_id', Novel::mangaIdsQuery())
                    ->whereBetween('read_progress', [1, 99])
                    ->whereNotNull('read_progress_at')))
            ->selectRaw('novel_id, MAX(COALESCE(read_at, read_progress_at)) as last_read')
            ->groupBy('novel_id')
            ->orderByDesc('last_read')
            ->limit($limit * 2) // over-fetch; some may be fully read
            ->pluck('last_read', 'novel_id');

        if ($recent->isEmpty()) {
            return [];
        }

        // Resolve every candidate novel (with its cover) in one query, with
        // only the columns the hero/shelf/state badge read — this lands in the
        // dashboard_continue cache, so no description/json.
        $novelIds = $recent->keys()->all();
        $novels = Novel::with('file')
            ->whereIn('id', $novelIds)
            ->get(['id', 'name', 'author', 'status', 'paused_at', 'last_toc_count', 'translator_url', 'medium'])
            ->keyBy('id');

        // Abandoned mid-chapter? Resume it exactly where the reader left off
        // (read_progress syncs from the reader on every device): the most
        // recently read such chapter per novel, all novels in one query. A
        // manga chapter in progress is not read yet (see above); page_count
        // lets the cards say "Page 12 of 34".
        $inProgress = NovelHealth::firstPerNovel($novelIds, function ($q, int $novelId) use ($novels) {
            $q->where('status', 1)->where('blacklist', 0)
                ->select(['id', 'novel_id', 'chapter', 'book', 'label', 'read_progress', 'page_count']);
            if ($novels->get($novelId)?->isManga()) {
                $q->whereNull('read_at')
                    ->whereBetween('read_progress', [1, 99])
                    ->whereNotNull('read_progress_at')
                    ->orderByDesc('read_progress_at');
            } else {
                $q->whereNotNull('read_at')
                    ->whereNotNull('read_progress')
                    ->where('read_progress', '<', 90)
                    ->orderByDesc('read_at');
            }
            $q->orderByDesc('id');
        });

        // Otherwise the first unread in reading order.
        $next = $this->firstUnread($novelIds, ['id', 'novel_id', 'chapter', 'book', 'label', 'page_count']);

        $items = [];
        foreach ($recent as $novelId => $lastRead) {
            $novel = $novels->get($novelId);
            if (!$novel) {
                continue;
            }

            if ($chapter = $inProgress->get($novelId)) {
                $items[] = ['novel' => $novel, 'next' => $chapter, 'resume' => true];
            } elseif ($chapter = $next->get($novelId)) {
                $items[] = ['novel' => $novel, 'next' => $chapter, 'resume' => false];
            } else {
                continue; // caught up — nothing to continue
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Each novel's first downloaded, unread chapter in reading order (one
     * statement for all novels, see NovelHealth::firstPerNovel()).
     *
     * @param  int[]  $novelIds
     * @return \Illuminate\Support\Collection<int, NovelChapter>  keyed by novel_id
     */
    private function firstUnread(array $novelIds, array $columns)
    {
        return NovelHealth::firstPerNovel($novelIds, fn($q) => $q
            ->where('status', 1)->where('blacklist', 0)
            ->whereNull('read_at')
            ->select($columns)
            // Exactly NovelChapter::scopeOrdered().
            ->ordered());
    }
}
