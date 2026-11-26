<?php

namespace Tests\Feature;

use App\ApiToken;
use App\DevicePairing;
use App\Services\Credentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The native clients' sign-in (auth contract): password → bearer token,
 * who-am-I, sign out, and linking a device by code.
 */
class ApiAuthTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    public function test_password_sign_in_returns_a_token_and_the_user(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();

        $response = $this->asNobody()->postJson('/api/v1/auth/token', [
            'username' => 'Sam', 'password' => self::READER_PASSWORD, 'device_id' => 'ios-abc', 'device_name' => 'Sam’s iPhone',
        ])->assertOk();

        $response->assertExactJson([
            'token' => $response->json('token'),
            'user' => ['id' => $reader->id, 'name' => 'Sam', 'username' => 'sam', 'role' => 'reader', 'email' => null],
        ]);
        $plain = $response->json('token');
        $this->assertIsString($plain);
        $this->assertLessThanOrEqual(255, strlen($plain));

        $stored = ApiToken::sole();
        $this->assertSame(hash('sha256', $plain), $stored->token_hash, 'only the hash is stored');
        $this->assertSame('Sam’s iPhone', $stored->name);
        $this->assertSame('ios-abc', $stored->device_id);

        $this->asToken($plain)->getJson('/api/v1/auth/me')->assertOk()
            ->assertExactJson(['user' => ['id' => $reader->id, 'name' => 'Sam', 'username' => 'sam', 'role' => 'reader', 'email' => null]]);
        $this->assertNotNull($stored->fresh()->last_used_at);
    }

    public function test_wrong_credentials_are_422_with_the_contract_message_and_429_when_throttled(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        RateLimiter::clear(Credentials::throttleKey('sam', '127.0.0.1'));

        $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'sam', 'password' => 'nope'])
            ->assertStatus(422)->assertExactJson(['message' => 'Wrong name or password.']);
        for ($i = 1; $i < Credentials::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/auth/token', ['username' => 'sam', 'password' => 'nope'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonStructure(['message']);
        $this->assertSame(0, ApiToken::count());
    }

    public function test_sign_out_revokes_the_token_in_use_only(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $phone = $this->tokenFor($reader, 'Phone');
        $kindle = $this->tokenFor($reader, 'Kindle');

        $this->asToken($phone)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->asToken($phone)->getJson('/api/v1/auth/me')
            ->assertStatus(401)->assertExactJson(['message' => 'Sign in to continue.', 'code' => 'unauthenticated']);
        $this->asToken($kindle)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertNotNull(ApiToken::where('name', 'Phone')->value('revoked_at'));
    }

    public function test_a_missing_or_unknown_token_is_401_and_a_reader_on_an_admin_endpoint_is_403(): void
    {
        $this->enableSignIn();
        $this->asNobody()->getJson('/api/v1/novels')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->asToken('nvr_not-a-token')->getJson('/api/v1/novels')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        // Without an Accept header too (the API always answers JSON).
        $this->asToken('nvr_not-a-token')->get('/api/v1/novels')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

        $token = $this->tokenFor($this->makeReader());
        $this->asToken($token)->postJson('/api/v1/novels', ['url' => 'https://example.test/x'])
            ->assertStatus(403)->assertExactJson(['message' => 'Only the admin can do that.', 'code' => 'forbidden']);
        $this->asToken($token)->getJson('/api/v1/discover')->assertStatus(403);
    }

    public function test_with_sign_in_off_me_is_the_owner_as_admin_and_tokens_can_be_made_ahead(): void
    {
        $owner = $this->owner();
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.id', $owner->id)->assertJsonPath('user.role', 'admin');

        $owner->setNewPassword(self::OWNER_PASSWORD);
        $plain = $this->postJson('/api/v1/auth/token', ['username' => $owner->username, 'password' => self::OWNER_PASSWORD])
            ->assertOk()->json('token');
        $this->asToken($plain)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->assertNotNull(ApiToken::sole()->revoked_at);
    }

    public function test_the_web_session_signs_in_the_web_uis_own_api_calls(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);

        $this->withBrowser($cookies)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $reader->id);
        // An API call without cookies starts no session (no Set-Cookie).
        $response = $this->asToken($this->tokenFor($reader))->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame([], $response->headers->getCookies());
    }

    // ---- Device pairing ----------------------------------------------------

    public function test_pairing_happy_path_hands_the_token_over_once(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();

        $start = $this->asNobody()->postJson('/api/v1/auth/pairings', ['device_id' => 'kindle-1', 'device_name' => 'Kindle'])
            ->assertStatus(201);
        $start->assertJsonStructure(['code', 'secret', 'expires_in', 'interval']);
        $code = $start->json('code');
        $secret = $start->json('secret');
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $code);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertSame(600, $start->json('expires_in'));
        $this->assertSame(3, $start->json('interval'));

        $this->getJson("/api/v1/auth/pairings/{$secret}")->assertOk()->assertExactJson(['status' => 'pending']);

        // The reader types it on the web, "k7m 4qx"-style, and sees the device.
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $typed = strtolower(substr($code, 0, 3) . ' ' . substr($code, 3));
        $lookup = $this->withBrowser($cookies)->post(route('account.link'), ['code' => $typed]);
        $lookup->assertRedirect(route('account') . '#link');
        $confirmPage = $this->withBrowser($this->cookiesOf($lookup) + $cookies)->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('Link <strong>Kindle</strong>', $confirmPage);

        $pairing = DevicePairing::sole();
        $this->withBrowser($cookies)->post(route('account.link.confirm'), ['pairing' => $pairing->id, 'code' => $code])
            ->assertRedirect(route('account') . '#link')->assertSessionHas('status');

        $linked = $this->asNobody()->getJson("/api/v1/auth/pairings/{$secret}")->assertOk();
        $linked->assertJsonPath('status', 'linked')
            ->assertJsonPath('user', ['id' => $reader->id, 'name' => 'Sam', 'username' => 'sam', 'role' => 'reader', 'email' => null]);
        $token = $linked->json('token');
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $reader->id);
        $this->assertSame('kindle-1', ApiToken::where('name', 'Kindle')->value('device_id'));

        // Exactly once.
        $this->asNobody()->getJson("/api/v1/auth/pairings/{$secret}")->assertNotFound();
        $this->assertSame(0, DevicePairing::count());
    }

    public function test_a_pairing_expires_after_ten_minutes(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $start = $this->asNobody()->postJson('/api/v1/auth/pairings', ['device_name' => 'Kindle'])->assertStatus(201);

        $this->travel(DevicePairing::TTL_SECONDS + 1)->seconds();

        $this->getJson('/api/v1/auth/pairings/' . $start->json('secret'))->assertNotFound();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->post(route('account.link'), ['code' => $start->json('code')])
            ->assertSessionHasErrors(['code'], null, 'link');
        // A linked pairing nobody collects: its token is revoked when swept.
        $this->assertSame(0, ApiToken::count());
    }

    public function test_an_uncollected_linked_pairing_revokes_its_token_when_swept(): void
    {
        $reader = $this->makeReader();
        [$pairing] = DevicePairing::begin('k', 'Kindle');
        $token = $pairing->link($reader);

        $this->travel(DevicePairing::TTL_SECONDS + 1)->seconds();
        DevicePairing::begin('k2', 'Another');

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame(1, DevicePairing::count());
    }

    public function test_wrong_codes_on_the_web_are_throttled_per_user(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        RateLimiter::clear('novarr-link:' . \App\User::where('username', 'sam')->value('id'));

        for ($i = 0; $i < \App\Http\Controllers\AccountController::LINK_ATTEMPTS; $i++) {
            $this->withBrowser($cookies)->post(route('account.link'), ['code' => 'AAAAAA'])
                ->assertSessionHasErrors(['code' => 'That code is wrong or has expired. Codes last 10 minutes: ask the device for a new one.'], null, 'link');
        }
        // The right code now waits too.
        $start = $this->asNobody()->postJson('/api/v1/auth/pairings', ['device_name' => 'Kindle'])->assertStatus(201);
        $this->withBrowser($cookies)->post(route('account.link'), ['code' => $start->json('code')])
            ->assertSessionHasErrors(['code'], null, 'link');
        $this->assertStringStartsWith('Too many codes tried', session('errors')->getBag('link')->first('code'));
    }

    public function test_starting_pairings_is_throttled_per_address(): void
    {
        $this->enableSignIn();
        for ($i = 0; $i < 10; $i++) {
            $this->asNobody()->postJson('/api/v1/auth/pairings', ['device_name' => 'Kindle'])->assertStatus(201);
        }
        $this->postJson('/api/v1/auth/pairings', ['device_name' => 'Kindle'])->assertStatus(429);
    }

    public function test_codes_are_unique_among_unexpired_pairings(): void
    {
        $codes = [];
        for ($i = 0; $i < 30; $i++) {
            $codes[] = DevicePairing::begin(null, null)[0]->code;
        }
        $this->assertSame(count($codes), count(array_unique($codes)));
        $this->assertSame('K7M4QX', DevicePairing::normaliseCode(' k7m-4qx '));
        $this->assertNull(DevicePairing::normaliseCode('K7M4Q0'), 'no 0 in the alphabet');
    }
}
