<?php

namespace Tests\Feature;

use App\ChapterRead;
use App\Novel;
use App\NovelChapter;
use App\Services\Updates\NewChapterUpdates;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * GET /api/v1/updates?since= (App\Services\Updates\NewChapterUpdates): a
 * series is listed only when the reader had nothing left to read in it at
 * `since` and now does.
 */
class ApiV1UpdatesTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private User $reader;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));
        $this->enableSignIn();
        $this->reader = $this->makeReader();
        $this->token = $this->tokenFor($this->reader);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A novel with $count chapters downloaded two days ago. @return array{0: Novel, 1: array<int, NovelChapter>} */
    private function series(string $name, int $count, string $medium = Novel::MEDIUM_NOVEL): array
    {
        $novel = Novel::create(['name' => $name, 'status' => 0, 'group_id' => 1, 'no_of_chapters' => $count, 'medium' => $medium]);
        $chapters = [];
        if ($count > 0) {
            $at = now()->subDays(2)->format('Y-m-d H:i:s');
            $rows = [];
            foreach (range(1, $count) as $n) {
                $rows[] = [
                    'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0, 'label' => "Chapter {$n}",
                    'url' => 'https://example.test/' . md5($name) . "/{$n}", 'status' => 1, 'blacklist' => 0,
                    'download_date' => $at, 'created_at' => $at, 'updated_at' => $at,
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                NovelChapter::insert($chunk);
            }
            $chapters = NovelChapter::where('novel_id', $novel->id)->orderBy('chapter')->get()->keyBy(fn($c) => (int) $c->chapter)->all();
        }

        return [$novel, $chapters];
    }

    /** New chapters $from..$to, downloaded now (or queued: status 0). @return array<int, NovelChapter> */
    private function arrive(Novel $novel, int $from, int $to, int $status = 1): array
    {
        $out = [];
        foreach (range($from, $to) as $n) {
            $chapter = NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0, 'label' => "Chapter {$n}",
                'url' => 'https://example.test/' . md5($novel->name) . "/{$n}",
            ]);
            $chapter->forceFill(['status' => $status, 'download_date' => $status ? now() : null])->save();
            $out[$n] = $chapter;
        }

        return $out;
    }

    /** Reader state on chapters $from..$to: read and finished, an hour before now. */
    private function readUpTo(Novel $novel, int $to, ?User $user = null, int $from = 1, bool $manual = false): void
    {
        $user ??= $this->reader;
        $ids = NovelChapter::where('novel_id', $novel->id)->whereBetween('chapter', [$from, $to])->pluck('id');
        $at = now()->subHour();
        foreach ($ids as $id) {
            ChapterRead::create([
                'user_id' => $user->id, 'novel_id' => $novel->id, 'novel_chapter_id' => $id,
                'read_at' => $at, 'progress' => $manual ? null : 100, 'progress_at' => $manual ? null : $at,
                'manual' => $manual,
            ]);
        }
    }

    private function poll(?string $since = null, ?string $token = null)
    {
        return $this->asToken($token ?? $this->token)
            ->getJson('/api/v1/updates' . ($since === null ? '' : '?since=' . urlencode($since)));
    }

    /** A first poll (the cursor), then a minute passes. */
    private function cursorThenLater(?string $token = null): string
    {
        $cursor = $this->poll(null, $token)->assertOk()->assertJsonPath('series', [])->json('cursor');
        Carbon::setTestNow(now()->addMinute());

        return $cursor;
    }

    public function test_caught_up_then_one_new_chapter_is_listed_with_count_one(): void
    {
        [$novel] = $this->series('Caught Up', 5);
        $this->readUpTo($novel, 5);
        $cursor = $this->cursorThenLater();
        $new = $this->arrive($novel, 6, 6);
        Carbon::setTestNow(now()->addSecond());

        $series = $this->poll($cursor)->assertOk()->json('series');
        $this->assertSame([[
            'novel_id' => $novel->id, 'name' => 'Caught Up', 'medium' => 'novel', 'new_count' => 1,
            'chapter_id' => $new[6]->id, 'chapter_label' => 'Chapter 6',
            'latest_at' => $new[6]->fresh()->download_date->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]], $series);
    }

    public function test_three_arrive_count_three_and_first_chapter(): void
    {
        [$novel] = $this->series('Three', 4);
        $this->readUpTo($novel, 4);
        $cursor = $this->cursorThenLater();
        $new = $this->arrive($novel, 5, 7);
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()
            ->assertJsonCount(1, 'series')
            ->assertJsonPath('series.0.new_count', 3)
            ->assertJsonPath('series.0.chapter_id', $new[5]->id)
            ->assertJsonPath('series.0.chapter_label', 'Chapter 5');
    }

    public function test_the_next_poll_does_not_repeat_and_a_chapter_in_the_answer_second_goes_to_the_next_poll(): void
    {
        [$novel] = $this->series('Once', 3);
        $this->readUpTo($novel, 3);
        $cursor = $this->cursorThenLater();
        $this->arrive($novel, 4, 4);
        // Same second as the answer: not in this window.
        $first = $this->poll($cursor)->assertOk()->assertJsonPath('series', []);
        Carbon::setTestNow(now()->addSecond());
        $second = $this->poll($first->json('cursor'))->assertOk()->assertJsonCount(1, 'series');
        Carbon::setTestNow(now()->addMinute());
        $this->arrive($novel, 5, 5);
        Carbon::setTestNow(now()->addSecond());
        // Still unread from before: not caught up at `since`, so never again.
        $this->poll($second->json('cursor'))->assertOk()->assertJsonPath('series', []);
    }

    public function test_500_behind_with_backlog_downloading_is_not_listed(): void
    {
        [$novel] = $this->series('Far Behind', 600);
        $this->readUpTo($novel, 100);
        $cursor = $this->cursorThenLater();
        $this->arrive($novel, 601, 650);
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()->assertJsonPath('series', []);
    }

    public function test_never_started_is_not_listed(): void
    {
        [$novel] = $this->series('Untouched', 3);
        $cursor = $this->cursorThenLater();
        $this->arrive($novel, 4, 5);
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()->assertJsonPath('series', []);
    }

    public function test_a_backlog_chapter_behind_the_reader_downloaded_now_is_not_listed(): void
    {
        [$novel, $chapters] = $this->series('Backfill', 10);
        // Chapter 3 was never downloaded; the reader read past it.
        $chapters[3]->forceFill(['status' => 0, 'download_date' => null])->save();
        $this->readUpTo($novel, 10);
        ChapterRead::where('novel_chapter_id', $chapters[3]->id)->delete();
        $cursor = $this->cursorThenLater();
        $chapters[3]->forceFill(['status' => 1, 'download_date' => now()])->save();
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()->assertJsonPath('series', []);
    }

    public function test_an_unread_chapter_from_before_since_plus_a_new_one_is_not_listed(): void
    {
        [$novel] = $this->series('Had One Waiting', 5);
        $this->readUpTo($novel, 4);
        $cursor = $this->cursorThenLater();
        $this->arrive($novel, 6, 6);
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()->assertJsonPath('series', []);
    }

    public function test_a_new_gap_chapter_before_an_old_unread_one_is_not_listed(): void
    {
        // Chapter 6 was missing; chapter 7 was waiting unread all along.
        [$novel, $chapters] = $this->series('Gap Filled', 7);
        $chapters[6]->forceFill(['status' => 0, 'download_date' => null])->save();
        $this->readUpTo($novel, 5);
        $cursor = $this->cursorThenLater();
        $chapters[6]->forceFill(['status' => 1, 'download_date' => now()])->save();
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()->assertJsonPath('series', []);
    }

    public function test_part_way_through_the_last_chapter_then_a_new_one_is_listed(): void
    {
        [$novel, $chapters] = $this->series('Mid Chapter', 5);
        $this->readUpTo($novel, 4);
        ChapterRead::create([
            'user_id' => $this->reader->id, 'novel_id' => $novel->id, 'novel_chapter_id' => $chapters[5]->id,
            'read_at' => null, 'progress' => 40, 'progress_at' => now()->subMinutes(10),
        ]);
        $cursor = $this->cursorThenLater();
        // Nothing new yet: the half-read chapter itself is never "new".
        $again = $this->poll($cursor)->assertOk()->assertJsonPath('series', [])->json('cursor');
        Carbon::setTestNow(now()->addMinute());
        $new = $this->arrive($novel, 6, 6);
        Carbon::setTestNow(now()->addSecond());

        $this->poll($again)->assertOk()
            ->assertJsonCount(1, 'series')
            ->assertJsonPath('series.0.new_count', 1)
            ->assertJsonPath('series.0.chapter_id', $new[6]->id);
    }

    public function test_hand_marked_only_series_with_a_new_chapter_is_listed(): void
    {
        [$novel] = $this->series('Marked By Hand', 5);
        // "Mark all read": no activity, so ResumePoint answers START (first unread).
        $this->readUpTo($novel, 5, manual: true);
        $cursor = $this->cursorThenLater();
        $new = $this->arrive($novel, 6, 7);
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()
            ->assertJsonCount(1, 'series')
            ->assertJsonPath('series.0.new_count', 2)
            ->assertJsonPath('series.0.chapter_id', $new[6]->id);
    }

    public function test_queued_blacklisted_and_deleted_chapters_do_not_count(): void
    {
        [$novel] = $this->series('Filtered', 3);
        $this->readUpTo($novel, 3);
        [$gone] = $this->series('Deleted Series', 2);
        $this->readUpTo($gone, 2);
        $cursor = $this->cursorThenLater();
        $this->arrive($novel, 4, 4, status: 0);
        $this->arrive($novel, 5, 5)[5]->forceFill(['blacklist' => 1])->save();
        $this->arrive($novel, 6, 6)[6]->delete();
        $this->arrive($gone, 3, 3);
        $gone->delete();
        Carbon::setTestNow(now()->addSecond());

        $this->poll($cursor)->assertOk()->assertJsonPath('series', []);
    }

    public function test_without_since_empty_with_a_cursor(): void
    {
        [$novel] = $this->series('First Call', 2);
        $this->readUpTo($novel, 1);
        $this->arrive($novel, 3, 3);

        $response = $this->poll()->assertOk()->assertExactJson(['cursor' => $this->poll()->json('cursor'), 'series' => []]);
        $this->assertNotNull(NewChapterUpdates::decode($response->json('cursor')));
    }

    public function test_a_malformed_cursor_is_422(): void
    {
        foreach (['garbage', base64_encode('rn1:5'), str_repeat('a', 80), base64_encode('nu1:abc')] as $bad) {
            $this->poll($bad)->assertStatus(422)->assertJsonValidationErrors('since');
        }
    }

    public function test_another_readers_state_does_not_leak(): void
    {
        [$novel] = $this->series('Shared Series', 5);
        $kim = $this->makeReader('kim', 'Kim');
        $kimToken = $this->tokenFor($kim);
        // Kim is caught up; Sam has not started.
        $this->readUpTo($novel, 5, $kim);
        $samCursor = $this->poll()->json('cursor');
        $kimCursor = $this->poll(null, $kimToken)->json('cursor');
        Carbon::setTestNow(now()->addMinute());
        $this->arrive($novel, 6, 6);
        Carbon::setTestNow(now()->addSecond());

        $this->poll($samCursor)->assertOk()->assertJsonPath('series', []);
        $this->poll($kimCursor, $kimToken)->assertOk()->assertJsonCount(1, 'series');

        // Sam caught up on chapters 1–3 only: chapters 4–5 were waiting, not new.
        $this->readUpTo($novel, 3);
        $this->poll($samCursor)->assertOk()->assertJsonPath('series', []);
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->asNobody()->getJson('/api/v1/updates')->assertStatus(401);
    }

    public function test_newest_first_and_at_most_25(): void
    {
        $novels = [];
        foreach (range(1, 30) as $i) {
            [$novels[$i]] = $this->series("Series {$i}", 1, $i % 2 ? Novel::MEDIUM_NOVEL : Novel::MEDIUM_MANGA);
            $this->readUpTo($novels[$i], 1);
        }
        $cursor = $this->cursorThenLater();
        foreach ($novels as $i => $novel) {
            Carbon::setTestNow(now()->addSecond());
            $this->arrive($novel, 2, 2);
        }
        Carbon::setTestNow(now()->addSecond());

        $series = $this->poll($cursor)->assertOk()->json('series');
        $this->assertCount(NewChapterUpdates::LIMIT, $series);
        $this->assertSame(
            array_map(fn($i) => $novels[$i]->id, range(30, 6)),
            array_column($series, 'novel_id')
        );
        $this->assertSame('manga', $series[0]['medium']);
        $this->assertSame('novel', $series[1]['medium']);
        $times = array_column($series, 'latest_at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
    }

    public function test_meta_offers_updates(): void
    {
        $this->asNobody()->getJson('/api/v1/meta')->assertOk()->assertJsonPath('updates', true);
    }

    public function test_statement_count_is_constant_as_series_grow(): void
    {
        // Two readers following 4 and 16 series of the same mix: NEXT caught
        // up, START hand-marked, far behind, RESUME part-way through the last.
        $readers = ['small' => [$this->reader, $this->token, 4]];
        $big = $this->makeReader('kim', 'Kim');
        $readers['large'] = [$big, $this->tokenFor($big), 16];
        foreach ($readers as $key => [$user, $token, $n]) {
            foreach (range(1, $n) as $i) {
                [$novel, $chapters] = $this->series("{$key} {$i}", 4);
                match ($i % 4) {
                    0 => $this->readUpTo($novel, 4, $user),
                    1 => $this->readUpTo($novel, 4, $user, manual: true),
                    2 => $this->readUpTo($novel, 1, $user),
                    3 => ChapterRead::create([
                        'user_id' => $user->id, 'novel_id' => $novel->id, 'novel_chapter_id' => $chapters[4]->id,
                        'read_at' => null, 'progress' => 30, 'progress_at' => now()->subMinutes(5),
                    ]),
                };
                $readers[$key][] = $novel;
            }
        }
        $cursors = array_map(fn($r) => $this->poll(null, $r[1])->json('cursor'), $readers);
        Carbon::setTestNow(now()->addMinute());
        foreach ($readers as $r) {
            foreach (array_slice($r, 3) as $novel) {
                $this->arrive($novel, 5, 6);
            }
        }
        Carbon::setTestNow(now()->addSecond());

        $counting = false;
        $statements = 0;
        DB::listen(function () use (&$counting, &$statements) {
            if ($counting) {
                $statements++;
            }
        });
        $measured = [];
        foreach ($readers as $key => [$user, $token, $n]) {
            $this->poll($cursors[$key], $token)->assertOk(); // warm the auth path
            $statements = 0;
            $counting = true;
            $series = $this->poll($cursors[$key], $token)->assertOk()->json('series');
            $counting = false;
            $this->assertCount($n / 4 * 3, $series, $key);
            $measured[$key] = $statements;
        }
        $this->assertSame($measured['small'], $measured['large']);
    }
}
