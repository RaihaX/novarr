<?php

namespace App\Http\Controllers;

use App\Services\ReaderStats;
use App\Services\ReadState;
use Illuminate\Http\Request;

/**
 * The current reader's own stats across reading and listening (Stats 2.0,
 * App\Services\ReaderStats — the same figures as GET /api/v1/stats), with
 * a 30 / 90 / 365-day range (?range=). The owner sees their own here too;
 * the admin's Readers → reader page keeps the older summary
 * (App\Services\ReadingStats, stats/_body).
 */
class StatsController extends Controller
{
    public function index(Request $request, ReadState $readState, ReaderStats $stats)
    {
        $range = ReaderStats::normaliseRange($request->query('range', 30));

        return view('stats.index', [
            's' => $stats->for($readState->userId(), $range),
            'range' => $range,
        ]);
    }
}
