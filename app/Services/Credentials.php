<?php

namespace App\Services;

use App\User;
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
 */
class Credentials
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /** Public address: credential attempts per IP per window. */
    public const PUBLIC_MAX_ATTEMPTS = 20;

    public const PUBLIC_DECAY_SECONDS = 600;

    public const WRONG = 'Wrong name or password.';

    public const DISABLED = 'This account is turned off. Ask the person who runs this Novarr to turn it back on.';

    public const NO_PASSWORD = 'This account has no password yet. Set one in Settings → Users, or with php artisan novarr:password.';

    /**
     * The user, or an error: ['error' => message, 'status' => 422|429, 'retry_after' => seconds].
     *
     * $publicHost: the request came in on the public address (adds the
     * per-IP cap; 'public_cap' => true in the error when that cap refused it).
     *
     * @return User|array{error: string, status: int, retry_after?: int, public_cap?: bool}
     */
    public function attempt(?string $username, ?string $password, ?string $ip, bool $publicHost = false): User|array
    {
        if ($publicHost && ($seconds = static::publicCapRetryAfter($ip)) !== null) {
            return static::tooMany($seconds) + ['public_cap' => true];
        }

        $key = static::throttleKey($username, $ip);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return static::tooMany(RateLimiter::availableIn($key));
        }

        $user = User::findForSignIn($username);
        $ok = $user !== null && is_string($password) && $password !== '' && static::check($password, (string) $user->password);
        if (!$ok) {
            // Same work whether or not the name exists, so timing does not tell.
            if ($user === null) {
                static::check((string) $password, '$2y$12$' . str_repeat('a', 53));
            }
            RateLimiter::hit($key, self::DECAY_SECONDS);
            if ($publicHost) {
                static::hitPublicCap($ip);
            }

            return ['error' => self::WRONG, 'status' => 422];
        }

        // The password is right: say why the account still cannot sign in.
        if ($user->password_set_at === null) {
            return ['error' => self::NO_PASSWORD, 'status' => 422];
        }
        if ($user->isDisabled()) {
            return ['error' => self::DISABLED, 'status' => 422];
        }

        RateLimiter::clear($key);

        return $user;
    }

    /** The 429 error: the same sentence for both limits. */
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
