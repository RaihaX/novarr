<?php

namespace App\Http\Controllers;

use App\Services\SourceHealth;

/**
 * GET /health/sources — Health → Sources (admin only): each source's
 * success rate for chapter downloads, chapter lists and searches over the
 * last 24 hours and 7 days, worst first (App\Services\SourceHealth).
 * Database and cache only: never a request to a site.
 */
class SourceHealthController extends Controller
{
    public function index(SourceHealth $health)
    {
        return view('health.sources', [
            'sources' => $health->overview(),
            'thresholds' => [
                'min_attempts' => (int) config('novarr.source_health.min_attempts', 20),
                'failing_below' => (float) config('novarr.source_health.failing_below', 0.5),
                'degraded_below' => (float) config('novarr.source_health.degraded_below', 0.85),
            ],
        ]);
    }
}
