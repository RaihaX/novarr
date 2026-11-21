<?php

namespace App\Services;

use App\AuthEvent;
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
 * Per account, whatever the address: ACCOUNT_MAX_FAILURES wrong passwords
 * for one existing account within ACCOUNT_WINDOW_SECONDS (from anywhere)
 * lock that account's password sign-in ON THE PUBLIC ADDRESS for
 * ACCOUNT_LOCK_SECONDS (429, every entry point that takes a password). The
 * home network is never locked out, and a sign-in from it clears the lock.
 * Unknown names have no lock (they behave as before).
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
    public function attempt(?string $username, ?string $password, ?string $ip, bool $publicHost = false, ?Request $request = null, string $via = 'web'): User|array
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
        // The account's lock (public address only; unknown names have none).
        if ($publicHost && $user !== null && ($seconds = static::accountLockRetryAfter($user)) !== null) {
            static::hitPublicCap($ip);
            static::recordLimited($request, $via, 'account', $username, static::accountLockKey($user));

            return static::tooMany($seconds) + ['account_lock' => true];
        }

        $ok = $user !== null && is_string($password) && $password !== '' && static::check($password, (string) $user->password);
        if (!$ok) {
            // Same work whether or not the name exists, so timing does not tell.
            if ($user === null) {
                static::check((string) $password, '$2y$12$' . str_repeat('a', 53));
            }
            static::recordFailure($username, $ip, $publicHost);
            static::recordFailed($request, $via, $user, $user === null ? 'unknown_name' : 'wrong');
            if ($user !== null) {
                static::countAccountFailure($user, $request, $via);
            }

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

        RateLimiter::clear($key);
        RateLimiter::clear(static::accountFailuresKey($user));
        if (!$publicHost) {
            // The owner at home can always get back in, and that unlocks the account.
            RateLimiter::clear(static::accountLockKey($user));
        }
        // Basic (OPDS) checks the password on every request: those
        // successes are not history, only the failures are.
        if ($request !== null && $via !== 'basic') {
            AuthEvent::record(AuthEvent::SIGN_IN, $request, (int) $user->id, static::detail($via, $request));
        }

        return $user;
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

    /** Seconds the account's password sign-in stays refused on the public address, null when it is not locked. */
    public static function accountLockRetryAfter(User $user): ?int
    {
        $key = static::accountLockKey($user);

        return RateLimiter::tooManyAttempts($key, 1) ? max(1, RateLimiter::availableIn($key)) : null;
    }

    /** One wrong password for $user (any address); the ACCOUNT_MAX_FAILURES-th locks it. */
    private static function countAccountFailure(User $user, ?Request $request, string $via): void
    {
        $failures = static::accountFailuresKey($user);
        RateLimiter::hit($failures, self::ACCOUNT_WINDOW_SECONDS);
        if (RateLimiter::attempts($failures) >= self::ACCOUNT_MAX_FAILURES && static::accountLockRetryAfter($user) === null) {
            RateLimiter::hit(static::accountLockKey($user), self::ACCOUNT_LOCK_SECONDS);
            AuthEvent::record(AuthEvent::ACCOUNT_LOCKED, $request, (int) $user->id, ['via' => $via, 'seconds' => self::ACCOUNT_LOCK_SECONDS]);
        }
    }

    public static function accountFailuresKey(User $user): string
    {
        return 'novarr-account-failures:' . (int) $user->id;
    }

    public static function accountLockKey(User $user): string
    {
        return 'novarr-account-lock:' . (int) $user->id;
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
