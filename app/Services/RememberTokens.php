<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-browser remember-me tokens (table remember_tokens), read and written
 * by App\Auth\BrowserGuard: one row per browser sign-in, so signing one
 * browser out never touches another. The cookie holds "selector|token":
 * the selector finds the row, the token is compared (constant time) with
 * the stored SHA-256.
 *
 * A row lives while it is used at least every IDLE_DAYS (sliding: a use is
 * a remember-me sign-in, or a signed-in request of its browser at most every
 * few minutes, App\Services\BrowserSessions::touch) and never more than
 * MAX_DAYS after it was made. second_step_passed_at: the sign-in passed the
 * second step (App\Services\SecondStep), so the browser may skip it on the
 * public address. Stateless: Octane-safe.
 */
class RememberTokens
{
    public const IDLE_DAYS = 30;

    public const MAX_DAYS = 365;

    private const VALUE_PATTERN = '/^([A-Za-z0-9]{32})\|([A-Za-z0-9]{64})$/';

    /**
     * A new row for $user. Returns [row id, cookie value]; only the token's
     * hash is stored, so the value cannot be shown again.
     *
     * @return array{0: int, 1: string}
     */
    public function issue(User $user, bool $secondStep): array
    {
        $selector = Str::random(32);
        $token = Str::random(64);
        $now = now();
        $id = (int) DB::table('remember_tokens')->insertGetId([
            'user_id' => (int) $user->id,
            'selector' => $selector,
            'token_hash' => hash('sha256', $token),
            'second_step_passed_at' => $secondStep ? $now : null,
            'created_at' => $now,
            'last_used_at' => $now,
            'expires_at' => $now->copy()->addDays(self::MAX_DAYS),
        ]);

        return [$id, $selector . '|' . $token];
    }

    /** The live row a cookie value refers to, or null (unknown, wrong token, expired). */
    public function find(?string $value): ?object
    {
        if (!is_string($value) || !preg_match(self::VALUE_PATTERN, $value, $m)) {
            return null;
        }
        $row = DB::table('remember_tokens')->where('selector', $m[1])->first();
        if ($row === null || !hash_equals((string) $row->token_hash, hash('sha256', $m[2]))) {
            return null;
        }
        if (!static::isLive($row)) {
            DB::table('remember_tokens')->where('id', $row->id)->delete();

            return null;
        }

        return $row;
    }

    public static function isLive(object $row): bool
    {
        $now = now();
        if ($row->expires_at === null || Carbon::parse($row->expires_at)->lte($now)) {
            return false;
        }

        return $row->last_used_at !== null && Carbon::parse($row->last_used_at)->gt($now->copy()->subDays(self::IDLE_DAYS));
    }

    /** Whether a cookie value has our shape (an old "id|token|hash" cookie does not). */
    public static function looksValid(?string $value): bool
    {
        return is_string($value) && preg_match(self::VALUE_PATTERN, $value) === 1;
    }

    public function touch(int $id): void
    {
        DB::table('remember_tokens')->where('id', $id)->update(['last_used_at' => now()]);
    }

    /** Tie the row to its browser's line on Account → Signed-in browsers. */
    public function link(int $id, int $browserSessionId): void
    {
        DB::table('remember_tokens')->where('id', $id)->update(['browser_session_id' => $browserSessionId]);
    }

    public function delete(int $id): void
    {
        DB::table('remember_tokens')->where('id', $id)->delete();
    }

    /** The rows of these browser lines (Sign out on one line, a browser replaced by a new sign-in). */
    public function deleteForBrowsers(array $browserSessionIds, ?int $exceptId = null): void
    {
        if ($browserSessionIds === []) {
            return;
        }
        DB::table('remember_tokens')->whereIn('browser_session_id', $browserSessionIds)
            ->when($exceptId !== null, fn($q) => $q->where('id', '!=', $exceptId))
            ->delete();
    }

    /** Every browser of $user (sign out everywhere, password reset, turn off, second step reset). */
    public function deleteForUser(User $user, ?int $exceptId = null): void
    {
        DB::table('remember_tokens')->where('user_id', $user->id)
            ->when($exceptId !== null, fn($q) => $q->where('id', '!=', $exceptId))
            ->delete();
    }

    /** Drop expired rows. Returns how many. */
    public function prune(): int
    {
        $now = now();

        return DB::table('remember_tokens')
            ->where(fn($q) => $q->where('expires_at', '<=', $now)
                ->orWhere('last_used_at', '<=', $now->copy()->subDays(self::IDLE_DAYS))
                ->orWhereNull('last_used_at'))
            ->delete();
    }
}
