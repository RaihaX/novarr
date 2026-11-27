<?php

namespace Tests\Feature;

use App\Novel;
use App\ReadingList;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Reading lists on the web: the novel page's Lists menu and the Library's
 * list filter (?list=), its bar (rename / delete) and empty states.
 */
class ReadingListsWebTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name, string $medium = Novel::MEDIUM_NOVEL): Novel
    {
        return Novel::create(['name' => $name, 'status' => 0, 'group_id' => 1, 'medium' => $medium]);
    }

    private function listWith(string $name, array $novels, ?User $user = null): ReadingList
    {
        $list = ReadingList::create(['user_id' => ($user ?? $this->owner())->id, 'name' => $name, 'name_key' => ReadingList::key($name)]);
        foreach ($novels as $novel) {
            DB::table('reading_list_novel')->insert(['reading_list_id' => $list->id, 'novel_id' => $novel->id, 'added_at' => now()]);
        }

        return $list;
    }

    /** Ids of the series the Library lists. @return list<int> */
    private function shownIds($response): array
    {
        return collect($response->viewData('novels')->items())->map(fn($n) => (int) $n->id)->sort()->values()->all();
    }

    public function test_the_novel_page_offers_starters_when_there_are_no_lists(): void
    {
        $novel = $this->novel('Lonely');
        $html = $this->get("/novels/{$novel->id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-lists-menu data-novel="' . $novel->id . '"', $html);
        $this->assertStringContainsString('id="listsBtn"', $html);
        foreach (ReadingList::STARTERS as $starter) {
            $this->assertStringContainsString('data-starter="' . $starter . '"', $html);
        }
        $this->assertMatchesRegularExpression('/<ul class="lists-items" data-lists-items aria-labelledby="listsMenuTitle"\s+hidden\s*>/', $html);
        $this->assertStringContainsString('New list…', $html);
        $this->assertStringContainsString('<label for="listsNewName" class="visually-hidden">New list name</label>', $html);
    }

    public function test_the_novel_page_ticks_the_lists_the_series_is_in(): void
    {
        $novel = $this->novel('Ticked');
        $fav = $this->listWith('Classics', [$novel]);
        $later = $this->listWith('To read', []);

        $html = $this->get("/novels/{$novel->id}")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-list-id="' . $fav->id . '" data-list-name="Classics"\s+checked>/', $html);
        $this->assertMatchesRegularExpression('/data-list-id="' . $later->id . '" data-list-name="To read"\s*>/', $html);
        $this->assertStringContainsString('aria-label="Lists, in 1 list"', $html);
        // Starters only before the first list.
        $this->assertMatchesRegularExpression('/data-lists-starters\s+hidden/', $html);
    }

    public function test_a_reader_sees_their_own_lists_and_no_admin_controls(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();
        $novel = $this->novel('Shared Series');
        $this->listWith('Owner secret list', [$novel], $owner);
        $this->listWith('Sam picks', [], $reader);

        $html = $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD))->get("/novels/{$novel->id}")->assertOk()->getContent();

        $this->assertStringContainsString('id="listsBtn"', $html);
        $this->assertStringContainsString('Sam picks', $html);
        $this->assertStringNotContainsString('Owner secret list', $html);
        $this->assertStringNotContainsString('id="deleteNovel"', $html);

        $library = $this->get('/novels')->assertOk()->getContent();
        $this->assertStringContainsString('List: Sam picks (0)', $library);
        $this->assertStringNotContainsString('Owner secret list', $library);
    }

    public function test_the_library_filter_shows_only_the_lists_series(): void
    {
        $a = $this->novel('Alpha');
        $b = $this->novel('Bravo');
        $c = $this->novel('Charlie');
        $manga = $this->novel('Manga One', Novel::MEDIUM_MANGA);
        $list = $this->listWith('Classics', [$a, $c, $manga]);

        // No list chosen: everything, and the filter is offered with this library's count.
        $all = $this->get('/novels?medium=novel&view=grid');
        $this->assertSame([$a->id, $b->id, $c->id], $this->shownIds($all));
        $this->assertStringContainsString('<select name="list"', $all->getContent());
        $this->assertStringContainsString('>List: Classics (2)</option>', $all->getContent());

        $response = $this->get("/novels?medium=novel&view=grid&list={$list->id}")->assertOk();
        $this->assertSame([$a->id, $c->id], $this->shownIds($response));
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $list->id . '"[^>]*selected>List: Classics/', $html);
        $this->assertStringContainsString('data-list-bar data-list-id="' . $list->id . '"', $html);
        $this->assertStringContainsString('data-list-title>Classics</h2>', $html);
        $this->assertStringContainsString('data-list-delete', $html);
        $this->assertStringContainsString('data-list-rename', $html);
        // A flat list (no Reading / Not started shelves) and the "showing" count.
        $this->assertStringNotContainsString('id="shelf-', $html);
        $this->assertMatchesRegularExpression('/showing <span class="mono-num">2<\/span>/', $html);

        // Chips and links keep the list.
        $this->assertStringContainsString('list=' . $list->id, $html);

        // It combines with search; the table view filters too.
        $this->assertSame([$c->id], $this->shownIds($this->get("/novels?medium=novel&view=grid&list={$list->id}&search=Char")));
        $this->assertSame([$a->id, $c->id], $this->shownIds($this->get("/novels?medium=novel&view=table&list={$list->id}")));

        // The Manga library: that list's manga only.
        $this->assertSame([$manga->id], $this->shownIds($this->get("/novels?medium=manga&list={$list->id}")));
    }

    public function test_a_soft_deleted_series_is_not_shown_or_counted(): void
    {
        $a = $this->novel('Alpha');
        $gone = $this->novel('Gone');
        $list = $this->listWith('Classics', [$a, $gone]);
        $gone->delete();

        $response = $this->get("/novels?medium=novel&list={$list->id}");
        $this->assertSame([$a->id], $this->shownIds($response));
        $this->assertStringContainsString('List: Classics (1)', $response->getContent());
    }

    public function test_someone_elses_or_an_unknown_list_is_ignored(): void
    {
        $this->enableSignIn();
        $reader = $this->makeReader();
        $a = $this->novel('Alpha');
        $b = $this->novel('Bravo');
        $ownerList = $this->listWith('Owner list', [$a]);

        $this->withBrowser($this->webSignIn('sam', self::READER_PASSWORD));
        foreach ([$ownerList->id, 999, 'abc'] as $id) {
            $response = $this->get("/novels?medium=novel&list={$id}")->assertOk();
            $this->assertSame([$a->id, $b->id], $this->shownIds($response));
            $this->assertStringNotContainsString('data-list-bar', $response->getContent());
            $this->assertStringNotContainsString('Owner list', $response->getContent());
        }
    }

    public function test_an_empty_list_has_its_own_empty_state(): void
    {
        $this->novel('Alpha');
        $empty = $this->listWith('To read', []);

        $html = $this->get("/novels?medium=novel&list={$empty->id}")->assertOk()->getContent();
        $this->assertStringContainsString('No novels in “To read” yet', $html);
        $this->assertStringContainsString('use its <strong>Lists</strong> button', $html);

        // Only manga in it: say so and link to the Manga library with the list.
        $manga = $this->novel('Manga One', Novel::MEDIUM_MANGA);
        $mangaOnly = $this->listWith('Manga picks', [$manga]);
        $html = $this->get("/novels?medium=novel&list={$mangaOnly->id}")->assertOk()->getContent();
        $this->assertStringContainsString('No novels in “Manga picks” yet', $html);
        $this->assertStringContainsString('This list has 1 manga in the Manga library.', $html);
        $this->assertStringContainsString('list=' . $mangaOnly->id, $html);
    }

    public function test_no_lists_no_filter(): void
    {
        $this->novel('Alpha');
        $html = $this->get('/novels')->assertOk()->getContent();
        $this->assertStringNotContainsString('<select name="list"', $html);
        $this->assertStringNotContainsString('data-list-bar', $html);
    }
}
