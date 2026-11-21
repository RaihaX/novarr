<?php

namespace App\Services;

use App\Auth\BrowserGuard;
use App\AuthEvent;
use App\Support\DeviceDescription;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The web browsers an account is signed in on (Account → Signed-in
 * browsers, "N browsers" on Settings → Users). Novarr's own small table
 * (browser_sessions), keyed by the SHA-256 of the session id, so it is the
 * same on every session driver and never reads the driver's storage.
 *
 *   start()   — at sign-in (LoginController::signInHere) and when the
 *               remember-me cookie starts a new session (IdentifyReader);
 *               an older row of the same browser (same sign-in device
 *               cookie) is replaced, with its remember-me row;
 *   touch()   — on signed-in requests, at most every TOUCH_EVERY_SECONDS
 *               (the time is kept in the session: no query in between);
 *               the browser's remember-me row is kept alive with it;
 *   end()     — at sign-out, and when a session is found no longer valid;
 *   signOut() — "Sign out" on one row: that browser's session is ended in
 *               the session store at once (the row keeps the session id
 *               encrypted with the app key, session_ref) and its
 *               remember-me row deleted; no other browser is touched;
 *   endAll()  — "sign out everywhere" (App\User::signOutEverywhere): every
 *               row, session and remember-me row of the account.
 *
 * Each row links to the browser's remember-me row (App\Services\RememberTokens,
 * remember_tokens.browser_session_id). A row is live while its browser
 * could still be signed in: active within the remember-me idle limit
 * (RememberTokens::IDLE_DAYS). Older rows are dropped by
 * novarr:prune-auth-log. Stateless: Octane-safe.
 */
class BrowserSessions
{
    public const TOUCH_EVERY_SECONDS = 300;

    /** Session key: when this browser's row was last written (unix seconds). */
    public const SESSION_TOUCHED_AT = 'novarr_browser_touched_at';

    /** Session key: the id of this browser's row (set when it is written). */
    public const SESSION_ROW = 'novarr_browser_row';

    public static function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** The current request's session hash, or null without a session. */
    public static function currentHash(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }
        $id = (string) $request->session()->getId();

        return $id === '' ? null : static::hash($id);
    }

    /** Write this browser's row (replacing an older one of the same browser). Returns its id, null without a session. */
    public function start(Request $request, User $user, ?int $signedInAt = null): ?int
    {
        $hash = static::currentHash($request);
        if ($hash === null) {
            return null;
        }

        $deviceId = app(KnownDevices::class)->browserId($request);
        $deviceHash = $deviceId === null ? null : KnownDevices::hash('web:' . $deviceId);
        $now = now();
        $session = $request->session();
        $rememberId = is_numeric($id = $session->get(BrowserGuard::SESSION_REMEMBER_ID)) ? (int) $id : null;

        $rowId = DB::transaction(function () use ($request, $user, $hash, $deviceHash, $now, $signedInAt, $session, $rememberId) {
            // The same browser's earlier session (it expired, or this is a new
            // sign-in on it): its line goes, and so does its remember-me row
            // unless it is the one this session signed in with.
            $old = DB::table('browser_sessions')
                ->where(fn($q) => $q->where('session_hash', $hash)
                    ->when($deviceHash !== null, fn($q) => $q->orWhere(fn($q) => $q->where('user_id', $user->id)->where('device_hash', $deviceHash))))
                ->pluck('id')->map(fn($id) => (int) $id)->all();
            app(RememberTokens::class)->deleteForBrowsers($old, $rememberId);
            DB::table('browser_sessions')->whereIn('id', $old)->delete();

            $rowId = (int) DB::table('browser_sessions')->insertGetId([
                'user_id' => (int) $user->id,
                'session_hash' => $hash,
                'session_ref' => Crypt::encryptString((string) $session->getId()),
                'device_hash' => $deviceHash,
                'ip' => $request->ip(),
                'user_agent' => AuthEvent::truncate($request->userAgent()),
                'public' => PublicAddress::isPublicRequest($request),
                'signed_in_at' => $signedInAt !== null ? Carbon::createFromTimestamp($signedInAt) : $now,
                'last_active_at' => $now,
            ]);
            if ($rememberId !== null) {
                app(RememberTokens::class)->link($rememberId, $rowId);
            }

            return $rowId;
        });
        $session->put(self::SESSION_TOUCHED_AT, $now->getTimestamp());
        $session->put(self::SESSION_ROW, $rowId);

        return $rowId;
    }

    /** Tie the current session's row to the browser's sign-in device cookie (its hash). */
    public function setDevice(Request $request, string $deviceHash): void
    {
        $hash = static::currentHash($request);
        if ($hash === null) {
            return;
        }
        $row = DB::table('browser_sessions')->where('session_hash', $hash)->first(['id', 'user_id', 'device_hash']);
        if ($row === null || $row->device_hash === $deviceHash) {
            return;
        }
        DB::table('browser_sessions')->where('user_id', $row->user_id)->where('device_hash', $deviceHash)->where('id', '!=', $row->id)->delete();
        DB::table('browser_sessions')->where('id', $row->id)->update(['device_hash' => $deviceHash]);
    }

    /**
     * A signed-in request: refresh the row at most every TOUCH_EVERY_SECONDS
     * (made if missing for a session from before this table). False when
     * this session's row was removed by "Sign out" on it (the session is
     * then no longer signed in) — a fallback for when the session store
     * could not be reached at that moment.
     */
    public function touch(Request $request, User $user): bool
    {
        if (!$request->hasSession()) {
            return true;
        }
        $session = $request->session();
        $touched = $session->get(self::SESSION_TOUCHED_AT);
        if (is_numeric($touched) && (int) $touched > now()->getTimestamp() - self::TOUCH_EVERY_SECONDS) {
            return true;
        }

        $hash = static::currentHash($request);
        $updated = DB::table('browser_sessions')->where('session_hash', $hash)->where('user_id', $user->id)->update([
            'last_active_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => AuthEvent::truncate($request->userAgent()),
            'public' => PublicAddress::isPublicRequest($request),
        ]);
        // MySQL/MariaDB report changed rows: the same values in the same
        // second change nothing although the row is there. Ask for the row.
        if ($updated === 0 && DB::table('browser_sessions')->where('session_hash', $hash)->where('user_id', $user->id)->exists()) {
            $updated = 1;
        }
        if ($updated === 0) {
            if ($session->has(self::SESSION_ROW)) {
                // It had a row, and someone signed it out.
                return false;
            }
            // A session from before this table.
            $signedInAt = $session->get(User::SESSION_SIGNED_IN_AT);
            $this->start($request, $user, is_numeric($signedInAt) ? (int) $signedInAt : null);

            return true;
        }
        $session->put(self::SESSION_TOUCHED_AT, now()->getTimestamp());
        // The browser is in use: its remember-me row stays alive (30 days sliding).
        if (is_numeric($rememberId = $session->get(BrowserGuard::SESSION_REMEMBER_ID))) {
            app(RememberTokens::class)->touch((int) $rememberId);
        }

        return true;
    }

    public function end(Request $request): void
    {
        $hash = static::currentHash($request);
        if ($hash !== null) {
            $ids = DB::table('browser_sessions')->where('session_hash', $hash)->pluck('id')->map(fn($id) => (int) $id)->all();
            app(RememberTokens::class)->deleteForBrowsers($ids);
            DB::table('browser_sessions')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * "Sign out" on one row of $user's (not the current browser's: that is
     * an ordinary sign-out): its session ends in the session store, its
     * remember-me row and the line go. False when there is no such row.
     */
    public function signOut(Request $request, User $user, int $rowId): bool
    {
        $row = DB::table('browser_sessions')->where('id', $rowId)->where('user_id', $user->id)->first();
        if ($row === null) {
            return false;
        }
        $this->destroySessions($request, [$row]);
        app(RememberTokens::class)->deleteForBrowsers([(int) $row->id]);
        DB::table('browser_sessions')->where('id', $row->id)->delete();

        return true;
    }

    /** Every browser of $user: sessions ended in the store, remember-me rows and lines deleted. */
    public function endAll(User $user, ?Request $request = null): void
    {
        $rows = DB::table('browser_sessions')->where('user_id', $user->id)->get(['id', 'session_ref', 'session_hash']);
        $this->destroySessions($request, $rows->all());
        app(RememberTokens::class)->deleteForUser($user);
        DB::table('browser_sessions')->where('user_id', $user->id)->delete();
    }

    /**
     * End these rows' sessions in the session store (any driver), never the
     * current request's own (its controller signs it out or in again).
     */
    private function destroySessions(?Request $request, array $rows): void
    {
        $request ??= app()->bound('request') ? app('request') : null;
        $current = $request === null ? null : static::currentHash($request);
        $handler = null;
        foreach ($rows as $row) {
            if ($row->session_ref === null || ($current !== null && hash_equals((string) $row->session_hash, $current))) {
                continue;
            }
            try {
                $handler ??= app('session')->driver()->getHandler();
                $handler->destroy(Crypt::decryptString($row->session_ref));
            } catch (\Throwable $e) {
                // A key change or a store that is down: the session ends at
                // its next request instead (sessions_valid_after, or no row).
                Log::warning('Could not end a signed-out browser session: ' . $e->getMessage());
            }
        }
    }

    /** The oldest last-active time a live row can have: the remember-me idle limit. */
    public static function liveSince(): Carbon
    {
        return now()->subDays(RememberTokens::IDLE_DAYS);
    }

    /** $user's live browsers, most recently active first, each with device, address and isCurrent. */
    public function forUser(User $user, Request $request): Collection
    {
        $current = static::currentHash($request);

        return DB::table('browser_sessions')
            ->where('user_id', $user->id)
            ->where('last_active_at', '>=', static::liveSince())
            ->orderByDesc('last_active_at')->orderByDesc('id')
            ->get()
            ->map(fn($row) => (object) [
                'id' => (int) $row->id,
                'device' => DeviceDescription::from($row->user_agent),
                'ip' => $row->ip,
                'public' => (bool) $row->public,
                'signed_in_at' => $row->signed_in_at ? Carbon::parse($row->signed_in_at) : null,
                'last_active_at' => $row->last_active_at ? Carbon::parse($row->last_active_at) : null,
                'current' => $current !== null && hash_equals($row->session_hash, $current),
            ]);
    }

    /** Live browsers per user id (Settings → Users). @return Collection<int, int> */
    public function counts(): Collection
    {
        return DB::table('browser_sessions')
            ->where('last_active_at', '>=', static::liveSince())
            ->selectRaw('user_id, COUNT(*) AS n')->groupBy('user_id')
            ->pluck('n', 'user_id');
    }

    /** Drop rows past the remember-me lifetime. Returns how many. */
    public function prune(): int
    {
        return DB::table('browser_sessions')->where(fn($q) => $q->where('last_active_at', '<', static::liveSince())->orWhereNull('last_active_at'))->delete();
    }
}
