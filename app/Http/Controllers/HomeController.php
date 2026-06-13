<?php

namespace App\Http\Controllers;

use App\NovelChapter;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Only the columns the dashboard renders — description is a longtext
        // and must never be pulled for list views.
        $columns = ['id', 'novel_id', 'chapter', 'label', 'created_at'];

        $missing_chapters = NovelChapter::with('novel:id,name')
            ->where('status', 0)
            ->where('blacklist', 0)
            ->orderBy('created_at', 'desc')
            ->paginate(10, $columns);

        // simplePaginate skips the COUNT(*) over the whole table; the badge
        // shows the last 24 hours instead, which is cheap and more useful.
        $latest_chapters = NovelChapter::with('novel:id,name')
            ->where('blacklist', 0)
            ->orderBy('created_at', 'desc')
            ->simplePaginate(10, $columns);

        $added_today = NovelChapter::where('blacklist', 0)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return view('home', [
            'missing_chapters' => $missing_chapters,
            'latest_chapters' => $latest_chapters,
            'added_today' => $added_today,
        ]);
    }
}
