<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use App\Services\ReadState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Regression for "the Android app never marks chapters read": a signed-in
 * reader's app writes (bearer token, device headers, queued `age`) must set
 * read_at, both with the explicit read flag and — the server-side safety —
 * with a finished position (ReadState::FINISHED_PROGRESS) alone. Look-ups
 * and unfinished positions never mark.
 */
class AndroidReadMarkTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const HEADERS = [
        'X-Novarr-Device' => '6b0f6a3e-android',
        'X-Novarr-Device-Name' => 'Pixel 10 Pro',
        'User-Agent' => 'okhttp/5.3.2',
    ];

    private string $token;

    private int $readerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSignIn();
        $reader = $this->makeReader();
        $this->readerId = $reader->id;
        $this->token = $this->tokenFor($reader, 'Novarr app on Android');
    }

    private function chapter(int $n = 1, string $medium = Novel::MEDIUM_MANGA): NovelChapter
    {
        $novel = Novel::create(['name' => "Series {$n}", 'author' => 'A', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 1]);
        $novel->forceFill(['medium' => $medium])->save();
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'book' => 0, 'label' => 'Chapter 1', 'url' => "https://example.test/{$n}/1"]);
        $chapter->forceFill(['status' => 1, 'download_date' => now()])->save();

        return $chapter;
    }

    private function write(NovelChapter $chapter, array $body)
    {
        return $this->asToken($this->token)->withHeaders(self::HEADERS)->postJson("/api/v1/chapters/{$chapter->id}/progress", $body);
    }

    private function row(NovelChapter $chapter): ?object
    {
        return DB::table('chapter_reads')->where('user_id', $this->readerId)->where('novel_chapter_id', $chapter->id)->first();
    }

    public function test_positions_then_the_read_flag_mark_the_chapter_read(): void
    {
        $chapter = $this->chapter();
        $this->write($chapter, ['progress' => 27, 'age' => 30])->assertOk()->assertJson(['read' => false]);
        $this->write($chapter, ['progress' => 86, 'age' => 10])->assertOk()->assertJson(['read' => false]);
        $this->write($chapter, ['progress' => 100, 'read' => true, 'age' => 5])->assertOk()->assertExactJson(['success' => true, 'read' => true]);

        $row = $this->row($chapter);
        $this->assertNotNull($row->read_at);
        $this->assertSame(100, (int) $row->progress);
        $this->assertSame(0, (int) $row->manual, 'finishing a chapter is reading, not a hand mark');
    }

    public function test_an_offline_read_mark_replayed_hours_later_still_marks(): void
    {
        $chapter = $this->chapter();
        $this->write($chapter, ['progress' => 50])->assertOk();
        // Queued 3 hours ago: older than the stored position, so its position is stale; the mark still applies.
        $this->write($chapter, ['progress' => 100, 'read' => true, 'age' => 3 * 3600 * 1000])->assertOk()->assertJson(['read' => true]);
        $this->assertNotNull($this->row($chapter)->read_at);
    }

    public function test_a_finished_position_alone_marks_read(): void
    {
        $finished = $this->chapter(1);
        $this->write($finished, ['progress' => ReadState::FINISHED_PROGRESS])->assertOk()->assertJson(['read' => true]);
        $this->assertNotNull($this->row($finished)->read_at);

        $almost = $this->chapter(2, Novel::MEDIUM_NOVEL);
        $this->write($almost, ['progress' => ReadState::FINISHED_PROGRESS - 1])->assertOk()->assertJson(['read' => false]);
        $this->assertNull($this->row($almost)->read_at);
    }

    public function test_a_look_up_never_marks_from_its_position(): void
    {
        $chapter = $this->chapter(3, Novel::MEDIUM_BOOK);
        $this->write($chapter, ['progress' => 100, 'lookup' => true])->assertOk()->assertJson(['read' => false]);
        $this->assertNull($this->row($chapter)?->read_at);
    }

    public function test_a_chapter_not_downloaded_is_never_marked(): void
    {
        $chapter = $this->chapter(4);
        $chapter->forceFill(['status' => 0])->save();
        $this->write($chapter, ['progress' => 100, 'read' => true])->assertOk()->assertJson(['read' => false]);
    }

    public function test_the_owner_marks_too_and_readers_stay_separate(): void
    {
        $chapter = $this->chapter(5);
        $this->write($chapter, ['progress' => 100, 'read' => true])->assertOk();
        $this->assertSame(1, DB::table('chapter_reads')->where('novel_chapter_id', $chapter->id)->whereNotNull('read_at')->count());
        $this->assertNull(DB::table('chapter_reads')->where('user_id', $this->owner()->id)->where('novel_chapter_id', $chapter->id)->first());
    }
}
