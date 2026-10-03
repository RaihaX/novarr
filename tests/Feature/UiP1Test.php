<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI audit P1: one progress definition across screens, unified search,
 * needs-attention snooze (never pauses), chromeless reader, mobile chapter list.
 */
class UiP1Test extends TestCase
{
    use RefreshDatabase;

    private function novel(array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => 'Progress Parity Novel',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 40,
        ], $attrs));
    }

    private function chapter(Novel $novel, int $n, bool $downloaded, int $book = 0): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => $book,
            'label' => "Chapter {$n}",
            'url' => "https://example.test/novel/chapter-{$n}",
        ]);
        if ($downloaded) {
            $chapter->description = '<p>Text of chapter ' . $n . '.</p>';
            $chapter->status = 1;
            $chapter->download_date = now();
            $chapter->save();
        }

        return $chapter;
    }

    /** Every `data-progress-percent>NN%` figure rendered on a page. */
    private function percents(string $html): array
    {
        preg_match_all('/data-progress-percent>(\d+)%/', $html, $m);

        return array_map('intval', $m[1]);
    }

    public function test_download_progress_definition(): void
    {
        // 20 downloaded of 323 advertised by the source → 6%, never 100%.
        $this->assertSame(['downloaded' => 20, 'total' => 323, 'percent' => 6], NovelHealth::downloadProgress(20, 20, 323));
        // TOC rows win when they exceed the advertised count.
        $this->assertSame(50, NovelHealth::downloadProgress(10, 20, 5)['percent']);
        $this->assertSame(0, NovelHealth::downloadProgress(0, 0, null)['percent']);
        $this->assertSame('downloaded', NovelHealth::progressState(100, false, false, false));
        $this->assertSame('attention', NovelHealth::progressState(40, false, false, true));
        $this->assertSame('queued', NovelHealth::progressState(40, false, false, false));
    }

    public function test_novel_page_and_novels_list_show_the_same_percentage(): void
    {
        $novel = $this->novel();
        foreach (range(1, 10) as $n) {
            $this->chapter($novel, $n, $n <= 4);   // 4 downloaded, 6 queued, 40 on source
        }

        $show = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();
        $list = $this->get(route('novels.index', ['view' => 'list']))->assertOk()->getContent();
        $grid = $this->get(route('novels.index', ['view' => 'grid']))->assertOk()->getContent();

        $this->assertSame([10], $this->percents($show));
        $this->assertSame([10], $this->percents($list));
        $this->assertStringContainsString('4 of 40 on source', $show);
        $this->assertStringContainsString('4 of 40 on source', $list);
        $this->assertStringContainsString('4 of 40 · 10%', $grid);
        // Download progress takes a status colour, never the indigo accent.
        $this->assertStringContainsString('progress progress-queued', $show);
    }

    public function test_search_finds_a_novel_by_a_word_of_its_title(): void
    {
        $this->novel(['name' => 'The Silent Sword Saint', 'author' => 'Mo Yan']);
        $this->novel(['name' => 'Unrelated Archivist']);

        $this->get(route('search.index', ['q' => 'sword']))
            ->assertOk()
            ->assertSeeInOrder(['Novels', 'The Silent Sword Saint', 'In chapters'])
            ->assertDontSee('Unrelated Archivist');

        // Author matches too.
        $this->get(route('search.index', ['q' => 'mo yan']))
            ->assertOk()
            ->assertSee('The Silent Sword Saint');
    }

    private function failingNovel(): Novel
    {
        $novel = $this->novel(['name' => 'Failing Source Novel']);
        $novel->scrape_failures = 12;
        $novel->save();
        $this->chapter($novel, 1, false);

        return $novel->fresh();
    }

    public function test_snooze_hides_from_attention_without_pausing(): void
    {
        $novel = $this->failingNovel();
        $health = app(NovelHealth::class);
        $this->assertContains($novel->id, array_column($health->needingAttention(), 'id'));

        $this->postJson(route('novels.attention_snooze', $novel->id))
            ->assertOk()
            ->assertJson(['success' => true, 'id' => $novel->id]);

        $fresh = $novel->fresh();
        $this->assertNull($fresh->paused_at, 'Snoozing must never pause the novel');
        $this->assertTrue(now()->addDays(7)->subMinute()->lt($fresh->attention_ignored_until));
        $this->assertNotContains($novel->id, array_column($health->needingAttention(), 'id'));

        // Expired snooze → back in the list.
        $fresh->attention_ignored_until = now()->subMinute();
        $fresh->save();
        $this->assertContains($novel->id, array_column($health->needingAttention(), 'id'));
    }

    public function test_snooze_days_are_validated(): void
    {
        $novel = $this->failingNovel();

        $this->postJson(route('novels.attention_snooze', $novel->id), ['days' => -1])->assertStatus(422);
        $this->postJson(route('novels.attention_snooze', $novel->id), ['days' => 91])->assertStatus(422);
        $this->postJson(route('novels.attention_snooze', $novel->id), ['days' => 30])->assertOk();
        $this->assertTrue(now()->addDays(29)->lt($novel->fresh()->attention_ignored_until));

        // days=0 wakes the novel: the snooze is cleared and it is listed again.
        $this->postJson(route('novels.attention_snooze', $novel->id), ['days' => 0])
            ->assertOk()
            ->assertJsonPath('until', null);
        $this->assertNull($novel->fresh()->attention_ignored_until);
    }

    public function test_dashboard_offers_snooze_not_pause(): void
    {
        $this->failingNovel();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Snooze 7 days')
            ->assertDontSee('toggle-pause', false);
    }

    public function test_reader_renders_without_the_global_navbar(): void
    {
        $novel = $this->novel();
        $chapter = $this->chapter($novel, 1, true);

        $this->get(route('chapters.show', $chapter->id))
            ->assertOk()
            ->assertSee('is-chromeless', false)
            ->assertDontSee('navbar navbar-expand-lg', false);

        $this->get(route('novels.show', $novel->id))
            ->assertSee('navbar navbar-expand-lg', false);
    }

    public function test_book_column_only_when_a_chapter_has_a_book(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1, true);
        $this->get(route('novels.show', $novel->id))->assertOk()->assertDontSee('class="ch-book"', false);

        $this->chapter($novel, 2, true, 2);
        $this->get(route('novels.show', $novel->id))->assertOk()->assertSee('class="ch-book"', false);
    }
}
