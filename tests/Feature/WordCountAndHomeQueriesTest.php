<?php

namespace Tests\Feature;

use App\ChapterText;
use App\Http\Controllers\HomeController;
use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * November 2026 audit: stored word counts (save hook, backfill, /stats, the
 * Home hero), Home's first-load query budget, batched attention URLs and the
 * performance middleware.
 */
class WordCountAndHomeQueriesTest extends TestCase
{
    use RefreshDatabase;

    private function novel(string $name, array $attrs = []): Novel
    {
        $novel = Novel::create([
            'name' => $name,
            'author' => 'Author',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
            'description' => 'A long synopsis that should never be cached on Home.',
            'translator_url' => 'https://www.source.test/' . md5($name),
        ]);
        $novel->forceFill($attrs)->save();

        return $novel;
    }

    private function chapter(Novel $novel, int $n, ?string $body = null, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://www.source.test/{$novel->id}/chapter-{$n}",
        ]);
        if ($body !== null) {
            $chapter->description = $body;
            $chapter->status = 1;
            $chapter->download_date = now();
        }
        $chapter->forceFill($attrs)->save();

        return $chapter;
    }

    // --- 4. word counts -----------------------------------------------------

    public function test_saving_chapter_text_stores_its_word_count(): void
    {
        $novel = $this->novel('Counted');
        $chapter = $this->chapter($novel, 1, '<p>One two <b>three</b></p> <p>four</p>');

        $this->assertSame(4, ChapterText::find($chapter->id)->word_count);

        $chapter->description = '<p>' . trim(str_repeat('word ', 10)) . '</p>';
        $chapter->save();
        $this->assertSame(10, ChapterText::find($chapter->id)->word_count);
    }

    public function test_backfill_fills_only_missing_counts_and_is_rerunnable(): void
    {
        $novel = $this->novel('Backfill');
        $a = $this->chapter($novel, 1, '<p>alpha beta gamma</p>');
        $b = $this->chapter($novel, 2, '<p>one two</p>');
        DB::table('chapter_texts')->update(['word_count' => null]);
        // A count already stored is left alone.
        DB::table('chapter_texts')->where('novel_chapter_id', $b->id)->update(['word_count' => 99]);

        $this->artisan('chapters:word-counts')
            ->expectsOutputToContain('Counted 1 / 1 chapter texts')
            ->assertSuccessful();

        $this->assertSame(3, (int) DB::table('chapter_texts')->where('novel_chapter_id', $a->id)->value('word_count'));
        $this->assertSame(99, (int) DB::table('chapter_texts')->where('novel_chapter_id', $b->id)->value('word_count'));

        $this->artisan('chapters:word-counts')
            ->expectsOutputToContain('already have a word count')
            ->assertSuccessful();
    }

    public function test_stats_sum_stored_word_counts(): void
    {
        $novel = $this->novel('Stats');
        $c1 = $this->chapter($novel, 1, '<p>' . trim(str_repeat('word ', 300)) . '</p>');
        $c2 = $this->chapter($novel, 2, '<p>' . trim(str_repeat('word ', 200)) . '</p>');
        $this->chapter($novel, 3, '<p>unread words here</p>');
        $c1->forceFill(['read_at' => now()])->save();
        $c2->forceFill(['read_at' => now()])->save();

        $this->get(route('stats.index'))
            ->assertOk()
            ->assertViewHas('words_total', 500)
            ->assertViewHas('window_words', 500);
    }

    public function test_hero_minutes_use_the_stored_count_and_fall_back_without_one(): void
    {
        $novel = $this->novel('Hero');
        $read = $this->chapter($novel, 1, '<p>done</p>', ['read_at' => now()->subHour(), 'read_progress' => 100]);
        // 460 words → 2 minutes at 230 wpm.
        $next = $this->chapter($novel, 2, '<p>' . trim(str_repeat('word ', 460)) . '</p>');

        // The stored count wins over the body (proves the body isn't read).
        DB::table('chapter_texts')->where('novel_chapter_id', $next->id)->update(['word_count' => 1150]);
        $this->get(route('home'))->assertOk()->assertSee('about 5 min to read');

        // Not backfilled yet: counted from the body as before.
        Cache::flush();
        DB::table('chapter_texts')->where('novel_chapter_id', $next->id)->update(['word_count' => null]);
        $this->get(route('home'))->assertOk()->assertSee('about 2 min to read');
    }

    // --- 5. Home query budget ----------------------------------------------

    public function test_home_first_load_query_count_is_bounded(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $novel = $this->novel("Reading {$i}");
            $this->chapter($novel, 1, '<p>read one</p>', ['read_at' => now()->subMinutes(60 - $i)]);
            $this->chapter($novel, 2, '<p>half read</p>', $i % 2 ? ['read_at' => now()->subMinutes(30 - $i), 'read_progress' => 40] : []);
            $this->chapter($novel, 3, '<p>next one</p>');
            $this->chapter($novel, 4); // queued
        }

        Cache::flush();
        DB::enableQueryLog();
        $response = $this->get(route('home'))->assertOk();
        $log = DB::getQueryLog();
        $count = count($log);
        DB::disableQueryLog();

        // Hero + five shelf tiles, all six novels present.
        for ($i = 1; $i <= 6; $i++) {
            $response->assertSee("Reading {$i}");
        }
        // Was 35 before batching (per-novel continue/readFrom lookups, five
        // separate stat counts, select * plus a body fetch for the hero). Now
        // fixed: 6 attention/snooze, 5 continue, 1 counts, 2 hero, 3 new
        // chapters, 3 stats — independent of how many novels are in progress.
        $this->assertLessThanOrEqual(20, $count, "Home ran {$count} queries on an empty cache");
    }

    public function test_home_query_count_holds_with_not_started_novels_in_new_chapters(): void
    {
        // Same budget as above with the New chapters split exercised: the
        // "started" flag rides on the novels query and both kinds of
        // read-from target share one UNION statement.
        for ($i = 1; $i <= 4; $i++) {
            $novel = $this->novel("Reading {$i}");
            $this->chapter($novel, 1, '<p>read one</p>', ['read_at' => now()->subMinutes(60 - $i)]);
            $this->chapter($novel, 2, '<p>next one</p>');
        }
        for ($i = 1; $i <= 6; $i++) {
            $novel = $this->novel("Fresh {$i}");
            $this->chapter($novel, 0, '<p>notice</p>');
            $this->chapter($novel, 1, '<p>first</p>');
            $this->chapter($novel, 2);
        }

        Cache::flush();
        DB::enableQueryLog();
        $response = $this->get(route('home'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertSee('6 novels not started yet')->assertSee('In novels you are reading');
        $this->assertLessThanOrEqual(20, $count, "Home ran {$count} queries on an empty cache");
    }

    public function test_continue_reading_picks_the_same_chapters_as_before(): void
    {
        $a = $this->novel('Resume Me');
        $this->chapter($a, 1, '<p>x</p>', ['read_at' => now()->subHours(3), 'read_progress' => 30]);
        $mid = $this->chapter($a, 2, '<p>x</p>', ['read_at' => now()->subHours(2), 'read_progress' => 50]);
        $this->chapter($a, 3, '<p>x</p>', ['read_at' => now()->subHours(4)]);

        $b = $this->novel('Next Up');
        $this->chapter($b, 1, '<p>x</p>', ['read_at' => now()->subHour(), 'read_progress' => 100]);
        // Reading order is book, sort_key (NULL first), chapter, id.
        $later = $this->chapter($b, 2, '<p>x</p>', ['sort_key' => 2]);
        $first = $this->chapter($b, 5, '<p>x</p>');
        $this->chapter($b, 3, '<p>x</p>', ['book' => 1]);

        $c = $this->novel('Caught Up');
        $this->chapter($c, 1, '<p>x</p>', ['read_at' => now()]);

        $items = app(HomeController::class)->continueReading(8);

        $this->assertSame(['Next Up', 'Resume Me'], array_map(fn($i) => $i['novel']->name, $items));
        $this->assertSame([$first->id, $mid->id], array_map(fn($i) => $i['next']->id, $items));
        $this->assertSame([false, true], array_map(fn($i) => $i['resume'], $items));
        $this->assertSame(50, (int) $items[1]['next']->read_progress);
        // Slim models: nothing heavy goes into the dashboard_continue cache.
        $this->assertArrayNotHasKey('description', $items[0]['novel']->getAttributes());
        $this->assertTrue($items[0]['novel']->relationLoaded('file'));
    }

    public function test_new_chapters_read_from_is_the_first_unread_per_novel(): void
    {
        $a = $this->novel('New A');
        $this->chapter($a, 1, '<p>x</p>', ['read_at' => now()]);
        $a2 = $this->chapter($a, 2, '<p>x</p>');
        $this->chapter($a, 3);

        $b = $this->novel('New B');
        $this->chapter($b, 1);

        $groups = collect((new \ReflectionMethod(HomeController::class, 'newChapters'))->invoke(app(HomeController::class)))
            ->keyBy('name');

        $this->assertSame($a2->id, $groups['New A']['read_from_id']);
        $this->assertSame(2.0, $groups['New A']['read_from']);
        $this->assertNull($groups['New B']['read_from_id']);
        $this->assertSame(3, $groups['New A']['total']);
    }

    public function test_dashboard_stats_keep_their_values(): void
    {
        $this->novel('Active');
        $this->novel('Done', ['status' => 1]);
        $n = $this->novel('Counts');
        $this->chapter($n, 1, '<p>x</p>');                                            // today
        $this->chapter($n, 2, '<p>x</p>', ['download_date' => now()->subHours(23)]); // last 24h
        $this->chapter($n, 3, '<p>x</p>', ['download_date' => now()->subDays(3)]);
        $this->chapter($n, 4);                                                        // pending
        $this->chapter($n, 5, null, ['blacklist' => 1]);

        $stats = HomeController::stats();

        $this->assertSame(2, $stats['active']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(2, $stats['downloaded_today']);
        $expectedToday = now()->subHours(23)->gte(today()) ? 2 : 1;
        $this->assertSame($expectedToday, $stats['today']);
    }

    // --- 8. NovelHealth + PerformanceMonitoring ----------------------------

    public function test_attention_source_urls_are_batched(): void
    {
        $group = DB::table('groups')->insertGetId(['label' => 'Src', 'url' => 'https://group.test', 'created_at' => now()]);
        $names = [];
        for ($i = 1; $i <= 4; $i++) {
            $novel = $this->novel("Failing {$i}", ['group_id' => $group, 'scrape_failures' => 3]);
            NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 7, 'book' => 0, 'label' => 'c7', 'url' => "/n{$i}/c7"]);
            NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 6, 'book' => 0, 'label' => 'c6', 'url' => "/n{$i}/c6"]);
            $names[] = $novel->name;
        }
        // No pending chapter: falls back to the translator page.
        $toc = $this->novel('Toc Broken', ['toc_failures' => 2, 'translator_url' => 'https://toc.test/page']);

        DB::enableQueryLog();
        $items = collect(app(NovelHealth::class)->needingAttention())->keyBy('name');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('https://group.test/n1/c6', $items['Failing 1']['url']);
        $this->assertSame('https://group.test/n4/c6', $items['Failing 4']['url']);
        $this->assertSame('https://toc.test/page', $items['Toc Broken']['url']);
        $this->assertSame(['id', 'name', 'reason', 'url'], array_keys($items['Failing 1']));
        // 3 selections + 2 stalled aggregates + groups + first pending.
        $this->assertLessThanOrEqual(8, $count);
        $this->assertSame('https://group.test/n2/c6', app(NovelHealth::class)->sourceUrlFor(Novel::find($items['Failing 2']['id'])));
    }

    public function test_slow_queries_are_logged_without_the_query_log_in_debug(): void
    {
        config(['app.debug' => true]);
        Log::spy();

        $this->get(route('palette', ['q' => 'x']));
        $this->assertFalse(DB::logging(), 'the full query log must not be enabled');

        // A query over the threshold, fired while a request is in flight.
        \Illuminate\Support\Facades\Route::get('/__slow-test', function () {
            DB::connection()->logQuery('select 1', [], 150.0);
            return 'ok';
        });
        $this->get('/__slow-test')->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn($msg, $ctx) => $msg === 'Slow query detected'
            && $ctx['sql'] === 'select 1' && $ctx['time_ms'] === 150.0 && str_ends_with($ctx['url'], '/__slow-test'))->once();
    }
}
