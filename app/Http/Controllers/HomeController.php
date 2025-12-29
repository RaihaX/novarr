<?php

namespace App\Http\Controllers;

use App\NovelChapter;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use Carbon\Carbon;
use DataTables;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function datatables_latest_chapters() {
        // Cache DataTables response for 2 minutes
        return Cache::remember('datatables_latest_chapters', now()->addMinutes(2), function () {
            $query = NovelChapter::query()
                ->leftJoin('novels', 'novels.id', '=', 'novel_chapters.novel_id')
                ->where('novel_chapters.status', 1)
                ->select(
                    'novel_chapters.id',
                    'novel_chapters.label',
                    'novel_chapters.chapter',
                    'novel_chapters.book',
                    'novel_chapters.novel_id',
                    'novel_chapters.created_at',
                    'novel_chapters.download_date',
                    'novels.name'
                )
                ->orderBy('download_date', 'desc')
                ->orderBy('novel_chapters.id', 'desc')
                ->limit(100);

            return DataTables::eloquent($query)->toJson();
        });
    }

    public function datatables_missing_chapters() {
        // Cache DataTables response for 2 minutes
        return Cache::remember('datatables_missing_chapters', now()->addMinutes(2), function () {
            $query = NovelChapter::query()
                ->leftJoin('novels', 'novels.id', '=', 'novel_chapters.novel_id')
                ->where('novel_chapters.blacklist', 0)
                ->where('novel_chapters.status', 0)
                ->select(
                    'novel_chapters.id',
                    'novel_chapters.label',
                    'novel_chapters.chapter',
                    'novel_chapters.book',
                    'novel_chapters.novel_id',
                    'novel_chapters.created_at',
                    'novels.name'
                );

            return DataTables::eloquent($query)->toJson();
        });
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Add eager loading for novel relationship
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
