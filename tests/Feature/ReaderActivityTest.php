<?php

namespace Tests\Feature;

use App\Bookmark;
use App\Novel;
use App\NovelChapter;
use App\ReadingPosition;
use App\Services\ReaderActivity;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * The admin's Readers pages (App\Services\ReaderActivity,
 * ReaderActivityController): per-reader figures with the bulk / soft-delete
 * rules of /stats, "reading now", most popular, sessions, the history's
 * filters and paging, one reader's page, and admin-only access.
 */
class ReaderActivityTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const NOW = '2026-11-25 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse(self::NOW));
    }

    /** $user read $chapter at $at; optionally as a bulk / by-hand mark. */
    private function read(NovelChapter $chapter, User $user, $at, bool $bulk = false, bool $manual = false): void
    {
        $this->markRead($chapter, $user, $at);
        DB::table('chapter_reads')->where('user_id', $user->id)->where('novel_chapter_id', $chapter->id)
            ->update(['bulk' => $bulk, 'manual' => $manual]);
    }

    private function position(User $user, NovelChapter $chapter, $at, ?int $progress = 40, ?string $device = 'Kindle 2'): void
    {
        ReadingPosition::create([
            'user_id' => $user->id, 'novel_id' => $chapter->novel_id, 'novel_chapter_id' => $chapter->id,
            'progress' => $progress, 'device_id' => $device ? 'device-' . md5($device) : null, 'device_name' => $device,
            'recorded_at' => $at,
        ]);
    }

    private function figures($response, User $user): array
    {
        return $response->viewData('overview')['readers'][$user->id];
    }

    // ---- Dashboard figures -------------------------------------------------

    public function test_dashboard_shows_each_readers_figures_with_the_stats_rules(): void
    {
        $owner = $this->owner();
        $sam = $this->makeReader();
        [$a, $ac] = $this->novelWithChapters('Alpha Saga', 6);
        [$b, $bc] = $this->novelWithChapters('Beta Chronicle', 3);

        // Owner: today, yesterday, two days ago → streak 3.
        $this->read($ac[1], $owner, now()->subHours(2));
        $this->read($ac[2], $owner, now()->subDay());
        $this->read($ac[3], $owner, now()->subDays(2));
        // Two bulk marks today: all-time only.
        $this->read($ac[4], $owner, now()->subHour(), bulk: true);
        $this->read($ac[5], $owner, now()->subHour(), bulk: true);
        // A read of a soft-deleted chapter counts nowhere.
        $this->read($ac[6], $owner, now()->subMinutes(30));
        $ac[6]->delete();

        // Sam: today and 8 days ago → streak 1, week 1, month 2.
        $this->read($bc[1], $sam, now()->subDays(8));
        $this->read($bc[2], $sam, now()->subMinutes(10));

        $response = $this->get(route('readers.index'))->assertOk()
            ->assertSee('Readers')->assertSee('Reading activity')
            ->assertSee(route('readers.history'))->assertSee(route('settings.users'));

        $this->assertSame(['today' => 1, 'week' => 3, 'month' => 3, 'total' => 5, 'streak' => 3],
            array_intersect_key($this->figures($response, $owner), array_flip(['today', 'week', 'month', 'total', 'streak'])));
        $this->assertSame(['today' => 1, 'week' => 1, 'month' => 2, 'total' => 2, 'streak' => 1],
            array_intersect_key($this->figures($response, $sam), array_flip(['today', 'week', 'month', 'total', 'streak'])));

        // Last read: the newest activity read (not the bulk or deleted ones).
        $this->assertSame('Alpha Saga', $this->figures($response, $owner)['last']['novel_name']);
        $this->assertSame($ac[1]->id, $this->figures($response, $owner)['last']['chapter_id']);
        $this->assertSame($bc[2]->id, $this->figures($response, $sam)['last']['chapter_id']);

        $tiles = $response->viewData('overview')['tiles'];
        $this->assertSame(['active_today' => 2, 'accounts' => 2, 'today' => 2, 'week' => 4, 'month' => 5], $tiles);

        // The chart stacks today's bar by reader, names in the legend and title.
        $html = $response->getContent();
        $this->assertStringContainsString('bar-chart-stacked', $html);
        $this->assertStringContainsString('class="reader-legend"', $html);
        $this->assertStringContainsString(now()->format('j M') . ': ' . $owner->name . ' 1, Sam 1', $html);
        // The busiest reader in 30 days comes first in the table.
        $this->assertLessThan(strpos($html, 'data-reader="' . $sam->id . '"'), strpos($html, 'data-reader="' . $owner->id . '"'));
    }

    public function test_dashboard_works_with_a_single_account_and_no_reads(): void
    {
        $this->get(route('readers.index'))->assertOk()
            ->assertSee('Nobody is reading right now.')
            ->assertSee('Nobody read a chapter in the last 30 days.')
            ->assertDontSee('class="reader-legend"', false);

        [, $c] = $this->novelWithChapters('Solo Story', 1);
        $this->read($c[1], $this->owner(), now()->subMinutes(5));
        // The aggregates are cached for a minute.
        $this->assertSame(0, $this->get(route('readers.index'))->viewData('overview')['tiles']['today']);
        $this->travel(ReaderActivity::CACHE_SECONDS + 1)->seconds();
        // Only one reader: one colour, no legend.
        $this->get(route('readers.index'))->assertOk()
            ->assertSee('bar-chart-seg reader-series-1', false)
            ->assertDontSee('class="reader-legend"', false);
    }

    public function test_disabled_accounts_are_listed_as_turned_off(): void
    {
        $sam = $this->makeReader();
        $sam->forceFill(['disabled_at' => now()])->save();

        $this->get(route('readers.index'))->assertOk()
            ->assertSee('data-reader="' . $sam->id . '"', false)
            ->assertSee('Turned off');
    }

    public function test_the_dashboard_is_a_fixed_number_of_queries(): void
    {
        $count = function () {
            \Illuminate\Support\Facades\Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('readers.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        [, $c] = $this->novelWithChapters('Counted', 4);
        $this->read($c[1], $this->owner(), now()->subMinutes(5));
        $this->position($this->owner(), $c[1], now()->subMinute());
        $one = $count();

        foreach (['amy', 'ben', 'cat'] as $i => $name) {
            $u = $this->makeReader($name, ucfirst($name));
            $this->read($c[$i + 2], $u, now()->subMinutes(5));
            $this->position($u, $c[$i + 2], now()->subMinute());
        }
        $this->assertSame($one, $count());
    }

    // ---- Reading now ---------------------------------------------------------

    public function test_reading_now_shows_fresh_positions_one_per_reader(): void
    {
        $owner = $this->owner();
        $sam = $this->makeReader();
        [, $ac] = $this->novelWithChapters('Fresh Tale', 2);
        [, $bc] = $this->novelWithChapters('Older Tale', 2);
        [, $cc] = $this->novelWithChapters('Stale Tale', 2);

        $this->position($owner, $ac[2], now()->subMinutes(2), 64, 'Kindle 2');
        $this->position($owner, $bc[1], now()->subMinutes(5));       // older: not shown
        $this->position($sam, $cc[1], now()->subMinutes(30));        // too old

        $response = $this->get(route('readers.index'))->assertOk();
        $now = $response->viewData('now');
        $this->assertCount(1, $now);
        $this->assertSame($owner->id, $now[0]->user_id);
        $this->assertSame('Fresh Tale', $now[0]->novel->name);
        $this->assertSame(2, $now[0]->minutes);

        $this->get(route('readers.now'))->assertOk()
            ->assertSee('Fresh Tale')->assertSee('Chapter 2')->assertSee('64%')->assertSee('Kindle 2')->assertSee('2 min ago')
            ->assertDontSee('Older Tale')->assertDontSee('Stale Tale');

        // A position stamped a little in the future is "now".
        [, $fc] = $this->novelWithChapters('Future Tale', 1);
        $this->position($sam, $fc[1], now()->addMinute());
        $this->assertSame([$sam->id, $owner->id], app(ReaderActivity::class)->readingNow()->pluck('user_id')->all());
    }

    public function test_reading_now_skips_deleted_chapters_and_says_when_nobody_reads(): void
    {
        [, $c] = $this->novelWithChapters('Gone Soon', 1);
        $this->position($this->owner(), $c[1], now()->subMinute());
        $c[1]->delete();

        $this->get(route('readers.now'))->assertOk()->assertSee('Nobody is reading right now.');
    }

    // ---- Most popular ------------------------------------------------------

    public function test_most_popular_ranks_by_distinct_readers_then_chapters(): void
    {
        $owner = $this->owner();
        $sam = $this->makeReader();
        [$shared, $sc] = $this->novelWithChapters('Shared Story', 2);
        [$binge, $bc] = $this->novelWithChapters('Binge Story', 5);

        $this->read($sc[1], $owner, now()->subDays(3));
        $this->read($sc[1], $sam, now()->subDays(2));
        foreach ($bc as $i => $chapter) {
            $this->read($chapter, $owner, now()->subHours($i));
        }
        // Outside the 30-day window: not counted.
        $this->read($sc[2], $owner, now()->subDays(40));

        $popular = $this->get(route('readers.index'))->assertOk()->viewData('overview')['popular'];
        $this->assertSame(['Shared Story', 'Binge Story'], array_column($popular, 'name'));
        $this->assertSame([2, 1], array_column($popular, 'readers'));
        $this->assertSame([2, 5], array_column($popular, 'chapters'));
        $this->get(route('readers.index'))->assertSee(route('readers.history', ['novel' => $shared->id]));
    }

    // ---- Sessions (pure) ---------------------------------------------------

    private function row(int $user, int $novel, int $chapter, string $at, array $extra = []): array
    {
        return ['user_id' => $user, 'novel_id' => $novel, 'chapter_id' => $chapter, 'display_number' => (string) $chapter,
            'read_at' => Carbon::parse($at), 'novel_name' => "Novel {$novel}"] + $extra;
    }

    public function test_sessions_merge_reads_up_to_the_gap_and_split_otherwise(): void
    {
        $sessions = ReaderActivity::sessions([
            $this->row(1, 10, 31, '2026-11-25 11:00:00'),
            $this->row(1, 10, 30, '2026-11-25 10:30:00'),   // 30 min: same session
            $this->row(1, 10, 29, '2026-11-25 10:10:00'),
            $this->row(1, 10, 28, '2026-11-25 09:39:00'),   // 31 min: new session
            $this->row(1, 11, 5, '2026-11-25 09:30:00'),    // another series
            $this->row(1, 10, 27, '2026-11-25 09:20:00'),   // back to 10: new session
        ]);

        $this->assertSame([3, 1, 1, 1], array_column($sessions, 'chapters'));
        $this->assertSame(29, $sessions[0]['first']['id']);
        $this->assertSame(31, $sessions[0]['last']['id']);
        $this->assertSame('2026-11-25 10:10:00', $sessions[0]['started_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-25 11:00:00', $sessions[0]['ended_at']->format('Y-m-d H:i:s'));
        $this->assertSame([10, 10, 11, 10], array_column($sessions, 'novel_id'));
        $this->assertSame('Chapters 29–31 (3 chapters)', ReaderActivity::sessionChapters($sessions[0]));
        $this->assertSame('Chapter 28', ReaderActivity::sessionChapters($sessions[1]));
    }

    public function test_sessions_keep_readers_apart_even_when_interleaved(): void
    {
        $sessions = ReaderActivity::sessions([
            $this->row(1, 10, 3, '2026-11-25 11:00:00'),
            $this->row(2, 10, 9, '2026-11-25 10:55:00'),
            $this->row(1, 10, 2, '2026-11-25 10:50:00'),
            $this->row(2, 10, 8, '2026-11-25 10:45:00'),
            $this->row(1, 10, 1, '2026-11-25 10:40:00', ['manual' => true]),   // marked by hand: its own session
        ]);

        $this->assertSame([1, 2, 1], array_column($sessions, 'user_id'));
        $this->assertSame([2, 2, 1], array_column($sessions, 'chapters'));
        $this->assertSame([false, false, true], array_column($sessions, 'manual'));
        $this->assertSame([], ReaderActivity::sessions([]));
    }

    // ---- History -----------------------------------------------------------

    public function test_history_filters_by_reader_series_and_bulk(): void
    {
        $owner = $this->owner();
        $sam = $this->makeReader();
        [$a, $ac] = $this->novelWithChapters('Alpha Saga', 3);
        [$b, $bc] = $this->novelWithChapters('Beta Chronicle', 3);

        $this->read($ac[1], $owner, now()->subHours(5));
        $this->read($ac[2], $owner, now()->subHours(3), manual: true);
        $this->read($bc[1], $sam, now()->subHours(2));
        $this->read($bc[2], $sam, now()->subHours(1), bulk: true);

        $users = fn($r) => array_values(array_unique(array_column($r->viewData('sessions'), 'user_id')));

        $all = $this->get(route('readers.history'))->assertOk()->assertSee('Marked by hand')->assertDontSee('>Bulk<', false);
        $this->assertSame(3, count($all->viewData('sessions')));

        $this->assertSame([$sam->id], $users($this->get(route('readers.history', ['user' => $sam->id]))->assertOk()));
        $this->get(route('readers.history', ['novel' => $a->id]))->assertOk()
            ->assertSee('id="historyNovelChip"', false)->assertSee('Alpha Saga')->assertDontSee('Beta Chronicle');

        $withBulk = $this->get(route('readers.history', ['user' => $sam->id, 'bulk' => 1]))->assertOk()->assertSee('>Bulk<', false);
        $this->assertSame(2, count($withBulk->viewData('sessions')));
        $this->assertTrue($withBulk->viewData('bulk'));

        // Unknown or invalid filter values are ignored.
        $ignored = $this->get('/readers/history?user=999999&novel=abc&bulk=nope')->assertOk();
        $this->assertNull($ignored->viewData('userId'));
        $this->assertNull($ignored->viewData('novel'));
        $this->assertSame(3, count($ignored->viewData('sessions')));
        $this->get(route('readers.history', ['novel' => 999999]))->assertOk()->assertDontSee('id="historyNovelChip"', false);
    }

    public function test_history_pages_by_100_reads(): void
    {
        $owner = $this->owner();
        $novel = Novel::create(['name' => 'Long Read', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);
        $rows = [];
        for ($n = 1; $n <= 105; $n++) {
            $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $n, 'book' => 0, 'label' => "Chapter {$n}", 'url' => "https://example.test/long/{$n}"]);
            // An hour apart: one session per read.
            $rows[] = ['user_id' => $owner->id, 'novel_id' => $novel->id, 'novel_chapter_id' => $chapter->id, 'read_at' => now()->subHours($n), 'bulk' => false, 'manual' => false];
        }
        DB::table('chapter_reads')->insert($rows);

        $first = $this->get(route('readers.history', ['user' => $owner->id]))->assertOk();
        $this->assertCount(100, $first->viewData('sessions'));
        $this->assertTrue($first->viewData('rows')->hasMorePages());
        $this->assertStringContainsString('user=' . $owner->id, (string) $first->viewData('rows')->nextPageUrl());

        $second = $this->get(route('readers.history', ['user' => $owner->id, 'page' => 2]))->assertOk();
        $this->assertCount(5, $second->viewData('sessions'));
        $this->assertFalse($second->viewData('rows')->hasMorePages());
    }

    // ---- One reader --------------------------------------------------------

    public function test_reader_page_shows_that_readers_stats_not_the_admins(): void
    {
        $owner = $this->owner();
        $sam = $this->makeReader();
        [, $ac] = $this->novelWithChapters('Owner Only', 3);
        [, $bc] = $this->novelWithChapters('Sam Story', 2);
        foreach ($ac as $c) {
            $this->read($c, $owner, now()->subMinutes(50));
        }
        $this->read($bc[1], $sam, now()->subMinutes(20));
        $this->position($sam, $bc[1], now()->subMinutes(3), 25, 'Pixel 8');
        \App\ApiToken::issue($sam, 'Sam phone')[0]->forceFill(['last_used_at' => now()->subMinutes(15)])->save();
        DB::table('browser_sessions')->insert([
            'user_id' => $sam->id, 'session_hash' => hash('sha256', 'sam-session'), 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'ip' => '203.0.113.77', 'public' => true, 'signed_in_at' => now()->subDay(), 'last_active_at' => now()->subHour(),
        ]);

        $this->get(route('readers.show', $sam->id))->assertOk()
            ->assertViewHas('read_total', 1)
            ->assertViewHas('read_today', 1)
            ->assertSee('Sam')->assertSee('Reading now')->assertSee('Pixel 8')
            ->assertSee('Sam Story')->assertDontSee('Owner Only')
            ->assertSee('Sam phone')->assertSee('Safari on macOS')->assertSee('public address')
            ->assertDontSee('203.0.113.77')
            ->assertSee(route('settings.users.history', ['user' => $sam->id]))
            ->assertSee(route('settings.users') . '#user-' . $sam->id, false)
            ->assertSee(route('readers.history', ['user' => $sam->id]));

        $this->get(route('readers.show', User::max('id') + 100))->assertNotFound();
    }

    public function test_settings_users_links_to_each_readers_activity(): void
    {
        $sam = $this->makeReader();
        $this->get(route('settings.users'))->assertOk()
            ->assertSee(route('readers.show', $sam->id))
            ->assertSee('Reading activity');
    }

    // ---- Access ------------------------------------------------------------

    public function test_readers_are_refused_and_never_see_the_link_the_admin_does(): void
    {
        $owner = $this->enableSignIn();
        $sam = $this->makeReader();
        $routes = [route('readers.index'), route('readers.now'), route('readers.history'), route('readers.show', $owner->id)];

        $this->asSignedIn($sam);
        foreach ($routes as $url) {
            $this->get($url)->assertForbidden();
        }
        $home = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="' . route('readers.index') . '"', $home);
        $this->assertStringNotContainsString(str_replace('/', '\/', route('readers.index')) . '"', $home);

        $this->asSignedIn($owner);
        foreach ($routes as $url) {
            $this->get($url)->assertOk();
        }
        $home = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('readers.index') . '"', $home);
        $this->assertStringContainsString(str_replace('/', '\/', route('readers.index')) . '"', $home);
    }

    public function test_highlights_never_appear_on_the_readers_pages(): void
    {
        $sam = $this->makeReader();
        [$novel, $c] = $this->novelWithChapters('Marked Up', 2);
        $this->read($c[1], $sam, now()->subMinutes(5));
        $this->position($sam, $c[1], now()->subMinute());
        Bookmark::create(['user_id' => $sam->id, 'novel_id' => $novel->id, 'novel_chapter_id' => $c[1]->id, 'excerpt' => 'SECRET-HIGHLIGHT-EXCERPT', 'note' => 'SECRET-HIGHLIGHT-NOTE']);

        foreach ([route('readers.index'), route('readers.now'), route('readers.history'), route('readers.show', $sam->id)] as $url) {
            $this->get($url)->assertOk()->assertDontSee('SECRET-HIGHLIGHT');
        }
    }
}

