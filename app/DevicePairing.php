<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Linking a device by code (the KOReader plugin: no password typed on a
 * Kindle). See the auth contract:
 *
 *   1. the device POSTs /api/v1/auth/pairings → code + secret (10 minutes);
 *   2. a signed-in user types the code on Account → "Link a device";
 *   3. the device polls by the secret; once linked it gets its token, once.
 *
 * Only the secret's SHA-256 is stored. Between "linked" and "collected" the
 * plain token is kept encrypted (app key) in `token`; collecting deletes the
 * row, so the token is handed over exactly once.
 */
class DevicePairing extends Model
{
    public const TTL_SECONDS = 600;

    public const POLL_INTERVAL = 3;

    /** No 0/O, 1/I/L: readable on an e-ink screen and easy to type. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 6;

    protected $fillable = ['code', 'secret_hash', 'device_id', 'device_name', 'user_id', 'api_token_id', 'token', 'linked_at', 'expires_at'];

    protected $hidden = ['secret_hash', 'token'];

    protected $casts = [
        'user_id' => 'integer',
        'api_token_id' => 'integer',
        'linked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Start a pairing. Returns [model, plain secret]. Expired rows are swept
     * first; the code is unique among the unexpired ones.
     *
     * @return array{0: self, 1: string}
     */
    public static function begin(?string $deviceId, ?string $deviceName): array
    {
        static::sweep();

        do {
            $code = static::randomCode();
        } while (static::where('code', $code)->where('expires_at', '>', now())->exists());

        $secret = bin2hex(random_bytes(32));
        $pairing = static::create([
            'code' => $code,
            'secret_hash' => hash('sha256', $secret),
            'device_id' => static::clean($deviceId, 64),
            'device_name' => static::clean($deviceName, 120),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return [$pairing, $secret];
    }

    /**
     * Delete expired pairings. A token issued to a device that never came to
     * collect it is revoked: nobody holds it.
     */
    public static function sweep(): void
    {
        $expired = static::where('expires_at', '<=', now());
        $orphans = (clone $expired)->whereNotNull('api_token_id')->whereNotNull('token')->pluck('api_token_id');
        if ($orphans->isNotEmpty()) {
            ApiToken::whereIn('id', $orphans)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        $expired->delete();
    }

    public static function randomCode(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** "k7m 4qx" / "K7M-4QX" → "K7M4QX"; null when it cannot be a code. */
    public static function normaliseCode(?string $input): ?string
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', (string) $input));

        return preg_match('/^[' . self::ALPHABET . ']{' . self::CODE_LENGTH . '}$/', $code) ? $code : null;
    }

    /** "K7M4QX" → "K7M 4QX" (how codes are shown). */
    public static function formatCode(string $code): string
    {
        return substr($code, 0, 3) . ' ' . substr($code, 3);
    }

    /** An unexpired pairing nobody has linked yet, by the code a user typed. */
    public static function pendingByCode(?string $input): ?self
    {
        $code = static::normaliseCode($input);
        if ($code === null) {
            return null;
        }

        return static::where('code', $code)
            ->where('expires_at', '>', now())
            ->whereNull('user_id')
            ->orderByDesc('id')
            ->first();
    }

    /** An unexpired pairing by the device's secret. */
    public static function bySecret(string $secret): ?self
    {
        if (!preg_match('/^[0-9a-f]{64}$/i', $secret)) {
            return null;
        }

        return static::where('secret_hash', hash('sha256', strtolower($secret)))
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Link to $user: issues the device's token, held (encrypted) until collected. */
    public function link(User $user): ApiToken
    {
        [$token, $plain] = ApiToken::issue($user, $this->device_name ?: 'Linked device', $this->device_id);
        $this->forceFill([
            'user_id' => (int) $user->id,
            'api_token_id' => (int) $token->id,
            'token' => Crypt::encryptString($plain),
            'linked_at' => now(),
        ])->save();

        return $token;
    }

    /** The plain token, once: the row is deleted as it is read. Null when not linked yet. */
    public function collect(): ?string
    {
        if ($this->user_id === null || $this->token === null) {
            return null;
        }

        // Only one poll can win the delete; a concurrent one gets 0 and nothing.
        $deleted = static::whereKey($this->id)->whereNotNull('token')->delete();
        if ($deleted !== 1) {
            return null;
        }

        try {
            return Crypt::decryptString($this->token);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function displayName(): string
    {
        return $this->device_name ?: 'A device';
    }

    private static function clean(?string $value, int $max): ?string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
