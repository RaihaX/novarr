<?php

namespace App\Http\Controllers;

use App\NovelChapter;
use App\Services\NovelHealth;
use Illuminate\Support\Facades\Cache;

/**
 * GET /activity — the operational view that used to sit under the dashboard:
 * chapters still missing, chapters most recently downloaded, the library
 * counts that were the old stat tiles, and the snoozed-attention note. The
 * home page is now about reading; its status strip links here.
 */
class ActivityController extends Controller
{
    public function index(NovelHealth $health)
    {
        // Only the columns the tables render — the body lives in chapter_texts
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

        // Same cache key the scheduler pre-warms (routes/console.php).
        $attention = Cache::remember('dashboard_attention', 900, fn() => $health->needingAttention());

        return view('activity.index', [
            'missing_chapters' => $missing_chapters,
            'latest_chapters' => $latest_chapters,
            'stats' => HomeController::stats(),
            'attention_count' => count($attention),
            'snoozed' => $health->snoozed(),
        ]);
    }
}
