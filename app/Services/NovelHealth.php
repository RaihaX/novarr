<?php

namespace App\Services;

use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;

class NovelHealth
{
    /**
     * Active novels that look unhealthy: repeated all-failed scrape runs, or
     * pending chapters that haven't progressed in over a week (stalled).
     * Both branches require chapters still pending — the failure counter goes
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
            ->get(['id', 'name', 'scrape_failures', 'last_scrape_issue', 'translator_url']);

        foreach ($failing as $novel) {
            $attention[$novel->id] = [
                'id' => $novel->id,
                'name' => $novel->name,
                'reason' => "{$novel->scrape_failures} consecutive scrape runs failed — "
                    . ($novel->last_scrape_issue ?: 'the source site may have changed'),
                'url' => $this->sourceUrlFor($novel),
            ];
        }

        $stalled = Novel::where('status', 0)
            ->whereNull('paused_at')
            ->where($this->notSnoozed(...))
            // A backlog made only of needs-review chapters (8+ failed
            // attempts, retried every 3 days) isn't a stall the scraper can
            // fix, so it doesn't count — same rule the scraper applies.
            ->whereHas('chapters', fn($q) => $q->where('status', 0)->where('blacklist', 0)
                ->where('attempts', '<', NovelChapter::REVIEW_ATTEMPTS))
            ->orderBy('name')
            ->get(['id', 'name', 'translator_url', 'created_at', 'scrape_failures']);

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
                    'url' => $this->sourceUrlFor($novel),
                ];
            }
        }

        return array_values($attention);
    }

    /**
     * The URL the scraper is failing on: the next pending chapter's resolved
     * source URL, falling back to the novel's translator page.
     */
    public function sourceUrlFor(Novel $novel): ?string
    {
        $chapter = NovelChapter::with('novel.group')
            ->where('novel_id', $novel->id)
            ->where('status', 0)
            ->where('blacklist', 0)
            ->ordered()
            ->first(['id', 'novel_id', 'chapter', 'book', 'sort_key', 'url']);

        if ($chapter && $chapter->novel) {
            return chapterSourceUrl($chapter);
        }

        return $novel->translator_url ?: null;
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
