<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Presenter;
use App\Http\Controllers\HomeController;
use App\Novel;
use App\NovelChapter;
use App\Services\ReadState;
use App\Services\ResumePoint;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The resume rule every surface shares (App\Services\ResumePoint): the API's
 * resume_chapter_id (Android, iOS, Kindle), the web Home's Continue shelf,
 * /continue, the novel page's Continue/Start button and Home's New chapters
 * "Read from". Every case is built once and asked through every entry point,
 * which must agree. Chapters are named by key, never by id.
 */
class ResumePointTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, NovelChapter> */
    private array $ch = [];

    private Novel $novel;

    /** @var array<string, User> */
    private array $readers = [];

    /**
     * Each case: `chapters` (key => [number, attrs]; attrs: status (default
     * 1), blacklist, deleted, book, sort_key, page_count) in creation order,
     * `state` (reader => key => [read => minutes ago | null, progress,
     * pat => position minutes ago]), `expect` (reader => [kind, key]; for
     * caught_up the key is the API's last downloaded chapter), `manga`.
     */
    public static function cases(): array
    {
        $ch = fn(...$numbers) => array_combine(
            array_map(fn($n) => "c{$n}", $numbers),
            array_map(fn($n) => [$n, []], $numbers)
        );

        return [
            'nothing read or started: the first chapter' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => [],
                'expect' => ['owner' => [ResumePoint::START, 'c1']],
            ]],
            'reading in order: the next unread chapter' => [[
                'chapters' => $ch(1, 2, 3, 4),
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => 60, 'progress' => 100, 'pat' => 60],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c3']],
            ]],
            'an old half-read chapter behind does not win' => [[
                'chapters' => $ch(1, 2, 3, 4),
                'state' => ['owner' => [
                    'c1' => ['read' => 4000, 'progress' => 40, 'pat' => 4000], // glanced at on the web, long ago
                    'c2' => ['read' => 1440, 'progress' => 100, 'pat' => 1440],
                    'c3' => ['read' => 60, 'progress' => 100, 'pat' => 60],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c4']],
            ]],
            'most recent chapter half read on the web: resume it' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => 60, 'progress' => 40, 'pat' => 50], // marked read on open
                ]],
                'expect' => ['owner' => [ResumePoint::RESUME, 'c2']],
            ]],
            'most recent chapter half read on an app: resume it' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => null, 'progress' => 40, 'pat' => 30], // apps mark read at the end
                ]],
                'expect' => ['owner' => [ResumePoint::RESUME, 'c2']],
            ]],
            'most recent chapter finished: the next unread after it' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => ['owner' => [
                    'c1' => ['read' => 180, 'progress' => 30, 'pat' => 180],
                    'c2' => ['read' => 60, 'progress' => 95, 'pat' => 55],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c3']],
            ]],
            'earlier skipped chapters do not pull back' => [[
                'chapters' => $ch(1, 2, 3, 4),
                'state' => ['owner' => ['c3' => ['read' => 60, 'progress' => 100, 'pat' => 60]]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c4']],
            ]],
            'next chapter not downloaded yet: the next downloaded unread' => [[
                'chapters' => ['c1' => [1, []], 'c2' => [2, ['status' => 0]], 'c3' => [3, []]],
                'state' => ['owner' => ['c1' => ['read' => 60, 'progress' => 100, 'pat' => 60]]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c3']],
            ]],
            'caught up: nothing unread after the chapter read last' => [[
                'chapters' => ['c1' => [1, []], 'c2' => [2, []], 'c3' => [3, ['status' => 0]]],
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => 60, 'progress' => 100, 'pat' => 60],
                ]],
                'expect' => ['owner' => [ResumePoint::CAUGHT_UP, 'c2']],
            ]],
            're-reading an early chapter part-way: resume it' => [[
                'chapters' => $ch(1, 2, 3, 4),
                'state' => ['owner' => [
                    'c1' => ['read' => 3000, 'progress' => 100, 'pat' => 3000],
                    'c2' => ['read' => 2900, 'progress' => 30, 'pat' => 10], // opened again, scrolled to 30 %
                    'c3' => ['read' => 2800, 'progress' => 100, 'pat' => 2800],
                ]],
                'expect' => ['owner' => [ResumePoint::RESUME, 'c2']],
            ]],
            're-reading an early chapter to its end: the next unread after it' => [[
                'chapters' => $ch(1, 2, 3, 4),
                'state' => ['owner' => [
                    'c1' => ['read' => 3000, 'progress' => 100, 'pat' => 3000],
                    'c2' => ['read' => 2900, 'progress' => 100, 'pat' => 10],
                    'c3' => ['read' => 2800, 'progress' => 100, 'pat' => 2800],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c4']],
            ]],
            'manga chapter in progress (unread, page progress): resume it' => [[
                'manga' => true,
                'chapters' => [
                    'c1' => [1, ['page_count' => 20]],
                    'c2' => [2, ['page_count' => 30]],
                    'c3' => [3, ['page_count' => 25]],
                ],
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => null, 'progress' => 50, 'pat' => 15],
                ]],
                'expect' => ['owner' => [ResumePoint::RESUME, 'c2']],
            ]],
            'two readers: each their own state' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => [
                    'owner' => [
                        'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                        'c2' => ['read' => 60, 'progress' => 100, 'pat' => 60],
                    ],
                    'other' => ['c1' => ['read' => null, 'progress' => 60, 'pat' => 5]],
                    'fresh' => [],
                ],
                'expect' => [
                    'owner' => [ResumePoint::NEXT, 'c3'],
                    'other' => [ResumePoint::RESUME, 'c1'],
                    'fresh' => [ResumePoint::START, 'c1'],
                ],
            ]],
            'blacklisted and deleted chapters are ignored' => [[
                'chapters' => [
                    'c1' => [1, []],
                    'c2' => [2, ['blacklist' => 1]],
                    'c3' => [3, ['deleted' => true]],
                    'c4' => [4, ['blacklist' => 1]],
                    'c5' => [5, []],
                ],
                'state' => ['owner' => [
                    'c1' => ['read' => 1440, 'progress' => 100, 'pat' => 1440],
                    'c2' => ['read' => 60, 'progress' => 40, 'pat' => 60],   // would be the latest
                    'c3' => ['read' => 30, 'progress' => 40, 'pat' => 30],   // likewise
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c5']],
            ]],
            'reading order, not creation order (books, sort keys)' => [[
                'chapters' => [
                    'b1c3' => [3, ['book' => 1]],
                    'b0c2' => [2, ['sort_key' => 5]],
                    'b0c9' => [9, []],                 // NULL sort_key sorts first
                    'b0c1' => [1, ['sort_key' => 1]],
                ],
                // Order: b0c9, b0c1, b0c2, b1c3.
                'state' => ['owner' => ['b0c1' => ['read' => 60, 'progress' => 100, 'pat' => 60]]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'b0c2']],
            ]],
            'marked read in bulk at one time: the next after the furthest' => [[
                'chapters' => $ch(1, 2, 3, 4),
                'state' => ['owner' => [
                    'c1' => ['read' => 30, 'progress' => 100, 'pat' => 30],
                    'c2' => ['read' => 30, 'progress' => 100, 'pat' => 30],
                    'c3' => ['read' => 30, 'progress' => 100, 'pat' => 30],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c4']],
            ]],
            'a tie goes to the furthest chapter in reading order' => [[
                // Book 1's chapter 1 comes after book 0's chapter 5.
                'chapters' => ['c1' => [5, []], 'c2' => [1, ['book' => 1]], 'c3' => [2, ['book' => 1]]],
                'state' => ['owner' => [
                    'c1' => ['read' => 30, 'progress' => 40, 'pat' => 30],
                    'c2' => ['read' => 30, 'progress' => 100, 'pat' => 30],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c3']],
            ]],
            'unread at 100 % (end reached, no read mark) is finished' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => null, 'progress' => 100, 'pat' => 10],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c3']],
            ]],
            'marked unread or opened at 0 % is not reading activity' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => null, 'progress' => null, 'pat' => 5],  // marked unread
                    'c3' => ['read' => null, 'progress' => 0, 'pat' => 1],     // opened on an app, 0 %
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c2']],
            ]],
            'read with no position yet counts as finished' => [[
                'chapters' => $ch(1, 2, 3),
                'state' => ['owner' => [
                    'c1' => ['read' => 120, 'progress' => 100, 'pat' => 120],
                    'c2' => ['read' => 60, 'progress' => null, 'pat' => null],
                ]],
                'expect' => ['owner' => [ResumePoint::NEXT, 'c3']],
            ]],
        ];
    }

    #[DataProvider('cases')]
    public function test_every_entry_point_picks_the_same_chapter(array $case): void
    {
        $this->build($case);

        foreach ($case['expect'] as $who => [$kind, $key]) {
            $reader = $this->readers[$who];
            $expected = $this->ch[$key]->id;
            $label = "{$who}: {$kind} {$key}";

            // The service, both ways.
            $this->assertSame(['id' => $expected, 'kind' => $kind], app(ResumePoint::class)->libraryPoints($reader->id)[$this->novel->id] ?? null, "library: {$label}");
            $point = app(ResumePoint::class)->forNovels([$this->novel->id], ['id', 'novel_id', 'chapter'], $reader->id, true)[$this->novel->id] ?? null;
            $this->assertSame([$kind, $expected], [$point['kind'] ?? null, $point['chapter']?->id], "forNovels: {$label}");

            // The API (apps, Kindle): resume_chapter_id.
            $this->assertSame($expected, Presenter::resumeChapterIds($reader->id)[$this->novel->id] ?? null, "api map: {$label}");
            $this->actAsReader($reader);
            $medium = !empty($case['manga']) ? '?medium=all' : '';
            $summary = collect($this->getJson('/api/v1/novels' . $medium)->assertOk()->json('novels'))->firstWhere('id', $this->novel->id);
            $this->assertSame($expected, $summary['resume_chapter_id'], "api json: {$label}");

            // The web: Home's Continue shelf, /continue and the novel page.
            $listed = in_array($kind, [ResumePoint::RESUME, ResumePoint::NEXT], true);
            $item = collect(app(HomeController::class)->continueReading(8, $reader->id))
                ->first(fn($i) => $i['novel']->id === $this->novel->id);
            if ($listed) {
                $this->assertNotNull($item, "continue list: {$label}");
                $this->assertSame($expected, $item['next']->id, "continue list: {$label}");
                $this->assertSame($kind === ResumePoint::RESUME, $item['resume'], "continue list resume flag: {$label}");
                $this->get('/continue')->assertRedirect(route('chapters.show', $expected));
            } else {
                $this->assertNull($item, "continue list leaves it out: {$label}");
                $this->get('/continue')->assertRedirect(route('home'));
            }

            $page = $this->get(route('novels.show', $this->novel->id))->assertOk();
            $page->assertViewHas('continue_chapter_id', $kind === ResumePoint::CAUGHT_UP ? null : $expected);
            if ($kind !== ResumePoint::CAUGHT_UP) {
                $page->assertSee($kind === ResumePoint::START ? 'Start reading' : 'Continue');
            }

            // Home's New chapters "Read from" (every chapter here is new).
            $groups = collect((new \ReflectionMethod(HomeController::class, 'newChapters'))->invoke(app(HomeController::class), 10, $reader->id))
                ->keyBy('novel_id');
            $this->assertSame($kind === ResumePoint::CAUGHT_UP ? null : $expected, $groups[$this->novel->id]['read_from_id'], "new chapters: {$label}");
        }
    }

    public function test_a_manga_page_in_progress_names_its_page_on_the_novel_page(): void
    {
        $this->build(self::cases()['manga chapter in progress (unread, page progress): resume it'][0]);
        $this->actAsReader($this->readers['owner']);

        // 50 % of 30 pages.
        $this->get(route('novels.show', $this->novel->id))->assertOk()
            ->assertViewHas('continue_page_number', 15)
            ->assertSee('page 15');
    }

    public function test_a_position_write_moves_continue_at_once_on_every_surface(): void
    {
        $this->build(self::cases()['reading in order: the next unread chapter'][0]);
        $owner = $this->readers['owner'];
        $this->actAsReader($owner);

        // Warm every cache that holds a resume point.
        $this->assertSame($this->ch['c3']->id, Presenter::resumeChapterIds()[$this->novel->id]);
        $this->get(route('home'))->assertOk();
        $this->get(route('novels.show', $this->novel->id))->assertOk()->assertViewHas('continue_chapter_id', $this->ch['c3']->id);

        // An app reads 40 % into chapter 1 again (no read mark): resume it.
        $this->postJson("/api/v1/chapters/{$this->ch['c1']->id}/progress", ['progress' => 40])->assertOk();

        $this->assertSame($this->ch['c1']->id, Presenter::resumeChapterIds()[$this->novel->id]);
        $this->assertSame($this->ch['c1']->id, collect($this->getJson('/api/v1/novels')->json('novels'))->firstWhere('id', $this->novel->id)['resume_chapter_id']);
        $this->get('/continue')->assertRedirect(route('chapters.show', $this->ch['c1']->id));
        $this->get(route('home'))->assertOk()->assertViewHas('hero', fn($h) => $h['chapter']->id === $this->ch['c1']->id && $h['resume']);
        $this->get(route('novels.show', $this->novel->id))->assertOk()->assertViewHas('continue_chapter_id', $this->ch['c1']->id);

        // Finishing it goes back to where the reader was: the next unread after it.
        $this->postJson("/api/v1/chapters/{$this->ch['c1']->id}/progress", ['progress' => 100])->assertOk();
        $this->assertSame($this->ch['c3']->id, Presenter::resumeChapterIds()[$this->novel->id]);
        $this->get(route('novels.show', $this->novel->id))->assertOk()->assertViewHas('continue_chapter_id', $this->ch['c3']->id);
    }

    public function test_a_look_up_does_not_move_continue(): void
    {
        // Reading chapter 3 on the web (40 %); a highlight in chapter 1 is
        // looked up from an app, which sends progress 100 with `lookup`.
        $this->build([
            'chapters' => ['c1' => [1, []], 'c2' => [2, []], 'c3' => [3, []], 'c4' => [4, []]],
            'state' => ['owner' => [
                'c1' => ['read' => 3000, 'progress' => 100, 'pat' => 3000],
                'c2' => ['read' => 2000, 'progress' => 100, 'pat' => 2000],
                'c3' => ['read' => 60, 'progress' => 40, 'pat' => 50],
            ]],
        ]);
        $this->actAsReader($this->readers['owner']);

        $this->postJson("/api/v1/chapters/{$this->ch['c1']->id}/progress", ['progress' => 100, 'read' => true, 'lookup' => true])->assertOk();

        $this->assertSame($this->ch['c3']->id, Presenter::resumeChapterIds()[$this->novel->id]);
    }

    public function test_library_points_is_one_statement_and_for_novels_at_most_two(): void
    {
        $this->build(self::cases()['reading in order: the next unread chapter'][0]);
        $second = Novel::create(['name' => 'Second', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 2]);
        $this->makeChapter($second, 'x1', 1, []);

        $count = function (\Closure $fn): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $fn();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $owner = $this->readers['owner']->id;
        $points = app(ResumePoint::class);

        $this->assertSame(1, $count(fn() => $points->libraryPoints($owner)));
        $this->assertSame(2, $count(fn() => $points->forNovels([$this->novel->id, $second->id], ['id'], $owner)));
        // Every series resuming its anchor: no target statement at all.
        $this->setReadState($this->ch['c2'], ['read_at' => now(), 'progress' => 40, 'progress_at' => now()]);
        $this->assertSame(1, $count(fn() => $points->forNovels([$this->novel->id], ['id'], $owner)));
        $this->assertSame(0, $count(fn() => $points->forNovels([], ['id'], $owner)));
    }

    // ---------------------------------------------------------------------
    // Only real reading moves the anchor: hand marks (chapter_reads.manual)
    // and look-ups are not activity.
    // ---------------------------------------------------------------------

    public function test_a_hand_mark_ahead_does_not_move_continue(): void
    {
        $this->series(50);
        foreach (range(1, 10) as $n) {
            $this->readState()->markOpened($this->ch["c{$n}"], $this->owner()->id);
        }
        $this->travel(5)->minutes();
        $this->readState()->setRead($this->ch['c40'], true, now(), $this->owner()->id);

        $this->assertTrue((bool) $this->readStateOf($this->ch['c40'])->manual);
        $this->assertResume(ResumePoint::NEXT, 'c11');
    }

    public function test_mark_to_here_with_nothing_read_starts_after_the_marks(): void
    {
        $this->series(60);
        $this->readState()->markMany(NovelChapter::where('novel_id', $this->novel->id)->where('chapter', '<=', 50), true, now(), false, $this->owner()->id);

        $this->assertResume(ResumePoint::START, 'c51');
    }

    public function test_a_series_only_marked_by_hand_stays_on_the_continue_shelf(): void
    {
        $this->series(60);
        $this->readState()->markMany(NovelChapter::where('novel_id', $this->novel->id)->where('chapter', '<=', 50), true, now(), false, $this->owner()->id);

        $items = app(\App\Http\Controllers\HomeController::class)->continueReading(8, $this->owner()->id);

        $this->assertCount(1, $items);
        $this->assertSame($this->ch['c51']->id, $items[0]['next']->id);
        $this->assertFalse($items[0]['resume']);
    }

    public function test_a_web_look_up_of_an_unread_chapter_does_not_move_continue(): void
    {
        $this->series(40);
        $state = $this->readState();
        $owner = $this->owner()->id;
        foreach (range(1, 10) as $n) {
            $state->recordProgress($this->ch["c{$n}"], 100, true, now()->subMinutes(100 - $n), $owner);
        }
        $this->actAsReader($this->readers['owner']);

        $this->get(route('chapters.show', $this->ch['c30']->id) . '?lookup=1')->assertOk();

        $this->assertTrue((bool) DB::table('chapter_reads')->where('user_id', $owner)->where('novel_chapter_id', $this->ch['c30']->id)->value('manual'));
        $this->assertResume(ResumePoint::NEXT, 'c11');

        // Opened to read, it is reading.
        $this->get(route('chapters.show', $this->ch['c31']->id))->assertOk();
        $this->assertResume(ResumePoint::NEXT, 'c32');
    }

    public function test_single_hand_marks_with_nothing_read_start_after_them(): void
    {
        $this->series(10);
        foreach (['c1', 'c2', 'c3'] as $key) {
            $this->readState()->setRead($this->ch[$key], true, now(), $this->owner()->id);
            $this->travel(1)->minutes();
        }

        $this->assertResume(ResumePoint::START, 'c4');
    }

    public function test_every_chapter_marked_by_hand_is_caught_up(): void
    {
        $this->series(5);
        $this->readState()->markMany(NovelChapter::where('novel_id', $this->novel->id), true, now(), false, $this->owner()->id);
        $owner = $this->owner()->id;
        $points = app(ResumePoint::class);

        $this->assertSame(['id' => $this->ch['c5']->id, 'kind' => ResumePoint::CAUGHT_UP], $points->libraryPoints($owner)[$this->novel->id]);
        $point = $points->forNovels([$this->novel->id], ['id'], $owner, true)[$this->novel->id];
        $this->assertSame([ResumePoint::CAUGHT_UP, $this->ch['c5']->id], [$point['kind'], $point['chapter']?->id]);
        $this->assertSame(['chapter' => null, 'kind' => ResumePoint::CAUGHT_UP], $points->forNovels([$this->novel->id], ['id'], $owner)[$this->novel->id]);
        // A start filter that empties it does not hide that it is caught up.
        $filtered = $points->forNovels([$this->novel->id], ['id'], $owner, false, fn($q) => $q->where('chapter', '>', 99));
        $this->assertSame(ResumePoint::CAUGHT_UP, $filtered[$this->novel->id]['kind'] ?? null);
        // Unread chapters the filter leaves out: absent, for the caller's fallback.
        $this->readState()->setRead($this->ch['c5'], false, now(), $owner);
        $this->assertSame([], $points->forNovels([$this->novel->id], ['id'], $owner, false, fn($q) => $q->where('chapter', '>', 99)));
    }

    public function test_a_hand_mark_does_not_pull_away_from_a_chapter_part_read(): void
    {
        $this->series(40);
        $owner = $this->owner()->id;
        foreach (range(1, 9) as $n) {
            $this->readState()->markOpened($this->ch["c{$n}"], $owner);
        }
        $this->travel(1)->minutes();
        $this->readState()->recordProgress($this->ch['c10'], 40, false, now(), $owner);
        $this->travel(5)->minutes();
        $this->readState()->setRead($this->ch['c30'], true, now(), $owner);

        $this->assertResume(ResumePoint::RESUME, 'c10');
    }

    public function test_reading_in_a_hand_marked_chapter_makes_it_the_anchor(): void
    {
        $this->series(50);
        $owner = $this->owner()->id;
        foreach (range(1, 10) as $n) {
            $this->readState()->markOpened($this->ch["c{$n}"], $owner);
        }
        $this->travel(1)->minutes();
        $this->readState()->setRead($this->ch['c40'], true, now(), $owner);
        $this->assertResume(ResumePoint::NEXT, 'c11');

        $this->travel(1)->minutes();
        $this->readState()->recordProgress($this->ch['c40'], 30, false, now(), $owner);

        $this->assertFalse((bool) $this->readStateOf($this->ch['c40'])->manual);
        $this->assertResume(ResumePoint::RESUME, 'c40');

        // Re-marking it read keeps it real reading (its date and flags are kept).
        $this->travel(1)->minutes();
        $this->readState()->setRead($this->ch['c40'], true, now(), $owner);
        $this->assertFalse((bool) $this->readStateOf($this->ch['c40'])->manual);
        $this->assertResume(ResumePoint::NEXT, 'c41');
    }

    public function test_a_look_up_read_mark_is_not_activity(): void
    {
        $this->series(50);
        $owner = $this->owner()->id;
        $this->readState()->recordProgress($this->ch['c10'], 40, false, now(), $owner);
        $this->travel(5)->minutes();
        $this->readState()->recordProgress($this->ch['c40'], null, true, now(), $owner, lookup: true);

        $state = $this->readStateOf($this->ch['c40']);
        $this->assertNotNull($state->read_at);
        $this->assertTrue((bool) $state->manual);
        $this->assertResume(ResumePoint::RESUME, 'c10');

        // The same mark without `lookup` is reading there.
        $this->travel(1)->minutes();
        $this->readState()->recordProgress($this->ch['c45'], null, true, now(), $owner);
        $this->assertFalse((bool) $this->readStateOf($this->ch['c45'])->manual);
        $this->assertResume(ResumePoint::NEXT, 'c46');
    }

    public function test_mark_unread_is_still_not_activity_and_clears_the_flag(): void
    {
        $this->series(50);
        $owner = $this->owner()->id;
        foreach (range(1, 10) as $n) {
            $this->readState()->markOpened($this->ch["c{$n}"], $owner);
        }
        $this->travel(1)->minutes();
        $this->readState()->setRead($this->ch['c40'], true, now(), $owner);
        $this->travel(1)->minutes();
        $this->readState()->setRead($this->ch['c40'], false, now(), $owner);
        $this->readState()->markMany(NovelChapter::where('novel_id', $this->novel->id)->where('chapter', '>=', 45), false, now(), false, $owner);

        $this->assertFalse((bool) $this->readStateOf($this->ch['c40'])->manual);
        $this->assertResume(ResumePoint::NEXT, 'c11');
    }

    public function test_the_migration_flags_bulk_rows_manual(): void
    {
        $this->series(3);
        foreach (['c1', 'c2', 'c3'] as $key) {
            $this->markRead($this->ch[$key]);
        }
        DB::table('chapter_reads')->where('novel_chapter_id', $this->ch['c1']->id)->update(['bulk' => true]);
        DB::table('chapter_reads')->where('novel_chapter_id', $this->ch['c2']->id)->update(['bulk' => true, 'manual' => true]);

        $migration = require database_path('migrations/2026_11_24_000001_add_manual_to_chapter_reads_table.php');
        $migration->up();
        $migration->up(); // re-runnable

        $this->assertTrue((bool) $this->readStateOf($this->ch['c1'])->manual);
        $this->assertTrue((bool) $this->readStateOf($this->ch['c2'])->manual);
        $this->assertFalse((bool) $this->readStateOf($this->ch['c3'])->manual);
    }

    public function test_the_web_toggle_read_does_not_move_continue(): void
    {
        $this->series(10);
        $this->actAsReader($this->owner());
        foreach (['c1', 'c2', 'c3'] as $key) {
            $this->get(route('chapters.show', $this->ch[$key]->id))->assertOk();
        }
        $this->travel(5)->minutes();
        $this->postJson(route('chapters.toggle_read', $this->ch['c8']->id))->assertOk()->assertJson(['read' => true]);

        $this->get('/continue')->assertRedirect(route('chapters.show', $this->ch['c4']->id));
        $this->get(route('novels.show', $this->novel->id))->assertOk()->assertViewHas('continue_chapter_id', $this->ch['c4']->id);
        $this->assertSame($this->ch['c4']->id, Presenter::resumeChapterIds()[$this->novel->id]);
    }

    public function test_the_api_hand_mark_and_look_up_do_not_move_resume_chapter_id(): void
    {
        $this->series(10);
        $this->actAsReader($this->owner());
        $resumeId = fn() => collect($this->getJson('/api/v1/novels')->assertOk()->json('novels'))->firstWhere('id', $this->novel->id)['resume_chapter_id'];

        $this->postJson(route('api.v1.chapters.progress', $this->ch['c1']->id), ['progress' => 100, 'read' => true])->assertOk();
        $this->travel(1)->minutes();
        $this->postJson(route('api.v1.chapters.progress', $this->ch['c2']->id), ['progress' => 50])->assertOk();
        $this->assertSame($this->ch['c2']->id, $resumeId());

        $this->travel(1)->minutes();
        $this->postJson(route('api.v1.chapters.read', $this->ch['c6']->id), ['read' => true])->assertOk()->assertJson(['read' => true]);
        $this->assertSame($this->ch['c2']->id, $resumeId());

        $this->travel(1)->minutes();
        $this->postJson(route('api.v1.chapters.progress', $this->ch['c8']->id), ['progress' => 100, 'read' => true, 'lookup' => true])
            ->assertOk()->assertJson(['read' => true]);
        $this->assertSame($this->ch['c2']->id, $resumeId());
    }

    // ---------------------------------------------------------------------

    private function build(array $case): void
    {
        $this->readers = ['owner' => $this->owner()];
        foreach (array_keys($case['state'] + ($case['expect'] ?? [])) as $who) {
            $this->readers[$who] ??= User::factory()->create(['name' => "Reader {$who}"]);
        }

        $this->novel = Novel::create([
            'name' => 'Resume Novel', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => count($case['chapters']),
            'medium' => !empty($case['manga']) ? Novel::MEDIUM_MANGA : Novel::MEDIUM_NOVEL,
        ]);
        foreach ($case['chapters'] as $key => [$number, $attrs]) {
            $this->makeChapter($this->novel, $key, $number, $attrs);
        }

        $ago = fn($minutes) => $minutes === null ? null : now()->subMinutes($minutes);
        foreach ($case['state'] as $who => $rows) {
            foreach ($rows as $key => $row) {
                $this->setReadState($this->ch[$key], [
                    'read_at' => $ago($row['read'] ?? null),
                    'progress' => $row['progress'] ?? null,
                    'progress_at' => $ago($row['pat'] ?? null),
                ], $this->readers[$who]);
            }
        }
    }

    /** A novel of chapters c1..c$count (all downloaded), nothing read. */
    private function series(int $count): void
    {
        $this->build(['chapters' => array_combine(
            array_map(fn($n) => "c{$n}", range(1, $count)),
            array_map(fn($n) => [$n, []], range(1, $count))
        ), 'state' => []]);
    }

    private function readState(): ReadState
    {
        return app(ReadState::class);
    }

    /** The owner's resume point, asked of libraryPoints(), forNovels() and the API map, which must agree. */
    private function assertResume(string $kind, string $key): void
    {
        $owner = $this->owner()->id;
        $expected = $this->ch[$key]->id;

        $this->assertSame(['id' => $expected, 'kind' => $kind], app(ResumePoint::class)->libraryPoints($owner)[$this->novel->id] ?? null, "library: {$kind} {$key}");
        $point = app(ResumePoint::class)->forNovels([$this->novel->id], ['id', 'novel_id', 'chapter'], $owner, true)[$this->novel->id] ?? null;
        $this->assertSame([$kind, $expected], [$point['kind'] ?? null, $point['chapter']?->id], "forNovels: {$kind} {$key}");
        $this->assertSame($expected, Presenter::resumeChapterIds($owner)[$this->novel->id] ?? null, "api map: {$kind} {$key}");
    }

    private function makeChapter(Novel $novel, string $key, float $number, array $attrs): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => $number, 'book' => $attrs['book'] ?? 0,
            'label' => "Chapter {$number}", 'url' => "https://example.test/{$novel->id}/{$key}",
        ]);
        $chapter->forceFill([
            'status' => $attrs['status'] ?? 1,
            'download_date' => ($attrs['status'] ?? 1) ? now()->subDay() : null,
            'blacklist' => $attrs['blacklist'] ?? 0,
            'sort_key' => $attrs['sort_key'] ?? null,
            'page_count' => $attrs['page_count'] ?? null,
        ])->save();
        if (!empty($attrs['deleted'])) {
            $chapter->delete();
        }

        return $this->ch[$key] = $chapter;
    }
}
