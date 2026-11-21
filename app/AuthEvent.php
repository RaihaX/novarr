<?php

namespace App;

use App\Support\DeviceDescription;
use App\Support\PublicAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One line of the sign-in history (Settings → Users → Sign-in history, and
 * each account's "Recent sign-ins"). Written by record() with the request it
 * happened on (null for a console command: no address). Never holds a
 * password, token, invite link or token hash: `detail` is a few small,
 * harmless facts (how, why, which device name).
 *
 * The address is $request->ip(): the client address as Laravel resolves it
 * through TRUSTED_PROXIES. A failure to write is logged, never thrown — the
 * history must not break signing in.
 */
class AuthEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    // Signing in and out
    public const SIGN_IN = 'sign_in';

    public const SIGN_IN_FAILED = 'sign_in_failed';

    public const RATE_LIMITED = 'rate_limited';

    public const ACCOUNT_LOCKED = 'account_locked';

    public const SIGN_OUT = 'sign_out';

    // Own account
    public const PASSWORD_CHANGED = 'password_changed';

    public const SIGNED_OUT_EVERYWHERE = 'signed_out_everywhere';

    // Admin actions
    public const PASSWORD_SET = 'password_set';

    public const ACCOUNT_CREATED = 'account_created';

    public const ACCOUNT_DISABLED = 'account_disabled';

    public const ACCOUNT_ENABLED = 'account_enabled';

    public const ACCOUNT_DELETED = 'account_deleted';

    public const ACCOUNT_EDITED = 'account_edited';

    public const INVITE_CREATED = 'invite_created';

    public const INVITE_RESENT = 'invite_resent';

    public const INVITE_CANCELLED = 'invite_cancelled';

    public const INVITE_ACCEPTED = 'invite_accepted';

    public const SIGN_IN_SWITCH = 'sign_in_switch';

    // Apps and devices
    public const TOKEN_ISSUED = 'token_issued';

    public const TOKEN_REVOKED = 'token_revoked';

    /** The filter groups of the history page (an event may be in none: it shows under "All"). */
    public const GROUPS = [
        'sign-ins' => [self::SIGN_IN, self::SIGN_OUT],
        'failures' => [self::SIGN_IN_FAILED, self::RATE_LIMITED, self::ACCOUNT_LOCKED],
        'admin' => [
            self::PASSWORD_SET, self::ACCOUNT_CREATED, self::ACCOUNT_DISABLED, self::ACCOUNT_ENABLED,
            self::ACCOUNT_DELETED, self::ACCOUNT_EDITED, self::INVITE_CREATED, self::INVITE_RESENT,
            self::INVITE_CANCELLED, self::SIGN_IN_SWITCH,
        ],
    ];

    public const GROUP_LABELS = [
        'sign-ins' => 'Sign-ins',
        'failures' => 'Failures',
        'admin' => 'Admin actions',
    ];

    /** Detail keys that are never stored, whatever a caller passes. */
    private const FORBIDDEN_DETAIL = ['password', 'password_confirmation', 'current_password', 'token', 'token_hash', 'secret', 'link', 'cf-turnstile-response'];

    protected $table = 'auth_events';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'user_id' => 'integer',
        'actor_user_id' => 'integer',
        'public' => 'boolean',
        'detail' => 'array',
    ];

    /**
     * Record $event about $userId (null: no account, e.g. an unknown name),
     * done by $actorId (an admin acting on someone else's account).
     */
    public static function record(string $event, ?Request $request, ?int $userId = null, array $detail = [], ?int $actorId = null): ?self
    {
        try {
            foreach (self::FORBIDDEN_DETAIL as $key) {
                unset($detail[$key]);
            }

            return static::create([
                'occurred_at' => now(),
                'user_id' => $userId,
                'actor_user_id' => $actorId !== null && $actorId !== $userId ? $actorId : null,
                'event' => $event,
                'ip' => $request?->ip(),
                'user_agent' => $request === null ? null : static::truncate($request->userAgent()),
                'public' => $request !== null && PublicAddress::isPublicRequest($request),
                'detail' => $detail === [] ? null : $detail,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write the sign-in history (' . $event . '): ' . $e->getMessage());

            return null;
        }
    }

    public static function truncate(?string $value, int $max = 255): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_strcut(trim($value), 0, $max, 'UTF-8');
    }

    public function scopeGroup(Builder $query, ?string $group): Builder
    {
        return isset(self::GROUPS[$group]) ? $query->whereIn('event', self::GROUPS[$group]) : $query;
    }

    /** The event in plain words. */
    public function label(): string
    {
        $d = $this->detail ?? [];
        $via = match ($d['via'] ?? null) {
            'api' => ' (app)',
            'basic' => ' (OPDS)',
            default => '',
        };

        return match ($this->event) {
            self::SIGN_IN => 'Signed in' . $via,
            self::SIGN_IN_FAILED => match ($d['reason'] ?? null) {
                'unknown_name' => 'Sign-in failed: no account with that name' . $via,
                'disabled' => 'Sign-in refused: the account is turned off' . $via,
                'no_password' => 'Sign-in refused: the account has no password yet' . $via,
                'check' => 'Sign-in failed: the human check was not completed' . $via,
                'check_unavailable' => 'Sign-in refused: the human check was not available' . $via,
                default => 'Sign-in failed: wrong password' . $via,
            },
            self::RATE_LIMITED => (($d['limit'] ?? null) === 'account' ? 'Refused: the account is locked on the public address' : 'Too many attempts: refused') . match ($d['via'] ?? null) {
                'api' => ' (app)',
                'basic' => ' (OPDS)',
                'invite' => ' (invite)',
                default => '',
            },
            self::ACCOUNT_LOCKED => 'Account locked on the public address for 15 minutes after repeated wrong passwords',
            self::SIGN_OUT => 'Signed out',
            self::PASSWORD_CHANGED => 'Changed their password',
            self::SIGNED_OUT_EVERYWHERE => 'Signed out all other browsers',
            self::PASSWORD_SET => 'Password set by the admin',
            self::ACCOUNT_CREATED => ($d['via'] ?? null) === 'invite' ? 'Account created from an invite' : 'Account added without an invite',
            self::ACCOUNT_DISABLED => 'Account turned off',
            self::ACCOUNT_ENABLED => 'Account turned back on',
            self::ACCOUNT_DELETED => 'Account deleted' . (isset($d['username']) ? ' (' . $d['username'] . ')' : ''),
            self::ACCOUNT_EDITED => 'Account edited' . (!empty($d['changed']) ? ' (' . implode(', ', (array) $d['changed']) . ')' : ''),
            self::INVITE_CREATED => 'Invite sent to ' . ($d['email'] ?? 'someone'),
            self::INVITE_RESENT => 'Invite resent to ' . ($d['email'] ?? 'someone'),
            self::INVITE_CANCELLED => 'Invite cancelled for ' . ($d['email'] ?? 'someone'),
            self::INVITE_ACCEPTED => 'Invite accepted',
            self::SIGN_IN_SWITCH => (!empty($d['on']) ? 'Sign-in turned on' : 'Sign-in turned off') . (($d['via'] ?? null) === 'cli' ? ' (on the server)' : ''),
            self::TOKEN_ISSUED => 'App or device signed in' . (isset($d['name']) ? ': ' . $d['name'] : '') . (($d['via'] ?? null) === 'pairing' ? ' (linked with a code)' : ''),
            self::TOKEN_REVOKED => 'App or device signed out' . (isset($d['name']) ? ': ' . $d['name'] : ''),
            default => Str::headline($this->event),
        };
    }

    /** Whether the event is a failure (shown in red). */
    public function isFailure(): bool
    {
        return in_array($this->event, self::GROUPS['failures'], true);
    }

    /** "Firefox on macOS", or the app's device name. */
    public function device(): string
    {
        $name = $this->detail['device'] ?? null;

        return is_string($name) && $name !== '' ? $name : DeviceDescription::from($this->user_agent);
    }
}
