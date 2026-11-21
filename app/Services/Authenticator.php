<?php

namespace App\Services;

use App\Support\AppClock;
use App\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;

/**
 * The authenticator app second step (RFC 6238 TOTP: SHA-1, 6 digits,
 * 30-second steps). A code is accepted for the current step and one step
 * either side; a step that was used once is never accepted again
 * (users.totp_last_step, moved forward atomically, so two requests with
 * the same code cannot both pass). The secret is stored encrypted with the
 * app key; the QR code is drawn here as inline SVG, so the secret never
 * goes to anyone else. Stateless: Octane-safe.
 */
class Authenticator
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Steps accepted either side of the current one. */
    public const WINDOW = 1;

    public const ISSUER = 'Novarr';

    /** A new random secret (base32, 160 bits). */
    public function newSecret(): string
    {
        return TOTP::generate(new AppClock(), 20)->getSecret();
    }

    /** The otpauth:// address an authenticator app reads from the QR code. */
    public function uri(string $secret, User $user): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel((string) ($user->username ?: $user->name));
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    /** The QR code of the uri, as an inline <svg> element. */
    public function qrSvg(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd())))->writeString($uri);

        // Drop the XML declaration: the SVG is inlined in the page.
        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }

    /** "123 456" → "123456"; null when it cannot be a code. */
    public static function normalise(?string $code): ?string
    {
        $code = preg_replace('/\s+/', '', (string) $code);

        return preg_match('/^\d{' . self::DIGITS . '}$/', (string) $code) ? $code : null;
    }

    /**
     * The time step $code is right for (current ±WINDOW), skipping steps up
     * to and including $lastStep; null when it is right for none.
     */
    public function matchStep(string $secret, ?string $code, ?int $lastStep = null): ?int
    {
        $code = static::normalise($code);
        if ($code === null) {
            return null;
        }
        $totp = $this->totp($secret);
        $current = intdiv(now()->getTimestamp(), self::PERIOD);
        $found = null;
        // Every candidate is compared (no early exit), in constant time.
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($step < 0 || ($lastStep !== null && $step <= $lastStep)) {
                continue;
            }
            if (hash_equals($totp->at($step * self::PERIOD), $code) && $found === null) {
                $found = $step;
            }
        }

        return $found;
    }

    /** Check $code against $user's authenticator, using its step up for good. */
    public function verify(User $user, ?string $code): bool
    {
        $secret = $this->secretOf($user);
        if ($secret === null) {
            return false;
        }
        $step = $this->matchStep($secret, $code, $user->totp_last_step === null ? null : (int) $user->totp_last_step);
        if ($step === null) {
            return false;
        }

        // Atomic: of two requests with the same code only one moves the step.
        $moved = User::whereKey($user->id)
            ->where(fn($q) => $q->whereNull('totp_last_step')->orWhere('totp_last_step', '<', $step))
            ->toBase()->update(['totp_last_step' => $step]);
        if ($moved !== 1) {
            return false;
        }
        $user->totp_last_step = $step;
        $user->syncOriginalAttribute('totp_last_step');

        return true;
    }

    /** Turn the authenticator on with $secret, the step of the code that proved it used up. */
    public function enable(User $user, string $secret, int $step): void
    {
        $user->forceFill([
            'totp_secret' => Crypt::encryptString($secret),
            'totp_enabled_at' => now(),
            'totp_last_step' => $step,
        ])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill(['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_step' => null])->save();
    }

    public function secretOf(User $user): ?string
    {
        if ($user->totp_secret === null || $user->totp_enabled_at === null) {
            return null;
        }
        try {
            return Crypt::decryptString($user->totp_secret);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function totp(string $secret): TOTP
    {
        return TOTP::createFromSecret($secret, new AppClock());
    }
}
