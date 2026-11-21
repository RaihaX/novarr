<?php

namespace Tests\Feature;

use App\AuthEvent;
use App\Http\Controllers\UserInviteController;
use App\Services\Credentials;
use App\Services\Turnstile;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The human check (Cloudflare Turnstile, App\Services\Turnstile): only on
 * the public address, only on the web sign-in and invite forms, only when
 * both keys are set. siteverify is faked: no test reaches Cloudflare.
 */
class TurnstileTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const PUBLIC = 'http://read.example.com';

    private const LAN = 'http://novarr.lan:8080';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com'], 'mail.default' => 'smtp']);
        Mail::fake();
    }

    private function configure(): void
    {
        config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
    }

    /**
     * What the faked siteverify answers: true / false (with $codes), 'down'
     * (connection error) or an HTTP status. One fake, switched by this
     * (Http::fake stubs stack: the first registered one wins).
     */
    private array $answer = [true, []];

    private bool $faked = false;

    private function siteverify(bool|string|int $success, array $codes = []): void
    {
        $this->answer = [$success, $codes];
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function () {
            [$success, $codes] = $this->answer;
            if ($success === 'down') {
                throw new ConnectionException('Connection timed out');
            }
            if (is_int($success)) {
                return Http::response('oops', $success);
            }

            return Http::response(['success' => $success, 'error-codes' => $codes], 200);
        });
    }

    /** The reader, with an authenticator (so a public sign-in goes on to the second step). */
    protected function makeReader(string $username = 'sam', string $name = 'Sam'): User
    {
        $user = $this->makeReaderAccount($username, $name);
        $this->giveAuthenticator($user);

        return $user;
    }

    private function signIn(string $host, array $extra = [], string $password = self::READER_PASSWORD)
    {
        return $this->asNobody()->from($host . '/login')->post($host . '/login', ['username' => 'sam', 'password' => $password] + $extra);
    }

    public function test_off_by_default_nothing_is_rendered_or_asked(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        Http::fake();

        $page = $this->asNobody()->get(self::PUBLIC . '/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('challenges.cloudflare.com', $page);
        $this->assertStringNotContainsString('cf-turnstile', $page);
        // The password is right: on to the second step (no check asked).
        $this->assertStringEndsWith('/login/second-step', $this->signIn(self::PUBLIC)->headers->get('Location'));
        Http::assertNothingSent();

        // One key alone is still off.
        config(['services.turnstile.site_key' => 'site-only']);
        $this->assertFalse(Turnstile::configured());
        $this->assertStringNotContainsString('cf-turnstile', $this->asNobody()->get(self::PUBLIC . '/login')->getContent());
    }

    public function test_the_widget_is_only_on_the_public_address(): void
    {
        $this->configure();
        $this->enableSignIn();

        $public = $this->asNobody()->get(self::PUBLIC . '/login')->assertOk()->getContent();
        $this->assertStringContainsString('<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>', $public);
        $this->assertStringContainsString('class="cf-turnstile" data-sitekey="1x00000000000000000000AA"', $public);
        $this->assertStringContainsString('turbo-visit-control', $public);

        $lan = $this->asNobody()->get(self::LAN . '/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('challenges.cloudflare.com', $lan);
        $this->assertStringNotContainsString('cf-turnstile', $lan);
        // The secret is never on a page.
        $this->assertStringNotContainsString('1x0000000000000000000000000000000AA', $public . $lan);
    }

    public function test_public_sign_in_passes_with_a_good_answer_and_sends_the_visitors_address(): void
    {
        $this->configure();
        $this->enableSignIn();
        $this->makeReader();
        $this->siteverify(true);

        // The check passed and the password is right: the second step follows
        // (it never shows the human check: that stays on the password form).
        $next = $this->signIn(self::PUBLIC, [Turnstile::FIELD => 'good-token']);
        $this->assertStringEndsWith('/login/second-step', $next->headers->get('Location'));
        $this->assertStringNotContainsString('cf-turnstile', $this->withBrowser($this->cookiesOf($next))->get(self::PUBLIC . '/login/second-step')->assertOk()->getContent());

        Http::assertSent(function (HttpRequest $request) {
            $this->assertSame(Turnstile::VERIFY_URL, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame([
                'secret' => '1x0000000000000000000000000000000AA', 'response' => 'good-token', 'remoteip' => '127.0.0.1',
            ], $request->data());

            return true;
        });
    }

    public function test_a_missing_or_wrong_answer_is_refused_and_counts_as_a_failed_attempt(): void
    {
        $this->configure();
        $this->enableSignIn();
        $reader = $this->makeReader();
        $this->siteverify(false, ['invalid-input-response']);

        // Missing: refused without asking Cloudflare.
        $this->signIn(self::PUBLIC)->assertRedirect(self::PUBLIC . '/login')->assertSessionHasErrors(['username' => Turnstile::INVALID_MESSAGE]);
        Http::assertNothingSent();
        $failed = AuthEvent::where('event', AuthEvent::SIGN_IN_FAILED)->sole();
        $this->assertSame([$reader->id, 'check'], [$failed->user_id, $failed->detail['reason']]);

        // Wrong: refused even with the right password.
        $this->signIn(self::PUBLIC, [Turnstile::FIELD => 'forged'])->assertSessionHasErrors(['username' => Turnstile::INVALID_MESSAGE]);
        $this->assertNull(User::find($reader->id)->last_seen_at);

        // Each one counted in the per-name limit: three more and it is 5.
        for ($i = 0; $i < 3; $i++) {
            $this->signIn(self::PUBLIC, [Turnstile::FIELD => 'forged']);
        }
        $this->signIn(self::PUBLIC, [Turnstile::FIELD => 'forged'])->assertSessionHasErrors(['username']);
        $this->assertStringStartsWith('Too many sign-in attempts.', session('errors')->first('username'));
        // ... and in the public per-IP cap.
        $this->assertSame(5, \Illuminate\Support\Facades\RateLimiter::attempts(Credentials::publicCapKey('127.0.0.1')));
    }

    public function test_cloudflare_unreachable_refuses_the_public_sign_in(): void
    {
        $this->configure();
        $this->enableSignIn();
        $this->makeReader();
        $this->siteverify('down');
        Log::spy();

        $this->signIn(self::PUBLIC, [Turnstile::FIELD => 'some-token'])
            ->assertRedirect(self::PUBLIC . '/login')
            ->assertSessionHasErrors(['username' => Turnstile::UNAVAILABLE_MESSAGE]);
        Log::shouldHaveReceived('warning')->withArgs(fn($m) => str_contains($m, 'Turnstile'));
        $this->assertSame('check_unavailable', AuthEvent::where('event', AuthEvent::SIGN_IN_FAILED)->sole()->detail['reason']);

        // Cloudflare's own error is "unavailable" too, not the visitor's fault.
        $this->siteverify(false, ['internal-error']);
        $this->assertSame(Turnstile::UNAVAILABLE, app(Turnstile::class)->verify('t', '1.2.3.4'));
        $this->siteverify(502);
        $this->assertSame(Turnstile::UNAVAILABLE, app(Turnstile::class)->verify('t', '1.2.3.4'));
    }

    public function test_never_asked_on_the_lan_or_by_the_api_opds_and_pairing(): void
    {
        $this->configure();
        $this->enableSignIn();
        $this->makeReader();
        Http::fake();

        // The home network: the form works as before, without JavaScript.
        $this->signIn(self::LAN)->assertRedirect(route('home'));
        // Apps and e-readers on the public address.
        $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/token', ['username' => 'sam', 'password' => self::READER_PASSWORD])
            ->assertStatus(401)->assertJsonPath('code', 'second_step_required');
        $catalogue = \App\ApiToken::issue(\App\User::findByUsername('sam'), 'KOReader', null, \App\ApiToken::KIND_CATALOGUE)[1];
        $this->asNobody()->withHeader('Authorization', 'Basic ' . base64_encode('sam:' . $catalogue))->get(self::PUBLIC . '/opds')->assertOk();
        $this->asNobody()->postJson(self::PUBLIC . '/api/v1/auth/pairings', ['device_name' => 'Kindle'])->assertStatus(201);

        Http::assertNothingSent();
    }

    public function test_the_invite_form_asks_on_the_public_address_only(): void
    {
        $this->configure();
        // Sign-in off: the owner makes the invite from home.
        $this->post(self::LAN . '/settings/users/invites', ['name' => 'Sam Lee', 'email' => 'sam@example.test']);
        $link = session(UserInviteController::FLASH)['link'];
        $token = substr($link, strrpos($link, '/') + 1);
        $this->enableSignIn();
        $form = ['name' => 'Sam Lee', 'username' => 'sam.lee', 'password' => 'sams-long-password', 'password_confirmation' => 'sams-long-password'];

        $page = $this->asNobody()->get(self::PUBLIC . '/invite/' . $token)->assertOk()->getContent();
        $this->assertStringContainsString('class="cf-turnstile"', $page);
        $this->assertStringContainsString(Turnstile::SCRIPT_URL, $page);
        $this->assertStringNotContainsString('cf-turnstile', $this->asNobody()->get(self::LAN . '/invite/' . $token)->getContent());

        $this->siteverify(false, ['invalid-input-response']);
        $refused = $this->asNobody()->from(self::PUBLIC . '/invite/' . $token)->post(self::PUBLIC . '/invite/' . $token, $form + [Turnstile::FIELD => 'forged']);
        $refused->assertRedirect(self::PUBLIC . '/invite/' . $token)->assertSessionHasErrors(['turnstile' => Turnstile::INVALID_MESSAGE]);
        $this->assertNull(User::where('username', 'sam.lee')->first());
        // The passwords are not flashed back.
        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame('sam.lee', session()->getOldInput('username'));
        $shown = $this->asNobody()->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['turnstile' => Turnstile::INVALID_MESSAGE]))])
            ->get(self::PUBLIC . '/invite/' . $token)->getContent();
        $this->assertStringContainsString('id="humanCheckError">' . Turnstile::INVALID_MESSAGE, $shown);

        $this->siteverify('down');
        $this->asNobody()->post(self::PUBLIC . '/invite/' . $token, $form + [Turnstile::FIELD => 'any'])
            ->assertSessionHasErrors(['turnstile' => Turnstile::UNAVAILABLE_MESSAGE]);

        $this->siteverify(true);
        // Made; on the public address the new account then signs in with the second step.
        $made = $this->asNobody()->post(self::PUBLIC . '/invite/' . $token, $form + [Turnstile::FIELD => 'good']);
        $this->assertStringEndsWith('/login/second-step', $made->headers->get('Location'));
        $this->assertNotNull(User::where('username', 'sam.lee')->first());
    }

    public function test_the_invite_form_on_the_lan_needs_no_check(): void
    {
        $this->configure();
        $this->post(self::LAN . '/settings/users/invites', ['name' => 'Sam Lee', 'email' => 'sam@example.test']);
        $link = session(UserInviteController::FLASH)['link'];
        $token = substr($link, strrpos($link, '/') + 1);
        $this->enableSignIn();
        Http::fake();

        $this->asNobody()->post(self::LAN . '/invite/' . $token, [
            'name' => 'Sam Lee', 'username' => 'sam.lee', 'password' => 'sams-long-password', 'password_confirmation' => 'sams-long-password',
        ])->assertRedirect(route('home'));
        Http::assertNothingSent();
    }
}
