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
 */
class Credentials
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const WRONG = 'Wrong name or password.';

    public const DISABLED = 'This account is turned off. Ask the person who runs this Novarr to turn it back on.';

    public const NO_PASSWORD = 'This account has no password yet. Set one in Settings → Users, or with php artisan novarr:password.';

    /**
     * The user, or an error: ['error' => message, 'status' => 422|429, 'retry_after' => seconds].
     *
     * @return User|array{error: string, status: int, retry_after?: int}
     */
    public function attempt(?string $username, ?string $password, ?string $ip): User|array
    {
        $key = static::throttleKey($username, $ip);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return [
                'error' => "Too many sign-in attempts. Try again in {$seconds} " . ($seconds === 1 ? 'second.' : 'seconds.'),
                'status' => 429,
                'retry_after' => $seconds,
            ];
        }

        $user = User::findForSignIn($username);
        $ok = $user !== null && is_string($password) && $password !== '' && static::check($password, (string) $user->password);
        if (!$ok) {
            // Same work whether or not the name exists, so timing does not tell.
            if ($user === null) {
                static::check((string) $password, '$2y$12$' . str_repeat('a', 53));
            }
            RateLimiter::hit($key, self::DECAY_SECONDS);

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
