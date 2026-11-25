<?php

namespace App\Http\Controllers;

use App\Services\ReadState;
use App\Services\ReadingStats;

/**
 * The current reader's reading statistics. The figures are built (and
 * cached) by App\Services\ReadingStats, which the admin's Readers page
 * uses for any reader too.
 */
class StatsController extends Controller
{
    public function index(ReadState $readState, ReadingStats $stats)
    {
        return view('stats.index', $stats->for($readState->userId()));
    }
}
