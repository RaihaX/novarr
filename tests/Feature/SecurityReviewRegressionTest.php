<?php

namespace Tests\Feature;

use App\ApiToken;
use App\DevicePairing;
use App\Services\Authenticator;
use App\Services\Credentials;
use App\Services\RecoveryCodes;
use App\Services\SecondStep;
use App\Support\AuthMode;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Regression tests for the phase-2 security review (each was a working probe
 * against the first version): unlimited "Confirm it's you", tokens and
 * accounts made on the public address while sign-in is off, bearer tokens on
 * web pages, a link code shown by a bare pairing id, races in the second
 * step, unbound app challenges, remember-me rows older than "sign out
 * everywhere", the lock telling names apart, and caching of the Account page.
 */
class SecurityReviewRegressionTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC = 'http://read.example.com';

    private const LAN = 'http://novarr.lan';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com'], 'mail.default' => 'smtp']);
        Mail::fake();
    }

    // 1 ---------------------------------------------------------------------------

    public function test_confirm_its_you_cannot_be_used_to_guess_an_authenticator_code(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $this->giveAuthenticator($sam);
        $cookies = $this->cookiesOf($this->publicWebSignIn('sam', self::READER_PASSWORD));
        $this->travel(11)->minutes();

        $statuses = [];
        for ($i = 0; $i < 60; $i++) {
            $status = $this->withBrowser($cookies)->postJson(self::PUBLIC . '/account/confirm', ['method' => 'totp', 'code' => sprintf('%06d', $i)])->getStatusCode();
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }
        // At most 10 wrong codes are ever looked at (the account's lock), then 429.
        $this->assertLessThanOrEqual(10, ($statuses[422] ?? 0) + ($statuses[404] ?? 0));
        $this->assertGreaterThanOrEqual(50, $statuses[429] ?? 0);
        $this->assertNotNull(Credentials::accountLockRetryAfter($sam));
        // The 429 is the contract's shape, with the wait in words.
        $locked = $this->withBrowser($cookies)->postJson(self::PUBLIC . '/account/confirm', ['method' => 'totp', 'code' => '123456']);
        $locked->assertStatus(429)->assertJsonPath('code', 'throttled')->assertHeader('Retry-After');
        $this->assertStringStartsWith('Too many sign-in attempts. Try again in ', $locked->json('message'));
        // Even the right code is not looked at now.
        $sam->forceFill(['totp_last_step' => null])->save();
        $this->withBrowser($cookies)->postJson(self::PUBLIC . '/account/confirm', ['method' => 'totp', 'code' => $this->totpCode($this->giveAuthenticator($sam))])->assertStatus(429);
        // Nor can a code be emailed for it.
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/confirm/email')->assertStatus(429);
    }

    public function test_confirm_its_you_respects_the_public_per_ip_cap(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $this->giveAuthenticator($sam);
        $cookies = $this->cookiesOf($this->publicWebSignIn('sam', self::READER_PASSWORD));
        for ($i = 0; $i < Credentials::PUBLIC_MAX_ATTEMPTS; $i++) {
            Credentials::hitPublicCap('127.0.0.1');
        }
        $this->withBrowser($cookies)->post(self::PUBLIC . '/account/confirm', ['method' => 'totp', 'code' => '000000'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertSee('Too many sign-in attempts. Try again in');
        $this->assertSame(0, DB::table('second_step_challenges')->where('purpose', 'confirm')->count());
    }

    public function test_the_confirm_route_is_throttled_per_address(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        for ($i = 0; $i < 30; $i++) {
            $this->withBrowser($cookies)->post(route('account.confirm'), ['password' => 'x']);
        }
        $this->withBrowser($cookies)->postJson(route('account.confirm'), ['password' => 'x'])->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    // 2 ---------------------------------------------------------------------------

    public function test_sign_in_off_the_public_address_makes_no_tokens_and_no_accounts(): void
    {
        $owner = $this->owner();
        $owner->setNewPassword(self::OWNER_PASSWORD);
        AuthMode::store(false);
        $forbidden = ['message' => PublicAddress::SIGN_IN_OFF_MESSAGE, 'code' => 'forbidden'];

        $this->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => $owner->username, 'password' => self::OWNER_PASSWORD])
            ->assertStatus(403)->assertExactJson($forbidden);
        $this->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => str_repeat('a', 64)])->assertStatus(403)->assertExactJson($forbidden);
        $this->postJson(self::PUBLIC . '/api/v1/auth/token/second-step', ['challenge' => str_repeat('a', 64), 'method' => 'totp', 'code' => '123456'])
            ->assertStatus(403)->assertExactJson($forbidden);
        $this->assertSame(0, ApiToken::count());

        [, $token] = \App\UserInvite::issue('Kim', 'kim@example.test', $owner);
        $this->post(self::PUBLIC . '/invite/' . $token, ['name' => 'Kim', 'username' => 'kim', 'password' => 'kims-long-password', 'password_confirmation' => 'kims-long-password'])
            ->assertStatus(403)->assertSee(PublicAddress::SIGN_IN_OFF_MESSAGE);
        $this->assertNull(User::findByUsername('kim'));

        // The home network with sign-in off: exactly as before.
        $this->postJson(self::LAN . '/api/v1/auth/token', ['username' => $owner->username, 'password' => self::OWNER_PASSWORD])->assertOk()->assertJsonStructure(['token', 'user']);
        $this->post(self::LAN . '/invite/' . $token, ['name' => 'Kim', 'username' => 'kim', 'password' => 'kims-long-password', 'password_confirmation' => 'kims-long-password'])->assertOk();
        $this->assertNotNull(User::findByUsername('kim'));
    }

    // 3 ---------------------------------------------------------------------------

    public function test_an_app_token_does_not_open_the_account_page_or_its_security_actions(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $secret = $this->giveAuthenticator($sam);
        $token = $this->tokenFor($sam);

        $this->asToken($token)->get(self::PUBLIC . '/account')->assertRedirect('https://read.example.com/login');
        $this->asToken($token)->post(self::PUBLIC . '/account/confirm', ['method' => 'totp', 'code' => $this->totpCode($secret)])->assertRedirect('https://read.example.com/login');
        $this->assertSame(0, DB::table('second_step_challenges')->count());
        $this->asToken($token)->post(self::PUBLIC . '/account/catalogue-passwords', ['name' => 'x'])->assertRedirect('https://read.example.com/login');
        $this->assertSame(0, ApiToken::where('kind', ApiToken::KIND_CATALOGUE)->count());
        // The API keeps working with it.
        $this->asToken($token)->getJson(self::PUBLIC . '/api/v1/auth/me')->assertOk();
    }

    // 4 ---------------------------------------------------------------------------

    public function test_a_stale_link_confirmation_never_shows_another_devices_pending_code(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->travel(11)->minutes();
        [$victim] = DevicePairing::begin('victim-kindle', 'Victim Kindle');

        $response = $this->withBrowser($cookies)->post(route('account.link.confirm'), ['pairing' => $victim->id, 'code' => 'XXXXXX']);
        $response->assertRedirect(route('account') . '#confirm')->assertSessionMissing('pairing_id');
        $page = $this->withBrowser(array_merge($cookies, $this->cookiesOf($response)))->get(route('account'))->getContent();
        $this->assertStringNotContainsString($victim->code, $page);
        $this->assertStringNotContainsString('Victim Kindle', $page);

        // The right code for that pairing (the one the person typed) is kept across the confirmation.
        $this->withBrowser($cookies)->post(route('account.link.confirm'), ['pairing' => $victim->id, 'code' => $victim->code])
            ->assertSessionHas('pairing_id', $victim->id);
        $this->assertNull($victim->fresh()->user_id);
    }

    // 5 ---------------------------------------------------------------------------

    private function samWithChallenge(): array
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $secret = $this->giveAuthenticator($sam);
        [$challenge] = app(SecondStep::class)->begin($sam, SecondStep::SIGN_IN, 'api', 'phone-1');

        return [$sam->fresh(), $secret, $challenge];
    }

    private function publicRequest(): Request
    {
        $request = Request::create(self::PUBLIC . '/api/v1/auth/token/second-step', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_a_try_is_reserved_before_the_answer_is_looked_at(): void
    {
        [$sam, , $challenge] = $this->samWithChallenge();
        $codes = app(RecoveryCodes::class)->regenerate($sam);
        // Four tries used by requests running in parallel with this one.
        DB::table('second_step_challenges')->where('id', $challenge->id)->update(['wrong_tries' => SecondStep::MAX_WRONG]);

        [$outcome] = app(SecondStep::class)->check($challenge, $sam, SecondStep::RECOVERY, $codes[0], $this->publicRequest());
        $this->assertSame(SecondStep::EXPIRED, $outcome);
        // Not looked at, so not spent.
        $this->assertSame(10, app(RecoveryCodes::class)->remaining($sam));
    }

    public function test_a_request_that_loses_the_race_spends_no_recovery_code_and_no_time_step(): void
    {
        [$sam, $secret, $challenge] = $this->samWithChallenge();
        $codes = app(RecoveryCodes::class)->regenerate($sam);

        // Another request finishes the challenge between our check and our consume.
        $this->app->instance(RecoveryCodes::class, new class extends RecoveryCodes {
            public ?int $finish = null;

            public function isUnused(User $user, ?string $input): bool
            {
                $right = parent::isUnused($user, $input);
                DB::table('second_step_challenges')->where('id', $this->finish)->delete();

                return $right;
            }
        });
        app(RecoveryCodes::class)->finish = (int) $challenge->id;
        $this->app->forgetInstance(SecondStep::class);
        [$outcome] = app(SecondStep::class)->check($challenge, $sam, SecondStep::RECOVERY, $codes[0], $this->publicRequest());
        $this->assertSame(SecondStep::EXPIRED, $outcome);
        $this->assertSame(10, (new RecoveryCodes())->remaining($sam));

        // The same for the authenticator: the time step stays unused.
        [$second] = app(SecondStep::class)->begin($sam, SecondStep::SIGN_IN, 'api', 'phone-1');
        $this->app->instance(Authenticator::class, new class extends Authenticator {
            public ?int $finish = null;

            public function rightStep(User $user, ?string $code): ?int
            {
                $step = parent::rightStep($user, $code);
                DB::table('second_step_challenges')->where('id', $this->finish)->delete();

                return $step;
            }
        });
        app(Authenticator::class)->finish = (int) $second->id;
        $this->app->forgetInstance(SecondStep::class);
        [$outcome] = app(SecondStep::class)->check($second, $sam, SecondStep::TOTP, $this->totpCode($secret), $this->publicRequest());
        $this->assertSame(SecondStep::EXPIRED, $outcome);
        $this->assertNull($sam->fresh()->totp_last_step);
    }

    public function test_the_failure_is_counted_before_checking_so_a_burst_cannot_pass_the_lock(): void
    {
        [$sam, $secret, $challenge] = $this->samWithChallenge();
        // Ten failures already counted by parallel requests that have not answered yet.
        for ($i = 0; $i < Credentials::ACCOUNT_MAX_FAILURES; $i++) {
            RateLimiter::hit(Credentials::accountFailuresKey($sam), Credentials::ACCOUNT_WINDOW_SECONDS);
        }
        [$outcome, $seconds] = app(SecondStep::class)->check($challenge, $sam, SecondStep::TOTP, $this->totpCode($secret), $this->publicRequest());
        $this->assertSame(SecondStep::LOCKED, $outcome);
        $this->assertGreaterThan(0, $seconds);
        // The right code was not looked at, so not used.
        $this->assertNull($sam->fresh()->totp_last_step);
    }

    public function test_a_right_answer_takes_its_counted_failure_back(): void
    {
        [$sam, $secret, $challenge] = $this->samWithChallenge();
        $request = $this->publicRequest();
        [$outcome] = app(SecondStep::class)->check($challenge, $sam, SecondStep::TOTP, $this->totpCode($secret), $request);
        $this->assertSame(SecondStep::OK, $outcome);
        $this->assertSame(0, RateLimiter::attempts(Credentials::accountFailuresKey($sam)));
        $this->assertSame(0, RateLimiter::attempts(Credentials::publicCapKey('203.0.113.5')));
    }

    // 7 ---------------------------------------------------------------------------

    public function test_a_remember_row_made_before_sign_out_everywhere_does_not_sign_in(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $remember = array_filter($cookies, fn($n) => str_starts_with($n, 'remember_web_'), ARRAY_FILTER_USE_KEY);
        // A row that slipped in while "sign out everywhere" ran (not deleted by it).
        $sam->forceFill(['sessions_valid_after' => now()->addSecond()])->save();
        $this->travel(2)->seconds();

        $this->withBrowser($remember)->get('/stats')->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $sam->id)->count());

        // A sign-in right after (the same second) is not caught by it.
        $sam->signOutEverywhere(keepTokens: true);
        $fresh = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser(array_filter($fresh, fn($n) => str_starts_with($n, 'remember_web_'), ARRAY_FILTER_USE_KEY))->get('/stats')->assertOk();
    }

    // 8 ---------------------------------------------------------------------------

    public function test_the_lock_answers_the_same_for_an_accounts_email_and_an_address_nobody_has(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $sam->forceFill(['email' => 'sam@example.test'])->save();
        $this->giveAuthenticator($sam);

        $outcomes = [];
        foreach (['sam@example.test', 'nobody@example.test'] as $name) {
            for ($i = 1; $i <= 10; $i++) {
                $this->asNobody()->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => $name, 'password' => 'wrong-' . $i]);
            }
            $outcomes[$name] = $this->asNobody()->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => $name, 'password' => 'x'])->json();
            $outcomes[$name]['message'] = preg_replace('/\d+/', 'N', $outcomes[$name]['message']);
            $outcomes[$name]['retry_after'] = 'N';
        }
        $this->assertSame($outcomes['sam@example.test'], $outcomes['nobody@example.test']);
        $this->assertSame('throttled', $outcomes['nobody@example.test']['code']);
        // Locking the email does not lock the sign-in name: nothing ties the two.
        $this->asNobody()->withServerVariables(['REMOTE_ADDR' => '198.51.100.51'])->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])
            ->assertStatus(401)->assertJsonPath('code', 'second_step_required');
    }

    // 9 ---------------------------------------------------------------------------

    public function test_the_account_page_is_never_cached(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->post(route('account.authenticator.start'));
        $page = $this->withBrowser($cookies)->get(route('account'))->assertOk()->assertSee('id="totpSecret"', false);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $page->headers->get('Cache-Control'));
    }
}
