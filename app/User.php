<?php

namespace App;

use App\Support\CurrentReader;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A reader account. One is the library's owner (app setting owner_user_id,
 * role admin); everyone else is a reader. Accounts sign in by username +
 * password (usernames are stored lowercase; sign-in is case-insensitive)
 * when "Require sign-in" is on (App\Support\AuthMode); with it off every
 * request is the owner (App\Support\CurrentReader).
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_READER = 'reader';

    /** Allowed characters of a username (stored lowercase). */
    public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,59}$/';

    /** users.last_seen_at is written at most this often per user. */
    public const SEEN_EVERY_SECONDS = 300;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'username', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'totp_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'disabled_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'password_set_at' => 'datetime',
        'sessions_valid_after' => 'datetime',
        'totp_enabled_at' => 'datetime',
        'email_codes_off_at' => 'datetime',
        'summary_email_off_at' => 'datetime',
        'summary_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A reader's own state and credentials go with them (no foreign
        // keys, like the chapter / novel hooks).
        static::deleted(function (self $user) {
            app(\App\Services\ReadState::class)->forgetUser((int) $user->id);
            ReadingPosition::where('user_id', $user->id)->delete();
            Bookmark::where('user_id', $user->id)->delete();
            AudiobookBookmark::where('user_id', $user->id)->delete();
            \Illuminate\Support\Facades\DB::table('audiobook_progress')->where('user_id', $user->id)->delete();
            ReadingList::forgetUser((int) $user->id);
            ApiToken::where('user_id', $user->id)->delete();
            DevicePairing::where('user_id', $user->id)->delete();
            app(\App\Services\BrowserSessions::class)->endAll($user);
            Passkey::where('user_id', $user->id)->delete();
            app(\App\Services\RecoveryCodes::class)->forget($user);
            app(\App\Services\SecondStep::class)->forgetUser($user);
            \Illuminate\Support\Facades\DB::table('known_devices')->where('user_id', $user->id)->delete();
            UserInvite::where('user_id', $user->id)->delete();
            // Their requests (and the feed items about them) go too.
            $requestIds = NovelRequest::where('user_id', $user->id)->pluck('id');
            RequestNotification::whereIn('novel_request_id', $requestIds)->delete();
            RequestNotification::where('user_id', $user->id)->delete();
            NovelRequestFollower::whereIn('novel_request_id', $requestIds)->delete();
            NovelRequestFollower::where('user_id', $user->id)->delete();
            \Illuminate\Support\Facades\DB::table(ReadingList::REQUEST_CATEGORIES)->whereIn('novel_request_id', $requestIds)->delete();
            NovelRequest::whereIn('id', $requestIds)->delete();
            // Their audiobook uploads (files too); an approved one's audiobook stays in the library.
            \App\Audiobooks\AudiobookUploads::forgetUser((int) $user->id);
        });
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isOwner(): bool
    {
        return (int) $this->id === CurrentReader::ownerId();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** Usernames are stored lowercase, so sign-in is case-insensitive on every database. */
    public static function normaliseUsername(?string $username): string
    {
        return strtolower(trim((string) $username));
    }

    public static function findByUsername(?string $username): ?self
    {
        $username = static::normaliseUsername($username);

        return $username === '' ? null : static::where('username', $username)->first();
    }

    /**
     * The account a sign-in name refers to: an email address (has an "@",
     * which usernames cannot) matches the account's email, case-insensitive;
     * anything else is a username. Used by the web login, the API and Basic.
     */
    public static function findForSignIn(?string $login): ?self
    {
        $login = static::normaliseUsername($login);
        if ($login === '') {
            return null;
        }
        if (str_contains($login, '@')) {
            return static::findBySignInEmail($login);
        }

        return static::where('username', $login)->first();
    }

    /**
     * The account signing in with an email address: none when two accounts
     * share it (older data; novarr:auth status lists them) — signing in by
     * that address is refused, the sign-in name still works.
     */
    public static function findBySignInEmail(?string $email): ?self
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }
        $matches = static::whereRaw('LOWER(email) = ?', [$email])->orderBy('id')->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Whether another account (not $exceptId) has this email, case-insensitive. */
    public static function emailTaken(?string $email, ?int $exceptId = null): bool
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && static::whereRaw('LOWER(email) = ?', [$email])
            ->when($exceptId !== null, fn($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /** Case-insensitive (older rows may hold mixed case; new ones are stored lowercase). */
    public static function findByEmail(?string $email): ?self
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : static::whereRaw('LOWER(email) = ?', [$email])->orderBy('id')->first();
    }

    /** Set a new password (hashed by the cast) and remember that it was set here. */
    public function setNewPassword(string $plain): void
    {
        $this->forceFill([
            'password' => $plain,
            'password_set_at' => now(),
        ])->save();
    }

    /** Session key: when this browser signed in (unix seconds). */
    public const SESSION_SIGNED_IN_AT = 'novarr_signed_in_at';

    /** Session key: when this browser's sign-in passed a second step or used a passkey (unix seconds). */
    public const SESSION_SECOND_STEP_AT = 'novarr_second_step_at';

    /** Session key: when this browser last confirmed it is the account's owner (App\Support\RecentConfirmation). */
    public const SESSION_CONFIRMED_AT = 'novarr_confirmed_at';

    /**
     * Sign this account out everywhere: every web session signed in before
     * now ends (IdentifyReader compares the session's sign-in time; the
     * listed ones end in the session store at once), every remember-me row
     * is deleted, and — unless $keepTokens — every app / device token and
     * catalogue password is revoked.
     */
    public function signOutEverywhere(bool $keepTokens = false): void
    {
        $this->forceFill(['sessions_valid_after' => now()])->save();
        if (!$keepTokens) {
            ApiToken::where('user_id', $this->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        // Gone from Account → Signed-in browsers, with their remember-me
        // (a browser kept signed in here is listed again by the sign-in
        // that follows).
        app(\App\Services\BrowserSessions::class)->endAll($this);
    }

    /**
     * The admin's "Reset second step": passkeys, authenticator and recovery
     * codes are removed, emailed codes are on again, and the account is
     * signed out everywhere (apps too).
     */
    public function resetSecondStep(): void
    {
        Passkey::where('user_id', $this->id)->delete();
        app(\App\Services\RecoveryCodes::class)->forget($this);
        app(\App\Services\SecondStep::class)->forgetUser($this);
        $this->forceFill([
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_last_step' => null,
            'email_codes_off_at' => null,
        ])->save();
        $this->signOutEverywhere();
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    /** Whether a web session signed in at $signedInAt (unix seconds, null: unknown) is still valid. */
    public function sessionStillValid(?int $signedInAt): bool
    {
        if ($this->sessions_valid_after === null) {
            return true;
        }

        // Strictly after: a sign-in in the same second as the reset is stale.
        return $signedInAt !== null && $signedInAt > $this->sessions_valid_after->getTimestamp();
    }

    /** The sign-in time to stamp on a new session: now, and always after the last sign-out-everywhere. */
    public function signInStamp(): int
    {
        $now = now()->getTimestamp();
        $after = $this->sessions_valid_after?->getTimestamp();

        return $after !== null && $after >= $now ? $after + 1 : $now;
    }

    /** The shape the API returns for a user (auth contract). */
    public function toApi(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'username' => (string) $this->username,
            'role' => $this->isAdmin() ? self::ROLE_ADMIN : self::ROLE_READER,
            // Only ever returned to the account itself (sign-in, pairing, /auth/me):
            // the app's "email ePubs from {this address}" hint.
            'email' => filter_var(trim((string) $this->email), FILTER_VALIDATE_EMAIL) ? trim((string) $this->email) : null,
        ];
    }

    /** Record activity, at most once per SEEN_EVERY_SECONDS (no updated_at churn). */
    public function markSeen(): void
    {
        if ($this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(self::SEEN_EVERY_SECONDS))) {
            return;
        }
        $at = now();
        // toBase(): an Eloquent update would also bump updated_at.
        static::whereKey($this->id)->toBase()->update(['last_seen_at' => $at]);
        $this->last_seen_at = $at;
        $this->syncOriginalAttribute('last_seen_at');
    }
}
