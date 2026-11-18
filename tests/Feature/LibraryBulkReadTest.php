<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Library selection bar: "Mark all read" marks every chapter of the selected
 * novels, and the table is not its own scroll box.
 */
class LibraryBulkReadTest extends TestCase
{
    use RefreshDatabase;

    private function novel(string $name): Novel
    {
        return Novel::create(['name' => $name, 'author' => 'Someone', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);
    }

    private function chapter(Novel $novel, int $n, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
            'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/c-{$n}",
        ]);
        $chapter->forceFill(array_merge(['status' => 1], $attrs))->save();

        return $chapter;
    }

    public function test_bulk_read_marks_every_chapter_of_the_selected_novels_only(): void
    {
        $a = $this->novel('Selected A');
        $b = $this->novel('Selected B');
        $other = $this->novel('Not selected');

        $earlier = now()->subDays(40)->startOfSecond();
        $kept = $this->chapter($a, 1);
        $this->markRead($kept, null, $earlier, 100);
        $this->chapter($a, 2);
        $skipped = $this->chapter($a, 3, ['blacklist' => 1]);
        $this->chapter($b, 1);
        $untouched = $this->chapter($other, 1);

        $this->postJson(route('novels.bulk'), ['action' => 'read', 'ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 2, 'chapters' => 2]);

        $this->assertSame(3, $this->readCount($a->id) + $this->readCount($b->id), 'every non-blacklisted chapter is read');
        // A chapter read earlier keeps its date.
        $this->assertTrue($earlier->equalTo($this->readAtOf($kept)));
        $this->assertNull($this->readAtOf($skipped));
        $this->assertNull($this->readAtOf($untouched));

        // The Library shows the new state straight away.
        $this->get(route('novels.index', ['view' => 'table']))
            ->assertOk()
            ->assertSee('2 of 2 chapters read', false);
    }

    public function test_bulk_rejects_an_unknown_action_and_unknown_novels(): void
    {
        $novel = $this->novel('Only one');

        $this->postJson(route('novels.bulk'), ['action' => 'unread', 'ids' => [$novel->id]])->assertStatus(422);
        $this->postJson(route('novels.bulk'), ['action' => 'read', 'ids' => [$novel->id + 999]])->assertStatus(422);
    }

    public function test_the_selection_bar_offers_mark_all_read(): void
    {
        $this->chapter($this->novel('Listed'), 1);

        $this->get(route('novels.index', ['view' => 'table']))
            ->assertOk()
            ->assertSee('id="bulkRead"', false)
            ->assertSee('Mark all read');
    }
}
