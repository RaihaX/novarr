<?php

namespace Tests\Feature;

use App\Bookmark;
use App\Http\Controllers\NovelChapterController;
use App\Novel;
use App\NovelChapter;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The text reader's next-chapter state (a pending next chapter is not a
 * place to go), its saved highlights embedded in the reader state (each
 * reader sees only their own), opening a highlight at its passage, and
 * "Mark to here"'s count. Ids are always taken from the created rows.
 */
class ReaderNextAndHighlightsTest extends TestCase
{
    use RefreshDatabase;

    private Novel $novel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->novel = Novel::create(['name' => 'Next Test', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 4]);
    }

    private function chapter(int $n, bool $downloaded = true): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $this->novel->id, 'chapter' => $n, 'book' => 0,
            'label' => "Chapter {$n}", 'url' => "https://example.test/next/{$n}",
        ]);
        if ($downloaded) {
            $chapter->description = "<p>The words of chapter {$n}, with a <em>passage</em> worth keeping.</p>";
            $chapter->forceFill(['status' => 1, 'download_date' => now()])->save();
        }

        return $chapter->fresh();
    }

    private function readerState($response): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="readerState">(.*?)</script>#s', $response->getContent(), $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function highlight(User $user, NovelChapter $chapter, string $excerpt, ?string $note = null): Bookmark
    {
        return Bookmark::create([
            'user_id' => $user->id, 'novel_id' => $chapter->novel_id, 'novel_chapter_id' => $chapter->id,
            'excerpt' => $excerpt, 'note' => $note,
        ]);
    }

    // -----------------------------------------------------------------
    // Next chapter: downloaded or not
    // -----------------------------------------------------------------

    public function test_neighbours_expose_the_download_status(): void
    {
        $one = $this->chapter(1);
        $two = $this->chapter(2, downloaded: false);

        [$prev, $next] = NovelChapterController::neighbours($one);
        $this->assertNull($prev);
        $this->assertSame($two->id, $next->id);
        $this->assertFalse((bool) $next->status);

        $this->assertSame([
            'id' => $two->id, 'chapter' => $two->chapter, 'label' => 'Chapter 2',
            'url' => route('chapters.show', $two->id), 'downloaded' => false,
        ], NovelChapterController::neighbourState($next));
        $this->assertNull(NovelChapterController::neighbourState(null));
    }

    public function test_a_downloaded_next_chapter_is_continued_to(): void
    {
        $one = $this->chapter(1);
        $two = $this->chapter(2);

        $response = $this->get(route('chapters.show', $one->id))->assertOk();
        $state = $this->readerState($response);

        $this->assertSame($two->id, $state['next']['id']);
        $this->assertTrue($state['next']['downloaded']);
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('chapters.show', $two->id), '#') . '" id="nextChapterCta" class="reader-continue ">#', $html);
        $this->assertMatchesRegularExpression('#id="markReadStay" class="reader-continue d-none"#', $html);
        $this->assertMatchesRegularExpression('#id="navNextPending" class="[^"]*\bd-none\b#', $html);
        $this->assertStringContainsString('<link rel="prefetch" href="' . route('chapters.show', $two->id) . '">', $html);
    }

    public function test_a_next_chapter_not_downloaded_offers_mark_read_and_a_check_instead(): void
    {
        $one = $this->chapter(1);
        $two = $this->chapter(2, downloaded: false);

        $response = $this->get(route('chapters.show', $one->id))->assertOk();
        $state = $this->readerState($response);

        $this->assertSame($two->id, $state['next']['id']);
        $this->assertFalse($state['next']['downloaded']);

        $html = $response->getContent();
        // "Mark read" (stay) shown, "Mark read & continue" and the Next link hidden.
        $this->assertMatchesRegularExpression('#id="nextChapterCta" class="reader-continue d-none"#', $html);
        $this->assertMatchesRegularExpression('#id="markReadStay" class="reader-continue "#', $html);
        $this->assertMatchesRegularExpression('#id="navNext" class="[^"]*\bd-none\b#', $html);
        // The Next block is text with the label, "not downloaded yet" and a check.
        $this->assertMatchesRegularExpression('#id="navNextPending" class="reader-navblock reader-navblock-next is-pending "#', $html);
        $pending = substr($html, strpos($html, 'id="navNextPending"'), 1200);
        $this->assertStringContainsString('Chapter 2</span> &mdash; not downloaded yet', $pending);
        $this->assertStringContainsString('data-check-novel="' . $this->novel->id . '"', $pending);
        $this->assertStringNotContainsString('<a ', $pending);
        // Nothing prefetches a chapter with no body.
        $this->assertStringNotContainsString('<link rel="prefetch" href="' . route('chapters.show', $two->id) . '">', $html);
    }

    public function test_the_last_chapter_has_no_next(): void
    {
        $this->chapter(1);
        $two = $this->chapter(2);

        $response = $this->get(route('chapters.show', $two->id))->assertOk();
        $this->assertNull($this->readerState($response)['next']);
        $this->assertStringContainsString('id="endOfNovelNote"', $response->getContent());
        $this->assertStringNotContainsString('id="markReadStay"', $response->getContent());
    }

    public function test_the_empty_chapter_state_offers_check_for_new_chapters(): void
    {
        $this->chapter(1);
        $pending = $this->chapter(2, downloaded: false);

        $html = $this->get(route('chapters.show', $pending->id))->assertOk()->getContent();
        $empty = substr($html, strpos($html, 'class="reader-empty"'), 2000);

        $this->assertStringContainsString('No content available for this chapter yet.', $empty);
        $this->assertStringContainsString('data-check-novel="' . $this->novel->id . '"', $empty);
        $this->assertStringContainsString('Check for new chapters', $empty);
    }

    public function test_the_check_the_reader_buttons_use_is_open_to_readers(): void
    {
        $this->assertSame('reader', \App\Support\RouteAccess::classifyKey('api.v1.novels.check.start'));
        $this->assertSame('reader', \App\Support\RouteAccess::classifyKey('api.v1.novels.check'));
    }

    // -----------------------------------------------------------------
    // Highlights in the reader state
    // -----------------------------------------------------------------

    public function test_the_reader_state_carries_only_this_readers_highlights_of_this_chapter(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create(['name' => 'Second Reader']);
        $one = $this->chapter(1);
        $two = $this->chapter(2);

        $mine = $this->highlight($owner, $one, 'a passage worth keeping', 'Remember this');
        $mineLater = $this->highlight($owner, $one, 'The words of chapter 1');
        $this->highlight($owner, $two, 'chapter 2');                  // another chapter
        $theirs = $this->highlight($other, $one, 'worth keeping', 'Not yours');

        $this->actAsReader($owner);
        $state = $this->readerState($this->get(route('chapters.show', $one->id))->assertOk());
        $this->assertSame([
            ['id' => $mine->id, 'excerpt' => 'a passage worth keeping', 'note' => 'Remember this'],
            ['id' => $mineLater->id, 'excerpt' => 'The words of chapter 1', 'note' => null],
        ], $state['highlights']);

        $this->actAsReader($other);
        $response = $this->get(route('chapters.show', $one->id))->assertOk();
        $state = $this->readerState($response);
        $this->assertSame([['id' => $theirs->id, 'excerpt' => 'worth keeping', 'note' => 'Not yours']], $state['highlights']);
        $this->assertStringNotContainsString('Remember this', $response->getContent());
    }

    public function test_a_chapter_fetched_for_continuous_reading_carries_its_highlights(): void
    {
        $owner = $this->owner();
        $this->chapter(1);
        $two = $this->chapter(2);
        $hl = $this->highlight($owner, $two, 'passage');

        $this->actAsReader($owner);
        $state = $this->readerState(
            $this->withHeaders(['X-Novarr-Fetch' => 'continuous'])->get(route('chapters.show', $two->id))->assertOk()
        );

        $this->assertSame([['id' => $hl->id, 'excerpt' => 'passage', 'note' => null]], $state['highlights']);
    }

    public function test_no_highlights_is_an_empty_list(): void
    {
        $one = $this->chapter(1);

        $state = $this->readerState($this->get(route('chapters.show', $one->id))->assertOk());

        $this->assertSame([], $state['highlights']);
        $this->assertNull($state['focusHighlight']);
    }

    public function test_opening_from_the_highlights_page_names_the_highlight(): void
    {
        $owner = $this->owner();
        $one = $this->chapter(1);
        $hl = $this->highlight($owner, $one, 'passage');

        $this->actAsReader($owner);
        $html = $this->get(route('bookmarks.index'))->assertOk()->getContent();
        $url = route('chapters.show', ['id' => $one->id, 'lookup' => 1, 'highlight' => $hl->id]);
        $this->assertStringContainsString('href="' . e($url) . '"', $html);

        $state = $this->readerState($this->get($url)->assertOk());
        $this->assertSame($hl->id, $state['focusHighlight']);
        $this->assertTrue($state['lookup']);
    }

    public function test_the_highlights_page_says_highlight_throughout(): void
    {
        $owner = $this->owner();
        $one = $this->chapter(1);
        $this->highlight($owner, $one, 'passage');

        $this->actAsReader($owner);
        $html = $this->get(route('bookmarks.index'))->assertOk()->getContent();

        $this->assertStringContainsString('1 highlight</span>', $html);
        $this->assertStringContainsString('Highlight deleted.', $html);
        $this->assertStringNotContainsString('Bookmark deleted', $html);
        // The reader's save button uses the same word.
        $reader = $this->get(route('chapters.show', $one->id))->assertOk()->getContent();
        $this->assertStringContainsString('>Save highlight</button>', $reader);
    }

    public function test_a_reader_cannot_delete_another_readers_highlight(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create(['name' => 'Second Reader']);
        $one = $this->chapter(1);
        $theirs = $this->highlight($other, $one, 'passage');

        $this->actAsReader($owner);
        $this->deleteJson(route('bookmarks.destroy', $theirs->id))->assertNotFound();
        $this->assertNotNull(Bookmark::find($theirs->id));

        $this->actAsReader($other);
        $this->deleteJson(route('bookmarks.destroy', $theirs->id))->assertOk()->assertJson(['success' => true]);
        $this->assertNull(Bookmark::find($theirs->id));
    }

    // -----------------------------------------------------------------
    // "Mark to here"
    // -----------------------------------------------------------------

    public function test_mark_to_here_dry_run_counts_without_marking_then_the_mark_reports_the_count(): void
    {
        $chapters = [];
        foreach (range(1, 4) as $n) {
            $chapters[$n] = $this->chapter($n, downloaded: $n !== 2);
        }
        $this->markRead($chapters[1]);

        // Chapter 3 and earlier: 1 is read, 2 is not downloaded → only 3 counts.
        $this->postJson(route('chapters.read_through', $chapters[3]->id), ['dry_run' => true])
            ->assertOk()->assertJson(['success' => true, 'dry_run' => true, 'marked' => 1]);
        $this->assertNull($this->readStateOf($chapters[3])?->read_at);

        $this->postJson(route('chapters.read_through', $chapters[3]->id))
            ->assertOk()->assertJson(['success' => true, 'marked' => 1]);
        $this->assertNotNull($this->readStateOf($chapters[3])?->read_at);
        $this->assertNull($this->readStateOf($chapters[4])?->read_at);

        $this->postJson(route('chapters.read_through', $chapters[3]->id), ['dry_run' => true])
            ->assertOk()->assertJson(['marked' => 0]);
    }
}
