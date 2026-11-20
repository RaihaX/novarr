<?php

namespace App\Http\Controllers;

use App\Services\StorageUsage;

/**
 * GET /health/storage — Health → Storage (admin only): disk space, where it
 * goes (novels, manga, database, backups…) and the biggest series. Reads the
 * last `storage:measure` run (App\Services\StorageUsage); never measures on
 * page load. "Measure now" queues the command through the Commands runner
 * (CommandController 'storage_measure').
 */
class StorageUsageController extends Controller
{
    public function index(StorageUsage $usage)
    {
        return view('health.storage', [
            'report' => $usage->report(25),
            'lowFreePercent' => StorageUsage::lowFreePercent(),
        ]);
    }
}
