<?php

namespace Tests\Feature;

use App\Audiobook;
use App\Audiobooks\ListeningProgress;
use App\Audiobooks\ListeningTime;
use App\Http\Controllers\Api\V1\StatsController;
use App\Novel;
use App\NovelChapter;
use App\Services\ReaderStats;
use App\Support\RouteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Stats 2.0 (App\Services\ReaderStats, GET /api/v1/stats, /stats): figures
 * from seeded reads and listening, bulk marks kept out of activity, own data
 * only, day/hour bucketing in the app timezone, listening-seconds
 * accounting (ListeningTime caps) and the API's shape.
 */
class ReaderStatsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private int $chapterNo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Australia/Perth']);
        date_default_timezone_set('Australia/Perth');
        // Friday 27 Nov 2026, 10:00 in Perth.
        Carbon::setTestNow(Carbon::parse('2026-11-27 10:00:00', 'Australia/Perth'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 10:00:00', 'Australia/Perth'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function series(string $name, string $medium = Novel::MEDIUM_NOVEL, bool $complete = false, array $tags = []): Novel
    {
        $novel = Novel::create(['name' => $name, 'author' => 'A', 'status' => $complete ? 1 : 0, 'group_id' => 1, 'no_of_chapters' => 0]);
        $novel->forceFill(['medium' => $medium])->save();
        foreach ($tags as $tag) {
            $id = DB::table('tags')->where('name', $tag)->value('id') ?? DB::table('tags')->insertGetId(['name' => $tag]);
            DB::table('novel_tag')->insert(['novel_id' => $novel->id, 'tag_id' => $id]);
        }

        return $novel;
    }

    private function chapter(Novel $novel, ?int $words = 1000): NovelChapter
    {
        $n = ++$this->chapterNo;
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $n, 'book' => 0, 'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/{$n}"]);
        $chapter->forceFill(['status' => 1, 'download_date' => now()])->save();
        if ($words !== null) {
            $chapter->description = '<p>' . str_repeat('word ', 10) . '</p>';
            $chapter->save();
            DB::table('chapter_texts')->where('novel_chapter_id', $chapter->id)->update(['word_count' => $words]);
        }

        return $chapter;
    }

    private function read(int $userId, NovelChapter $chapter, string $at, bool $bulk = false): void
    {
        DB::table('chapter_reads')->insert([
            'user_id' => $userId, 'novel_id' => $chapter->novel_id, 'novel_chapter_id' => $chapter->id,
            'read_at' => $at, 'progress' => 100, 'progress_at' => $at, 'bulk' => $bulk, 'manual' => $bulk,
        ]);
    }

    private function audiobook(string $title, ?Novel $novel = null, int $durationMs = 36_000_000): Audiobook
    {
        $book = new Audiobook();
        $book->forceFill([
            'title' => $title, 'author' => 'Narrated', 'duration_ms' => $durationMs, 'novel_id' => $novel?->id,
            'status' => Audiobook::STATUS_READY, 'fingerprint' => sha1($title), 'dir' => 'x',
        ])->save();

        return $book;
    }

    private function listen(int $userId, Audiobook $book, string $day, int $hour, int $seconds): void
    {
        app(ListeningTime::class)->add($userId, $book->id, CarbonImmutable::parse(sprintf('%s %02d:10:00', $day, $hour), 'Australia/Perth'), $seconds);
    }

    // ------------------------------------------------------------------

    public function test_the_api_answers_the_documented_shape_for_each_range(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $token = $this->tokenFor($reader);

        $json = $this->asToken($token)->getJson('/api/v1/stats')->assertOk()->json();
        $this->assertSame(StatsController::KEYS, array_keys($json));
        $this->assertSame(30, $json['range']);
        $this->assertCount(30, $json['daily']);
        $this->assertSame('2026-11-27', $json['today']);
        $this->assertSame('2026-11-27', end($json['daily'])['date']);
        $this->assertSame(['date', 'chapters', 'words', 'listening_seconds'], array_keys($json['daily'][0]));
        $this->assertSame([
            'chapters_read', 'words_read', 'series_finished', 'books_finished', 'audiobooks_finished', 'listening_seconds',
            'current_streak', 'best_streak', 'active_today', 'chapters_today', 'words_today', 'listening_seconds_today',
            'chapters_week', 'listening_seconds_week', 'chapters_per_day', 'words_per_day', 'listening_minutes_per_day',
        ], array_keys($json['headline']));
        $this->assertSame(['days', 'chapters', 'words', 'listening_seconds', 'active_days'], array_keys($json['range_totals']));
        $this->assertSame(['start', 'chapters', 'words', 'listening_seconds'], array_keys($json['weekly'][0]));
        $this->assertSame(['start', 'end', 'max_minutes', 'days'], array_keys($json['heatmap']));
        $this->assertSame(['date', 'chapters', 'minutes', 'level'], array_keys($json['heatmap']['days'][0]));
        $this->assertSame(['medium', 'label', 'chapters', 'words', 'listening_seconds', 'minutes', 'share'], array_keys($json['by_medium'][0]));
        $this->assertSame(['novel', 'manga', 'book', 'audiobook', 'comic'], array_column($json['by_medium'], 'medium'));
        $this->assertCount(24, $json['hours']);
        $this->assertSame(['hour', 'chapters', 'minutes'], array_keys($json['hours'][0]));
        $this->assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], array_column($json['weekdays'], 'label'));
        $this->assertSame([
            'year', 'chapters', 'words', 'listening_seconds', 'series_finished', 'books_finished', 'audiobooks_finished',
            'active_days', 'top_genre', 'top_series', 'busiest_month',
        ], array_keys($json['year']));
        $this->assertSame(['key', 'title', 'detail', 'earned', 'progress', 'current', 'target'], array_keys($json['milestones'][0]));
        $this->assertSame([], $json['top_series']);
        $this->assertSame([], $json['top_audiobooks']);

        // Heatmap: from a Monday 52 weeks back to today, one cell a day.
        $start = CarbonImmutable::parse($json['heatmap']['start']);
        $this->assertSame(1, (int) $start->format('N'));
        $this->assertSame('2026-11-27', $json['heatmap']['end']);
        $this->assertCount($start->diffInDays(CarbonImmutable::parse('2026-11-27')) + 1, $json['heatmap']['days']);

        $this->assertCount(90, $this->asToken($token)->getJson('/api/v1/stats?range=90')->assertOk()->json('daily'));
        $year = $this->asToken($token)->getJson('/api/v1/stats?range=365')->assertOk()->json();
        $this->assertCount(365, $year['daily']);
        $this->assertSame('2025-11-28', $year['daily'][0]['date']);
        // Anything else is the default range.
        $this->assertSame(30, $this->asToken($token)->getJson('/api/v1/stats?range=7')->assertOk()->json('range'));
    }

    public function test_it_is_a_reader_route_and_needs_a_sign_in(): void
    {
        $this->assertContains('api.v1.stats', RouteAccess::READER_ROUTES);
        $this->enableSignIn();
        $this->asNobody()->getJson('/api/v1/stats')->assertUnauthorized();
        $reader = $this->makeReader();
        $this->asSignedIn($reader)->get('/stats')->assertOk()->assertSee('Your stats');
    }

    public function test_figures_from_reads_and_listening_are_the_readers_own(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $other = $this->makeReader('kim', 'Kim');
        $fantasy = $this->series('Shadow Slave', Novel::MEDIUM_NOVEL, false, ['Fantasy', 'Action']);
        $manga = $this->series('Solo Levelling', Novel::MEDIUM_MANGA, false, ['Action']);
        $book = $this->series('Mistborn', Novel::MEDIUM_BOOK, false, ['Fantasy']);

        // Today: two novel chapters (2,000 + 3,000 words) and a manga chapter.
        $this->read($reader->id, $this->chapter($fantasy, 2000), '2026-11-27 08:15:00');
        $this->read($reader->id, $this->chapter($fantasy, 3000), '2026-11-27 09:05:00');
        $this->read($reader->id, $this->chapter($manga, null), '2026-11-27 09:30:00');
        // Yesterday and the day before: the book (both chapters: it's finished).
        $this->read($reader->id, $this->chapter($book, 5000), '2026-11-26 21:00:00');
        $this->read($reader->id, $this->chapter($book, 5000), '2026-11-25 07:00:00');
        // A bulk catch-up: in the all-time totals, never activity.
        $this->read($reader->id, $this->chapter($fantasy, 4000), '2026-11-27 09:59:00', bulk: true);
        // Someone else's reading is not ours.
        $this->read($other->id, $this->chapter($fantasy, 9000), '2026-11-27 08:00:00');

        $audio = $this->audiobook('The Final Empire', $book);
        $this->listen($reader->id, $audio, '2026-11-27', 7, 1800);
        $this->listen($reader->id, $audio, '2026-11-20', 22, 3600);
        $this->listen($other->id, $audio, '2026-11-27', 7, 999);
        DB::table('audiobook_progress')->insert(['user_id' => $reader->id, 'audiobook_id' => $audio->id, 'position_ms' => 1, 'speed' => 1, 'finished_at' => '2026-11-27 07:40:00', 'updated_at' => '2026-11-27 07:40:00']);

        $s = app(ReaderStats::class)->fresh($reader->id, 30);
        $h = $s['headline'];

        // All-time totals include the bulk mark (the other reader's 9,000 never).
        $this->assertSame(6 + 0, $h['chapters_read']);
        $this->assertSame(2000 + 3000 + 0 + 5000 + 5000 + 4000, $h['words_read']);
        // Activity leaves it out.
        $this->assertSame(3, $h['chapters_today']);
        $this->assertSame(5000, $h['words_today']);
        $this->assertSame(1800, $h['listening_seconds_today']);
        $this->assertSame(5, $h['chapters_week']);
        $this->assertSame(5400, $h['listening_seconds']);
        $this->assertSame(1800, $h['listening_seconds_week']); // the 20th is 8 days back
        $this->assertSame(3, $h['current_streak']);
        $this->assertTrue($h['active_today']);
        $this->assertSame(1, $h['series_finished']);
        $this->assertSame(1, $h['books_finished']);
        $this->assertSame(1, $h['audiobooks_finished']);
        $this->assertSame(round(5 / 30, 1), $h['chapters_per_day']);

        $this->assertSame(['days' => 30, 'chapters' => 5, 'words' => 15000, 'listening_seconds' => 5400, 'active_days' => 4], $s['range_totals']);
        $today = end($s['daily']);
        $this->assertSame(['date' => '2026-11-27', 'chapters' => 3, 'words' => 5000, 'listening_seconds' => 1800], $today);

        // By medium: minutes = words / 250, 5 a manga chapter, real listening.
        $media = array_column($s['by_medium'], null, 'medium');
        $this->assertSame([2, 20], [$media['novel']['chapters'], $media['novel']['minutes']]);
        $this->assertSame([1, 5], [$media['manga']['chapters'], $media['manga']['minutes']]);
        $this->assertSame([2, 40], [$media['book']['chapters'], $media['book']['minutes']]);
        $this->assertSame([5400, 90], [$media['audiobook']['listening_seconds'], $media['audiobook']['minutes']]);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($s['by_medium'], 'share')), 0.001);

        // Genres: Fantasy = novel 20 + book 40 + its audiobook 90 minutes.
        $this->assertSame(['name' => 'Fantasy', 'chapters' => 4, 'minutes' => 150], $s['top_genres'][0]);
        $this->assertSame(['name' => 'Action', 'chapters' => 3, 'minutes' => 25], $s['top_genres'][1]);

        $this->assertSame(['Shadow Slave', 'Mistborn', 'Solo Levelling'], array_column($s['top_series'], 'name')); // ties: latest read first
        $this->assertSame('2026-11-26T13:00:00Z', $s['top_series'][1]['last_read_at']);
        $this->assertSame([['id' => $audio->id, 'title' => 'The Final Empire', 'author' => 'Narrated', 'novel_id' => $book->id, 'listening_seconds' => 5400]], $s['top_audiobooks']);

        // Time of day and weekday (Friday the 27th, Thursday 26th, Wednesday 25th, Friday 20th).
        $this->assertSame(2, $s['hours'][9]['chapters']);
        $this->assertSame(50, $s['hours'][7]['minutes']); // 07:00 book chapter (20 min) + 30 min listening
        $this->assertSame(60, $s['hours'][22]['minutes']);
        $this->assertSame([0, 0, 1, 1, 3, 0, 0], array_column($s['weekdays'], 'chapters'));
        // Fridays: 25 min reading + 30 + 60 min listening.
        $this->assertSame([0, 0, 20, 20, 115, 0, 0], array_column($s['weekdays'], 'minutes'));

        // Year in review.
        $this->assertSame(5, $s['year']['chapters']);
        $this->assertSame('Fantasy', $s['year']['top_genre']);
        $this->assertSame(['month' => '2026-11', 'label' => 'November', 'chapters' => 5], $s['year']['busiest_month']);
        $this->assertSame(1, $s['year']['series_finished']);

        // Heatmap: shades against the busiest day (the 20th, 60 min); today is
        // 5,000 words = 20 min + a manga chapter 5 + 30 listening.
        $cells = array_column($s['heatmap']['days'], null, 'date');
        $this->assertSame(60, $s['heatmap']['max_minutes']);
        $this->assertSame(['date' => '2026-11-27', 'chapters' => 3, 'minutes' => 55, 'level' => 4], $cells['2026-11-27']);
        $this->assertSame(4, $cells['2026-11-20']['level']);
        $this->assertSame(2, $cells['2026-11-25']['level']);
        $this->assertSame(0, $cells['2026-11-21']['level']);

        // Milestones.
        $earned = array_column(array_filter($s['milestones'], fn($m) => $m['earned']), 'key');
        $this->assertSame(['first_chapter', 'series_1', 'audiobook_1'], $earned);

        // The other reader sees only theirs.
        $theirs = app(ReaderStats::class)->fresh($other->id, 30)['headline'];
        $this->assertSame([1, 9000, 0], [$theirs['chapters_read'], $theirs['words_read'], $theirs['series_finished']]);
    }

    public function test_days_and_hours_are_in_the_app_timezone(): void
    {
        $reader = $this->makeReader();
        $novel = $this->series('Late Night');
        // 23:30 Perth on the 26th is 15:30 UTC: still the 26th, hour 23.
        $this->read($reader->id, $this->chapter($novel), '2026-11-26 23:30:00');
        // 00:20 Perth on the 27th is 16:20 UTC on the 26th: the 27th, hour 0.
        $this->read($reader->id, $this->chapter($novel), '2026-11-27 00:20:00');

        $s = app(ReaderStats::class)->fresh($reader->id, 30);
        $days = array_column($s['daily'], 'chapters', 'date');
        $this->assertSame(1, $days['2026-11-26']);
        $this->assertSame(1, $days['2026-11-27']);
        $this->assertSame(1, $s['hours'][23]['chapters']);
        $this->assertSame(1, $s['hours'][0]['chapters']);
        $this->assertSame('Australia/Perth', $s['timezone']);

        // Listening at 06:30 UTC lands at 14:00 local.
        $book = $this->audiobook('Local');
        app(ListeningTime::class)->add($reader->id, $book->id, CarbonImmutable::parse('2026-11-27 06:30:00', 'UTC'), 120);
        $row = DB::table('listening_activity')->first();
        $this->assertSame(['2026-11-27', 14, 120], [substr((string) $row->day, 0, 10), (int) $row->hour, (int) $row->seconds]);
    }

    public function test_streaks_count_reading_or_a_minute_of_listening(): void
    {
        $reader = $this->makeReader();
        $novel = $this->series('Streaky');
        $book = $this->audiobook('Streak audio');
        // Best: 5 days in October; current: yesterday + the day before (today not yet).
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-11-25'] as $d) {
            $this->read($reader->id, $this->chapter($novel), "{$d} 12:00:00");
        }
        $this->listen($reader->id, $book, '2026-11-26', 20, 60);
        // 59 seconds is not a day of listening.
        $this->listen($reader->id, $book, '2026-11-24', 20, 59);

        $h = app(ReaderStats::class)->fresh($reader->id, 30)['headline'];
        $this->assertSame(2, $h['current_streak']);
        $this->assertSame(5, $h['best_streak']);
        $this->assertFalse($h['active_today']);
    }

    public function test_listening_seconds_are_counted_from_progress_deltas_with_caps(): void
    {
        $reader = $this->makeReader();
        $book = $this->audiobook('Paced', null, 10 * 3600 * 1000);
        $progress = app(ListeningProgress::class);
        $t0 = CarbonImmutable::parse('2026-11-27 08:00:00', 'Australia/Perth');
        $total = fn() => (int) DB::table('listening_activity')->where('user_id', $reader->id)->sum('seconds');

        // The first write has nothing to compare with.
        $progress->record($reader->id, $book, 0, 1.0, null, $t0);
        $this->assertSame(0, $total());
        // 15 s of playing at 1x.
        $progress->record($reader->id, $book, 15_000, 1.0, null, $t0->addSeconds(15));
        $this->assertSame(15, $total());
        // 15 s at 1.5x moves 22.5 s of audio: 15 s listened.
        $progress->record($reader->id, $book, 37_500, 1.5, null, $t0->addSeconds(30));
        $this->assertSame(30, $total());
        // Paused for an hour, then 15 s more: only the 15 s.
        $progress->record($reader->id, $book, 52_500, 1.0, null, $t0->addSeconds(3630));
        $this->assertSame(45, $total());
        // A seek an hour ahead 10 s later: at most the 10 s (+ the clock slack).
        $progress->record($reader->id, $book, 52_500 + 3_600_000, 1.0, null, $t0->addSeconds(3640));
        $this->assertSame(45 + 10 + ListeningTime::SLACK_SECONDS, $total());
        // Going back adds nothing.
        $progress->record($reader->id, $book, 10_000, 1.0, null, $t0->addSeconds(3660));
        $this->assertSame(57, $total());
        // A stale (older) replayed write adds nothing.
        $progress->record($reader->id, $book, 900_000, 1.0, null, $t0->addSeconds(100));
        $this->assertSame(57, $total());
        // Offline for 2 hours of real listening, replayed in one write: capped.
        $progress->record($reader->id, $book, 10_000 + 7_200_000, 1.0, null, $t0->addSeconds(3660 + 7200));
        $this->assertSame(57 + ListeningTime::MAX_SECONDS_PER_WRITE, $total());

        // Pure function edge cases.
        $this->assertSame(0, ListeningTime::listenedSeconds(5000, $t0, 9000, $t0->subSeconds(5), 1.0));
        $this->assertSame(2, ListeningTime::listenedSeconds(0, $t0, 60_000, $t0, 1.0));
        $this->assertSame(20, ListeningTime::listenedSeconds(0, $t0, 60_000, $t0->addSeconds(30), 3.0));
    }

    public function test_the_api_endpoint_records_listening_through_progress_writes(): void
    {
        $reader = $this->makeReader();
        $this->enableSignIn();
        $token = $this->tokenFor($reader);
        $book = $this->audiobook('Through the API');
        $this->asToken($token)->putJson("/api/v1/audiobooks/{$book->id}/progress", ['position_ms' => 60_000, 'speed' => 1, 'recorded_at' => '2026-11-27T01:00:00Z'])->assertOk();
        $this->asToken($token)->putJson("/api/v1/audiobooks/{$book->id}/progress", ['position_ms' => 75_000, 'speed' => 1, 'recorded_at' => '2026-11-27T01:00:15Z'])->assertOk();
        $json = $this->asToken($token)->getJson('/api/v1/stats')->assertOk()->json();
        $this->assertSame(15, $json['headline']['listening_seconds']);
        $this->assertSame(15, $json['headline']['listening_seconds_today']);
    }

    public function test_listening_rows_go_with_their_reader_and_audiobook(): void
    {
        $reader = $this->makeReader();
        $book = $this->audiobook('Gone');
        $this->listen($reader->id, $book, '2026-11-27', 8, 100);
        $book->forceDelete();
        $this->assertSame(0, DB::table('listening_activity')->count());
        $other = $this->audiobook('Kept');
        $this->listen($reader->id, $other, '2026-11-27', 8, 100);
        $reader->delete();
        $this->assertSame(0, DB::table('listening_activity')->count());
    }

    public function test_the_web_page_draws_the_charts_with_seeded_data(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $novel = $this->series('Shadow Slave', Novel::MEDIUM_NOVEL, false, ['Fantasy']);
        $this->read($reader->id, $this->chapter($novel, 2500), '2026-11-27 08:15:00');

        $html = $this->asSignedIn($reader)->get('/stats?range=90')->assertOk()->getContent();
        foreach (['Your stats', 'stats-heatmap', 'Chapters per day', 'By medium', 'Shadow Slave', 'Fantasy', 'Milestones', '2026 so far'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('aria-current="page"', $html);
    }
}
