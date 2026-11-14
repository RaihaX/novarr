<?php

namespace App\Services;

use App\Novel;
use App\NovelChapter;
use App\Scraping\SourceUnavailable;
use Carbon\Carbon;

class NovelHealth
{
    /**
     * Active novels that look unhealthy: repeated all-failed scrape runs,
     * pending chapters that haven't progressed in over a week (stalled), or
     * a TOC sync failing on 2+ consecutive runs (toc_failures, see
     * NovelScraper::recordTocHealth()). The first two require chapters still pending — the failure counter goes
     * stale once a novel is fully caught up, and a novel with nothing pending
     * has nothing failing. Shared by the daily summary email and the dashboard
     * so both always report the same problems.
     *
     * Novels added in the last 7 days that have never downloaded anything and
     * have no failed runs are given a grace period — their chapters are just
     * queued behind the download backlog, not stuck.
     *
     * Novels snoozed from the dashboard ("Snooze 7 days" sets
     * attention_ignored_until) are left out until the snooze expires. Snoozing
     * never pauses downloads — the scraper keeps working on them.
     *
     * @return array<int, array{id: int, name: string, reason: string, url: ?string}>
     */
    public function needingAttention(): array
    {
        $attention = [];

        $failing = Novel::where('status', 0)
            ->whereNull('paused_at')
            ->where($this->notSnoozed(...))
            ->where('scrape_failures', '>=', 3)
            ->whereHas('chapters', fn($q) => $q->where('status', 0)->where('blacklist', 0))
            ->orderBy('name')
            ->get(['id', 'name', 'scrape_failures', 'last_scrape_issue', 'translator_url', 'alternative_url', 'group_id']);

        foreach ($failing as $novel) {
            $attention[$novel->id] = [
                'id' => $novel->id,
                'name' => $novel->name,
                'reason' => "{$novel->scrape_failures} consecutive scrape runs failed — "
                    . ($novel->last_scrape_issue ?: 'the source site may have changed'),
                'novel' => $novel,
            ];
        }

        // Novels whose content-failed chapters are parked as a group (the
        // source lists them but does not serve them, see SourceUnavailable):
        // those chapters are a known state shown on the Health page, not a
        // stall.
        // A subquery, not a pluck: Home's query budget is fixed.
        $notParked = fn($m) => $m->whereNotIn('novel_id', \Illuminate\Support\Facades\DB::table('novels')->select('id')->where('source_fail_streak', '>', 0))
            ->orWhereNull('last_failure_reason')
            ->orWhereNotIn('last_failure_reason', SourceUnavailable::CONTENT_REASONS);

        $stalled = Novel::where('status', 0)
            ->whereNull('paused_at')
            ->where($this->notSnoozed(...))
            // A backlog made only of needs-review chapters (8+ failed
            // attempts, retried every 3 days) isn't a stall the scraper can
            // fix, so it doesn't count — same rule the scraper applies.
            ->whereHas('chapters', fn($q) => $q->where('status', 0)->where('blacklist', 0)
                ->where('attempts', '<', NovelChapter::REVIEW_ATTEMPTS)
                // Chapters the source no longer lists are parked, not stalled.
                ->where(fn($m) => $m->whereNull('last_failure_reason')->orWhere('last_failure_reason', '!=', 'source_missing'))
                ->where($notParked))
            ->orderBy('name')
            ->get(['id', 'name', 'translator_url', 'alternative_url', 'group_id', 'created_at', 'scrape_failures']);

        // Batch the per-novel stats into two grouped aggregate queries (served by
        // idx_novel_download_date) instead of two queries inside the loop.
        $stalledIds = $stalled->pluck('id')->all();

        $lastDownloads = NovelChapter::whereIn('novel_id', $stalledIds)
            ->where('status', 1)
            ->selectRaw('novel_id, MAX(download_date) as last_download')
            ->groupBy('novel_id')
            ->pluck('last_download', 'novel_id');

        $pendingCounts = NovelChapter::whereIn('novel_id', $stalledIds)
            ->where('status', 0)->where('blacklist', 0)
            ->where('attempts', '<', NovelChapter::REVIEW_ATTEMPTS)
            ->where(fn($m) => $m->whereNull('last_failure_reason')->orWhere('last_failure_reason', '!=', 'source_missing'))
            ->where($notParked)
            ->selectRaw('novel_id, COUNT(*) as pending')
            ->groupBy('novel_id')
            ->pluck('pending', 'novel_id');

        foreach ($stalled as $novel) {
            if (isset($attention[$novel->id])) {
                continue;
            }

            $lastDownload = $lastDownloads[$novel->id] ?? null;

            // Freshly added novel that hasn't had a scrape run fail yet: its
            // chapters are simply waiting their turn in the backlog, so don't
            // report it as a problem for the first week.
            if (
                $lastDownload === null
                && $novel->created_at !== null
                && Carbon::parse($novel->created_at)->gt(Carbon::now()->subDays(7))
                && (int) $novel->scrape_failures === 0
            ) {
                continue;
            }

            if ($lastDownload === null || Carbon::parse($lastDownload)->lt(Carbon::now()->subDays(7))) {
                $pending = $pendingCounts[$novel->id] ?? 0;
                $attention[$novel->id] = [
                    'id' => $novel->id,
                    'name' => $novel->name,
                    'reason' => "{$pending} pending chapter(s) but no successful download since "
                        . ($lastDownload ? Carbon::parse($lastDownload)->format('j M Y') : 'ever'),
                    'novel' => $novel,
                ];
            }
        }

        // TOC sync failing (empty / partial / shrinking list) on 2+
        // consecutive runs. No pending chapters required: a broken TOC is
        // exactly what hides new chapters from the queue.
        $tocFailing = Novel::where('status', 0)
            ->whereNull('paused_at')
            ->where($this->notSnoozed(...))
            ->where('toc_failures', '>=', 2)
            ->orderBy('name')
            ->get(['id', 'name', 'translator_url', 'alternative_url', 'group_id', 'toc_failures', 'last_toc_issue']);

        foreach ($tocFailing as $novel) {
            if (isset($attention[$novel->id])) {
                continue;
            }
            $attention[$novel->id] = [
                'id' => $novel->id,
                'name' => $novel->name,
                'reason' => $novel->last_toc_issue ?: "{$novel->toc_failures} consecutive TOC runs failed",
                'novel' => $novel,
            ];
        }

        $urls = $this->sourceUrlsFor(array_column($attention, 'novel'));

        return array_values(array_map(function (array $item) use ($urls) {
            $novel = $item['novel'];
            unset($item['novel']);

            return $item + ['url' => $urls[$novel->id] ?? null];
        }, $attention));
    }

    /**
     * The URL the scraper is failing on: the next pending chapter's resolved
     * source URL, falling back to the novel's translator page.
     */
    public function sourceUrlFor(Novel $novel): ?string
    {
        return $this->sourceUrlsFor([$novel])[$novel->id] ?? null;
    }

    /**
     * sourceUrlFor() for many novels at once: one query for their groups and
     * one for each novel's first pending chapter (in reading order). The
     * novels need translator_url, alternative_url and group_id loaded.
     *
     * @param  Novel[]  $novels
     * @return array<int, ?string>  keyed by novel id
     */
    public function sourceUrlsFor(array $novels): array
    {
        if ($novels === []) {
            return [];
        }

        $novels = (new \Illuminate\Database\Eloquent\Collection($novels))->loadMissing('group');

        $firstPending = self::firstPerNovel($novels->modelKeys(), fn($q) => $q
            ->where('status', 0)
            ->where('blacklist', 0)
            ->select(['id', 'novel_id', 'chapter', 'book', 'sort_key', 'url'])
            // Same order as NovelChapter::scopeOrdered().
            ->ordered());

        $urls = [];
        foreach ($novels as $novel) {
            $chapter = $firstPending->get($novel->id);
            if ($chapter) {
                $chapter->setRelation('novel', $novel);
                $urls[$novel->id] = chapterSourceUrl($chapter);
            } else {
                $urls[$novel->id] = $novel->translator_url ?: null;
            }
        }

        return $urls;
    }

    /** Members per UNION ALL statement (SQLite caps a compound select at 500). */
    private const UNION_CHUNK = 200;

    /**
     * The top chapter of each novel, for many novels in one statement: a
     * UNION ALL of one `WHERE novel_id = ? ... ORDER BY ... LIMIT 1` select
     * per novel. Each member is an index range scan that stops at its first
     * row, where a ROW_NUMBER() over `novel_id IN (...)` had to sort every
     * matching chapter of every novel first (150-550 ms on production).
     *
     * $perNovel adds the filters, columns and ORDER BY to a builder already
     * scoped to one novel (soft-delete scope included). The grammar wraps
     * each member so its ORDER BY/LIMIT stays its own: `(select ...)` on
     * MySQL/MariaDB, `select * from (select ...)` on SQLite.
     *
     * @param  int[]  $novelIds
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder): mixed  $perNovel
     * @return \Illuminate\Support\Collection<int, NovelChapter>  keyed by novel_id
     */
    public static function firstPerNovel(array $novelIds, \Closure $perNovel)
    {
        $novelIds = array_values(array_unique(array_map('intval', $novelIds)));
        if ($novelIds === []) {
            return collect();
        }

        $member = function (int $novelId) use ($perNovel) {
            $query = NovelChapter::where('novel_id', $novelId);
            $perNovel($query);

            return $query->limit(1);
        };

        $rows = collect();
        foreach (array_chunk($novelIds, self::UNION_CHUNK) as $chunk) {
            $query = $member(array_shift($chunk));
            foreach ($chunk as $novelId) {
                // toBase() applies the global (soft-delete) scope to the member.
                $query->unionAll($member($novelId)->toBase());
            }
            $rows = $rows->concat($query->get());
        }

        return $rows->keyBy('novel_id');
    }

    /**
     * Query constraint: not snoozed, i.e. attention_ignored_until is null or past.
     */
    protected function notSnoozed($q): void
    {
        $q->whereNull('attention_ignored_until')
            ->orWhere('attention_ignored_until', '<=', Carbon::now());
    }

    /**
     * Novels currently snoozed out of the needs-attention list, soonest
     * expiry first — the health page lists them so a snooze is never silent.
     *
     * @return \Illuminate\Support\Collection<int, Novel>
     */
    public function snoozed()
    {
        return Novel::where('attention_ignored_until', '>', Carbon::now())
            ->orderBy('attention_ignored_until')
            ->get(['id', 'name', 'attention_ignored_until']);
    }

    /**
     * The one definition of download progress, shared by the novel page and
     * the novels list so both always show the same figure:
     *
     *   downloaded ÷ chapters known to the source
     *
     * "Known to the source" is the TOC rows we hold (downloaded + queued,
     * blacklist excluded), or the source's own advertised chapter count when
     * that is larger (the TOC hasn't been fully synced yet) — so a novel with
     * 20 of 323 chapters reads 6%, never 100%.
     *
     * @return array{downloaded: int, total: int, percent: int}
     */
    public static function downloadProgress(int $downloaded, int $tocRows, ?int $advertised = null): array
    {
        $total = max($tocRows, (int) $advertised, $downloaded);
        $percent = $total > 0 ? (int) min(100, floor($downloaded / $total * 100)) : 0;

        return ['downloaded' => $downloaded, 'total' => $total, 'percent' => $percent];
    }

    /**
     * IDs of novels currently in the needs-attention list, read from the same
     * cache the dashboard uses (re-warmed by the scheduler), so list pages
     * can tint progress bars amber without recomputing stall detection.
     *
     * @return array<int, true>
     */
    public static function attentionIds(): array
    {
        $items = \Illuminate\Support\Facades\Cache::remember(
            'dashboard_attention',
            900,
            fn() => app(self::class)->needingAttention()
        );

        return array_fill_keys(array_column($items, 'id'), true);
    }

    /**
     * Status-triad state for a novel's download progress bar: green once
     * everything known is downloaded (or the novel is finished), amber when it
     * needs attention, muted while paused, cyan while chapters are queued.
     * Never the indigo accent — that colour is reserved for actions.
     */
    public static function progressState(int $percent, bool $completed, bool $paused, bool $attention): string
    {
        return match (true) {
            $percent >= 100 || $completed => 'downloaded',
            $attention => 'attention',
            $paused => 'paused',
            default => 'queued',
        };
    }
}
