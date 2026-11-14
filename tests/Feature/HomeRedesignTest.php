<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Round 4 "Now reading" home: hero + empty state, Continue shelf, new
 * chapters grouped by novel, the status strip, and the ops tables moved to
 * /activity.
 */
class HomeRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function novel(array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => 'Home Novel',
            'author' => 'Test Author',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
            'translator_url' => 'https://www.novelfull.test/home-novel',
        ], $attrs));
    }

    private function chapter(Novel $novel, int $n, bool $downloaded = true, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create(array_merge([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://www.novelfull.test/home-novel/chapter-{$n}",
        ], $attrs));
        if ($downloaded) {
            // 460 words → 2 minutes at 230 wpm.
            $chapter->description = '<p>' . trim(str_repeat('word ', 460)) . '</p>';
            $chapter->status = 1;
            $chapter->download_date = now();
            $chapter->save();
        }

        return $chapter;
    }

    /** Backdate a row's created_at (outside the new-chapters window). */
    private function age(NovelChapter $chapter, int $days): void
    {
        NovelChapter::whereKey($chapter->id)->update(['created_at' => now()->subDays($days)]);
    }

    public function test_hero_renders_the_in_progress_novel_with_a_resume_link(): void
    {
        $novel = $this->novel(['name' => 'The Silent Sword Saint']);
        $c1 = $this->chapter($novel, 1);
        $c2 = $this->chapter($novel, 2);
        $this->chapter($novel, 3);
        $this->chapter($novel, 4, false);

        $c1->forceFill(['read_at' => now()->subHour(), 'read_progress' => 100])->save();
        $c2->forceFill(['read_at' => now(), 'read_progress' => 50])->save();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="nowReading"', false)
            ->assertSee('Now reading')
            ->assertSee('The Silent Sword Saint')
            ->assertSee('by Test Author')
            ->assertSee('id="heroResume"', false)
            ->assertSee('href="' . route('chapters.show', $c2->id) . '" class="btn btn-primary" id="heroResume"', false)
            ->assertSee(route('novels.show', $novel->id) . '#chapterPanel', false)
            ->assertSee('Resume chapter 2')
            ->assertSee('novelfull.test')
            // Position in the novel (2 of 4, 2 to go) on the bar; the chapter
            // remainder (50% of 460 words at 230 wpm = 1 minute) labelled
            // apart. No "% of chapter" caption competing with the bar.
            ->assertSeeInOrder(['Chapter 2 of 4', '2 to go', 'aria-valuetext="Chapter 2 of 4"', '1 min left in this chapter'], false)
            ->assertDontSee('OF CHAPTER')
            ->assertDontSee('id="nothingReading"', false);

        // Novel progress shows once: the hero cover has no reading edge.
        $html = $this->get(route('home'))->getContent();
        preg_match('/<a href="[^"]*" class="home-hero-cover".*?<\/a>/s', $html, $cover);
        $this->assertNotEmpty($cover);
        $this->assertStringNotContainsString('home-cover-edge', $cover[0]);
    }

    public function test_empty_state_when_nothing_has_been_read(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="nothingReading"', false)
            ->assertSee('Nothing in progress')
            ->assertSee(route('novels.index'), false)
            ->assertSee(route('novels.discover'), false)
            ->assertDontSee('id="nowReading"', false)
            ->assertDontSee('id="continue-section"', false);
    }

    public function test_continue_row_lists_the_next_in_progress_novels(): void
    {
        $first = $this->novel(['name' => 'Hero Book']);
        $h = $this->chapter($first, 1);
        $this->chapter($first, 2);
        $h->forceFill(['read_at' => now(), 'read_progress' => 100])->save();

        $second = $this->novel(['name' => 'Shelf Book']);
        $s1 = $this->chapter($second, 1);
        $s2 = $this->chapter($second, 2);
        $this->chapter($second, 3);
        $s1->forceFill(['read_at' => now()->subDay(), 'read_progress' => 100])->save();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="continue-section"', false)
            ->assertSeeInOrder(['id="nowReading"', 'Hero Book', 'id="continue-section"', 'Shelf Book'], false)
            ->assertSee(route('chapters.show', $s2->id), false)
            ->assertSee('Continue Shelf Book at chapter 2, 2 to read')
            ->assertSee('Next: </span>ch 2 · 2 <span class="home-tile-wide">to read</span>', false);
    }

    public function test_new_chapters_are_grouped_by_novel_with_counts(): void
    {
        $a = $this->novel(['name' => 'Grouped Alpha']);
        $old = $this->chapter($a, 1);
        $this->age($old, 30);
        $old->forceFill(['read_at' => now()->subDays(20), 'read_progress' => 100])->save();
        $readFrom = $this->chapter($a, 2);
        $this->chapter($a, 3);
        $this->chapter($a, 4, false);
        $this->chapter($a, 5, true, ['kind' => NovelChapter::KIND_NOTE]);

        $b = $this->novel(['name' => 'Grouped Beta', 'translator_url' => null]);
        $this->chapter($b, 7, false);

        // Outside the window: never listed.
        $c = $this->novel(['name' => 'Stale Gamma']);
        $this->age($this->chapter($c, 1), 9);

        $response = $this->get(route('home'))->assertOk();

        $html = $response->getContent();
        preg_match('/<li class="home-new-row" data-novel="' . $a->id . '">(.*?)<\/li>/s', $html, $alpha);
        $this->assertNotEmpty($alpha, 'Alpha group renders');
        $this->assertStringContainsString('Chapters 2 to 5', $alpha[1]);
        $this->assertStringContainsString('novelfull.test', $alpha[1]);
        // A plain count, not a "N downloaded" badge.
        $this->assertStringContainsString('<span class="home-new-count">3 new</span>', $alpha[1]);
        $this->assertStringNotContainsString('downloaded', $alpha[1]);
        $this->assertStringContainsString('1 queued', $alpha[1]);
        $this->assertStringContainsString("author's note", $alpha[1]);
        $this->assertStringContainsString('Continue at ch 2', $alpha[1]);
        $this->assertStringContainsString(route('chapters.show', $readFrom->id), $alpha[1]);

        preg_match('/<li class="home-new-row" data-novel="' . $b->id . '">(.*?)<\/li>/s', $html, $beta);
        $this->assertNotEmpty($beta, 'Beta group renders');
        $this->assertStringContainsString('Chapter 7', $beta[1]);
        $this->assertStringContainsString('1 queued', $beta[1]);
        $this->assertStringNotContainsString(' new<', $beta[1]);
        $this->assertStringNotContainsString("author's note", $beta[1]);
        // Nothing downloaded yet: nothing to start.
        $this->assertStringNotContainsString('Start', $beta[1]);

        // Alpha has been read, so it sits under "In novels you are reading";
        // Beta has not, so it sits under "Not started yet".
        $response->assertSeeInOrder(['id="newReading"', 'data-novel="' . $a->id . '"', 'id="newFresh"', 'data-novel="' . $b->id . '"'], false);

        $response->assertDontSee('Stale Gamma');
    }

    public function test_new_chapters_split_into_reading_and_not_started(): void
    {
        $reading = $this->novel(['name' => 'Started Novel']);
        $old = $this->chapter($reading, 1);
        $this->age($old, 30);
        $old->forceFill(['read_at' => now()->subDays(20), 'read_progress' => 100])->save();
        $next = $this->chapter($reading, 2);

        $fresh = $this->novel(['name' => 'Fresh Novel', 'translator_url' => 'https://novelping.test/fresh']);
        $first = $this->chapter($fresh, 1);
        $this->chapter($fresh, 2);
        $this->chapter($fresh, 3, false);

        $html = $this->get(route('home'))->assertOk()->getContent();

        preg_match('/id="newReading".*?<\/ul>/s', $html, $readingBlock);
        preg_match('/id="newFresh".*?<\/ul>/s', $html, $freshBlock);
        $this->assertNotEmpty($readingBlock);
        $this->assertNotEmpty($freshBlock);

        $this->assertStringContainsString('In novels you are reading', $readingBlock[0]);
        $this->assertStringContainsString('Started Novel', $readingBlock[0]);
        $this->assertStringNotContainsString('Fresh Novel', $readingBlock[0]);
        $this->assertStringContainsString('Continue at ch 2', $readingBlock[0]);
        $this->assertStringContainsString(route('chapters.show', $next->id), $readingBlock[0]);

        $this->assertStringContainsString('Not started yet', $freshBlock[0]);
        $this->assertStringContainsString('1 novel not started yet', $freshBlock[0]);
        $this->assertStringContainsString('Fresh Novel', $freshBlock[0]);
        $this->assertStringNotContainsString('Started Novel', $freshBlock[0]);
        $this->assertStringContainsString('Chapters 1 to 3 · <span class="home-mono">novelping.test</span>', $freshBlock[0]);
        $this->assertStringContainsString('<span class="home-new-count">2 new</span>', $freshBlock[0]);
        $this->assertStringContainsString('1 queued', $freshBlock[0]);
        $this->assertStringContainsString('href="' . route('chapters.show', $first->id) . '" class="home-new-go"', $freshBlock[0]);
        $this->assertStringNotContainsString('Read from', $html);
    }

    public function test_start_never_opens_chapter_zero_when_a_chapter_one_exists(): void
    {
        $novel = $this->novel(['name' => 'Prologue Novel']);
        // Reading order: chapter 0, a translator's note, chapter 1, chapter 2.
        $zero = $this->chapter($novel, 0, true, ['label' => 'Chapter 0 - Notice', 'sort_key' => 0]);
        $note = $this->chapter($novel, 1, true, ['kind' => NovelChapter::KIND_NOTE, 'label' => 'Translator note', 'sort_key' => 1]);
        $one = $this->chapter($novel, 1, true, ['sort_key' => 2]);
        $this->chapter($novel, 2, true, ['sort_key' => 3]);

        // Only a chapter 0 downloaded: that is all there is to start with.
        $onlyZero = $this->novel(['name' => 'Only Zero Novel']);
        $zeroOnly = $this->chapter($onlyZero, 0);
        $this->chapter($onlyZero, 1, false);

        $html = $this->get(route('home'))->assertOk()->getContent();

        preg_match('/<li class="home-new-row" data-novel="' . $novel->id . '">(.*?)<\/li>/s', $html, $row);
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('href="' . route('chapters.show', $one->id) . '" class="home-new-go"', $row[1]);
        $this->assertStringContainsString('at chapter 1', $row[1]);
        $this->assertStringNotContainsString(route('chapters.show', $zero->id) . '"', $row[1]);
        $this->assertStringNotContainsString(route('chapters.show', $note->id) . '"', $row[1]);

        preg_match('/<li class="home-new-row" data-novel="' . $onlyZero->id . '">(.*?)<\/li>/s', $html, $zeroRow);
        $this->assertNotEmpty($zeroRow);
        $this->assertStringContainsString('href="' . route('chapters.show', $zeroOnly->id) . '" class="home-new-go"', $zeroRow[1]);
    }

    public function test_reading_novel_continues_where_the_shelf_does(): void
    {
        // In progress mid-chapter 2: New chapters continues there, like the
        // hero, rather than at the first unread chapter (3).
        $novel = $this->novel(['name' => 'Mid Chapter Novel']);
        $this->chapter($novel, 1)->forceFill(['read_at' => now()->subHour(), 'read_progress' => 100])->save();
        $mid = $this->chapter($novel, 2);
        $mid->forceFill(['read_at' => now(), 'read_progress' => 30])->save();
        $this->chapter($novel, 3);

        $html = $this->get(route('home'))->assertOk()->getContent();
        preg_match('/<li class="home-new-row" data-novel="' . $novel->id . '">(.*?)<\/li>/s', $html, $row);
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('Continue at ch 2', $row[1]);
        $this->assertStringContainsString(route('chapters.show', $mid->id), $row[1]);
    }

    public function test_status_strip_counts(): void
    {
        $failing = $this->novel(['name' => 'Failing Strip Novel']);
        $failing->scrape_failures = 12;
        $failing->save();
        $this->chapter($failing, 1, false);

        $novel = $this->novel();
        $this->chapter($novel, 1);
        $this->chapter($novel, 2);
        $yesterday = $this->chapter($novel, 3);
        $yesterday->forceFill(['download_date' => now()->subDays(2)])->save();

        Cache::put('scheduler_last_run', now()->subMinute()->toDateTimeString());

        $response = $this->get(route('home'))->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/is-success" id="stripScheduler">\s*<dt>scheduler<\/dt>.*?>OK<\/a>/s', $html);
        // "Queued" is pending chapter downloads (what Activity calls "chapters
        // queued"), not background jobs — those only appear when running.
        $pending = \App\NovelChapter::where('status', 0)->where('blacklist', 0)->count();
        $this->assertMatchesRegularExpression('/is-pending" id="stripQueue">\s*<dt>queued<\/dt>\s*<dd><a [^>]*>' . number_format($pending) . '<\/a><\/dd>/s', $html);
        $this->assertMatchesRegularExpression('/id="stripToday">\s*<dt>downloaded today<\/dt>\s*<dd><a [^>]*>2<\/a><\/dd>/s', $html);

        // The attention item: name, plain reason, source host, and the same
        // actions Health offers (test the source, snooze for 7 days).
        preg_match('/id="attentionSummary">(.*?)<dl class="home-status-nums">/s', $html, $attn);
        $this->assertNotEmpty($attn, 'attention block renders');
        $this->assertStringContainsString('Needs attention', $attn[1]);
        $this->assertStringContainsString('Failing Strip Novel', $attn[1]);
        $this->assertStringContainsString('12 consecutive scrape runs failed', $attn[1]);
        $this->assertStringContainsString('novelfull.test', $attn[1]);
        $this->assertStringContainsString('target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Test source', $attn[1]);
        $this->assertStringContainsString('action="' . route('novels.attention_snooze', $failing->id) . '"', $attn[1]);
        $this->assertStringContainsString('name="days" value="7"', $attn[1]);
        $this->assertStringContainsString('Snooze 7 days', $attn[1]);

        $response->assertSee(route('health.index') . '#attentionPanel', false)
            ->assertSee('is-warning" id="attentionSummary"', false)
            // Phones: one tappable row instead of the card.
            ->assertSee('id="attentionRow"', false)
            ->assertSee('1 novel needs attention')
            ->assertSee(route('activity.index'), false)
            // The scrolling strip and the old stat tiles are gone.
            ->assertDontSee('home-strip', false)
            ->assertDontSee('stat-tile', false);

        // A stale heartbeat reads "late".
        Cache::put('scheduler_last_run', now()->subMinutes(10)->toDateTimeString());
        $this->assertMatchesRegularExpression('/is-danger" id="stripScheduler">.*?>late<\/a>/s', $this->get(route('home'))->getContent());
    }

    public function test_snooze_from_home_uses_the_attention_endpoint_and_comes_back(): void
    {
        $failing = $this->novel(['name' => 'Snooze From Home']);
        $failing->scrape_failures = 12;
        $failing->save();
        $this->chapter($failing, 1, false);

        $this->from(route('home'))
            ->post(route('novels.attention_snooze', $failing->id), ['days' => 7])
            ->assertRedirect(route('home'));

        $this->assertNotNull($failing->fresh()->attention_ignored_until);
        $this->get(route('home'))->assertOk()
            ->assertDontSee('id="attentionRow"', false)
            ->assertSee('attention-snoozed', false);
    }

    public function test_status_numbers_are_muted_when_zero(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('>not running</a>', false)
            ->assertSee('is-zero" id="attentionSummary"', false)
            ->assertSee('is-zero" id="stripToday"', false)
            ->assertSee('is-zero" id="stripQueue"', false)
            ->assertDontSee('id="attentionRow"', false);
    }

    public function test_activity_renders_the_queued_and_recent_tables(): void
    {
        $novel = $this->novel(['name' => 'Activity Novel']);
        $this->chapter($novel, 1);
        $this->chapter($novel, 2, false, ['label' => 'Chapter 2 - Still Missing']);

        $snoozed = $this->novel(['name' => 'Snoozed Activity Novel']);
        $snoozed->scrape_failures = 12;
        $snoozed->attention_ignored_until = now()->addDays(3);
        $snoozed->save();

        $this->get(route('activity.index'))
            ->assertOk()
            ->assertSee('id="queued-section"', false)
            ->assertSee('id="recent-section"', false)
            // The download backlog is "Queued" everywhere, in the queued
            // (cyan) colour, not a red "Missing" alarm.
            ->assertSee('Queued chapters')
            ->assertSee('chip-mono chip-mono-pending', false)
            ->assertDontSee('Missing chapters')
            ->assertDontSee('chip-mono-danger', false)
            ->assertSee('Recently downloaded')
            ->assertSee('Chapter 2 - Still Missing')
            ->assertSee('Activity Novel')
            ->assertSee('attention-snoozed', false)
            ->assertSee('Snoozed Activity Novel');

        // …and they are no longer on the home page.
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="queued-section"', false)
            ->assertDontSee('id="recent-section"', false);
    }

    public function test_dashboard_cache_keys_are_still_used(): void
    {
        Cache::put('dashboard_attention', [['id' => 999, 'name' => 'Prewarmed']], 900);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Prewarmed');

        $this->assertTrue(Cache::has('dashboard_stats'));
        $this->assertTrue(Cache::has('dashboard_continue'));
    }
}
