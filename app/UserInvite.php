<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * An invitation to make a reader account (Settings → Users → Invite a
 * reader). The admin types a name and an email; the person opens the link
 * (/invite/{token}), chooses a sign-in name and password, and is signed in.
 *
 * Only the token's SHA-256 is stored, so the link can be shown only when it
 * is made (create / resend). Resending makes a new token and a new expiry:
 * the old link stops working. Cancelling deletes the row. Accepting stamps
 * accepted_at and user_id; the row goes when that user is deleted.
 */
class UserInvite extends Model
{
    public const TTL_DAYS = 7;

    protected $fillable = ['name', 'email', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'user_id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'invited_by' => 'integer',
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Make an invite. Returns [model, plain token].
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, string $email, ?User $by): array
    {
        $token = static::newToken();
        $invite = static::create([
            'name' => trim($name),
            'email' => static::normaliseEmail($email),
            'token_hash' => hash('sha256', $token),
            'invited_by' => $by?->id,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return [$invite, $token];
    }

    /** A new token and a new expiry (the old link stops working). Returns the plain token. */
    public function renew(): string
    {
        $token = static::newToken();
        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ])->save();

        return $token;
    }

    /** The invite a link's token belongs to, while it can still be accepted; else null. */
    public static function findUsable(?string $token): ?self
    {
        if (!is_string($token) || $token === '' || strlen($token) > 128) {
            return null;
        }

        return static::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Not accepted yet (expired ones included: they can be resent). */
    public function scopeUnaccepted(Builder $query): Builder
    {
        return $query->whereNull('accepted_at');
    }

    /** Not accepted and not expired. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /** Case-insensitive match on the email (stored lowercase; older rows matched by LOWER()). */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('LOWER(email) = ?', [static::normaliseEmail($email)]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** When the current link was sent (made or last resent). */
    public function sentAt(): ?\Illuminate\Support\Carbon
    {
        return $this->expires_at?->copy()->subDays(self::TTL_DAYS);
    }

    public function inviter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * The link to send: on the public address when one is configured
     * (App\Support\PublicAddress::url(), whatever host the admin is on: the
     * admin works from the home network, readers come from the internet);
     * otherwise the origin the admin is using right now (scheme + host of
     * $request), because Novarr is reached by different hostnames (LAN,
     * Tailscale) and APP_URL may not be the one readers use.
     */
    public static function link(Request $request, string $token): string
    {
        return static::origin($request) . route('invite.show', ['token' => $token], false);
    }

    /** The origin links for readers are built on (see link()). */
    public static function origin(Request $request): string
    {
        return \App\Support\PublicAddress::url() ?? rtrim($request->getSchemeAndHttpHost(), '/');
    }

    public static function normaliseEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
