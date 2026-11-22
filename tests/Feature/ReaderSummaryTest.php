<?php

namespace Tests\Feature;

use App\Console\Commands\EmailSummary;
use App\Mail\NewChapters;
use App\Novel;
use App\NovelChapter;
use App\Setting;
use App\Support\AuthMode;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Each reader's own daily summary (novel:email-summary): who gets one, the
 * whole library's downloads and completions with their own
 * progress, the reader mode of the email, the per-reader window, the
 * --reader / --to options, turning it off in Account and with the signed
 * "Stop these emails" link.
 */
class ReaderSummaryTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const OWNER_TO = 'owner-summary@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        // Mail "configured" (the log mailer counts as not set up for readers).
        config(['mail.default' => 'smtp']);
        $this->enableSignIn();
        Setting::put('summary_email', self::OWNER_TO);
        Cache::put('dashboard_attention', [], 900);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----------------------------------------------------------------

    private function novel(string $name, array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Test Author',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
            'translator_url' => 'https://www.novelfull.test/' . \Illuminate\Support\Str::slug($name),
        ], $attrs));
    }

    private function chapter(Novel $novel, float $n, ?Carbon $downloadedAt): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://www.novelfull.test/{$novel->id}/chapter-{$n}",
        ]);
        if ($downloadedAt) {
            $chapter->forceFill([
                'description' => '<p>' . trim(str_repeat('word ', 50)) . '</p>',
                'status' => 1,
                'download_date' => $downloadedAt,
            ])->save();
        }

        return $chapter;
    }

    private function reader(string $username, ?string $email = null, array $attrs = []): User
    {
        $user = $this->makeReaderAccount($username, ucfirst($username));
        $user->forceFill(array_merge(['email' => $email ?? "{$username}@example.test"], $attrs))->save();

        return $user->fresh();
    }

    /**
     * Started (Sam read 1, is 25% into 2; the owner read all four, 70% into
     * the last); Listed and Other (Sam never opened either); DoneListed and
     * DoneOther completed today.
     */
    private function seedLibrary(): array
    {
        $sam = $this->reader('sam');
        $owner = $this->owner();

        $started = $this->novel('Started Saga');
        $s1 = $this->chapter($started, 1, now()->subDays(3));
        $s2 = $this->chapter($started, 2, now()->subDays(3));
        $s3 = $this->chapter($started, 3, now()->subHour());
        $s4 = $this->chapter($started, 4, now()->subHour());
        $this->markRead($s1, $sam, now()->subDays(2), 100);
        $this->markRead($s2, $sam, now()->subHour(), 25);
        $this->markRead($s1, $owner, now()->subDays(2), 100);
        $this->markRead($s2, $owner, now()->subDays(2), 100);
        $this->markRead($s3, $owner, now()->subMinutes(50), 100);
        $this->markRead($s4, $owner, now()->subMinutes(30), 70);

        $listed = $this->novel('Listed Tale');
        $l5 = $this->chapter($listed, 5, now()->subHours(2));

        $other = $this->novel('Other Epic');
        $this->chapter($other, 1, now()->subHour());
        $this->chapter($other, 2, now()->subHour());

        $doneListed = $this->novel('Done Listed', ['status' => 1]);
        $doneListed->forceFill(['completed_at' => now()->subHours(3)])->save();
        $doneOther = $this->novel('Done Other', ['status' => 1]);
        $doneOther->forceFill(['completed_at' => now()->subHours(3)])->save();

        return compact('sam', 'started', 'listed', 'other', 'doneListed', 'doneOther', 's2', 's4', 'l5');
    }

    /** Write summary_sent_at straight to the row (a stale model would skip an unchanged value). */
    private function setSent(User $user, $at): void
    {
        User::whereKey($user->id)->update(['summary_sent_at' => $at]);
    }

    private function sentTo(string $address): ?NewChapters
    {
        return Mail::sent(NewChapters::class, fn(NewChapters $m) => $m->hasTo($address))->first();
    }

    private function sentCount(string $address): int
    {
        return Mail::sent(NewChapters::class, fn(NewChapters $m) => $m->hasTo($address))->count();
    }

    private function textOf(NewChapters $mail): string
    {
        return view('emails.newchapters_text', ['v' => $mail->viewData(), 'data' => $mail->mailData])->render();
    }

    // ---- what a reader gets --------------------------------------------------------

    public function test_a_reader_gets_the_whole_library_with_their_own_progress(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();

        $this->artisan('novel:email-summary')
            ->expectsOutput('Reader summaries: 1 sent, 0 nothing new, 0 failed.')
            ->assertSuccessful();

        $mail = $this->sentTo('sam@example.test');
        $this->assertNotNull($mail, 'Sam gets a summary');
        $view = $mail->viewData();

        // Everything downloaded, read by Sam or not.
        $this->assertSame(['Other Epic', 'Started Saga', 'Listed Tale'], array_column($view['novels'], 'name'));
        $this->assertSame(5, $view['stats']['new_chapters']);
        // Sam's progress, not the owner's (owner: 4 of 4 read, from 4).
        $this->assertSame(50, $view['novels'][1]['percent']);
        $this->assertSame(2.0, $view['novels'][1]['read_from']);
        $this->assertSame(route('chapters.show', $s['s2']->id), $view['novels'][1]['read_from_url']);
        $this->assertSame(0, $view['novels'][2]['percent']);
        $this->assertSame(5.0, $view['novels'][2]['read_from']);
        // Every completion, no Kindle note, the ePub link kept.
        $this->assertSame(['Done Listed', 'Done Other'], array_column($view['completed'], 'name'));
        $this->assertFalse($view['completed'][0]['kindle']);
        $mail->assertHasSubject('Novarr · 5 new chapters in 3 novels · 2 completed');

        $html = $mail->render();
        $this->assertStringContainsString('data-novel-row="' . $s['started']->id . '"', $html);
        $this->assertStringContainsString('data-novel-row="' . $s['listed']->id . '"', $html);
        $this->assertStringContainsString('data-novel-row="' . $s['other']->id . '"', $html);
        $this->assertStringContainsString('Other Epic', $html);
        $this->assertStringContainsString('Done Other', $html);
        $this->assertStringNotContainsString('Sent to Kindle', $html);
        $this->assertStringContainsString(route('novels.download_epub', $s['doneListed']->id), $html);
        // The continue block is Sam's: chapter 2, 25 % in (the owner's is 4, 70 %).
        $this->assertStringContainsString('CHAPTER 2 OF 4 · 25% OF CHAPTER', $html);
        $this->assertStringContainsString('href="' . route('chapters.show', $s['s2']->id) . '"', $html);
        $this->assertSame(25, $view['continue']['chapter_percent']);

        // The owner's own email is unchanged: everything, the owner's progress.
        $owner = $this->sentTo(self::OWNER_TO);
        $this->assertNotNull($owner);
        $ownerView = $owner->viewData();
        $this->assertNull($ownerView['reader']);
        $this->assertContains('Other Epic', array_column($ownerView['novels'], 'name'));
        $started = collect($ownerView['novels'])->firstWhere('name', 'Started Saga');
        $this->assertSame(100, $started['percent']);
        $this->assertSame(70, $ownerView['continue']['chapter_percent']);
        $this->assertSame(['Done Listed', 'Done Other'], array_column($ownerView['completed'], 'name'));

        $this->assertNotNull($s['sam']->fresh()->summary_sent_at);
    }

    public function test_a_reader_who_has_started_nothing_gets_the_same_downloads_as_the_owner(): void
    {
        Mail::fake();
        $this->seedLibrary();
        $this->reader('new');

        $this->artisan('novel:email-summary')->assertSuccessful();

        $reader = $this->sentTo('new@example.test')->viewData();
        $owner = $this->sentTo(self::OWNER_TO)->viewData();
        foreach (['novels', 'completed'] as $section) {
            $this->assertSame(array_column($owner[$section], 'name'), array_column($reader[$section], 'name'));
        }
        $this->assertSame($owner['stats']['new_chapters'], $reader['stats']['new_chapters']);
        $this->assertSame([0, 0, 0], array_column($reader['novels'], 'percent'));
    }

    public function test_the_reader_email_has_no_admin_sections_figures_or_links(): void
    {
        Mail::fake();
        $this->seedLibrary();
        // An attention item and a stalled source: in the owner's email only.
        $gamma = $this->novel('Gamma Broken');
        Cache::put('dashboard_attention', [['id' => $gamma->id, 'name' => 'Gamma Broken', 'reason' => 'TOC sync failed', 'url' => 'https://x.test']], 900);

        $this->artisan('novel:email-summary')->assertSuccessful();

        $mail = $this->sentTo('sam@example.test');
        $html = $mail->render();
        $text = $this->textOf($mail);
        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('TOC sync failed', $part);
            $this->assertStringNotContainsString('Gamma Broken', $part);
            $this->assertStringNotContainsString('Needs attention', $part);
            $this->assertStringNotContainsString('Queued', $part);
            $this->assertStringNotContainsString('Sources not downloading', $part);
            $this->assertStringNotContainsString(route('activity.index'), $part);
            $this->assertStringNotContainsString(route('settings.index'), $part);
            $this->assertStringNotContainsString(route('health.index'), $part);
            $this->assertStringNotContainsString('change in Settings', $part);
            $this->assertStringContainsString('turn off in Account', $part);
            $this->assertStringContainsString(route('account'), $part);
            $this->assertStringContainsString('/summary/stop/', $part);
        }
        $this->assertStringContainsString('Stop these emails', $html);
        $this->assertStringContainsString('>Account</a>', $html);
        $this->assertStringContainsString('Open Novarr', $html);
        $this->assertStringNotContainsString('Novels updated', $html);
        $this->assertStringContainsString("Open Novarr: " . route('home'), $text);
        $this->assertStringContainsString('Account: ' . route('account'), $text);
        $this->assertStringContainsString('New chapters: 5 · Completed: 2', $text);
        $this->assertStringContainsString('Daily summary for Sam', $text);
        $mail->assertHasSubject('Novarr · 5 new chapters in 3 novels · 2 completed');

        // The owner's still has them.
        $owner = $this->sentTo(self::OWNER_TO)->render();
        $this->assertStringContainsString('TOC sync failed', $owner);
        $this->assertStringContainsString(route('activity.index'), $owner);
    }

    public function test_reader_links_use_the_public_address_when_one_is_set_up(): void
    {
        Mail::fake();
        config(['novarr.public_hosts' => ['read.example.com'], 'novarr.public_url' => null]);
        $s = $this->seedLibrary();

        $this->artisan('novel:email-summary')->assertSuccessful();

        $mail = $this->sentTo('sam@example.test');
        $view = $mail->viewData();
        $public = 'https://read.example.com';
        $this->assertSame($public . '/novels/' . $s['started']->id, $view['novels'][1]['url']);
        $this->assertSame($public . '/chapters/' . $s['s2']->id, $view['novels'][1]['read_from_url']);
        $this->assertSame($public . '/novels/' . $s['doneListed']->id . '/epub', $view['completed'][0]['epub_url']);
        $this->assertSame($public . '/chapters/' . $s['s2']->id, $view['continue']['url']);
        $this->assertSame($public . '/novels/' . $s['started']->id, $view['continue']['novel_url']);
        $this->assertSame($public . '/', $view['links']['home']);
        $this->assertSame($public . '/account', $view['links']['account']);
        $this->assertStringStartsWith($public . '/summary/stop/' . $s['sam']->id . '?signature=', $view['links']['unsubscribe']);

        $html = $mail->render();
        $this->assertStringNotContainsString('http://localhost', $html);
        $this->assertStringNotContainsString('http://localhost', $this->textOf($mail));

        // The owner's links stay on APP_URL.
        $owner = $this->sentTo(self::OWNER_TO)->viewData();
        $this->assertSame(route('home'), $owner['links']['home']);
        $this->assertStringStartsWith('http://localhost/', $owner['novels'][0]['url']);
    }

    public function test_without_a_public_address_reader_links_use_the_app_url(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();

        $this->artisan('novel:email-summary')->assertSuccessful();

        $view = $this->sentTo('sam@example.test')->viewData();
        $this->assertSame(route('novels.show', $s['started']->id), $view['novels'][1]['url']);
        $this->assertSame(route('home'), $view['links']['home']);
        $this->assertStringStartsWith(url('/summary/stop/' . $s['sam']->id) . '?signature=', $view['links']['unsubscribe']);
    }

    // ---- when it is sent ------------------------------------------------------------

    public function test_nothing_new_sends_nothing_and_moves_the_window_on(): void
    {
        Carbon::setTestNow('2026-11-20 08:00:00');
        Mail::fake();
        $sam = $this->reader('sam');
        $novel = $this->novel('Quiet Novel');
        $this->chapter($novel, 1, now()->subDays(3));

        $this->artisan('novel:email-summary')
            ->expectsOutput('Reader summaries: 0 sent, 1 nothing new, 0 failed.')
            ->assertSuccessful();

        $this->assertSame(0, $this->sentCount('sam@example.test'));
        $this->assertSame(0, $this->sentCount(self::OWNER_TO));
        $this->assertSame('2026-11-20 08:00:00', $sam->fresh()->summary_sent_at->toDateTimeString());
    }

    public function test_the_window_runs_from_summary_sent_at_else_24_hours_never_more_than_7_days(): void
    {
        Carbon::setTestNow('2026-11-20 08:00:00');
        Mail::fake();
        $sam = $this->reader('sam');
        $novel = $this->novel('Window Novel');
        $this->chapter($novel, 1, now()->subDays(8));
        $this->chapter($novel, 2, now()->subDays(6));
        $this->chapter($novel, 3, now()->subHours(30));
        $this->chapter($novel, 4, now()->subHours(3));

        // Sent 2 hours ago: chapter 4 (3 hours old) is outside.
        $this->setSent($sam, now()->subHours(2));
        $this->artisan('novel:email-summary', ['--hours' => null])->assertSuccessful();
        $this->assertSame(0, $this->sentCount('sam@example.test'));

        // Never sent: the last 24 hours (chapter 4 only).
        $this->setSent($sam, null);
        $this->artisan('novel:email-summary')->assertSuccessful();
        $mail = $this->sentTo('sam@example.test');
        $this->assertSame(1, $mail->viewData()['stats']['new_chapters']);
        $this->assertSame('2026-11-19 08:00:00', Carbon::parse($mail->viewData()['since'])->toDateTimeString());

        // Last sent 10 days ago: 7 days back at most (chapters 2, 3, 4).
        $this->setSent($sam, now()->subDays(10));
        $this->artisan('novel:email-summary')->assertSuccessful();
        $mail = Mail::sent(NewChapters::class, fn($m) => $m->hasTo('sam@example.test'))->last();
        $this->assertSame(3, $mail->viewData()['stats']['new_chapters']);
        $this->assertSame(2.0, $mail->viewData()['novels'][0]['first']);
        $this->assertSame('2026-11-13 08:00:00', Carbon::parse($mail->viewData()['since'])->toDateTimeString());

        // --hours wins, as for the owner (all four).
        $this->setSent($sam, now()->subHour());
        $this->artisan('novel:email-summary', ['--hours' => 24 * 9])->assertSuccessful();
        $mail = Mail::sent(NewChapters::class, fn($m) => $m->hasTo('sam@example.test'))->last();
        $this->assertSame(4, $mail->viewData()['stats']['new_chapters']);
    }

    public function test_only_eligible_readers_get_one(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $owner->forceFill(['email' => 'owner@example.test'])->save();
        $novel = $this->novel('Everyone Reads');
        $this->chapter($novel, 1, now()->subHour());

        $ok = $this->reader('okay');
        $disabled = $this->reader('gone', null, ['disabled_at' => now()]);
        $pending = $this->reader('pending', null, ['password_set_at' => null]);
        $noEmail = $this->reader('nomail', '');
        $placeholder = $this->reader('placeholder', 'placeholder@example.invalid');
        $off = $this->reader('quiet', null, ['summary_email_off_at' => now()]);

        $this->artisan('novel:email-summary')
            ->expectsOutput('Reader summaries: 1 sent, 0 nothing new, 0 failed.')
            ->assertSuccessful();
        $this->assertSame(1, $this->sentCount('okay@example.test'));
        $this->assertSame(0, $this->sentCount('owner@example.test'));
        Mail::assertSent(NewChapters::class, 2); // the owner's summary + okay's

        // The owner's switch in Settings: nobody.
        Setting::put('summary_readers', '0');
        $this->setSent($ok, null);
        $this->artisan('novel:email-summary', ['--hours' => 2])
            ->expectsOutput('Reader summaries: switched off in Settings.')
            ->assertSuccessful();
        $this->assertSame(1, $this->sentCount('okay@example.test'));

        // Sign-in off: nobody either.
        Setting::put('summary_readers', '1');
        AuthMode::store(false);
        $this->artisan('novel:email-summary', ['--hours' => 2])
            ->expectsOutput('Reader summaries: none (sign-in is not required).')
            ->assertSuccessful();
        $this->assertSame(1, $this->sentCount('okay@example.test'));

        // Mail not set up: no reader has a usable address.
        AuthMode::store(true);
        config(['mail.default' => 'log']);
        $this->artisan('novel:email-summary', ['--hours' => 2])
            ->expectsOutput('Reader summaries: 0 sent, 0 nothing new, 0 failed.')
            ->assertSuccessful();
        $this->assertSame(1, $this->sentCount('okay@example.test'));
    }

    public function test_the_owners_early_returns_do_not_stop_the_readers(): void
    {
        Mail::fake();
        $sam = $this->reader('sam');
        $novel = $this->novel('Sams Novel');
        $this->chapter($novel, 1, now()->subHour());

        // No owner recipient: the command fails as before, Sam still gets his.
        Setting::put('summary_email', '');
        config(['mail.summary_email' => null]);
        $this->artisan('novel:email-summary')
            ->expectsOutput('No recipient configured. Set MAIL_SUMMARY_EMAIL or pass --to.')
            ->assertExitCode(1);
        $this->assertSame(1, $this->sentCount('sam@example.test'));

        // Nothing new for the owner (sent a minute ago): Sam's window is his own.
        Setting::put('summary_email', self::OWNER_TO);
        Setting::put(EmailSummary::LAST_SENT_KEY, now()->subMinute()->toIso8601String());
        $this->setSent($sam, null);
        $this->artisan('novel:email-summary')->assertSuccessful();
        $this->assertSame(0, $this->sentCount(self::OWNER_TO));
        $this->assertSame(2, $this->sentCount('sam@example.test'));

        // Only unchanged attention items for the owner.
        $item = ['id' => $novel->id, 'name' => 'Sams Novel', 'reason' => 'TOC failed', 'url' => null];
        Cache::put('dashboard_attention', [$item], 900);
        Setting::put(EmailSummary::ATTENTION_HASH_KEY, sha1("{$novel->id}:TOC failed"));
        $this->setSent($sam, null);
        $this->artisan('novel:email-summary')
            ->expectsOutputToContain('Only unchanged attention items')
            ->assertSuccessful();
        $this->assertSame(0, $this->sentCount(self::OWNER_TO));
        $this->assertSame(3, $this->sentCount('sam@example.test'));
    }

    public function test_a_failing_reader_mail_is_logged_and_the_next_reader_still_gets_theirs(): void
    {
        // A real mailer (the array transport) so a send can fail.
        config(['mail.default' => 'array']);
        Log::spy();
        $ann = $this->reader('ann');
        $ben = $this->reader('ben');
        $novel = $this->novel('Shared Novel');
        $this->chapter($novel, 1, now()->subHour());
        Event::listen(MessageSending::class, function (MessageSending $event) {
            foreach ($event->message->getTo() as $address) {
                if ($address->getAddress() === 'ann@example.test') {
                    throw new \RuntimeException('mailbox unavailable');
                }
            }
        });

        $this->artisan('novel:email-summary')
            ->expectsOutput('Reader summaries: 1 sent, 0 nothing new, 1 failed.')
            ->assertSuccessful();

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())
            ->flatMap(fn($m) => array_map(fn($a) => $a->getAddress(), $m->getEnvelope()->getRecipients()))
            ->all();
        $this->assertContains('ben@example.test', $sent);
        $this->assertContains(self::OWNER_TO, $sent);
        $this->assertNotContains('ann@example.test', $sent);
        $this->assertNull($ann->fresh()->summary_sent_at);
        $this->assertNotNull($ben->fresh()->summary_sent_at);
        Log::shouldHaveReceived('error')->withArgs(fn($message) => str_contains($message, 'ann@example.test') && str_contains($message, 'mailbox unavailable'))->once();
    }

    public function test_to_alone_sends_only_the_owners_summary(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();

        $this->artisan('novel:email-summary', ['--to' => 'elsewhere@example.test'])->assertSuccessful();

        Mail::assertSent(NewChapters::class, 1);
        $this->assertSame(1, $this->sentCount('elsewhere@example.test'));
        $this->assertNull($s['sam']->fresh()->summary_sent_at);
    }

    public function test_stalled_only_sends_no_reader_mail(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();

        $this->artisan('novel:email-summary --stalled-only')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($s['sam']->fresh()->summary_sent_at);
    }

    public function test_reader_option_sends_only_that_readers_summary_to_their_address(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();
        $kim = $this->reader('kim');
        // Ignores the opt-out and the Settings switch: an explicit admin run.
        $s['sam']->forceFill(['summary_email_off_at' => now()])->save();
        Setting::put('summary_readers', '0');

        $this->artisan('novel:email-summary', ['--reader' => 'SAM'])
            ->expectsOutput('Reader summaries: 1 sent, 0 nothing new, 0 failed.')
            ->assertSuccessful();

        Mail::assertSent(NewChapters::class, 1);
        $this->assertSame(1, $this->sentCount('sam@example.test'));
        $this->assertNotNull($s['sam']->fresh()->summary_sent_at);
        $this->assertNull($kim->fresh()->summary_sent_at);
        $this->assertNull(Setting::get(EmailSummary::LAST_SENT_KEY));

        // Nothing new now: nothing sent, the window still moves on.
        Carbon::setTestNow(now()->addMinute());
        $this->artisan('novel:email-summary', ['--reader' => 'sam'])
            ->expectsOutput('Reader summaries: 0 sent, 1 nothing new, 0 failed.')
            ->assertSuccessful();
        $this->assertSame(1, $this->sentCount('sam@example.test'));
        $this->assertSame(now()->toDateTimeString(), $s['sam']->fresh()->summary_sent_at->toDateTimeString());

        // Unknown, the owner, or no address: an error.
        $this->artisan('novel:email-summary', ['--reader' => 'nobody'])->assertExitCode(1);
        $this->artisan('novel:email-summary', ['--reader' => $this->owner()->username])->assertExitCode(1);
        $this->reader('blank', '');
        $this->artisan('novel:email-summary', ['--reader' => 'blank'])->assertExitCode(1);
        Mail::assertSent(NewChapters::class, 1);
    }

    public function test_reader_option_with_to_is_a_preview(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();

        $this->artisan('novel:email-summary', ['--reader' => 'sam', '--to' => 'admin@example.test'])->assertSuccessful();
        $mail = $this->sentTo('admin@example.test');
        $this->assertNotNull($mail);
        $this->assertSame('Sam', $mail->viewData()['reader']['name']);
        $this->assertSame(['Other Epic', 'Started Saga', 'Listed Tale'], array_column($mail->viewData()['novels'], 'name'));
        $this->assertSame(0, $this->sentCount('sam@example.test'));
        $this->assertNull($s['sam']->fresh()->summary_sent_at);

        // Sent even when nothing is new, with the line that says so.
        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('novel:email-summary', ['--reader' => 'sam', '--to' => 'admin@example.test', '--hours' => 1])->assertSuccessful();
        $this->assertSame(2, $this->sentCount('admin@example.test'));
        $quiet = Mail::sent(NewChapters::class, fn($m) => $m->hasTo('admin@example.test'))->last();
        $quiet->assertHasSubject('Novarr · Nothing new');
        $this->assertStringContainsString('Nothing new in this period', $quiet->render());
        $this->assertStringContainsString('Nothing new in this period', $this->textOf($quiet));
        // Sam's continue block is still there.
        $this->assertStringContainsString('CHAPTER 2 OF 4 · 25% OF CHAPTER', $quiet->render());
        $this->assertNull($s['sam']->fresh()->summary_sent_at);
    }

    // ---- turning it off ----------------------------------------------------------

    public function test_a_reader_turns_it_off_and_on_in_account(): void
    {
        $sam = $this->reader('sam');
        $this->asSignedIn($sam);

        $this->get('/account')->assertOk()
            ->assertSee('Daily summary email')
            ->assertSee('New chapters and completed series across the library, once a day.')
            ->assertSee('On: daily at 08:00 to sam@example.test.')
            ->assertSee('Turn the daily summary off');

        $this->post('/account/summary-email', ['on' => '0'])->assertRedirect(route('account') . '#summary-email');
        $this->assertNotNull($sam->fresh()->summary_email_off_at);
        $this->get('/account')->assertSee('Turn the daily summary on')->assertSee('id="summaryEmailState">Off.', false);

        $this->post('/account/summary-email', ['on' => '1'])->assertRedirect();
        $this->assertNull($sam->fresh()->summary_email_off_at);
    }

    public function test_the_account_block_without_an_email_when_switched_off_and_for_the_owner(): void
    {
        $nomail = $this->reader('nomail', '');
        $this->asSignedIn($nomail);
        $this->get('/account')->assertOk()
            ->assertSee('Not available: your account has no email. Ask the person who runs this Novarr to add one.')
            ->assertDontSee('Turn the daily summary');

        Setting::put('summary_readers', '0');
        $sam = $this->reader('sam');
        $this->asSignedIn($sam);
        $this->get('/account')->assertOk()
            ->assertSee('Switched off for this server')
            ->assertDontSee('Turn the daily summary')
            ->assertDontSee(route('account.summary_email'));

        // The owner's is in Settings: nothing here, and the switch refuses.
        $this->asSignedIn($this->owner());
        $this->get('/account')->assertOk()->assertDontSee('Daily summary email');
        $this->post('/account/summary-email', ['on' => '0'])->assertRedirect(route('account'));
        $this->assertNull($this->owner()->summary_email_off_at);
    }

    public function test_the_stop_link_asks_first_and_the_button_turns_it_off(): void
    {
        $sam = $this->reader('sam');
        $url = EmailSummary::unsubscribeUrl($sam);
        $path = substr($url, strlen(url('/')));

        // Opening the link (a mail scanner, the reader) changes nothing.
        $this->asNobody()->get($url)->assertOk()
            ->assertSee('Stop the daily summary')
            ->assertSee('Sam')
            ->assertSee('action="' . e($path) . '"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNull($sam->fresh()->summary_email_off_at);

        $this->asNobody()->post($url)->assertOk()->assertSee('Done')->assertSee('switched back on in Account');
        $this->assertNotNull($sam->fresh()->summary_email_off_at);

        // Turned off: the run skips Sam. (A console run reads as the owner:
        // drop the guest the last request bound in the test container.)
        $this->app->forgetInstance(\App\Support\CurrentReader::class);
        Mail::fake();
        $novel = $this->novel('After Stop');
        $this->chapter($novel, 1, now()->subHour());
        $this->artisan('novel:email-summary')->assertSuccessful();
        $this->assertSame(0, $this->sentCount('sam@example.test'));
    }

    public function test_a_bad_signature_is_refused(): void
    {
        $sam = $this->reader('sam');
        $kim = $this->reader('kim');
        $url = EmailSummary::unsubscribeUrl($sam);

        $this->asNobody()->get($url . 'x')->assertStatus(403);
        $this->asNobody()->post($url . 'x')->assertStatus(403);
        // Sam's signature does not work for Kim.
        $this->asNobody()->post(str_replace('/summary/stop/' . $sam->id, '/summary/stop/' . $kim->id, $url))->assertStatus(403);
        $this->asNobody()->get(url('/summary/stop/' . $sam->id))->assertStatus(403);
        $this->assertNull($sam->fresh()->summary_email_off_at);
        $this->assertNull($kim->fresh()->summary_email_off_at);
    }

    public function test_the_stop_link_works_on_the_public_address(): void
    {
        config(['novarr.public_hosts' => ['read.example.com'], 'novarr.public_url' => null]);
        $sam = $this->reader('sam');
        // Made in the console (APP_URL), opened on the public host.
        $url = EmailSummary::unsubscribeUrl($sam);
        $this->assertStringStartsWith('https://read.example.com/summary/stop/', $url);
        $public = 'http://read.example.com' . substr($url, strlen('https://read.example.com'));

        $page = $this->asNobody()->get($public);
        $page->assertOk()->assertSee('Stop the daily summary')->assertHeader('X-Robots-Tag');
        $this->assertNull($sam->fresh()->summary_email_off_at);

        $this->asNobody()->post($public)->assertOk()->assertSee('Done');
        $this->assertNotNull($sam->fresh()->summary_email_off_at);

        // With sign-in off too (the link outlives the switch).
        AuthMode::store(false);
        $sam->forceFill(['summary_email_off_at' => null])->save();
        $this->asNobody()->post($public)->assertOk();
        $this->assertNotNull($sam->fresh()->summary_email_off_at);
    }

    // ---- the owner's switch ----------------------------------------------------------

    public function test_settings_has_the_reader_switch_on_by_default(): void
    {
        AuthMode::store(false);
        $this->get('/settings')->assertOk()
            ->assertSee('Send readers their daily summary')
            ->assertSee('Readers can turn it off in Account.');

        $this->post('/settings', ['summary_time' => '08:00'])->assertRedirect();
        $this->assertSame('0', (string) Setting::get('summary_readers'));
        $this->post('/settings', ['summary_time' => '08:00', 'summary_readers' => '1'])->assertRedirect();
        $this->assertSame('1', (string) Setting::get('summary_readers'));
    }
}
