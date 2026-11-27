<?php

namespace Tests\Feature;

use App\Novel;
use App\ReadingList;
use App\Services\ReadingLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * "Favourites": every reader's second built-in reading list, the heart
 * (App\Services\ReadingLists::favourites()) — the API (/api/v1/novels/{id}/
 * favourite, /api/v1/favourites, the `favourite` flag, meta), the web (the
 * series page's heart, Home's shelf, /favourites, the Library filter) and the
 * migration that turns a reader's own "Favourites" list into it. Sign-in
 * off (every call is the owner) unless a test turns it on.
 */
class FavouritesTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name = 'Loved Novel', string $medium = Novel::MEDIUM_NOVEL): Novel
    {
        return Novel::create(['name' => $name, 'status' => 0, 'group_id' => 1, 'medium' => $medium]);
    }

    private function favouriteIds(): array
    {
        return ReadingList::where('kind', ReadingList::KIND_FAVOURITES)->get()
            ->flatMap(fn($l) => DB::table('reading_list_novel')->where('reading_list_id', $l->id)->pluck('novel_id'))
            ->map(fn($id) => (int) $id)->sort()->values()->all();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_12_13_000001_convert_favourites_lists.php');
    }

    // --- API: the toggle ---------------------------------------------------

    public function test_toggle_is_idempotent_and_answers_the_list(): void
    {
        $novel = $this->novel();

        $response = $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $list = ReadingList::where('kind', 'favourites')->sole();
        $this->assertSame([
            'favourite' => true,
            'list' => ['id' => $list->id, 'name' => 'Favourites', 'count' => 1, 'novel_ids' => [$novel->id], 'kind' => 'favourites', 'position' => 0, 'hidden_on_home' => false],
        ], $response->json());
        $this->assertSame($this->owner()->id, $list->user_id);
        $this->assertSame(ReadingList::FAVOURITES_KEY, $list->name_key);
        $this->assertTrue($list->isFavourites());
        $this->assertFalse($list->isWantToRead());

        // Again: still one row, same answer; "Want to read" untouched (not made).
        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk()->assertJsonPath('list.count', 1);
        $this->assertSame(1, DB::table('reading_list_novel')->count());
        $this->assertSame(1, ReadingList::count());

        $this->deleteJson("/api/v1/novels/{$novel->id}/favourite")->assertOk()
            ->assertExactJson(['favourite' => false, 'list' => ['id' => $list->id, 'name' => 'Favourites', 'count' => 0, 'novel_ids' => [], 'kind' => 'favourites', 'position' => 0, 'hidden_on_home' => false]]);
        $this->deleteJson("/api/v1/novels/{$novel->id}/favourite")->assertOk()->assertJsonPath('list.count', 0);
        $this->assertSame(1, ReadingList::count());
    }

    public function test_unknown_series_is_404(): void
    {
        $this->putJson('/api/v1/novels/999/favourite')->assertNotFound()->assertJsonPath('message', 'Series not found.');
        $this->deleteJson('/api/v1/novels/999/favourite')->assertNotFound();

        $gone = $this->novel('Gone');
        $gone->delete();
        $this->putJson("/api/v1/novels/{$gone->id}/favourite")->assertNotFound();
    }

    public function test_it_is_separate_from_want_to_read(): void
    {
        $novel = $this->novel();
        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();

        $this->getJson("/api/v1/novels/{$novel->id}")->assertOk()
            ->assertJsonPath('novel.favourite', true)->assertJsonPath('novel.want_to_read', false);
        $this->getJson('/api/v1/want-to-read')->assertOk()->assertJsonPath('items', []);

        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $this->deleteJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $this->getJson("/api/v1/novels/{$novel->id}")->assertOk()
            ->assertJsonPath('novel.favourite', false)->assertJsonPath('novel.want_to_read', true);
    }

    public function test_it_holds_every_medium_newest_first(): void
    {
        $novel = $this->novel('A Novel');
        $manga = $this->novel('A Manga', Novel::MEDIUM_MANGA);
        $book = $this->novel('A Book', Novel::MEDIUM_BOOK);

        $this->putJson("/api/v1/novels/{$manga->id}/favourite")->assertOk();
        $this->travel(1)->minutes();
        $this->putJson("/api/v1/novels/{$book->id}/favourite")->assertOk();
        $this->travel(1)->minutes();
        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk()
            ->assertJsonPath('list.novel_ids', [$novel->id, $book->id, $manga->id]);

        // Listed second, after "Want to read" and before the reader's own.
        $this->postJson('/api/v1/lists', ['name' => 'Aardvarks'])->assertCreated();
        $lists = $this->getJson('/api/v1/lists')->assertOk()->json('lists');
        $this->assertSame(['want_to_read', 'favourites', null], array_column($lists, 'kind'));
        $this->assertSame(['Want to read', 'Favourites', 'Aardvarks'], array_column($lists, 'name'));
        $this->assertSame([$novel->id, $book->id, $manga->id], $lists[1]['novel_ids']);

        // The whole list (default: every medium), and one medium at a time.
        $items = $this->getJson('/api/v1/favourites')->assertOk()->json('items');
        $this->assertSame([$novel->id, $book->id, $manga->id], array_map(fn($i) => $i['novel']['id'], $items));
        $this->assertSame([$manga->id], array_map(fn($i) => $i['novel']['id'], $this->getJson('/api/v1/favourites?medium=manga')->json('items')));
        $this->assertSame([$novel->id, $book->id], array_map(fn($i) => $i['novel']['id'], $this->getJson('/api/v1/favourites?medium=text')->json('items')));
        $this->getJson('/api/v1/favourites?medium=films')->assertStatus(422);
    }

    public function test_the_whole_list_payload_and_started(): void
    {
        [$read] = $this->novelWithChapters('Started By Reading', 2);
        $fresh = $this->novel('Not Started');
        foreach ([$fresh, $read] as $n) {
            $this->putJson("/api/v1/novels/{$n->id}/favourite")->assertOk();
            $this->travel(1)->minutes();
        }
        $chapter = $read->chapters()->orderBy('chapter')->first();
        $this->postJson("/api/v1/chapters/{$chapter->id}/read", ['read' => true])->assertOk();

        $json = $this->getJson('/api/v1/favourites')->assertOk()->json();
        $this->assertSame(['list', 'items'], array_keys($json));
        $this->assertSame('favourites', $json['list']['kind']);
        $this->assertSame(2, $json['list']['count']);
        $this->assertSame([$read->id, $fresh->id], array_map(fn($i) => $i['novel']['id'], $json['items']));
        foreach ($json['items'] as $item) {
            $this->assertSame(['novel', 'added_at', 'started'], array_keys($item));
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['added_at']);
            $this->assertTrue($item['novel']['favourite']);
        }
        $this->assertSame([true, false], array_column($json['items'], 'started'));
    }

    public function test_an_unused_list_answers_empty_and_the_whole_list_endpoint_makes_it(): void
    {
        $this->assertSame(0, ReadingList::count());
        $this->getJson('/api/v1/favourites')->assertOk()
            ->assertJsonPath('items', [])->assertJsonPath('list.kind', 'favourites')->assertJsonPath('list.count', 0);
        $this->assertSame(1, ReadingList::count());
    }

    public function test_the_lists_listing_makes_both_built_in_lists(): void
    {
        $this->assertSame(0, ReadingList::count());
        $lists = $this->getJson('/api/v1/lists')->assertOk()->json('lists');
        $this->assertSame(['want_to_read', 'favourites'], array_column($lists, 'kind'));
        $this->assertSame(['Want to read', 'Favourites'], array_column($lists, 'name'));
        // Again: the same two, never more.
        $this->assertSame(array_column($lists, 'id'), array_column($this->getJson('/api/v1/lists')->json('lists'), 'id'));
        $this->assertSame(2, ReadingList::count());
    }

    // --- The built-in list's rules -----------------------------------------

    public function test_it_cannot_be_renamed_or_deleted_or_duplicated(): void
    {
        $list = ReadingLists::favourites($this->owner()->id);

        $this->patchJson("/api/v1/lists/{$list->id}", ['name' => 'Loved'])->assertStatus(422)
            ->assertJsonPath('message', '“Favourites” is built in and can’t be renamed.');
        $this->deleteJson("/api/v1/lists/{$list->id}")->assertStatus(422)
            ->assertJsonPath('message', '“Favourites” is built in and can’t be deleted.');
        $this->assertSame('Favourites', $list->fresh()->name);

        // Its name is taken, whatever the case or spacing, for new lists and renames.
        $this->postJson('/api/v1/lists', ['name' => ' FAVOURITES  '])->assertStatus(422)
            ->assertJsonPath('message', '“Favourites” is already one of your lists.');
        $own = $this->postJson('/api/v1/lists', ['name' => 'Later'])->assertCreated()->json('list.id');
        $this->patchJson("/api/v1/lists/{$own}", ['name' => 'favourites'])->assertStatus(422)
            ->assertJsonPath('message', '“Favourites” is already one of your lists.');
        // "Want to read" stays reserved too.
        $this->postJson('/api/v1/lists', ['name' => 'want to read'])->assertStatus(422);

        // Its members can still be changed through the list routes.
        $novel = $this->novel();
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$novel->id}")->assertOk()->assertJsonPath('list.novel_ids', [$novel->id]);
        $this->assertSame([$novel->id], $this->favouriteIds());
    }

    public function test_it_is_outside_the_thirty_list_cap_and_made_once(): void
    {
        $owner = $this->owner();
        $first = ReadingLists::favourites($owner->id);
        $this->assertSame($first->id, ReadingLists::favourites($owner->id)->id);
        ReadingLists::wantToRead($owner->id);

        foreach (range(1, ReadingList::MAX_LISTS - 1) as $n) {
            ReadingList::create(['user_id' => $owner->id, 'name' => "List {$n}", 'name_key' => ReadingList::key("List {$n}")]);
        }
        // 29 own lists and both built-in ones: a 30th may still be made, not a 31st.
        $this->postJson('/api/v1/lists', ['name' => 'Thirtieth'])->assertCreated();
        $this->postJson('/api/v1/lists', ['name' => 'One more'])->assertStatus(422)
            ->assertJsonPath('message', 'You can have up to 30 lists. Delete one to make room.');

        // The (user_id, kind) key: a second one can never be inserted.
        $this->expectException(\Illuminate\Database\QueryException::class);
        ReadingList::create(['user_id' => $owner->id, 'name' => 'Favourites', 'name_key' => 'x', 'kind' => ReadingList::KIND_FAVOURITES]);
    }

    public function test_favourites_is_no_longer_a_starter(): void
    {
        $this->assertSame(['To read', 'Finished'], ReadingList::STARTERS);
        $this->assertNotContains('Favourites', ReadingList::STARTERS);
    }

    // --- Readers -----------------------------------------------------------

    public function test_readers_only_see_and_change_their_own(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $ownerToken = $this->tokenFor($owner);
        $readerToken = $this->tokenFor($reader);
        $novel = $this->novel();

        $this->asToken($ownerToken)->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $ownersList = ReadingList::where('user_id', $owner->id)->where('kind', 'favourites')->sole();

        $this->asToken($readerToken)->getJson('/api/v1/favourites')->assertOk()->assertJsonPath('items', []);
        $this->asToken($readerToken)->getJson("/api/v1/novels/{$novel->id}")->assertOk()->assertJsonPath('novel.favourite', false);
        $this->asToken($ownerToken)->getJson("/api/v1/novels/{$novel->id}")->assertOk()->assertJsonPath('novel.favourite', true);

        $this->asToken($readerToken)->deleteJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $this->asToken($readerToken)->deleteJson("/api/v1/lists/{$ownersList->id}/novels/{$novel->id}")->assertNotFound();
        $this->asToken($readerToken)->deleteJson("/api/v1/lists/{$ownersList->id}")->assertNotFound();
        $this->assertSame(1, DB::table('reading_list_novel')->where('reading_list_id', $ownersList->id)->count());

        $this->asToken($readerToken)->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $this->assertSame(2, ReadingList::where('kind', 'favourites')->count());
    }

    public function test_unauthenticated_calls_are_refused_with_sign_in_on(): void
    {
        $this->enableSignIn();
        $novel = $this->novel();

        $this->asNobody();
        $this->getJson('/api/v1/favourites')->assertUnauthorized();
        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertUnauthorized();
        $this->deleteJson("/api/v1/novels/{$novel->id}/favourite")->assertUnauthorized();
        $this->get('/favourites')->assertRedirect();
        $this->assertSame(0, ReadingList::count());
    }

    // --- Payloads ----------------------------------------------------------

    public function test_summaries_carry_the_flag_as_their_last_key(): void
    {
        $loved = $this->novel('Loved');
        $other = $this->novel('Other');
        $this->putJson("/api/v1/novels/{$loved->id}/favourite")->assertOk();

        $novels = collect($this->getJson('/api/v1/novels?medium=all')->assertOk()->json('novels'))->keyBy('id');
        $this->assertTrue($novels[$loved->id]['favourite']);
        $this->assertFalse($novels[$other->id]['favourite']);
        $this->assertSame(['audiobook', 'favourite'], array_slice(array_keys($novels[$loved->id]), -2));
        $this->getJson("/api/v1/novels/{$loved->id}")->assertJsonPath('novel.favourite', true);
        $this->getJson("/api/v1/novels/{$other->id}")->assertJsonPath('novel.favourite', false);

        // A soft-deleted series drops off the list (and back with a restore).
        $loved->delete();
        $this->getJson('/api/v1/favourites')->assertJsonPath('items', [])->assertJsonPath('list.count', 0);
        $loved->restore();
        $this->getJson('/api/v1/favourites')->assertJsonPath('list.count', 1);
    }

    public function test_the_flag_costs_no_statement_per_novel(): void
    {
        $count = function (int $novels): int {
            foreach (range(1, $novels) as $n) {
                $novel = $this->novel("Bulk {$n} " . uniqid());
                ReadingLists::setFavourite($this->owner()->id, $novel->id, true);
            }
            $this->getJson('/api/v1/novels')->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/novels')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($count(2), $count(6));
    }

    public function test_meta_offers_favourites_last_and_home_is_unchanged(): void
    {
        $meta = $this->getJson('/api/v1/meta')->assertOk()->json();
        $this->assertTrue($meta['favourites']);
        $this->assertSame('favourites', array_key_last($meta));

        $novel = $this->novel();
        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $this->assertArrayNotHasKey('favourites', $this->getJson('/api/v1/home')->assertOk()->json());
    }

    // --- Web -----------------------------------------------------------------

    public function test_the_series_page_has_the_heart(): void
    {
        $novel = $this->novel('Heart Me');
        $html = $this->get("/novels/{$novel->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="favouriteBtn"[^>]*data-favourite data-novel="' . $novel->id . '"[^>]*\s+aria-pressed="false" aria-label="Favourite"/', $html);
        // Beside the Want to read button.
        $this->assertLessThan(strpos($html, 'id="favouriteBtn"'), strpos($html, 'id="wantToReadBtn"'));
        // The page view makes no list.
        $this->assertSame(0, ReadingList::count());

        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $html = $this->get("/novels/{$novel->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="favouriteBtn" class="[^"]*is-favourite[^"]*"[^>]*aria-pressed="true" aria-label="Remove from favourites"/', $html);
        // The Lists menu holds the reader's own lists only.
        $this->assertStringNotContainsString('data-list-name="Favourites"', $html);
        $this->assertStringNotContainsString('data-starter="Favourites"', $html);
    }

    public function test_home_shows_the_shelf_above_want_to_read_only_when_there_are_favourites(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="favourites-section"', $html);
        $this->assertStringContainsString('id="want-to-read-section"', $html);

        $novel = $this->novel('On The Shelf');
        $manga = $this->novel('Shelf Manga', Novel::MEDIUM_MANGA);
        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $this->putJson("/api/v1/novels/{$manga->id}/favourite")->assertOk();

        $response = $this->get('/')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('id="favourites-section"', $html);
        $this->assertLessThan(strpos($html, 'id="want-to-read-section"'), strpos($html, 'id="favourites-section"'));
        $this->assertStringContainsString('href="' . route('favourites') . '" class="home-section-link">See all', $html);
        $this->assertStringContainsString('href="' . route('novels.show', $novel->id) . '" class="home-tile" aria-label="On The Shelf"', $html);
        $this->assertSame(2, $response->viewData('favourites')['count']);
        $this->assertSame([$manga->id, $novel->id], array_map(fn($i) => $i['novel']->id, $response->viewData('favourites')['items']));
    }

    public function test_the_favourites_page_lists_every_medium_or_explains(): void
    {
        $this->get('/favourites')->assertOk()
            ->assertSee('id="favouritesEmpty"', false)
            ->assertSee('Nothing here yet.', false);

        $book = $this->novel('Loved Book', Novel::MEDIUM_BOOK);
        $manga = $this->novel('Loved Manga', Novel::MEDIUM_MANGA);
        $this->putJson("/api/v1/novels/{$book->id}/favourite")->assertOk();
        $this->travel(1)->minutes();
        $this->putJson("/api/v1/novels/{$manga->id}/favourite")->assertOk();

        $response = $this->get('/favourites')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('id="favouritesGrid"', $html);
        $this->assertSame([$manga->id, $book->id], array_map(fn($i) => $i['novel']->id, $response->viewData('favourites')['items']));
        $this->assertLessThan(strpos($html, 'Loved Book'), strpos($html, 'Loved Manga'));
        $this->assertMatchesRegularExpression('/data-favourite data-novel="' . $manga->id . '"[^>]*\s+aria-pressed="true"/', $html);
    }

    public function test_the_nav_links_to_the_page(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('favourites') . '"', $html);
        $this->assertStringContainsString('>Favourites</a>', $html);
    }

    public function test_the_library_list_filter_offers_it_without_rename_or_delete(): void
    {
        $novel = $this->novel('Filtered');
        $this->putJson("/api/v1/novels/{$novel->id}/favourite")->assertOk();
        $list = ReadingList::where('kind', 'favourites')->sole();

        $html = $this->get("/novels?medium=novel&view=grid&list={$list->id}")->assertOk()->getContent();
        $this->assertStringContainsString('data-list-title>Favourites</h2>', $html);
        $this->assertStringNotContainsString('data-list-bar', $html);
        $this->assertStringNotContainsString('data-list-delete', $html);
        $this->assertStringNotContainsString('data-list-rename', $html);
        $this->assertStringContainsString('href="' . route('favourites') . '" class="btn btn-ghost btn-sm">Every medium', $html);
        $this->assertStringContainsString('>Filtered<', $html);
    }

    public function test_a_reader_sees_only_their_own_on_the_web(): void
    {
        $this->enableSignIn();
        $novel = $this->novel('Owner Pick');
        ReadingLists::setFavourite($this->owner()->id, $novel->id, true);
        $this->makeReader();

        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->get('/favourites')->assertOk()->assertDontSee('Owner Pick');
        $this->withBrowser($cookies)->get("/novels/{$novel->id}")->assertOk()->assertSee('aria-label="Favourite"', false);
    }

    // --- The migration -----------------------------------------------------

    public function test_the_migration_turns_an_own_favourites_list_into_the_built_in_one(): void
    {
        $owner = $this->owner();
        $reader = $this->makeReader();
        $other = $this->makeReader('pat');
        $a = $this->novel('A');
        $b = $this->novel('B');

        // The owner: an own "favourites" list (any case) with members.
        $ownersOwn = ReadingList::create(['user_id' => $owner->id, 'name' => 'FAVOURITES', 'name_key' => 'favourites']);
        DB::table('reading_list_novel')->insert([
            ['reading_list_id' => $ownersOwn->id, 'novel_id' => $a->id, 'added_at' => now()->subDay()],
            ['reading_list_id' => $ownersOwn->id, 'novel_id' => $b->id, 'added_at' => now()],
        ]);
        // A reader with both an own one and the built-in one already: both stay.
        $readersOwn = ReadingList::create(['user_id' => $reader->id, 'name' => 'Favourites', 'name_key' => 'favourites']);
        $readersBuiltIn = ReadingLists::favourites($reader->id);
        // Another reader with other lists only: nothing happens.
        $othersList = ReadingList::create(['user_id' => $other->id, 'name' => 'To read', 'name_key' => 'to read']);

        $migration = $this->migration();
        $migration->up();
        $migration->up(); // idempotent

        $ownersOwn->refresh();
        $this->assertSame('favourites', $ownersOwn->kind);
        $this->assertSame('Favourites', $ownersOwn->name);
        $this->assertSame(ReadingList::FAVOURITES_KEY, $ownersOwn->name_key);
        $this->assertSame([$b->id, $a->id], ReadingLists::present($ownersOwn)['novel_ids']);
        $this->assertSame($ownersOwn->id, ReadingLists::favourites($owner->id)->id);
        $this->assertNull($readersOwn->fresh()->kind);
        $this->assertSame('favourites', $readersBuiltIn->fresh()->kind);
        $this->assertNull($othersList->fresh()->kind);
        $this->assertSame(0, ReadingList::where('user_id', $other->id)->where('kind', 'favourites')->count());

        // The heart and the API see the converted list.
        $this->getJson("/api/v1/novels/{$a->id}")->assertJsonPath('novel.favourite', true);
        $this->getJson('/api/v1/favourites')->assertJsonPath('list.id', $ownersOwn->id)->assertJsonPath('list.count', 2);

        // down(): back to an own list where the name is free.
        $migration->down();
        $ownersOwn->refresh();
        $this->assertNull($ownersOwn->kind);
        $this->assertSame('favourites', $ownersOwn->name_key);
        $this->assertSame(2, DB::table('reading_list_novel')->where('reading_list_id', $ownersOwn->id)->count());
        // The reader's own "Favourites" holds the name: their built-in one stays.
        $this->assertSame('favourites', $readersBuiltIn->fresh()->kind);
    }
}
