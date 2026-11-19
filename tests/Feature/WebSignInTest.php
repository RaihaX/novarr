<?php

namespace Tests\Feature;

use App\Services\Credentials;
use App\Support\AuthMode;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The web sign-in (/login), the "Require sign-in" switch and what changes
 * with it — through real requests carrying the browser's cookies.
 */
class WebSignInTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    public function test_login_page_works_without_js_and_redirects_home_when_sign_in_is_off(): void
    {
        $this->get('/login')->assertRedirect(route('home'));

        $this->enableSignIn();
        $html = $this->asNobody()->get('/login')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->getContent();
        $this->assertStringContainsString('action="' . route('login.store') . '"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="username"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('brand-mark', $html);
        // The guest shell: no app navigation, no palette, no tab bar.
        $this->assertStringNotContainsString('id="navbarNav"', $html);
        $this->assertStringNotContainsString('id="palette"', $html);
        $this->assertStringNotContainsString('class="tabbar"', $html);
        // Signed out: the page tells its JS to empty the cached pages.
        $this->assertStringContainsString('<meta name="novarr-signed" content="out">', $html);
    }

    public function test_sign_in_regenerates_the_session_remembers_for_a_year_and_returns_to_the_intended_page(): void
    {
        $this->enableSignIn();
        $this->makeReader();

        // A guest opening a page is sent to sign in, the page remembered.
        $first = $this->asNobody()->get('/stats');
        $first->assertRedirect(route('login'));
        $cookies = $this->cookiesOf($first);
        $oldSession = $cookies[config('session.cookie')] ?? null;
        $this->assertNotNull($oldSession);

        $login = $this->withBrowser($cookies)->post('/login', ['username' => 'SAM', 'password' => self::READER_PASSWORD]);
        $login->assertRedirect(route('stats.index'));
        $after = $this->cookiesOf($login);
        $this->assertNotSame($oldSession, $after[config('session.cookie')], 'the session id changes on sign-in');

        $remember = collect($login->headers->getCookies())->first(fn($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($remember, 'sign-in is always remembered');
        $this->assertEqualsWithDelta(now()->addYear()->getTimestamp(), $remember->getExpiresTime(), 3600 * 25);

        $page = $this->withBrowser($after)->get('/stats')->assertOk()->getContent();
        $this->assertStringContainsString('Signed in as <strong>sam</strong>', $page);
        $this->assertStringContainsString('<meta name="novarr-signed" content="in">', $this->withBrowser($after)->get('/')->getContent() . $page);

        // The remember-me cookie alone (session gone) still signs in.
        $this->withBrowser([$remember->getName() => $remember->getValue()])->get('/stats')->assertOk();
    }

    public function test_wrong_password_and_unknown_name_say_the_same_thing(): void
    {
        $this->enableSignIn();
        $this->makeReader();

        foreach ([['sam', 'nope'], ['nobody', 'nope']] as [$name, $password]) {
            $this->asNobody()->from('/login')->post('/login', ['username' => $name, 'password' => $password])
                ->assertRedirect('/login')
                ->assertSessionHasErrors(['username' => Credentials::WRONG]);
        }
    }

    public function test_sign_in_is_throttled_per_name_and_address_on_the_file_cache(): void
    {
        config(['cache.default' => 'file']);
        Cache::store('file')->flush();
        RateLimiter::clear(Credentials::throttleKey('sam', '127.0.0.1'));
        $this->enableSignIn();
        $this->makeReader();

        for ($i = 0; $i < Credentials::MAX_ATTEMPTS; $i++) {
            $this->asNobody()->post('/login', ['username' => 'sam', 'password' => 'wrong'])
                ->assertSessionHasErrors(['username' => Credentials::WRONG]);
        }
        // Even the right password waits now…
        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])
            ->assertSessionHasErrors('username');
        $this->assertStringStartsWith('Too many sign-in attempts', session('errors')->first('username'));
        // …another name is not affected.
        $this->asNobody()->post('/login', ['username' => 'reyhan-x', 'password' => 'wrong'])
            ->assertSessionHasErrors(['username' => Credentials::WRONG]);

        Cache::store('file')->flush();
    }

    public function test_sign_out_ends_the_session(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->get('/')->assertOk();

        $out = $this->withBrowser($cookies)->post('/logout');
        $out->assertRedirect(route('login'));
        $this->withBrowser($cookies)->get('/')->assertRedirect(route('login'));
    }

    public function test_a_disabled_account_cannot_sign_in_and_its_session_and_tokens_stop(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $token = $this->tokenFor($reader);
        $this->withBrowser($cookies)->get('/')->assertOk();
        $this->asToken($token)->getJson('/api/v1/home')->assertOk();

        $reader->forceFill(['disabled_at' => now()])->save();

        $this->withBrowser($cookies)->get('/')->assertRedirect(route('login'));
        $this->asToken($token)->getJson('/api/v1/home')->assertStatus(401);
        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])
            ->assertSessionHasErrors(['username' => Credentials::DISABLED]);
    }

    public function test_an_account_without_a_password_set_here_cannot_sign_in(): void
    {
        $this->enableSignIn();
        $legacy = $this->makeReader('legacy');
        $legacy->forceFill(['password_set_at' => null])->save();

        $this->asNobody()->post('/login', ['username' => 'legacy', 'password' => self::READER_PASSWORD])
            ->assertSessionHasErrors(['username' => Credentials::NO_PASSWORD]);
    }

    public function test_turning_sign_in_on_is_refused_until_the_owner_has_a_password(): void
    {
        $this->assertNull($this->owner()->password_set_at, 'the migrated owner has no known password');

        $this->post(route('settings.users.auth'), ['required' => 1])
            ->assertRedirect(route('settings.users'))->assertSessionHas('error');
        $this->assertFalse(AuthMode::required());

        $this->get(route('settings.users'))->assertOk()
            ->assertSee('Set a password for the owner account first')
            ->assertDontSee('id="signinToggle"', false);
    }

    public function test_turning_sign_in_on_from_the_web_signs_the_owner_in_on_the_spot(): void
    {
        $owner = $this->owner();
        $owner->setNewPassword(self::OWNER_PASSWORD);

        $on = $this->post(route('settings.users.auth'), ['required' => 1]);
        $on->assertRedirect(route('settings.users'));
        $this->assertTrue(AuthMode::required());

        // The response's cookies are a signed-in owner: no lock-out.
        $cookies = $this->cookiesOf($on);
        $this->withBrowser($cookies)->get(route('settings.users'))->assertOk()->assertSee('Sign-in is <strong id="signinState">on</strong>', false);
        $this->asNobody()->get(route('settings.users'))->assertRedirect(route('login'));

        // And off again.
        $this->withBrowser($cookies)->post(route('settings.users.auth'), ['required' => 0])->assertRedirect(route('settings.users'));
        $this->assertFalse(AuthMode::required());
        $this->asNobody()->get('/')->assertOk();
    }

    public function test_novarr_auth_env_wins_and_locks_the_toggle(): void
    {
        $this->owner()->setNewPassword(self::OWNER_PASSWORD);

        config(['novarr.auth' => 'off']);
        AuthMode::store(true);
        $this->assertFalse(AuthMode::required());
        $this->get(route('settings.users'))->assertOk()->assertSee('NOVARR_AUTH=off', false)->assertDontSee('id="signinToggle"', false);
        $this->post(route('settings.users.auth'), ['required' => 0])->assertSessionHas('error');
        $this->assertTrue(AuthMode::setting(), 'a locked toggle changes nothing');

        config(['novarr.auth' => 'on']);
        AuthMode::store(false);
        $this->assertTrue(AuthMode::required());
        $this->asNobody()->get('/')->assertRedirect(route('login'));
    }

    public function test_novarr_auth_on_is_refused_while_the_owner_has_no_password(): void
    {
        config(['novarr.auth' => 'on']);

        $this->assertTrue(AuthMode::forcedOnButRefused());
        $this->assertFalse(AuthMode::required());
        $this->get('/')->assertOk();
        $this->get(route('settings.users'))->assertOk()->assertSee('sign-in stays off until the owner account below has a password');
        $this->getJson('/api/v1/meta')->assertJsonPath('auth.required', false);
    }

    public function test_meta_reports_whether_sign_in_is_required(): void
    {
        $this->getJson('/api/v1/meta')->assertJsonPath('auth', ['required' => false, 'methods' => ['password', 'pairing']]);
        $this->enableSignIn();
        $this->asNobody()->getJson('/api/v1/meta')->assertOk()->assertJsonPath('auth.required', true);
    }

    public function test_a_turbo_or_xhr_request_of_a_guest_gets_json_not_a_redirect(): void
    {
        $this->enableSignIn();
        [$novel] = $this->novelWithChapters();
        $this->asNobody()->getJson(route('novels.chapters_json', $novel->id))
            ->assertStatus(401)->assertExactJson(['message' => 'Sign in to continue.', 'code' => 'unauthenticated']);
        $this->asNobody()->get(route('novels.show', $novel->id))->assertRedirect(route('login'));
    }

    public function test_a_reader_gets_a_plain_403_page_for_an_admin_page(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);

        $this->withBrowser($cookies)->get('/settings')->assertStatus(403)->assertSee('Not available to your account');
    }

    public function test_last_seen_is_written_at_most_every_five_minutes(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $token = $this->tokenFor($reader);

        $this->travelTo(now()->startOfMinute());
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $seen = $reader->fresh()->last_seen_at;
        $this->assertNotNull($seen);
        $updatedAt = $reader->fresh()->updated_at;

        $this->travel(2)->minutes();
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertEquals($seen, $reader->fresh()->last_seen_at);

        $this->travel(4)->minutes();
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue($reader->fresh()->last_seen_at->gt($seen));
        $this->assertEquals($updatedAt, $reader->fresh()->updated_at, 'no updated_at churn');
    }
}
