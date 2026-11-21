<?php

namespace Tests\Feature;

use App\ApiToken;
use App\AuthEvent;
use App\DevicePairing;
use App\Mail\SignInCode;
use App\Services\Authenticator;
use App\Services\RecoveryCodes;
use App\Support\RecentConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Sensitive Account changes need "Confirm it's you" within the last 10
 * minutes (App\Support\RecentConfirmation): the password at home, a second
 * step on the public address; signing in counts. Wrong passwords there are
 * limited to 5 per 15 minutes per account.
 */
class RecentConfirmationTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC = 'http://read.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com'], 'mail.default' => 'smtp']);
        Mail::fake();
    }

    /** Every sensitive change, as [method, route, data]. */
    private function sensitive(\App\User $sam): array
    {
        return [
            'authenticator setup' => ['post', route('account.authenticator.start'), []],
            'authenticator removal' => ['delete', route('account.authenticator.destroy'), []],
            'recovery codes' => ['post', route('account.recovery_codes'), []],
            'emailed codes' => ['post', route('account.email_codes'), ['on' => '0']],
            'catalogue password' => ['post', route('account.catalogue.store'), ['name' => 'KOReader']],
            'passkey removal' => ['delete', route('account.passkeys.destroy', 999), []],
        ];
    }

    public function test_every_sensitive_change_asks_to_confirm_after_ten_minutes(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $this->giveAuthenticator($sam);
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->travel(11)->minutes();

        foreach ($this->sensitive($sam) as $what => [$method, $url, $data]) {
            $this->withBrowser($cookies)->{$method}($url, $data)->assertRedirect(route('account') . '#confirm');
        }
        $this->withBrowser($cookies)->postJson(route('account.passkeys.options'))->assertStatus(428)
            ->assertExactJson(['code' => 'confirm_required', 'message' => RecentConfirmation::NEEDED_MESSAGE, 'redirect' => route('account') . '#confirm']);
        $this->withBrowser($cookies)->postJson(route('account.passkeys.store'), ['credential' => []])->assertStatus(428);
        // Nothing changed.
        $this->assertNotNull(app(Authenticator::class)->secretOf($sam->fresh()));
        $this->assertSame(0, app(RecoveryCodes::class)->remaining($sam));
        $this->assertSame(0, ApiToken::where('kind', ApiToken::KIND_CATALOGUE)->count());
        $this->assertNull($sam->fresh()->email_codes_off_at);

        // The page shows the confirm step.
        $page = $this->withBrowser($cookies)->get(route('account'))->getContent();
        $this->assertStringContainsString("Confirm it's you", $page);
        $this->assertStringContainsString('id="confirm_password"', $page);

        // Confirmed with the password at home: the changes go through for 10 minutes.
        $this->withBrowser($cookies)->post(route('account.confirm'), ['password' => self::READER_PASSWORD])->assertRedirect(route('account') . '#security');
        $this->withBrowser($cookies)->post(route('account.recovery_codes'))->assertRedirect(route('account') . '#recovery');
        $this->assertSame(10, app(RecoveryCodes::class)->remaining($sam));
        $this->assertStringNotContainsString('id="confirm_password"', $this->withBrowser($cookies)->get(route('account'))->getContent());
    }

    public function test_signing_in_counts_as_confirming(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $this->giveAuthenticator($sam);
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->post(route('account.recovery_codes'))->assertRedirect(route('account') . '#recovery');
    }

    public function test_a_wrong_password_is_limited_to_five_per_fifteen_minutes(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->travel(11)->minutes();

        for ($i = 0; $i < 5; $i++) {
            $this->withBrowser($cookies)->post(route('account.confirm'), ['password' => 'nope'])
                ->assertRedirect(route('account') . '#confirm')->assertSessionHasErrors(['password'], null, 'confirm');
        }
        $limited = $this->withBrowser($cookies)->post(route('account.confirm'), ['password' => self::READER_PASSWORD]);
        $limited->assertStatus(429)->assertHeader('Retry-After')->assertSee('Too many wrong passwords. Try again in');
        // The current password when changing it is the same limit.
        $this->withBrowser($cookies)->post(route('account.password'), [
            'current_password' => self::READER_PASSWORD, 'password' => 'a-brand-new-pass', 'password_confirmation' => 'a-brand-new-pass',
        ])->assertStatus(429);
        $this->assertSame(5, AuthEvent::where('event', AuthEvent::CONFIRM_FAILED)->count());

        $this->travel(16)->minutes();
        $this->withBrowser($cookies)->post(route('account.confirm'), ['password' => self::READER_PASSWORD])->assertRedirect(route('account') . '#security');
    }

    public function test_on_the_public_address_confirming_takes_a_second_step_not_the_password(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $sam->forceFill(['email' => 'sam@example.test'])->save();
        $secret = $this->giveAuthenticator($sam);
        $cookies = $this->cookiesOf($this->publicWebSignIn('sam', self::READER_PASSWORD));
        $this->travel(11)->minutes();

        $page = $this->withBrowser($cookies)->get(self::PUBLIC . '/account')->getContent();
        $this->assertStringNotContainsString('id="confirm_password"', $page);
        $this->assertStringContainsString('A code from your authenticator app', $page);
        $this->assertStringContainsString('Email me a code', $page);

        // The password is no confirmation there.
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/confirm', ['password' => self::READER_PASSWORD])->assertRedirect('https://read.example.com/account#confirm');
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/recovery-codes')->assertRedirect('https://read.example.com/account#confirm');
        // Nor is changing the password with only the current one.
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/password', [
            'current_password' => self::READER_PASSWORD, 'password' => 'a-brand-new-pass', 'password_confirmation' => 'a-brand-new-pass',
        ])->assertRedirect('https://read.example.com/account#confirm');

        // Emailed codes for confirming say so.
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/confirm/email')->assertRedirect('https://read.example.com/account#confirm');
        Mail::assertSent(SignInCode::class, fn(SignInCode $m) => !$m->signIn);
        // The authenticator: a wrong code counts …
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/confirm', ['method' => 'totp', 'code' => '000000'])->assertSessionHasErrors(['code'], null, 'confirm');
        $this->assertSame('confirm', AuthEvent::where('event', AuthEvent::SECOND_STEP_FAILED)->latest('id')->first()->detail['purpose']);
        // (a browser without a challenge yet gets one with its first code)
        $this->assertSame(1, DB::table('second_step_challenges')->where('purpose', 'confirm')->count());
        // … the right one confirms.
        $sam->forceFill(['totp_last_step' => null])->save();
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/confirm', ['method' => 'totp', 'code' => $this->totpCode($secret)])->assertRedirect('https://read.example.com/account#security');
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/recovery-codes')->assertRedirect('https://read.example.com/account#recovery');
        $this->assertSame('Confirmed it was them with the authenticator app', AuthEvent::where('event', AuthEvent::SECOND_STEP_PASSED)->latest('id')->first()->label());
    }

    public function test_confirming_a_device_link_code_needs_a_recent_confirmation_and_keeps_the_device(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        [$pairing] = DevicePairing::begin('kindle-1', 'Kindle Paperwhite');
        $this->travel(11)->minutes();
        DB::table('device_pairings')->update(['expires_at' => now()->addMinutes(5)]);

        $this->withBrowser($cookies)->post(route('account.link.confirm'), ['pairing' => $pairing->id, 'code' => $pairing->code])
            ->assertRedirect(route('account') . '#confirm')->assertSessionHas('pairing_id', $pairing->id);
        $this->assertNull($pairing->fresh()->user_id);

        $this->withBrowser($cookies)->post(route('account.confirm'), ['password' => self::READER_PASSWORD]);
        $this->withBrowser($cookies)->post(route('account.link.confirm'), ['pairing' => $pairing->id, 'code' => $pairing->code])
            ->assertRedirect(route('account') . '#link')->assertSessionHas('status');
        $this->assertNotNull($pairing->fresh()->user_id);
    }

    public function test_the_page_keeps_the_names_account_and_link_a_device(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $page = $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="page-title">Account</h1>', $page);
        $this->assertStringContainsString('id="linkTitle">Link a device</h2>', $page);
    }
}
