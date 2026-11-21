<?php

namespace Tests\Feature;

use App\Http\Controllers\UserInviteController;
use App\Mail\NewSignIn;
use App\Services\KnownDevices;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The "New sign-in to your Novarr account" email (App\Services\KnownDevices):
 * a password sign-in on the web or from an app, on a device the account has
 * not used, emails the account — not its first device, not without an
 * email address or a mailer, never delaying or failing the sign-in.
 */
class NewSignInEmailTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp', 'novarr.public_hosts' => ['read.example.com']]);
        Mail::fake();
    }

    private function reader(): User
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $reader->forceFill(['email' => 'sam@example.test'])->save();

        return $reader;
    }

    private function deviceCookie($response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === KnownDevices::COOKIE) {
                return $cookie;
            }
        }

        return null;
    }

    private function signInWith(array $cookies, string $host = 'http://novarr.lan', string $ua = self::SAFARI)
    {
        // The public address: the password and then the second step (the
        // email goes when the sign-in is done, after both).
        if (str_contains($host, 'read.example.com')) {
            return $this->publicWebSignIn('sam', self::READER_PASSWORD, $host, function ($test) use ($ua, $cookies) {
                $test->withHeader('User-Agent', $ua);
                $test->unencryptedCookies += $cookies;
                $test->withCredentials = true;
            });
        }

        return $this->withBrowser($cookies)->withHeader('User-Agent', $ua)
            ->post($host . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
    }

    public function test_a_new_browser_gets_the_email_a_known_one_does_not(): void
    {
        config(['app.timezone' => 'Australia/Perth']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-21 02:30:00', 'UTC'));
        $reader = $this->reader();

        // The account's first device: remembered, no email.
        $first = $this->signInWith([])->assertRedirect(route('home'));
        Mail::assertNothingSent();
        $cookie = $this->deviceCookie($first);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertFalse($cookie->isSecure());
        $this->assertGreaterThan(now()->addDays(399)->getTimestamp(), $cookie->getExpiresTime());
        $browser = [KnownDevices::COOKIE => $cookie->getValue()];

        // Same browser again: nothing.
        $this->signInWith($browser)->assertRedirect(route('home'));
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('known_devices')->where('user_id', $reader->id)->count());

        // A new browser, on the public address.
        $this->signInWith([], 'http://read.example.com')->assertRedirect();
        Mail::assertSent(NewSignIn::class, function (NewSignIn $mail) {
            $this->assertTrue($mail->hasTo('sam@example.test'));
            $this->assertSame('New sign-in to your Novarr account', $mail->build()->subject);
            $html = $mail->render();
            $this->assertStringContainsString('Hi Sam,', $html);
            $this->assertStringContainsString('21 November 2026, 10:30 AWST', $html);
            $this->assertStringContainsString('Safari on iPhone', $html);
            $this->assertStringContainsString('127.0.0.1', $html);
            $this->assertStringContainsString('the public address', $html);
            $this->assertStringContainsString('If this was not you, change your password on your Account page, or ask the person who runs this Novarr.', $html);
            $text = view('emails.new_sign_in_text', $mail->buildViewData())->render();
            $this->assertStringContainsString('Device: Safari on iPhone', $text);
            $this->assertStringContainsString('Came in on: the public address', $text);
            $this->assertStringContainsString('If this was not you', $text);

            return true;
        });
        Mail::assertSentCount(1);
    }

    public function test_the_device_cookie_is_secure_on_the_public_address_and_only_its_hash_is_stored(): void
    {
        $this->reader();
        $response = $this->signInWith([], 'http://read.example.com');
        $cookie = $this->deviceCookie($response);
        $this->assertTrue($cookie->isSecure());

        $plain = decrypt($cookie->getValue(), false);
        $plain = str_contains($plain, '|') ? substr($plain, strpos($plain, '|') + 1) : $plain;
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain);
        $this->assertSame(KnownDevices::hash('web:' . $plain), DB::table('known_devices')->value('device_hash'));
        $this->assertStringNotContainsString($plain, json_encode(DB::table('known_devices')->get()));
    }

    public function test_apps_are_known_by_their_device_id(): void
    {
        $reader = $this->reader();
        $token = fn(array $extra) => $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD] + $extra)->assertOk();

        $token(['device_id' => 'ios-1', 'device_name' => 'Sam’s iPhone']);
        Mail::assertNothingSent();
        $token(['device_id' => 'ios-1', 'device_name' => 'Sam’s iPhone']);
        Mail::assertNothingSent();

        $token(['device_id' => 'android-7', 'device_name' => 'Pixel 8']);
        Mail::assertSent(NewSignIn::class, fn(NewSignIn $m) => $m->device === 'Pixel 8' && $m->public === false);

        // No device_id: always new, and nothing stored for it.
        $token([]);
        Mail::assertSentCount(2);
        $this->assertSame(2, DB::table('known_devices')->where('user_id', $reader->id)->count());
    }

    public function test_accepting_an_invite_makes_its_browser_known_without_an_email(): void
    {
        $this->post('/settings/users/invites', ['name' => 'Sam Lee', 'email' => 'sam@example.test']);
        $link = session(UserInviteController::FLASH)['link'];
        $token = substr($link, strrpos($link, '/') + 1);
        Mail::fake();
        $this->enableSignIn();

        $accepted = $this->asNobody()->post('/invite/' . $token, [
            'name' => 'Sam Lee', 'username' => 'sam.lee', 'password' => 'sams-long-password', 'password_confirmation' => 'sams-long-password',
        ])->assertRedirect(route('home'));
        Mail::assertNothingSent();
        $browser = [KnownDevices::COOKIE => $this->deviceCookie($accepted)->getValue()];

        // Signing in again on that browser later: still nothing.
        $this->withBrowser($browser)->post('/login', ['username' => 'sam.lee', 'password' => 'sams-long-password'])->assertRedirect(route('home'));
        Mail::assertNothingSent();

        // Another device: the email.
        $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'sam.lee', 'password' => 'sams-long-password', 'device_id' => 'pixel'])->assertOk();
        Mail::assertSent(NewSignIn::class);
    }

    public function test_no_email_without_an_address_or_a_mailer(): void
    {
        $reader = $this->reader();
        $this->signInWith([]);

        config(['mail.default' => 'log']);
        $this->signInWith([]);
        Mail::assertNothingSent();

        config(['mail.default' => 'smtp']);
        $reader->forceFill(['email' => null])->save();
        $this->signInWith([]);
        Mail::assertNothingSent();
    }

    public function test_a_failed_send_is_logged_and_never_breaks_the_sign_in(): void
    {
        $this->reader();
        $this->signInWith([]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused by smtp.example.test'));
        Log::spy();

        $this->signInWith([])->assertRedirect(route('home'));
        Log::shouldHaveReceived('warning')->withArgs(fn($m) => str_contains($m, 'New sign-in email to sam@example.test failed'));
    }

    public function test_it_is_sent_after_the_response_and_only_once(): void
    {
        $this->reader();
        $this->signInWith([]);
        // A second request on the same (reused) application must not resend it.
        $this->signInWith([]);
        $this->asNobody()->get('/login');
        Mail::assertSentCount(1);
    }

    public function test_at_most_fifty_devices_are_kept_per_account(): void
    {
        $reader = $this->reader();
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['user_id' => $reader->id, 'device_hash' => hash('sha256', 'old' . $i), 'first_seen_at' => now()->subDays(100 - $i), 'last_seen_at' => now()->subDays(100 - $i), 'label' => 'Old ' . $i];
        }
        DB::table('known_devices')->insert($rows);

        $this->signInWith([]);
        $this->assertSame(50, DB::table('known_devices')->where('user_id', $reader->id)->count());
        $this->assertFalse(DB::table('known_devices')->where('label', 'Old 1')->exists());
        $this->assertTrue(DB::table('known_devices')->where('label', 'Safari on iPhone')->exists());
        Mail::assertSent(NewSignIn::class);
    }

    public function test_deleting_an_account_forgets_its_devices(): void
    {
        $reader = $this->reader();
        $this->signInWith([]);
        $reader->delete();

        $this->assertSame(0, DB::table('known_devices')->count());
    }
}
