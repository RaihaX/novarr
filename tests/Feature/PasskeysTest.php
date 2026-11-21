<?php

namespace Tests\Feature;

use App\AuthEvent;
use App\Passkey;
use App\Services\Passkeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Passkeys (App\Services\Passkeys), exercised with a software authenticator
 * (real P-256 keys, CBOR and signatures): adding one on the Account page,
 * "Sign in with a passkey" (one step, discoverable), the passkey as the
 * second step, and every check — origin, relying-party id, single-use
 * challenge, user verification, counter, the address a passkey belongs to.
 */
class PasskeysTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC = 'http://read.example.com';

    private const PUBLIC_ORIGIN = 'https://read.example.com';

    private const TAILNET = 'https://novarr.tail1234.ts.net';

    private const LAN = 'http://novarr.lan';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com']]);
    }

    /** Signed in (and so recently confirmed) on $host; for the public address through the second step. */
    private function signedIn(string $host, string $username = 'sam', string $password = self::READER_PASSWORD): array
    {
        if ($host === self::PUBLIC) {
            return $this->cookiesOf($this->publicWebSignIn($username, $password, $host)->assertRedirect());
        }
        $this->asNobody();

        return $this->cookiesOf($this->post($host . '/login', ['username' => $username, 'password' => $password])->assertRedirect());
    }

    /** Add a passkey through the Account page; returns the authenticator. */
    private function addPasskey(array $cookies, string $host, string $origin, string $name = 'My phone'): SoftwareAuthenticator
    {
        $options = $this->withBrowser($cookies)->postJson($host . '/account/passkeys/options')->assertOk()->json();
        $authenticator = new SoftwareAuthenticator();
        $this->withBrowser($cookies)->postJson($host . '/account/passkeys', ['name' => $name, 'credential' => $authenticator->register($options, $origin)])
            ->assertOk()->assertJsonPath('redirect', fn($url) => str_ends_with($url, '/account#security'));

        return $authenticator;
    }

    private function passkeySignIn(SoftwareAuthenticator $authenticator, string $host, string $origin, array $bend = [], array $cookies = [])
    {
        $this->withBrowser($cookies);
        $options = $this->postJson($host . '/login/passkey/options')->assertOk();
        $session = $this->cookiesOf($options) + $cookies;

        return $this->withBrowser($session)->postJson($host . '/login/passkey', ['credential' => $authenticator->assert($options->json(), $origin, $bend)]);
    }

    public function test_a_passkey_is_added_from_the_account_page_with_the_hosts_options(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $cookies = $this->signedIn(self::TAILNET);

        $options = $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys/options')->assertOk()->json();
        $this->assertSame(['id' => 'novarr.tail1234.ts.net', 'name' => 'Novarr'], $options['rp']);
        $this->assertSame('sam', $options['user']['name']);
        $this->assertSame('Sam', $options['user']['displayName']);
        // The user handle is random, never the account id.
        $this->assertNotSame((string) $reader->id, SoftwareAuthenticator::b64d($options['user']['id']));
        $this->assertSame(32, strlen(SoftwareAuthenticator::b64d($options['challenge'])));
        $this->assertSame(['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'], $options['authenticatorSelection']);
        $this->assertSame('none', $options['attestation']);
        $this->assertSame([], $options['excludeCredentials']);

        $authenticator = new SoftwareAuthenticator();
        $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options, self::TAILNET)])->assertOk();

        $passkey = Passkey::sole();
        $this->assertSame($reader->id, $passkey->user_id);
        $this->assertSame('novarr.tail1234.ts.net', $passkey->rp_id);
        $this->assertSame('Laptop', $passkey->name);
        $this->assertSame(hash('sha256', $authenticator->credentialId), $passkey->credential_hash);
        $this->assertSame(['internal'], $passkey->transportList());
        $this->assertSame('passkey', AuthEvent::where('event', AuthEvent::METHOD_ADDED)->sole()->detail['method']);

        // The next options exclude it (no double registration on one authenticator).
        $again = $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys/options')->json();
        $this->assertSame(SoftwareAuthenticator::b64($authenticator->credentialId), $again['excludeCredentials'][0]['id']);

        // Listed on the Account page, with its address.
        $page = $this->withBrowser($cookies)->get(self::TAILNET . '/account')->assertOk()->getContent();
        $this->assertStringContainsString('Laptop', $page);
        $this->assertStringContainsString('For novarr.tail1234.ts.net', $page);
    }

    public function test_registration_checks_the_origin_the_rp_id_the_challenge_and_user_verification(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->signedIn(self::TAILNET);

        $cases = [
            'origin' => ['origin' => 'https://evil.example.com'],
            'origin port' => ['origin' => self::TAILNET . ':8443'],
            'rp id' => ['rp_id' => 'evil.example.com'],
            'challenge' => ['challenge' => SoftwareAuthenticator::b64(random_bytes(32))],
            'type' => ['type' => 'webauthn.get'],
            'no user verification' => ['flags' => 0x41],
        ];
        foreach ($cases as $case => $bend) {
            $options = $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys/options')->json();
            $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys', ['name' => 'x', 'credential' => (new SoftwareAuthenticator())->register($options, self::TAILNET, $bend)])
                ->assertStatus(422)->assertJsonPath('message', Passkeys::FAILED_MESSAGE);
            $this->assertSame(0, Passkey::count(), $case);
        }

        // A challenge is used once: the same answer twice is refused the second time.
        $options = $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys/options')->json();
        $answer = (new SoftwareAuthenticator())->register($options, self::TAILNET);
        $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys', ['name' => 'a', 'credential' => $answer])->assertOk();
        $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys', ['name' => 'b', 'credential' => $answer])->assertStatus(422);
        $this->assertSame(1, Passkey::count());

        // Challenges are short-lived.
        $options = $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys/options')->json();
        $this->travel(Passkeys::CHALLENGE_TTL + 1)->seconds();
        $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys', ['name' => 'late', 'credential' => (new SoftwareAuthenticator())->register($options, self::TAILNET)])->assertStatus(422);
    }

    public function test_sign_in_with_a_passkey_is_one_step_on_the_public_address_and_names_no_account(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $authenticator = $this->addPasskey($this->signedIn(self::PUBLIC), self::PUBLIC, self::PUBLIC_ORIGIN);
        AuthEvent::query()->delete();

        // The options list no credentials (discoverable): nothing about any account.
        $options = $this->asNobody()->postJson(self::PUBLIC . '/login/passkey/options')->assertOk()->json();
        $this->assertSame(['challenge', 'rpId', 'timeout', 'userVerification'], array_keys($options));
        $this->assertSame('read.example.com', $options['rpId']);
        $this->assertSame('required', $options['userVerification']);

        $response = $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN);
        $response->assertOk()->assertJsonPath('redirect', 'https://read.example.com');
        $cookies = $this->cookiesOf($response);
        // Signed in, the second step counted as passed, remembered for this browser.
        $this->withBrowser($cookies)->get(self::PUBLIC . '/account')->assertOk()->assertSee('Account');
        $this->assertNotNull(DB::table('remember_tokens')->where('user_id', $reader->id)->value('second_step_passed_at'));
        $event = AuthEvent::where('event', AuthEvent::SIGN_IN)->sole();
        $this->assertSame('passkey', $event->detail['via']);
        $this->assertSame('Signed in with a passkey', $event->label());
        $this->assertNotNull(Passkey::sole()->last_used_at);
    }

    public function test_a_passkey_sign_in_is_refused_for_a_wrong_origin_rp_id_signature_handle_or_without_user_verification(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $authenticator = $this->addPasskey($this->signedIn(self::PUBLIC), self::PUBLIC, self::PUBLIC_ORIGIN);

        $cases = [
            'origin' => ['origin' => 'https://evil.example.com'],
            'http origin' => ['origin' => 'http://read.example.com'],
            'rp id' => ['rp_id' => 'example.com'],
            'challenge' => ['challenge' => SoftwareAuthenticator::b64(random_bytes(32))],
            'no user verification' => ['flags' => 0x01],
            'another account handle' => ['user_handle' => 'someone-else'],
            'unknown credential' => ['credential_id' => random_bytes(32)],
        ];
        foreach ($cases as $case => $bend) {
            $response = $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN, $bend);
            $response->assertStatus(422);
            $this->assertSame(Passkeys::FAILED_MESSAGE, $response->json('message'), $case);
            $this->withBrowser($this->cookiesOf($response))->get(self::PUBLIC . '/account')->assertRedirect();
        }
        $this->assertGreaterThanOrEqual(count($cases), AuthEvent::where('event', AuthEvent::SIGN_IN_FAILED)->count());

        // The genuine one still works afterwards.
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN)->assertOk();
    }

    public function test_a_replayed_assertion_and_a_counter_going_back_are_refused(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $authenticator = $this->addPasskey($this->signedIn(self::PUBLIC), self::PUBLIC, self::PUBLIC_ORIGIN);

        // One options request, one good answer, sent twice: the challenge is used once.
        $options = $this->asNobody()->postJson(self::PUBLIC . '/login/passkey/options');
        $session = $this->cookiesOf($options);
        $answer = $authenticator->assert($options->json(), self::PUBLIC_ORIGIN);
        $this->withBrowser($session)->postJson(self::PUBLIC . '/login/passkey', ['credential' => $answer])->assertOk();
        $this->withBrowser($session)->postJson(self::PUBLIC . '/login/passkey', ['credential' => $answer])->assertStatus(422);

        // The same answer against a fresh challenge: wrong challenge.
        $options = $this->asNobody()->postJson(self::PUBLIC . '/login/passkey/options');
        $this->withBrowser($this->cookiesOf($options))->postJson(self::PUBLIC . '/login/passkey', ['credential' => $answer])->assertStatus(422);

        // A cloned authenticator whose counter is behind (counter 1 again, stored is 1).
        $this->assertSame(1, Passkey::sole()->counter);
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN, ['counter' => 1])->assertStatus(422);
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN, ['counter' => 0])->assertStatus(422);
        $this->assertSame(1, Passkey::sole()->counter);
        // Forward is fine.
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN, ['counter' => 7])->assertOk();
        $this->assertSame(7, Passkey::sole()->counter);
    }

    public function test_an_authenticator_that_never_counts_keeps_working(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $authenticator = $this->addPasskey($this->signedIn(self::PUBLIC), self::PUBLIC, self::PUBLIC_ORIGIN);

        // Synced passkeys always say 0.
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN, ['counter' => 0])->assertOk();
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN, ['counter' => 0])->assertOk();
        $this->assertSame(0, Passkey::sole()->counter);
    }

    public function test_a_passkey_works_only_on_the_address_it_was_made_on(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $authenticator = $this->addPasskey($this->signedIn(self::TAILNET), self::TAILNET, self::TAILNET);

        // On the public address it is neither accepted (another rp id) …
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN, ['rp_id' => 'novarr.tail1234.ts.net', 'origin' => self::TAILNET])->assertStatus(422);
        $this->passkeySignIn($authenticator, self::PUBLIC, self::PUBLIC_ORIGIN)->assertStatus(422);
        // … nor offered as a second step there (the account has nothing usable there).
        $first = $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $first->assertSessionHasErrors(['username' => \App\Services\SecondStep::UNAVAILABLE_MESSAGE]);
        // At home, on its own address, it signs in.
        $this->passkeySignIn($authenticator, self::TAILNET, self::TAILNET)->assertOk();
    }

    public function test_on_a_plain_http_address_there_is_no_passkey_ui_and_no_options(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->signedIn(self::LAN);

        $login = $this->asNobody()->get(self::LAN . '/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-passkey-signin', $login);
        $this->assertStringContainsString(Passkeys::UNAVAILABLE_MESSAGE, $login);
        $this->asNobody()->postJson(self::LAN . '/login/passkey/options')->assertStatus(422)->assertJsonPath('message', Passkeys::UNAVAILABLE_MESSAGE);

        $account = $this->withBrowser($cookies)->get(self::LAN . '/account')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-passkey-register', $account);
        $this->assertStringContainsString(Passkeys::UNAVAILABLE_MESSAGE, $account);
        $this->withBrowser($cookies)->postJson(self::LAN . '/account/passkeys/options')->assertStatus(422);

        // An IP address is never a relying party, even over https.
        $this->asNobody()->postJson('https://192.168.1.34/login/passkey/options')->assertStatus(422);
        // The https names do show it.
        $this->assertStringContainsString('data-passkey-signin', $this->asNobody()->get(self::TAILNET . '/login')->getContent());
        $this->assertStringContainsString('data-passkey-signin', $this->asNobody()->get(self::PUBLIC . '/login')->getContent());
    }

    public function test_a_passkey_as_the_second_step_after_the_password(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $authenticator = $this->addPasskey($this->signedIn(self::PUBLIC), self::PUBLIC, self::PUBLIC_ORIGIN);
        // No authenticator app any more: the passkey is the only method there.
        app(\App\Services\Authenticator::class)->disable($reader->fresh());

        $first = $this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $cookies = $this->cookiesOf($first);
        $page = $this->withBrowser($cookies)->get(self::PUBLIC . '/login/second-step')->assertOk()->getContent();
        $this->assertStringContainsString('data-passkey-second-step', $page);
        $this->assertStringNotContainsString('id="totp"', $page);

        $options = $this->withBrowser($cookies)->postJson(self::PUBLIC . '/login/second-step/passkey')->assertOk()->json();
        // Here the account is known: its passkeys for this address are listed.
        $this->assertSame([SoftwareAuthenticator::b64($authenticator->credentialId)], array_column($options['allowCredentials'], 'id'));

        // A wrong one counts as a wrong try …
        $wrong = $this->withBrowser($cookies)->postJson(self::PUBLIC . '/login/second-step', ['method' => 'passkey', 'credential' => $authenticator->assert($options, self::PUBLIC_ORIGIN, ['origin' => 'https://evil.example.com'])]);
        $wrong->assertStatus(422)->assertJsonPath('tries_left', 4);
        $this->assertSame('passkey', AuthEvent::where('event', AuthEvent::SECOND_STEP_FAILED)->sole()->detail['method']);

        // … the right one signs in.
        $options = $this->withBrowser($cookies)->postJson(self::PUBLIC . '/login/second-step/passkey')->json();
        $done = $this->withBrowser($cookies)->postJson(self::PUBLIC . '/login/second-step', ['method' => 'passkey', 'credential' => $authenticator->assert($options, self::PUBLIC_ORIGIN)]);
        $done->assertOk()->assertJsonPath('redirect', 'https://read.example.com');
        $this->withBrowser($this->cookiesOf($done))->get(self::PUBLIC . '/account')->assertOk();
        $this->assertSame('Second step passed with a passkey', AuthEvent::where('event', AuthEvent::SECOND_STEP_PASSED)->latest('id')->first()->label());
    }

    public function test_another_accounts_passkey_is_not_a_second_step(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $other = $this->makeReader('kim', 'Kim');
        $kims = $this->addPasskey($this->signedIn(self::PUBLIC, 'kim'), self::PUBLIC, self::PUBLIC_ORIGIN);
        $this->addPasskey($this->signedIn(self::PUBLIC), self::PUBLIC, self::PUBLIC_ORIGIN);

        $cookies = $this->cookiesOf($this->asNobody()->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]));
        $options = $this->withBrowser($cookies)->postJson(self::PUBLIC . '/login/second-step/passkey')->json();
        $this->withBrowser($cookies)->postJson(self::PUBLIC . '/login/second-step', ['method' => 'passkey', 'credential' => $kims->assert($options, self::PUBLIC_ORIGIN)])
            ->assertStatus(422);
        $this->assertNotNull($other->id);
    }

    public function test_removing_a_passkey_needs_a_recent_confirmation_and_is_logged(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->signedIn(self::TAILNET);
        $this->addPasskey($cookies, self::TAILNET, self::TAILNET, 'Old key');
        $id = Passkey::sole()->id;

        $this->travel(11)->minutes();
        $this->withBrowser($cookies)->delete(self::TAILNET . '/account/passkeys/' . $id)->assertRedirect(self::TAILNET . '/account#confirm');
        $this->assertSame(1, Passkey::count());
        $this->withBrowser($cookies)->postJson(self::TAILNET . '/account/passkeys/options')->assertStatus(428)->assertJsonPath('code', 'confirm_required');

        $this->withBrowser($cookies)->post(self::TAILNET . '/account/confirm', ['password' => self::READER_PASSWORD])->assertRedirect(self::TAILNET . '/account#security');
        $this->withBrowser($cookies)->delete(self::TAILNET . '/account/passkeys/' . $id)->assertRedirect(self::TAILNET . '/account#security');
        $this->assertSame(0, Passkey::count());
        $this->assertSame('Passkey removed: Old key', AuthEvent::where('event', AuthEvent::METHOD_REMOVED)->sole()->label());
    }

    public function test_a_disabled_accounts_passkey_does_not_sign_in(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $authenticator = $this->addPasskey($this->signedIn(self::TAILNET), self::TAILNET, self::TAILNET);
        $reader->forceFill(['disabled_at' => now()])->save();

        $before = DB::table('browser_sessions')->where('user_id', $reader->id)->count();
        $this->passkeySignIn($authenticator, self::TAILNET, self::TAILNET)->assertStatus(422)
            ->assertJsonPath('message', \App\Services\Credentials::DISABLED);
        $this->assertSame($before, DB::table('browser_sessions')->where('user_id', $reader->id)->count());
        $this->assertSame('disabled', AuthEvent::where('event', AuthEvent::SIGN_IN_FAILED)->latest('id')->first()->detail['reason']);
    }
}
