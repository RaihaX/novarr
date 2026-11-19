<?php

namespace Tests\Feature;

use App\Bookmark;
use App\Novel;
use App\NovelChapter;
use App\ReadingPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Look-up opens (`?lookup=1`): a chapter opened from the Highlights page or a
 * full-text search hit is checked, not read, so it must not become the
 * series' latest reading position (which the reader's other devices offer as
 * "Go to latest"). Ids are always taken from the created rows, never assumed.
 */
class ReadingPositionLookupTest extends TestCase
{
    use RefreshDatabase;

    private const KINDLE = ['X-Novarr-Device' => 'kindle_0001', 'X-Novarr-Device-Name' => 'Kindle'];

    private Novel $novel;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function novel(string $name = 'Lookup Test'): Novel
    {
        return $this->novel = Novel::create([
            'name' => $name,
            'author' => 'Author',
            'status' => 0,
            'group_id' => 42,
            'no_of_chapters' => 10,
        ]);
    }

    private function chapter(int $n, string $body = '<p>Once upon a time.</p>'): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $this->novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://example.test/{$this->novel->id}/chapter-{$n}",
        ]);
        $chapter->description = $body;
        $chapter->status = 1;
        $chapter->save();

        return $chapter->fresh();
    }

    private function position(): ?ReadingPosition
    {
        return ReadingPosition::where('novel_id', $this->novel->id)->first();
    }

    /** The reader state JSON embedded in a chapter page. */
    private function readerState($response): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="readerState">(.*?)</script>#s', $response->getContent(), $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function lookupUrl(NovelChapter $chapter): string
    {
        return route('chapters.show', ['id' => $chapter->id, 'lookup' => 1]);
    }

    public function test_a_normal_open_records_the_position(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);

        $state = $this->readerState($this->get(route('chapters.show', $chapter->id))->assertOk());

        $this->assertFalse($state['lookup']);
        $this->assertSame($chapter->id, $this->position()?->novel_chapter_id);
    }

    public function test_a_lookup_open_does_not_move_the_latest_position(): void
    {
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->novel();
        $old = $this->chapter(1);
        $latest = $this->chapter(5);

        // Read up to chapter 5 on the Kindle.
        $this->postJson(route('api.v1.chapters.progress', $latest->id), ['progress' => 60], self::KINDLE)->assertOk();

        Carbon::setTestNow('2026-11-18 11:00:00');
        $state = $this->readerState($this->get($this->lookupUrl($old))->assertOk());

        // The page knows it is a look-up, and still offers the latest position.
        $this->assertTrue($state['lookup']);
        $this->assertSame($latest->id, $state['latest_position']['chapter_id']);

        // ...and the series' latest position is untouched.
        $p = $this->position();
        $this->assertSame($latest->id, $p->novel_chapter_id);
        $this->assertSame(60, $p->progress);
        $this->assertSame('kindle_0001', $p->device_id);
        $this->assertSame('2026-11-18 10:00:00', $p->recorded_at->format('Y-m-d H:i:s'));
    }

    public function test_a_lookup_open_into_a_series_with_no_position_records_nothing(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);

        $this->get($this->lookupUrl($chapter))->assertOk();

        $this->assertNull($this->position());
    }

    public function test_a_lookup_open_still_marks_an_unread_chapter_read(): void
    {
        $this->novel();
        $looked = $this->chapter(1);
        $normal = $this->chapter(2);

        $this->get($this->lookupUrl($looked))->assertOk();
        $this->get(route('chapters.show', $normal->id))->assertOk();

        // Mark-read-on-open behaves the same with or without the marker.
        $this->assertNotNull($this->readAtOf($normal));
        $this->assertNotNull($this->readAtOf($looked));
    }

    public function test_navigation_out_of_a_lookup_chapter_is_a_normal_open(): void
    {
        $this->novel();
        $prev = $this->chapter(1);
        $looked = $this->chapter(2);
        $next = $this->chapter(3);

        $state = $this->readerState($this->get($this->lookupUrl($looked))->assertOk());

        // Next / previous links carry no marker, so reading on is tracked.
        $this->assertSame(route('chapters.show', $next->id), $state['next']['url']);
        $this->assertSame(route('chapters.show', $prev->id), $state['prev']['url']);
        $this->assertSame(route('chapters.show', $looked->id), $state['url']);
        $this->assertStringNotContainsString('lookup', $state['next']['url']);

        $this->get($state['next']['url'])->assertOk();
        $this->assertSame($next->id, $this->position()->novel_chapter_id);
    }

    public function test_a_lookup_mark_read_on_view_marks_read_without_moving_the_position(): void
    {
        $this->novel();
        $looked = $this->chapter(1);
        $latest = $this->chapter(5);
        $this->postJson(route('api.v1.chapters.progress', $latest->id), ['progress' => 60], self::KINDLE)->assertOk();

        // A look-up page served to a prefetch is marked read when shown.
        $this->postJson(route('chapters.progress', $looked->id), ['read' => true, 'lookup' => true])
            ->assertOk()->assertJson(['success' => true, 'read' => true]);

        $this->assertNotNull($this->readAtOf($looked));
        $this->assertSame($latest->id, $this->position()->novel_chapter_id);

        // Without the marker the same write still records as before.
        $this->postJson(route('chapters.progress', $looked->id), ['read' => true])->assertOk();
        $this->assertSame($looked->id, $this->position()->novel_chapter_id);
    }

    public function test_the_highlights_page_links_with_the_lookup_marker(): void
    {
        $this->novel();
        $chapter = $this->chapter(3);
        $bookmark = Bookmark::create([
            'user_id' => $this->owner()->id,
            'novel_id' => $chapter->novel_id,
            'novel_chapter_id' => $chapter->id,
            'excerpt' => 'A passage worth keeping',
        ]);

        $html = $this->get(route('bookmarks.index'))->assertOk()->getContent();

        // The link also names the highlight, so the reader opens at it.
        $url = route('chapters.show', ['id' => $chapter->id, 'lookup' => 1, 'highlight' => $bookmark->id]);
        $this->assertStringContainsString('href="' . e($url) . '"', $html);
        $this->assertStringNotContainsString('href="' . e(route('chapters.show', $chapter->id)) . '"', $html);
    }

    public function test_search_results_link_with_the_lookup_marker(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('InnoDB FULLTEXT cannot see rows from an uncommitted test transaction.');
        }

        $this->novel('Zeta Chronicle');
        $hit = $this->chapter(7, '<p>The dragon slept beneath the mountain.</p>');

        $html = $this->get(route('search.index', ['q' => 'dragon']))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . e($this->lookupUrl($hit)) . '"', $html);
        $this->assertStringNotContainsString('href="' . e(route('chapters.show', $hit->id)) . '"', $html);
    }

    public function test_a_palette_chapter_jump_is_navigation_not_a_lookup(): void
    {
        $this->novel('Zeta Chronicle');
        $chapter = $this->chapter(142);

        $chapters = $this->getJson(route('palette', ['q' => 'zeta 142']))->assertOk()->json('chapters');

        $this->assertSame([route('chapters.show', $chapter->id)], array_column($chapters, 'url'));
    }
}
