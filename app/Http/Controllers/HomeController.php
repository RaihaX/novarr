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
        $columns = ['id', 'novel_id', 'chapter', 'label', 'created_at'];

        $missing_chapters = NovelChapter::with('novel:id,name')
            ->where('status', 0)
            ->where('blacklist', 0)
            ->orderBy('created_at', 'desc')
            ->paginate(10, $columns, 'missing_page');

        $latest_chapters = NovelChapter::with('novel:id,name')
            ->where('blacklist', 0)
            ->orderBy('created_at', 'desc')
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

        return view('home', [
            'missing_chapters' => $missing_chapters,
            'latest_chapters' => $latest_chapters,
            'stats' => $stats,
            'attention' => $attention,
        ]);
    }
}
