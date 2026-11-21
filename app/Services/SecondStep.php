<?php

namespace App\Services;

use App\AuthEvent;
use App\Http\Controllers\UserInviteController;
use App\Mail\SignInCode;
use App\Passkey;
use App\Support\AuthMode;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The second sign-in step, required on the public address (sign-in on)
 * after a correct password: a passkey (web only), the authenticator app,
 * an emailed code, or a recovery code.
 *
 * Between the password and the second step there is a challenge (table
 * second_step_challenges): a random token held by the browser's session
 * (web) or returned to the app (`challenge`), stored as its SHA-256, bound
 * to the account (and the app's device_id), valid TTL seconds, ended by
 * MAX_WRONG wrong codes, used once. It grants nothing by itself. The same
 * challenges serve the Account page's "Confirm it's you" on the public
 * address (purpose confirm).
 *
 * Emailed codes: 6 digits, valid CODE_TTL seconds, kept as an HMAC (app
 * key), MAX_SENDS per challenge and MAX_SENDS_PER_HOUR per account; a new
 * send replaces the code. Sent after the response (a slow mail server never
 * holds the page up). Wrong codes of any kind count toward the public
 * address's per-IP cap and the account's lock (App\Services\Credentials).
 * Stateless: Octane-safe.
 */
class SecondStep
{
    public const TTL = 600;

    public const MAX_WRONG = 5;

    public const CODE_TTL = 600;

    public const MAX_SENDS = 3;

    public const MAX_SENDS_PER_HOUR = 6;

    public const PASSKEY = 'passkey';

    public const TOTP = 'totp';

    public const EMAIL = 'email';

    public const RECOVERY = 'recovery';

    /** The order the methods are offered in. */
    public const ORDER = [self::PASSKEY, self::TOTP, self::EMAIL, self::RECOVERY];

    public const SIGN_IN = 'sign_in';

    public const CONFIRM = 'confirm';

    public const REQUIRED_MESSAGE = 'Enter your sign-in code to finish signing in.';

    public const UNAVAILABLE_MESSAGE = 'This account needs a second sign-in step before it can be used here. Set one up from your home network, or ask the person who runs this Novarr.';

    public const PASSKEY_ONLY_MESSAGE = 'This account signs in with a passkey. Link this device with a code instead: on a phone or computer where you are signed in, open Account → Link a device.';

    public const WRONG_CODE_MESSAGE = 'That code is not right. Check it and try again.';

    public const EXPIRED_MESSAGE = 'That took too long. Sign in again.';

    public const TOO_MANY_WRONG_MESSAGE = 'Too many wrong codes. Sign in again.';

    // check() outcomes
    public const OK = 'ok';

    public const WRONG = 'wrong';

    /** That was the MAX_WRONG-th wrong code: the challenge is gone. */
    public const DEAD = 'dead';

    public const EXPIRED = 'expired';

    /** The account's lock or the public cap: not evaluated. */
    public const LOCKED = 'locked';

    public function __construct(
        private Authenticator $authenticator,
        private RecoveryCodes $recoveryCodes,
        private Passkeys $passkeys,
    ) {
    }

    /** Whether a second step is asked for on this request's address. */
    public static function requiredFor(Request $request): bool
    {
        return AuthMode::required() && PublicAddress::isPublicRequest($request);
    }

    // ---------------------------------------------------------------- what an account has

    /** The address emailed codes go to, or null (none, a placeholder, or mail not set up). */
    public static function emailAddress(User $user): ?string
    {
        $email = strtolower(trim((string) $user->email));
        if ($email === '' || !str_contains($email, '@') || str_ends_with($email, '.invalid') || UserInviteController::mailIsOff()) {
            return null;
        }

        return $email;
    }

    /** A passkey (on any address) or the authenticator app. */
    public function hasStrongMethod(User $user): bool
    {
        return $this->authenticator->secretOf($user) !== null || Passkey::where('user_id', $user->id)->exists();
    }

    /** Emailed codes are on: not turned off, or turned off but nothing else is left. */
    public function emailCodesOn(User $user): bool
    {
        return $user->email_codes_off_at === null || !$this->hasStrongMethod($user);
    }

    /**
     * The methods $user can pass the second step with here, in ORDER. The
     * apps ($web false) cannot use passkeys. A passkey counts only on the
     * address it was made on, and only where the browser can use one.
     *
     * @return list<string>
     */
    public function methods(User $user, Request $request, bool $web = true): array
    {
        $methods = [];
        if ($web && Passkeys::available($request) && $this->passkeys->forHere($user, $request)->isNotEmpty()) {
            $methods[] = self::PASSKEY;
        }
        $strong = $this->hasStrongMethod($user);
        if ($this->authenticator->secretOf($user) !== null) {
            $methods[] = self::TOTP;
        }
        if (static::emailAddress($user) !== null && $this->emailCodesOn($user)) {
            $methods[] = self::EMAIL;
        }
        if ($strong && $this->recoveryCodes->remaining($user) > 0) {
            $methods[] = self::RECOVERY;
        }

        return $methods;
    }

    /** "reyhan@atomic.com.au" → "r•••@a•••.com.au". */
    public static function emailHint(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot = strpos($domain, '.');
        $first = $dot === false ? $domain : substr($domain, 0, $dot);
        $rest = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1) . '•••@' . mb_substr($first, 0, 1) . '•••' . $rest;
    }

    // ---------------------------------------------------------------- challenges

    /**
     * A new challenge for $user. Returns [row, plain token]. Expired rows
     * are swept first.
     *
     * @return array{0: object, 1: string}
     */
    public function begin(User $user, string $purpose, string $via, ?string $deviceId = null, ?string $deviceName = null): array
    {
        static::sweep();
        $token = bin2hex(random_bytes(32));
        $now = now();
        $id = DB::table('second_step_challenges')->insertGetId([
            'user_id' => (int) $user->id,
            'token_hash' => hash('sha256', $token),
            'purpose' => $purpose,
            'via' => $via,
            'device_id' => static::clean($deviceId, 64),
            'device_name' => static::clean($deviceName, 120),
            'expires_at' => $now->copy()->addSeconds(self::TTL),
            'created_at' => $now,
        ]);

        return [DB::table('second_step_challenges')->where('id', $id)->first(), $token];
    }

    /** The live challenge a token names (of $purpose and $via), or null. */
    public function find(?string $token, string $purpose, string $via): ?object
    {
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        return DB::table('second_step_challenges')
            ->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose)->where('via', $via)
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Use the challenge up: true for the one request that does. */
    public function consume(object $challenge): bool
    {
        return DB::table('second_step_challenges')->where('id', $challenge->id)->delete() === 1;
    }

    public function forget(object $challenge): void
    {
        DB::table('second_step_challenges')->where('id', $challenge->id)->delete();
    }

    public static function sweep(): void
    {
        DB::table('second_step_challenges')->where('expires_at', '<=', now())->delete();
    }

    public function forgetUser(User $user): void
    {
        DB::table('second_step_challenges')->where('user_id', $user->id)->delete();
    }

    // ---------------------------------------------------------------- emailed codes

    /**
     * Email a new code for $challenge. Returns null when it is going, else
     * ['error' => unavailable | throttled, 'retry_after' => seconds].
     */
    public function sendEmailCode(object $challenge, User $user, Request $request): ?array
    {
        $email = static::emailAddress($user);
        if ($email === null || !$this->emailCodesOn($user)) {
            return ['error' => 'unavailable'];
        }
        $hourKey = 'novarr-sign-in-code:' . (int) $user->id;
        if ((int) $challenge->email_sends >= self::MAX_SENDS) {
            // No more for this challenge: a new sign-in makes a new one.
            return ['error' => 'throttled', 'retry_after' => max(1, (int) ceil(now()->diffInSeconds(Carbon::parse($challenge->expires_at), false)))];
        }
        if (RateLimiter::tooManyAttempts($hourKey, self::MAX_SENDS_PER_HOUR)) {
            return ['error' => 'throttled', 'retry_after' => max(1, RateLimiter::availableIn($hourKey))];
        }
        // Counted atomically: two clicks at once send at most MAX_SENDS.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $updated = DB::table('second_step_challenges')->where('id', $challenge->id)
            ->where('email_sends', '<', self::MAX_SENDS)->where('expires_at', '>', now())
            ->update([
                'email_sends' => DB::raw('email_sends + 1'),
                'email_code_hash' => static::codeHash((int) $challenge->id, $code),
                'email_code_expires_at' => now()->addSeconds(self::CODE_TTL),
            ]);
        if ($updated !== 1) {
            return ['error' => 'throttled', 'retry_after' => 60];
        }
        RateLimiter::hit($hourKey, 3600);
        AuthEvent::record(AuthEvent::SIGN_IN_CODE_SENT, $request, (int) $user->id, ['via' => $challenge->via] + ($challenge->purpose === self::CONFIRM ? ['purpose' => 'confirm'] : []));

        $mail = new SignInCode((string) $user->name, $code, intdiv(self::CODE_TTL, 60), (string) $request->ip(), $challenge->purpose === self::SIGN_IN);
        $sent = false;
        app()->terminating(function () use (&$sent, $email, $mail) {
            if ($sent) {
                return;
            }
            $sent = true;
            try {
                Mail::to($email)->send($mail);
            } catch (\Throwable $e) {
                Log::warning("Sign-in code email to {$email} failed: " . $e->getMessage());
            }
        });

        return null;
    }

    public static function codeHash(int $challengeId, string $code): string
    {
        return hash_hmac('sha256', 'email-code|' . $challengeId . '|' . $code, (string) config('app.key'));
    }

    // ---------------------------------------------------------------- checking

    /**
     * Check one answer ($code: the code typed, or for a passkey the
     * browser's credential JSON). Returns [outcome, n]:
     *   OK      — right; the challenge is used up now (n: tries left);
     *   WRONG   — counted everywhere (n: tries left, ≥ 1);
     *   DEAD    — that was the MAX_WRONG-th wrong answer: the challenge is gone;
     *   EXPIRED — no such live challenge (timed out, used, another request won);
     *   LOCKED  — the account's lock or the public per-IP cap is reached:
     *             nothing was evaluated (n: seconds to wait).
     *
     * Race-safe, in this order: (1) a try is reserved atomically
     * (wrong_tries + 1 only while below MAX_WRONG and unexpired), so
     * parallel requests never get more than MAX_WRONG evaluations;
     * (2) the failure is counted toward the account's lock and the public
     * cap BEFORE evaluating, so a burst cannot get past those limits
     * either, and taken back on success; (3) the answer is checked without
     * spending it; (4) the challenge is consumed (one request wins); (5)
     * only the winner spends the code (the authenticator's time step, the
     * recovery code). A request that loses the race spends nothing.
     *
     * @return array{0: string, 1: int}
     */
    public function check(object $challenge, User $user, string $method, mixed $code, Request $request): array
    {
        $reserved = DB::table('second_step_challenges')
            ->where('id', $challenge->id)->where('user_id', $user->id)
            ->where('wrong_tries', '<', self::MAX_WRONG)->where('expires_at', '>', now())
            ->update(['wrong_tries' => DB::raw('wrong_tries + 1')]);
        $fresh = $reserved === 1 ? DB::table('second_step_challenges')->where('id', $challenge->id)->first() : null;
        if ($fresh === null) {
            return [self::EXPIRED, 0];
        }

        $publicHost = PublicAddress::isPublicRequest($request);
        if (($locked = $this->countBeforeChecking($user, $request, (string) $fresh->via, $publicHost)) !== null) {
            return [self::LOCKED, $locked];
        }

        $spend = in_array($method, self::ORDER, true) ? $this->answerIsRight($fresh, $user, $method, $code, $request) : null;
        if ($spend !== null) {
            // Taken back: a right answer is not a failure.
            $this->uncount($user, $request, $publicHost);
            if (!$this->consume($fresh)) {
                return [self::EXPIRED, 0];
            }
            if (!$spend()) {
                // The code was used in that same instant by another sign-in.
                Credentials::secondStepFailed($user, $request, (string) $fresh->via, $method, (string) $fresh->purpose);

                return [self::EXPIRED, 0];
            }
            if ($method === self::RECOVERY) {
                AuthEvent::record(AuthEvent::RECOVERY_CODE_USED, $request, (int) $user->id, ['via' => $fresh->via, 'left' => $this->recoveryCodes->remaining($user)]);
            }

            return [self::OK, self::MAX_WRONG - (int) $fresh->wrong_tries + 1];
        }

        Credentials::secondStepFailed($user, $request, (string) $fresh->via, $method, (string) $fresh->purpose);
        $left = self::MAX_WRONG - (int) $fresh->wrong_tries;
        if ($left <= 0) {
            $this->forget($fresh);

            return [self::DEAD, 0];
        }

        return [self::WRONG, $left];
    }

    /**
     * Count one failure (the account's lock, the public per-IP cap) before
     * the answer is looked at. Seconds to wait when that limit was already
     * reached (the answer is then not evaluated), else null.
     */
    private function countBeforeChecking(User $user, Request $request, string $via, bool $publicHost): ?int
    {
        if ($publicHost) {
            Credentials::hitPublicCap($request->ip());
        }
        Credentials::countAccountFailure($user, $request, $via);
        $overAccount = RateLimiter::attempts(Credentials::accountFailuresKey($user)) > Credentials::ACCOUNT_MAX_FAILURES;
        $overCap = $publicHost && RateLimiter::attempts(Credentials::publicCapKey($request->ip())) > Credentials::PUBLIC_MAX_ATTEMPTS;
        if (!$overAccount && !$overCap) {
            return null;
        }
        // (The reserved try stays used: the challenge is near its end anyway.)
        return max(
            $publicHost ? (int) (Credentials::accountLockRetryAfter($user) ?? 0) : 0,
            $overCap ? (int) (Credentials::publicCapRetryAfter($request->ip()) ?? 0) : 0,
            $overAccount ? max(1, RateLimiter::availableIn(Credentials::accountFailuresKey($user))) : 0,
            1,
        );
    }

    private function uncount(User $user, Request $request, bool $publicHost): void
    {
        RateLimiter::decrement(Credentials::accountFailuresKey($user), Credentials::ACCOUNT_WINDOW_SECONDS);
        if ($publicHost) {
            RateLimiter::decrement(Credentials::publicCapKey($request->ip()), Credentials::PUBLIC_DECAY_SECONDS);
        }
    }

    /**
     * Whether the answer is right, without using anything up: a closure that
     * spends it (true when still unspent) when right, null when wrong.
     */
    private function answerIsRight(object $challenge, User $user, string $method, mixed $code, Request $request): ?\Closure
    {
        // Only a method the account can use here (never a recovery code
        // without a passkey or authenticator, never a turned-off email).
        if (!in_array($method, $this->methods($user, $request, $challenge->via === 'web'), true)) {
            return null;
        }

        switch ($method) {
            case self::TOTP:
                $step = is_string($code) ? $this->authenticator->rightStep($user, $code) : null;

                return $step === null ? null : fn() => $this->authenticator->useStep($user, $step);
            case self::EMAIL:
                return is_string($code) && $this->emailCodeIsRight($challenge, $code) ? fn() => true : null;
            case self::RECOVERY:
                return is_string($code) && $this->recoveryCodes->isUnused($user, $code) ? fn() => $this->recoveryCodes->use($user, $code) : null;
            case self::PASSKEY:
                // A passkey's own challenge is used by the check itself (and
                // can be asked for again); nothing secret is spent.
                return $this->passkeys->verify($request, $challenge->purpose === self::CONFIRM ? Passkeys::CONFIRM : Passkeys::SECOND_STEP, $code, $user) !== null
                    ? fn() => true : null;
        }

        return null;
    }

    private function emailCodeIsRight(object $challenge, string $input): bool
    {
        $code = Authenticator::normalise($input);
        if ($code === null || $challenge->email_code_hash === null || $challenge->email_code_expires_at === null
            || now()->gte(Carbon::parse($challenge->email_code_expires_at))) {
            return false;
        }

        return hash_equals((string) $challenge->email_code_hash, static::codeHash((int) $challenge->id, $code));
    }

    private static function clean(?string $value, int $max): ?string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
