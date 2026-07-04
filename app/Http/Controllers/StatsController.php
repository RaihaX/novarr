<?php

namespace App\Http\Controllers;

use App\NovelChapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reading statistics, derived entirely from read_at timestamps (and text
 * length for an approximate word count — LENGTH()/6 avoids a stored
 * word_count column and works on both MySQL and SQLite).
 */
class StatsController extends Controller
{
    private const AVG_WORD_LEN = 6; // chars per word incl. space, rough

    public function index()
    {
        $stats = Cache::remember('reading_stats_v1', 900, fn() => $this->build());

        return view('stats.index', $stats);
    }

    private function build(): array
    {
        $days = 30;
        $since = now()->subDays($days - 1)->startOfDay();

        // Chapters + text volume read per day, last 30 days.
        $rows = NovelChapter::whereNotNull('read_at')
            ->where('read_at', '>=', $since)
            ->selectRaw('DATE(read_at) as d, COUNT(*) as chapters, SUM(LENGTH(description)) as chars')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $rows->get($date);
            $daily[] = [
                'date' => $date,
                'label' => now()->subDays($i)->format('j M'),
                'chapters' => (int) ($row->chapters ?? 0),
                'words' => (int) round(($row->chars ?? 0) / self::AVG_WORD_LEN),
            ];
        }

        // Streak: consecutive days (ending today or yesterday) with >= 1 read.
        $readDates = NovelChapter::whereNotNull('read_at')
            ->selectRaw('DISTINCT DATE(read_at) as d')
            ->orderByDesc('d')
            ->limit(400)
            ->pluck('d')
            ->all();

        $streak = 0;
        $cursor = now()->startOfDay();
        $dates = array_flip($readDates);
        if (!isset($dates[$cursor->toDateString()])) {
            $cursor->subDay(); // today untouched yet — a streak can still be alive from yesterday
        }
        while (isset($dates[$cursor->toDateString()])) {
            $streak++;
            $cursor->subDay();
        }

        // Totals. The all-time word sum scans read chapters' text — cached.
        $totals = NovelChapter::whereNotNull('read_at')
            ->selectRaw('COUNT(*) as chapters, SUM(LENGTH(description)) as chars')
            ->first();

        $readToday = NovelChapter::whereNotNull('read_at')
            ->where('read_at', '>=', now()->startOfDay())->count();
        $readWeek = NovelChapter::whereNotNull('read_at')
            ->where('read_at', '>=', now()->subDays(6)->startOfDay())->count();

        // Most-read novels in the window.
        $topNovels = NovelChapter::whereNotNull('read_at')
            ->where('read_at', '>=', $since)
            ->select('novel_id', DB::raw('COUNT(*) as chapters'), DB::raw('MAX(read_at) as last_read'))
            ->groupBy('novel_id')
            ->orderByDesc('chapters')
            ->limit(10)
            ->with('novel:id,name')
            ->get()
            ->filter(fn($r) => $r->novel !== null)
            ->values();

        $activeDays = count(array_filter($daily, fn($d) => $d['chapters'] > 0));

        return [
            'daily' => $daily,
            'streak' => $streak,
            'read_today' => $readToday,
            'read_week' => $readWeek,
            'read_total' => (int) ($totals->chapters ?? 0),
            'words_total' => (int) round(($totals->chars ?? 0) / self::AVG_WORD_LEN),
            'top_novels' => $topNovels,
            'window_days' => $days,
            'active_days' => $activeDays,
            'window_chapters' => array_sum(array_column($daily, 'chapters')),
            'window_words' => array_sum(array_column($daily, 'words')),
        ];
    }
}
