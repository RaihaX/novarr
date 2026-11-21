<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Recovery codes: COUNT single-use codes like "k7m4q-x9p2a" (lower-case
 * letters and digits without look-alikes), shown once when made, stored as
 * an HMAC with the app key (a database copy alone cannot be guessed back).
 * Making a new set deletes the old one. A use is atomic: of two requests
 * with the same code only one passes. Stateless: Octane-safe.
 */
class RecoveryCodes
{
    public const COUNT = 10;

    /** No 0/o, 1/l/i. */
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * A new set for $user (the old one is gone). Returns the plain codes.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = static::random();
            $codes[$code] = $code;
        }
        $codes = array_values($codes);
        $now = now();
        DB::transaction(function () use ($user, $codes, $now) {
            DB::table('recovery_codes')->where('user_id', $user->id)->delete();
            DB::table('recovery_codes')->insert(array_map(fn($code) => [
                'user_id' => (int) $user->id,
                'code_hash' => static::hash($code),
                'created_at' => $now,
            ], $codes));
        });

        return $codes;
    }

    /** Use one of $user's codes; true when it was unused (it is used now). */
    public function use(User $user, ?string $input): bool
    {
        $code = static::normalise($input);
        if ($code === null) {
            return false;
        }

        return DB::table('recovery_codes')->where('user_id', $user->id)
            ->where('code_hash', static::hash($code))->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    /** Whether $input is one of $user's unused codes (nothing is used). */
    public function isUnused(User $user, ?string $input): bool
    {
        $code = static::normalise($input);

        return $code !== null && DB::table('recovery_codes')->where('user_id', $user->id)
            ->where('code_hash', static::hash($code))->whereNull('used_at')->exists();
    }

    public function remaining(User $user): int
    {
        return DB::table('recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->count();
    }

    public function forget(User $user): void
    {
        DB::table('recovery_codes')->where('user_id', $user->id)->delete();
    }

    /** "K7M4Q X9P2A" / "k7m4q-x9p2a" → "k7m4q-x9p2a"; null when it cannot be a code. */
    public static function normalise(?string $input): ?string
    {
        $code = strtolower((string) preg_replace('/[\s\-]+/', '', (string) $input));

        return preg_match('/^[' . self::ALPHABET . ']{10}$/', $code) ? substr($code, 0, 5) . '-' . substr($code, 5) : null;
    }

    public static function random(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', 'recovery|' . $code, (string) config('app.key'));
    }
}
