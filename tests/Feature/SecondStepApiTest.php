<?php

namespace Tests\Feature;

use App\ApiToken;
use App\AuthEvent;
use App\Mail\NewSignIn;
use App\Mail\SignInCode;
use App\Passkey;
use App\Services\RecoveryCodes;
use App\Services\SecondStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The apps' second step (contract v1 + v1.1): POST /api/v1/auth/token on the
 * public address answers 401 second_step_required with a challenge, then
 * POST /auth/token/email-code and /auth/token/second-step. The JSON is
 * checked byte for byte (only the random challenge and token vary).
 */
class SecondStepApiTest extends TestCase
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

    /** Sam, with an email (codes can be emailed) and an authenticator app. */
    private function sam(bool $authenticator = true, ?string $email = 'reyhan@atomic.com.au')
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $sam->forceFill(['email' => $email])->save();
        if ($authenticator) {
            $this->secret = $this->giveAuthenticator($sam);
        }

        return $sam->fresh();
    }

    private function password(array $extra = [], string $host = self::PUBLIC)
    {
        return $this->asNobody()->postJson($host . '/api/v1/auth/token', $extra + ['username' => 'sam', 'password' => self::READER_PASSWORD, 'device_id' => 'phone-1', 'device_name' => 'Sam’s Pixel']);
    }

    private function step(string $challenge, string $method, string $code, array $extra = [])
    {
        return $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/second-step', $extra + ['challenge' => $challenge, 'method' => $method, 'code' => $code]);
    }

    private function emailedCode(): string
    {
        $code = null;
        Mail::assertSent(SignInCode::class, function (SignInCode $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    public function test_the_401_challenge_is_exactly_the_contracts(): void
    {
        $this->sam();
        $response = $this->password()->assertStatus(401);
        $challenge = $response->json('challenge');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge);

        $this->assertSame(
            '{"code":"second_step_required","message":"Enter your sign-in code to finish signing in.","challenge":"' . $challenge . '","expires_in":600,"methods":["totp","email"],"email_hint":"r•••@a•••.com.au"}',
            $response->getContent(),
        );
        // Stored hashed, bound to the account and the device.
        $row = DB::table('second_step_challenges')->sole();
        $this->assertSame(hash('sha256', $challenge), $row->token_hash);
        $this->assertSame('phone-1', $row->device_id);
        $this->assertStringNotContainsString($challenge, json_encode(DB::table('second_step_challenges')->get()));
        // No token, no sign-in yet: only the failure-free password check happened.
        $this->assertSame(0, ApiToken::count());
        $this->assertSame(0, AuthEvent::where('event', AuthEvent::SIGN_IN)->count());
        Mail::assertNotSent(NewSignIn::class);
        Mail::assertNotSent(SignInCode::class);
    }

    public function test_methods_list_only_what_the_account_can_use_and_email_hint_comes_with_email(): void
    {
        // Email only: the baseline, no setup.
        $sam = $this->sam(authenticator: false, email: 'sam@example.test');
        $only = $this->password()->assertStatus(401);
        $this->assertSame(['email'], $only->json('methods'));
        $this->assertSame('s•••@e•••.test', $only->json('email_hint'));

        // Authenticator + recovery codes, emailed codes turned off: no email, no email_hint.
        $this->giveAuthenticator($sam);
        app(RecoveryCodes::class)->regenerate($sam);
        $sam->forceFill(['email_codes_off_at' => now()])->save();
        $body = $this->password()->assertStatus(401)->json();
        $this->assertSame(['totp', 'recovery'], $body['methods']);
        $this->assertArrayNotHasKey('email_hint', $body);

        // Mail not set up: no email method either.
        $sam->forceFill(['email_codes_off_at' => null])->save();
        config(['mail.default' => 'log']);
        $this->assertSame(['totp', 'recovery'], $this->password()->json('methods'));
    }

    public function test_no_usable_method_is_403_with_the_contracts_message(): void
    {
        $this->sam(authenticator: false, email: null);
        $response = $this->password()->assertStatus(403);
        $this->assertSame('{"code":"second_step_unavailable","message":"This account needs a second sign-in step before it can be used here. Set one up from your home network, or ask the person who runs this Novarr."}', $response->getContent());
        $this->assertSame('no_second_step', AuthEvent::where('event', AuthEvent::SIGN_IN_FAILED)->sole()->detail['reason']);
        $this->assertSame(0, DB::table('second_step_challenges')->count());
    }

    public function test_a_passkey_only_account_is_told_to_link_with_a_code_never_given_an_empty_challenge(): void
    {
        $sam = $this->sam(authenticator: false, email: null);
        Passkey::create(['user_id' => $sam->id, 'rp_id' => 'read.example.com', 'credential_hash' => str_repeat('a', 64), 'credential_id' => 'AQID', 'public_key' => 'x', 'name' => 'Phone']);
        $response = $this->password()->assertStatus(403);
        $this->assertSame('{"code":"second_step_unavailable","message":"This account signs in with a passkey. Link this device with a code instead: on a phone or computer where you are signed in, open Account → Link a device."}', $response->getContent());
        $this->assertSame(0, DB::table('second_step_challenges')->count());
    }

    public function test_the_authenticator_code_finishes_the_sign_in(): void
    {
        $this->sam();
        $challenge = $this->password()->json('challenge');

        $done = $this->step($challenge, 'totp', $this->totpCode($this->secret))->assertOk();
        $token = $done->json('token');
        $this->assertMatchesRegularExpression('/^nvr_[A-Za-z0-9]{60}$/', $token);
        $sam = \App\User::findByUsername('sam');
        $this->assertSame('{"token":"' . $token . '","user":{"id":' . $sam->id . ',"name":"Sam","username":"sam","role":"reader"}}', $done->getContent());

        // The token is the device's (name and id from the password request).
        $issued = ApiToken::sole();
        $this->assertSame('Sam’s Pixel', $issued->name);
        $this->assertSame('phone-1', $issued->device_id);
        $this->asToken($token)->getJson(self::PUBLIC . '/api/v1/auth/me')->assertOk()->assertJsonPath('user.username', 'sam');

        // Consumed: the same challenge again is 404.
        $again = $this->step($challenge, 'totp', $this->totpCode($this->secret, 1));
        $again->assertStatus(404);
        $this->assertSame('{"code":"challenge_expired","message":"That took too long. Sign in again."}', $again->getContent());

        // The sign-in is recorded when it completes, with the method.
        $signIn = AuthEvent::where('event', AuthEvent::SIGN_IN)->sole();
        $this->assertSame(['via' => 'api', 'device' => 'Sam’s Pixel', 'method' => 'totp'], $signIn->detail);
        $this->assertTrue($signIn->public);
        $this->assertSame('totp', AuthEvent::where('event', AuthEvent::SECOND_STEP_PASSED)->sole()->detail['method']);
        $this->assertSame('second_step', AuthEvent::where('event', AuthEvent::TOKEN_ISSUED)->sole()->detail['via']);
    }

    public function test_a_wrong_code_is_422_with_tries_left_and_the_fifth_ends_the_challenge(): void
    {
        $this->sam();
        $challenge = $this->password()->json('challenge');

        foreach ([4, 3, 2, 1] as $left) {
            $wrong = $this->step($challenge, 'totp', '000000')->assertStatus(422);
            $this->assertSame('{"code":"wrong_code","message":"That code is not right. Check it and try again.","tries_left":' . $left . '}', $wrong->getContent());
        }
        // The 5th: the challenge is gone, never "tries_left": 0.
        $fifth = $this->step($challenge, 'totp', '000000')->assertStatus(404);
        $this->assertSame('{"code":"challenge_expired","message":"Too many wrong codes. Sign in again."}', $fifth->getContent());
        $this->assertSame(0, DB::table('second_step_challenges')->count());
        // Even the right code now: expired.
        $this->step($challenge, 'totp', $this->totpCode($this->secret))->assertStatus(404)->assertJsonPath('message', SecondStep::EXPIRED_MESSAGE);

        $this->assertSame(5, AuthEvent::where('event', AuthEvent::SECOND_STEP_FAILED)->count());
        $this->assertSame('Second step failed: wrong authenticator code (app)', AuthEvent::where('event', AuthEvent::SECOND_STEP_FAILED)->first()->label());
        $this->assertSame(0, ApiToken::count());
    }

    public function test_a_challenge_lasts_ten_minutes(): void
    {
        $this->sam();
        $challenge = $this->password()->json('challenge');
        $this->travel(601)->seconds();
        $late = $this->step($challenge, 'totp', $this->totpCode($this->secret))->assertStatus(404);
        $this->assertSame('{"code":"challenge_expired","message":"That took too long. Sign in again."}', $late->getContent());
        $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $challenge])->assertStatus(404)
            ->assertExactJson(['code' => 'challenge_expired', 'message' => 'That took too long. Sign in again.']);
    }

    public function test_a_challenge_is_bound_to_its_device_and_grants_nothing_by_itself(): void
    {
        $this->sam();
        $challenge = $this->password()->json('challenge');

        // Another device_id: not this sign-in.
        $this->step($challenge, 'totp', $this->totpCode($this->secret), ['device_id' => 'someone-else'])->assertStatus(404)->assertJsonPath('code', 'challenge_expired');
        // The challenge is no bearer token.
        $this->asToken($challenge)->getJson(self::PUBLIC . '/api/v1/auth/me')->assertStatus(401);
        // An unknown or malformed challenge: the same 404.
        $this->step(str_repeat('a', 64), 'totp', '123456')->assertStatus(404)->assertJsonPath('code', 'challenge_expired');
        $this->step('nope', 'totp', '123456')->assertStatus(404);
        // The same device_id (or none) is fine.
        $this->step($challenge, 'totp', $this->totpCode($this->secret), ['device_id' => 'phone-1'])->assertOk();
    }

    public function test_authenticator_codes_are_accepted_one_step_either_side_and_never_twice(): void
    {
        $this->sam();
        // A step either side passes …
        foreach ([-1, 1] as $offset) {
            $challenge = $this->password()->json('challenge');
            $this->step($challenge, 'totp', $this->totpCode($this->secret, $offset))->assertOk();
            $this->travel(2 * 30)->seconds();
        }
        // … two steps away does not.
        $challenge = $this->password()->json('challenge');
        $this->step($challenge, 'totp', $this->totpCode($this->secret, 2))->assertStatus(422);
        $this->step($challenge, 'totp', $this->totpCode($this->secret, -2))->assertStatus(422);

        // A code used once is refused next time, in the same or another challenge.
        $code = $this->totpCode($this->secret);
        $this->step($challenge, 'totp', $code)->assertOk();
        $other = $this->password()->json('challenge');
        $this->step($other, 'totp', $code)->assertStatus(422)->assertJsonPath('code', 'wrong_code');
        // Nor an earlier step than the one used.
        $this->step($other, 'totp', $this->totpCode($this->secret, -1))->assertStatus(422);
        // The next step is fine.
        $this->step($other, 'totp', $this->totpCode($this->secret, 1))->assertOk();
    }

    public function test_an_emailed_code_is_sent_only_when_asked_works_once_and_is_stored_hashed(): void
    {
        $this->sam(authenticator: false);
        $challenge = $this->password()->json('challenge');
        Mail::assertNotSent(SignInCode::class);

        $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $challenge])->assertNoContent();
        $code = $this->emailedCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertSent(SignInCode::class, fn(SignInCode $m) => $m->hasTo('reyhan@atomic.com.au'));
        $row = DB::table('second_step_challenges')->sole();
        $this->assertSame(SecondStep::codeHash((int) $row->id, $code), $row->email_code_hash);
        $this->assertStringNotContainsString('"' . $code . '"', json_encode(DB::table('second_step_challenges')->get()));
        $this->assertSame(1, AuthEvent::where('event', AuthEvent::SIGN_IN_CODE_SENT)->count());

        $this->step($challenge, 'email', $code)->assertOk()->assertJsonStructure(['token', 'user']);
        // The new-device email goes when the sign-in completes.
        Mail::assertSent(NewSignIn::class, 0);
    }

    public function test_an_emailed_code_lasts_ten_minutes_and_a_new_send_replaces_it(): void
    {
        $this->sam(authenticator: false);
        $challenge = $this->password()->json('challenge');
        $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $challenge])->assertNoContent();
        $first = $this->emailedCode();
        Mail::fake();
        $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $challenge])->assertNoContent();
        $second = $this->emailedCode();
        if ($first !== $second) {
            $this->step($challenge, 'email', $first)->assertStatus(422);
        }
        // Valid ten minutes from its send (the challenge itself is also ten minutes).
        DB::table('second_step_challenges')->update(['expires_at' => now()->addMinutes(30)]);
        $this->travel(601)->seconds();
        $this->step($challenge, 'email', $second)->assertStatus(422)->assertJsonPath('code', 'wrong_code');
    }

    public function test_emailed_codes_are_limited_to_three_sends_a_challenge_and_six_an_hour(): void
    {
        $this->sam(authenticator: false);
        $challenge = $this->password()->json('challenge');
        for ($i = 0; $i < 3; $i++) {
            $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $challenge])->assertNoContent();
        }
        $fourth = $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $challenge])->assertStatus(429);
        $this->assertSame(['code', 'message', 'retry_after'], array_keys($fourth->json()));
        $this->assertSame('throttled', $fourth->json('code'));
        $this->assertSame((string) $fourth->json('retry_after'), $fourth->headers->get('Retry-After'));
        $this->assertMatchesRegularExpression('/^Too many codes asked for\. Try again in \d+ (seconds|minutes)\.$/', $fourth->json('message'));

        // A new challenge: three more, then the hourly cap of six.
        $next = $this->password()->json('challenge');
        for ($i = 0; $i < 3; $i++) {
            $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $next])->assertNoContent();
        }
        $third = $this->password()->json('challenge');
        $capped = $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $third])->assertStatus(429);
        $this->assertGreaterThan(3000, $capped->json('retry_after'));
        $this->assertMatchesRegularExpression('/^Too many codes asked for\. Try again in \d+ minutes\.$/', $capped->json('message'));
        Mail::assertSentCount(6);
    }

    public function test_email_code_for_an_account_without_emailed_codes_is_refused(): void
    {
        $this->sam(email: null);
        $challenge = $this->password()->json('challenge');
        $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => $challenge])
            ->assertStatus(422)->assertExactJson(['code' => 'method_unavailable', 'message' => 'Emailed codes are not available for this account.']);
        // An email method the account cannot use is just a wrong code.
        $this->step($challenge, 'email', '123456')->assertStatus(422)->assertJsonPath('code', 'wrong_code');
    }

    public function test_recovery_codes_work_once_each_and_only_with_a_strong_method(): void
    {
        $sam = $this->sam();
        $codes = app(RecoveryCodes::class)->regenerate($sam);
        $this->assertCount(10, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{5}-[abcdefghjkmnpqrstuvwxyz23456789]{5}$/', $code);
        }
        $this->assertSame(['totp', 'email', 'recovery'], $this->password()->json('methods'));

        $challenge = $this->password()->json('challenge');
        // Typed in capitals, with a space: still the code.
        $this->step($challenge, 'recovery', strtoupper(str_replace('-', ' ', $codes[0])))->assertOk();
        $this->assertSame(9, app(RecoveryCodes::class)->remaining($sam));
        $this->assertSame(9, AuthEvent::where('event', AuthEvent::RECOVERY_CODE_USED)->sole()->detail['left']);
        // Used: refused next time.
        $this->step($this->password()->json('challenge'), 'recovery', $codes[0])->assertStatus(422);

        // New codes kill the old set.
        $fresh = app(RecoveryCodes::class)->regenerate($sam);
        $this->step($this->password()->json('challenge'), 'recovery', $codes[1])->assertStatus(422);
        $this->step($this->password()->json('challenge'), 'recovery', $fresh[0])->assertOk();

        // Without a passkey or authenticator recovery codes are not a method.
        app(\App\Services\Authenticator::class)->disable($sam->fresh());
        $body = $this->password()->json();
        $this->assertSame(['email'], $body['methods']);
        $this->step($body['challenge'], 'recovery', $fresh[1])->assertStatus(422);
    }

    public function test_the_home_network_answers_as_today_without_a_second_step(): void
    {
        $this->sam();
        $this->password([], self::LAN)->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertSame(0, DB::table('second_step_challenges')->count());
        // The second-step endpoints are no shortcut there either: no challenge, 404.
        $this->asNobody()->postJson(self::LAN . '/api/v1/auth/token/second-step', ['challenge' => str_repeat('a', 64), 'method' => 'totp', 'code' => '123456'])->assertStatus(404);
    }

    public function test_every_429_has_code_message_and_retry_after(): void
    {
        $this->sam();
        // The per-name limit at home (5 a minute).
        for ($i = 0; $i < 5; $i++) {
            $this->password(['password' => 'wrong'], self::LAN)->assertStatus(422);
        }
        $limited = $this->password([], self::LAN)->assertStatus(429);
        $this->assertSame(['code', 'message', 'retry_after'], array_keys($limited->json()));
        $this->assertSame('throttled', $limited->json('code'));
        $this->assertMatchesRegularExpression('/^Too many sign-in attempts\. Try again in \d+ seconds?\.$/', $limited->json('message'));
        $this->assertSame((string) $limited->json('retry_after'), $limited->headers->get('Retry-After'));

        // The public address's per-IP cap on the second-step endpoint.
        \Illuminate\Support\Facades\RateLimiter::clear(\App\Services\Credentials::throttleKey('sam', '127.0.0.1'));
        $challenge = $this->password(['device_id' => 'x'])->json('challenge');
        for ($i = 0; $i < 20; $i++) {
            \App\Services\Credentials::hitPublicCap('127.0.0.1');
        }
        $capped = $this->step($challenge, 'totp', '123456')->assertStatus(429);
        $this->assertSame(['code', 'message', 'retry_after'], array_keys($capped->json()));
        $this->assertNotNull($capped->headers->get('Retry-After'));
        $this->assertStringStartsWith('Too many sign-in attempts. Try again in ', $capped->json('message'));
    }

    public function test_the_routes_throttle_answers_the_same_shape(): void
    {
        $this->sam();
        for ($i = 0; $i < 30; $i++) {
            $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => str_repeat('b', 64)]);
        }
        $throttled = $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token/email-code', ['challenge' => str_repeat('b', 64)])->assertStatus(429);
        $this->assertSame(['code', 'message', 'retry_after'], array_keys($throttled->json()));
        $this->assertMatchesRegularExpression('/Try again in \d+ seconds?\./', $throttled->json('message'));
        $this->assertNotNull($throttled->headers->get('Retry-After'));
    }

    public function test_meta_reports_second_step_and_availability_per_address(): void
    {
        config(['novarr.public_hosts' => ['read.example.com']]);
        // Sign-in off: public is required but not available; home as before.
        $this->assertSame(['required' => true, 'available' => false, 'second_step' => false, 'methods' => ['password', 'pairing']], $this->getJson(self::PUBLIC . '/api/v1/meta')->json('auth'));
        $this->assertSame(['required' => false, 'available' => true, 'second_step' => false, 'methods' => ['password', 'pairing']], $this->getJson(self::LAN . '/api/v1/meta')->json('auth'));
        // The other routes keep the 403 there.
        $this->getJson(self::PUBLIC . '/api/v1/novels')->assertStatus(403);

        $this->enableSignIn();
        $this->assertSame(['required' => true, 'available' => true, 'second_step' => true, 'methods' => ['password', 'pairing']], $this->getJson(self::PUBLIC . '/api/v1/meta')->json('auth'));
        $this->assertSame(['required' => true, 'available' => true, 'second_step' => false, 'methods' => ['password', 'pairing']], $this->getJson(self::LAN . '/api/v1/meta')->json('auth'));
    }

    public function test_a_disabled_account_cannot_finish(): void
    {
        $sam = $this->sam();
        $challenge = $this->password()->json('challenge');
        $sam->forceFill(['disabled_at' => now()])->save();
        $this->step($challenge, 'totp', $this->totpCode($this->secret))->assertStatus(404);
        $this->assertSame(0, ApiToken::count());
    }
}
