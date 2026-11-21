<?php

namespace Tests\Feature;

use App\AuthEvent;
use App\Services\BrowserSessions;
use App\Services\KnownDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Account → Signed-in browsers (App\Services\BrowserSessions): the web
 * sessions of an account, from Novarr's own table (whatever the session
 * driver), touched at most every 5 minutes, gone at sign-out and "sign out
 * everywhere"; "Sign out all other browsers"; the count on Settings → Users.
 */
class BrowserSessionsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:132.0) Gecko/20100101 Firefox/132.0';

    private const SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['novarr.public_hosts' => ['read.example.com']]);
    }

    private function signInAs(string $ua, string $host = 'http://novarr.lan', string $ip = '127.0.0.1'): array
    {
        if (str_contains($host, 'read.example.com')) {
            $response = $this->publicWebSignIn('sam', self::READER_PASSWORD, $host, fn($test) => $test->withHeader('User-Agent', $ua)->withServerVariables(['REMOTE_ADDR' => $ip]));
            $response->assertRedirect();
            $cookies = $this->cookiesOf($response);
            // route() in the test would otherwise build public-host URLs
            // (the generator keeps the last request's root; a browser's cookies
            // never cross hosts anyway).
            $this->asNobody()->get('http://novarr.lan/up');

            return $cookies;
        }
        $this->asNobody();
        $response = $this->withHeader('User-Agent', $ua)->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post($host . '/login', ['username' => 'sam', 'password' => self::READER_PASSWORD]);
        $response->assertRedirect();

        return $this->cookiesOf($response);
    }

    private function remember(array $cookies): array
    {
        return array_filter($cookies, fn($name) => str_starts_with($name, 'remember_web_'), ARRAY_FILTER_USE_KEY);
    }

    public function test_the_account_page_lists_each_signed_in_browser(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $mac = $this->signInAs(self::FIREFOX);
        $this->signInAs(self::SAFARI, 'http://read.example.com', '203.0.113.9');

        $this->assertSame(2, DB::table('browser_sessions')->where('user_id', $reader->id)->count());
        $page = $this->withBrowser($mac)->withHeader('User-Agent', self::FIREFOX)->get(route('account'))->assertOk()->getContent();
        preg_match('#id="browsersList">(.*?)</ul>#s', $page, $m);
        $list = $m[1];
        $this->assertSame(2, substr_count($list, '<li'));
        $this->assertStringContainsString('Firefox on macOS', $list);
        $this->assertStringContainsString('Safari on iPhone', $list);
        $this->assertStringContainsString('203.0.113.9 · public address', $list);
        $this->assertStringContainsString('127.0.0.1 · home network', $list);
        $this->assertSame(1, substr_count($list, 'This browser'));
        $this->assertMatchesRegularExpression('#Firefox on macOS\s*<span class="badge badge-accent">This browser#', $list);
        $this->assertStringContainsString('Sign out all other browsers', $page);
        // Per-row "Sign out" on the other browsers, not on this one.
        $this->assertSame(1, substr_count($list, 'aria-label="Sign out '));
        $this->assertStringContainsString('aria-label="Sign out Safari on iPhone"', $list);

        // Only hashes are stored.
        $row = DB::table('browser_sessions')->first();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->session_hash);
        foreach ($mac as $value) {
            $this->assertStringNotContainsString($value, json_encode(DB::table('browser_sessions')->get()));
        }
    }

    public function test_alone_there_is_no_sign_out_others_button(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $page = $this->withBrowser($this->signInAs(self::FIREFOX))->get(route('account'))->getContent();

        $this->assertStringContainsString('This browser', $page);
        $this->assertStringNotContainsString('id="signOutOthers"', $page);
    }

    public function test_last_active_is_written_at_most_every_five_minutes(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->signInAs(self::FIREFOX);
        $first = DB::table('browser_sessions')->value('last_active_at');

        $this->travel(2)->minutes();
        $this->withBrowser($cookies)->get('/')->assertOk();
        $this->assertSame($first, DB::table('browser_sessions')->value('last_active_at'));

        $this->travel(4)->minutes();
        $this->withBrowser($cookies)->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get('/')->assertOk();
        $row = DB::table('browser_sessions')->first();
        $this->assertNotSame($first, $row->last_active_at);
        $this->assertSame('10.0.0.8', $row->ip);
    }

    public function test_signing_out_removes_the_browser(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->signInAs(self::FIREFOX);
        $this->signInAs(self::SAFARI);

        $this->withBrowser($cookies)->post('/logout')->assertRedirect(route('login'));
        $this->assertSame(1, DB::table('browser_sessions')->count());
        $this->assertStringContainsString('iPhone', (string) DB::table('browser_sessions')->value('user_agent'));
    }

    public function test_sign_out_all_other_browsers_ends_them_and_their_remember_me_but_keeps_this_one_and_the_apps(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $token = $this->tokenFor($reader, 'Kindle');
        $mac = $this->signInAs(self::FIREFOX);
        $phone = $this->signInAs(self::SAFARI);
        $this->signInAs(self::SAFARI, 'http://read.example.com', '203.0.113.9');

        $response = $this->withBrowser($mac)->post(route('account.browsers.sign_out_others'));
        $response->assertRedirect(route('account') . '#browsers')->assertSessionHas('status');
        $mac = $this->cookiesOf($response) + $mac;

        // The other browsers: session ended, and their remember-me cookie no longer signs in.
        $this->withBrowser($phone)->get('/')->assertRedirect(route('login'));
        $this->withBrowser($this->remember($phone))->get('/')->assertRedirect(route('login'));
        // This browser stays ...
        $this->withBrowser($mac)->get('/')->assertOk();
        // ... and so do the apps.
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame(1, DB::table('browser_sessions')->where('user_id', $reader->id)->count());
        $page = $this->withBrowser($mac)->get(route('account'))->getContent();
        preg_match('#id="browsersList">(.*?)</ul>#s', $page, $m);
        $this->assertSame(1, substr_count($m[1], '<li'));
        $this->assertStringContainsString('This browser', $m[1]);

        // This browser's new remember-me cookie works on its own (a new session of the same browser).
        $this->withBrowser($this->remember($mac) + [KnownDevices::COOKIE => $mac[KnownDevices::COOKIE]])->get('/')->assertOk();
        $this->assertSame(1, DB::table('browser_sessions')->where('user_id', $reader->id)->count());

        $event = AuthEvent::where('event', AuthEvent::SIGNED_OUT_EVERYWHERE)->sole();
        $this->assertSame($reader->id, $event->user_id);
    }

    public function test_a_remember_me_sign_in_replaces_the_same_browsers_old_row(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $cookies = $this->signInAs(self::FIREFOX);
        $this->assertArrayHasKey(KnownDevices::COOKIE, $cookies);
        $oldHash = DB::table('browser_sessions')->value('session_hash');

        // The session is gone (expired); the remember-me and device cookies remain.
        $this->travel(3)->hours();
        $browser = $this->remember($cookies) + [KnownDevices::COOKIE => $cookies[KnownDevices::COOKIE]];
        $this->withBrowser($browser)->withHeader('User-Agent', self::FIREFOX)->get('/')->assertOk();

        $rows = DB::table('browser_sessions')->where('user_id', $reader->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNotSame($oldHash, $rows[0]->session_hash);
    }

    public function test_a_session_whose_row_was_removed_is_signed_out_at_its_next_touch(): void
    {
        // "Sign out" on a row ends the session in the store at once; should
        // the store not have been reachable then, the missing row ends it.
        $this->enableSignIn();
        $this->makeReader();
        $cookies = $this->signInAs(self::FIREFOX);
        DB::table('browser_sessions')->delete();

        $this->travel(6)->minutes();
        $this->withBrowser(array_filter($cookies, fn($name) => !str_starts_with($name, 'remember_web_'), ARRAY_FILTER_USE_KEY))
            ->get('/')->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('browser_sessions')->count());
    }

    public function test_settings_users_shows_browsers_next_to_devices(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $this->tokenFor($reader, 'Kindle');
        $this->signInAs(self::FIREFOX);
        $this->signInAs(self::SAFARI);
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);

        $page = $this->withBrowser($admin)->get(route('settings.users'))->assertOk()->getContent();
        preg_match('#id="user-' . $reader->id . '".*?</li>#s', $page, $row);
        $this->assertStringContainsString('1 device', $row[0]);
        $this->assertStringContainsString('2 browsers', $row[0]);
    }

    public function test_reset_turn_off_and_delete_forget_the_browsers(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);
        $this->signInAs(self::FIREFOX);

        $this->withBrowser($admin)->post(route('settings.users.password', $reader->id), ['password' => 'reset-by-the-admin'])->assertRedirect();
        $this->assertSame(0, DB::table('browser_sessions')->where('user_id', $reader->id)->count());

        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => 'reset-by-the-admin']);
        $this->assertSame(1, DB::table('browser_sessions')->where('user_id', $reader->id)->count());
        $this->withBrowser($admin)->post(route('settings.users.disable', $reader->id))->assertRedirect();
        $this->assertSame(0, DB::table('browser_sessions')->where('user_id', $reader->id)->count());

        $this->withBrowser($admin)->delete(route('settings.users.destroy', $reader->id))->assertRedirect();
        $this->assertSame(0, DB::table('browser_sessions')->where('user_id', $reader->id)->count());
    }

    public function test_with_sign_in_off_the_page_and_the_action_are_404(): void
    {
        $this->get(route('account'))->assertNotFound();
        $this->post(route('account.browsers.sign_out_others'))->assertNotFound();
        $this->assertSame(0, DB::table('browser_sessions')->count());
    }

    public function test_rows_past_the_remember_me_lifetime_are_not_listed_and_are_pruned(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        DB::table('browser_sessions')->insert([
            'user_id' => $reader->id, 'session_hash' => hash('sha256', 'old'), 'ip' => '10.0.0.1',
            'signed_in_at' => now()->subYears(2), 'last_active_at' => now()->subDays(400),
        ]);
        $cookies = $this->signInAs(self::FIREFOX);

        $page = $this->withBrowser($cookies)->get(route('account'))->getContent();
        $this->assertStringNotContainsString('10.0.0.1', $page);
        $this->assertSame(1, app(BrowserSessions::class)->counts()[$reader->id]);

        $this->artisan('novarr:prune-auth-log')->assertExitCode(0);
        $this->assertSame(1, DB::table('browser_sessions')->count());
    }
}
