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
        'password', 'remember_token',
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
            ApiToken::where('user_id', $user->id)->delete();
            DevicePairing::where('user_id', $user->id)->delete();
            UserInvite::where('user_id', $user->id)->delete();
            // Their requests (and the feed items about them) go too.
            $requestIds = NovelRequest::where('user_id', $user->id)->pluck('id');
            RequestNotification::whereIn('novel_request_id', $requestIds)->delete();
            RequestNotification::where('user_id', $user->id)->delete();
            NovelRequestFollower::whereIn('novel_request_id', $requestIds)->delete();
            NovelRequestFollower::where('user_id', $user->id)->delete();
            NovelRequest::whereIn('id', $requestIds)->delete();
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
            return static::findByEmail($login);
        }

        return static::where('username', $login)->first();
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

    /**
     * Sign this account out everywhere: every web session signed in before
     * now ends (IdentifyReader compares the session's sign-in time), every
     * remember-me cookie stops working, and — unless $keepTokens — every
     * app / device token is revoked.
     */
    public function signOutEverywhere(bool $keepTokens = false): void
    {
        $this->forceFill([
            'sessions_valid_after' => now(),
            'remember_token' => \Illuminate\Support\Str::random(60),
        ])->save();
        if (!$keepTokens) {
            ApiToken::where('user_id', $this->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
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
