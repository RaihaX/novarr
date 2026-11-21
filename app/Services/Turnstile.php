<?php

namespace App\Services;

use App\Support\PublicAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The human check (Cloudflare Turnstile) on the public address: the web
 * sign-in form and the invite form ask for it there, and only there, once
 * TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY are both set (config
 * services.turnstile). Never on the home network, never for the API, OPDS
 * or device pairing (apps cannot solve it). Either key empty = off: nothing
 * is rendered and nothing changes.
 *
 * verify() asks Cloudflare's siteverify endpoint (5 s timeout, with the
 * visitor's address): ok, invalid (missing, wrong, expired or reused token)
 * or unavailable (Cloudflare could not be asked; the caller refuses).
 * Everything is read per call: Octane-safe.
 */
class Turnstile
{
    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const TIMEOUT_SECONDS = 5;

    /** The form field the widget fills in. */
    public const FIELD = 'cf-turnstile-response';

    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const UNAVAILABLE = 'unavailable';

    public const INVALID_MESSAGE = 'Please complete the check below and try again.';

    public const UNAVAILABLE_MESSAGE = 'The sign-in check is not available right now. Try again in a minute.';

    public static function configured(): bool
    {
        return static::siteKey() !== null && static::secretKey() !== null;
    }

    /** Whether $request must pass the check: configured, and on the public address. */
    public static function requiredFor(Request $request): bool
    {
        return static::configured() && PublicAddress::isPublicRequest($request);
    }

    /** The site key to render the widget with on this request, or null (no widget). */
    public static function siteKeyFor(Request $request): ?string
    {
        return static::requiredFor($request) ? static::siteKey() : null;
    }

    public function verify(?string $token, ?string $ip): string
    {
        $token = trim((string) $token);
        // Cloudflare's tokens are at most 2048 characters.
        if ($token === '' || strlen($token) > 2048) {
            return self::INVALID;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => static::secretKey(),
                    'response' => $token,
                    'remoteip' => $ip,
                ], fn($value) => $value !== null && $value !== ''));
        } catch (\Throwable $e) {
            return $this->unavailable($e->getMessage());
        }

        $json = $response->json();
        if (!$response->successful() || !is_array($json) || !array_key_exists('success', $json)) {
            return $this->unavailable('HTTP ' . $response->status());
        }
        if ($json['success'] === true) {
            return self::OK;
        }
        // Cloudflare's own trouble (or a wrong secret key) is not the visitor's fault.
        $codes = (array) ($json['error-codes'] ?? []);
        if (array_intersect($codes, ['internal-error', 'missing-input-secret', 'invalid-input-secret']) !== []) {
            return $this->unavailable(implode(', ', $codes));
        }

        return self::INVALID;
    }

    private function unavailable(string $why): string
    {
        Log::warning("The sign-in check (Cloudflare Turnstile) could not be verified ({$why}); the attempt was refused.");

        return self::UNAVAILABLE;
    }

    private static function siteKey(): ?string
    {
        $key = trim((string) config('services.turnstile.site_key', ''));

        return $key === '' ? null : $key;
    }

    private static function secretKey(): ?string
    {
        $key = trim((string) config('services.turnstile.secret_key', ''));

        return $key === '' ? null : $key;
    }
}
