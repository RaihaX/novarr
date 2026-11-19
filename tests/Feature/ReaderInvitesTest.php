<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\UserInviteController;
use App\Mail\ReaderInvite;
use App\Support\AuthMode;
use App\Support\RouteAccess;
use App\User;
use App\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Inviting a reader by email (Settings → Users → Invite a reader) and
 * accepting at /invite/{token}; signing in by email; the Android app
 * download offered to readers. Real requests, real cookies.
 */
class ReaderInvitesTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private ?string $apk = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Mail "configured" (the log mailer counts as not set up); sends are faked per test.
        config(['mail.default' => 'smtp']);
        // No Android app unless a test puts one there.
        config(['novarr.android_apk_path' => sys_get_temp_dir() . '/novarr-test-missing-' . uniqid() . '.apk']);
    }

    protected function tearDown(): void
    {
        if ($this->apk !== null) {
            @unlink($this->apk);
            @unlink($this->apk . '.json');
        }
        parent::tearDown();
    }

    /** Invite as the admin (sign-in off: every request is the owner). Returns [response, plain token]. */
    private function invite(string $name = 'Sam Lee', string $email = 'sam@example.test', string $host = 'http://novarr.lan:8080'): array
    {
        $response = $this->post($host . '/settings/users/invites', ['name' => $name, 'email' => $email]);
        $result = session(UserInviteController::FLASH);

        return [$response, $result ? $this->tokenOf($result['link']) : null];
    }

    private function tokenOf(string $link): string
    {
        return substr($link, strrpos($link, '/invite/') + strlen('/invite/'));
    }

    private function putApk(?array $meta = ['version' => '1.2', 'build' => 7]): void
    {
        $this->apk = sys_get_temp_dir() . '/novarr-test-' . uniqid() . '.apk';
        file_put_contents($this->apk, 'PK fake apk bytes');
        if ($meta !== null) {
            file_put_contents($this->apk . '.json', json_encode($meta));
        }
        config(['novarr.android_apk_path' => $this->apk]);
    }

    private function accept(string $token, array $overrides = [])
    {
        return $this->post('/invite/' . $token, $overrides + [
            'name' => 'Sam Lee',
            'username' => 'sam.lee',
            'password' => 'sams-password',
            'password_confirmation' => 'sams-password',
        ]);
    }

    // ---- Making invites (admin) ------------------------------------------------

    public function test_an_invite_is_emailed_and_its_link_shown_once_on_the_admins_origin(): void
    {
        Mail::fake();
        $this->owner()->forceFill(['name' => 'Reyhan'])->save();

        [$response, $token] = $this->invite('Sam Lee', ' Sam@Example.TEST ');
        $response->assertRedirect(route('settings.users') . '#invite');

        $invite = UserInvite::sole();
        $this->assertSame('sam@example.test', $invite->email);
        $this->assertSame('Sam Lee', $invite->name);
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertSame(64, strlen($token));
        $this->assertSame($this->owner()->id, $invite->invited_by);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $invite->expires_at->getTimestamp(), 5);
        // Only the hash is stored.
        $this->assertStringNotContainsString($token, json_encode(\DB::table('user_invites')->first()));

        $link = 'http://novarr.lan:8080/invite/' . $token;
        Mail::assertSent(ReaderInvite::class, function (ReaderInvite $mail) use ($link) {
            $this->assertTrue($mail->hasTo('sam@example.test'));
            $this->assertSame($link, $mail->link);
            $html = $mail->render();
            $this->assertStringContainsString('Reyhan invited you', $html);
            $this->assertStringContainsString($link, $html);
            $this->assertStringContainsString('works until ' . now()->addDays(7)->format('j F Y'), $html);
            $this->assertStringNotContainsString('Android', $html);
            $this->assertSame("You're invited to Novarr", $mail->build()->subject);

            return true;
        });

        // The page shows the link (selectable, with Copy) just this once.
        $page = $this->get(route('settings.users'))->assertOk()->getContent();
        $this->assertStringContainsString('Invite sent to sam@example.test', $page);
        $this->assertStringContainsString('value="' . $link . '"', $page);
        $this->assertStringContainsString('data-copy-target="#inviteLink"', $page);
        $this->assertStringContainsString('The link uses this address', $page);
        $this->get(route('settings.users'))->assertOk()->assertDontSee($token);

        // Listed as pending, with Resend and Cancel.
        $page = $this->get(route('settings.users'))->getContent();
        $this->assertStringContainsString('id="invite-' . $invite->id . '"', $page);
        $this->assertStringContainsString(route('settings.users.invites.resend', $invite->id), $page);
        $this->assertStringContainsString(route('settings.users.invites.destroy', $invite->id), $page);
    }

    public function test_an_email_with_an_account_or_a_pending_invite_is_refused(): void
    {
        Mail::fake();
        $this->makeReader()->forceFill(['email' => 'Taken@Example.test'])->save();

        $this->invite('Someone', 'taken@EXAMPLE.test')[0]->assertSessionHasErrors(['email'], null, 'invite');
        $this->assertSame(0, UserInvite::count());

        $this->invite('Kim', 'kim@example.test');
        $first = UserInvite::sole();
        $this->invite('Kim again', 'KIM@example.test')[0]
            ->assertSessionHasErrors(['email'], null, 'invite')
            ->assertSessionHas(UserInviteController::FLASH_DUPLICATE, $first->id);
        $this->assertSame(1, UserInvite::count());
        Mail::assertSentCount(1);

        // Validation.
        $this->invite('', 'not-an-email')[0]->assertSessionHasErrors(['name', 'email'], null, 'invite');
    }

    public function test_resend_makes_a_new_link_and_the_old_one_stops_working(): void
    {
        Mail::fake();
        [, $old] = $this->invite();
        $invite = UserInvite::sole();
        $this->travel(2)->days();

        $this->post(route('settings.users.invites.resend', $invite->id))->assertRedirect();
        $new = $this->tokenOf(session(UserInviteController::FLASH)['link']);
        $this->assertNotSame($old, $new);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $invite->fresh()->expires_at->getTimestamp(), 5);
        Mail::assertSentCount(2);

        $this->get('/invite/' . $old)->assertNotFound()->assertSee('This invite link does not work');
        $this->get('/invite/' . $new)->assertOk()->assertSee('Welcome, Sam Lee');
    }

    public function test_cancel_deletes_the_invite_and_its_link(): void
    {
        Mail::fake();
        [, $token] = $this->invite();
        $invite = UserInvite::sole();

        $this->delete(route('settings.users.invites.destroy', $invite->id))->assertRedirect()->assertSessionHas('status');
        $this->assertSame(0, UserInvite::count());
        $this->get('/invite/' . $token)->assertNotFound()->assertSee('Ask the person who runs this Novarr to send you a new invite', false);
        $this->accept($token)->assertNotFound();
        $this->assertNull(User::findByEmail('sam@example.test'));
    }

    public function test_an_expired_invite_does_not_work_shows_as_expired_and_can_be_replaced(): void
    {
        Mail::fake();
        [, $token] = $this->invite();
        $this->travel(8)->days();

        $this->get('/invite/' . $token)->assertNotFound()->assertSee('This invite link does not work');
        $this->accept($token)->assertNotFound();
        $this->get(route('settings.users'))->assertSee('Expired');
        $this->assertSame(0, UserInvite::pending()->count());

        // A new invite for the same address replaces the expired one.
        [, $fresh] = $this->invite();
        $this->assertSame(1, UserInvite::count());
        $this->get('/invite/' . $fresh)->assertOk();
    }

    public function test_a_failed_email_still_makes_the_invite_and_shows_the_link(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused by smtp.example'));
        [$response, $token] = $this->invite();
        $response->assertRedirect();
        $this->assertSame(1, UserInvite::count());
        $result = session(UserInviteController::FLASH);
        $this->assertFalse($result['sent']);

        $page = $this->get(route('settings.users'))->getContent();
        $this->assertStringContainsString('but the email was not sent', $page);
        $this->assertStringContainsString('Connection refused by smtp.example', $page);
        $this->assertStringContainsString('/invite/' . $token, $page);
    }

    public function test_with_mail_not_set_up_the_page_says_so_and_shows_the_link(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();
        [, $token] = $this->invite();
        Mail::assertNothingSent();
        $page = $this->get(route('settings.users'))->getContent();
        $this->assertStringContainsString('Email is not set up on this server', $page);
        $this->assertStringContainsString('/invite/' . $token, $page);
    }

    public function test_readers_cannot_invite_resend_or_cancel(): void
    {
        Mail::fake();
        $this->enableSignIn();
        $this->makeReader();
        $invite = UserInvite::issue('Kim', 'kim@example.test', $this->owner())[0];
        $reader = $this->webSignIn('sam', self::READER_PASSWORD);

        $this->withBrowser($reader)->post(route('settings.users.invites.store'), ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
        $this->withBrowser($reader)->post(route('settings.users.invites.resend', $invite->id))->assertForbidden();
        $this->withBrowser($reader)->delete(route('settings.users.invites.destroy', $invite->id))->assertForbidden();
        $this->assertSame(1, UserInvite::count());
        Mail::assertNothingSent();
    }

    public function test_invite_routes_are_public_and_management_is_admin(): void
    {
        foreach (['invite.show', 'invite.accept', 'app.android'] as $name) {
            $this->assertSame(RouteAccess::PUBLIC, RouteAccess::classifyKey($name), $name);
        }
        foreach (['settings.users.invites.store', 'settings.users.invites.resend', 'settings.users.invites.destroy'] as $name) {
            $this->assertSame(RouteAccess::ADMIN, RouteAccess::classifyKey($name), $name);
            $this->assertTrue(RouteAccess::isListed($name));
        }
    }

    // ---- Accepting ---------------------------------------------------------------

    public function test_accepting_makes_a_reader_signs_them_in_and_uses_up_the_link(): void
    {
        Mail::fake();
        $this->enableSignIn();
        $owner = $this->webSignIn($this->owner()->username, self::OWNER_PASSWORD);
        $this->withBrowser($owner)->post('/settings/users/invites', ['name' => 'Sam Lee', 'email' => 'Sam@Example.test']);
        $token = $this->tokenOf(session(UserInviteController::FLASH)['link']);

        // A stranger's browser opens the link.
        $page = $this->asNobody()->get('/invite/' . $token)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString('Welcome, Sam Lee', $page);
        $this->assertStringContainsString('value="sam.lee"', $page); // suggested sign-in name
        $this->assertStringContainsString('name="password_confirmation"', $page);
        $this->assertStringNotContainsString('Sign-in is not required', $page);

        $done = $this->asNobody()->accept($token, ['name' => 'Sammy', 'username' => 'Sam.L']);
        $done->assertRedirect(route('home'));
        $user = User::where('username', 'sam.l')->sole();
        $this->assertSame('Sammy', $user->name);
        $this->assertSame('sam@example.test', $user->email);
        $this->assertSame(User::ROLE_READER, $user->role);
        $this->assertNotNull($user->password_set_at);
        $this->assertTrue(Hash::check('sams-password', $user->password));
        $invite = UserInvite::sole();
        $this->assertNotNull($invite->accepted_at);
        $this->assertSame($user->id, $invite->user_id);
        $this->assertSame(0, UserInvite::unaccepted()->count());

        // Signed in (remembered) and welcomed once on Home.
        $browser = $this->cookiesOf($done);
        $this->assertTrue(collect(array_keys($browser))->contains(fn($k) => str_starts_with($k, 'remember_web_')));
        $home = $this->withBrowser($browser)->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Welcome to Novarr, Sammy.', $home);
        $this->assertStringContainsString(route('account') . '#link', $home);
        $this->withBrowser($browser)->get(route('account'))->assertOk()->assertSee('sam@example.test');
        $this->withBrowser($browser)->get(route('home'))->assertDontSee('Welcome to Novarr');

        // The link is used up.
        $this->asNobody()->get('/invite/' . $token)->assertNotFound()->assertSee('This invite link does not work');
        $this->asNobody()->accept($token, ['username' => 'other'])->assertNotFound();
        $this->assertSame(0, User::where('username', 'other')->count());

        // The admin's list shows the email; the invite is no longer pending.
        $users = $this->withBrowser($owner)->get(route('settings.users'))->getContent();
        $this->assertStringContainsString('sam@example.test', $users);
        $this->assertStringNotContainsString('id="invite-' . $invite->id . '"', $users);
    }

    public function test_accept_validates_like_everywhere_else(): void
    {
        Mail::fake();
        [, $token] = $this->invite();
        $this->makeReader('taken');

        $this->accept($token, ['name' => '', 'username' => 'a', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertRedirect()->assertSessionHasErrors(['name', 'username', 'password']);
        $this->accept($token, ['password_confirmation' => 'different-one'])->assertSessionHasErrors(['password']);
        $this->accept($token, ['username' => 'bad name!'])->assertSessionHasErrors(['username']);
        $this->accept($token, ['username' => 'TAKEN'])->assertSessionHasErrors(['username' => 'Someone already signs in with that name.']);
        $this->assertSame(0, User::where('email', 'sam@example.test')->count());
        $this->assertNull(UserInvite::sole()->accepted_at);

        // The suggestion steps around a taken name.
        $this->makeReader('sam.lee', 'Other Sam');
        $this->flushSession(); // no old input from the refused tries
        $this->get('/invite/' . $token)->assertOk()->assertSee('value="sam.lee2"', false);
    }

    public function test_a_signed_in_visitor_is_told_and_not_switched(): void
    {
        Mail::fake();
        $this->enableSignIn();
        $owner = $this->webSignIn($this->owner()->username, self::OWNER_PASSWORD);
        $this->withBrowser($owner)->post('/settings/users/invites', ['name' => 'Sam Lee', 'email' => 'sam@example.test']);
        $token = $this->tokenOf(session(UserInviteController::FLASH)['link']);

        $page = $this->withBrowser($owner)->get('/invite/' . $token)->assertOk()->getContent();
        $this->assertStringContainsString('id="inviteSignedIn"', $page);
        $this->assertStringContainsString('You are already signed in on this browser', $page);
        $this->assertStringNotContainsString('name="password_confirmation"', $page);

        $this->withBrowser($owner)->accept($token)->assertRedirect(route('invite.show', $token));
        $this->assertNull(User::findByEmail('sam@example.test'));

        // "Sign out and continue" comes back to the link, signed out.
        $out = $this->withBrowser($owner)->post(route('logout'), ['next' => '/invite/' . $token]);
        $out->assertRedirect(url('/invite/' . $token));
        // Only invite paths are followed.
        $this->withBrowser($owner)->post(route('logout'), ['next' => 'https://evil.example/']) ->assertRedirect(route('login'));
    }

    public function test_accepting_while_sign_in_is_off_makes_the_account_and_says_so(): void
    {
        Mail::fake();
        [, $token] = $this->invite();
        $this->assertFalse(AuthMode::required());

        $this->get('/invite/' . $token)->assertOk()->assertSee('does not ask people to sign in yet');
        $this->accept($token)->assertOk()
            ->assertSee('Your account is ready, Sam Lee')
            ->assertSee('Sign-in is not required on this Novarr yet');
        $this->assertSame('sam.lee', User::findByEmail('sam@example.test')->username);
        $this->assertNotNull(UserInvite::sole()->accepted_at);
    }

    public function test_deleting_a_user_removes_their_invite_and_status_counts_pending(): void
    {
        Mail::fake();
        [, $token] = $this->invite();
        $this->invite('Kim', 'kim@example.test');
        $this->accept($token);
        $user = User::findByEmail('sam@example.test');

        $this->artisan('novarr:auth', ['action' => 'status'])
            ->expectsOutputToContain('Pending invites')
            ->assertSuccessful();
        $this->assertSame(1, UserInvite::pending()->count());

        $this->delete(route('settings.users.destroy', $user->id))->assertRedirect();
        $this->assertSame(0, UserInvite::where('email', 'sam@example.test')->count());
        $this->assertSame(1, UserInvite::count());
    }

    // ---- Signing in by email -----------------------------------------------------

    public function test_sign_in_accepts_the_email_on_web_api_and_basic(): void
    {
        $this->enableSignIn();
        $this->makeReader()->forceFill(['email' => 'Sam@Example.test'])->save();
        $this->makeReader('kim', 'Kim'); // no email: unaffected

        $this->asNobody()->post('/login', ['username' => ' SAM@example.TEST ', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));
        $this->asNobody()->post('/login', ['username' => 'kim', 'password' => self::READER_PASSWORD])->assertRedirect(route('home'));

        $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'sam@example.test', 'password' => self::READER_PASSWORD])
            ->assertOk()->assertJsonPath('user.username', 'sam');
        $this->asNobody()->get('/opds', ['Authorization' => 'Basic ' . base64_encode('sam@EXAMPLE.test:' . self::READER_PASSWORD)])->assertOk();

        // Same generic error for a wrong password and an unknown email.
        $wrong = $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'sam@example.test', 'password' => 'nope'])->assertStatus(422)->json('message');
        $unknown = $this->asNobody()->postJson('/api/v1/auth/token', ['username' => 'nobody@example.test', 'password' => 'nope'])->assertStatus(422)->json('message');
        $this->assertSame($wrong, $unknown);
        $this->asNobody()->post('/login', ['username' => 'nobody@example.test', 'password' => 'x'])
            ->assertSessionHasErrors(['username' => \App\Services\Credentials::WRONG]);
    }

    // ---- Android app ---------------------------------------------------------------

    public function test_the_android_download_is_404_with_a_page_when_missing(): void
    {
        $this->enableSignIn();
        $this->asNobody()->get('/app/android')->assertNotFound()->assertSee('The Android app is not available');
    }

    public function test_the_android_download_streams_the_apk_to_anyone(): void
    {
        $this->putApk();
        $this->enableSignIn();

        $response = $this->asNobody()->get('/app/android')->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=novarr.apk', $response->headers->get('Content-Disposition'));
        $this->assertSame((string) filesize($this->apk), $response->headers->get('Content-Length'));
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertNotEmpty($response->headers->get('Last-Modified'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('PK fake apk bytes', $response->streamedContent());

        // Revalidation: 304 for the same ETag.
        $this->asNobody()->get('/app/android', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    }

    public function test_android_app_links_appear_only_when_an_apk_is_there(): void
    {
        Mail::fake();
        // Not there: no paragraph, no links, Users says how to offer one.
        [, $token] = $this->invite();
        Mail::assertSent(ReaderInvite::class, fn(ReaderInvite $m) => $m->androidUrl === null && !str_contains($m->render(), 'Android'));
        $this->get(route('settings.users'))->assertSee('No Android app to offer readers')->assertSee(config('novarr.android_apk_path'));

        // There: the invite email links it on the same origin.
        $this->putApk();
        Mail::fake();
        [, $token2] = $this->invite('Kim', 'kim@example.test', 'https://reader.example.ts.net');
        Mail::assertSent(ReaderInvite::class, function (ReaderInvite $m) {
            $this->assertSame('https://reader.example.ts.net/app/android', $m->androidUrl);
            $html = $m->render();
            $this->assertStringContainsString('Reading on an Android phone? After you have set your password, install the Novarr app:', $html);
            $this->assertStringContainsString('https://reader.example.ts.net/app/android', $html);
            $this->assertStringContainsString('allow installing apps from your browser', $html);

            return true;
        });
        $this->get(route('settings.users'))->assertSee('Android app (version 1.2, build 7) is available to readers');

        // Welcome notice and Account page.
        $this->enableSignIn();
        $done = $this->asNobody()->accept($token2, ['username' => 'kim']);
        $browser = $this->cookiesOf($done);
        $this->withBrowser($browser)->get(route('home'))->assertSee('Get the Android app (version 1.2, build 7)');
        $this->withBrowser($browser)->get(route('account'))->assertSee('id="accountAndroid"', false);

        @unlink($this->apk);
        $this->withBrowser($browser)->get(route('account'))->assertDontSee('id="accountAndroid"', false);
    }
}
