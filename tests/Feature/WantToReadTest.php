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
 * "Want to read": every reader's built-in reading list (App\Services\
 * ReadingLists::wantToRead()) — the API (/api/v1/novels/{id}/want-to-read,
 * /api/v1/want-to-read, the want_to_read flag, Home's shelf) and the web
 * (the novel page's button, Home's shelf, /want-to-read). Sign-in off (every
 * call is the owner) unless a test turns it on.
 */
class WantToReadTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name = 'Wanted Novel', string $medium = Novel::MEDIUM_NOVEL): Novel
    {
        return Novel::create(['name' => $name, 'status' => 0, 'group_id' => 1, 'medium' => $medium]);
    }

    private function wantedIds(): array
    {
        return ReadingList::where('kind', ReadingList::KIND_WANT_TO_READ)->get()
            ->flatMap(fn($l) => DB::table('reading_list_novel')->where('reading_list_id', $l->id)->pluck('novel_id'))
            ->map(fn($id) => (int) $id)->sort()->values()->all();
    }

    // --- API: the toggle ---------------------------------------------------

    public function test_toggle_is_idempotent_and_answers_the_list(): void
    {
        $novel = $this->novel();

        $response = $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $list = ReadingList::where('kind', 'want_to_read')->sole();
        $this->assertSame([
            'want_to_read' => true,
            'list' => ['id' => $list->id, 'name' => 'Want to read', 'count' => 1, 'novel_ids' => [$novel->id], 'kind' => 'want_to_read'],
        ], $response->json());
        $this->assertSame($this->owner()->id, $list->user_id);

        // Again: still one row, same answer.
        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk()->assertJsonPath('list.count', 1);
        $this->assertSame(1, DB::table('reading_list_novel')->count());
        $this->assertSame(1, ReadingList::count());

        $this->deleteJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk()
            ->assertJsonPath('want_to_read', false)->assertJsonPath('list.count', 0)->assertJsonPath('list.novel_ids', []);
        $this->deleteJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk()->assertJsonPath('list.count', 0);
        // The list stays (empty), never a second one.
        $this->assertSame(1, ReadingList::count());
    }

    public function test_unknown_series_is_404(): void
    {
        $this->putJson('/api/v1/novels/999/want-to-read')->assertNotFound()->assertJsonPath('message', 'Series not found.');
        $this->deleteJson('/api/v1/novels/999/want-to-read')->assertNotFound();

        $gone = $this->novel('Gone');
        $gone->delete();
        $this->putJson("/api/v1/novels/{$gone->id}/want-to-read")->assertNotFound();
    }

    public function test_it_holds_every_medium_newest_first(): void
    {
        $novel = $this->novel('A Novel');
        $manga = $this->novel('A Manga', Novel::MEDIUM_MANGA);
        $book = $this->novel('A Book', Novel::MEDIUM_BOOK);

        $this->putJson("/api/v1/novels/{$manga->id}/want-to-read")->assertOk();
        $this->travel(1)->minutes();
        $this->putJson("/api/v1/novels/{$book->id}/want-to-read")->assertOk();
        $this->travel(1)->minutes();
        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk()
            ->assertJsonPath('list.novel_ids', [$novel->id, $book->id, $manga->id]);

        // Listed first among the reader's lists, same order.
        $this->postJson('/api/v1/lists', ['name' => 'Aardvarks'])->assertCreated();
        $lists = $this->getJson('/api/v1/lists')->assertOk()->json('lists');
        $this->assertSame(['want_to_read', null], array_column($lists, 'kind'));
        $this->assertSame([$novel->id, $book->id, $manga->id], $lists[0]['novel_ids']);

        // The whole list (default: every medium), and one medium at a time.
        $items = $this->getJson('/api/v1/want-to-read')->assertOk()->json('items');
        $this->assertSame([$novel->id, $book->id, $manga->id], array_map(fn($i) => $i['novel']['id'], $items));
        $this->assertSame([$manga->id], array_map(fn($i) => $i['novel']['id'], $this->getJson('/api/v1/want-to-read?medium=manga')->json('items')));
        $this->assertSame([$novel->id, $book->id], array_map(fn($i) => $i['novel']['id'], $this->getJson('/api/v1/want-to-read?medium=text')->json('items')));
        $this->getJson('/api/v1/want-to-read?medium=films')->assertStatus(422);
    }

    public function test_the_whole_list_payload_and_started(): void
    {
        [$read] = $this->novelWithChapters('Started By Reading', 2);
        [$progress, $progressChapters] = $this->novelWithChapters('Started By Progress', 2);
        $fresh = $this->novel('Not Started');
        foreach ([$fresh, $progress, $read] as $n) {
            $this->putJson("/api/v1/novels/{$n->id}/want-to-read")->assertOk();
            $this->travel(1)->minutes();
        }

        // Reading a chapter (and partial progress in one) keeps it on the list.
        $chapter = $read->chapters()->orderBy('chapter')->first();
        $this->postJson("/api/v1/chapters/{$chapter->id}/read", ['read' => true])->assertOk();
        $this->setReadState($progressChapters[1], ['progress' => 40, 'progress_at' => now()]);

        $json = $this->getJson('/api/v1/want-to-read')->assertOk()->json();
        $this->assertSame(['items', 'list'], array_keys(collect($json)->sortKeys()->all()));
        $this->assertSame('want_to_read', $json['list']['kind']);
        $this->assertSame(3, $json['list']['count']);

        $this->assertSame([$read->id, $progress->id, $fresh->id], array_map(fn($i) => $i['novel']['id'], $json['items']));
        foreach ($json['items'] as $item) {
            $this->assertSame(['added_at', 'novel', 'started'], array_keys(collect($item)->sortKeys()->all()));
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['added_at']);
            $this->assertTrue($item['novel']['want_to_read']);
        }
        $this->assertSame([true, true, false], array_column($json['items'], 'started'));
    }

    public function test_an_unused_list_answers_empty_and_the_whole_list_endpoint_makes_it(): void
    {
        $this->assertSame(0, ReadingList::count());
        $this->getJson('/api/v1/want-to-read')->assertOk()
            ->assertJsonPath('items', [])->assertJsonPath('list.kind', 'want_to_read')->assertJsonPath('list.count', 0);
        $this->assertSame(1, ReadingList::count());
    }

    // --- The built-in list's rules -----------------------------------------

    public function test_it_cannot_be_renamed_or_deleted_or_duplicated(): void
    {
        $list = ReadingLists::wantToRead($this->owner()->id);

        $this->patchJson("/api/v1/lists/{$list->id}", ['name' => 'Someday'])->assertStatus(422)
            ->assertJsonPath('message', '“Want to read” is built in and can’t be renamed.');
        $this->deleteJson("/api/v1/lists/{$list->id}")->assertStatus(422)
            ->assertJsonPath('message', '“Want to read” is built in and can’t be deleted.');
        $this->assertSame('Want to read', $list->fresh()->name);

        // Its name is taken, whatever the case or spacing, for new lists and renames.
        $this->postJson('/api/v1/lists', ['name' => ' WANT  to read '])->assertStatus(422)
            ->assertJsonPath('message', '“Want to read” is already one of your lists.');
        $own = $this->postJson('/api/v1/lists', ['name' => 'Later'])->assertCreated()->json('list.id');
        $this->patchJson("/api/v1/lists/{$own}", ['name' => 'want to read'])->assertStatus(422);

        // Its members can still be changed through the list routes.
        $novel = $this->novel();
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$novel->id}")->assertOk()->assertJsonPath('list.novel_ids', [$novel->id]);
        $this->assertSame([$novel->id], $this->wantedIds());
    }

    public function test_it_is_outside_the_thirty_list_cap_and_made_once(): void
    {
        $owner = $this->owner();
        $first = ReadingLists::wantToRead($owner->id);
        $this->assertSame($first->id, ReadingLists::wantToRead($owner->id)->id);

        foreach (range(1, ReadingList::MAX_LISTS) as $n) {
            ReadingList::create(['user_id' => $owner->id, 'name' => "List {$n}", 'name_key' => ReadingList::key("List {$n}")]);
        }
        $this->postJson('/api/v1/lists', ['name' => 'One more'])->assertStatus(422)
            ->assertJsonPath('message', 'You can have up to 30 lists. Delete one to make room.');

        // A reader with 29 lists and "Want to read" may still make a 30th.
        ReadingList::where('name', 'List 30')->delete();
        $this->postJson('/api/v1/lists', ['name' => 'Thirtieth'])->assertCreated();

        // The (user_id, kind) key: a second one can never be inserted.
        $this->expectException(\Illuminate\Database\QueryException::class);
        ReadingList::create(['user_id' => $owner->id, 'name' => 'Want to read', 'name_key' => 'x', 'kind' => ReadingList::KIND_WANT_TO_READ]);
    }

    public function test_an_older_list_called_want_to_read_does_not_clash(): void
    {
        $owner = $this->owner();
        ReadingList::create(['user_id' => $owner->id, 'name' => 'Want to read', 'name_key' => ReadingList::key('Want to read')]);

        $novel = $this->novel();
        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk()->assertJsonPath('list.kind', 'want_to_read');
        $this->assertSame(2, ReadingList::count());
    }

    // --- Readers -----------------------------------------------------------

    public function test_readers_only_see_and_change_their_own(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $ownerToken = $this->tokenFor($owner);
        $readerToken = $this->tokenFor($reader);
        $novel = $this->novel();

        $this->asToken($ownerToken)->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $ownersList = ReadingList::where('user_id', $owner->id)->where('kind', 'want_to_read')->sole();

        // The reader's own list is empty and the series is not flagged for them.
        $this->asToken($readerToken)->getJson('/api/v1/want-to-read')->assertOk()->assertJsonPath('items', []);
        $this->asToken($readerToken)->getJson("/api/v1/novels/{$novel->id}")->assertOk()->assertJsonPath('novel.want_to_read', false);
        $this->asToken($ownerToken)->getJson("/api/v1/novels/{$novel->id}")->assertOk()->assertJsonPath('novel.want_to_read', true);

        // Taking it off their own list leaves the owner's alone…
        $this->asToken($readerToken)->deleteJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $this->assertSame(1, DB::table('reading_list_novel')->where('reading_list_id', $ownersList->id)->count());
        // …and the owner's list is not theirs to change, through the list routes either.
        $this->asToken($readerToken)->deleteJson("/api/v1/lists/{$ownersList->id}/novels/{$novel->id}")->assertNotFound();
        $this->asToken($readerToken)->patchJson("/api/v1/lists/{$ownersList->id}", ['name' => 'x'])->assertNotFound();
        $this->asToken($readerToken)->deleteJson("/api/v1/lists/{$ownersList->id}")->assertNotFound();
        $this->assertSame(1, DB::table('reading_list_novel')->where('reading_list_id', $ownersList->id)->count());

        // Each reader has their own.
        $this->asToken($readerToken)->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $this->assertSame(2, ReadingList::where('kind', 'want_to_read')->count());
    }

    public function test_unauthenticated_calls_are_refused_with_sign_in_on(): void
    {
        $this->enableSignIn();
        $novel = $this->novel();

        $this->asNobody();
        $this->getJson('/api/v1/want-to-read')->assertUnauthorized();
        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertUnauthorized();
        $this->deleteJson("/api/v1/novels/{$novel->id}/want-to-read")->assertUnauthorized();
        $this->get('/want-to-read')->assertRedirect();
        $this->assertSame(0, ReadingList::count());
    }

    // --- Payloads ----------------------------------------------------------

    public function test_summaries_carry_the_flag(): void
    {
        $wanted = $this->novel('Wanted');
        $other = $this->novel('Other');
        $this->putJson("/api/v1/novels/{$wanted->id}/want-to-read")->assertOk();

        $flags = collect($this->getJson('/api/v1/novels?medium=all')->assertOk()->json('novels'))->pluck('want_to_read', 'id')->all();
        $this->assertSame([$wanted->id => true, $other->id => false], [$wanted->id => $flags[$wanted->id], $other->id => $flags[$other->id]]);
        $this->getJson("/api/v1/novels/{$wanted->id}")->assertJsonPath('novel.want_to_read', true);
        $this->getJson("/api/v1/novels/{$other->id}")->assertJsonPath('novel.want_to_read', false);

        // A soft-deleted series drops off the list (and back with a restore).
        $wanted->delete();
        $this->getJson('/api/v1/want-to-read')->assertJsonPath('items', [])->assertJsonPath('list.count', 0);
        $wanted->restore();
        $this->getJson('/api/v1/want-to-read')->assertJsonPath('list.count', 1);
    }

    public function test_home_has_the_shelf_newest_first_up_to_twelve(): void
    {
        $ids = [];
        foreach (range(1, 14) as $n) {
            $novel = $this->novel(sprintf('Wanted %02d', $n));
            $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
            $ids[] = $novel->id;
            $this->travel(1)->minutes();
        }
        $manga = $this->novel('Wanted Manga', Novel::MEDIUM_MANGA);
        $this->putJson("/api/v1/novels/{$manga->id}/want-to-read")->assertOk();

        // The default medium (novels): 12 of its 14, newest first.
        $json = $this->getJson('/api/v1/home')->assertOk()->json();
        $this->assertSame(14, $json['want_to_read_count']);
        $this->assertSame(array_slice(array_reverse($ids), 0, 12), array_map(fn($i) => $i['novel']['id'], $json['want_to_read']));
        $this->assertSame(['added_at', 'novel', 'started'], array_keys(collect($json['want_to_read'][0])->sortKeys()->all()));

        $all = $this->getJson('/api/v1/home?medium=all')->assertOk()->json();
        $this->assertSame(15, $all['want_to_read_count']);
        $this->assertSame($manga->id, $all['want_to_read'][0]['novel']['id']);
    }

    // --- Web -----------------------------------------------------------------

    public function test_the_novel_page_has_the_toggle(): void
    {
        $novel = $this->novel('Toggle Me');
        $html = $this->get("/novels/{$novel->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="wantToReadBtn"[^>]*data-want-to-read data-novel="' . $novel->id . '"[^>]*\s+aria-pressed="false"/', $html);
        $this->assertStringContainsString('<span>Want to read</span>', $html);
        // The page view makes no list.
        $this->assertSame(0, ReadingList::count());

        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $html = $this->get("/novels/{$novel->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="wantToReadBtn" class="[^"]*is-wanted[^"]*"[^>]*aria-pressed="true"/', $html);

        // The Lists menu holds the reader's own lists only: still the starters.
        $this->assertStringContainsString('data-starter="Favourites"', $html);
        $this->assertStringNotContainsString('data-list-name="Want to read"', $html);
    }

    public function test_home_shows_the_shelf_or_how_to_fill_it(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('id="want-to-read-section"', $html);
        $this->assertStringContainsString('Shortlist what to read next', $html);
        $this->assertStringNotContainsString('See all', $html);

        $novel = $this->novel('On The Shelf');
        $manga = $this->novel('Shelf Manga', Novel::MEDIUM_MANGA);
        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $this->putJson("/api/v1/novels/{$manga->id}/want-to-read")->assertOk();

        $response = $this->get('/')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('href="' . route('want_to_read') . '" class="home-section-link">See all', $html);
        $this->assertStringContainsString('href="' . route('novels.show', $novel->id) . '" class="home-tile" aria-label="On The Shelf"', $html);
        $this->assertStringContainsString('Shelf Manga', $html);
        $this->assertStringNotContainsString('Shortlist what to read next', $html);
        $this->assertSame(2, $response->viewData('want_to_read')['count']);
    }

    public function test_the_want_to_read_page_lists_every_medium_or_explains(): void
    {
        $this->get('/want-to-read')->assertOk()
            ->assertSee('id="wantToReadEmpty"', false)
            ->assertSee('Nothing here yet.', false);

        [$started, $chapters] = $this->novelWithChapters('Begun Book', 1, Novel::MEDIUM_BOOK);
        $manga = $this->novel('Paged Manga', Novel::MEDIUM_MANGA);
        $this->putJson("/api/v1/novels/{$started->id}/want-to-read")->assertOk();
        $this->travel(1)->minutes();
        $this->putJson("/api/v1/novels/{$manga->id}/want-to-read")->assertOk();
        $this->setReadState($chapters[1], ['read_at' => now()]);

        $response = $this->get('/want-to-read')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('id="wantToReadGrid"', $html);
        $this->assertSame([$manga->id, $started->id], array_map(fn($i) => $i['novel']->id, $response->viewData('want_to_read')['items']));
        $this->assertLessThan(strpos($html, 'Begun Book'), strpos($html, 'Paged Manga'));
        $this->assertStringContainsString('<span class="wtr-started">Started</span>', $html);
        $this->assertMatchesRegularExpression('/data-want-to-read data-novel="' . $manga->id . '"[^>]*\s+aria-pressed="true"/', $html);
    }

    public function test_the_library_list_filter_offers_it_without_rename_or_delete(): void
    {
        $novel = $this->novel('Filtered');
        $this->putJson("/api/v1/novels/{$novel->id}/want-to-read")->assertOk();
        $list = ReadingList::where('kind', 'want_to_read')->sole();

        $html = $this->get("/novels?medium=novel&view=grid&list={$list->id}")->assertOk()->getContent();
        $this->assertStringContainsString('data-list-title>Want to read</h2>', $html);
        $this->assertStringNotContainsString('data-list-bar', $html);
        $this->assertStringNotContainsString('data-list-delete', $html);
        $this->assertStringNotContainsString('data-list-rename', $html);
        $this->assertStringContainsString('href="' . route('want_to_read') . '"', $html);
    }

    public function test_a_reader_sees_only_their_own_on_the_web(): void
    {
        $this->enableSignIn();
        $novel = $this->novel('Owner Pick');
        ReadingLists::setWantToRead($this->owner()->id, $novel->id, true);
        $reader = $this->makeReader();

        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->get('/want-to-read')->assertOk()->assertDontSee('Owner Pick');
        $this->withBrowser($cookies)->get("/novels/{$novel->id}")->assertOk()->assertSee('aria-pressed="false"', false);
    }
}
