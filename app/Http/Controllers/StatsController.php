<?php

namespace App\Http\Controllers;

use App\NovelChapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reading statistics, derived entirely from read_at timestamps and the
 * stored chapter_texts.word_count (see ChapterText::countWords()).
 */
class StatsController extends Controller
{
    /**
     * Rough chars per word (incl. space) — only for rows the
     * chapters:word-counts backfill hasn't reached yet.
     */
    private const AVG_WORD_LEN = 6;

    /** Words read, preferring the stored count. */
    private const WORDS_SQL = 'SUM(COALESCE(chapter_texts.word_count, LENGTH(chapter_texts.content) / ' . self::AVG_WORD_LEN . ')) as words';

    public function index()
    {
        $stats = Cache::remember('reading_stats_v1', 900, fn() => $this->build());

        return view('stats.index', $stats);
    }

    private function build(): array
    {
        $days = 30;
        $since = now()->subDays($days - 1)->startOfDay();

        // Chapters + words read per day, last 30 days. Word counts live in
        // chapter_texts, hence the join.
        $rows = NovelChapter::query()
            ->leftJoin('chapter_texts', 'chapter_texts.novel_chapter_id', '=', 'novel_chapters.id')
            ->whereNotNull('read_at')
            ->where('read_at', '>=', $since)
            ->selectRaw('DATE(read_at) as d, COUNT(*) as chapters, ' . self::WORDS_SQL)
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
                'words' => (int) round($row->words ?? 0),
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

        // Totals (cached with the rest).
        $totals = NovelChapter::query()
            ->leftJoin('chapter_texts', 'chapter_texts.novel_chapter_id', '=', 'novel_chapters.id')
            ->whereNotNull('read_at')
            ->selectRaw('COUNT(*) as chapters, ' . self::WORDS_SQL)
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
            'words_total' => (int) round($totals->words ?? 0),
            'top_novels' => $topNovels,
            'window_days' => $days,
            'active_days' => $activeDays,
            'window_chapters' => array_sum(array_column($daily, 'chapters')),
            'window_words' => array_sum(array_column($daily, 'words')),
        ];
    }
}
