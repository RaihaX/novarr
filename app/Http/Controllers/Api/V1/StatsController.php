<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReaderStats;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/stats?range=30|90|365 — the signed-in reader's own stats
 * across reading and listening (App\Services\ReaderStats; the website's
 * /stats renders the same figures). A reader route (App\Support\RouteAccess):
 * every reader sees only their own. `range` defaults to 30; any other value
 * is treated as 30. Cached for 5 minutes per reader and range.
 *
 * Dates are YYYY-MM-DD in `timezone` (the server's app timezone), times
 * ISO-8601 UTC. Bulk "mark all read" chapters count in headline
 * chapters_read / words_read only, never as activity. Reading "minutes"
 * are estimates (250 words a minute, 5 minutes a manga chapter) so reading
 * and listening share one scale; listening seconds are measured.
 *
 *   {
 *     "generated_at": "2026-11-27T09:40:00Z",
 *     "timezone": "Australia/Perth",
 *     "today": "2026-11-27",
 *     "range": 30,
 *     "headline": {
 *       "chapters_read": 1234, "words_read": 2500000,            // all time
 *       "series_finished": 12, "books_finished": 3,               // books ⊂ series
 *       "audiobooks_finished": 2, "listening_seconds": 36000,      // all time
 *       "current_streak": 5, "best_streak": 21, "active_today": true,
 *       "chapters_today": 3, "words_today": 9000, "listening_seconds_today": 1200,
 *       "chapters_week": 20, "listening_seconds_week": 5400,       // last 7 days
 *       "chapters_per_day": 4.2, "words_per_day": 9000.5,          // over the range
 *       "listening_minutes_per_day": 12.3
 *     },
 *     "range_totals": {"days": 30, "chapters": 126, "words": 270015, "listening_seconds": 22140, "active_days": 18},
 *     "daily":   [{"date": "2026-10-29", "chapters": 0, "words": 0, "listening_seconds": 0}, ...],  // `range` entries, oldest first
 *     "weekly":  [{"start": "2026-10-27", "chapters": 10, "words": 20000, "listening_seconds": 600}, ...], // Monday weeks covering the range
 *     "heatmap": {"start": "2025-11-24", "end": "2026-11-27", "max_minutes": 180,       // start: a Monday ~52 weeks back
 *                 "days": [{"date": "2025-11-24", "chapters": 0, "minutes": 0, "level": 0}, ...]}, // level 0..4
 *     "by_medium": [{"medium": "novel"|"manga"|"book"|"audiobook", "label": "Web novels", "chapters": 80,
 *                    "words": 200000, "listening_seconds": 0, "minutes": 800, "share": 0.5}, ...],    // range; share by minutes
 *     "top_genres":     [{"name": "Fantasy", "chapters": 60, "minutes": 700}, ...],                    // range, up to 8
 *     "top_series":     [{"id": 12, "name": "Shadow Slave", "medium": "novel", "chapters": 40,
 *                         "words": 120000, "last_read_at": "2026-11-27T08:00:00Z"}, ...],            // range, up to 10
 *     "top_audiobooks": [{"id": 3, "title": "…", "author": "…", "novel_id": 55, "listening_seconds": 14000}, ...], // range, up to 10
 *     "hours":    [{"hour": 0, "chapters": 1, "minutes": 10}, ...],                // 24 entries, range
 *     "weekdays": [{"day": 1, "label": "Mon", "chapters": 20, "minutes": 140}, ...], // 7 entries (1 = Monday), range
 *     "year": {"year": 2026, "chapters": 900, "words": 2000000, "listening_seconds": 30000,
 *              "series_finished": 8, "books_finished": 2, "audiobooks_finished": 1, "active_days": 200,
 *              "top_genre": "Fantasy"|null, "top_series": {"id": 12, "name": "…"}|null,
 *              "busiest_month": {"month": "2026-11", "label": "November", "chapters": 140}|null},
 *     "milestones": [{"key": "chapters_100", "title": "Century", "detail": "Read 100 chapters",
 *                     "earned": true, "progress": 1.0, "current": 1234, "target": 100}, ...]
 *   }
 */
class StatsController extends Controller
{
    /** The top-level keys of the answer, in order (the contract the apps decode). */
    public const KEYS = [
        'generated_at', 'timezone', 'today', 'range', 'headline', 'range_totals', 'daily', 'weekly',
        'heatmap', 'by_medium', 'top_genres', 'top_series', 'top_audiobooks', 'hours', 'weekdays',
        'year', 'milestones',
    ];

    public function index(Request $request, ReaderStats $stats, CurrentReader $reader): JsonResponse
    {
        return response()->json($stats->for($reader->id(), ReaderStats::normaliseRange($request->query('range', 30))));
    }
}
