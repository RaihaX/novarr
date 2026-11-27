<?php

namespace Tests\Feature;

use App\ApiToken;
use App\AuthEvent;
use App\Console\Commands\AnnounceAndroidRelease;
use App\Mail\AndroidReleaseMail;
use App\Setting;
use App\Support\AndroidApp;
use App\Support\AndroidChangelog;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Release notes for the Android app: android/CHANGELOG.md and the
 * sidecar script `make release-android` runs, `notes` in /api/v1/meta,
 * the "What's new" page, the new version email (app:announce-android: who
 * gets it, once per build, --force, --dry-run), its signed "Stop app
 * update emails" link and the Account switch.
 */
class AndroidReleaseAnnouncementTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const ANDROID_UA = 'okhttp/4.12.0';

    private string $apk;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp', 'novarr.announce_pause_ms' => 0]);
        $this->apk = sys_get_temp_dir() . '/novarr-announce-' . uniqid() . '.apk';
        config(['novarr.android_apk_path' => $this->apk]);
    }

    protected function tearDown(): void
    {
        @unlink($this->apk);
        @unlink($this->apk . '.json');
        parent::tearDown();
    }

    private function publish(string $version = '1.17', int $build = 18, ?array $notes = ['Progress syncs as you read.', 'A Stats page.']): void
    {
        file_put_contents($this->apk, 'apk');
        $meta = ['version' => $version, 'build' => $build];
        if ($notes !== null) {
            $meta['notes'] = $notes;
        }
        file_put_contents($this->apk . '.json', json_encode($meta));
    }

    /** A reader with an email, a live app token and (by default) Android reads. */
    private function androidReader(string $username, array $attrs = [], bool $android = true): User
    {
        $user = $this->makeReaderAccount($username, ucfirst($username));
        $user->forceFill(array_merge(['email' => "{$username}@example.test"], $attrs))->save();
        ApiToken::issue($user, 'Pixel 8', 'device-' . $username);
        if ($android) {
            $this->readOn($user, 'Novarr app on Android');
        }

        return $user->fresh();
    }

    private function readOn(User $user, string $client): void
    {
        static $chapter = 0;
        DB::table('chapter_reads')->insert([
            'user_id' => $user->id, 'novel_id' => 1, 'novel_chapter_id' => ++$chapter,
            'read_at' => now(), 'progress' => 100, 'client' => $client,
        ]);
    }

    private function ownerWithEmail(string $email = 'owner@example.test'): User
    {
        $owner = $this->owner();
        $owner->forceFill(['email' => $email])->save();

        return $owner->fresh();
    }

    /** @return list<string> */
    private function recipientEmails(): array
    {
        return AnnounceAndroidRelease::recipients()->pluck('email')->all();
    }

    // ---- the changelog -----------------------------------------------------------

    public function test_the_changelog_parses_into_sections_newest_first(): void
    {
        $md = "# Novarr\n\nIntro text.\n\n## 1.18 (19)\n\n- Notes to come\n\n## 1.17 (18)\n\n- Progress **syncs**\n  across devices.\n* A `Stats` page\n\n## 1.16\n- Old\n";
        $sections = AndroidChangelog::parse($md);

        $this->assertSame(['1.18', '1.17', '1.16'], array_column($sections, 'version'));
        $this->assertSame([19, 18, null], array_column($sections, 'build'));
        $this->assertSame(['Progress syncs across devices.', 'A Stats page'], $sections[1]['notes']);
        $this->assertSame(['Progress syncs across devices.', 'A Stats page'], AndroidChangelog::notesFor($md, '1.17', 18));
        $this->assertSame(['Old'], AndroidChangelog::notesFor($md, '1.16', 17));
    }

    public function test_the_changelog_refuses_a_missing_placeholder_mismatched_or_empty_section(): void
    {
        $md = "## 1.18 (19)\n\n- Notes to come\n\n## 1.17 (18)\n\n## 1.16 (17)\n\n- Fine\n";
        $cases = [
            ['1.19', 20, 'has no section for 1.19'],
            ['1.18', 19, 'still says "Notes to come"'],
            ['1.17', 18, 'has no notes under'],
            ['1.16', 99, 'says 1.16 is build 17'],
        ];
        foreach ($cases as [$version, $build, $message]) {
            try {
                AndroidChangelog::notesFor($md, $version, $build);
                $this->fail("{$version} should be refused");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $version);
            }
        }
    }

    public function test_the_repository_changelog_has_every_released_version(): void
    {
        $md = (string) file_get_contents(base_path('android/CHANGELOG.md'));
        foreach (['1.10' => 11, '1.11' => 12, '1.12' => 13, '1.13' => 14, '1.14' => 15, '1.15' => 16, '1.16' => 17] as $version => $build) {
            $this->assertNotEmpty(AndroidChangelog::notesFor($md, $version, $build), $version);
        }
        // The next versions are placeholders until their notes are written.
        foreach (['1.17' => 18, '1.18' => 19] as $version => $build) {
            $section = collect(AndroidChangelog::parse($md))->firstWhere('version', $version);
            $this->assertNotNull($section, $version);
            $this->assertSame($build, $section['build']);
        }
    }

    public function test_the_release_script_writes_the_sidecar_or_refuses(): void
    {
        $dir = sys_get_temp_dir() . '/novarr-changelog-' . uniqid();
        mkdir($dir);
        $changelog = "{$dir}/CHANGELOG.md";
        $out = "{$dir}/novarr.apk.json";
        file_put_contents($changelog, "## 1.18 (19)\n\n- Notes to come\n\n## 1.17 (18)\n\n- Progress syncs.\n- A “Stats” page.\n");
        $script = base_path('scripts/android-release-json.php');
        $run = function (string ...$args) use ($script): array {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
            exec($cmd, $lines, $code);

            return [$code, implode("\n", $lines)];
        };

        try {
            [$code] = $run($changelog, '1.17', '18', $out);
            $this->assertSame(0, $code);
            $this->assertSame(['version' => '1.17', 'build' => 18, 'notes' => ['Progress syncs.', 'A “Stats” page.']], json_decode((string) file_get_contents($out), true));

            [$code, $output] = $run($changelog, '1.17', '18');
            $this->assertSame(0, $code);
            $this->assertSame('1.17', json_decode($output, true)['version']);

            [$code, $output] = $run($changelog, '1.18', '19', "{$dir}/other.json");
            $this->assertSame(1, $code);
            $this->assertStringContainsString('still says "Notes to come"', $output);
            $this->assertFileDoesNotExist("{$dir}/other.json");

            [$code, $output] = $run($changelog, '1.19', '20');
            $this->assertSame(1, $code);
            $this->assertStringContainsString('has no section for 1.19', $output);
        } finally {
            @unlink($out);
            @unlink($changelog);
            @rmdir($dir);
        }
    }

    // ---- meta and the page ----------------------------------------------------------

    public function test_meta_sends_the_notes_last(): void
    {
        $this->publish();
        $android = $this->getJson('/api/v1/meta')->assertOk()->json('android');

        $this->assertSame(['version', 'build', 'url', 'size', 'sha256', 'notes'], array_keys($android));
        $this->assertSame(['Progress syncs as you read.', 'A Stats page.'], $android['notes']);
        $this->assertStringEndsWith('/app/android', $android['url']);
    }

    public function test_meta_notes_are_empty_without_any_and_junk_is_dropped(): void
    {
        $this->publish(notes: null);
        $this->assertSame([], $this->getJson('/api/v1/meta')->json('android.notes'));

        $this->publish(notes: ['  Real  ', '', 42, ['x'], 'Two']);
        $this->assertSame(['Real', 'Two'], $this->getJson('/api/v1/meta')->json('android.notes'));

        file_put_contents($this->apk . '.json', json_encode(['version' => '1.17', 'build' => 18, 'notes' => 'not a list']));
        $this->assertSame([], $this->getJson('/api/v1/meta')->json('android.notes'));
    }

    public function test_the_whats_new_page_shows_the_notes_and_the_download(): void
    {
        $this->enableSignIn();
        $this->publish();

        $this->asNobody()->get('/app/android/whats-new')->assertOk()
            ->assertSee('Novarr for Android 1.17')
            ->assertSee("What's new in 1.17", false)
            ->assertSee('Progress syncs as you read.')
            ->assertSee('A Stats page.')
            ->assertSee('Open Novarr and tap Update')
            ->assertSee('href="' . url('/app/android') . '"', false);

        // No notes: no section, still the download.
        $this->publish(notes: null);
        $this->asNobody()->get('/app/android/whats-new')->assertOk()
            ->assertDontSee('androidWhatsNew', false)
            ->assertSee('Get the Android app');

        // No APK: the "not available" page.
        @unlink($this->apk);
        $this->asNobody()->get('/app/android/whats-new')->assertNotFound()->assertSee('The Android app is not available');
    }

    // ---- who gets the email ----------------------------------------------------------

    public function test_recipients_are_the_owner_and_readers_who_use_the_android_app(): void
    {
        $this->enableSignIn();
        $this->ownerWithEmail();
        $this->androidReader('ann');                                         // Android reads: yes
        $this->androidReader('ios', android: false);                         // only iOS
        $this->readOn(User::where('username', 'ios')->first(), 'Novarr app on iOS');
        $this->androidReader('web', android: false);                         // a token, no Android sign of use
        $off = $this->androidReader('off', ['app_update_emails_off_at' => now()]);
        $this->androidReader('gone', ['disabled_at' => now()]);
        $this->androidReader('nomail', ['email' => null]);
        $this->androidReader('invalid', ['email' => 'nomail@novarr.invalid']);

        // Used Android once, then signed out everywhere (no live app token).
        $revoked = $this->androidReader('revoked');
        ApiToken::where('user_id', $revoked->id)->update(['revoked_at' => now()]);

        // Signed in from the app (the token_issued event's User-Agent), nothing read yet.
        $signedIn = $this->makeReaderAccount('bob', 'Bob');
        $signedIn->forceFill(['email' => 'bob@example.test'])->save();
        ApiToken::issue($signedIn, 'Galaxy');
        AuthEvent::create(['occurred_at' => now(), 'user_id' => $signedIn->id, 'event' => AuthEvent::TOKEN_ISSUED, 'user_agent' => self::ANDROID_UA]);

        // Listened on Android (audiobook position).
        $listener = $this->androidReader('liz', android: false);
        DB::table('audiobook_progress')->insert(['user_id' => $listener->id, 'audiobook_id' => 1, 'position_ms' => 1000, 'client' => 'Novarr app on Android', 'updated_at' => now()]);

        $this->assertSame(['owner@example.test', 'ann@example.test', 'bob@example.test', 'liz@example.test'], $this->recipientEmails());
        $this->assertTrue(AnnounceAndroidRelease::recipients()->first()['owner']);

        // The owner turned it off: only the readers.
        $this->owner()->forceFill(['app_update_emails_off_at' => now()])->save();
        $this->assertSame(['ann@example.test', 'bob@example.test', 'liz@example.test'], $this->recipientEmails());

        // Back on for the reader who had turned it off.
        $off->forceFill(['app_update_emails_off_at' => null])->save();
        $this->assertContains('off@example.test', $this->recipientEmails());
    }

    public function test_the_owner_without_an_email_falls_back_to_the_summary_address(): void
    {
        $this->owner()->forceFill(['email' => null])->save();
        Setting::put('summary_email', 'Summary@Example.test');
        $this->assertSame(['summary@example.test'], $this->recipientEmails());

        Setting::put('summary_email', 'not an address');
        $this->assertSame([], $this->recipientEmails());
    }

    public function test_with_sign_in_off_only_the_owner_gets_it(): void
    {
        $this->ownerWithEmail();
        $this->androidReader('ann');

        $this->assertSame(['owner@example.test'], $this->recipientEmails());
    }

    // ---- the command ----------------------------------------------------------------

    public function test_it_emails_each_recipient_once_per_build(): void
    {
        Mail::fake();
        $this->enableSignIn();
        $this->ownerWithEmail();
        $ann = $this->androidReader('ann');
        $this->publish();

        $this->artisan('app:announce-android')
            ->expectsOutputToContain('1.17 (build 18) announcement: 2 sent, 0 failed.')
            ->assertSuccessful();

        Mail::assertSent(AndroidReleaseMail::class, 2);
        Mail::assertSent(AndroidReleaseMail::class, fn(AndroidReleaseMail $m) => $m->hasTo('ann@example.test')
            && $m->version === '1.17'
            && $m->notes === ['Progress syncs as you read.', 'A Stats page.']
            && str_ends_with($m->downloadUrl, '/app/android/whats-new')
            && str_contains((string) $m->unsubscribeUrl, '/app-updates/stop/' . $ann->id . '?signature='));
        $this->assertSame('18', Setting::get(AnnounceAndroidRelease::ANNOUNCED_KEY));

        // Again: already announced, nothing sent.
        $this->artisan('app:announce-android')->expectsOutputToContain('was already announced')->assertSuccessful();
        Mail::assertSent(AndroidReleaseMail::class, 2);

        // --force sends it again.
        $this->artisan('app:announce-android --force')->assertSuccessful();
        Mail::assertSent(AndroidReleaseMail::class, 4);

        // The next build goes out once more.
        $this->publish('1.18', 19);
        $this->artisan('app:announce-android')->assertSuccessful();
        Mail::assertSent(AndroidReleaseMail::class, 6);
        $this->assertSame('19', Setting::get(AnnounceAndroidRelease::ANNOUNCED_KEY));

        // An older build published again (a rollback) is not announced.
        $this->publish('1.17', 18);
        $this->artisan('app:announce-android')->expectsOutputToContain('was already announced')->assertSuccessful();
        Mail::assertSent(AndroidReleaseMail::class, 6);
    }

    public function test_dry_run_lists_and_sends_and_records_nothing(): void
    {
        Mail::fake();
        $this->enableSignIn();
        $this->ownerWithEmail();
        $this->androidReader('ann');
        $this->publish();

        $this->artisan('app:announce-android --dry-run')
            ->expectsOutputToContain('Dry run: would announce Novarr 1.17 (build 18) to 2 people.')
            ->expectsOutputToContain('ann <ann@example.test>')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull(Setting::get(AnnounceAndroidRelease::ANNOUNCED_KEY));
    }

    public function test_nothing_is_announced_without_a_published_version_or_mail(): void
    {
        Mail::fake();
        $this->ownerWithEmail();

        $this->artisan('app:announce-android')->expectsOutputToContain('No Android app is published')->assertFailed();

        file_put_contents($this->apk, 'apk');
        $this->artisan('app:announce-android')->expectsOutputToContain('has no version and build')->assertFailed();

        $this->publish();
        config(['mail.default' => 'log']);
        $this->artisan('app:announce-android')->expectsOutputToContain('Mail is not set up')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertNull(Setting::get(AnnounceAndroidRelease::ANNOUNCED_KEY));
    }

    public function test_a_failing_send_is_logged_and_the_rest_still_get_theirs(): void
    {
        config(['mail.default' => 'array']);
        Log::spy();
        $this->enableSignIn();
        $this->ownerWithEmail();
        $this->androidReader('ann');
        $this->androidReader('ben');
        $this->publish();
        Event::listen(MessageSending::class, function (MessageSending $event) {
            foreach ($event->message->getTo() as $address) {
                if ($address->getAddress() === 'ann@example.test') {
                    throw new \RuntimeException('mailbox unavailable');
                }
            }
        });

        $this->artisan('app:announce-android')
            ->expectsOutputToContain('2 sent, 1 failed.')
            ->assertSuccessful();

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())
            ->flatMap(fn($m) => array_map(fn($a) => $a->getAddress(), $m->getEnvelope()->getRecipients()))
            ->all();
        $this->assertSame(['owner@example.test', 'ben@example.test'], $sent);
        $this->assertSame('18', Setting::get(AnnounceAndroidRelease::ANNOUNCED_KEY));
        Log::shouldHaveReceived('error')->withArgs(fn($message) => str_contains($message, 'ann@example.test') && str_contains($message, 'mailbox unavailable'))->once();
    }

    public function test_when_every_send_fails_the_build_is_not_recorded(): void
    {
        config(['mail.default' => 'array']);
        $this->ownerWithEmail();
        $this->publish();
        Event::listen(MessageSending::class, fn() => throw new \RuntimeException('smtp down'));

        $this->artisan('app:announce-android')->expectsOutputToContain('not recorded')->assertFailed();
        $this->assertNull(Setting::get(AnnounceAndroidRelease::ANNOUNCED_KEY));
    }

    // ---- the email --------------------------------------------------------------------

    public function test_the_email_has_the_notes_the_button_and_the_stop_link(): void
    {
        $mail = new AndroidReleaseMail('1.17', ['Progress syncs as you read.', 'A <b>Stats</b> page.'], 'https://read.example.com/app/android/whats-new', 'Sam', 'https://read.example.com/app-updates/stop/2?signature=abc', 'https://read.example.com/');
        $html = $mail->render();

        $this->assertSame('Novarr 1.17 is out', $mail->subject);
        $this->assertStringContainsString('data-mail-layout="novarr"', $html);
        $this->assertStringContainsString('Android app', $html);
        $this->assertStringContainsString('Novarr 1.17 is out', $html);
        $this->assertStringContainsString('Hi Sam,', $html);
        $this->assertStringContainsString('Open Novarr and tap Update, or download it here.', $html);
        $this->assertStringContainsString('What&#039;s new', $html);
        $this->assertSame(2, substr_count($html, 'data-mail-item'));
        $this->assertStringContainsString('A &lt;b&gt;Stats&lt;/b&gt; page.', $html);
        $this->assertStringContainsString('href="https://read.example.com/app/android/whats-new"', $html);
        $this->assertStringContainsString('Update in the app', $html);
        $this->assertStringContainsString('You get this because you use the Novarr app on Android.', $html);
        $this->assertStringContainsString('href="https://read.example.com/app-updates/stop/2?signature=abc"', $html);
        $this->assertStringContainsString('Stop app update emails', $html);

        $text = \App\Mail\MailPreviews::text($mail);
        $this->assertStringContainsString("What's new:\n- Progress syncs as you read.\n- A <b>Stats</b> page.", $text);
        $this->assertStringContainsString("Update in the app:\nhttps://read.example.com/app/android/whats-new", $text);
        $this->assertStringContainsString('Stop app update emails: https://read.example.com/app-updates/stop/2?signature=abc', $text);

        // Without notes or a stop link it still reads well.
        $bare = (new AndroidReleaseMail('1.17', [], 'https://h/app/android/whats-new'))->render();
        $this->assertStringNotContainsString('data-mail-list', $bare);
        $this->assertStringNotContainsString('Stop app update emails', $bare);
        $this->assertStringNotContainsString('Hi ,', $bare);
    }

    public function test_the_mail_preview_lists_the_android_release(): void
    {
        $this->assertNotNull(\App\Mail\MailPreviews::make('android-release'));
        $this->assertInstanceOf(AndroidReleaseMail::class, \App\Mail\MailPreviews::make('android-release'));
    }

    // ---- stopping it --------------------------------------------------------------------

    public function test_the_stop_link_asks_first_and_the_button_turns_it_off(): void
    {
        $this->enableSignIn();
        $sam = $this->androidReader('sam');
        $url = AnnounceAndroidRelease::unsubscribeUrl($sam);
        $path = substr($url, strlen(url('/')));

        $this->asNobody()->get($url)->assertOk()
            ->assertSee('Stop app update emails?')
            ->assertSee('Sam')
            ->assertSee('action="' . e($path) . '"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNull($sam->fresh()->app_update_emails_off_at);

        $this->asNobody()->post($url)->assertOk()->assertSee('Done')->assertSee('switched back on in Account');
        $this->assertNotNull($sam->fresh()->app_update_emails_off_at);
        $this->assertNotContains('sam@example.test', $this->recipientEmails());
    }

    public function test_a_bad_signature_is_refused(): void
    {
        $this->enableSignIn();
        $sam = $this->androidReader('sam');
        $kim = $this->androidReader('kim');
        $url = AnnounceAndroidRelease::unsubscribeUrl($sam);

        $this->asNobody()->get($url . 'x')->assertStatus(403);
        $this->asNobody()->post($url . 'x')->assertStatus(403);
        $this->asNobody()->post(str_replace('/app-updates/stop/' . $sam->id, '/app-updates/stop/' . $kim->id, $url))->assertStatus(403);
        $this->asNobody()->get(url('/app-updates/stop/' . $sam->id))->assertStatus(403);
        // The daily summary's signature does not stop app updates (another route).
        $summary = \App\Console\Commands\EmailSummary::unsubscribeUrl($sam);
        $this->asNobody()->post(str_replace('/summary/stop/', '/app-updates/stop/', $summary))->assertStatus(403);
        $this->assertNull($sam->fresh()->app_update_emails_off_at);
    }

    public function test_the_stop_link_uses_the_public_address(): void
    {
        config(['novarr.public_hosts' => ['read.example.com'], 'novarr.public_url' => null]);
        $this->enableSignIn();
        $sam = $this->androidReader('sam');
        $url = AnnounceAndroidRelease::unsubscribeUrl($sam);
        $this->assertStringStartsWith('https://read.example.com/app-updates/stop/', $url);
        $this->assertSame('https://read.example.com/app/android/whats-new', AndroidApp::pageUrl());

        $public = 'http://read.example.com' . substr($url, strlen('https://read.example.com'));
        $this->asNobody()->post($public)->assertOk()->assertSee('Done');
        $this->assertNotNull($sam->fresh()->app_update_emails_off_at);
    }

    public function test_account_switches_app_update_emails_off_and_on(): void
    {
        $this->enableSignIn();
        $sam = $this->androidReader('sam');
        $this->asSignedIn($sam);

        $this->get('/account')->assertOk()
            ->assertSee('Email me about app updates')
            ->assertSee('id="appUpdateEmailsState">On: to sam@example.test.', false)
            ->assertSee('Turn app update emails off');

        $this->post('/account/app-update-emails', ['on' => '0'])->assertRedirect(route('account') . '#app-update-emails');
        $this->assertNotNull($sam->fresh()->app_update_emails_off_at);
        $this->get('/account')->assertSee('Turn app update emails on')->assertSee('id="appUpdateEmailsState">Off.', false);

        $this->post('/account/app-update-emails', ['on' => '1'])->assertRedirect();
        $this->assertNull($sam->fresh()->app_update_emails_off_at);

        // The owner has the switch too.
        $owner = $this->ownerWithEmail();
        $this->asSignedIn($owner);
        $this->get('/account')->assertOk()->assertSee('Turn app update emails off');
        $this->post('/account/app-update-emails', ['on' => '0'])->assertRedirect();
        $this->assertNotNull($owner->fresh()->app_update_emails_off_at);

        // No email: no switch.
        $nomail = $this->androidReader('nomail', ['email' => null]);
        $this->asSignedIn($nomail);
        $this->get('/account')->assertOk()
            ->assertSee('id="appUpdateEmailsState">Not available: your account has no email.', false)
            ->assertDontSee('Turn app update emails');
    }
}
