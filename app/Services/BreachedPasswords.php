<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Whether a new password appears in a known data leak, by the Have I Been
 * Pwned range API (k-anonymity): only the first 5 hex characters of the
 * password's SHA-1 leave the server; the matching suffixes come back
 * (padded with fake ones, "Add-Padding: true", so the response size says
 * nothing) and are compared here.
 *
 * Fails open: a timeout or any other error lets the password through, with
 * a warning in the log at most once an hour. NOVARR_PASSWORD_BREACH_CHECK=off
 * (config novarr.password_breach_check) turns the check off. No state is
 * kept on the object: Octane-safe.
 */
class BreachedPasswords
{
    public const RANGE_URL = 'https://api.pwnedpasswords.com/range/';

    public const TIMEOUT_SECONDS = 3;

    public const MESSAGE = 'That password has appeared in a data leak, so it is not safe to use. Choose a different one.';

    /** Cache key: a warning was logged within the hour. */
    public const WARNED_KEY = 'novarr-breach-check-warned';

    public static function enabled(): bool
    {
        return (bool) config('novarr.password_breach_check', true);
    }

    /** True when $password is in the leak list; false when it is not, the check is off, or it could not be asked. */
    public function appears(string $password): bool
    {
        if (!static::enabled() || $password === '') {
            return false;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'Novarr'])
                ->get(self::RANGE_URL . $prefix);
        } catch (\Throwable $e) {
            $this->warn($e->getMessage());

            return false;
        }
        if (!$response->successful()) {
            $this->warn('HTTP ' . $response->status());

            return false;
        }

        foreach (preg_split('/\r\n|\r|\n/', (string) $response->body()) as $line) {
            $parts = explode(':', trim($line), 2);
            // Padding lines have a count of 0: they are not leaks.
            if (count($parts) === 2 && hash_equals($suffix, strtoupper($parts[0])) && (int) $parts[1] > 0) {
                return true;
            }
        }

        return false;
    }

    private function warn(string $why): void
    {
        if (Cache::add(self::WARNED_KEY, 1, 3600)) {
            Log::warning("The breached-password check could not reach Have I Been Pwned ({$why}); the password was allowed.");
        }
    }
}
