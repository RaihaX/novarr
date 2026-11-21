<?php

namespace App\Services;

use App\AuthEvent;
use App\Support\DeviceDescription;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The web browsers an account is signed in on (Account → Signed-in
 * browsers, "N browsers" on Settings → Users). Novarr's own small table
 * (browser_sessions), keyed by the SHA-256 of the session id, so it is the
 * same on every session driver and never reads the driver's storage.
 *
 *   start()  — at sign-in (LoginController::signInHere) and when the
 *              remember-me cookie starts a new session (IdentifyReader);
 *              an older row of the same browser (same sign-in device
 *              cookie) is replaced;
 *   touch()  — on signed-in requests, at most every TOUCH_EVERY_SECONDS
 *              (the time is kept in the session: no query in between);
 *   end()    — at sign-out, and when a session is found no longer valid;
 *   endAll() — "sign out everywhere" (App\User::signOutEverywhere).
 *
 * A row is live while its browser could still be signed in: last active
 * within the remember-me lifetime (auth.guards.web.remember). Older rows
 * are dropped by novarr:prune-auth-log. Stateless: Octane-safe.
 */
class BrowserSessions
{
    public const TOUCH_EVERY_SECONDS = 300;

    /** Session key: when this browser's row was last written (unix seconds). */
    public const SESSION_TOUCHED_AT = 'novarr_browser_touched_at';

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

    public function start(Request $request, User $user, ?int $signedInAt = null): void
    {
        $hash = static::currentHash($request);
        if ($hash === null) {
            return;
        }

        $deviceId = app(KnownDevices::class)->browserId($request);
        $deviceHash = $deviceId === null ? null : KnownDevices::hash('web:' . $deviceId);
        $now = now();

        DB::transaction(function () use ($request, $user, $hash, $deviceHash, $now, $signedInAt) {
            // The same browser's earlier session (it expired, or this is a new sign-in on it).
            if ($deviceHash !== null) {
                DB::table('browser_sessions')->where('user_id', $user->id)->where('device_hash', $deviceHash)->delete();
            }
            DB::table('browser_sessions')->where('session_hash', $hash)->delete();
            DB::table('browser_sessions')->insert([
                'user_id' => (int) $user->id,
                'session_hash' => $hash,
                'device_hash' => $deviceHash,
                'ip' => $request->ip(),
                'user_agent' => AuthEvent::truncate($request->userAgent()),
                'public' => PublicAddress::isPublicRequest($request),
                'signed_in_at' => $signedInAt !== null ? Carbon::createFromTimestamp($signedInAt) : $now,
                'last_active_at' => $now,
            ]);
        });
        $request->session()->put(self::SESSION_TOUCHED_AT, $now->getTimestamp());
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

    /** A signed-in request: refresh the row at most every TOUCH_EVERY_SECONDS (made if missing). */
    public function touch(Request $request, User $user): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->session();
        $touched = $session->get(self::SESSION_TOUCHED_AT);
        if (is_numeric($touched) && (int) $touched > now()->getTimestamp() - self::TOUCH_EVERY_SECONDS) {
            return;
        }

        $hash = static::currentHash($request);
        $updated = DB::table('browser_sessions')->where('session_hash', $hash)->where('user_id', $user->id)->update([
            'last_active_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => AuthEvent::truncate($request->userAgent()),
            'public' => PublicAddress::isPublicRequest($request),
        ]);
        if ($updated === 0) {
            // A session from before this table, or one whose row was pruned.
            $signedInAt = $session->get(User::SESSION_SIGNED_IN_AT);
            $this->start($request, $user, is_numeric($signedInAt) ? (int) $signedInAt : null);

            return;
        }
        $session->put(self::SESSION_TOUCHED_AT, now()->getTimestamp());
    }

    public function end(Request $request): void
    {
        $hash = static::currentHash($request);
        if ($hash !== null) {
            DB::table('browser_sessions')->where('session_hash', $hash)->delete();
        }
    }

    public function endAll(User $user): void
    {
        DB::table('browser_sessions')->where('user_id', $user->id)->delete();
    }

    /** The oldest last-active time a live row can have. */
    public static function liveSince(): Carbon
    {
        $minutes = (int) config('auth.guards.web.remember', 0);
        if ($minutes <= 0) {
            $minutes = (int) config('session.lifetime', 120);
        }

        return now()->subMinutes($minutes);
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
