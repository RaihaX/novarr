<?php

namespace App\Http\Controllers;

use App\Novel;
use App\Services\ReaderActivity;
use App\Services\ReadingStats;
use App\User;
use Illuminate\Http\Request;

/**
 * The admin's Readers pages (admin-only, so home network only — see
 * App\Http\Middleware\IdentifyReader): who is reading right now, every
 * account's figures, what is popular, the reading history and one reader's
 * page. Thin: the queries live in App\Services\ReaderActivity (and
 * App\Services\ReadingStats for one reader's stats). Works with sign-in on
 * or off (off: usually just the owner).
 */
class ReaderActivityController extends Controller
{
    /** GET /readers — the dashboard. */
    public function index(ReaderActivity $activity)
    {
        $accounts = $activity->accounts();

        return view('readers.index', [
            'accounts' => $accounts,
            'names' => $accounts->keyBy('id'),
            'overview' => $activity->overview(),
            'now' => $activity->readingNow(),
            'recent' => $activity->recentSessions(),
        ]);
    }

    /** GET /readers/now — the "Reading now" panel alone, for its 30-second refresh. */
    public function now(ReaderActivity $activity)
    {
        return response()->view('readers._now', [
            'now' => $activity->readingNow(),
            'names' => $activity->accounts()->keyBy('id'),
        ]);
    }

    /**
     * GET /readers/history — every read, newest first, filtered by account,
     * series and (opt-in) bulk marks. Unknown or invalid filter values are
     * ignored, never an error.
     */
    public function history(Request $request, ReaderActivity $activity)
    {
        $accounts = $activity->accounts();
        $names = $accounts->keyBy('id');

        $userId = $request->integer('user') ?: null;
        if ($userId !== null && !$names->has($userId)) {
            $userId = null;
        }
        $novelId = $request->integer('novel') ?: null;
        $novel = $novelId === null ? null : Novel::find($novelId, ['id', 'name']);
        $bulk = $request->boolean('bulk');

        $ids = $userId !== null ? [$userId] : $accounts->pluck('id')->map(fn($id) => (int) $id)->all();
        $rows = $activity->historyPage($ids, $novel?->id, $bulk);

        return view('readers.history', [
            'accounts' => $accounts,
            'names' => $names,
            'rows' => $rows,
            // Collapsed within this page only: a session that straddles a
            // page boundary shows as two (once per page) — accepted, so the
            // page stays one indexed range read of HISTORY_PER_PAGE rows.
            'sessions' => ReaderActivity::sessions($rows->items()),
            'userId' => $userId,
            'novel' => $novel,
            'bulk' => $bulk,
        ]);
    }

    /** GET /readers/{id} — one reader: their stats, positions, devices and recent history. */
    public function show($id, ReaderActivity $activity, ReadingStats $stats)
    {
        $user = User::findOrFail($id);

        return view('readers.show', $stats->for((int) $user->id) + [
            'reader' => $user,
            'names' => collect([(int) $user->id => $user]),
            'now' => $activity->readingNow([(int) $user->id]),
            'inProgress' => $activity->inProgress((int) $user->id),
            'devices' => $activity->devices($user),
            'recent' => $activity->recentSessions([(int) $user->id], 20),
        ]);
    }
}
