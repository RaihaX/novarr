<?php

namespace Tests\Feature;

use App\AuthEvent;
use App\Passkey;
use App\Services\Authenticator;
use App\Services\RecoveryCodes;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Settings → Users and the second step: each account's methods, "Reset
 * second step", emails set on Edit / Add and unique (case-insensitive),
 * sign-in by an email two accounts share refused, novarr:auth status
 * reporting the shared ones. Also the Account page's authenticator setup.
 */
class AdminSecondStepTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private function admin(): array
    {
        $owner = $this->enableSignIn();

        return $this->webSignIn($owner->username, self::OWNER_PASSWORD);
    }

    public function test_the_users_screen_shows_each_accounts_second_steps_and_resets_them(): void
    {
        $admin = $this->admin();
        $sam = $this->makeReader();
        $sam->forceFill(['email' => 'sam@example.test', 'email_codes_off_at' => now()])->save();
        $this->giveAuthenticator($sam);
        app(RecoveryCodes::class)->regenerate($sam);
        Passkey::create(['user_id' => $sam->id, 'rp_id' => 'read.example.com', 'credential_hash' => str_repeat('a', 64), 'credential_id' => 'AQID', 'public_key' => 'x', 'name' => 'Phone']);
        $kim = $this->makeReader('kim', 'Kim');
        config(['mail.default' => 'smtp']);

        $page = $this->withBrowser($admin)->get(route('settings.users'))->assertOk()->getContent();
        preg_match('#id="user-steps-' . $sam->id . '">(.*?)</span>#s', $page, $samSteps);
        $this->assertSame('Second step: 1 passkey, authenticator app, 10 recovery codes left', trim(preg_replace('/\s+/', ' ', $samSteps[1])));
        preg_match('#id="user-steps-' . $kim->id . '">(.*?)</span>#s', $page, $kimSteps);
        $this->assertStringContainsString('none set up', $kimSteps[1]);
        $this->assertStringContainsString('id="reset-steps-' . $sam->id . '"', $page);
        $this->assertStringNotContainsString('id="reset-steps-' . $kim->id . '"', $page);

        $phone = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($admin)->post(route('settings.users.second_step.reset', $sam->id))->assertRedirect(route('settings.users'))->assertSessionHas('status');
        $sam->refresh();
        $this->assertSame(0, Passkey::where('user_id', $sam->id)->count());
        $this->assertNull(app(Authenticator::class)->secretOf($sam));
        $this->assertSame(0, app(RecoveryCodes::class)->remaining($sam));
        $this->assertNull($sam->email_codes_off_at);
        $this->withBrowser($phone)->get('/stats')->assertRedirect(route('login'));
        $event = AuthEvent::where('event', AuthEvent::SECOND_STEP_RESET)->sole();
        $this->assertSame($sam->id, $event->user_id);
        $this->assertSame($this->owner()->id, $event->actor_user_id);
    }

    public function test_the_reset_is_home_network_only(): void
    {
        config(['novarr.public_hosts' => ['read.example.com']]);
        $this->enableSignIn();
        $sam = $this->makeReader();
        $this->asNobody()->postJson('http://read.example.com/settings/users/' . $sam->id . '/second-step/reset')->assertStatus(403);
    }

    public function test_emails_are_set_on_edit_and_add_and_unique_ignoring_case(): void
    {
        $admin = $this->admin();
        $sam = $this->makeReader();
        $kim = $this->makeReader('kim', 'Kim');

        $this->withBrowser($admin)->put(route('settings.users.update', $sam->id), ['name' => 'Sam', 'username' => 'sam', 'email' => 'Sam@Example.TEST'])->assertRedirect();
        $this->assertSame('sam@example.test', $sam->fresh()->email);
        $this->assertSame(['email'], AuthEvent::where('event', AuthEvent::ACCOUNT_EDITED)->latest('id')->first()->detail['changed']);

        $this->withBrowser($admin)->put(route('settings.users.update', $kim->id), ['name' => 'Kim', 'username' => 'kim', 'email' => 'SAM@example.test'])
            ->assertSessionHasErrors(['email' => 'Another account already has that email.'], null, 'edit' . $kim->id);
        // Its own email again is fine; clearing it too.
        $this->withBrowser($admin)->put(route('settings.users.update', $sam->id), ['name' => 'Sam', 'username' => 'sam', 'email' => 'sam@example.test'])->assertSessionHasNoErrors();
        $this->withBrowser($admin)->put(route('settings.users.update', $kim->id), ['name' => 'Kim', 'username' => 'kim', 'email' => ''])->assertSessionHasNoErrors();

        $this->withBrowser($admin)->post(route('settings.users.store'), ['name' => 'Lee', 'username' => 'lee', 'email' => 'sam@EXAMPLE.test', 'password' => 'lees-long-password'])
            ->assertSessionHasErrors(['email'], null, 'add');
        $this->withBrowser($admin)->post(route('settings.users.store'), ['name' => 'Lee', 'username' => 'lee', 'email' => 'lee@example.test', 'password' => 'lees-long-password'])->assertSessionHasNoErrors();
        $this->assertSame('lee@example.test', User::findByUsername('lee')->email);

        // An invite to an address an account has: refused (case-insensitive).
        $this->withBrowser($admin)->post(route('settings.users.invites.store'), ['name' => 'X', 'email' => 'LEE@example.test'])
            ->assertSessionHasErrors(['email'], null, 'invite');
    }

    public function test_sign_in_by_an_email_two_accounts_share_is_refused_and_reported(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $kim = $this->makeReader('kim', 'Kim');
        // Older data: the same address in two cases. Only SQLite can hold it
        // (its unique index on users.email is case-sensitive); MySQL/MariaDB's
        // index (case-insensitive collation) refuses it outright.
        DB::table('users')->where('id', $sam->id)->update(['email' => 'shared@example.test']);
        try {
            DB::table('users')->where('id', $kim->id)->update(['email' => 'Shared@Example.test']);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->assertNotSame('sqlite', DB::connection()->getDriverName());
            $this->assertNull(DB::table('users')->where('id', $kim->id)->value('email'));
            // So nothing is shared, and status reports nothing.
            $this->artisan('novarr:auth status')->doesntExpectOutputToContain('accounts share the email')->assertExitCode(0);
            $this->asNobody()->post('/login', ['username' => 'shared@example.test', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));

            return;
        }

        $this->asNobody()->post('/login', ['username' => 'shared@example.test', 'password' => self::READER_PASSWORD])
            ->assertSessionHasErrors(['username' => \App\Services\Credentials::WRONG]);
        $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'shared@example.test', 'password' => self::READER_PASSWORD])->assertStatus(422);
        // Their sign-in names still work.
        $this->asNobody()->post('/login', ['username' => 'kim', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));

        $this->artisan('novarr:auth status')
            ->expectsOutputToContain('2 accounts share the email shared@example.test')
            ->assertExitCode(0);
    }

    public function test_the_authenticator_app_is_set_up_with_a_server_drawn_qr_code_and_a_code(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);

        $this->withBrowser($cookies)->post(route('account.authenticator.start'))->assertRedirect(route('account') . '#authenticator');
        $page = $this->withBrowser($cookies)->get(route('account'))->assertOk()->getContent();
        preg_match('#id="totpQr">(.*?)</div>#s', $page, $qr);
        $this->assertStringStartsWith('<svg', trim($qr[1]));
        // Drawn here: no image from anywhere else.
        $this->assertStringNotContainsString('<img', $qr[1]);
        $this->assertStringNotContainsString('http', preg_replace('#xmlns(:\w+)?="[^"]*"#', '', $qr[1]));
        preg_match('#id="totpSecret">([A-Z2-7 ]+)</span>#', $page, $secret);
        $secret = str_replace(' ', '', $secret[1]);
        $this->assertSame(32, strlen($secret));

        // A wrong code: not turned on.
        $this->withBrowser($cookies)->post(route('account.authenticator.store'), ['code' => '000000'])->assertSessionHasErrors(['code'], null, 'authenticator');
        $this->assertNull(app(Authenticator::class)->secretOf($sam->fresh()));

        // The right one: on, stored encrypted, and that step used up.
        $this->withBrowser($cookies)->post(route('account.authenticator.store'), ['code' => $this->totpCode($secret)])->assertRedirect(route('account') . '#security');
        $sam->refresh();
        $this->assertSame($secret, app(Authenticator::class)->secretOf($sam));
        $this->assertStringNotContainsString($secret, (string) DB::table('users')->where('id', $sam->id)->value('totp_secret'));
        $this->assertFalse(app(Authenticator::class)->verify($sam, $this->totpCode($secret)));
        $this->assertSame('Authenticator app set up', AuthEvent::where('event', AuthEvent::METHOD_ADDED)->sole()->label());

        // Emailed codes can now be turned off; removing the authenticator turns them back on.
        $sam->forceFill(['email' => 'sam@example.test'])->save();
        $this->withBrowser($cookies)->post(route('account.email_codes'), ['on' => '0'])->assertRedirect();
        $this->assertNotNull($sam->fresh()->email_codes_off_at);
        $this->withBrowser($cookies)->delete(route('account.authenticator.destroy'))->assertRedirect();
        $this->assertNull($sam->fresh()->email_codes_off_at);
        $this->assertNull(app(Authenticator::class)->secretOf($sam->fresh()));
    }

    public function test_emailed_codes_cannot_be_turned_off_without_another_method_nor_recovery_codes_made(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $sam->forceFill(['email' => 'sam@example.test'])->save();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->post(route('account.email_codes'), ['on' => '0'])->assertSessionHas('error');
        $this->assertNull($sam->fresh()->email_codes_off_at);
        $this->withBrowser($cookies)->post(route('account.recovery_codes'))->assertSessionHas('error');
        $this->assertSame(0, app(RecoveryCodes::class)->remaining($sam));
    }
}
