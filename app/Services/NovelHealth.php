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
     * @return array<int, array{id: int, name: string, reason: string, url: ?string}>
     */
    public function needingAttention(): array
    {
        $attention = [];

        $failing = Novel::where('status', 0)
            ->whereNull('paused_at')
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
            ->whereHas('chapters', fn($q) => $q->where('status', 0)->where('blacklist', 0))
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
            ->orderBy('book')
            ->orderBy('chapter')
            ->first(['id', 'novel_id', 'chapter', 'book', 'url']);

        if ($chapter && $chapter->novel) {
            return chapterSourceUrl($chapter);
        }

        return $novel->translator_url ?: null;
    }
}
