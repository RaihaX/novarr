<?php

namespace App\Services;

use App\AuthEvent;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Username (or email) + password checks, shared by the web login, the API's
 * POST /api/v1/auth/token and HTTP Basic (OPDS). Throttled per
 * username + IP: MAX_ATTEMPTS failures a minute, through the rate limiter's
 * cache store (file in production). Only failures count; a success clears them.
 *
 * On the public address (App\Support\PublicAddress) there is also a per-IP
 * cap across every credential entry point — web login, POST /api/v1/auth/token,
 * HTTP Basic (OPDS) and invite acceptance: PUBLIC_MAX_ATTEMPTS per
 * PUBLIC_DECAY_SECONDS. Failed sign-ins and every invite acceptance count;
 * a success does not clear it (one good account must not reset a guesser).
 * Home-network requests keep only the per-name limit.
 *
 * The lock, whatever the address: ACCOUNT_MAX_FAILURES wrong passwords for
 * one sign-in name as typed (normalised; a name, an email or a name nobody
 * has alike) within ACCOUNT_WINDOW_SECONDS (from anywhere) lock password
 * sign-in with that name ON THE PUBLIC ADDRESS for ACCOUNT_LOCK_SECONDS
 * (429). Wrong second-step codes (App\Services\SecondStep) lock the account
 * itself, under every name. The home network is never locked out, and a
 * sign-in from it clears the locks. Known and unknown names get the same
 * lock and the same work (a password hash check), so the answers never tell
 * whether a name or email belongs to an account.
 *
 * With a second step to follow ($complete false), a right password records
 * nothing and clears no limit: the sign-in is not done until the second
 * step is (completeSignIn()). Otherwise a known password would reset the
 * count of wrong codes each time it is typed.
 *
 * Given the request, every outcome is written to the sign-in history
 * (App\AuthEvent): successes, failures (the account only when the name
 * matches one) and refusals by a limit (at most one line a minute per limit
 * and name or address, so a flood does not fill the table).
 */
class Credentials
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /** Public address: credential attempts per IP per window. */
    public const PUBLIC_MAX_ATTEMPTS = 20;

    public const PUBLIC_DECAY_SECONDS = 600;

    /** Wrong passwords for one account (any address) before the public-address lock. */
    public const ACCOUNT_MAX_FAILURES = 10;

    public const ACCOUNT_WINDOW_SECONDS = 900;

    /** How long the account's password sign-in stays refused on the public address. */
    public const ACCOUNT_LOCK_SECONDS = 900;

    public const WRONG = 'Wrong name or password.';

    public const DISABLED = 'This account is turned off. Ask the person who runs this Novarr to turn it back on.';

    public const NO_PASSWORD = 'This account has no password yet. Set one in Settings → Users, or with php artisan novarr:password.';

    /**
     * The user, or an error: ['error' => message, 'status' => 422|429, 'retry_after' => seconds].
     *
     * $publicHost: the request came in on the public address (adds the
     * per-IP cap; 'public_cap' => true in the error when that cap refused it).
     * $request / $via (web | api | basic): where the attempt came in, for
     * the sign-in history; no request = nothing is recorded.
     *
     * 'account_lock' => true in the error: the account's public-address lock refused it.
     *
     * @return User|array{error: string, status: int, retry_after?: int, public_cap?: bool, account_lock?: bool}
     */
    public function attempt(?string $username, ?string $password, ?string $ip, bool $publicHost = false, ?Request $request = null, string $via = 'web', bool $complete = true): User|array
    {
        if ($publicHost && ($seconds = static::publicCapRetryAfter($ip)) !== null) {
            static::recordLimited($request, $via, 'address', $username, static::publicCapKey($ip));

            return static::tooMany($seconds) + ['public_cap' => true];
        }

        $key = static::throttleKey($username, $ip);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            static::recordLimited($request, $via, 'name', $username, $key);

            return static::tooMany(RateLimiter::availableIn($key));
        }

        $user = User::findForSignIn($username);
        // The lock (public address only) is kept per name as typed (wrong
        // passwords count there), whether or not it matches an account, so a
        // name, an email and a name nobody has all behave the same way — the
        // answers never tell that an email belongs to an account. Wrong
        // second-step codes (the password was right) lock the account itself.
        $name = User::normaliseUsername($username);
        $lockedFor = $publicHost ? (static::accountLockRetryAfter($name) ?? ($user !== null ? static::accountLockRetryAfter($user) : null)) : null;
        if ($lockedFor !== null) {
            static::hitPublicCap($ip);
            static::recordLimited($request, $via, 'account', $username, static::accountLockKey($name));

            return static::tooMany($lockedFor) + ['account_lock' => true];
        }

        $ok = $user !== null && is_string($password) && $password !== '' && static::check($password, (string) $user->password);
        if (!$ok) {
            // Same work whether or not the name exists, so timing does not tell.
            if ($user === null) {
                static::check((string) $password, '$2y$12$' . str_repeat('a', 53));
            }
            static::recordFailure($username, $ip, $publicHost);
            static::recordFailed($request, $via, $user, $user === null ? 'unknown_name' : 'wrong');
            static::countAccountFailure($name, $request, $via, $user);

            return ['error' => self::WRONG, 'status' => 422];
        }

        // The password is right: say why the account still cannot sign in.
        if ($user->password_set_at === null) {
            static::recordFailed($request, $via, $user, 'no_password');

            return ['error' => self::NO_PASSWORD, 'status' => 422];
        }
        if ($user->isDisabled()) {
            static::recordFailed($request, $via, $user, 'disabled');

            return ['error' => self::DISABLED, 'status' => 422];
        }

        if ($complete) {
            static::completeSignIn($user, $username, $ip, $publicHost, $via === 'basic' ? null : $request, $via);
        }

        return $user;
    }

    /**
     * A sign-in is done (the password, or the password and the second step,
     * or a passkey): the limits for the name and account are cleared, at
     * home the account's lock too, and it goes into the history (no
     * $request: nothing recorded — Basic checks the password on every
     * request, those successes are not history).
     */
    public static function completeSignIn(User $user, ?string $username, ?string $ip, bool $publicHost, ?Request $request, string $via, array $detail = []): void
    {
        if ($username !== null) {
            RateLimiter::clear(static::throttleKey($username, $ip));
            RateLimiter::clear(static::accountFailuresKey(User::normaliseUsername($username)));
        }
        RateLimiter::clear(static::accountFailuresKey($user));
        if (!$publicHost) {
            // The owner at home can always get back in, and that unlocks the
            // account and the names it signs in with.
            RateLimiter::clear(static::accountLockKey($user));
            foreach (array_filter([$user->username, $user->email, $username]) as $known) {
                RateLimiter::clear(static::accountLockKey(User::normaliseUsername((string) $known)));
                RateLimiter::clear(static::accountFailuresKey(User::normaliseUsername((string) $known)));
            }
        }
        if ($request !== null) {
            AuthEvent::record(AuthEvent::SIGN_IN, $request, (int) $user->id, static::detail($via, $request) + $detail);
        }
    }

    /** History: a wrong second-step code (or passkey) for $user. */
    public static function secondStepFailed(User $user, Request $request, string $via, string $method, string $purpose = 'sign_in'): void
    {
        // (Counted toward the limits before the answer was checked: SecondStep::check.)
        AuthEvent::record(AuthEvent::SECOND_STEP_FAILED, $request, (int) $user->id, ['via' => $via, 'method' => $method] + ($purpose === 'confirm' ? ['purpose' => 'confirm'] : []));
    }

    /**
     * The limit that refuses an attempt for $username from $ip right now
     * (before any check), as attempt()'s 429 error; null when none does.
     * For steps that come before the password check (the human check).
     */
    public static function refusal(?string $username, ?string $ip, bool $publicHost): ?array
    {
        if ($publicHost && ($seconds = static::publicCapRetryAfter($ip)) !== null) {
            return static::tooMany($seconds) + ['public_cap' => true];
        }
        $key = static::throttleKey($username, $ip);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return static::tooMany(RateLimiter::availableIn($key));
        }

        return null;
    }

    /** Count one failed attempt in the per-name limit and, on the public address, the per-IP cap. */
    public static function recordFailure(?string $username, ?string $ip, bool $publicHost): void
    {
        RateLimiter::hit(static::throttleKey($username, $ip), self::DECAY_SECONDS);
        if ($publicHost) {
            static::hitPublicCap($ip);
        }
    }

    /** History: a failed attempt (the account only when the name matched one). */
    public static function recordFailed(?Request $request, string $via, ?User $user, string $reason): void
    {
        if ($request === null) {
            return;
        }
        $detail = ['via' => $via, 'reason' => $reason];
        if ($user === null) {
            $detail['unknown_name'] = true;
        }
        AuthEvent::record(AuthEvent::SIGN_IN_FAILED, $request, $user?->id, $detail);
    }

    /**
     * History: an attempt refused by a limit ($limit: address | name |
     * account), at most once a minute per limit key.
     */
    public static function recordLimited(?Request $request, string $via, string $limit, ?string $username, string $limitKey): void
    {
        if ($request === null || !Cache::add('novarr-authlog-limited:' . sha1($limit . '|' . $limitKey), 1, 60)) {
            return;
        }
        $user = $username === null ? null : User::findForSignIn($username);
        AuthEvent::record(AuthEvent::RATE_LIMITED, $request, $user?->id, ['via' => $via, 'limit' => $limit]);
    }

    /** History detail of a sign-in: how, and the app's device name when it gave one. */
    private static function detail(string $via, Request $request): array
    {
        $detail = ['via' => $via];
        $device = trim((string) $request->input('device_name', ''));
        if ($via === 'api' && $device !== '') {
            $detail['device'] = AuthEvent::truncate($device, 120);
        }

        return $detail;
    }

    /**
     * Seconds the password sign-in of $subject (an account, or a normalised
     * name that matches none) stays refused on the public address, null when
     * it is not locked.
     */
    public static function accountLockRetryAfter(User|string $subject): ?int
    {
        $key = static::accountLockKey($subject);

        return RateLimiter::tooManyAttempts($key, 1) ? max(1, RateLimiter::availableIn($key)) : null;
    }

    /** One wrong password or code for $subject (any address); the ACCOUNT_MAX_FAILURES-th locks it. */
    /**
     * One wrong password (for a typed name) or code (for an account) for
     * $subject, from any address; the ACCOUNT_MAX_FAILURES-th locks it.
     * $user: the account a typed name matches, for the history only.
     */
    public static function countAccountFailure(User|string $subject, ?Request $request, string $via, ?User $user = null): void
    {
        $failures = static::accountFailuresKey($subject);
        RateLimiter::hit($failures, self::ACCOUNT_WINDOW_SECONDS);
        if (RateLimiter::attempts($failures) >= self::ACCOUNT_MAX_FAILURES && static::accountLockRetryAfter($subject) === null) {
            RateLimiter::hit(static::accountLockKey($subject), self::ACCOUNT_LOCK_SECONDS);
            $detail = ['via' => $via, 'seconds' => self::ACCOUNT_LOCK_SECONDS];
            $account = $subject instanceof User ? $subject : $user;
            AuthEvent::record(AuthEvent::ACCOUNT_LOCKED, $request, $account?->id !== null ? (int) $account->id : null,
                $account !== null ? $detail : $detail + ['unknown_name' => true]);
        }
    }

    public static function accountFailuresKey(User|string $subject): string
    {
        return 'novarr-account-failures:' . static::subjectKey($subject);
    }

    public static function accountLockKey(User|string $subject): string
    {
        return 'novarr-account-lock:' . static::subjectKey($subject);
    }

    /** An account by id; a name that matches none by the hash of its normalised form. */
    private static function subjectKey(User|string $subject): string
    {
        return $subject instanceof User ? (string) (int) $subject->id : 'name:' . sha1(User::normaliseUsername($subject));
    }

    /**
     * Seconds to wait when the public address's per-IP cap or $user's lock
     * there refuses $request now; null on the home network or when neither
     * does. Every step that checks a password, code, passkey or recovery
     * code for a known account asks this first.
     */
    public static function publicRefusal(Request $request, ?User $user): ?int
    {
        if (!PublicAddress::isPublicRequest($request)) {
            return null;
        }

        return static::publicCapRetryAfter($request->ip()) ?? ($user === null ? null : static::accountLockRetryAfter($user));
    }

    /** The JSON of a 429 from the auth endpoints (contract v1.1), with its Retry-After header. */
    public static function tooManyJson(int $seconds, ?string $message = null): \Illuminate\Http\JsonResponse
    {
        $seconds = max(1, $seconds);

        return response()->json([
            'code' => 'throttled',
            'message' => $message ?? static::tooManyMessage($seconds),
            'retry_after' => $seconds,
        ], 429, ['Retry-After' => (string) $seconds]);
    }

    /** The 429 error: the same sentence for every limit. */
    public static function tooMany(int $seconds): array
    {
        $seconds = max(1, $seconds);

        return [
            'error' => static::tooManyMessage($seconds),
            'status' => 429,
            'retry_after' => $seconds,
        ];
    }

    public static function tooManyMessage(int $seconds): string
    {
        return "Too many sign-in attempts. Try again in {$seconds} " . ($seconds === 1 ? 'second.' : 'seconds.');
    }

    /** Seconds until $ip may try again on the public address, null when it may now. */
    public static function publicCapRetryAfter(?string $ip): ?int
    {
        $key = static::publicCapKey($ip);

        return RateLimiter::tooManyAttempts($key, self::PUBLIC_MAX_ATTEMPTS) ? max(1, RateLimiter::availableIn($key)) : null;
    }

    /** Count one credential attempt from $ip on the public address. */
    public static function hitPublicCap(?string $ip): void
    {
        RateLimiter::hit(static::publicCapKey($ip), self::PUBLIC_DECAY_SECONDS);
    }

    public static function publicCapKey(?string $ip): string
    {
        return 'novarr-public-credentials:' . sha1((string) $ip);
    }

    /** Hash::check that treats a hash of another algorithm (a legacy account) as a mismatch. */
    public static function check(string $plain, string $hash): bool
    {
        try {
            return Hash::check($plain, $hash);
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public static function throttleKey(?string $username, ?string $ip): string
    {
        return 'novarr-login:' . sha1(User::normaliseUsername($username) . '|' . $ip);
    }
}
