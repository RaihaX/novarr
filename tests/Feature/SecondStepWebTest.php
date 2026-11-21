<?php

namespace Tests\Feature;

use App\AuthEvent;
use App\Mail\NewSignIn;
use App\Mail\SignInCode;
use App\Services\RecoveryCodes;
use App\Services\SecondStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The web second step (/login/second-step): after a correct password on the
 * public address, never on the home network; methods in the order passkey,
 * authenticator, emailed code, recovery code; the sign-in (history, new
 * device email, remember-me) completes only after it.
 */
class SecondStepWebTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC = 'http://read.example.com';

    private const LAN = 'http://novarr.lan';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com'], 'mail.default' => 'smtp']);
        Mail::fake();
    }

    private function sam(bool $authenticator = true, ?string $email = 'sam@example.test')
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $sam->forceFill(['email' => $email])->save();
        if ($authenticator) {
            $this->secret = $this->giveAuthenticator($sam);
        }

        return $sam->fresh();
    }

    /** The password on the public address; returns the browser's cookies. */
    private function password(string $password = self::READER_PASSWORD): array
    {
        $response = $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => $password]);

        return $this->cookiesOf($response);
    }

    private function answer(array $cookies, string $method, string $code)
    {
        return $this->withBrowser($cookies)->post(self::PUBLIC . '/login/second-step', ['method' => $method, 'code' => $code]);
    }

    public function test_the_public_address_asks_for_a_second_step_after_the_password(): void
    {
        $this->sam();
        $first = $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $this->assertSame('https://read.example.com/login/second-step', $first->headers->get('Location'));
        $cookies = $this->cookiesOf($first);
        // No remember-me cookie yet, nothing signed in, nothing recorded as a sign-in.
        $this->assertEmpty(array_filter(array_keys($cookies), fn($n) => str_starts_with($n, 'remember_web_')));
        $this->withBrowser($cookies)->get(self::PUBLIC . '/')->assertRedirect();
        $this->withBrowser($cookies)->get(self::PUBLIC . '/stats')->assertRedirect('https://read.example.com/login');
        $this->assertSame(0, AuthEvent::where('event', AuthEvent::SIGN_IN)->count());
        $this->assertSame(0, DB::table('browser_sessions')->count());
        Mail::assertNotSent(NewSignIn::class);

        $page = $this->withBrowser($cookies)->get(self::PUBLIC . '/login/second-step')->assertOk();
        $page->assertHeader('Cache-Control', 'no-store, private');
        $page->assertSee('One more step');
        $page->assertDontSee('2FA');
        $page->assertDontSee('cf-turnstile', false);
    }

    public function test_the_home_network_needs_only_the_password(): void
    {
        $this->sam();
        $this->asNobody()->post(self::LAN . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));
        $this->assertSame(0, DB::table('second_step_challenges')->count());
    }

    public function test_the_page_offers_the_methods_in_order_and_never_sends_an_email_by_itself(): void
    {
        $sam = $this->sam();
        app(RecoveryCodes::class)->regenerate($sam);
        $page = $this->withBrowser($this->password())->get(self::PUBLIC . '/login/second-step')->getContent();

        $positions = array_map(fn($id) => strpos($page, 'id="' . $id . '"'), ['totp', 'email', 'recovery']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringContainsString('Email me a code', $page);
        $this->assertStringContainsString('s•••@e•••.test', $page);
        $this->assertStringNotContainsString('data-passkey-second-step', $page);
        Mail::assertNotSent(SignInCode::class);
    }

    public function test_the_authenticator_code_completes_the_sign_in_and_remembers_the_browser_with_the_second_step(): void
    {
        $sam = $this->sam();
        // A device the account knew, so a new one sends the email.
        DB::table('known_devices')->insert(['user_id' => $sam->id, 'device_hash' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now(), 'label' => 'Old']);
        $cookies = $this->password();

        $done = $this->answer($cookies, 'totp', $this->totpCode($this->secret));
        $done->assertRedirect('https://read.example.com');
        $browser = $this->cookiesOf($done);
        $this->withBrowser($browser)->get(self::PUBLIC . '/stats')->assertOk();

        $row = DB::table('remember_tokens')->sole();
        $this->assertNotNull($row->second_step_passed_at);
        $this->assertSame($sam->id, (int) $row->user_id);
        $signIn = AuthEvent::where('event', AuthEvent::SIGN_IN)->sole();
        $this->assertSame(['via' => 'web', 'method' => 'totp'], $signIn->detail);
        $this->assertSame('Second step passed with the authenticator app', AuthEvent::where('event', AuthEvent::SECOND_STEP_PASSED)->sole()->label());
        Mail::assertSent(NewSignIn::class, 1);
        $this->assertSame(0, DB::table('second_step_challenges')->count());
    }

    public function test_wrong_codes_are_counted_and_the_fifth_starts_again(): void
    {
        $this->sam();
        $cookies = $this->password();

        for ($left = 4; $left >= 1; $left--) {
            $this->answer($cookies, 'totp', '000000')->assertRedirect('https://read.example.com/login/second-step#totp')
                ->assertSessionHasErrors(['totp' => 'That code is not right. Check it and try again. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.']);
        }
        $this->answer($cookies, 'totp', '000000')->assertRedirect('https://read.example.com/login')
            ->assertSessionHasErrors(['username' => SecondStep::TOO_MANY_WRONG_MESSAGE]);
        // The right code now: start again.
        $this->answer($cookies, 'totp', $this->totpCode($this->secret))->assertRedirect('https://read.example.com/login')
            ->assertSessionHasErrors(['username' => SecondStep::EXPIRED_MESSAGE]);
        $this->assertSame(5, AuthEvent::where('event', AuthEvent::SECOND_STEP_FAILED)->count());
        $this->assertSame(0, AuthEvent::where('event', AuthEvent::SIGN_IN)->count());
    }

    public function test_the_pending_sign_in_lasts_ten_minutes(): void
    {
        $this->sam();
        $cookies = $this->password();
        $this->travel(11)->minutes();
        $this->withBrowser($cookies)->get(self::PUBLIC . '/login/second-step')->assertRedirect('https://read.example.com/login');
        $this->answer($cookies, 'totp', $this->totpCode($this->secret))->assertRedirect('https://read.example.com/login');
    }

    public function test_an_emailed_code_signs_in_after_email_me_a_code(): void
    {
        $this->sam(authenticator: false);
        $cookies = $this->password();
        $page = $this->withBrowser($cookies)->get(self::PUBLIC . '/login/second-step')->getContent();
        $this->assertStringNotContainsString('id="email-code"', $page);

        $this->withBrowser($cookies)->post(self::PUBLIC . '/login/second-step/email')->assertRedirect('https://read.example.com/login/second-step#email');
        $code = null;
        Mail::assertSent(SignInCode::class, function (SignInCode $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('sam@example.test') && $mail->signIn;
        });
        $this->assertStringContainsString('id="email-code"', $this->withBrowser($cookies)->get(self::PUBLIC . '/login/second-step')->getContent());

        $this->answer($cookies, 'email', '999999' === $code ? '111111' : '999999')->assertSessionHasErrors('email');
        $this->answer($cookies, 'email', $code)->assertRedirect('https://read.example.com');
        $this->assertSame('email', AuthEvent::where('event', AuthEvent::SECOND_STEP_PASSED)->sole()->detail['method']);
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $sam = $this->sam();
        $codes = app(RecoveryCodes::class)->regenerate($sam);
        $this->answer($this->password(), 'recovery', $codes[3])->assertRedirect('https://read.example.com');
        $this->answer($this->password(), 'recovery', $codes[3])->assertSessionHasErrors('recovery');
        $this->assertSame(9, app(RecoveryCodes::class)->remaining($sam));
    }

    public function test_no_usable_method_is_refused_after_the_right_password_only(): void
    {
        $sam = $this->sam(authenticator: false, email: null);
        $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => 'wrong-one'])
            ->assertSessionHasErrors(['username' => \App\Services\Credentials::WRONG]);
        $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])
            ->assertSessionHasErrors(['username' => SecondStep::UNAVAILABLE_MESSAGE]);
        $this->assertSame(0, DB::table('second_step_challenges')->count());
        // Mail not set up: an email alone is no method.
        $sam->forceFill(['email' => 'sam@example.test'])->save();
        config(['mail.default' => 'log']);
        $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])
            ->assertSessionHasErrors(['username' => SecondStep::UNAVAILABLE_MESSAGE]);
    }

    public function test_a_remembered_browser_that_passed_the_second_step_stays_signed_in_on_the_public_address(): void
    {
        $this->sam();
        $browser = $this->cookiesOf($this->publicWebSignIn('sam', self::READER_PASSWORD));
        $remember = array_filter($browser, fn($n) => str_starts_with($n, 'remember_web_'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $remember);

        // The session is gone; the remember-me cookie alone signs in, no second step.
        $this->travel(3)->hours();
        $this->withBrowser($remember)->get(self::PUBLIC . '/stats')->assertOk();
        // And typing the password again with that cookie skips the second step too.
        $again = $this->withBrowser($remember)->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $again->assertRedirect('https://read.example.com');
    }

    public function test_a_remembered_browser_from_the_home_network_does_not_skip_the_second_step(): void
    {
        $this->sam();
        // Signed in at home on a hostname that is also the public one (split DNS).
        config(['novarr.public_hosts' => ['other.example.com']]);
        $home = $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $home->assertRedirect();
        $cookies = $this->cookiesOf($home);
        $this->assertNull(DB::table('remember_tokens')->value('second_step_passed_at'));
        config(['novarr.public_hosts' => ['read.example.com']]);

        // Its session does not count on the public address …
        $this->withBrowser($cookies)->get(self::PUBLIC . '/stats')->assertRedirect('https://read.example.com/login');
        // … nor does its remember-me cookie alone …
        $remember = array_filter($cookies, fn($n) => str_starts_with($n, 'remember_web_'), ARRAY_FILTER_USE_KEY);
        $this->withBrowser($remember)->get(self::PUBLIC . '/stats')->assertRedirect('https://read.example.com/login');
        // … nor does it skip the second step after the password.
        $this->assertSame('https://read.example.com/login/second-step', $this->withBrowser($remember)->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])->headers->get('Location'));
        // The row is left alone (it still works at home).
        $this->assertSame(1, DB::table('remember_tokens')->count());
    }

    public function test_an_invite_accepted_on_the_public_address_goes_on_to_the_second_step(): void
    {
        $this->enableSignIn();
        [$invite, $token] = \App\UserInvite::issue('Kim', 'kim@example.test', $this->owner());
        $made = $this->asNobody()->post(self::PUBLIC . '/invite/' . $token, ['name' => 'Kim', 'username' => 'kim', 'password' => 'kims-long-password', 'password_confirmation' => 'kims-long-password']);
        $this->assertSame('https://read.example.com/login/second-step', $made->headers->get('Location'));
        $cookies = $this->cookiesOf($made);
        $this->withBrowser($cookies)->get(self::PUBLIC . '/stats')->assertRedirect();
        $this->assertStringContainsString('Email me a code', $this->withBrowser($cookies)->get(self::PUBLIC . '/login/second-step')->getContent());
        $this->assertNotNull($invite->fresh()->accepted_at);
    }

    public function test_sign_in_off_is_unchanged(): void
    {
        $this->enableSignIn();
        \App\Support\AuthMode::store(false);
        $this->makeReader();
        $this->asNobody()->get(self::LAN . '/stats')->assertOk();
        $this->asNobody()->post(self::LAN . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));
        $this->asNobody()->get(self::LAN . '/login/second-step')->assertRedirect(route('login'));
        $this->asNobody()->get(self::PUBLIC . '/stats')->assertForbidden();
    }
}
