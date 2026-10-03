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
            // 50% of 460 words at 230 wpm = 1 minute; position 2 of 4.
            ->assertSeeInOrder(['CHAPTER 2 OF 4', '1 MIN LEFT IN THIS CHAPTER', '50% OF CHAPTER'])
            ->assertDontSee('id="nothingReading"', false);
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
            ->assertSee('CH 2 · 2 NEW');
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
        $this->assertStringContainsString('3 downloaded', $alpha[1]);
        $this->assertStringContainsString('1 queued', $alpha[1]);
        $this->assertStringContainsString("Author's note", $alpha[1]);
        $this->assertStringContainsString('Read from 2', $alpha[1]);
        $this->assertStringContainsString(route('chapters.show', $readFrom->id), $alpha[1]);

        preg_match('/<li class="home-new-row" data-novel="' . $b->id . '">(.*?)<\/li>/s', $html, $beta);
        $this->assertNotEmpty($beta, 'Beta group renders');
        $this->assertStringContainsString('Chapter 7', $beta[1]);
        $this->assertStringContainsString('1 queued', $beta[1]);
        $this->assertStringNotContainsString('downloaded', $beta[1]);
        $this->assertStringNotContainsString("Author's note", $beta[1]);
        $this->assertStringNotContainsString('Read from', $beta[1]);

        $response->assertDontSee('Stale Gamma');
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

        $this->assertMatchesRegularExpression('/id="stripScheduler"[^>]*>.*?Scheduler OK/s', $html);
        $this->assertMatchesRegularExpression('/id="stripQueue"[^>]*>.*?Queue.*?<span class="home-strip-mono">0<\/span>/s', $html);
        $this->assertMatchesRegularExpression('/id="stripToday"[^>]*>.*?<span class="home-strip-mono">2<\/span> chapters today/s', $html);
        $response->assertSee('1 novel</strong> needs attention', false)
            ->assertSee(route('health.index') . '#attentionPanel', false)
            ->assertSee('is-warning" id="attentionSummary"', false)
            ->assertSee(route('activity.index'), false)
            // The old stat tiles are gone.
            ->assertDontSee('stat-tile', false);

        // A stale heartbeat reads "late".
        Cache::put('scheduler_last_run', now()->subMinutes(10)->toDateTimeString());
        $this->get(route('home'))->assertSee('Scheduler late');
    }

    public function test_strip_items_are_muted_when_zero(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Scheduler not running')
            ->assertSee('is-zero" id="attentionSummary"', false)
            ->assertSee('is-zero" id="stripToday"', false)
            ->assertSee('is-zero" id="stripQueue"', false);
    }

    public function test_activity_renders_the_missing_and_recent_tables(): void
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
            ->assertSee('id="missing-section"', false)
            ->assertSee('id="recent-section"', false)
            ->assertSee('Missing chapters')
            ->assertSee('Recently downloaded')
            ->assertSee('Chapter 2 - Still Missing')
            ->assertSee('Activity Novel')
            ->assertSee('attention-snoozed', false)
            ->assertSee('Snoozed Activity Novel');

        // …and they are no longer on the home page.
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="missing-section"', false)
            ->assertDontSee('id="recent-section"', false);
    }

    public function test_dashboard_cache_keys_are_still_used(): void
    {
        Cache::put('dashboard_attention', [['id' => 999, 'name' => 'Prewarmed']], 900);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('1 novel</strong> needs attention', false);

        $this->assertTrue(Cache::has('dashboard_stats'));
        $this->assertTrue(Cache::has('dashboard_continue'));
    }
}
