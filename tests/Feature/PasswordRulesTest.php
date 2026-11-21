<?php

namespace Tests\Feature;

use App\Http\Controllers\UserInviteController;
use App\Services\BreachedPasswords;
use App\Support\AccountRules;
use App\User;
use App\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * New and changed passwords (App\Support\AccountRules::password): 12 to 255
 * characters, not the account's names, not in a data leak (Have I Been
 * Pwned, faked here: no test reaches the network) — at every place a
 * password is chosen. Old passwords keep working.
 */
class PasswordRulesTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const LEAKED = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    /** HIBP's range answer for $password's prefix: its suffix with $count, among padding. */
    private function fakeRange(string $password, int $count = 42): void
    {
        $suffix = substr(strtoupper(sha1($password)), 5);
        Http::fake([
            'api.pwnedpasswords.com/range/*' => Http::response(
                "0018A45C4D1DEF81644B54AB7F969B88D65:0\r\n{$suffix}:{$count}\r\nFFFFF45C4D1DEF81644B54AB7F969B88D65:3\r\n",
                200
            ),
        ]);
    }

    private function inviteToken(string $email = 'sam@example.test'): string
    {
        $this->post('/settings/users/invites', ['name' => 'Sam Lee', 'email' => $email]);
        $link = session(UserInviteController::FLASH)['link'];

        return substr($link, strrpos($link, '/') + 1);
    }

    private function accept(string $token, string $password, array $overrides = [])
    {
        return $this->post('/invite/' . $token, $overrides + [
            'name' => 'Sam Lee', 'username' => 'sam.lee', 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    public function test_twelve_characters_at_least_and_255_at_most_everywhere(): void
    {
        $owner = $this->owner();
        $short = 'elevenchars';
        $this->assertSame(11, strlen($short));

        // Add without an invite (sign-in off: the owner).
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => $short])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_SHORT], null, 'add');
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => str_repeat('x', 256)])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_LONG], null, 'add');
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => 'twelve-chars'])->assertSessionHasNoErrors();
        $kim = User::where('username', 'kim')->firstOrFail();

        // Admin set / reset.
        $this->post(route('settings.users.password', $kim->id), ['password' => $short])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_SHORT], null, 'password' . $kim->id);
        $this->post(route('settings.users.password', $kim->id), ['password' => 'a-good-reset!'])->assertSessionHasNoErrors();

        // Invite acceptance.
        $token = $this->inviteToken();
        $this->accept($token, $short)->assertSessionHasErrors(['password' => AccountRules::PASSWORD_SHORT]);
        $this->assertNull(User::where('username', 'sam.lee')->first());
        $this->accept($token, 'sams-password')->assertSessionHasNoErrors();

        // Own change (Account page).
        $owner->setNewPassword(self::OWNER_PASSWORD);
        \App\Support\AuthMode::store(true);
        $cookies = $this->webSignIn('kim', 'a-good-reset!');
        $this->withBrowser($cookies)->post(route('account.password'), ['current_password' => 'a-good-reset!', 'password' => $short, 'password_confirmation' => $short])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_SHORT], null, 'password');
        $this->withBrowser($cookies)->post(route('account.password'), ['current_password' => 'a-good-reset!', 'password' => 'kims-own-choice', 'password_confirmation' => 'kims-own-choice'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('kims-own-choice', $kim->fresh()->password));

        // The server's command.
        $this->artisan('novarr:password', ['username' => 'kim', '--password' => $short])
            ->expectsOutputToContain('Use at least 12 characters. Nothing changed.')->assertExitCode(1);
        $this->artisan('novarr:password', ['username' => 'kim', '--password' => 'from-the-server'])->assertExitCode(0);
    }

    public function test_no_composition_rules(): void
    {
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => 'aaaaaaaaaaaa'])->assertSessionHasNoErrors();
        $this->post(route('settings.users.store'), ['name' => 'Lee', 'username' => 'lee', 'password' => 'ünïcödé wörds'])->assertSessionHasNoErrors();
    }

    public function test_a_password_cannot_be_the_accounts_name_sign_in_name_or_email(): void
    {
        $owner = $this->owner();

        // Add without an invite: the display name or the sign-in name.
        $this->post(route('settings.users.store'), ['name' => 'Kimberly Lee', 'username' => 'kim', 'password' => 'KIMBERLY LEE'])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_IS_A_NAME], null, 'add');
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kimberly.lee.1', 'password' => 'Kimberly.Lee.1'])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_IS_A_NAME], null, 'add');

        // Invite: the email, the typed name, the typed sign-in name.
        $token = $this->inviteToken('samantha@example.test');
        $this->accept($token, 'Samantha@Example.test')->assertSessionHasErrors(['password' => AccountRules::PASSWORD_IS_A_NAME]);
        $this->accept($token, 'samantha.lee.x', ['username' => 'samantha.lee.x'])->assertSessionHasErrors(['password' => AccountRules::PASSWORD_IS_A_NAME]);
        $this->accept($token, 'Samantha Jones', ['name' => 'Samantha Jones'])->assertSessionHasErrors(['password' => AccountRules::PASSWORD_IS_A_NAME]);

        // Admin reset: the account's own names.
        $reader = $this->makeReader('samuel.jackson', 'Sam');
        $reader->forceFill(['email' => 'samuel@example.test'])->save();
        $this->post(route('settings.users.password', $reader->id), ['password' => 'SAMUEL.JACKSON'])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_IS_A_NAME], null, 'password' . $reader->id);
        $this->post(route('settings.users.password', $reader->id), ['password' => ' samuel@example.test '])
            ->assertSessionHasErrors(['password'], null, 'password' . $reader->id);

        // Own change.
        $owner->setNewPassword(self::OWNER_PASSWORD);
        \App\Support\AuthMode::store(true);
        $cookies = $this->webSignIn('samuel.jackson', self::READER_PASSWORD);
        $this->withBrowser($cookies)->post(route('account.password'), ['current_password' => self::READER_PASSWORD, 'password' => 'samuel@example.test', 'password_confirmation' => 'samuel@example.test'])
            ->assertSessionHasErrors(['password' => AccountRules::PASSWORD_IS_A_NAME], null, 'password');

        // The command.
        $this->artisan('novarr:password', ['username' => 'samuel.jackson', '--password' => 'Samuel.Jackson'])
            ->expectsOutputToContain(AccountRules::PASSWORD_IS_A_NAME)->assertExitCode(1);
    }

    public function test_existing_short_passwords_keep_working(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $reader->forceFill(['password' => Hash::make('eight-ch')])->save();

        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => 'eight-ch'])->assertRedirect(route('home'));
        $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'sam', 'password' => 'eight-ch'])->assertOk();
    }

    public function test_a_leaked_password_is_refused_and_only_five_hash_characters_are_sent(): void
    {
        config(['novarr.password_breach_check' => true]);
        $this->fakeRange(self::LEAKED);

        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => self::LEAKED])
            ->assertSessionHasErrors(['password' => BreachedPasswords::MESSAGE], null, 'add');
        $this->assertNull(User::where('username', 'kim')->first());

        $hash = strtoupper(sha1(self::LEAKED));
        Http::assertSent(function (HttpRequest $request) use ($hash) {
            $this->assertSame('https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), $request->url());
            $this->assertSame('GET', $request->method());
            $this->assertSame(['true'], $request->header('Add-Padding'));
            $this->assertStringNotContainsString(substr($hash, 5), $request->url() . json_encode($request->headers()) . $request->body());
            $this->assertStringNotContainsString(self::LEAKED, $request->url() . $request->body());

            return true;
        });

        // The same at invite acceptance, with the sentence on the form.
        $token = $this->inviteToken();
        $this->accept($token, self::LEAKED)->assertSessionHasErrors(['password' => BreachedPasswords::MESSAGE]);

        // A password not in the list passes.
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => 'not-in-the-list-1'])->assertSessionHasNoErrors();
    }

    public function test_padding_entries_are_not_leaks(): void
    {
        config(['novarr.password_breach_check' => true]);
        $this->fakeRange('only-padding-here', 0);

        $this->assertFalse(app(BreachedPasswords::class)->appears('only-padding-here'));
    }

    public function test_the_check_is_not_asked_for_a_password_that_fails_another_rule(): void
    {
        config(['novarr.password_breach_check' => true]);
        Http::fake();

        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => 'short'])->assertSessionHasErrors(['password'], null, 'add');
        Http::assertNothingSent();
    }

    public function test_it_fails_open_and_warns_at_most_once_an_hour(): void
    {
        config(['novarr.password_breach_check' => true]);
        $down = true;
        Http::fake(function () use (&$down) {
            if ($down) {
                throw new ConnectionException('Operation timed out after 3000 milliseconds');
            }

            return Http::response('', 503);
        });
        Log::spy();

        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => self::LEAKED])->assertSessionHasNoErrors();
        $this->post(route('settings.users.store'), ['name' => 'Lee', 'username' => 'lee', 'password' => self::LEAKED])->assertSessionHasNoErrors();
        $this->assertSame(2, User::whereIn('username', ['kim', 'lee'])->count());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn($message) => str_contains($message, 'could not reach Have I Been Pwned'));

        // A server error fails open too.
        $down = false;
        $this->assertFalse(app(BreachedPasswords::class)->appears(self::LEAKED));
    }

    public function test_it_can_be_turned_off(): void
    {
        config(['novarr.password_breach_check' => false]);
        Http::fake();

        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => self::LEAKED])->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_the_env_switch(): void
    {
        $read = function (?string $value) {
            $value === null ? putenv('NOVARR_PASSWORD_BREACH_CHECK') : putenv('NOVARR_PASSWORD_BREACH_CHECK=' . $value);
            unset($_ENV['NOVARR_PASSWORD_BREACH_CHECK'], $_SERVER['NOVARR_PASSWORD_BREACH_CHECK']);
            \Illuminate\Support\Env::disablePutenv();
            \Illuminate\Support\Env::enablePutenv();

            return (require base_path('config/novarr.php'))['password_breach_check'];
        };
        try {
            $this->assertTrue($read(null));
            $this->assertTrue($read(''));
            $this->assertTrue($read('on'));
            $this->assertFalse($read('off'));
            $this->assertFalse($read('OFF'));
            $this->assertFalse($read('false'));
        } finally {
            putenv('NOVARR_PASSWORD_BREACH_CHECK=off');
            $_ENV['NOVARR_PASSWORD_BREACH_CHECK'] = 'off';
            \Illuminate\Support\Env::enablePutenv();
        }
    }

    public function test_every_form_says_twelve(): void
    {
        $users = $this->get(route('settings.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('At least 8', $users);
        $this->assertStringNotContainsString('minlength="8"', $users);
        $this->assertStringContainsString('minlength="12"', $users);
        $this->assertStringContainsString('At least 12 characters', $users);

        $token = $this->inviteToken();
        $invite = $this->get('/invite/' . $token)->assertOk()->getContent();
        $this->assertStringContainsString('At least 12 characters, and not your name or email.', $invite);
        $this->assertStringContainsString('minlength="12"', $invite);

        $this->enableSignIn();
        $this->makeReader();
        $account = $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('At least 12 characters, and not your name or email.', $account);
        $this->assertStringContainsString('minlength="12"', $account);
    }
}
