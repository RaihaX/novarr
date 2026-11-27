<?php

namespace Tests\Feature;

use App\Http\Helpers\LibraryMedium;
use App\Novel;
use App\NovelChapter;
use App\Services\Discover;
use App\Sources\Source;
use App\Sources\SourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Two libraries on one route: /novels?medium=novel|manga (remembered in the
 * session, novels by default), with Discover and manual add following the
 * library they were opened from, and the desktop nav's Novels / Manga items.
 */
class SeparateLibrariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('dashboard_attention', [], 900);
    }

    private function series(string $name, bool $manga = false, array $attrs = []): Novel
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
        $novel->forceFill(array_merge(['medium' => $manga ? Novel::MEDIUM_MANGA : Novel::MEDIUM_NOVEL], $attrs))->save();

        return $novel;
    }

    private function chapter(Novel $novel, int $n, bool $downloaded = true): NovelChapter
    {
        $c = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://example.test/{$novel->id}/{$n}",
        ]);
        $c->forceFill(['status' => $downloaded ? 1 : 0, 'download_date' => $downloaded ? now()->subDay() : null])->saveQuietly();

        return $c;
    }

    private function summary(string $html): string
    {
        $this->assertSame(1, preg_match('#<p class="lib-summary mb-0">(.*?)</p>#s', $html, $m));

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    /** The count on a chip, or null when the chip shows none. */
    private function chipCount(string $html, string $chip): ?string
    {
        $this->assertSame(1, preg_match('#data-chip="' . $chip . '"[^>]*>(.*?)</a>#s', $html, $m), "chip {$chip}");

        return preg_match('#lib-chip-count[^"]*">([^<]+)<#', $m[1], $c) ? $c[1] : null;
    }

    private function activeNav(string $html): array
    {
        preg_match_all('#<a class="(?:nav-link|dropdown-item) active"[^>]*data-nav-medium="(\w+)"#', $html, $m);

        return $m[1];
    }

    // ---- Library ------------------------------------------------------------

    public function testTheDefaultLibraryIsNovelsAndLeavesMangaOut(): void
    {
        $this->series('Text Alpha');
        $this->series('Comic Beta', true);

        $html = $this->get(route('novels.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Text Alpha', $html);
        $this->assertStringNotContainsString('Comic Beta', $html);
        $this->assertStringContainsString('<title>Web Novels · ', $html);
        $this->assertMatchesRegularExpression('#<h1 class="page-title mb-0">Web Novels</h1>#', $html);
        $this->assertStringContainsString('placeholder="Search novels…"', $html);
        $this->assertMatchesRegularExpression('/data-medium="novel"\s+class="btn btn-secondary active"/', $html);
    }

    public function testTheMangaLibraryShowsOnlyMangaAndIsRemembered(): void
    {
        $this->series('Text Alpha');
        $this->series('Comic Beta', true);

        $html = $this->get(route('novels.index', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('Comic Beta', $html);
        $this->assertStringNotContainsString('Text Alpha', $html);
        $this->assertStringContainsString('<title>Manga · ', $html);
        $this->assertStringContainsString('placeholder="Search manga…"', $html);
        $this->assertSame('manga', session(LibraryMedium::SESSION_KEY));

        // The next plain /novels opens the Manga library again.
        $again = $this->get(route('novels.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Comic Beta', $again);
        $this->assertStringNotContainsString('Text Alpha', $again);

        // And an explicit ?medium=novel switches back (and is remembered).
        $this->get(route('novels.index', ['medium' => 'novel']))->assertOk()->assertSee('Text Alpha')->assertDontSee('Comic Beta');
        $this->get(route('novels.index'))->assertOk()->assertSee('Text Alpha')->assertDontSee('Comic Beta');
    }

    public function testMediumAllIsTreatedAsNoParameter(): void
    {
        $this->series('Text Alpha');
        $this->series('Comic Beta', true);

        $this->get(route('novels.index', ['medium' => 'all']))->assertOk()->assertSee('Text Alpha')->assertDontSee('Comic Beta');

        $this->get(route('novels.index', ['medium' => 'manga']))->assertOk();
        $html = $this->get(route('novels.index', ['medium' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('Comic Beta', $html, 'the remembered library');
        $this->assertStringNotContainsString('Text Alpha', $html);
        $this->assertStringNotContainsString('data-medium="all"', $html);
        $this->assertSame('manga', session(LibraryMedium::SESSION_KEY));
    }

    public function testHeaderAndChipCountsAreScopedToTheMedium(): void
    {
        $a = $this->series('Text Alpha');
        $b = $this->series('Text Gamma');
        $m = $this->series('Comic Beta', true);
        $this->chapter($a, 1);
        $this->chapter($a, 2, downloaded: false);
        $this->chapter($b, 1);
        foreach ([1, 2, 3] as $n) {
            $this->chapter($m, $n);
        }
        $this->chapter($m, 4, downloaded: false);
        $this->chapter($m, 5, downloaded: false);
        // One chapter read in each library.
        $this->markRead(NovelChapter::where('novel_id', $a->id)->where('chapter', 1)->first());
        $this->markRead(NovelChapter::where('novel_id', $m->id)->where('chapter', 1)->first());
        // A "new" chapter in the manga only (downloaded after the last read).
        $this->markRead(NovelChapter::where('novel_id', $m->id)->where('chapter', 1)->first(), null, now()->subDays(3));
        NovelChapter::where('novel_id', $m->id)->where('chapter', 3)->update(['download_date' => now()]);

        $novels = $this->get(route('novels.index', ['medium' => 'novel']))->assertOk()->getContent();
        $this->assertSame('2 web novels · 2 chapters downloaded · 1 queued', $this->summary($novels));
        $this->assertNull($this->chipCount($novels, 'new'));

        $manga = $this->get(route('novels.index', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertSame('1 manga · 3 chapters downloaded · 2 queued', $this->summary($manga));
        $this->assertSame('1', $this->chipCount($manga, 'new'));

        // The Reading chip's set is scoped too: only the manga is listed.
        $reading = $this->get(route('novels.index', ['medium' => 'manga', 'filter' => 'reading']))->assertOk()->getContent();
        $this->assertStringContainsString('Comic Beta', $reading);
        $this->assertStringNotContainsString('Text Alpha', $reading);

        // A single novel: "1 novel".
        $b->delete();
        Cache::flush();
        Cache::put('dashboard_attention', [], 900);
        $this->assertStringStartsWith('1 web novel · ', $this->summary($this->get(route('novels.index', ['medium' => 'novel']))->getContent()));
    }

    public function testOriginChipsAreOnlyInTheNovelsLibrary(): void
    {
        $this->series('Text Alpha', attrs: ['origin' => 'translated']);
        $this->series('Comic Beta', true);

        $novels = $this->get(route('novels.index', ['medium' => 'novel']))->assertOk()->getContent();
        $this->assertStringContainsString('data-chip="translated"', $novels);
        $this->assertStringContainsString('data-chip="original"', $novels);
        $this->assertSame('1', $this->chipCount($novels, 'translated'));

        $manga = $this->get(route('novels.index', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-chip="translated"', $manga);
        $this->assertStringNotContainsString('data-chip="original"', $manga);
        // An origin filter has no meaning there: the whole manga library shows.
        $this->get(route('novels.index', ['medium' => 'manga', 'filter' => 'translated']))->assertOk()->assertSee('Comic Beta');
    }

    public function testTheAddButtonAndEmptyStateCarryTheMedium(): void
    {
        $this->series('Text Alpha');

        $manga = $this->get(route('novels.index', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . e(route('novels.discover', ['medium' => 'manga'])) . '" class="btn btn-primary">+ Add manga</a>', $manga);
        $this->assertStringContainsString('Add your first manga', $manga);
        $this->assertStringContainsString('href="' . e(route('novels.create', ['medium' => 'manga'])) . '" class="btn btn-secondary">Add manually</a>', $manga);
        $this->assertStringContainsString('0 manga', $this->summary($manga));

        $novels = $this->get(route('novels.index', ['medium' => 'novel']))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . e(route('novels.discover', ['medium' => 'novel'])) . '" class="btn btn-primary">+ Add web novel</a>', $novels);

        // A chip that matches nothing offers the medium's whole library back.
        $chip = $this->get(route('novels.index', ['medium' => 'manga', 'filter' => 'finished']))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . e(route('novels.index', ['medium' => 'manga'])) . '" class="btn btn-secondary">Show all manga</a>', $chip);
    }

    public function testSwitchingMediumKeepsViewAndSortOnly(): void
    {
        $html = $this->get(route('novels.index', ['medium' => 'novel', 'view' => 'table', 'sort' => 'name', 'filter' => 'reading', 'tag' => 3, 'search' => 'x']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('href="' . e(route('novels.index', ['medium' => 'manga', 'view' => 'table', 'sort' => 'name'])) . '" data-medium="manga"', $html);
        // Chips stay inside the library.
        $this->assertMatchesRegularExpression('#href="[^"]*medium=novel[^"]*" class="lib-chip[^"]*" data-chip="new"#', $html);
    }

    // ---- Navigation ---------------------------------------------------------

    public function testTheDesktopNavHighlightsTheLibraryOfThePage(): void
    {
        $text = $this->series('Text Alpha');
        $manga = $this->series('Comic Beta', true);

        $this->assertSame(['novel'], $this->activeNav($this->get(route('novels.index', ['medium' => 'novel']))->getContent()));
        $this->assertSame(['manga'], $this->activeNav($this->get(route('novels.index', ['medium' => 'manga']))->getContent()));
        // Discover is its own nav item and adding a series is under Manage
        // (partials/navbar-menu): no library is marked there.
        $this->assertSame([], $this->activeNav($this->get(route('novels.discover'))->getContent()));
        $this->assertSame([], $this->activeNav($this->get(route('novels.create', ['medium' => 'manga']))->getContent()));

        // A series page: its own medium, whatever library was last shown.
        $this->assertSame(['manga'], $this->activeNav($this->get(route('novels.show', $manga->id))->getContent()));
        $this->assertSame(['novel'], $this->activeNav($this->get(route('novels.show', $text->id))->getContent()));
        $this->assertSame('manga', session(LibraryMedium::SESSION_KEY), 'a series page does not change the remembered library');

        // Both items, neither active, elsewhere.
        $home = $this->get(route('stats.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-nav-medium="novel">Web Novels</a>', $home);
        $this->assertStringContainsString('data-nav-medium="manga">Manga</a>', $home);
        $this->assertSame([], $this->activeNav($home));
    }

    public function testASeriesPageLinksBackToItsOwnLibrary(): void
    {
        $manga = $this->series('Comic Beta', true);

        $html = $this->get(route('novels.show', $manga->id))->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . e(route('novels.index', ['medium' => 'manga'])) . '" class="back-link">', $html);
    }

    public function testThePaletteListsBothLibrariesAndBothAdds(): void
    {
        $html = $this->get(route('stats.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="paletteNav">(.*?)</script>#s', $html, $m));
        $nav = collect(json_decode($m[1], true)['nav'])->keyBy('title');

        $this->assertSame(route('novels.index', ['medium' => 'novel']), $nav['Web Novels']['url']);
        $this->assertSame(route('novels.index', ['medium' => 'manga']), $nav['Manga']['url']);
        $this->assertSame(route('novels.create', ['medium' => 'manga']), $nav['Add a manga']['url']);
        $this->assertTrue($nav->has('Add a novel'));
        $this->assertSame(route('novels.discover'), $nav['Discover']['url']);
        $this->assertFalse($nav->has('Library'));
    }

    // ---- Discover and manual add ---------------------------------------------

    public function testDiscoverOffersOnlyTheMediumsSourcesAndRemembersIt(): void
    {
        $manga = $this->get(route('novels.discover', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="mangadex">', $manga);
        $this->assertStringNotContainsString('<option value="novelarrow">', $manga);
        $this->assertStringContainsString('<title>Add manga · ', $manga);
        $this->assertStringContainsString('<h1 class="page-title mb-0">Add manga</h1>', $manga);
        $this->assertStringContainsString('href="' . e(route('novels.create', ['medium' => 'manga'])) . '" class="btn btn-secondary">Manual add</a>', $manga);
        $this->assertStringContainsString('<a href="' . e(route('novels.index', ['medium' => 'manga'])) . '" class="back-link">', $manga);
        $this->assertStringContainsString('const MEDIUM = "manga";', $manga);
        $this->assertSame('manga', session(LibraryMedium::SESSION_KEY));

        // Plain /novels/discover: the remembered library.
        $this->get(route('novels.discover'))->assertOk()->assertSee('<option value="mangadex">', false)->assertSee('Add manga');
        // ... which the Library follows too.
        $this->get(route('novels.index'))->assertOk()->assertSee('<h1 class="page-title mb-0">Manga</h1>', false);

        $novel = $this->get(route('novels.discover', ['medium' => 'novel']))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="novelarrow">', $novel);
        $this->assertStringNotContainsString('<option value="mangadex">', $novel);
        $this->assertStringContainsString('<h1 class="page-title mb-0">Add web novel</h1>', $novel);
    }

    public function testBrowseWithAMediumOnlyAcceptsThatMediumsSources(): void
    {
        $seen = [];
        $this->partialMock(Discover::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('page')->andReturnUsing(function (Source $source) use (&$seen) {
                $seen[] = $source->discoverHost();

                return ['items' => [], 'has_more' => false];
            });
        });

        $this->getJson('/novels/discover/browse?' . http_build_query(['medium' => 'manga', 'source' => 'novelarrow', 'type' => 'search', 'q' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors('source');

        // No source: the first manga source, not the page-wide default.
        $this->getJson('/novels/discover/browse?' . http_build_query(['medium' => 'manga', 'type' => 'search', 'q' => 'abc']))->assertOk();
        $first = array_key_first(SourceResolver::discoverable(Novel::MEDIUM_MANGA));
        $this->assertSame([SourceResolver::discoverable()[$first]->discoverHost()], $seen);
        $this->assertSame('mangadex.org', $seen[0]);

        // Without medium: any source, as before.
        $this->getJson('/novels/discover/browse?' . http_build_query(['source' => 'novelarrow', 'type' => 'search', 'q' => 'abc']))->assertOk();
        $this->getJson('/novels/discover/browse?' . http_build_query(['source' => 'mangadex', 'type' => 'search', 'q' => 'abc']))->assertOk();
        $this->assertSame(['mangadex.org', 'novelping.com', 'mangadex.org'], $seen);
    }

    public function testAMediumWithoutSourcesSaysSo(): void
    {
        $this->partialMock(Discover::class, function ($mock) {
            $mock->shouldReceive('sources')->andReturn(SourceResolver::discoverable(Novel::MEDIUM_NOVEL));
        });

        $html = $this->get(route('novels.discover', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('No manga sources are set up.', $html);
        $this->assertStringContainsString('href="' . e(route('novels.create', ['medium' => 'manga'])) . '">Add manually</a>', $html);
        $this->assertStringNotContainsString('id="discoverSource"', $html);
        $this->assertStringNotContainsString('loadList(', $html);

        $this->get(route('novels.discover', ['medium' => 'novel']))->assertOk()
            ->assertSee('id="discoverSource"', false)->assertDontSee('No novel sources are set up.');
    }

    public function testManualAddFollowsTheMedium(): void
    {
        $html = $this->get(route('novels.create', ['medium' => 'manga']))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Add manga · ', $html);
        $this->assertStringContainsString('<h1 class="page-title mb-0">Add manga</h1>', $html);
        $this->assertStringContainsString('href="' . e(route('novels.discover', ['medium' => 'manga'])) . '" class="btn btn-secondary">Browse sources</a>', $html);
        $this->assertStringContainsString('href="' . e(route('novels.index', ['medium' => 'manga'])) . '" class="btn btn-secondary">Cancel</a>', $html);
        $this->assertStringNotContainsString('name="medium"', $html, 'no medium form field');
    }

    public function testActivityCountsActiveAndCompletedPerLibrary(): void
    {
        $this->series('Novel A');
        $this->series('Novel B', false, ['status' => 1]);
        Cache::flush();

        // No manga yet: only the novel figures, each opening the Novels library.
        $html = $this->get(route('activity.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-summary="active-novel"', $html);
        $this->assertStringNotContainsString('data-summary="active-manga"', $html);

        $this->series('Manga A', true);
        $this->series('Manga B', true);
        $this->series('Manga C', true, ['status' => 1]);
        Cache::flush();

        $stats = \App\Http\Controllers\HomeController::stats();
        $this->assertSame(['active' => 1, 'completed' => 1], $stats['by_medium']['novel']);
        $this->assertSame(['active' => 2, 'completed' => 1], $stats['by_medium']['manga']);
        $this->assertSame(3, $stats['active']);
        $this->assertSame(2, $stats['completed']);

        $html = $this->get(route('activity.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/medium=manga&amp;status=0"[^>]*data-summary="active-manga"><span[^>]*>2</', $html);
        $this->assertMatchesRegularExpression('/medium=novel&amp;status=1"[^>]*data-summary="completed-novel"><span[^>]*>1</', $html);
    }
}
