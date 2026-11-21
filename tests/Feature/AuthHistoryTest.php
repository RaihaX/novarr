<?php

namespace Tests\Feature;

use App\ApiToken;
use App\AuthEvent;
use App\DevicePairing;
use App\Http\Controllers\UserInviteController;
use App\Support\DeviceDescription;
use App\User;
use App\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The sign-in history (App\AuthEvent): what is recorded where, that no
 * secret ever lands in it, the admin's Sign-in history page, each account's
 * Recent sign-ins, and the daily prune.
 */
class AuthHistoryTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const FIREFOX_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:132.0) Gecko/20100101 Firefox/132.0';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    /** @return string[] event names, oldest first */
    private function events(): array
    {
        return AuthEvent::orderBy('id')->pluck('event')->all();
    }

    private function last(string $event): AuthEvent
    {
        return AuthEvent::where('event', $event)->orderByDesc('id')->firstOrFail();
    }

    public function test_web_sign_in_failure_and_sign_out_are_recorded_with_address_and_device(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();

        $this->asNobody()->withHeader('User-Agent', self::FIREFOX_MAC)
            ->post('/login', ['username' => 'sam', 'password' => 'wrong-password'])->assertRedirect();
        $failed = $this->last(AuthEvent::SIGN_IN_FAILED);
        $this->assertSame($reader->id, $failed->user_id);
        $this->assertSame(['via' => 'web', 'reason' => 'wrong'], $failed->detail);
        $this->assertSame('127.0.0.1', $failed->ip);
        $this->assertFalse($failed->public);
        $this->assertSame('Firefox on macOS', $failed->device());

        // An unknown name: no account, and the name itself is not kept.
        $this->asNobody()->post('/login', ['username' => 'mallory', 'password' => 'whatever'])->assertRedirect();
        $unknown = $this->last(AuthEvent::SIGN_IN_FAILED);
        $this->assertNull($unknown->user_id);
        $this->assertSame(['via' => 'web', 'reason' => 'unknown_name', 'unknown_name' => true], $unknown->detail);
        $this->assertStringNotContainsString('mallory', json_encode(DB::table('auth_events')->get()));

        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $signIn = $this->last(AuthEvent::SIGN_IN);
        $this->assertSame($reader->id, $signIn->user_id);
        $this->assertSame('web', $signIn->detail['via']);

        $this->withBrowser($cookies)->post('/logout')->assertRedirect(route('login'));
        $this->assertSame($reader->id, $this->last(AuthEvent::SIGN_OUT)->user_id);
    }

    public function test_the_public_flag_and_the_rate_limit_are_recorded_once_a_minute(): void
    {
        config(['novarr.public_hosts' => ['read.example.com']]);
        $this->enableSignIn();
        $reader = $this->makeReader();

        for ($i = 0; $i < 5; $i++) {
            $this->asNobody()->post('http://read.example.com/login', ['username' => 'sam', 'password' => 'wrong'])->assertRedirect();
        }
        $this->assertTrue($this->last(AuthEvent::SIGN_IN_FAILED)->public);

        // Over the per-name limit: refused, recorded once however often it is hit.
        for ($i = 0; $i < 3; $i++) {
            $this->asNobody()->post('http://read.example.com/login', ['username' => 'sam', 'password' => 'wrong']);
        }
        $limited = AuthEvent::where('event', AuthEvent::RATE_LIMITED)->get();
        $this->assertCount(1, $limited);
        $this->assertSame($reader->id, $limited[0]->user_id);
        $this->assertSame(['via' => 'web', 'limit' => 'name'], $limited[0]->detail);
        $this->assertTrue($limited[0]->public);
    }

    public function test_api_and_basic_sign_ins_tokens_and_pairing_are_recorded(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();

        $token = $this->asNobody()->postJson('/api/v1/auth/token', [
            'username' => 'sam', 'password' => self::READER_PASSWORD, 'device_id' => 'ios-1', 'device_name' => 'Sam’s iPhone',
        ])->assertOk()->json('token');
        $signIn = $this->last(AuthEvent::SIGN_IN);
        $this->assertSame(['via' => 'api', 'device' => 'Sam’s iPhone'], $signIn->detail);
        $this->assertSame('Sam’s iPhone', $signIn->device());
        $issued = $this->last(AuthEvent::TOKEN_ISSUED);
        $this->assertSame('password', $issued->detail['via']);
        $this->assertSame('Sam’s iPhone', $issued->detail['name']);

        $this->asToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->assertSame('api', $this->last(AuthEvent::TOKEN_REVOKED)->detail['via']);

        // OPDS: failures are history; the successes (every request) are not.
        $before = AuthEvent::where('event', AuthEvent::SIGN_IN)->count();
        $this->asNobody()->withHeader('Authorization', 'Basic ' . base64_encode('sam:' . self::READER_PASSWORD))->get('/opds')->assertOk();
        $this->assertSame($before, AuthEvent::where('event', AuthEvent::SIGN_IN)->count());
        $this->asNobody()->withHeader('Authorization', 'Basic ' . base64_encode('sam:nope'))->get('/opds')->assertStatus(401);
        $this->assertSame('basic', $this->last(AuthEvent::SIGN_IN_FAILED)->detail['via']);

        // Linking a Kindle by code issues a token: recorded as a pairing.
        $start = $this->asNobody()->postJson('/api/v1/auth/pairings', ['device_name' => 'Kindle'])->assertStatus(201);
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->post(route('account.link.confirm'), ['pairing' => DevicePairing::sole()->id, 'code' => $start->json('code')]);
        $paired = $this->last(AuthEvent::TOKEN_ISSUED);
        $this->assertSame(['via' => 'pairing', 'token_id' => ApiToken::where('name', 'Kindle')->value('id'), 'name' => 'Kindle'], $paired->detail);

        $kindle = ApiToken::where('name', 'Kindle')->firstOrFail();
        $this->withBrowser($cookies)->delete(route('account.tokens.revoke', $kindle->id))->assertRedirect();
        $this->assertSame('account', $this->last(AuthEvent::TOKEN_REVOKED)->detail['via']);
        $this->assertSame($reader->id, $this->last(AuthEvent::TOKEN_REVOKED)->user_id);
    }

    public function test_admin_actions_name_the_admin_and_the_account(): void
    {
        $owner = $this->owner();
        // Sign-in off: every request is the owner (the admin).
        $this->post(route('settings.users.store'), ['name' => 'Kim', 'username' => 'kim', 'password' => 'kims-first-pass'])->assertRedirect();
        $kim = User::where('username', 'kim')->firstOrFail();
        $created = $this->last(AuthEvent::ACCOUNT_CREATED);
        $this->assertSame([$kim->id, $owner->id, ['via' => 'admin']], [$created->user_id, $created->actor_user_id, $created->detail]);

        $this->put(route('settings.users.update', $kim->id), ['name' => 'Kim Lee', 'username' => 'kim'])->assertRedirect();
        $this->assertSame(['changed' => ['display name']], $this->last(AuthEvent::ACCOUNT_EDITED)->detail);

        $this->post(route('settings.users.password', $kim->id), ['password' => 'kims-second-pass'])->assertRedirect();
        $this->assertSame($owner->id, $this->last(AuthEvent::PASSWORD_SET)->actor_user_id);

        $this->post(route('settings.users.disable', $kim->id))->assertRedirect();
        $this->post(route('settings.users.disable', $kim->id))->assertRedirect();
        $this->assertSame($kim->id, $this->last(AuthEvent::ACCOUNT_DISABLED)->user_id);
        $this->assertSame($kim->id, $this->last(AuthEvent::ACCOUNT_ENABLED)->user_id);

        $this->delete(route('settings.users.destroy', $kim->id))->assertRedirect();
        $deleted = $this->last(AuthEvent::ACCOUNT_DELETED);
        $this->assertSame(['name' => 'Kim Lee', 'username' => 'kim'], $deleted->detail);

        // The switch, from the UI and from the server (no address).
        $owner->setNewPassword(self::OWNER_PASSWORD);
        $this->post(route('settings.users.auth'), ['required' => '1'])->assertRedirect();
        $ui = $this->last(AuthEvent::SIGN_IN_SWITCH);
        $this->assertSame(['on' => true, 'via' => 'ui'], $ui->detail);
        $this->assertSame('127.0.0.1', $ui->ip);
        $this->artisan('novarr:auth', ['action' => 'off'])->assertExitCode(0);
        $cli = $this->last(AuthEvent::SIGN_IN_SWITCH);
        $this->assertSame(['on' => false, 'via' => 'cli'], $cli->detail);
        $this->assertNull($cli->ip);
        $this->assertSame('Sign-in turned off (on the server)', $cli->label());

        $this->artisan('novarr:password', ['--password' => 'console-password-1'])->assertExitCode(0);
        $this->assertSame(['via' => 'cli'], $this->last(AuthEvent::PASSWORD_SET)->detail);
    }

    public function test_invites_are_recorded_without_their_links(): void
    {
        $owner = $this->owner();
        $this->post('/settings/users/invites', ['name' => 'Sam Lee', 'email' => 'sam@example.test'])->assertRedirect();
        $link = session(UserInviteController::FLASH)['link'];
        $invite = UserInvite::sole();
        $this->post(route('settings.users.invites.resend', $invite->id))->assertRedirect();
        $link = session(UserInviteController::FLASH)['link'];
        $token = substr($link, strrpos($link, '/') + 1);

        $this->enableSignIn();
        $this->asNobody()->post('/invite/' . $token, [
            'name' => 'Sam Lee', 'username' => 'sam.lee', 'password' => 'sams-long-password', 'password_confirmation' => 'sams-long-password',
        ])->assertRedirect(route('home'));
        $sam = User::where('username', 'sam.lee')->firstOrFail();

        $this->assertSame([AuthEvent::INVITE_CREATED, AuthEvent::INVITE_RESENT, AuthEvent::ACCOUNT_CREATED, AuthEvent::INVITE_ACCEPTED], $this->events());
        $this->assertSame($owner->id, $this->last(AuthEvent::INVITE_CREATED)->actor_user_id);
        $this->assertSame('sam@example.test', $this->last(AuthEvent::INVITE_RESENT)->detail['email']);
        $this->assertSame(['via' => 'invite', 'invite_id' => $invite->id], $this->last(AuthEvent::ACCOUNT_CREATED)->detail);
        $this->assertSame($sam->id, $this->last(AuthEvent::INVITE_ACCEPTED)->user_id);

        // Cancelling another invite.
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);
        $this->withBrowser($admin)->post('/settings/users/invites', ['name' => 'Kim', 'email' => 'kim@example.test']);
        $kimInvite = UserInvite::where('email', 'kim@example.test')->firstOrFail();
        $this->withBrowser($admin)->delete(route('settings.users.invites.destroy', $kimInvite->id))->assertRedirect();
        $this->assertSame('kim@example.test', $this->last(AuthEvent::INVITE_CANCELLED)->detail['email']);

        // Nothing secret anywhere in the history.
        $all = json_encode(DB::table('auth_events')->get());
        $this->assertStringNotContainsString($token, $all);
        $this->assertStringNotContainsString('/invite/', $all);
        $this->assertStringNotContainsString('sams-long-password', $all);
        $this->assertStringNotContainsString(self::OWNER_PASSWORD, $all);
        $this->assertStringNotContainsString((string) UserInvite::whereKey($invite->id)->value('token_hash'), $all);
    }

    public function test_own_password_change_and_name_change_are_recorded(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);

        $this->withBrowser($cookies)->post(route('account.profile'), ['name' => 'Sammy'])->assertRedirect();
        $this->assertSame(['changed' => ['display name']], $this->last(AuthEvent::ACCOUNT_EDITED)->detail);
        $this->assertNull($this->last(AuthEvent::ACCOUNT_EDITED)->actor_user_id);

        $this->withBrowser($cookies)->post(route('account.password'), [
            'current_password' => self::READER_PASSWORD, 'password' => 'a-brand-new-pass', 'password_confirmation' => 'a-brand-new-pass',
        ])->assertRedirect(route('account'));
        $this->assertSame($reader->id, $this->last(AuthEvent::PASSWORD_CHANGED)->user_id);
        $this->assertStringNotContainsString('a-brand-new-pass', json_encode(DB::table('auth_events')->get()));
    }

    public function test_the_history_page_is_admin_only_filters_and_pages(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);
        $readerCookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => 'wrong']);

        $this->withBrowser($readerCookies)->get(route('settings.users.history'))->assertForbidden();
        $this->asNobody()->get(route('settings.users.history'))->assertRedirect(route('login'));

        $page = $this->withBrowser($admin)->get(route('settings.users.history'))->assertOk()->getContent();
        $this->assertStringContainsString('Sign-in failed: wrong password', $page);
        $this->assertStringContainsString('Sam (sam)', $page);
        $this->withBrowser($admin)->get(route('settings.users'))->assertOk()->assertSee(route('settings.users.history'));

        $failures = $this->withBrowser($admin)->get(route('settings.users.history', ['group' => 'failures']))->assertOk()->getContent();
        $this->assertStringContainsString('Sign-in failed', $failures);
        $this->assertStringNotContainsString('>Signed in<', $failures);

        $ownerOnly = $this->withBrowser($admin)->get(route('settings.users.history', ['user' => $owner->id]))->getContent();
        preg_match('#id="historyList">(.*?)</ul>#s', $ownerOnly, $list);
        $this->assertStringContainsString('Signed in', $list[1]);
        $this->assertStringNotContainsString('Sam (sam)', $list[1]);

        // 50 a page, newest first.
        AuthEvent::insert(array_map(fn($i) => [
            'occurred_at' => now()->subMinutes(200 - $i), 'user_id' => $reader->id, 'event' => AuthEvent::SIGN_OUT, 'ip' => '10.0.0.' . $i, 'public' => false,
        ], range(1, 60)));
        $first = $this->withBrowser($admin)->get(route('settings.users.history', ['group' => 'sign-ins', 'user' => $reader->id]))->getContent();
        $this->assertSame(50, substr_count($first, 'class="account-device history-row'));
        $this->assertStringContainsString('page=2', $first);
        $second = $this->withBrowser($admin)->get(route('settings.users.history', ['group' => 'sign-ins', 'user' => $reader->id, 'page' => 2]))->getContent();
        $this->assertSame(11, substr_count($second, 'class="account-device history-row'));
    }

    public function test_the_history_page_is_refused_on_the_public_address(): void
    {
        config(['novarr.public_hosts' => ['read.example.com']]);
        $owner = $this->enableSignIn();
        $admin = $this->webSignIn($owner->username, self::OWNER_PASSWORD);

        $this->withBrowser($admin)->get('http://read.example.com/settings/users/history')->assertForbidden();
    }

    public function test_the_account_page_lists_your_own_last_twenty_sign_ins(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $other = $this->makeReader('kim', 'Kim');
        AuthEvent::insert(array_map(fn($i) => [
            'occurred_at' => now()->subHours(30 - $i), 'user_id' => $reader->id, 'event' => $i % 2 ? AuthEvent::SIGN_IN : AuthEvent::SIGN_IN_FAILED,
            'ip' => '198.51.100.' . $i, 'public' => $i === 25, 'user_agent' => self::FIREFOX_MAC,
        ], range(1, 25)));
        AuthEvent::create(['occurred_at' => now(), 'user_id' => $other->id, 'event' => AuthEvent::SIGN_IN, 'ip' => '192.0.2.99']);
        AuthEvent::create(['occurred_at' => now(), 'user_id' => $reader->id, 'event' => AuthEvent::PASSWORD_CHANGED, 'ip' => '192.0.2.98']);

        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $page = $this->withBrowser($cookies)->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('Recent sign-ins', $page);
        preg_match('#<ul class="account-devices history-list" id="signInsList">(.*?)</ul>#s', $page, $m);
        $this->assertSame(20, substr_count($m[1], '<li'));
        $this->assertStringContainsString('Firefox on macOS', $m[1]);
        $this->assertStringContainsString('198.51.100.25 · public address', $m[1]);
        $this->assertStringContainsString('home network', $m[1]);
        $this->assertStringNotContainsString('192.0.2.99', $page);
        $this->assertStringNotContainsString('192.0.2.98', $page);
    }

    public function test_with_sign_in_off_the_account_page_is_still_404_and_the_history_page_works(): void
    {
        $this->get(route('account'))->assertNotFound();
        $this->get(route('settings.users.history'))->assertOk()->assertSee('Nothing here yet.');
    }

    public function test_old_history_is_pruned_daily_unless_kept_forever(): void
    {
        foreach ([200, 181, 179, 1] as $days) {
            AuthEvent::create(['occurred_at' => now()->subDays($days), 'event' => AuthEvent::SIGN_IN]);
        }

        $this->artisan('novarr:prune-auth-log')->expectsOutputToContain('Deleted 2')->assertExitCode(0);
        $this->assertSame(2, AuthEvent::count());

        config(['novarr.auth_log_days' => 0]);
        AuthEvent::create(['occurred_at' => now()->subYears(3), 'event' => AuthEvent::SIGN_IN]);
        $this->artisan('novarr:prune-auth-log')->expectsOutputToContain('kept forever')->assertExitCode(0);
        $this->assertSame(3, AuthEvent::count());

        config(['novarr.auth_log_days' => 1]);
        $this->artisan('novarr:prune-auth-log')->assertExitCode(0);
        $this->assertSame(1, AuthEvent::count());
    }

    public function test_a_write_failure_never_breaks_signing_in(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        \Illuminate\Support\Facades\Schema::drop('auth_events');

        $this->asNobody()->post('/login', ['username' => 'sam', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));
    }

    public function test_device_descriptions(): void
    {
        $cases = [
            self::FIREFOX_MAC => 'Firefox on macOS',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1' => 'Safari on iPhone',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' => 'Chrome on Windows',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0' => 'Edge on Windows',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36' => 'Chrome on Android',
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0 Mobile/15E148 Safari/604.1' => 'Chrome on iPad',
            'NovarrKOReader/1.9.0' => 'KOReader',
            'okhttp/4.12.0' => 'Novarr app on Android',
            'Novarr/1 CFNetwork/1568.100.1 Darwin/24.0.0' => 'Novarr app on iOS',
            'curl/8.7.1' => 'curl',
            '' => DeviceDescription::UNKNOWN,
            'something odd' => DeviceDescription::UNKNOWN,
        ];
        foreach ($cases as $ua => $expected) {
            $this->assertSame($expected, DeviceDescription::from($ua), $ua);
        }
    }

    public function test_user_agents_are_cut_to_255_characters(): void
    {
        $this->enableSignIn();
        $this->makeReader();
        $this->asNobody()->withHeader('User-Agent', str_repeat('é', 400))->post('/login', ['username' => 'sam', 'password' => 'wrong']);

        $this->assertLessThanOrEqual(255, strlen((string) $this->last(AuthEvent::SIGN_IN_FAILED)->user_agent));
    }
}
