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
        $missing_chapters = NovelChapter::with('novel:id,name')
            ->where('status', 0)
            ->where('blacklist', 0)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $latest_chapters = NovelChapter::with('novel:id,name')
            ->where('blacklist', 0)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('home', [
            'missing_chapters' => $missing_chapters,
            'latest_chapters' => $latest_chapters
        ]);
    }
}
