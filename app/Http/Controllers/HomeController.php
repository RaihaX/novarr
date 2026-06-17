<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(NovelHealth $health)
    {
        // Only the columns the dashboard renders — description is a longtext
        // and must never be pulled for list views.
        $columns = ['id', 'novel_id', 'chapter', 'label', 'created_at', 'download_date'];

        $missing_chapters = NovelChapter::with('novel:id,name')
            ->where('status', 0)
            ->where('blacklist', 0)
            ->orderBy('created_at', 'desc')
            ->paginate(10, $columns, 'missing_page');

        // Most recently *downloaded* chapters (by download time), so the panel
        // reflects the every-10-minute scraper's actual activity.
        $latest_chapters = NovelChapter::with('novel:id,name')
            ->where('status', 1)
            ->where('blacklist', 0)
            ->whereNotNull('download_date')
            ->orderByDesc('download_date')
            ->simplePaginate(10, $columns, 'latest_page');

        // All indexed counts; cached briefly so dashboard refreshes stay cheap.
        $stats = Cache::remember('dashboard_stats', 60, fn() => [
            'active' => Novel::where('status', 0)->count(),
            'completed' => Novel::where('status', 1)->count(),
            'pending' => NovelChapter::where('status', 0)->where('blacklist', 0)->count(),
            'downloaded_today' => NovelChapter::where('status', 1)
                ->where('download_date', '>=', now()->subDay())->count(),
        ]);

        // Stall detection runs a few queries per pending novel — cache it.
        $attention = Cache::remember('dashboard_attention', 300, fn() => $health->needingAttention());

        $continue_reading = Cache::remember('dashboard_continue', 60, fn() => $this->continueReading());

        return view('home', [
            'missing_chapters' => $missing_chapters,
            'latest_chapters' => $latest_chapters,
            'stats' => $stats,
            'attention' => $attention,
            'continue_reading' => $continue_reading,
        ]);
    }

    /**
     * Novels you're partway through: most-recently-read first, each with its
     * next unread downloaded chapter. Skips novels you've fully caught up on.
     */
    private function continueReading(int $limit = 8): array
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

            // Index-served by idx_novel_book_chapter (novel_id, book, chapter).
            $next = NovelChapter::where('novel_id', $novelId)
                ->where('status', 1)->where('blacklist', 0)
                ->whereNull('read_at')
                ->orderBy('book')->orderBy('chapter')
                ->first(['id', 'chapter', 'label']);

            if (!$next) {
                continue; // caught up — nothing to continue
            }

            $items[] = ['novel' => $novel, 'next' => $next];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
