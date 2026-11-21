<?php

namespace App\Support;

use App\AuthEvent;
use App\Services\Credentials;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Confirm it's you" before a sensitive change on the Account page (adding
 * or removing a passkey or the authenticator, new recovery codes, emailed
 * codes on / off, the password, a catalogue password, linking a device):
 * this browser must have confirmed within WINDOW_SECONDS — with the
 * password on the home network, with a second step on the public address.
 * Signing in counts as confirming. Kept in the session (User::SESSION_CONFIRMED_AT).
 *
 * The password check here (and the current password when changing it) is
 * limited to PASSWORD_MAX_WRONG wrong ones per PASSWORD_DECAY_SECONDS per
 * account (429).
 */
final class RecentConfirmation
{
    public const WINDOW_SECONDS = 600;

    public const PASSWORD_MAX_WRONG = 5;

    public const PASSWORD_DECAY_SECONDS = 900;

    public const NEEDED_MESSAGE = "Confirm it's you first, then try again.";

    public static function mark(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(User::SESSION_CONFIRMED_AT, now()->getTimestamp());
        }
    }

    public static function fresh(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }
        $at = $request->session()->get(User::SESSION_CONFIRMED_AT);

        return is_numeric($at) && (int) $at > now()->getTimestamp() - self::WINDOW_SECONDS;
    }

    /** The public address asks for a second step to confirm; the home network for the password. */
    public static function needsSecondStep(Request $request): bool
    {
        return PublicAddress::isPublicRequest($request);
    }

    /**
     * Check $user's password for a confirmation. True when right; false
     * when wrong (counted); seconds to wait (int) when over the limit.
     */
    public static function checkPassword(User $user, ?string $password, Request $request): bool|int
    {
        $key = static::passwordKey($user);
        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_MAX_WRONG)) {
            return max(1, RateLimiter::availableIn($key));
        }
        if (is_string($password) && $password !== '' && Credentials::check($password, (string) $user->password)) {
            RateLimiter::clear($key);

            return true;
        }
        RateLimiter::hit($key, self::PASSWORD_DECAY_SECONDS);
        AuthEvent::record(AuthEvent::CONFIRM_FAILED, $request, (int) $user->id);

        return false;
    }

    public static function passwordKey(User $user): string
    {
        return 'novarr-confirm-password:' . (int) $user->id;
    }
}
