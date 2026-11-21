<?php

namespace App\Console\Commands;

use App\AuthEvent;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Console\Command;

/**
 * The "Require sign-in" escape hatch: switch it from the server when the web
 * UI is out of reach (locked out, forgot the password). NOVARR_AUTH, when
 * set, still wins over what this stores.
 */
class AuthSwitch extends Command
{
    protected $signature = 'novarr:auth {action=status : on, off or status}';

    protected $description = 'Turn "Require sign-in" on or off, or show its state';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        switch ($action) {
            case 'on':
                if (($reason = AuthMode::cannotTurnOnReason()) !== null) {
                    $this->error($reason);
                    $this->line('Set one with: php artisan novarr:password');

                    return self::FAILURE;
                }
                $wasOn = AuthMode::setting();
                AuthMode::store(true);
                if (!$wasOn) {
                    AuthEvent::record(AuthEvent::SIGN_IN_SWITCH, null, null, ['on' => true, 'via' => 'cli']);
                }
                $this->info('Sign-in is on.');
                break;
            case 'off':
                $wasOn = AuthMode::setting();
                AuthMode::store(false);
                if ($wasOn) {
                    AuthEvent::record(AuthEvent::SIGN_IN_SWITCH, null, null, ['on' => false, 'via' => 'cli']);
                }
                $this->info('Sign-in is off: every request is the owner.');
                break;
            case 'status':
                break;
            default:
                $this->error("Unknown action \"{$action}\": use on, off or status.");

                return self::INVALID;
        }

        $this->status();

        return self::SUCCESS;
    }

    private function status(): void
    {
        $owner = User::find(CurrentReader::ownerId());
        $forced = AuthMode::forced();

        $this->table(['', ''], [
            ['In effect', AuthMode::required() ? 'on' : 'off'],
            ['Setting (Settings → Users)', AuthMode::setting() ? 'on' : 'off'],
            ['NOVARR_AUTH', $forced === null ? 'not set' : ($forced ? 'on' : 'off')],
            ['Owner', $owner ? "{$owner->name} ({$owner->username})" : 'missing — run php artisan migrate'],
            ['Owner password', $owner?->password_set_at ? 'set ' . $owner->password_set_at->toDateTimeString() : 'not set'],
            ['Accounts', (string) User::count()],
            ['Pending invites', (string) \App\UserInvite::pending()->count()],
            ['Public address', PublicAddress::url() ?? 'not set'],
        ]);

        if (PublicAddress::configured() && !AuthMode::required()) {
            $this->warn('A public address is set (NOVARR_PUBLIC_HOSTS) but sign-in is off: the public address refuses every page until sign-in is on.');
        }

        if ($forced !== null && $forced !== AuthMode::setting()) {
            $this->warn('NOVARR_AUTH overrides the setting: change it in the server environment.');
        }
        if (AuthMode::forcedOnButRefused()) {
            $this->warn('NOVARR_AUTH=on is ignored until the owner has a password (php artisan novarr:password).');
        }
    }
}
