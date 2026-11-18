<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Manga phase 1, the web UI around the reader: the Library's medium filter,
 * Home's continue cards ("Page 12 of 34", the MANGA chip), the novel page's
 * primary action and hidden text-only controls, and the reader shell.
 */
class MangaUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name, bool $manga = false): Novel
    {
        $novel = Novel::create([
            'name' => $name,
            'author' => 'Someone',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 3,
            'translator_url' => $manga
                ? 'https://mangadex.org/title/9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2'
                : 'https://novelping.com/novel/' . str()->slug($name),
        ]);
        if ($manga) {
            $novel->forceFill(['medium' => Novel::MEDIUM_MANGA])->save();
        }

        return $novel;
    }

    private function chapter(Novel $novel, int $n, array $attrs = [], bool $downloaded = true): NovelChapter
    {
        $c = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://example.test/{$novel->id}/{$n}",
        ]);
        $c->forceFill(array_merge([
            'status' => $downloaded ? 1 : 0,
            'download_date' => $downloaded ? now()->subDay() : null,
            'page_count' => $novel->isManga() && $downloaded ? 34 : null,
        ], $attrs))->saveQuietly();

        return $c;
    }

    // ---- Library: medium filter ---------------------------------------------

    public function testTheMediumControlIsShownWithoutAManga(): void
    {
        // Separate libraries: the Novels | Manga control is always there, and
        // ?medium=manga is applied even when the library holds no manga.
        $this->novel('Only Text');

        $html = $this->get(route('novels.index', ['medium' => 'manga']))->assertOk()->getContent();

        $this->assertStringContainsString('data-medium="novel"', $html);
        $this->assertStringContainsString('data-medium="manga"', $html);
        $this->assertStringNotContainsString('data-medium="all"', $html);
        $this->assertStringNotContainsString('Only Text', $html);
    }

    public function testTheMediumControlSwitchesLibrariesAndStaysInTheQueryString(): void
    {
        $this->novel('Text Novel One');
        $this->novel('Comic Two', true);

        $novels = $this->get(route('novels.index'))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Library"', $novels);
        $this->assertMatchesRegularExpression('/data-medium="novel"\s+class="btn btn-secondary active"/', $novels);
        $this->assertStringContainsString('Text Novel One', $novels);
        $this->assertStringNotContainsString('Comic Two', $novels);

        $manga = $this->get(route('novels.index', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('Comic Two', $manga);
        $this->assertStringNotContainsString('Text Novel One', $manga);
        $this->assertStringContainsString('MANGA', $manga, 'the Library chip');
        $this->assertMatchesRegularExpression('/data-medium="manga"\s+class="btn btn-secondary active"/', $manga);
        // Kept by the search form and the other controls.
        $this->assertStringContainsString('<input type="hidden" name="medium" value="manga">', $manga);
        $this->assertStringContainsString('medium=manga&amp;view=table', $manga);

        // Combined with a chip and the table view.
        $table = $this->get(route('novels.index', ['medium' => 'manga', 'view' => 'table']))->assertOk()->getContent();
        $this->assertStringContainsString('Comic Two', $table);
        $this->assertStringNotContainsString('Text Novel One', $table);
    }

    // ---- Home: continue cards -----------------------------------------------

    public function testAMangaChapterInProgressIsTheHeroWithItsPage(): void
    {
        $manga = $this->novel('Comic Hero', true);
        $this->chapter($manga, 1, ['read_at' => now()->subDay(), 'read_progress' => 100]);
        // Chapter 2: half read, not marked read yet (the reader marks it at its last page).
        $c2 = $this->chapter($manga, 2, ['read_progress' => 50, 'read_progress_at' => now()]);
        $this->chapter($manga, 3);

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('Comic Hero', $html);
        $this->assertStringContainsString('href="' . route('chapters.show', $c2->id) . '" class="btn btn-primary" id="heroResume"', $html);
        $this->assertStringContainsString('Resume chapter 2', $html);
        $this->assertMatchesRegularExpression('/id="heroPages">\s*Page 17 of 34 in this chapter/', $html);
        $this->assertStringNotContainsString('id="heroMinutes"', $html);
        $this->assertStringContainsString('<span class="cover-chip">MANGA</span>', $html);
    }

    public function testAMangaStartedButNeverFinishedStillShowsOnHome(): void
    {
        // Nothing has a read_at yet: only progress on chapter 1.
        $manga = $this->novel('Comic Fresh', true);
        $c1 = $this->chapter($manga, 1, ['read_progress' => 9, 'read_progress_at' => now()]);
        $this->chapter($manga, 2);

        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Comic Fresh', $html);
        $this->assertStringContainsString('Resume chapter 1', $html);
        $this->assertMatchesRegularExpression('/Page 3 of 34 in this chapter/', $html);

        $this->get('/continue')->assertRedirect(route('chapters.show', $c1->id));
    }

    public function testShelfTilesShowPagesForMangaAndUnreadCountsForNovels(): void
    {
        // The hero: a text novel read most recently.
        $text = $this->novel('Text Lead');
        $this->chapter($text, 1, ['read_at' => now(), 'read_progress' => 40]);
        $this->chapter($text, 2);

        // Shelf: a manga in progress and a manga between chapters.
        $mid = $this->novel('Comic Mid', true);
        $this->chapter($mid, 1, ['read_at' => now()->subDays(2), 'read_progress' => 100]);
        $midC2 = $this->chapter($mid, 2, ['read_progress' => 35, 'read_progress_at' => now()->subHour()]);

        $between = $this->novel('Comic Between', true);
        $this->chapter($between, 1, ['read_at' => now()->subDays(3), 'read_progress' => 100]);
        $this->chapter($between, 2);

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('Text Lead', $html);
        $this->assertStringNotContainsString('id="heroPages"', $html, 'a text hero has no page line');

        $this->assertStringContainsString('href="' . route('chapters.show', $midC2->id) . '" class="home-tile"', $html);
        $this->assertStringContainsString('ch 2 · Page 12 of 34', $html);
        $this->assertStringContainsString('aria-label="Continue Comic Mid at chapter 2, page 12 of 34"', $html);
        // Between chapters: the usual unread text.
        $this->assertStringContainsString('aria-label="Continue Comic Between at chapter 2, 1 to read"', $html);
        $this->assertSame(2, substr_count($html, '<span class="cover-chip">MANGA</span>'));
    }

    public function testMangaPageMatchesTheReadersMapping(): void
    {
        $this->assertNull(HomeController::mangaPage(null, 34));
        $this->assertNull(HomeController::mangaPage(0, 34));
        $this->assertNull(HomeController::mangaPage(100, 34));
        $this->assertNull(HomeController::mangaPage(50, null));
        $this->assertSame(1, HomeController::mangaPage(1, 34));
        $this->assertSame(17, HomeController::mangaPage(50, 34));
        $this->assertSame(34, HomeController::mangaPage(99, 34));
        $this->assertSame(3, HomeController::mangaPage(1, 250));
        $this->assertSame(1, HomeController::mangaPage(60, 1));
    }

    // ---- Novel page --------------------------------------------------------------

    public function testTheMangaNovelPageContinuesTheChapterInProgress(): void
    {
        $manga = $this->novel('Comic Page', true);
        $this->chapter($manga, 1, ['read_at' => now()->subDay(), 'read_progress' => 100]);
        $c2 = $this->chapter($manga, 2, ['read_progress' => 50, 'read_progress_at' => now()]);
        $this->chapter($manga, 3, [], false);

        $html = $this->get(route('novels.show', $manga->id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('chapters.show', $c2->id), '#') . '" class="btn btn-primary nv-continue">.*?Continue at chapter 2, page 17\s*</a>#s', $html);
        $this->assertStringContainsString('34 pages', $html);
        // Text-only controls are gone.
        $this->assertStringNotContainsString('id="offlineControls"', $html);
        $this->assertStringNotContainsString('Search the text of this novel', $html);
        $this->assertStringNotContainsString('Generate ePub', $html);
        $this->assertStringNotContainsString('Send to Kindle', $html);
        $this->assertStringNotContainsString('min left', $html);
    }

    public function testAMangaNeverOpenedStartsAtTheFirstChapter(): void
    {
        $manga = $this->novel('Comic Unopened', true);
        $c1 = $this->chapter($manga, 1);
        $this->chapter($manga, 2);

        $html = $this->get(route('novels.show', $manga->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('chapters.show', $c1->id), '#') . '" class="btn btn-primary nv-continue">.*?Start reading\s*</a>#s', $html);
    }

    public function testATextNovelPageKeepsItsTextControls(): void
    {
        $text = $this->novel('Plain Text');
        $this->chapter($text, 1);

        $html = $this->get(route('novels.show', $text->id))->assertOk()->getContent();
        $this->assertStringContainsString('id="offlineControls"', $html);
        $this->assertStringContainsString('Search the text of this novel', $html);
        $this->assertStringNotContainsString(', page ', $html);
    }

    // ---- Reader shell ---------------------------------------------------------------

    public function testTheReaderShellCarriesItsChromeAndScript(): void
    {
        $manga = $this->novel('Comic Shell', true);
        $c = $this->chapter($manga, 1);

        $html = $this->get(route('chapters.show', $c->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-novel-name="Comic Shell"', $html);
        $this->assertStringContainsString('data-novel-id="' . $manga->id . '"', $html);
        foreach (['mrStage', 'mrSlider', 'mrCounter', 'mrFit', 'mrFullscreen', 'tocPanel', 'mrAnnounce'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, $id);
        }
        foreach (['rtl', 'ltr', 'vertical'] as $mode) {
            $this->assertStringContainsString('data-mode="' . $mode . '"', $html);
        }
        $this->assertStringContainsString('aria-label="Back to Comic Shell"', $html);
        $this->assertNull($c->fresh()->read_at);
    }
}
