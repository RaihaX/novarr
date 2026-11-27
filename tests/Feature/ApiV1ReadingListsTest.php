<?php

namespace Tests\Feature;

use App\Novel;
use App\ReadingList;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * /api/v1/lists…: a reader's reading lists (App\Services\ReadingLists).
 * Sign-in off (every call is the owner) unless a test turns it on.
 */
class ApiV1ReadingListsTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private function novel(string $name = 'Listed Novel', string $medium = Novel::MEDIUM_NOVEL): Novel
    {
        return Novel::create(['name' => $name, 'status' => 0, 'group_id' => 1, 'medium' => $medium]);
    }

    private function makeList(string $name, ?User $user = null): ReadingList
    {
        return ReadingList::create(['user_id' => ($user ?? $this->owner())->id, 'name' => $name, 'name_key' => ReadingList::key($name)]);
    }

    public function test_create_list_rename_and_delete(): void
    {
        $response = $this->postJson('/api/v1/lists', ['name' => '  Classics  '])->assertCreated();
        $id = $response->json('list.id');
        $this->assertSame(['list' => ['id' => $id, 'name' => 'Classics', 'count' => 0, 'novel_ids' => [], 'kind' => null]], $response->json());

        $this->postJson('/api/v1/lists', ['name' => 'To read'])->assertCreated();

        // "Want to read" and "Favourites" are made on first use (this listing) and always first.
        $this->getJson('/api/v1/lists')->assertOk()->assertExactJson(['lists' => [
            ['id' => $id + 2, 'name' => 'Want to read', 'count' => 0, 'novel_ids' => [], 'kind' => 'want_to_read'],
            ['id' => $id + 3, 'name' => 'Favourites', 'count' => 0, 'novel_ids' => [], 'kind' => 'favourites'],
            ['id' => $id, 'name' => 'Classics', 'count' => 0, 'novel_ids' => [], 'kind' => null],
            ['id' => $id + 1, 'name' => 'To read', 'count' => 0, 'novel_ids' => [], 'kind' => null],
        ]]);

        $this->patchJson("/api/v1/lists/{$id}", ['name' => 'Zzz best'])->assertOk()
            ->assertJsonPath('list.name', 'Zzz best')->assertJsonPath('list.id', $id);
        // Ordered by name, case-insensitively.
        $this->postJson('/api/v1/lists', ['name' => 'abc'])->assertCreated();
        $this->assertSame(['Want to read', 'Favourites', 'abc', 'To read', 'Zzz best'], array_column($this->getJson('/api/v1/lists')->json('lists'), 'name'));

        $this->deleteJson("/api/v1/lists/{$id}")->assertNoContent();
        $this->assertNull(ReadingList::find($id));
        $this->deleteJson("/api/v1/lists/{$id}")->assertNotFound();
    }

    public function test_names_are_validated_with_readable_messages(): void
    {
        $this->postJson('/api/v1/lists', ['name' => 'Classics'])->assertCreated();

        $cases = [
            [['name' => ''], 'Give the list a name.'],
            [['name' => "  \t "], 'Give the list a name.'],
            [[], 'Give the list a name.'],
            [['name' => ['x']], 'The list name must be text.'],
            [['name' => str_repeat('a', 41)], 'A list name can be up to 40 characters.'],
            [['name' => ' CLASSICS '], 'You already have a list called “CLASSICS”.'],
            [['name' => 'classics'], 'You already have a list called “classics”.'],
            [['name' => ' favourites '], '“Favourites” is already one of your lists.'],
        ];
        foreach ($cases as [$body, $message]) {
            $this->postJson('/api/v1/lists', $body)
                ->assertStatus(422)
                ->assertJsonPath('message', $message)
                ->assertJsonPath('errors.name.0', $message);
        }

        // 40 multi-byte characters fit; inner spaces collapse.
        $this->postJson('/api/v1/lists', ['name' => str_repeat('é', 40)])->assertCreated();
        $this->postJson('/api/v1/lists', ['name' => "Summer \t  reads"])->assertCreated()->assertJsonPath('list.name', 'Summer reads');
        $this->postJson('/api/v1/lists', ['name' => 'summer reads'])->assertStatus(422);
        $this->assertSame(3, ReadingList::count());
    }

    public function test_rename_keeps_its_own_name_and_refuses_another_lists(): void
    {
        $fav = $this->makeList('Classics');
        $this->makeList('To read');

        $this->patchJson("/api/v1/lists/{$fav->id}", ['name' => 'CLASSICS'])->assertOk()->assertJsonPath('list.name', 'CLASSICS');
        $this->patchJson("/api/v1/lists/{$fav->id}", ['name' => 'to read'])->assertStatus(422)
            ->assertJsonPath('message', 'You already have a list called “to read”.');
        $this->patchJson("/api/v1/lists/{$fav->id}", ['name' => ''])->assertStatus(422);
        $this->assertSame('CLASSICS', $fav->fresh()->name);
    }

    public function test_thirty_lists_per_reader(): void
    {
        foreach (range(1, ReadingList::MAX_LISTS) as $n) {
            $this->makeList("List {$n}");
        }
        $this->postJson('/api/v1/lists', ['name' => 'One more'])->assertStatus(422)
            ->assertJsonPath('message', 'You can have up to 30 lists. Delete one to make room.');

        // Another reader's count is their own.
        $reader = $this->makeReader();
        $this->actAsReader($reader);
        $this->postJson('/api/v1/lists', ['name' => 'One more'])->assertCreated();
    }

    public function test_adding_and_removing_series_is_idempotent(): void
    {
        $list = $this->makeList('Classics');
        $a = $this->novel('A');
        $b = $this->novel('B');

        $this->putJson("/api/v1/lists/{$list->id}/novels/{$b->id}")->assertOk()
            ->assertExactJson(['list' => ['id' => $list->id, 'name' => 'Classics', 'count' => 1, 'novel_ids' => [$b->id], 'kind' => null]]);
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$b->id}")->assertOk()->assertJsonPath('list.count', 1);
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$a->id}")->assertOk()
            ->assertJsonPath('list.novel_ids', [$a->id, $b->id]);
        $this->assertSame(2, DB::table('reading_list_novel')->count());
        $this->assertNotNull(DB::table('reading_list_novel')->value('added_at'));

        $this->deleteJson("/api/v1/lists/{$list->id}/novels/{$b->id}")->assertOk()
            ->assertJsonPath('list.novel_ids', [$a->id])->assertJsonPath('list.count', 1);
        $this->deleteJson("/api/v1/lists/{$list->id}/novels/{$b->id}")->assertOk()->assertJsonPath('list.count', 1);

        // A series can be in several lists.
        $other = $this->makeList('To read');
        $this->putJson("/api/v1/lists/{$other->id}/novels/{$a->id}")->assertOk();
        $this->assertSame([[], [], [$a->id], [$a->id]], array_column($this->getJson('/api/v1/lists')->json('lists'), 'novel_ids'));

        // Deleting a list removes its rows only.
        $this->deleteJson("/api/v1/lists/{$list->id}")->assertNoContent();
        $this->assertSame([$other->id], DB::table('reading_list_novel')->pluck('reading_list_id')->map(fn($v) => (int) $v)->all());
    }

    public function test_unknown_series_or_list_is_404(): void
    {
        $list = $this->makeList('Classics');

        $this->putJson("/api/v1/lists/{$list->id}/novels/999")->assertNotFound()->assertJsonPath('message', 'Series not found.');
        $this->deleteJson("/api/v1/lists/{$list->id}/novels/999")->assertNotFound();
        $this->putJson('/api/v1/lists/999/novels/' . $this->novel()->id)->assertNotFound()->assertJsonPath('message', 'List not found.');
        $this->patchJson('/api/v1/lists/999', ['name' => 'x'])->assertNotFound();
        $this->deleteJson('/api/v1/lists/999')->assertNotFound();
    }

    public function test_a_list_holds_two_thousand_series(): void
    {
        $list = $this->makeList('Big');
        $novel = $this->novel('Real');
        // Fill with live series straight in the database.
        $rows = [];
        $novelRows = [];
        foreach (range(1, ReadingList::MAX_NOVELS) as $n) {
            $novelRows[] = ['id' => 10_000 + $n, 'name' => "N{$n}", 'status' => 0, 'group_id' => 1, 'medium' => 'novel'];
            $rows[] = ['reading_list_id' => $list->id, 'novel_id' => 10_000 + $n, 'added_at' => now()];
        }
        foreach (array_chunk($novelRows, 400) as $chunk) {
            DB::table('novels')->insert($chunk);
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('reading_list_novel')->insert($chunk);
        }

        $this->putJson("/api/v1/lists/{$list->id}/novels/{$novel->id}")->assertStatus(422)
            ->assertJsonPath('message', 'A list can hold up to 2,000 series.');
        // Already in it: still idempotent, no error.
        $this->putJson("/api/v1/lists/{$list->id}/novels/10001")->assertOk()->assertJsonPath('list.count', 2000);

        // A soft-deleted member does not count towards the limit.
        Novel::find(10_001)->delete();
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$novel->id}")->assertOk()->assertJsonPath('list.count', 2000);
    }

    public function test_a_soft_deleted_series_is_hidden_and_a_hard_deleted_one_leaves(): void
    {
        $list = $this->makeList('Classics');
        $kept = $this->novel('Kept');
        $gone = $this->novel('Gone');
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$kept->id}")->assertOk();
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$gone->id}")->assertOk();

        $gone->delete();
        $this->getJson('/api/v1/lists')->assertJsonPath('lists.2.novel_ids', [$kept->id])->assertJsonPath('lists.2.count', 1);
        // It cannot be added or removed while deleted…
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$gone->id}")->assertNotFound();
        // …and comes back with a restore.
        $gone->restore();
        $this->getJson('/api/v1/lists')->assertJsonPath('lists.2.count', 2);

        $gone->forceDelete();
        $this->assertSame(0, DB::table('reading_list_novel')->where('novel_id', $gone->id)->count());
        $this->assertSame(1, DB::table('reading_list_novel')->where('novel_id', $kept->id)->count());
    }

    public function test_readers_only_see_and_change_their_own_lists(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $ownerToken = $this->tokenFor($owner);
        $readerToken = $this->tokenFor($reader);
        $novel = $this->novel();

        $mine = $this->asToken($readerToken)->postJson('/api/v1/lists', ['name' => 'Classics'])->assertCreated()->json('list.id');
        // Same name for another reader is fine.
        $theirs = $this->asToken($ownerToken)->postJson('/api/v1/lists', ['name' => 'Classics'])->assertCreated()->json('list.id');
        $this->asToken($ownerToken)->putJson("/api/v1/lists/{$theirs}/novels/{$novel->id}")->assertOk();

        $builtIn = $this->asToken($readerToken)->getJson('/api/v1/lists')->assertOk()->json('lists');
        [$wanted, $favourites] = [$builtIn[0]['id'], $builtIn[1]['id']];
        $this->asToken($readerToken)->getJson('/api/v1/lists')->assertOk()
            ->assertExactJson(['lists' => [
                ['id' => $wanted, 'name' => 'Want to read', 'count' => 0, 'novel_ids' => [], 'kind' => 'want_to_read'],
                ['id' => $favourites, 'name' => 'Favourites', 'count' => 0, 'novel_ids' => [], 'kind' => 'favourites'],
                ['id' => $mine, 'name' => 'Classics', 'count' => 0, 'novel_ids' => [], 'kind' => null],
            ]]);

        // Someone else's list: 404 for every action, nothing changes.
        $this->asToken($readerToken)->patchJson("/api/v1/lists/{$theirs}", ['name' => 'Mine now'])->assertNotFound();
        $this->asToken($readerToken)->deleteJson("/api/v1/lists/{$theirs}")->assertNotFound();
        $this->asToken($readerToken)->putJson("/api/v1/lists/{$theirs}/novels/{$novel->id}")->assertNotFound();
        $this->asToken($readerToken)->deleteJson("/api/v1/lists/{$theirs}/novels/{$novel->id}")->assertNotFound();
        $this->assertSame('Classics', ReadingList::find($theirs)->name);
        $this->asToken($ownerToken)->getJson('/api/v1/lists')->assertJsonPath('lists.2.novel_ids', [$novel->id]);

        // Web session works too (the web UI's own calls).
        $cookies = $this->webSignIn('sam', self::READER_PASSWORD);
        $this->withBrowser($cookies)->getJson('/api/v1/lists')->assertOk()->assertJsonPath('lists.2.id', $mine);
    }

    public function test_unauthenticated_calls_are_refused_with_sign_in_on(): void
    {
        $this->enableSignIn();
        $list = $this->makeList('Classics');
        $novel = $this->novel();

        $this->asNobody();
        $this->getJson('/api/v1/lists')->assertUnauthorized();
        $this->postJson('/api/v1/lists', ['name' => 'x'])->assertUnauthorized();
        $this->patchJson("/api/v1/lists/{$list->id}", ['name' => 'x'])->assertUnauthorized();
        $this->deleteJson("/api/v1/lists/{$list->id}")->assertUnauthorized();
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$novel->id}")->assertUnauthorized();
        $this->deleteJson("/api/v1/lists/{$list->id}/novels/{$novel->id}")->assertUnauthorized();
        $this->asToken('not-a-token')->getJson('/api/v1/lists')->assertUnauthorized();
        $this->assertSame(1, ReadingList::count());
    }

    public function test_with_sign_in_off_the_lists_are_the_owners(): void
    {
        $id = $this->postJson('/api/v1/lists', ['name' => 'Classics'])->assertCreated()->json('list.id');
        $this->assertSame($this->owner()->id, ReadingList::find($id)->user_id);
    }

    public function test_lists_work_on_the_public_address_for_a_signed_in_reader(): void
    {
        config(['novarr.public_hosts' => ['read.example.test']]);
        $this->enableSignIn();
        $reader = $this->makeReader();
        $token = $this->tokenFor($reader);

        $this->asToken($token)->postJson('http://read.example.test/api/v1/lists', ['name' => 'Classics'])->assertCreated();
        // Theirs and the built-in "Want to read" and "Favourites".
        $this->asToken($token)->getJson('http://read.example.test/api/v1/lists')->assertOk()->assertJsonCount(3, 'lists');
    }

    public function test_deleting_an_account_removes_its_lists(): void
    {
        $reader = $this->makeReader();
        $novel = $this->novel();
        $theirs = $this->makeList('Classics', $reader);
        $mine = $this->makeList('Classics');
        DB::table('reading_list_novel')->insert([
            ['reading_list_id' => $theirs->id, 'novel_id' => $novel->id, 'added_at' => now()],
            ['reading_list_id' => $mine->id, 'novel_id' => $novel->id, 'added_at' => now()],
        ]);

        $reader->delete();

        $this->assertSame([$mine->id], ReadingList::pluck('id')->map(fn($v) => (int) $v)->all());
        $this->assertSame([$mine->id], DB::table('reading_list_novel')->pluck('reading_list_id')->map(fn($v) => (int) $v)->all());
    }

    public function test_meta_offers_lists(): void
    {
        $this->getJson('/api/v1/meta')->assertOk()->assertJsonPath('lists', true);
    }

    public function test_the_novels_payload_is_unchanged(): void
    {
        $list = $this->makeList('Classics');
        $novel = $this->novel();
        $before = $this->getJson('/api/v1/novels')->json();
        $this->putJson("/api/v1/lists/{$list->id}/novels/{$novel->id}")->assertOk();
        $this->assertSame($before, $this->getJson('/api/v1/novels')->json());
    }
}
