<?php

namespace App\Support;

use App\Setting;
use App\User;

/**
 * The "Require sign-in" switch.
 *
 * Off (the default): every request is the owner, exactly as before accounts
 * existed — no login page, tokens and sessions are ignored for identity.
 * On: every page and API endpoint needs credentials except the public ones
 * (App\Support\RouteAccess).
 *
 * The env NOVARR_AUTH=on|off (config novarr.auth) wins over the app setting
 * `auth_required` and locks the Settings toggle. Read per call (settings are
 * cached by App\Setting), never kept in a static: Octane-safe.
 */
class AuthMode
{
    public const SETTING = 'auth_required';

    /**
     * Whether credentials are required right now. NOVARR_AUTH=on is refused
     * (stays off) while the owner has no password set through Novarr — nobody
     * could sign in; Settings → Users says so. The stored setting can only
     * have been turned on after that check, so it needs no query.
     */
    public static function required(): bool
    {
        $forced = static::forced();
        if ($forced === true) {
            return static::ownerHasPassword();
        }
        if ($forced === false) {
            return false;
        }

        return static::setting();
    }

    /** NOVARR_AUTH=on is set but refused because the owner has no password yet. */
    public static function forcedOnButRefused(): bool
    {
        return static::forced() === true && !static::ownerHasPassword();
    }

    /**
     * Remembered on the current request (NOVARR_AUTH=on asks on every
     * @can and signed URL); a console run always asks the database.
     */
    public static function ownerHasPassword(): bool
    {
        $request = app()->bound('request') && !app()->runningInConsole() ? app('request') : null;
        if ($request !== null && $request->attributes->has('novarr.owner_has_password')) {
            return $request->attributes->get('novarr.owner_has_password');
        }

        $has = User::whereKey(CurrentReader::ownerId())->whereNotNull('password_set_at')->exists();
        $request?->attributes->set('novarr.owner_has_password', $has);

        return $has;
    }

    /** true / false when NOVARR_AUTH forces it, null when the setting decides. */
    public static function forced(): ?bool
    {
        return match (config('novarr.auth')) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    /**
     * The stored setting, whatever the env says. A database without the
     * settings table (not migrated yet) has nothing switched on; any other
     * database error is thrown — it never silently turns sign-in off.
     */
    public static function setting(): bool
    {
        try {
            return (string) Setting::get(self::SETTING, '0') === '1';
        } catch (\Illuminate\Database\QueryException $e) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('app_settings')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Why sign-in cannot be turned on now (a sentence for the UI / CLI), or
     * null when it can. The owner must have set a password through Novarr:
     * the account carried over from before has an unknown password.
     */
    public static function cannotTurnOnReason(): ?string
    {
        return static::ownerHasPassword()
            ? null
            : 'Set a password for the owner account first, or nobody could sign in.';
    }

    /** Store the setting (the env, when set, still wins). */
    public static function store(bool $on): void
    {
        Setting::put(self::SETTING, $on ? '1' : '0');
    }
}
