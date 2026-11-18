<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Offline read-state writes are replayed later with `age` (ms since the
 * client queued them, on its own clock); the server dates them now - age.
 * A position recorded before the stored one must not overwrite it, and
 * explicit marks are stamped with their recorded time too.
 */
class ChapterProgressStaleWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function chapter(): NovelChapter
    {
        $novel = Novel::create([
            'name' => 'Progress Test',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 1,
        ]);

        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => 1,
            'book' => 0,
            'label' => 'Chapter 1',
            'url' => 'https://example.test/novel/chapter-1',
        ]);
        $chapter->status = 1;
        $chapter->save();

        return $chapter->fresh();
    }

    private function progress(NovelChapter $chapter, array $body)
    {
        return $this->postJson(route('chapters.progress', $chapter->id), $body);
    }

    private const MIN = 60_000;

    private function stamp(NovelChapter $chapter): string
    {
        return Carbon::parse($this->readStateOf($chapter)?->progress_at)->format('Y-m-d H:i:s');
    }

    public function test_a_live_write_applies_and_records_now(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $chapter = $this->chapter();

        $this->progress($chapter, ['progress' => 40])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(40, (int) $this->progressOf($chapter));
        $this->assertSame('2026-11-14 12:00:00', $this->stamp($chapter));
    }

    public function test_an_older_replayed_write_is_ignored_but_answered_with_success(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $chapter = $this->chapter();
        $this->progress($chapter, ['progress' => 80])->assertOk();

        // At 12:30, a position queued 90 minutes ago (11:00).
        Carbon::setTestNow('2026-11-14 12:30:00');
        $this->progress($chapter, ['progress' => 20, 'age' => 90 * self::MIN])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(80, (int) $this->progressOf($chapter));
        $this->assertSame('2026-11-14 12:00:00', $this->stamp($chapter));
    }

    public function test_a_newer_replayed_write_applies_with_its_recorded_time(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $chapter = $this->chapter();
        $this->progress($chapter, ['progress' => 30])->assertOk();

        Carbon::setTestNow('2026-11-14 13:00:00');
        $this->progress($chapter, ['progress' => 60, 'age' => 45 * self::MIN])->assertOk();

        $this->assertSame(60, (int) $this->progressOf($chapter));
        $this->assertSame('2026-11-14 12:15:00', $this->stamp($chapter));

        // A second queued write recorded between the two (12:10) is now stale.
        $this->progress($chapter, ['progress' => 45, 'age' => 50 * self::MIN])->assertOk();
        $this->assertSame(60, (int) $this->progressOf($chapter));
    }

    public function test_the_client_clock_does_not_matter(): void
    {
        // Whatever the phone's clock said, only the age is sent: a replay can
        // never be dated in the server's future, so live writes keep applying.
        Carbon::setTestNow('2026-11-14 12:00:00');
        $chapter = $this->chapter();
        $this->progress($chapter, ['progress' => 70, 'age' => 0])->assertOk();
        $this->assertSame('2026-11-14 12:00:00', $this->stamp($chapter));

        Carbon::setTestNow('2026-11-14 12:00:30');
        $this->progress($chapter, ['progress' => 10])->assertOk();
        $this->assertSame(10, (int) $this->progressOf($chapter));
    }

    public function test_age_is_capped_at_thirty_days(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $chapter = $this->chapter();

        $this->progress($chapter, ['progress' => 50, 'age' => 400 * 24 * 60 * self::MIN])->assertOk();
        $this->assertSame(50, (int) $this->progressOf($chapter));
        $this->assertSame('2026-10-15 12:00:00', $this->stamp($chapter));
    }

    public function test_age_must_be_a_non_negative_integer(): void
    {
        $chapter = $this->chapter();

        foreach (['soon', -1, 1.5] as $age) {
            $this->progress($chapter, ['progress' => 50, 'age' => $age])
                ->assertStatus(422)
                ->assertJsonValidationErrors('age');
        }
        $this->postJson(route('chapters.toggle_read', $chapter->id), ['age' => -5])
            ->assertStatus(422)->assertJsonValidationErrors('age');
        $this->assertNull($this->readAtOf($chapter), 'a rejected toggle changed nothing');
    }

    public function test_a_stale_position_still_carries_its_read_mark(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $chapter = $this->chapter();
        $this->progress($chapter, ['progress' => 90])->assertOk();
        $state = $this->readStateOf($chapter);
        $this->setReadState($chapter, ['read_at' => null, 'progress' => $state->progress, 'progress_at' => $state->progress_at]);

        $this->progress($chapter, ['progress' => 5, 'read' => true, 'age' => 60 * self::MIN])
            ->assertOk()
            ->assertJson(['read' => true]);

        $fresh = $this->readStateOf($chapter);
        $this->assertSame(90, (int) $fresh->progress);
        $this->assertNotNull($fresh->read_at);
    }

    public function test_a_queued_position_older_than_an_explicit_mark_is_ignored(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $chapter = $this->chapter();

        // Marked read on another device at 12:00...
        $this->postJson(route('chapters.toggle_read', $chapter->id))->assertOk();
        $this->assertSame(100, (int) $this->progressOf($chapter));

        // ...then at 12:05 a phone replays a scroll position from 11:50.
        Carbon::setTestNow('2026-11-14 12:05:00');
        $this->progress($chapter, ['progress' => 30, 'age' => 15 * self::MIN])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(100, (int) $this->progressOf($chapter));
    }

    /**
     * The reviewer's scenario: offline, mark a chapter unread (toggle-read)
     * at 09:00, then read it to 40% at 09:05; reconnect at 10:05. The marks
     * replay in order, each dated by its age, so the 40% is newer and stays.
     */
    public function test_offline_unmark_then_read_to_40_percent_keeps_the_40(): void
    {
        Carbon::setTestNow('2026-11-14 08:00:00');
        $chapter = $this->chapter();
        $this->postJson(route('chapters.toggle_read', $chapter->id))->assertOk(); // read online
        $this->assertNotNull($this->readAtOf($chapter));

        Carbon::setTestNow('2026-11-14 10:05:00');
        $this->postJson(route('chapters.toggle_read', $chapter->id), ['age' => 65 * self::MIN])
            ->assertOk()->assertJson(['read' => false]);
        $this->assertSame('2026-11-14 09:00:00', $this->stamp($chapter));
        $this->progress($chapter, ['progress' => 40, 'age' => 60 * self::MIN])->assertOk();

        $fresh = $this->readStateOf($chapter);
        $this->assertNull($fresh->read_at);
        $this->assertSame(40, (int) $fresh->progress);
        $this->assertSame('2026-11-14 09:05:00', $this->stamp($chapter));
    }

    public function test_offline_mark_to_here_then_read_on_keeps_the_position(): void
    {
        Carbon::setTestNow('2026-11-14 10:05:00');
        $chapter = $this->chapter();

        $this->postJson(route('chapters.read_through', $chapter->id), ['age' => 65 * self::MIN])->assertOk();
        $this->assertSame('2026-11-14 09:00:00', $this->stamp($chapter));
        $this->progress($chapter, ['progress' => 40, 'age' => 60 * self::MIN])->assertOk();

        $this->assertSame(40, (int) $this->progressOf($chapter));
    }

    public function test_bulk_read_stamps_its_recorded_time(): void
    {
        Carbon::setTestNow('2026-11-14 10:05:00');
        $chapter = $this->chapter();

        $this->postJson(route('chapters.bulk_read'), ['ids' => [$chapter->id], 'read' => false, 'age' => 65 * self::MIN])
            ->assertOk();
        $this->assertSame('2026-11-14 09:00:00', $this->stamp($chapter));
        $this->progress($chapter, ['progress' => 40, 'age' => 60 * self::MIN])->assertOk();
        $this->assertSame(40, (int) $this->progressOf($chapter));

        // Without age a bulk mark is "now".
        $this->postJson(route('chapters.bulk_read'), ['ids' => [$chapter->id], 'read' => true])->assertOk();
        $this->assertSame('2026-11-14 10:05:00', $this->stamp($chapter));
    }
}
