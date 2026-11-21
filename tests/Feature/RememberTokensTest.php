<?php

namespace Tests\Feature;

use App\Auth\BrowserGuard;
use App\AuthEvent;
use App\Services\RememberTokens;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Per-browser remember-me (App\Auth\BrowserGuard, App\Services\RememberTokens):
 * one row per browser sign-in, so signing out (or "Sign out" on one row of
 * Account → Signed-in browsers) never signs out another browser; sign out
 * everywhere, a password change or reset, turning an account off and the
 * admin's "Reset second step" delete the right rows; 30 days unused or a
 * year old and a row is dead; the old one-token cookies are not honoured.
 */
class RememberTokensTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:132.0) Gecko/20100101 Firefox/132.0';

    private const SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private function signIn(string $ua): array
    {
        $this->asNobody();
        $response = $this->withHeader('User-Agent', $ua)->post('/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $response->assertRedirect();

        return $this->cookiesOf($response);
    }

    private function remember(array $cookies): array
    {
        return array_filter($cookies, fn($name) => str_starts_with($name, 'remember_web_'), ARRAY_FILTER_USE_KEY);
    }

    public function test_each_browser_gets_its_own_row_stored_as_a_hash(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $mac = $this->signIn(self::FIREFOX);
        $this->signIn(self::SAFARI);

        $rows = DB::table('remember_tokens')->where('user_id', $sam->id)->get();
        $this->assertCount(2, $rows);
        $cookie = decrypt(array_values($this->remember($mac))[0], false);
        $value = substr($cookie, strpos($cookie, '|') + 1);
        [$selector, $token] = explode('|', $value);
        $this->assertSame(32, strlen($selector));
        $this->assertSame(64, strlen($token));
        $row = DB::table('remember_tokens')->where('selector', $selector)->sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('remember_tokens')->get()));
        // Linked to its line on Account → Signed-in browsers; a year at most.
        $this->assertNotNull($row->browser_session_id);
        $this->assertSame((int) $row->browser_session_id, (int) DB::table('browser_sessions')->where('id', $row->browser_session_id)->value('id'));
        $this->assertEqualsWithDelta(now()->addYear()->getTimestamp(), strtotime($row->expires_at), 86400 * 2);
        // users.remember_token is not used any more.
        $this->assertNull($sam->fresh()->remember_token);
    }

    public function test_signing_out_one_browser_keeps_the_others_remembered(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $mac = $this->signIn(self::FIREFOX);
        $phone = $this->signIn(self::SAFARI);

        $this->withBrowser($mac)->post('/logout')->assertRedirect(route('login'));
        // The Mac's remember-me is dead; the phone's still signs in on its own.
        $this->withBrowser($this->remember($mac))->get('/stats')->assertRedirect(route('login'));
        $this->withBrowser($this->remember($phone))->get('/stats')->assertOk();
        $this->assertSame(1, DB::table('remember_tokens')->count());
    }

    public function test_sign_out_on_one_row_ends_that_browser_at_once_and_only_that_one(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $mac = $this->signIn(self::FIREFOX);
        $phone = $this->signIn(self::SAFARI);
        $tablet = $this->signIn(self::SAFARI . ' iPad');
        $phoneRow = DB::table('browser_sessions')->where('user_agent', self::SAFARI)->value('id');

        $this->withBrowser($mac)->delete(route('account.browsers.sign_out', $phoneRow))
            ->assertRedirect(route('account') . '#browsers')->assertSessionHas('status');

        // The phone: its session ended in the session store (no 5-minute wait) and its remember-me deleted.
        $this->withBrowser($phone)->get('/stats')->assertRedirect(route('login'));
        $this->withBrowser($this->remember($phone))->get('/stats')->assertRedirect(route('login'));
        // The others are untouched.
        $this->withBrowser($mac)->get('/stats')->assertOk();
        $this->withBrowser($tablet)->get('/stats')->assertOk();
        $this->withBrowser($this->remember($tablet))->get('/stats')->assertOk();
        $this->assertSame(2, DB::table('remember_tokens')->where('user_id', $sam->id)->count());
        $this->assertSame('browser_signed_out', AuthEvent::where('event', AuthEvent::BROWSER_SIGNED_OUT)->sole()->event);

        // Another account's row is not reachable.
        $kim = $this->makeReader('kim', 'Kim');
        $this->asNobody();
        $kimCookies = $this->cookiesOf($this->post('/login', ['username' => 'kim', 'password' => self::READER_PASSWORD]));
        $kimRow = DB::table('browser_sessions')->where('user_id', $kim->id)->value('id');
        $this->withBrowser($mac)->delete(route('account.browsers.sign_out', $kimRow))->assertNotFound();
        $this->withBrowser($kimCookies)->get('/stats')->assertOk();
    }

    public function test_sign_out_on_this_browsers_own_row_is_an_ordinary_sign_out(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $mac = $this->signIn(self::FIREFOX);
        $row = DB::table('browser_sessions')->value('id');
        $this->withBrowser($mac)->delete(route('account.browsers.sign_out', $row))->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('remember_tokens')->count());
    }

    public function test_sign_out_everywhere_password_change_reset_and_turn_off_delete_every_row(): void
    {
        $owner = $this->enableSignIn();
        $sam = $this->makeReader();
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);

        // Password change on the Account page: the other browsers go, this one stays (a new row).
        $mac = $this->signIn(self::FIREFOX);
        $phone = $this->signIn(self::SAFARI);
        $changed = $this->withBrowser($mac)->post(route('account.password'), [
            'current_password' => self::READER_PASSWORD, 'password' => 'a-brand-new-pass', 'password_confirmation' => 'a-brand-new-pass',
        ]);
        $changed->assertRedirect(route('account'));
        $this->withBrowser($this->remember($phone))->get('/stats')->assertRedirect(route('login'));
        $this->assertSame(1, DB::table('remember_tokens')->where('user_id', $sam->id)->count());
        $this->withBrowser($this->remember($this->cookiesOf($changed)))->get('/stats')->assertOk();

        // The admin's reset: all of them.
        $this->withBrowser($admin)->post(route('settings.users.password', $sam->id), ['password' => 'reset-by-the-admin'])->assertRedirect();
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $sam->id)->count());

        // Turning the account off: all of them.
        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => 'reset-by-the-admin']);
        $this->assertSame(1, DB::table('remember_tokens')->where('user_id', $sam->id)->count());
        $this->withBrowser($admin)->post(route('settings.users.disable', $sam->id))->assertRedirect();
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $sam->id)->count());

        // The admin's own row is untouched throughout.
        $this->assertSame(1, DB::table('remember_tokens')->where('user_id', $owner->id)->count());
    }

    public function test_the_admins_reset_second_step_signs_out_every_browser(): void
    {
        $owner = $this->enableSignIn();
        $sam = $this->makeReader();
        $this->giveAuthenticator($sam);
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);
        $phone = $this->signIn(self::SAFARI);

        $this->withBrowser($admin)->post(route('settings.users.second_step.reset', $sam->id))->assertRedirect(route('settings.users'));
        $this->withBrowser($phone)->get('/stats')->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $sam->id)->count());
    }

    public function test_a_row_dies_after_thirty_days_unused_but_lives_while_the_browser_is_used(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $mac = $this->signIn(self::FIREFOX);
        $remember = $this->remember($mac);

        // Used every few weeks: alive (sliding).
        foreach ([20, 20, 20] as $days) {
            $this->travel($days)->days();
            $this->withBrowser($remember)->get('/stats')->assertOk();
        }
        // Then 31 days without: dead, and deleted when seen.
        $this->travel(31)->days();
        $this->withBrowser($remember)->get('/stats')->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('remember_tokens')->count());
    }

    public function test_a_row_dies_a_year_after_it_was_made_whatever_happens(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $remember = $this->remember($this->signIn(self::FIREFOX));
        for ($i = 0; $i < 14; $i++) {
            $this->travel(27)->days();
            $response = $this->withBrowser($remember)->get('/stats');
        }
        // 14 × 27 = 378 days: past the year.
        $response->assertRedirect(route('login'));
    }

    public function test_the_browser_in_use_keeps_its_row_alive_through_its_session(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $mac = $this->signIn(self::FIREFOX);
        $before = DB::table('remember_tokens')->value('last_used_at');
        $this->travel(6)->minutes();
        $this->withBrowser($mac)->get('/stats')->assertOk();
        $this->assertNotSame($before, DB::table('remember_tokens')->value('last_used_at'));
    }

    public function test_an_old_scheme_remember_cookie_is_not_honoured(): void
    {
        $this->enableSignIn();
        $sam = $this->makeReader();
        $sam->forceFill(['remember_token' => 'old-token-of-the-one-per-user-scheme'])->save();
        // The old cookie: remember_web_<sha1 of SessionGuard> = "id|token|password hash".
        $oldName = 'remember_web_' . sha1(\Illuminate\Auth\SessionGuard::class);
        $value = $sam->id . '|old-token-of-the-one-per-user-scheme|' . hash_hmac('sha256', $sam->password, config('app.key'));
        $this->withBrowser([$oldName => encrypt(\Illuminate\Cookie\CookieValuePrefix::create($oldName, app('encrypter')->getKey()) . $value, false)])
            ->get('/stats')->assertRedirect(route('login'));
        // Nor its value under the new cookie name.
        $newName = Auth::guard('web')->getRecallerName();
        $this->assertNotSame($oldName, $newName);
        $this->withBrowser([$newName => encrypt(\Illuminate\Cookie\CookieValuePrefix::create($newName, app('encrypter')->getKey()) . $value, false)])
            ->get('/stats')->assertRedirect(route('login'));
        $this->assertInstanceOf(BrowserGuard::class, Auth::guard('web'));
    }

    public function test_a_tampered_token_is_refused(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->signIn(self::FIREFOX);
        $row = DB::table('remember_tokens')->sole();
        $name = Auth::guard('web')->getRecallerName();
        $value = $row->selector . '|' . str_repeat('A', 64);
        $this->withBrowser([$name => encrypt(\Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()) . $value, false)])
            ->get('/stats')->assertRedirect(route('login'));
        $this->assertNull(app(RememberTokens::class)->find($value));
    }

    public function test_prune_drops_dead_rows(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->signIn(self::FIREFOX);
        $this->signIn(self::SAFARI);
        DB::table('remember_tokens')->limit(1)->update(['last_used_at' => now()->subDays(40)]);
        $this->artisan('novarr:prune-auth-log')->assertExitCode(0);
        $this->assertSame(1, DB::table('remember_tokens')->count());
    }

    public function test_deleting_an_account_deletes_its_rows(): void
    {
        $owner = $this->enableSignIn();
        $sam = $this->makeReader();
        $this->signIn(self::FIREFOX);
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);
        $this->withBrowser($admin)->delete(route('settings.users.destroy', $sam->id))->assertRedirect();
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $sam->id)->count());
        $this->assertNull(User::find($sam->id));
    }
}
