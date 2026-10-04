<?php

namespace Tests\Feature;

use App\Console\Commands\EmailSummary;
use App\Mail\NewChapters;
use App\Novel;
use App\NovelChapter;
use App\Setting;
use Carbon\Carbon;
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
}
