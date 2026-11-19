<?php

namespace Tests\Feature;

use App\Bookmark;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET/POST /api/v1/highlights and DELETE /api/v1/highlights/{id}: the
 * website's bookmarks for the native apps.
 */
class ApiV1HighlightTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['id', 'novel_id', 'novel_name', 'chapter_id', 'chapter_label', 'excerpt', 'note', 'created_at'];

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private function chapter(?Novel $novel = null, float $number = 12, ?string $label = 'Chapter 12: The Gate'): NovelChapter
    {
        $novel ??= Novel::create(['name' => 'Highlight Novel', 'status' => 0, 'group_id' => 1]);

        return NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => $number, 'book' => 0,
            'label' => $label, 'url' => "https://example.test/{$novel->id}/c-{$number}",
        ]);
    }

    private function bookmark(NovelChapter $chapter, string $excerpt, ?string $note = null): Bookmark
    {
        return Bookmark::create(['user_id' => $this->owner()->id, 'novel_id' => $chapter->novel_id, 'novel_chapter_id' => $chapter->id, 'excerpt' => $excerpt, 'note' => $note]);
    }

    public function testPostCreatesAHighlight(): void
    {
        $chapter = $this->chapter();

        $response = $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => '  The gate opened.  ', 'note' => 'Big moment'])
            ->assertCreated();

        $highlight = $response->json('highlight');
        $this->assertSame(self::KEYS, array_keys($highlight));
        $this->assertSame([
            'id' => Bookmark::first()->id,
            'novel_id' => $chapter->novel_id,
            'novel_name' => 'Highlight Novel',
            'chapter_id' => $chapter->id,
            'chapter_label' => 'Chapter 12: The Gate',
            'excerpt' => 'The gate opened.',
            'note' => 'Big moment',
        ], array_diff_key($highlight, ['created_at' => 1]));
        $this->assertMatchesRegularExpression(self::TIMESTAMP, $highlight['created_at']);
        $this->assertSame(1, Bookmark::count());
    }

    public function testChapterLabelIsTheChapterEndpointsLabel(): void
    {
        $chapter = $this->chapter(number: 7.5, label: null);

        $label = $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => 'x', 'note' => null])
            ->assertCreated()
            ->json('highlight.chapter_label');

        $this->assertSame('Chapter 7.5', $label);
        $this->assertSame($label, $this->getJson("/api/v1/chapters/{$chapter->id}")->json('chapter.label'));
        $this->assertNull(Bookmark::first()->note);
    }

    public function testPostIsIdempotentAndUpdatesTheNote(): void
    {
        $chapter = $this->chapter();
        $id = $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => 'Same words'])->assertCreated()->json('highlight.id');

        // Same chapter + excerpt: 200 with the stored one.
        $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => 'Same words'])
            ->assertOk()
            ->assertJsonPath('highlight.id', $id)
            ->assertJsonPath('highlight.note', null);

        // A note sent updates it.
        $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => ' Same words ', 'note' => 'Now noted'])
            ->assertOk()
            ->assertJsonPath('highlight.id', $id)
            ->assertJsonPath('highlight.note', 'Now noted');

        // Without the "note" key the stored note is kept.
        $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => 'Same words'])
            ->assertOk()
            ->assertJsonPath('highlight.note', 'Now noted');

        $this->assertSame(1, Bookmark::count());
        $this->assertSame('Now noted', Bookmark::find($id)->note);

        // An explicit null (the note was removed on the device) clears it.
        $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => 'Same words', 'note' => null])
            ->assertOk()
            ->assertJsonPath('highlight.id', $id)
            ->assertJsonPath('highlight.note', null);
        $this->assertNull(Bookmark::find($id)->note);

        // Another excerpt, or the same excerpt on another chapter, is new.
        $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => 'Other words'])->assertCreated();
        $other = $this->chapter(Novel::find($chapter->novel_id), 13, 'Chapter 13');
        $this->postJson('/api/v1/highlights', ['chapter_id' => $other->id, 'excerpt' => 'Same words'])->assertCreated();
        $this->assertSame(3, Bookmark::count());
    }

    public function testPostValidation(): void
    {
        $chapter = $this->chapter();
        $deleted = $this->chapter(Novel::find($chapter->novel_id), 13, 'Chapter 13');
        $deleted->delete();

        $cases = [
            [[], ['chapter_id', 'excerpt']],
            [['chapter_id' => 999999, 'excerpt' => 'x'], ['chapter_id']],
            [['chapter_id' => $deleted->id, 'excerpt' => 'x'], ['chapter_id']],
            [['chapter_id' => 'abc', 'excerpt' => 'x'], ['chapter_id']],
            [['chapter_id' => $chapter->id, 'excerpt' => ''], ['excerpt']],
            [['chapter_id' => $chapter->id, 'excerpt' => '   '], ['excerpt']],
            [['chapter_id' => $chapter->id, 'excerpt' => str_repeat('x', 2001)], ['excerpt']],
            [['chapter_id' => $chapter->id, 'excerpt' => 'x', 'note' => str_repeat('n', 2001)], ['note']],
        ];
        foreach ($cases as [$body, $errors]) {
            $this->postJson('/api/v1/highlights', $body)->assertStatus(422)->assertJsonValidationErrors($errors);
        }

        // The limits themselves pass.
        $this->postJson('/api/v1/highlights', ['chapter_id' => $chapter->id, 'excerpt' => str_repeat('x', 2000), 'note' => str_repeat('n', 2000)])->assertCreated();
        // Without an Accept header too: JSON, not a redirect.
        $this->post('/api/v1/highlights', [])->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['chapter_id']]);
        $this->assertSame(1, Bookmark::count());
    }

    public function testListNewestFirstWithPagingAndNovelFilter(): void
    {
        $a = $this->chapter();
        $novelB = Novel::create(['name' => 'Second Novel', 'status' => 0, 'group_id' => 1]);
        $b = $this->chapter($novelB, 3, 'Chapter 3');

        $ids = [];
        foreach (range(1, 5) as $n) {
            $this->travel(1)->minutes();
            $ids[] = $this->bookmark($n % 2 ? $a : $b, "Excerpt {$n}")->id;
        }
        $newestFirst = array_reverse($ids);

        $all = $this->getJson('/api/v1/highlights')->assertOk();
        $this->assertSame(['highlights', 'page', 'has_more'], array_keys($all->json()));
        $this->assertSame($newestFirst, array_column($all->json('highlights'), 'id'));
        $this->assertSame(1, $all->json('page'));
        $this->assertFalse($all->json('has_more'));
        foreach ($all->json('highlights') as $h) {
            $this->assertSame(self::KEYS, array_keys($h));
        }
        $this->assertSame('Second Novel', $all->json('highlights.1.novel_name'));
        $this->assertSame('Chapter 3', $all->json('highlights.1.chapter_label'));

        $first = $this->getJson('/api/v1/highlights?per_page=2')->assertOk();
        $this->assertSame(array_slice($newestFirst, 0, 2), array_column($first->json('highlights'), 'id'));
        $this->assertTrue($first->json('has_more'));
        $third = $this->getJson('/api/v1/highlights?per_page=2&page=3')->assertOk();
        $this->assertSame(3, $third->json('page'));
        $this->assertSame([$newestFirst[4]], array_column($third->json('highlights'), 'id'));
        $this->assertFalse($third->json('has_more'));
        $this->getJson('/api/v1/highlights?per_page=2&page=9')->assertOk()->assertJsonCount(0, 'highlights')->assertJsonPath('has_more', false);

        $filtered = $this->getJson("/api/v1/highlights?novel_id={$novelB->id}")->assertOk();
        $this->assertSame([$ids[3], $ids[1]], array_column($filtered->json('highlights'), 'id'));
        $this->getJson('/api/v1/highlights?novel_id=999999')->assertOk()->assertExactJson(['highlights' => [], 'page' => 1, 'has_more' => false]);
    }

    public function testListLeavesOutHighlightsOfDeletedChapters(): void
    {
        $chapter = $this->chapter();
        $gone = $this->chapter(Novel::find($chapter->novel_id), 13, 'Chapter 13');
        $kept = $this->bookmark($chapter, 'kept');
        $this->bookmark($gone, 'orphan');
        $gone->delete();

        $this->getJson('/api/v1/highlights')->assertOk()->assertJsonCount(1, 'highlights')->assertJsonPath('highlights.0.id', $kept->id);
    }

    public function testListValidation(): void
    {
        $this->getJson('/api/v1/highlights?per_page=201')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/highlights?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/highlights?page=0')->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->getJson('/api/v1/highlights?novel_id=abc')->assertStatus(422)->assertJsonValidationErrors(['novel_id']);
        $this->getJson('/api/v1/highlights?per_page=200')->assertOk();
    }

    public function testDefaultPageSizeIsFifty(): void
    {
        $chapter = $this->chapter();
        foreach (range(1, 51) as $n) {
            $this->bookmark($chapter, "Excerpt {$n}");
        }

        $this->getJson('/api/v1/highlights')->assertOk()->assertJsonCount(50, 'highlights')->assertJsonPath('has_more', true);
    }

    public function testListQueryCountDoesNotGrowWithHighlights(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/highlights')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->bookmark($this->chapter(), 'one');
        $few = $count();

        for ($i = 0; $i < 10; $i++) {
            $novel = Novel::create(['name' => "Novel {$i}", 'status' => 0, 'group_id' => 1]);
            $this->bookmark($this->chapter($novel, $i + 1, null), "excerpt {$i}");
        }
        $many = $count();

        // The bookmarks, their novels, their chapters.
        $this->assertSame(3, $few);
        $this->assertSame($few, $many);
    }

    public function testDeleteIs204AlsoWhenAlreadyGone(): void
    {
        $chapter = $this->chapter();
        $keep = $this->bookmark($chapter, 'keep');
        $drop = $this->bookmark($chapter, 'drop');

        $this->deleteJson("/api/v1/highlights/{$drop->id}")->assertNoContent();
        $this->deleteJson("/api/v1/highlights/{$drop->id}")->assertNoContent();
        $this->deleteJson('/api/v1/highlights/999999')->assertNoContent();

        $this->assertSame([$keep->id], Bookmark::pluck('id')->all());
    }
}
