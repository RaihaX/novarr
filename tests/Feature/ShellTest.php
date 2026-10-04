<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App shell: command palette endpoint, phone tab bar + More sheet, and the
 * light/dark theme plumbing in layouts/app.blade.php.
 */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    private function novel(string $name, ?string $author = null): Novel
    {
        return Novel::create([
            'name' => $name,
            'author' => $author,
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
        ]);
    }

    private function chapter(Novel $novel, int $n, bool $read = false): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n} - The Road",
            'url' => "https://example.test/shell/{$novel->id}/chapter-{$n}",
        ]);
        $chapter->description = '<p>Body of chapter ' . $n . '.</p>';
        $chapter->status = 1;
        $chapter->download_date = now();
        $chapter->read_at = $read ? now() : null;
        $chapter->save();

        return $chapter;
    }

    // ---- GET /palette ------------------------------------------------------

    public function test_palette_returns_novels_with_progress_in_the_documented_shape(): void
    {
        $novel = $this->novel('Ascending the Nine Heavens', 'Feng Qingyang');
        $this->chapter($novel, 1, read: true);
        $this->chapter($novel, 2, read: true);
        $this->chapter($novel, 3);
        $this->chapter($novel, 4);
        $this->novel('Unrelated Tale');

        $this->getJson('/palette?q=ascend')
            ->assertOk()
            ->assertExactJson([
                'novels' => [[
                    'id' => $novel->id,
                    'name' => 'Ascending the Nine Heavens',
                    'author' => 'Feng Qingyang',
                    'origin_label' => null,
                    'url' => route('novels.show', $novel->id),
                    'progress' => 50,
                ]],
                'chapters' => [],
            ]);
    }

    public function test_palette_matches_author_and_every_word(): void
    {
        $this->novel('Ascending the Nine Heavens', 'Feng Qingyang');
        $this->novel('Nine Suns', 'Someone Else');

        $this->getJson('/palette?q=feng')->assertJsonPath('novels.0.name', 'Ascending the Nine Heavens');
        $this->getJson('/palette?q=nine heav')->assertJsonCount(1, 'novels');
        $this->getJson('/palette?q=nine')->assertJsonCount(2, 'novels');
    }

    public function test_palette_resolves_novel_words_plus_a_number_to_that_chapter(): void
    {
        $novel = $this->novel('Ascending the Nine Heavens');
        $this->chapter($novel, 141);
        $target = $this->chapter($novel, 142, read: true);

        $response = $this->getJson('/palette?q=ascending 142')->assertOk();
        $response->assertJsonCount(1, 'chapters')
            ->assertJsonPath('chapters.0.id', $target->id)
            ->assertJsonPath('chapters.0.novel', 'Ascending the Nine Heavens')
            ->assertJsonPath('chapters.0.number', 142)
            ->assertJsonPath('chapters.0.url', route('chapters.show', $target->id))
            ->assertJsonPath('chapters.0.downloaded', true)
            ->assertJsonPath('chapters.0.read', true);

        // "ch 142" / "#142" spellings parse the same way.
        $this->getJson('/palette?q=ascending ch 142')->assertJsonPath('chapters.0.id', $target->id);
        $this->getJson('/palette?q=ascending %23142')->assertJsonPath('chapters.0.id', $target->id);
    }

    public function test_palette_escapes_like_wildcards(): void
    {
        $this->novel('100% Done');
        $this->novel('1000 Days');
        $this->novel('A_B Story');
        $this->novel('AxB Story');
        $this->novel('Bang! Bang');

        $this->getJson('/palette?q=' . urlencode('100%'))
            ->assertJsonCount(1, 'novels')
            ->assertJsonPath('novels.0.name', '100% Done');
        $this->getJson('/palette?q=' . urlencode('A_B'))
            ->assertJsonCount(1, 'novels')
            ->assertJsonPath('novels.0.name', 'A_B Story');
        // The escape character itself is literal too.
        $this->getJson('/palette?q=' . urlencode('g! B'))
            ->assertJsonCount(1, 'novels');
    }

    public function test_palette_with_no_query_returns_empty_lists(): void
    {
        $this->novel('Anything');

        $this->getJson('/palette')->assertExactJson(['novels' => [], 'chapters' => []]);
        $this->getJson('/palette?q=%20%20')->assertExactJson(['novels' => [], 'chapters' => []]);
    }

    public function test_palette_limits_results_to_eight(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->novel("Saga {$i}");
        }

        $this->getJson('/palette?q=saga')->assertJsonCount(8, 'novels');
    }

    // ---- Layout: tab bar, palette, theme ----------------------------------

    public function test_normal_pages_render_the_tab_bar_more_sheet_and_palette(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="tabbar"', $html);
        $this->assertStringContainsString('id="moreSheet"', $html);
        $this->assertStringContainsString('data-sheet-open="#moreSheet"', $html);
        $this->assertStringContainsString('id="palette"', $html);
        $this->assertStringContainsString('data-palette-open', $html);
        $this->assertStringContainsString('has-tabbar', $html);
        // More sheet + System menu carry the Activity page.
        $this->assertStringContainsString(route('activity.index'), $html);
        // Theme toggle in the navbar and the sheet.
        $this->assertSame(2, substr_count($html, 'data-theme-toggle'));
    }

    public function test_the_chromeless_reader_has_no_tab_bar_and_yields_the_theme(): void
    {
        $novel = $this->novel('Reader Novel');
        $chapter = $this->chapter($novel, 1);

        $html = $this->get(route('chapters.show', $chapter->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="tabbar"', $html);
        $this->assertStringNotContainsString('id="moreSheet"', $html);
        $this->assertStringContainsString('is-chromeless', $html);
        // The head script defers to the reader's own theme on this page.
        $this->assertStringContainsString("localStorage.getItem('reader_theme')", $html);
    }

    public function test_theme_attributes_and_the_pre_paint_script_are_present(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*data-bs-theme="dark"[^>]*data-theme="dark"/', $html);
        $this->assertStringContainsString("localStorage.getItem('novarr_theme')", $html);
        $this->assertStringContainsString('prefers-color-scheme: light', $html);
        $this->assertStringNotContainsString("localStorage.getItem('reader_theme')", $html);
        // The script runs before the stylesheet so the first paint is themed.
        $this->assertLessThan(strpos($html, '/build/assets/app-'), strpos($html, 'novarr_theme'));
        $this->assertStringContainsString('<meta name="theme-color"', $html);
    }

    public function test_navbar_search_still_submits_to_the_full_search_page(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]*role="search"[^>]*action="' . preg_quote(route('search.index'), '/') . '"/', $html);
        $this->assertStringContainsString('id="navSearch"', $html);
        $this->assertStringNotContainsString('navSearchResults', $html);
    }
}
