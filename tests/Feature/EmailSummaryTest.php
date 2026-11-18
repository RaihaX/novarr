<?php

namespace Tests\Feature;

use App\Console\Commands\EmailSummary;
use App\Mail\NewChapters;
use App\Novel;
use App\NovelChapter;
use App\Setting;
use App\Sources\SuwayomiSource;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The daily summary email: window from summary_last_sent_at, send rules
 * (news vs. attention-only), one row per novel, and the plain-text part.
 */
class EmailSummaryTest extends TestCase
{
    use RefreshDatabase;

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

    /** A chapter row; $downloadedAgo null = still pending. */
    private function chapter(Novel $novel, float $n, ?Carbon $downloadedAt, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create(array_merge([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://www.novelfull.test/{$novel->id}/chapter-{$n}",
        ], $attrs));
        if ($downloadedAt) {
            $chapter->forceFill([
                'description' => '<p>' . trim(str_repeat('word ', 50)) . '</p>',
                'status' => 1,
                'download_date' => $downloadedAt,
            ])->save();
        }

        return $chapter;
    }

    private function seedAttention(array $items): void
    {
        Cache::put('dashboard_attention', $items, 900);
    }

    /**
     * Three novels: Alpha (4 new incl. a note, chapter 1 read), Beta (2 new),
     * Gamma (only old downloads); one completed novel; one attention item.
     */
    private function seedLibrary(): array
    {
        Setting::put('summary_email', 'reader@example.test');

        $alpha = $this->novel('Alpha Saga');
        $old = $this->chapter($alpha, 1, now()->subDays(3));
        $old->forceFill(['read_at' => now()->subDays(2), 'read_progress' => 100])->save();
        $a2 = $this->chapter($alpha, 2, now()->subDays(3));
        $a2->forceFill(['read_at' => now()->subHour(), 'read_progress' => 40])->save();
        $a3 = $this->chapter($alpha, 3, now()->subHours(2));
        $this->chapter($alpha, 4, now()->subHours(2), ['kind' => NovelChapter::KIND_NOTE]);
        $this->chapter($alpha, 5, now()->subHours(1));
        $this->chapter($alpha, 6, now()->subHours(1));

        $beta = $this->novel('Beta Chronicle');
        $b1 = $this->chapter($beta, 10, now()->subHours(3));
        $this->chapter($beta, 11, now()->subHours(3));

        $gamma = $this->novel('Gamma Outside');
        $this->chapter($gamma, 1, now()->subDays(5));
        $this->chapter($gamma, 2, null); // queued

        $done = $this->novel('Delta Finished', ['status' => 1]);
        $done->forceFill(['completed_at' => now()->subHours(5)])->save();

        $this->seedAttention([[
            'id' => $gamma->id,
            'name' => 'Gamma Outside',
            'reason' => 'TOC sync failed on 3 consecutive runs — the source returned 0 chapters',
            'url' => 'https://www.novelfull.test/gamma-outside',
        ]]);

        return compact('alpha', 'beta', 'gamma', 'done', 'a2', 'a3', 'b1');
    }

    public function test_daily_summary_renders_one_row_per_novel_with_links(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();

        $this->artisan('novel:email-summary')->assertSuccessful();

        Mail::assertSent(NewChapters::class, 1);
        Mail::assertSent(NewChapters::class, function (NewChapters $mail) use ($s) {
            $html = $mail->render();
            $mail->assertHasSubject('Novarr · 6 new chapters in 2 novels · 1 completed · 1 needs attention');

            // One row per novel, not per chapter.
            $this->assertSame(2, substr_count($html, 'data-novel-row='));
            $this->assertStringContainsString('data-novel-row="' . $s['alpha']->id . '"', $html);
            $this->assertStringNotContainsString('data-novel-row="' . $s['gamma']->id . '"', $html);
            $this->assertStringNotContainsString('Chapter 5</td>', $html);

            // Alpha (4 new) sorts above Beta (2 new); range and count in mono.
            $this->assertLessThan(strpos($html, 'Beta Chronicle'), strpos($html, 'Alpha Saga'));
            $this->assertStringContainsString('Chapters 3 to 6', $html);
            $this->assertStringContainsString('4 new', $html);
            $this->assertStringContainsString('novelfull.test', $html);

            // Read from the first unread downloaded chapter (Alpha 3, Beta 10).
            $this->assertStringContainsString('href="' . route('chapters.show', $s['a3']->id) . '"', $html);
            $this->assertStringContainsString('Read from 3', $html);
            $this->assertStringContainsString('href="' . route('chapters.show', $s['b1']->id) . '"', $html);

            // The note chip appears once (Alpha only).
            $this->assertSame(1, substr_count($html, "Author's note") + substr_count($html, 'Author&#039;s note'));

            // Continue reading: Alpha chapter 2 is mid-chapter → Resume there.
            $this->assertStringContainsString('Continue reading', $html);
            $this->assertStringContainsString('href="' . route('chapters.show', $s['a2']->id) . '"', $html);
            $this->assertStringContainsString('Resume', $html);
            $this->assertStringContainsString('CHAPTER 2 OF 6 · 40% OF CHAPTER', $html);

            // Serial mark: four 3px bars, the last amber and 8 wide.
            $this->assertSame(4, substr_count($html, 'height="3" bgcolor="#'));
            $this->assertStringContainsString('width="8" height="3" bgcolor="#F0B429"', $html);

            // Completed + attention sections.
            $this->assertStringContainsString(route('novels.download_epub', $s['done']->id), $html);
            $this->assertStringContainsString('Sent to Kindle', $html);
            $this->assertStringContainsString('TOC sync failed on 3 consecutive runs', $html);
            $this->assertStringContainsString(route('health.index') . '#attentionPanel', $html);
            $this->assertStringContainsString(route('activity.index'), $html);

            // Plain-text part carries the same sections with URLs.
            $mail->assertSeeInText('Alpha Saga');
            $mail->assertSeeInText('Beta Chronicle');
            $mail->assertSeeInText('Delta Finished');
            $mail->assertSeeInText(route('chapters.show', $s['a3']->id));
            $mail->assertSeeInText('Gamma Outside');

            return true;
        });

        $this->assertNotNull(Setting::get(EmailSummary::LAST_SENT_KEY));
        $this->assertNotSame('', (string) Setting::get(EmailSummary::ATTENTION_HASH_KEY, ''));
    }

    public function test_second_run_with_nothing_new_sends_nothing(): void
    {
        Mail::fake();
        $this->seedLibrary();

        $this->artisan('novel:email-summary')->assertSuccessful();
        Carbon::setTestNow(now()->addMinute());
        $this->artisan('novel:email-summary')->assertSuccessful();
        Carbon::setTestNow();

        Mail::assertSent(NewChapters::class, 1);
    }

    public function test_attention_only_sends_once_until_the_set_changes(): void
    {
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $novel = $this->novel('Stuck Novel');
        $item = ['id' => $novel->id, 'name' => 'Stuck Novel', 'reason' => '3 consecutive scrape runs failed', 'url' => null];
        $this->seedAttention([$item]);

        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertSent(NewChapters::class, fn($m) => $m->render() && $m->hasSubject('Novarr · 1 needs attention'));

        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertSent(NewChapters::class, 1);

        // A new reason is a new problem: send again.
        $this->seedAttention([array_merge($item, ['reason' => '4 consecutive scrape runs failed'])]);
        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertSent(NewChapters::class, 2);
    }

    public function test_window_comes_from_last_sent_and_hours_overrides_it(): void
    {
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $this->seedAttention([]);
        $novel = $this->novel('Window Novel');
        $this->chapter($novel, 1, now()->subHours(30));

        // Last email 2 hours ago: the 30h-old chapter is outside the window.
        Setting::put(EmailSummary::LAST_SENT_KEY, now()->subHours(2)->toIso8601String());
        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertNothingSent();

        // An explicit --hours wins over the stored timestamp.
        $this->artisan('novel:email-summary', ['--hours' => 48])->assertSuccessful();
        Mail::assertSent(NewChapters::class, fn($m) => $m->render() && $m->hasSubject('Novarr · 1 new chapter in 1 novel'));
    }

    public function test_default_window_is_24_hours_without_a_stored_timestamp(): void
    {
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $this->seedAttention([]);
        $this->chapter($this->novel('Old Novel'), 1, now()->subHours(30));

        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_legacy_flat_payload_still_renders(): void
    {
        $mail = new NewChapters([
            ['novel' => 'Legacy Novel', 'label' => 'Chapter 7', 'chapter' => 7, 'book' => 0, 'progress' => '70.00'],
            ['novel' => 'Legacy Novel', 'label' => 'Chapter 8', 'chapter' => 8, 'book' => 0, 'progress' => '80.00'],
        ]);

        $html = $mail->render();
        $mail->assertHasSubject('Novarr · 2 new chapters in 1 novel');
        $this->assertStringContainsString('Legacy Novel', $html);
        $this->assertStringContainsString('Chapters 7 to 8', $html);
        $this->assertSame(1, substr_count($html, 'data-novel-row='));
        $mail->assertSeeInText('Legacy Novel');
    }

    public function test_novel_rows_are_capped_with_a_link_to_activity(): void
    {
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $this->seedAttention([]);
        foreach (range(1, 27) as $i) {
            $this->chapter($this->novel("Capped {$i}"), 1, now()->subHour());
        }

        $this->artisan('novel:email-summary')->assertSuccessful();

        Mail::assertSent(NewChapters::class, function (NewChapters $mail) {
            $html = $mail->render();
            $this->assertSame(25, substr_count($html, 'data-novel-row='));
            $this->assertStringContainsString('+2 more novels on Activity', $html);
            $mail->assertHasSubject('Novarr · 27 new chapters in 27 novels');

            return true;
        });
    }

    /** A Suwayomi manga on source $sourceRef (the server URL is set for the test; no request is made). */
    private function manga(string $name, int $mangaId, string $sourceRef = '1234567890'): Novel
    {
        config(['novarr.suwayomi.url' => 'http://suwayomi.test:4567']);
        $novel = $this->novel($name, [
            'translator_url' => SuwayomiSource::seriesUrl($mangaId),
            'source_ref' => $sourceRef,
        ]);
        $novel->forceFill(['medium' => Novel::MEDIUM_MANGA])->save();

        return $novel;
    }

    public function test_manga_get_their_own_section_and_figures(): void
    {
        Mail::fake();
        $s = $this->seedLibrary();
        $one = $this->manga('One Punch Scroll', 10);
        foreach ([20, 21, 22] as $n) {
            $this->chapter($one, $n, now()->subHour());
        }
        $two = $this->manga('Two Blade Comic', 11);
        $this->chapter($two, 5, now()->subHour());

        $this->artisan('novel:email-summary')->assertSuccessful();

        Mail::assertSent(NewChapters::class, function (NewChapters $mail) use ($s, $one, $two) {
            $mail->assertHasSubject('Novarr · 10 new chapters in 2 novels and 2 manga · 1 completed · 1 needs attention');

            $view = $mail->viewData();
            $this->assertSame(['Alpha Saga', 'Beta Chronicle'], array_column($view['novels'], 'name'));
            $this->assertSame(['One Punch Scroll', 'Two Blade Comic'], array_column($view['manga'], 'name'));
            $this->assertSame(['manga', 'manga'], array_column($view['manga'], 'medium'));
            $this->assertSame(0, $view['more_manga']);
            $this->assertSame(10, $view['stats']['new_chapters']);
            $this->assertSame(2, $view['stats']['novels_updated']);
            $this->assertSame(2, $view['stats']['manga_updated']);
            $this->assertSame(4, $view['stats']['manga_chapters']);

            // The lane label names the source, not the server's host; no "Manga" origin tag.
            $this->assertSame('Suwayomi · #1234567890', $view['manga'][0]['source']);
            $this->assertNull($view['manga'][0]['origin']);
            // Manga rows are enriched too.
            $this->assertSame(0, $view['manga'][0]['percent']);
            $this->assertSame(20.0, $view['manga'][0]['read_from']);

            $html = $mail->render();
            $this->assertSame(4, substr_count($html, 'data-novel-row='));
            $this->assertStringContainsString('6 IN 2 NOVELS', $html);
            $this->assertStringContainsString('4 IN 2 MANGA', $html);
            $this->assertStringContainsString('Manga updated', $html);
            $this->assertStringContainsString('Suwayomi · #1234567890', $html);
            $this->assertStringNotContainsString('suwayomi.test', $html);
            $manga = strpos($html, '>Manga</td>');
            $this->assertNotFalse($manga);
            $this->assertLessThan($manga, strpos($html, 'data-novel-row="' . $s['beta']->id . '"'));
            $this->assertGreaterThan($manga, strpos($html, 'data-novel-row="' . $one->id . '"'));
            $this->assertGreaterThan($manga, strpos($html, 'data-novel-row="' . $two->id . '"'));

            $mail->assertSeeInText('Manga updated: 2');
            $mail->assertSeeInText("MANGA\n\n- One Punch Scroll");

            return true;
        });
    }

    public function test_a_manga_only_day_is_news(): void
    {
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $this->seedAttention([]);
        $this->chapter($this->manga('Solo Manga', 12), 1, now()->subHour());

        $this->artisan('novel:email-summary')->assertSuccessful();

        Mail::assertSent(NewChapters::class, function (NewChapters $mail) {
            $mail->assertHasSubject('Novarr · 1 new chapter in 1 manga');
            $html = $mail->render();
            $this->assertStringNotContainsString('New chapters</td>', $html);
            $this->assertStringContainsString('>Manga</td>', $html);
            $this->assertStringContainsString('1 IN 1 MANGA', $html);
            $this->assertStringNotContainsString('Nothing new in this period', $html);
            $mail->assertDontSeeInText('NEW CHAPTERS');
            $mail->assertSeeInText('Solo Manga');

            return true;
        });
    }

    public function test_manga_rows_without_stats_are_split_and_counted(): void
    {
        $row = fn(string $name, ?string $medium, int $count) => array_filter([
            'id' => null, 'name' => $name, 'url' => null, 'source' => null, 'medium' => $medium,
            'count' => $count, 'notes' => 0, 'first' => 1.0, 'last' => (float) $count,
            'percent' => null, 'read_from' => null, 'read_from_url' => null,
        ], fn($v, $k) => $k !== 'medium' || $v !== null, ARRAY_FILTER_USE_BOTH);

        $mail = new NewChapters([
            'since' => now()->subDay(), 'chapters' => [], 'completed' => [], 'attention' => [],
            'novels' => [$row('Old Caller Novel', null, 3), $row('Some Manga', 'manga', 2)],
        ]);

        $view = $mail->viewData();
        $this->assertSame(['Old Caller Novel'], array_column($view['novels'], 'name'));
        $this->assertSame(['Some Manga'], array_column($view['manga'], 'name'));
        $this->assertSame(1, $view['stats']['novels_updated']);
        $this->assertSame(1, $view['stats']['manga_updated']);
        $this->assertSame(2, $view['stats']['manga_chapters']);
        $mail->render();
        $mail->assertHasSubject('Novarr · 5 new chapters in 1 novel and 1 manga');
    }

    // ---- Stalled sources -----------------------------------------------------

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A novel on $host whose $count pending chapters have been due for $hours hours. */
    private function stalledNovel(string $name, string $host, int $count, int $hours = 7): Novel
    {
        $novel = $this->novel($name, ['translator_url' => "https://{$host}/" . \Illuminate\Support\Str::slug($name)]);
        for ($n = 1; $n <= $count; $n++) {
            $this->chapter($novel, $n, null, ['url' => "https://{$host}/{$novel->id}/chapter-{$n}"])
                ->forceFill(['created_at' => now()->subHours($hours)])->saveQuietly();
        }

        return $novel;
    }

    /** Swap in the command with its webhook call recorded instead of sent. */
    private function recordWebhook(): object
    {
        $command = new class extends EmailSummary {
            public array $messages = [];

            protected function sendWebhook(string $message): void
            {
                $this->messages[] = $message;
            }
        };
        $this->app[Kernel::class]->registerCommand($command);

        return $command;
    }

    public function test_a_stalled_source_alone_is_news_once_until_another_stalls(): void
    {
        Carbon::setTestNow('2026-11-17 08:00:00');
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $this->seedAttention([]);
        $this->stalledNovel('Stuck One', 'stuck.test', 3);

        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertSent(NewChapters::class, function (NewChapters $mail) {
            $mail->assertHasSubject('Novarr · 1 source not downloading');
            $this->assertSame([['lane' => 'stuck.test', 'label' => 'stuck.test', 'queued' => 3, 'series' => 1, 'hours' => 7]], $mail->viewData()['stalled']);

            $html = $mail->render();
            $this->assertStringContainsString('Sources not downloading', $html);
            $this->assertStringContainsString('stuck.test</span><span style="color: #D8DDE6;"> — 3 chapters waiting, nothing downloaded for 7 hours', $html);
            $this->assertStringContainsString('href="' . route('activity.index') . '"', $html);
            $this->assertStringContainsString('View activity', $html);
            $this->assertStringContainsString('1 source not downloading', $html, 'preheader');
            $this->assertStringNotContainsString('Nothing new in this period', $html);
            $this->assertStringNotContainsString('Needs attention</td>', $html);

            $mail->assertSeeInText('SOURCES NOT DOWNLOADING');
            $mail->assertSeeInText('- stuck.test — 3 chapters waiting, nothing downloaded for 7 hours');
            $mail->assertSeeInText('View activity: ' . route('activity.index'));

            return true;
        });

        // Unchanged next day (no other news): not sent again.
        Carbon::setTestNow('2026-11-18 08:00:00');
        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertSent(NewChapters::class, 1);

        // Another lane stalls: news again, both listed, most waiting first.
        $this->stalledNovel('Stuck Two', 'other.test', 5);
        Carbon::setTestNow('2026-11-18 09:00:00');
        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertSent(NewChapters::class, 2);
        Mail::assertSent(NewChapters::class, fn($m) => $m->render() && $m->hasSubject('Novarr · 2 sources not downloading')
            && array_column($m->viewData()['stalled'], 'lane') === ['other.test', 'stuck.test']);
    }

    public function test_the_stalled_block_shows_singular_and_plural_wording(): void
    {
        $mail = new NewChapters([
            'since' => now()->subDay(), 'chapters' => [], 'completed' => [], 'attention' => [], 'novels' => [],
            'stalled' => [
                ['lane' => 'one.test', 'label' => 'one.test', 'queued' => 1, 'series' => 1, 'hours' => 1],
                ['lane' => 'many.test', 'label' => 'many.test', 'queued' => 1200, 'series' => 2, 'hours' => 30],
            ],
        ]);

        $html = $mail->render();
        $mail->assertHasSubject('Novarr · 2 sources not downloading');
        $this->assertStringContainsString(' — 1 chapter waiting, nothing downloaded for 1 hour<', $html);
        $this->assertStringContainsString(' — 1,200 chapters waiting, nothing downloaded for 30 hours<', $html);
        $this->assertSame(2, substr_count($html, 'data-stalled-lane='));
        $mail->assertSeeInText("- one.test — 1 chapter waiting, nothing downloaded for 1 hour\n");
        $mail->assertSeeInText('- many.test — 1,200 chapters waiting, nothing downloaded for 30 hours');

        $one = new NewChapters(['since' => now()->subDay(), 'chapters' => [], 'completed' => [], 'attention' => [], 'novels' => [],
            'stalled' => [['lane' => 'one.test', 'label' => 'one.test', 'queued' => 2, 'series' => 1, 'hours' => 8]]]);
        $one->render();
        $one->assertHasSubject('Novarr · 1 source not downloading');
    }

    public function test_a_payload_without_stalled_still_renders(): void
    {
        $mail = new NewChapters(['since' => now()->subDay(), 'chapters' => [], 'completed' => [], 'attention' => [], 'novels' => []]);

        $html = $mail->render();
        $this->assertSame([], $mail->viewData()['stalled']);
        $this->assertSame(0, $mail->viewData()['stats']['stalled']);
        $mail->assertHasSubject('Novarr · Nothing new');
        $this->assertStringNotContainsString('Sources not downloading', $html);
        $this->assertStringContainsString('Nothing new in this period', $html);
        $mail->assertDontSeeInText('SOURCES NOT DOWNLOADING');
    }

    public function test_the_attention_only_hash_is_unchanged_without_stalled_sources(): void
    {
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $novel = $this->novel('Stuck Novel');
        $this->seedAttention([['id' => $novel->id, 'name' => 'Stuck Novel', 'reason' => 'TOC failed', 'url' => null]]);

        $this->artisan('novel:email-summary')->assertSuccessful();

        Mail::assertSent(NewChapters::class, 1);
        $this->assertSame(sha1("{$novel->id}:TOC failed"), (string) Setting::get(EmailSummary::ATTENTION_HASH_KEY));
    }

    public function test_the_webhook_hears_once_per_new_set_of_stalled_sources(): void
    {
        Carbon::setTestNow('2026-11-17 08:00:00');
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $this->seedAttention([]);
        $webhook = $this->recordWebhook();
        $stuck = $this->stalledNovel('Stuck One', 'stuck.test', 3);

        $this->artisan('novel:email-summary')->assertSuccessful();
        $this->assertSame(['Novarr: stuck.test has 3 chapters waiting and has downloaded nothing for 7 hours.'], $webhook->messages);
        $this->assertSame('stuck.test', (string) Setting::get(EmailSummary::STALLED_NOTIFIED_KEY));

        // Same set: quiet.
        Carbon::setTestNow('2026-11-17 09:00:00');
        $this->artisan('novel:email-summary')->assertSuccessful();
        $this->assertCount(1, $webhook->messages);

        // Recovers: the remembered set is cleared, nothing is sent.
        $this->chapter($stuck, 50, now()->subMinutes(5));
        $this->artisan('novel:email-summary')->assertSuccessful();
        $this->assertCount(1, $webhook->messages);
        $this->assertSame('', (string) Setting::get(EmailSummary::STALLED_NOTIFIED_KEY, ''));

        // Stalls again (no download for 6+ hours) along with a second lane: one message, one line each.
        $this->stalledNovel('Stuck Two', 'other.test', 1, 12);
        Carbon::setTestNow('2026-11-17 16:00:00');
        $this->artisan('novel:email-summary')->assertSuccessful();
        $this->assertCount(2, $webhook->messages);
        $this->assertSame(
            "Novarr: stuck.test has 3 chapters waiting and has downloaded nothing for 7 hours.\n"
            . 'Novarr: other.test has 1 chapter waiting and has downloaded nothing for 19 hours.',
            $webhook->messages[1]
        );
        $this->assertSame('other.test,stuck.test', (string) Setting::get(EmailSummary::STALLED_NOTIFIED_KEY));
    }

    public function test_the_webhook_runs_without_an_email_and_never_fails_the_command(): void
    {
        Carbon::setTestNow('2026-11-17 08:00:00');
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');
        $this->seedAttention([]);
        $this->stalledNovel('Stuck One', 'stuck.test', 3);
        // Already emailed about this set: no email, but the webhook is new.
        $webhook = $this->recordWebhook();
        Setting::put(EmailSummary::ATTENTION_HASH_KEY, sha1('lane:stuck.test'));

        $this->artisan('novel:email-summary')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertCount(1, $webhook->messages);

        // A failing webhook is logged, never fatal.
        $this->app[Kernel::class]->registerCommand(new class extends EmailSummary {
            protected function sendWebhook(string $message): void
            {
                throw new \RuntimeException('webhook down');
            }
        });
        Setting::put(EmailSummary::STALLED_NOTIFIED_KEY, '');
        $this->artisan('novel:email-summary')->assertSuccessful();
    }
}
