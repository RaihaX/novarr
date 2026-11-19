<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer token of a native client (iOS app, KOReader plugin), stored as its
 * SHA-256 only. The plain token is returned once, by issue(). Valid until
 * revoked or its user is disabled / deleted.
 */
class ApiToken extends Model
{
    /** last_used_at is written at most this often per token. */
    public const TOUCH_EVERY_SECONDS = 60;

    protected $fillable = ['user_id', 'name', 'token_hash', 'device_id', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'user_id' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * A new token for $user. Returns [model, plain token]; only the hash is
     * stored, so the plain token cannot be shown again.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, ?string $name, ?string $deviceId = null): array
    {
        $plain = 'nvr_' . Str::random(60);
        $token = static::create([
            'user_id' => (int) $user->id,
            'name' => Str::limit(trim((string) $name) ?: 'Device', 120, ''),
            'token_hash' => static::hash($plain),
            'device_id' => $deviceId === null || trim($deviceId) === '' ? null : Str::limit(trim($deviceId), 64, ''),
        ]);

        return [$token, $plain];
    }

    /** The live token for a plain string, with its (enabled) user loaded; null otherwise. */
    public static function findActive(?string $plain): ?self
    {
        if ($plain === null || $plain === '' || strlen($plain) > 255) {
            return null;
        }

        $token = static::with('user')->where('token_hash', static::hash($plain))->whereNull('revoked_at')->first();
        if ($token === null || $token->user === null || $token->user->isDisabled()) {
            return null;
        }

        return $token;
    }

    /** Record use, at most once a minute (no updated_at churn). */
    public function touchUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subSeconds(self::TOUCH_EVERY_SECONDS))) {
            return;
        }
        $at = now();
        static::whereKey($this->id)->toBase()->update(['last_used_at' => $at]);
        $this->last_used_at = $at;
        $this->syncOriginalAttribute('last_used_at');
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }
}
