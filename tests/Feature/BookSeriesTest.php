<?php

namespace Tests\Feature;

use App\Books\BookLibrary;
use App\Books\SeriesInference;
use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EpubBuilder;
use Tests\TestCase;

/**
 * Series in the Books library: the inference rules (App\Books\SeriesInference),
 * storing a series at import, `books:series`, the grouped grid and the
 * ?series= view, the book page's "Book 2 of …" with previous / next, the
 * owner's edit, and the API summary.
 */
class BookSeriesTest extends TestCase
{
    use RefreshDatabase;

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
    }

    protected function tearDown(): void
    {
        EpubBuilder::cleanup();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The rules
    // ------------------------------------------------------------------

    /** Books for infer(): [title, author] or [title, author, series, index, source]. */
    private function infer(array $books): array
    {
        $rows = [];
        foreach ($books as $i => $b) {
            $rows[] = ['id' => $i + 1, 'name' => $b[0], 'author' => $b[1], 'series' => $b[2] ?? null,
                'series_index' => $b[3] ?? null, 'series_source' => $b[4] ?? null];
        }
        $out = SeriesInference::infer($rows);
        $named = [];
        foreach ($out as $id => $guess) {
            $named[$books[$id - 1][0]] = $guess['series'] === null ? null : [$guess['series'], $guess['series_index']];
        }

        return $named;
    }

    public function test_titles_are_parsed_into_stem_and_number(): void
    {
        $cases = [
            'Shadow Slave Vol. 3' => ['Shadow Slave', 3.0],
            'Desire: A LitRPG Adventure (Volume 2)' => ['Desire: A LitRPG Adventure', 2.0],
            'The Academy Part 2' => ['The Academy', 2.0],
            'Mistborn Book Two' => ['Mistborn', 2.0],
            'Cradle, Book IV: Wintersteel' => ['Cradle', 4.0],
            'Dungeon Life #3' => ['Dungeon Life', 3.0],
            'Overlord 14' => ['Overlord', 14.0],
            'Hunter Chapters 1-200' => ['Hunter', null],
            'Blade Runner 2049' => ['Blade Runner 2049', null],
            'The Book Thief' => ['The Book Thief', null],
            'Book of the Civil War' => ['Book of the Civil War', null],
        ];
        foreach ($cases as $title => [$base, $number]) {
            $parsed = SeriesInference::parseTitle($title);
            $this->assertSame([$base, $number], [$parsed['base'], $parsed['number']], $title);
        }
    }

    public function test_numbered_volumes_of_one_author_form_a_series(): void
    {
        $out = $this->infer([
            ['Shadow Slave Vol. 3', 'Guiltythree'],
            ['Shadow Slave Vol. 1', 'Guiltythree'],
            ['Shadow Slave', 'guiltythree'],          // the bare title joins; author case ignored
            ['Desire: A LitRPG Adventure (Volume 2)', 'Ann Bell'],
            ['Desire: A LitRPG Adventure (Volume 1)', 'Ann Bell'],
            ['The Academy Part 2', 'C. Dee'],
            ['The Academy', 'C. Dee'],
            ['Mistborn Book Two', 'Brandon Sanderson'],
            ['Mistborn Book One', 'Brandon Sanderson'],
        ]);

        $this->assertSame(['Shadow Slave', 3.0], $out['Shadow Slave Vol. 3']);
        $this->assertSame(['Shadow Slave', 1.0], $out['Shadow Slave Vol. 1']);
        $this->assertSame(['Shadow Slave', null], $out['Shadow Slave']);
        $this->assertSame(['Desire: A LitRPG Adventure', 2.0], $out['Desire: A LitRPG Adventure (Volume 2)']);
        $this->assertSame(['Desire: A LitRPG Adventure', 1.0], $out['Desire: A LitRPG Adventure (Volume 1)']);
        $this->assertSame(['The Academy', 2.0], $out['The Academy Part 2']);
        $this->assertSame(['The Academy', null], $out['The Academy']);
        $this->assertSame(['Mistborn', 2.0], $out['Mistborn Book Two']);
        $this->assertSame(['Mistborn', 1.0], $out['Mistborn Book One']);
    }

    public function test_a_shared_prefix_before_a_colon_forms_a_series(): void
    {
        $out = $this->infer([
            ['Reborn: Demon Core', 'Ivy Lane'],
            ["Reborn: Knight's Code", 'Ivy Lane'],
            ['Reborn', 'Ivy Lane'],
        ]);

        $this->assertSame(['Reborn', null], $out['Reborn: Demon Core']);
        $this->assertSame(['Reborn', null], $out["Reborn: Knight's Code"]);
        $this->assertSame(['Reborn', null], $out['Reborn']);
    }

    public function test_what_never_forms_a_series(): void
    {
        $out = $this->infer([
            // Shared words only.
            ['Throne of Secrets', 'Mel Ross'],
            ['Throne of the Fallen', 'Mel Ross'],
            // Different authors.
            ['Hunter Vol. 1', 'A. One'],
            ['Hunter Vol. 2', 'B. Two'],
            // One volume alone.
            ['Lone Star Vol. 2', 'Cy Pike'],
            // Copies, not volumes.
            ['Same Title', 'Di Ruth'],
            ['Same Title', 'Di Ruth'],
            // A subtitle that describes the book.
            ['Wander: A Novel', 'Ed Fox'],
            ['Wander', 'Ed Fox'],
            // A prefix without an author.
            ['Untitled: One', null],
            ['Untitled: Two', null],
            // A year is no volume.
            ['Blade Runner 2049', 'Fi Gale'],
            ['Blade Runner', 'Fi Gale'],
        ]);

        $this->assertSame([], array_filter($out), 'nothing grouped: ' . json_encode(array_filter($out)));
    }

    public function test_an_explicit_series_is_never_overridden_and_is_joined(): void
    {
        $out = $this->infer([
            ['Unsouled', 'Will Wight', 'Cradle', 1.0, 'calibre'],
            ['Cradle Vol. 4', 'Will Wight'],                       // joins Calibre's "Cradle"
            ['Mistborn: Secret History', 'Brandon Sanderson'],     // joins the ePub's "Mistborn" by its prefix
            ['The Final Empire', 'Brandon Sanderson', 'Mistborn', 1.0, 'epub'],
            ['Kept Vol. 1', 'Gil Hart', '', null, 'manual'],      // the owner said "no series"
            ['Kept Vol. 2', 'Gil Hart'],                           // alone now: no series
            ['Old Guess', 'Hal Ince', 'Gone', 1.0, 'inferred'],   // an earlier guess that no longer holds
        ]);

        $this->assertArrayNotHasKey('Unsouled', $out);
        $this->assertArrayNotHasKey('The Final Empire', $out);
        $this->assertArrayNotHasKey('Kept Vol. 1', $out);
        $this->assertSame(['Cradle', 4.0], $out['Cradle Vol. 4']);
        $this->assertSame(['Mistborn', null], $out['Mistborn: Secret History']);
        $this->assertNull($out['Kept Vol. 2']);
        $this->assertNull($out['Old Guess']);
    }

    // ------------------------------------------------------------------
    // Storing and the command
    // ------------------------------------------------------------------

    private function book(string $name, array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Ada Wren',
            'medium' => Novel::MEDIUM_BOOK,
            'translator_url' => BookLibrary::urlFor(sha1($name . $this->nextId++)),
            'group_id' => 0,
            'status' => 1,
            'no_of_chapters' => 3,
        ], $attrs));
    }

    public function test_import_stores_the_epubs_series_and_groups_the_authors_books(): void
    {
        Storage::fake('public');
        Bus::fake();
        $dir = sys_get_temp_dir() . '/novarr-books-' . uniqid();
        config(['novarr.books.path' => $dir]);
        // Already in the library, no series: "Orchard Cycle Book 1" by the same author.
        $first = $this->book('Orchard Cycle Book 1');

        try {
            $book = app(BookLibrary::class)->import(EpubBuilder::epub3(), 'orchard.epub')['novel'];
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame(['Orchard Cycle', 2.0, 'epub'], [$book->fresh()->series, $book->fresh()->series_index, $book->fresh()->series_source]);
        // The arrival grouped the author's other book with it.
        $this->assertSame(['Orchard Cycle', 1.0, 'inferred'], [$first->fresh()->series, $first->fresh()->series_index, $first->fresh()->series_source]);
    }

    public function test_books_series_command_dry_run_and_run(): void
    {
        $one = $this->book('Shadow Slave Vol. 1');
        $two = $this->book('Shadow Slave Vol. 2');
        $alone = $this->book('Throne of Secrets');
        $this->book('Throne of the Fallen');
        // A web novel is never grouped.
        Novel::create(['name' => 'Shadow Slave Vol. 9', 'author' => 'Ada Wren', 'group_id' => 1, 'status' => 0, 'no_of_chapters' => 1]);

        $this->artisan('books:series', ['--dry-run' => true])
            ->expectsOutputToContain('[dry run] Inferred: 4 books, 2 assigned, 0 cleared, 1 inferred series.')
            ->assertSuccessful();
        $this->assertNull($one->fresh()->series);

        $this->artisan('books:series')->expectsOutputToContain('Inferred: 4 books, 2 assigned')->assertSuccessful();
        $this->assertSame(['Shadow Slave', 1.0, 'inferred'], [$one->fresh()->series, $one->fresh()->series_index, $one->fresh()->series_source]);
        $this->assertSame(2.0, $two->fresh()->series_index);
        $this->assertNull($alone->fresh()->series);

        // Again: nothing to do.
        $this->artisan('books:series')->expectsOutputToContain('0 assigned, 0 cleared')->assertSuccessful();

        // Renamed so it no longer fits: the guess is taken back.
        $two->update(['name' => 'Something Else']);
        $this->artisan('books:series')->expectsOutputToContain('2 cleared')->assertSuccessful();
        $this->assertNull($one->fresh()->series);
        $this->assertNull($one->fresh()->series_source);
    }

    // ------------------------------------------------------------------
    // The Library
    // ------------------------------------------------------------------

    /** Three books of "Iron Sky" (out of order by name), two standalone. */
    private function library(): array
    {
        return [
            'three' => $this->book('Iron Sky: Ashes', ['series' => 'The Iron Sky Trilogy', 'series_index' => 3, 'series_source' => 'calibre']),
            'one' => $this->book('Iron Sky: Zenith', ['series' => 'The Iron Sky Trilogy', 'series_index' => 1, 'series_source' => 'calibre']),
            'two' => $this->book('Iron Sky: Embers', ['series' => 'The Iron Sky Trilogy', 'series_index' => 2, 'series_source' => 'calibre']),
            'solo' => $this->book('A Lone Book'),
            'onlyOne' => $this->book('Half a Pair', ['series' => 'Pairs', 'series_index' => 1, 'series_source' => 'epub']),
        ];
    }

    public function test_the_books_grid_shows_a_series_as_one_card(): void
    {
        $b = $this->library();

        $html = $this->get(route('novels.index', ['medium' => 'book', 'view' => 'grid', 'sort' => 'name']))->assertOk()->getContent();

        // One series card + two books (a series with one book here stays a book).
        $this->assertSame(1, substr_count($html, 'class="lib-tile lib-tile-series"'));
        $this->assertSame(2, substr_count($html, 'class="lib-tile"'));
        $this->assertStringContainsString('3 BOOKS', $html);
        $this->assertStringContainsString('<span class="lib-tile-title">The Iron Sky Trilogy</span>', $html);
        $this->assertStringContainsString(e(route('novels.index', ['view' => 'grid', 'sort' => 'name', 'medium' => 'book', 'series' => 'The Iron Sky Trilogy'])), $html);
        // The card's books are not tiles of their own.
        $this->assertStringNotContainsString('data-id="' . $b['two']->id . '"', $html);
        // Sorted by name, the card goes by the series' name: after "Half a Pair".
        $this->assertLessThan(strpos($html, 'The Iron Sky Trilogy</span>'), strpos($html, 'Half a Pair</span>'));
        $this->assertStringContainsString('Pairs #1', $html);

        // Novels are never grouped.
        $this->get(route('novels.index', ['medium' => 'novel']))->assertOk()->assertDontSee('lib-tile-series', false);
    }

    public function test_pagination_counts_cards(): void
    {
        foreach (range(1, 45) as $i) {
            $this->book("Saga Book {$i}", ['series' => 'Long Saga', 'series_index' => $i, 'series_source' => 'calibre']);
        }
        foreach (range(1, 41) as $i) {
            $this->book(sprintf('Standalone %02d', $i));
        }

        $html = $this->get(route('novels.index', ['medium' => 'book', 'view' => 'grid', 'sort' => 'name']))->assertOk()->getContent();

        // 1 card + 41 books = 42 cards: one page.
        $this->assertSame(41, substr_count($html, 'class="lib-tile"'));
        $this->assertSame(1, substr_count($html, 'class="lib-tile lib-tile-series"'));
        $this->assertStringContainsString('45 BOOKS', $html);
        $this->assertStringNotContainsString('page=2', $html);
    }

    public function test_the_series_view_lists_its_books_in_order(): void
    {
        $b = $this->library();

        $html = $this->get(route('novels.index', ['medium' => 'book', 'view' => 'grid', 'series' => 'The Iron Sky Trilogy']))->assertOk()->getContent();

        $this->assertStringContainsString('Series:</span> The Iron Sky Trilogy', $html);
        $this->assertMatchesRegularExpression('#<span class="mono-num">3</span> books#', $html);
        $this->assertStringContainsString('All books', $html);
        $this->assertSame(3, substr_count($html, 'class="lib-tile"'));
        $pos = fn(Novel $n) => strpos($html, 'data-id="' . $n->id . '"');
        $this->assertLessThan($pos($b['two']), $pos($b['one']));
        $this->assertLessThan($pos($b['three']), $pos($b['two']));
        $this->assertStringContainsString('Book 2', $html);
        $this->assertStringNotContainsString('A Lone Book', $html);

        // The table lists them flat, with a Series column.
        $table = $this->get(route('novels.index', ['medium' => 'book', 'view' => 'table']))->assertOk()->getContent();
        $this->assertStringContainsString('<th class="col-series">Series</th>', $table);
        $this->assertStringContainsString('#3</span>', $table);
    }

    public function test_a_search_shows_the_matching_books_themselves(): void
    {
        $b = $this->library();

        // By a book's title: the book, not its series' card.
        $html = $this->get(route('novels.index', ['medium' => 'book', 'view' => 'grid', 'search' => 'Embers']))->assertOk()->getContent();
        $this->assertSame(0, substr_count($html, 'lib-tile-series"'));
        $this->assertStringContainsString('data-id="' . $b['two']->id . '"', $html);
        $this->assertStringContainsString('The Iron Sky Trilogy #2', $html);

        // By the series' name: its books.
        $html = $this->get(route('novels.index', ['medium' => 'book', 'view' => 'grid', 'search' => 'Trilogy']))->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'class="lib-tile"'));
    }

    // ------------------------------------------------------------------
    // The book page and the edit
    // ------------------------------------------------------------------

    public function test_the_book_page_names_its_series_and_the_books_around_it(): void
    {
        $b = $this->library();

        $html = $this->get(route('novels.show', $b['two']->id))->assertOk()->getContent();
        $this->assertStringContainsString('Book 2 of', $html);
        $this->assertStringContainsString(e(route('novels.index', ['medium' => 'book', 'series' => 'The Iron Sky Trilogy'])), $html);
        $this->assertStringContainsString('href="' . route('novels.show', $b['one']->id) . '" class="nv-series-step" rel="prev"', $html);
        $this->assertStringContainsString('href="' . route('novels.show', $b['three']->id) . '" class="nv-series-step" rel="next"', $html);

        $first = $this->get(route('novels.show', $b['one']->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('rel="prev"', $first);
        $this->assertStringContainsString('rel="next"', $first);

        $this->get(route('novels.show', $b['solo']->id))->assertOk()->assertDontSee('nv-series', false);
    }

    public function test_the_owner_sets_and_clears_a_books_series(): void
    {
        $book = $this->book('Hollow Crown Vol. 1');
        $this->book('Hollow Crown Vol. 2');
        $this->artisan('books:series')->assertSuccessful();
        $this->assertSame('inferred', $book->fresh()->series_source);

        $this->get(route('novels.edit', $book->id))->assertOk()->assertSee('Number in series')->assertSee('Guessed from the titles');

        $this->put(route('novels.update', $book->id), ['name' => $book->name, 'series' => 'The Crowns', 'series_index' => '1.5'])->assertRedirect();
        $this->assertSame(['The Crowns', 1.5, 'manual'], [$book->fresh()->series, $book->fresh()->series_index, $book->fresh()->series_source]);

        // Cleared: "no series", and no guess puts it back.
        $this->put(route('novels.update', $book->id), ['name' => $book->name, 'series' => '', 'series_index' => ''])->assertRedirect();
        $this->artisan('books:series')->assertSuccessful();
        $this->assertSame(['', null, 'manual'], [$book->fresh()->series, $book->fresh()->series_index, $book->fresh()->series_source]);

        // Saving the form unchanged leaves a guess a guess.
        $other = $this->book('Plain Book');
        $this->put(route('novels.update', $other->id), ['name' => 'Plain Book', 'series' => '', 'series_index' => ''])->assertRedirect();
        $this->assertNull($other->fresh()->series_source);
    }

    public function test_the_api_summary_carries_the_series(): void
    {
        $b = $this->library();

        $novels = collect($this->getJson('/api/v1/novels?medium=book')->assertOk()->json('novels'))->keyBy('id');

        $this->assertSame('The Iron Sky Trilogy', $novels[$b['two']->id]['series']);
        $this->assertEquals(2, $novels[$b['two']->id]['series_index']);
        $this->assertNull($novels[$b['solo']->id]['series']);
        $this->assertNull($novels[$b['solo']->id]['series_index']);
    }
}
