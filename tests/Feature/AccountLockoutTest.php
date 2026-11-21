<?php

namespace Tests\Feature;

use App\AuthEvent;
use App\Services\Credentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The per-account lock (App\Services\Credentials): 10 wrong passwords for
 * one account within 15 minutes, from any addresses, refuse that account's
 * password sign-ins on the PUBLIC address for 15 minutes, at every entry
 * point that takes a password. The home network is never locked out and
 * unlocks it; unknown names behave as before.
 */
class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC = 'http://read.example.com';

    private const LAN = 'http://novarr.lan:8080';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com']]);
    }

    /** One wrong password per address, so neither the per-name nor the per-IP limit is what refuses. */
    private function failFromManyAddresses(int $times, string $host = self::PUBLIC, string $name = 'sam'): void
    {
        for ($i = 1; $i <= $times; $i++) {
            $this->asNobody()->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
                ->postJson($host . '/api/v1/auth/token', ['username' => $name, 'password' => 'wrong-guess-' . $i])
                ->assertStatus(422)->assertJsonPath('message', Credentials::WRONG);
        }
    }

    private function fromAddress(string $ip): static
    {
        return $this->asNobody()->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_ten_wrong_passwords_lock_the_account_on_the_public_address_at_every_entry_point(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();

        $this->failFromManyAddresses(9);
        // Nine: not locked yet.
        $this->fromAddress('198.51.100.1')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => 'wrong'])->assertStatus(422);
        $this->assertSame(1, AuthEvent::where('event', AuthEvent::ACCOUNT_LOCKED)->count());
        $this->assertSame($reader->id, AuthEvent::where('event', AuthEvent::ACCOUNT_LOCKED)->value('user_id'));

        // The right password from yet another public address: refused, 15 minutes.
        $api = $this->fromAddress('198.51.100.2')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $api->assertStatus(429);
        $this->assertStringStartsWith('Too many sign-in attempts. Try again in ', $api->json('message'));
        $this->assertEqualsWithDelta(900, (int) $api->headers->get('Retry-After'), 2);

        $web = $this->fromAddress('198.51.100.3')->post(self::PUBLIC . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $web->assertStatus(429)->assertSee('Too many sign-in attempts.');
        $this->assertNotNull($web->headers->get('Retry-After'));

        // By email too: the lock is the account's, not the name's.
        $reader->forceFill(['email' => 'sam@example.test'])->save();
        $this->fromAddress('198.51.100.4')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam@example.test', 'password' => self::READER_PASSWORD])->assertStatus(429);

        $basic = $this->fromAddress('198.51.100.5')->withHeader('Authorization', 'Basic ' . base64_encode('sam:' . self::READER_PASSWORD))->get(self::PUBLIC . '/opds');
        $basic->assertStatus(429);
        $this->assertNotNull($basic->headers->get('Retry-After'));

        $refusal = AuthEvent::where('event', AuthEvent::RATE_LIMITED)->firstOrFail();
        $this->assertSame('account', $refusal->detail['limit']);
        $this->assertStringContainsString('locked on the public address', $refusal->label());
    }

    public function test_the_home_network_is_never_locked_out_and_unlocks_the_account(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->failFromManyAddresses(10);

        // Still refused on the public address ...
        $this->fromAddress('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertStatus(429);
        // ... but at home the password works ...
        $this->asNobody()->post(self::LAN . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));
        // ... and that lifts the lock.
        $this->fromAddress('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertOk();
    }

    public function test_failures_at_home_count_but_never_lock_home(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->failFromManyAddresses(10, self::LAN);

        $this->fromAddress('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertStatus(429);
        $this->fromAddress('10.0.0.5')->postJson(self::LAN . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertOk();
    }

    public function test_the_lock_lasts_fifteen_minutes(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->failFromManyAddresses(10);

        $this->travel(14)->minutes();
        $this->fromAddress('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertStatus(429);
        $this->travel(2)->minutes();
        $this->fromAddress('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertOk();
    }

    public function test_failures_spread_beyond_fifteen_minutes_do_not_lock(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->failFromManyAddresses(9);
        $this->travel(16)->minutes();
        $this->failFromManyAddresses(9);

        $this->fromAddress('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertOk();
        $this->assertSame(0, AuthEvent::where('event', AuthEvent::ACCOUNT_LOCKED)->count());
    }

    public function test_unknown_names_behave_as_before(): void
    {
        $this->enableSignIn();
        $this->failFromManyAddresses(15, self::PUBLIC, 'nobody-here');

        $this->fromAddress('198.51.100.9')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'nobody-here', 'password' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', Credentials::WRONG);
        $this->assertSame(0, AuthEvent::where('event', AuthEvent::ACCOUNT_LOCKED)->count());
    }

    public function test_the_other_limits_are_unchanged(): void
    {
        $this->enableSignIn();
        $this->makeReader();

        // 5 a minute per name + address (home network too).
        for ($i = 0; $i < 5; $i++) {
            $this->fromAddress('10.0.0.7')->postJson(self::LAN . '/api/v1/auth/token', ['username' => 'sam', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->fromAddress('10.0.0.7')->postJson(self::LAN . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertStatus(429);

        // 20 per 10 minutes per address on the public address, across names.
        for ($i = 0; $i < 20; $i++) {
            $this->fromAddress('192.0.2.1')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'name' . $i, 'password' => 'wrong'])->assertStatus(422);
        }
        $this->fromAddress('192.0.2.1')->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'other', 'password' => 'x'])->assertStatus(429);
    }

    public function test_a_disabled_or_passwordless_account_with_the_right_password_is_not_a_failure(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $reader->forceFill(['disabled_at' => now()])->save();
        for ($i = 1; $i <= 12; $i++) {
            $this->fromAddress("203.0.113.{$i}")->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])
                ->assertStatus(422)->assertJsonPath('message', Credentials::DISABLED);
        }
        $this->assertSame(0, AuthEvent::where('event', AuthEvent::ACCOUNT_LOCKED)->count());
    }
}
