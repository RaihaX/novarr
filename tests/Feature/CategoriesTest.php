<?php

namespace Tests\Feature;

use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use App\NovelRequest;
use App\ReadingList;
use App\Scraping\Fetcher;
use App\Services\ReadState;
use App\Services\Requests\RequestNotifier;
use App\Services\Requests\Requests;
use App\Support\RouteAccess;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeFetcher;
use Tests\Support\FakeMangaA;
use Tests\Support\FakeMangaB;
use Tests\Support\FakeRequestSource;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Categories (Mihon-style, docs/plans/categories-and-home.md): reading
 * lists with a reader-defined order and Home visibility, many series in /
 * out of many categories in one call, whole series read / unread at once,
 * and categories chosen on a request (long-press in Browse) that the series
 * is put in when the request becomes available — for the requester and
 * every "Me too" follower who chose some.
 */
class CategoriesTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private User $owner;

    private User $sam;

    private User $kim;

    private string $ownerToken;

    private string $samToken;

    private string $kimToken;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FakeRequestSource::register();
        $this->app->instance(Fetcher::class, new FakeFetcher());
        Bus::fake([RunNovelCommand::class, \App\Jobs\FinishNovelCheck::class]);
        Mail::fake();
        $this->app->instance(RequestNotifier::class, new class extends RequestNotifier {
            protected function webhook(string $message): void
            {
            }
        });

        $this->owner = $this->enableSignIn();
        $this->sam = $this->makeReader();
        $this->kim = $this->makeReader('kim', 'Kim');
        $this->ownerToken = $this->tokenFor($this->owner);
        $this->samToken = $this->tokenFor($this->sam);
        $this->kimToken = $this->tokenFor($this->kim);

        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo']];
        FakeRequestSource::$search[FakeMangaB::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-b.test/solo', 'chapters' => 200]];
        FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => ['1', '2'], 'updated_at' => now()->subDays(3)->toIso8601String()];
        FakeRequestSource::$stats['https://manga-b.test/solo'] = ['numbers' => array_map('strval', range(1, 200)), 'updated_at' => now()->subDays(40)->toIso8601String()];
    }

    protected function tearDown(): void
    {
        FakeRequestSource::reset();
        parent::tearDown();
    }

    private function novel(string $name = 'Listed Novel'): Novel
    {
        return Novel::create(['name' => $name, 'status' => 0, 'group_id' => 1, 'medium' => Novel::MEDIUM_NOVEL]);
    }

    private function category(string $token, string $name): int
    {
        return $this->asToken($token)->postJson('/api/v1/lists', ['name' => $name])->assertCreated()->json('list.id');
    }

    private function chapters(Novel $novel, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $chapter = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $i, 'book' => 0, 'label' => "Chapter {$i}", 'url' => "https://example.test/{$novel->id}/{$i}"]);
            $chapter->forceFill(['status' => 1, 'download_date' => now()])->save();
            $ids[] = $chapter->id;
        }

        return $ids;
    }

    /** $token's finished manga search for "solo": [search id, the Solo Leveling result]. */
    private function search(string $token): array
    {
        $id = $this->asToken($token)->postJson('/api/v1/requests/searches', ['q' => 'solo', 'medium' => 'manga'])->assertStatus(202)->json('search.id');
        $search = $this->asToken($token)->getJson("/api/v1/requests/searches/{$id}")->assertOk()->json('search');

        return [$id, $search['results'][0]];
    }

    private function requestFromSearch(string $token, array $extra = [])
    {
        [$id, $result] = $this->search($token);

        return $this->asToken($token)->postJson('/api/v1/requests', ['search_id' => $id, 'key' => $result['key']] + $extra);
    }

    // ---- Order and Home visibility ----------------------------------------------

    public function test_lists_carry_position_and_hidden_on_home_and_can_be_reordered(): void
    {
        $classics = $this->category($this->samToken, 'Classics');
        $later = $this->category($this->samToken, 'Later');
        $lists = $this->asToken($this->samToken)->getJson('/api/v1/lists')->assertOk()->json('lists');
        [$wanted, $favourites] = [$lists[0]['id'], $lists[1]['id']];
        // Before any reorder: built-ins first, then by name; positions 0..n.
        $this->assertSame([$wanted, $favourites, $classics, $later], array_column($lists, 'id'));
        $this->assertSame([0, 1, 2, 3], array_column($lists, 'position'));
        $this->assertSame([false, false, false, false], array_column($lists, 'hidden_on_home'));

        // Only some ids: those first, the rest after in their old order.
        $order = $this->asToken($this->samToken)->patchJson('/api/v1/lists/order', ['ids' => [$later, $favourites]])->assertOk()->json('lists');
        $this->assertSame([$later, $favourites, $wanted, $classics], array_column($order, 'id'));
        $this->assertSame([0, 1, 2, 3], array_column($order, 'position'));
        $this->assertSame([$later, $favourites, $wanted, $classics], array_column($this->asToken($this->samToken)->getJson('/api/v1/lists')->json('lists'), 'id'));

        // A new category goes last once the reader has an order.
        $new = $this->category($this->samToken, 'Aardvark');
        $this->assertSame([$later, $favourites, $wanted, $classics, $new], array_column($this->asToken($this->samToken)->getJson('/api/v1/lists')->json('lists'), 'id'));

        // Someone else's id: 404, nothing changes.
        $theirs = $this->category($this->kimToken, 'Kim’s');
        $this->asToken($this->samToken)->patchJson('/api/v1/lists/order', ['ids' => [$classics, $theirs]])->assertNotFound();
        $this->assertSame([$later, $favourites, $wanted, $classics, $new], array_column($this->asToken($this->samToken)->getJson('/api/v1/lists')->json('lists'), 'id'));
        $this->asToken($this->samToken)->patchJson('/api/v1/lists/order', ['ids' => 'nope'])->assertStatus(422);
        $this->asToken($this->samToken)->patchJson('/api/v1/lists/order', ['ids' => [$classics, $classics]])->assertOk()
            ->assertJsonPath('lists.0.id', $classics);
    }

    public function test_any_category_can_be_hidden_on_home_but_a_built_in_is_never_renamed(): void
    {
        $lists = $this->asToken($this->samToken)->getJson('/api/v1/lists')->json('lists');
        $wanted = $lists[0]['id'];
        $this->asToken($this->samToken)->patchJson("/api/v1/lists/{$wanted}", ['hidden_on_home' => true])->assertOk()
            ->assertJsonPath('list.hidden_on_home', true)->assertJsonPath('list.name', 'Want to read')->assertJsonPath('list.position', 0);
        $this->assertTrue((bool) ReadingList::find($wanted)->hidden_on_home);
        $this->asToken($this->samToken)->patchJson("/api/v1/lists/{$wanted}", ['hidden_on_home' => true, 'name' => 'Someday'])->assertStatus(422);
        $this->asToken($this->samToken)->patchJson("/api/v1/lists/{$wanted}", ['hidden_on_home' => false])->assertOk()->assertJsonPath('list.hidden_on_home', false);

        $own = $this->category($this->samToken, 'Classics');
        $this->asToken($this->samToken)->patchJson("/api/v1/lists/{$own}", ['name' => 'Old classics', 'hidden_on_home' => true])->assertOk()
            ->assertJsonPath('list.name', 'Old classics')->assertJsonPath('list.hidden_on_home', true);
        // No field at all is still a rename without a name.
        $this->asToken($this->samToken)->patchJson("/api/v1/lists/{$own}", [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->asToken($this->samToken)->patchJson("/api/v1/lists/{$own}", ['hidden_on_home' => 'maybe'])->assertStatus(422);
        // Someone else's: 404.
        $this->asToken($this->kimToken)->patchJson("/api/v1/lists/{$own}", ['hidden_on_home' => false])->assertNotFound();
        $this->assertTrue((bool) ReadingList::find($own)->hidden_on_home);
    }

    // ---- Memberships -------------------------------------------------------------

    public function test_memberships_add_and_remove_many_series_in_one_call(): void
    {
        [$a, $b, $c] = [$this->novel('A'), $this->novel('B'), $this->novel('C')];
        $classics = $this->category($this->samToken, 'Classics');
        $later = $this->category($this->samToken, 'Later');
        $lists = $this->asToken($this->samToken)->getJson('/api/v1/lists')->json('lists');
        $favourites = $lists[1]['id'];

        $body = ['novel_ids' => [$a->id, $b->id], 'add' => [$classics, $favourites], 'remove' => []];
        $lists = $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', $body)->assertOk()->json('lists');
        $byId = collect($lists)->keyBy('id');
        $this->assertSame([$a->id, $b->id], $byId[$classics]['novel_ids']);
        $this->assertSame(2, $byId[$favourites]['count']);
        $this->assertSame(['id', 'name', 'count', 'novel_ids', 'kind', 'position', 'hidden_on_home'], array_keys($lists[0]));

        // Idempotent: again, same rows (and their added_at is kept).
        $addedAt = DB::table('reading_list_novel')->where('reading_list_id', $classics)->where('novel_id', $a->id)->value('added_at');
        $this->travel(5)->minutes();
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', $body)->assertOk();
        $this->assertSame(4, DB::table('reading_list_novel')->count());
        $this->assertSame($addedAt, DB::table('reading_list_novel')->where('reading_list_id', $classics)->where('novel_id', $a->id)->value('added_at'));

        // Move B and C: into Later, out of Classics (C was in none: fine).
        $lists = $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$b->id, $c->id], 'add' => [$later], 'remove' => [$classics]])->assertOk()->json('lists');
        $byId = collect($lists)->keyBy('id');
        $this->assertSame([$a->id], $byId[$classics]['novel_ids']);
        $this->assertSame([$b->id, $c->id], $byId[$later]['novel_ids']);
        $this->assertSame(2, $byId[$favourites]['count']);
    }

    public function test_memberships_refuse_foreign_lists_unknown_series_and_contradictions(): void
    {
        $a = $this->novel('A');
        $mine = $this->category($this->samToken, 'Classics');
        $theirs = $this->category($this->kimToken, 'Kim’s');

        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id], 'add' => [$mine, $theirs], 'remove' => []])->assertNotFound();
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id], 'add' => [], 'remove' => [$theirs]])->assertNotFound();
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id, 999999], 'add' => [$mine], 'remove' => []])->assertNotFound();
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id], 'add' => [$mine], 'remove' => [$mine]])->assertStatus(422);
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [], 'add' => [$mine], 'remove' => []])->assertStatus(422);
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id]])->assertStatus(422);
        $this->assertSame(0, DB::table('reading_list_novel')->count(), 'nothing changed on an error');

        // A soft-deleted series can't be added.
        $gone = $this->novel('Gone');
        $gone->delete();
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$gone->id], 'add' => [$mine], 'remove' => []])->assertNotFound();

        // A full category: 422, nothing added.
        $full = ReadingList::find($mine);
        $fill = [];
        for ($i = 0; $i < ReadingList::MAX_NOVELS; $i++) {
            $fill[] = ['reading_list_id' => $full->id, 'novel_id' => $a->id + 1000 + $i, 'added_at' => now()];
        }
        // Live members only count: give them real (cheap) rows.
        DB::table('novels')->insert(array_map(fn($row) => ['id' => $row['novel_id'], 'name' => 'N' . $row['novel_id'], 'status' => 0, 'group_id' => 1, 'medium' => 'novel', 'created_at' => now(), 'updated_at' => now()], $fill));
        DB::table('reading_list_novel')->insert($fill);
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id], 'add' => [$mine], 'remove' => []])->assertStatus(422);
        $this->assertFalse(DB::table('reading_list_novel')->where('reading_list_id', $mine)->where('novel_id', $a->id)->exists());

        // Signed out: 401.
        $this->asNobody()->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id], 'add' => [$mine], 'remove' => []])->assertUnauthorized();
    }

    // ---- Whole series read / unread ---------------------------------------------

    public function test_bulk_read_marks_whole_series_read_and_unread_for_the_caller_only(): void
    {
        [$a, $b] = [$this->novel('A'), $this->novel('B')];
        $aChapters = $this->chapters($a, 3);
        $this->chapters($b, 2);
        // Sam read A's first chapter yesterday: its date is kept.
        app(ReadState::class)->markMany(NovelChapter::whereKey($aChapters[0]), true, now()->subDay(), userId: (int) $this->sam->id);
        $yesterday = DB::table(ReadState::TABLE)->where('user_id', $this->sam->id)->where('novel_chapter_id', $aChapters[0])->value('read_at');
        $this->travel(1)->hours();

        $this->asToken($this->samToken)->postJson('/api/v1/novels/bulk-read', ['ids' => [$a->id, $b->id], 'read' => true])->assertOk()
            ->assertExactJson(['success' => true, 'count' => 2, 'chapters' => 4]);
        $this->assertSame(5, DB::table(ReadState::TABLE)->where('user_id', $this->sam->id)->whereNotNull('read_at')->count());
        $this->assertSame($yesterday, DB::table(ReadState::TABLE)->where('user_id', $this->sam->id)->where('novel_chapter_id', $aChapters[0])->value('read_at'));
        $this->assertSame(0, DB::table(ReadState::TABLE)->where('user_id', $this->kim->id)->count(), 'another reader is untouched');

        $this->asToken($this->samToken)->postJson('/api/v1/novels/bulk-read', ['ids' => [$a->id], 'read' => false])->assertOk()
            ->assertJsonPath('chapters', 3);
        $this->assertSame(2, DB::table(ReadState::TABLE)->where('user_id', $this->sam->id)->whereNotNull('read_at')->count());

        $this->asToken($this->samToken)->postJson('/api/v1/novels/bulk-read', ['ids' => [999999], 'read' => true])->assertStatus(422);
        $this->asToken($this->samToken)->postJson('/api/v1/novels/bulk-read', ['ids' => [$a->id]])->assertStatus(422);
        $this->asToken($this->samToken)->postJson('/api/v1/novels/bulk-read', ['ids' => [], 'read' => true])->assertStatus(422);
    }

    // ---- Categories chosen on a request ------------------------------------------

    public function test_categories_chosen_on_a_request_are_applied_for_requester_and_followers_when_available(): void
    {
        $samCat = $this->category($this->samToken, 'Manhwa');
        $samFav = $this->asToken($this->samToken)->getJson('/api/v1/lists')->json('lists.1.id');
        $kimCat = $this->category($this->kimToken, 'Action');

        $id = $this->requestFromSearch($this->samToken, ['categories' => [$samCat, $samFav]])->assertCreated()
            ->assertJsonPath('request.requested_categories', [$samCat, $samFav])->json('request.id');
        // Kim joins ("Me too") with her own category.
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga', 'categories' => [$kimCat]])
            ->assertOk()->assertJsonPath('joined', true)->assertJsonPath('request.requested_categories', [$kimCat]);
        // Each reader sees only their own choice.
        $this->asToken($this->samToken)->getJson("/api/v1/requests/{$id}")->assertJsonPath('request.requested_categories', [$samCat, $samFav]);
        $this->asToken($this->samToken)->getJson('/api/v1/requests')->assertJsonPath('requests.0.requested_categories', [$samCat, $samFav]);
        $this->asToken($this->ownerToken)->getJson("/api/v1/requests/{$id}")->assertJsonPath('request.requested_categories', []);

        // Search results carry the caller's chosen categories (greyed out in Browse).
        [, $result] = $this->search($this->samToken);
        $this->assertSame([$samCat, $samFav], $result['requested_categories']);
        $this->assertSame([], $result['in_categories']);
        [, $kimResult] = $this->search($this->kimToken);
        $this->assertSame([$kimCat], $kimResult['requested_categories']);

        $novelId = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")->assertOk()->json('novel_id');
        // Approved is not yet available: nothing added.
        $this->assertSame(0, DB::table('reading_list_novel')->count());

        $this->chapters(Novel::find($novelId), 1);
        $this->artisan('requests:availability')->assertSuccessful();
        foreach ([$samCat, $samFav, $kimCat] as $list) {
            $this->assertTrue(DB::table('reading_list_novel')->where('reading_list_id', $list)->where('novel_id', $novelId)->exists(), "in list {$list}");
        }
        $this->assertSame(3, DB::table('reading_list_novel')->count());

        // Now in the library: results show where it is.
        Cache::flush();
        [, $result] = $this->search($this->samToken);
        $this->assertTrue($result['in_library']);
        $this->assertSame([$samFav, $samCat], $result['in_categories'], 'in the reader order');
    }

    public function test_mark_available_now_applies_categories_too(): void
    {
        $cat = $this->category($this->samToken, 'Books');
        $id = $this->requestFromSearch($this->samToken, ['categories' => [$cat]])->assertCreated()->json('request.id');
        $book = $this->novel('Solo Leveling');
        $requests = app(Requests::class);
        $request = NovelRequest::find($id);
        $requests->approve($request, $this->owner, ['novel_id' => $book->id]);
        $requests->markAvailableNow($request);

        $this->assertTrue(DB::table('reading_list_novel')->where('reading_list_id', $cat)->where('novel_id', $book->id)->exists());
        // Once only: a second call changes nothing (and the category may have been emptied since).
        DB::table('reading_list_novel')->delete();
        $requests->markAvailableNow($request->fresh());
        $this->assertSame(0, DB::table('reading_list_novel')->count());
    }

    public function test_a_category_that_is_not_the_callers_refuses_the_request_before_it_is_made(): void
    {
        $theirs = $this->category($this->kimToken, 'Kim’s');
        $this->requestFromSearch($this->samToken, ['categories' => [$theirs]])->assertNotFound();
        $this->assertSame(0, NovelRequest::count());
        $this->requestFromSearch($this->samToken, ['categories' => 'x'])->assertStatus(422);
    }

    public function test_in_library_and_already_requested_answers_use_the_categories(): void
    {
        $cat = $this->category($this->samToken, 'Manhwa');
        $other = $this->category($this->samToken, 'Later');
        $id = $this->requestFromSearch($this->samToken)->assertCreated()->assertJsonPath('request.requested_categories', [])->json('request.id');

        // Asking again: 409 already_requested, the choice is remembered on the request.
        $this->asToken($this->samToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga', 'categories' => [$cat]])
            ->assertStatus(409)->assertJsonPath('code', 'already_requested')->assertJsonPath('requested_categories', [$cat]);
        $this->asToken($this->samToken)->getJson("/api/v1/requests/{$id}")->assertJsonPath('request.requested_categories', [$cat]);

        // PUT replaces it; [] clears it; a follower-less stranger gets 404.
        $this->asToken($this->samToken)->putJson("/api/v1/requests/{$id}/categories", ['categories' => [$other, $cat]])->assertOk()
            ->assertJsonPath('request.requested_categories', [$other, $cat]);
        $this->asToken($this->kimToken)->putJson("/api/v1/requests/{$id}/categories", ['categories' => []])->assertNotFound();
        $kimCat = $this->category($this->kimToken, 'Kim’s');
        $this->asToken($this->samToken)->putJson("/api/v1/requests/{$id}/categories", ['categories' => [$kimCat]])->assertNotFound();
        $this->asToken($this->samToken)->putJson("/api/v1/requests/{$id}/categories", [])->assertStatus(422);

        // Available: the series is in the library, a request for it puts it in the categories at once.
        $novelId = $this->asToken($this->ownerToken)->postJson("/api/v1/requests/{$id}/approve")->assertOk()->json('novel_id');
        $this->chapters(Novel::find($novelId), 1);
        $this->artisan('requests:availability')->assertSuccessful();
        $this->assertSame(2, DB::table('reading_list_novel')->where('novel_id', $novelId)->count());

        $kimFav = $this->asToken($this->kimToken)->getJson('/api/v1/lists')->json('lists.1.id');
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga', 'categories' => [$kimFav]])
            ->assertStatus(409)->assertJsonPath('code', 'in_library')->assertJsonPath('novel_id', $novelId)->assertJsonPath('added_to_categories', [$kimFav]);
        $this->assertTrue(DB::table('reading_list_novel')->where('reading_list_id', $kimFav)->where('novel_id', $novelId)->exists());

        // PUT on an available request the caller sees: straight into the category.
        DB::table('reading_list_novel')->where('reading_list_id', $other)->delete();
        $this->asToken($this->samToken)->putJson("/api/v1/requests/{$id}/categories", ['categories' => [$other]])->assertOk();
        $this->assertTrue(DB::table('reading_list_novel')->where('reading_list_id', $other)->where('novel_id', $novelId)->exists());

        // Without categories the 409s keep their old shape.
        $body = $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga'])->assertStatus(409)->json();
        $this->assertArrayNotHasKey('added_to_categories', $body);
    }

    public function test_remembered_categories_go_with_a_follower_leaving_a_request_or_a_category(): void
    {
        $samCat = $this->category($this->samToken, 'Manhwa');
        $kimCat = $this->category($this->kimToken, 'Action');
        $id = $this->requestFromSearch($this->samToken, ['categories' => [$samCat]])->assertCreated()->json('request.id');
        $this->asToken($this->kimToken)->postJson('/api/v1/requests', ['title' => 'Solo Leveling', 'medium' => 'manga', 'categories' => [$kimCat]])->assertOk();
        $this->assertSame(2, DB::table(ReadingList::REQUEST_CATEGORIES)->count());

        $this->asToken($this->kimToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();
        $this->assertSame([$samCat], DB::table(ReadingList::REQUEST_CATEGORIES)->pluck('reading_list_id')->map(fn($v) => (int) $v)->all());

        $this->asToken($this->samToken)->deleteJson("/api/v1/lists/{$samCat}")->assertNoContent();
        $this->assertSame(0, DB::table(ReadingList::REQUEST_CATEGORIES)->count());

        // A cancelled request takes the rest.
        $cat = $this->category($this->samToken, 'Again');
        $this->asToken($this->samToken)->putJson("/api/v1/requests/{$id}/categories", ['categories' => [$cat]])->assertOk();
        $this->asToken($this->samToken)->deleteJson("/api/v1/requests/{$id}")->assertNoContent();
        $this->assertSame(0, DB::table(ReadingList::REQUEST_CATEGORIES)->count());
    }

    public function test_the_new_routes_are_reader_routes(): void
    {
        $routes = ['api.v1.lists.order', 'api.v1.lists.memberships', 'api.v1.novels.bulk_read', 'api.v1.requests.categories'];
        foreach ($routes as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame(RouteAccess::READER, RouteAccess::classify($route), $name);
        }
    }

    // ---- Web Home: a summary of the categories -------------------------------------

    public function test_web_home_keeps_its_old_layout_until_the_reader_has_a_category(): void
    {
        $html = $this->asSignedIn($this->sam)->get(route("home"))->assertOk()->getContent();
        $this->assertStringContainsString('Create your first category', $html);
        $this->assertStringNotContainsString('is-categories-first', $html);
        $this->assertStringNotContainsString('id="categories-section"', $html);
    }

    public function test_web_home_shows_visible_non_empty_categories_in_the_readers_order(): void
    {
        [$a, $b] = [$this->novel('Alpha Story'), $this->novel('Beta Story')];
        $manhwa = $this->category($this->samToken, 'Manhwa');
        $later = $this->category($this->samToken, 'Later');
        $empty = $this->category($this->samToken, 'Empty one');
        $hidden = $this->category($this->samToken, 'Secret');
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$a->id], 'add' => [$manhwa, $hidden], 'remove' => []])->assertOk();
        $this->asToken($this->samToken)->putJson('/api/v1/lists/memberships', ['novel_ids' => [$b->id], 'add' => [$later], 'remove' => []])->assertOk();
        $this->asToken($this->samToken)->patchJson("/api/v1/lists/{$hidden}", ['hidden_on_home' => true])->assertOk();
        $this->asToken($this->samToken)->patchJson('/api/v1/lists/order', ['ids' => [$later, $manhwa]])->assertOk();

        $html = $this->asSignedIn($this->sam)->get(route("home"))->assertOk()->getContent();
        $this->assertStringContainsString('is-categories-first', $html);
        $this->assertStringNotContainsString('Create your first category', $html);
        $this->assertStringContainsString('data-category="' . $later . '"', $html);
        $this->assertStringContainsString('data-category="' . $manhwa . '"', $html);
        $this->assertLessThan(strpos($html, 'data-category="' . $manhwa . '"'), strpos($html, 'data-category="' . $later . '"'), 'in the reader order');
        $this->assertStringNotContainsString('data-category="' . $empty . '"', $html, 'empty: skipped');
        $this->assertStringNotContainsString('data-category="' . $hidden . '"', $html, 'hidden on Home');
        // Categories come before New chapters; the old shelves are optional.
        $this->assertLessThan(strpos($html, 'id="new-section"'), strpos($html, 'id="categories-section"'));
        $this->assertStringContainsString('data-home-optional="want_to_read"', $html);
        $this->assertStringContainsString('Customise Home', $html);
        // Another reader's Home has none of it.
        $this->assertStringNotContainsString('id="categories-section"', $this->asSignedIn($this->kim)->get(route("home"))->getContent());
    }
}
