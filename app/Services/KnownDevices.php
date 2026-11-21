<?php

namespace App\Services;

use App\Http\Controllers\UserInviteController;
use App\Mail\NewSignIn;
use App\Support\DeviceDescription;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The devices an account has signed in from with its password, and the
 * "New sign-in to your Novarr account" email when one is not among them.
 *
 * A browser is known by a random id in a long-lived cookie (COOKIE: HttpOnly,
 * SameSite=Lax, Secure on the public address and over HTTPS); an app by the
 * device_id it sends to POST /api/v1/auth/token. Only the SHA-256 of either
 * is stored (known_devices), at most MAX_PER_USER per account.
 *
 * No email when the account has no known device yet (its first sign-in
 * after accepting an invite, being added, or this feature arriving), when
 * it has no email address, or when mail is not set up (the log mailer).
 * The email is sent after the response, so a slow mail server never delays
 * signing in; a failure is logged, never shown. No state on the object:
 * Octane-safe.
 */
class KnownDevices
{
    /** Not novarr_device: that one is the reader's own, script-readable device id (App\Services\ReadingPositions). */
    public const COOKIE = 'novarr_signin_device';

    /** Browsers keep cookies at most about 400 days. */
    public const COOKIE_DAYS = 400;

    public const MAX_PER_USER = 50;

    /**
     * After a password sign-in on the web: remember this browser for $user
     * and send the email when it is new ($notify). Returns the browser's
     * cookie for the caller's response (always re-sent, so it lives another
     * COOKIE_DAYS) — set on the response itself, not queued in the shared
     * cookie jar.
     */
    public function webSignIn(Request $request, User $user, bool $notify = true): Cookie
    {
        $id = $this->browserId($request) ?? bin2hex(random_bytes(32));
        $this->seen($request, $user, 'web:' . $id, DeviceDescription::from($request->userAgent()), $notify);
        // A browser signing in for the first time gets its id only now: tie
        // it to the session row, so a later remember-me session replaces it.
        app(BrowserSessions::class)->setDevice($request, static::hash('web:' . $id));

        return $this->cookie($request, $id);
    }

    /** After a password sign-in from an app (POST /api/v1/auth/token). */
    public function appSignIn(Request $request, User $user, ?string $deviceId, ?string $deviceName): bool
    {
        $name = trim((string) $deviceName);
        $device = $name !== '' ? mb_substr($name, 0, 120) : DeviceDescription::from($request->userAgent());
        $deviceId = trim((string) $deviceId);
        if ($deviceId === '') {
            // Nothing to know it by next time: always a new device, not stored.
            return $this->notifyIfKnownBefore($request, $user, $device);
        }

        return $this->seen($request, $user, 'app:' . $deviceId, $device, true);
    }

    /** This browser's id from its cookie, or null. */
    public function browserId(Request $request): ?string
    {
        $value = $request->cookies->get(self::COOKIE);

        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) ? $value : null;
    }

    public function cookie(Request $request, string $id): Cookie
    {
        $secure = $request->isSecure() || PublicAddress::isPublicRequest($request);

        return \Illuminate\Support\Facades\Cookie::make(
            self::COOKIE, $id, self::COOKIE_DAYS * 24 * 60,
            '/', null, $secure, true, false, Cookie::SAMESITE_LAX
        );
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** Record $key for $user; true when the email is going (new device, and the account had others). */
    private function seen(Request $request, User $user, string $key, string $label, bool $notify): bool
    {
        $hash = static::hash($key);
        $now = now();
        $known = DB::table('known_devices')->where('user_id', $user->id)->where('device_hash', $hash)->exists();
        if ($known) {
            DB::table('known_devices')->where('user_id', $user->id)->where('device_hash', $hash)
                ->update(['last_seen_at' => $now, 'label' => $label]);

            return false;
        }

        $hadDevices = DB::table('known_devices')->where('user_id', $user->id)->exists();
        DB::table('known_devices')->insertOrIgnore([
            'user_id' => (int) $user->id, 'device_hash' => $hash, 'first_seen_at' => $now, 'last_seen_at' => $now, 'label' => $label,
        ]);
        $this->trim($user);

        return $hadDevices && $notify && $this->notify($request, $user, $label);
    }

    private function notifyIfKnownBefore(Request $request, User $user, string $label): bool
    {
        return DB::table('known_devices')->where('user_id', $user->id)->exists() && $this->notify($request, $user, $label);
    }

    /** Keep the newest MAX_PER_USER. */
    private function trim(User $user): void
    {
        $keep = DB::table('known_devices')->where('user_id', $user->id)
            ->orderByDesc('last_seen_at')->orderByDesc('id')->limit(self::MAX_PER_USER)->pluck('id');
        DB::table('known_devices')->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
    }

    /** Send the email after the response. False when there is nowhere to send it. */
    private function notify(Request $request, User $user, string $device): bool
    {
        $email = trim((string) $user->email);
        if ($email === '' || UserInviteController::mailIsOff()) {
            return false;
        }

        $mail = new NewSignIn(
            (string) $user->name,
            now(),
            $device,
            (string) $request->ip(),
            PublicAddress::isPublicRequest($request),
        );

        // After the response is sent (terminate). Once only: the callback list
        // is the application's, and a reused application (tests) runs it again.
        $sent = false;
        app()->terminating(function () use (&$sent, $email, $mail) {
            if ($sent) {
                return;
            }
            $sent = true;
            try {
                Mail::to($email)->send($mail);
            } catch (\Throwable $e) {
                Log::warning("New sign-in email to {$email} failed: " . $e->getMessage());
            }
        });

        return true;
    }
}
