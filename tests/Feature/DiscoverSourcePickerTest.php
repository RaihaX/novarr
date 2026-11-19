<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\DiscoverController as ApiDiscoverController;
use App\Services\Discover;
use App\Services\DiscoverSearch;
use App\Setting;
use App\Sources\SuwayomiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeFetcher;

/**
 * "Sources when adding": the discover_hidden_sources setting leaves sources
 * out of Discover (web page, browse, /api/v1/discover*), and the Settings
 * page section that edits it. Suwayomi answers from the fixtures, never the
 * network.
 */
class DiscoverSourcePickerTest extends GoldenTestCase
{
    use RefreshDatabase;

    private const BASE = 'http://192.168.1.37:4567';
    private const GQL = self::BASE . '/api/graphql';
    private const MANGAPILL = 'suwayomi:8448310129093543312';
    private const FLAMESCANS = 'suwayomi:1001157238479601077';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'novarr.suwayomi.url' => self::BASE,
            'novarr.suwayomi.languages' => ['en'],
            'novarr.suwayomi.exclude_sources' => ['MangaDex', 'Local source'],
            'novarr.suwayomi.timeout' => 90,
        ]);
    }

    /** Suwayomi lists the fixture's sources (or the given body / fail()). */
    private function suwayomi(string|array $sources = 'suwayomi/sources.json'): FakeFetcher
    {
        return $this->fake([self::GQL => function (array $body) use ($sources) {
            return $body['query'] === SuwayomiSource::QUERY_SOURCES ? $sources : null;
        }]);
    }

    /** A sources answer with $count English Suwayomi sources, ids 100… */
    private static function manySources(int $count): string
    {
        $nodes = [];
        for ($i = 0; $i < $count; $i++) {
            $nodes[] = ['id' => (string) (100 + $i), 'name' => sprintf('Site %02d', $i), 'lang' => 'en', 'isNsfw' => false, 'supportsLatest' => true];
        }

        return json_encode(['data' => ['sources' => ['nodes' => $nodes]]]);
    }

    private function hide(array $keys): void
    {
        Setting::put(Discover::HIDDEN_SETTING, json_encode($keys));
    }

    private function storedHidden(): mixed
    {
        return json_decode((string) Setting::get(Discover::HIDDEN_SETTING), true);
    }

    /** The option values of the Add page's source dropdown. */
    private static function dropdown(string $html): array
    {
        if (!preg_match('#<select id="discoverSource".*?</select>#s', $html, $m)) {
            return [];
        }
        preg_match_all('#<option value="([^"]+)"#', $m[0], $o);

        return $o[1];
    }

    /** Settings page checkboxes of the source picker: key => ticked. */
    private static function boxes(string $html): array
    {
        preg_match_all('#<input type="checkbox" class="form-check-input" name="discover_shown\[\]" id="[^"]+" value="([^"]+)"\s*(checked)?#', $html, $m, PREG_SET_ORDER);
        $boxes = [];
        foreach ($m as $match) {
            $boxes[html_entity_decode($match[1])] = ($match[2] ?? '') === 'checked';
        }

        return $boxes;
    }

    /** A valid Settings save with the given source picker fields. */
    private function save(array $offered, array $shown)
    {
        return $this->post(route('settings.update'), ['discover_offered' => $offered, 'discover_shown' => $shown]);
    }

    // ---- Discover service ------------------------------------------------------

    public function testWithoutTheSettingEverySourceIsShown(): void
    {
        $this->suwayomi();
        $discover = app(Discover::class);

        $this->assertSame([], $discover->hiddenKeys());
        $this->assertSame(array_keys($discover->allSources()), array_keys($discover->sources()));
        $this->assertSame(['mangadex', self::FLAMESCANS, self::MANGAPILL], array_keys($discover->sourcesFor('manga')));

        $html = $this->get(route('novels.discover', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertSame(['mangadex', self::FLAMESCANS, self::MANGAPILL], self::dropdown($html));
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfull', 'novelfire', 'royalroad'], self::dropdown($this->get(route('novels.discover', ['medium' => 'novel']))->getContent()));
        $this->assertSame(['mangadex', self::FLAMESCANS, self::MANGAPILL], array_column($this->getJson('/api/v1/discover?medium=manga')->json('sources'), 'id'));
    }

    public function testHiddenKeysLeaveTheWebPageSourceOptionsAndTheApi(): void
    {
        $this->suwayomi();
        $this->hide([self::MANGAPILL, 'novelfull']);
        $discover = app(Discover::class);

        $this->assertSame(['mangadex', self::FLAMESCANS], array_keys($discover->sourceOptions('manga')));
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfire', 'royalroad'], array_keys($discover->sourceOptions('novel')));
        $this->assertArrayHasKey(self::MANGAPILL, $discover->allSources(), 'the settings list still has it');

        $this->assertSame(['mangadex', self::FLAMESCANS], self::dropdown($this->get(route('novels.discover', ['medium' => 'manga']))->getContent()));
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfire', 'royalroad'], self::dropdown($this->get(route('novels.discover', ['medium' => 'novel']))->getContent()));

        $this->assertSame(['mangadex', self::FLAMESCANS], array_column($this->getJson('/api/v1/discover?medium=manga')->json('sources'), 'id'));
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfire', 'royalroad'], array_column($this->getJson('/api/v1/discover')->json('sources'), 'id'));
        $all = $this->getJson('/api/v1/discover?medium=all')->assertOk()->json('sources');
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfire', 'royalroad', 'mangadex', self::FLAMESCANS], array_column($all, 'id'));
        // Response shape unchanged.
        $this->assertSame(['id', 'name', 'host', 'default', 'lists'], array_keys($all[0]));
        $this->assertTrue($all[0]['default']);
    }

    public function testHidingTheDefaultSourceMakesTheNextOneTheDefault(): void
    {
        $this->suwayomi();
        $this->hide(['novelarrow']);

        $sources = $this->getJson('/api/v1/discover')->assertOk()->json('sources');
        $this->assertSame('empirenovel', $sources[0]['id']);
        $this->assertTrue($sources[0]['default']);
    }

    public function testStaleAndJunkEntriesInTheSettingAreIgnored(): void
    {
        $this->suwayomi();
        $discover = app(Discover::class);

        $this->hide(['suwayomi:999', 'gone-source', 42, null, '', ['nested'], 'novelfull']);
        $this->assertSame(['suwayomi:999', 'gone-source', 'novelfull'], $discover->hiddenKeys());
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfire', 'royalroad', 'mangadex', self::FLAMESCANS, self::MANGAPILL], array_keys($discover->sources()));
    }

    public function testAnInvalidSettingValueBehavesAsEmpty(): void
    {
        $this->suwayomi();
        $discover = app(Discover::class);
        $everything = array_keys($discover->allSources());

        foreach (['not json', '{"novelfull": true}', '"novelfull"', '123', 'null', '[]'] as $value) {
            Setting::put(Discover::HIDDEN_SETTING, $value);
            // (A JSON object's values are not keys: nothing hidden either.)
            $this->assertSame([], $discover->hiddenKeys(), $value);
            $this->assertSame($everything, array_keys($discover->sources()), $value);
        }
        $this->get(route('novels.discover', ['medium' => 'novel']))->assertOk()->assertSee('<option value="novelfull">', false);
    }

    // ---- Browse with a hidden key -------------------------------------------

    public function testBrowsingAHiddenSourceIsRejectedLikeAnUnknownOne(): void
    {
        $this->suwayomi();
        $this->hide([self::MANGAPILL, 'novelfull']);

        foreach ([self::MANGAPILL, 'suwayomi:999'] as $key) {
            $this->getJson('/novels/discover/browse?' . http_build_query(['medium' => 'manga', 'type' => 'popular', 'source' => $key]))
                ->assertStatus(422)->assertJsonValidationErrors('source');
            $this->getJson('/api/v1/discover/browse?' . http_build_query(['medium' => 'manga', 'type' => 'popular', 'source' => $key]))
                ->assertStatus(422)->assertJsonValidationErrors('source');
            $this->postJson('/api/v1/discover/searches', ['medium' => 'manga', 'type' => 'search', 'q' => 'abc', 'source' => $key])
                ->assertStatus(422)->assertJsonValidationErrors('source');
        }
        foreach (['novelfull', 'nosuchsource'] as $key) {
            $this->getJson('/novels/discover/browse?' . http_build_query(['type' => 'search', 'q' => 'abc', 'source' => $key]))
                ->assertStatus(422)->assertJsonValidationErrors('source');
            $this->getJson('/api/v1/discover/browse?' . http_build_query(['type' => 'search', 'q' => 'abc', 'source' => $key]))
                ->assertStatus(422)->assertJsonValidationErrors('source');
        }
    }

    public function testARecordedSearchOfASourceHiddenSinceFailsLikeARemovedSource(): void
    {
        $this->suwayomi();
        Bus::fake();
        $searches = app(DiscoverSearch::class);

        $search = $searches->start(['medium' => 'manga', 'source' => self::MANGAPILL, 'type' => 'search', 'q' => 'hajime']);
        $this->hide([self::MANGAPILL]);
        $searches->run($search['id'], app(Discover::class));

        $state = $searches->state($search['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(ApiDiscoverController::UPSTREAM_FAILED, $state['message']);
    }

    // ---- Add page --------------------------------------------------------------

    public function testTheAddPageLinksToChooseSources(): void
    {
        $this->suwayomi();
        $link = 'href="' . e(route('settings.index') . '#discover-sources') . '" class="small" id="discoverChooseSources">Choose sources</a>';

        $this->assertStringContainsString($link, $this->get(route('novels.discover', ['medium' => 'manga']))->getContent());
        $this->assertStringContainsString($link, $this->get(route('novels.discover', ['medium' => 'novel']))->getContent());
    }

    public function testEveryMangaSourceHiddenSaysSoWithAChooseSourcesLink(): void
    {
        $this->suwayomi();
        $this->hide(['mangadex', self::FLAMESCANS, self::MANGAPILL]);

        $html = $this->get(route('novels.discover', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('All manga sources are hidden.', $html);
        $this->assertStringNotContainsString('No manga sources are set up.', $html);
        $this->assertStringNotContainsString('id="discoverSource"', $html);
        $this->assertStringContainsString('href="' . e(route('settings.index') . '#discover-sources') . '" id="discoverChooseSources">Choose sources</a>', $html);
        $this->assertStringContainsString('href="' . e(route('novels.create', ['medium' => 'manga'])) . '">Add manually</a>', $html);

        // Browse has nothing to list (as with no sources at all).
        $this->getJson('/novels/discover/browse?' . http_build_query(['medium' => 'manga', 'type' => 'popular']))
            ->assertOk()->assertJson(['items' => [], 'has_more' => false]);
        $this->assertSame([], $this->getJson('/api/v1/discover?medium=manga')->json('sources'));

        // The novel page is untouched.
        $this->get(route('novels.discover', ['medium' => 'novel']))->assertOk()->assertSee('id="discoverSource"', false);
    }

    public function testEveryNovelSourceHiddenSaysSo(): void
    {
        $this->suwayomi();
        $this->hide(['novelarrow', 'empirenovel', 'novelfull', 'novelfire', 'royalroad']);

        $this->get(route('novels.discover', ['medium' => 'novel']))->assertOk()
            ->assertSee('All novel sources are hidden.')
            ->assertDontSee('No novel sources are set up.')
            ->assertSee('Choose sources');
    }

    public function testNoSourcesAtAllKeepsTheOriginalMessage(): void
    {
        // Suwayomi off and MangaDex not registered: genuinely no manga source.
        config(['novarr.suwayomi.url' => '', 'novarr.sources' => [
            'empirenovel' => \App\Sources\EmpireNovelSource::class,
            'novelarrow' => \App\Sources\NovelArrowSource::class,
        ]]);
        $this->hide(['novelfull']); // a hidden key of another medium changes nothing here

        $html = $this->get(route('novels.discover', ['medium' => 'manga']))->assertOk()->getContent();
        $this->assertStringContainsString('No manga sources are set up.', $html);
        $this->assertStringNotContainsString('All manga sources are hidden.', $html);
        $this->assertStringNotContainsString('Choose sources', $html);
    }

    // ---- Settings page ---------------------------------------------------------

    public function testTheSettingsPageListsBothGroupsWithHiddenSourcesUnticked(): void
    {
        $this->suwayomi();
        $this->hide([self::MANGAPILL, 'novelfull', 'stale:1']);

        $html = $this->get(route('settings.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<fieldset class="form-fieldset" id="discover-sources">', $html);
        $this->assertStringContainsString('<legend>Sources when adding</legend>', $html);
        $this->assertMatchesRegularExpression('#data-ds-group="novel">\s*<span class="form-label d-block">Novels</span>#', $html);
        $this->assertMatchesRegularExpression('#data-ds-group="manga">\s*<span class="form-label d-block">Manga</span>#', $html);

        $this->assertSame([
            'novelarrow' => true,
            'empirenovel' => true,
            'novelfull' => false,
            'novelfire' => true,
            'royalroad' => true,
            'mangadex' => true,
            self::FLAMESCANS => true,
            self::MANGAPILL => false,
        ], self::boxes($html));

        // Every offered key is posted alongside.
        preg_match_all('#name="discover_offered\[\]" value="([^"]+)"#', $html, $m);
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfull', 'novelfire', 'royalroad', 'mangadex', self::FLAMESCANS, self::MANGAPILL], $m[1]);

        // Label, then the host in muted text when it differs.
        $this->assertStringContainsString('Suwayomi · MangaPill <span class="text-muted small">192.168.1.37:4567</span></label>', $html);
        // Few sources: no Show all / Hide all / filter, and Suwayomi did list.
        $this->assertStringNotContainsString('data-ds-all="', $html);
        $this->assertStringNotContainsString('data-ds-filter placeholder', $html);
        $this->assertStringNotContainsString('id="discoverSuwayomiMissing"', $html);
    }

    public function testAMangaGroupOfMoreThanEightGetsShowHideAndAFilter(): void
    {
        $this->suwayomi(self::manySources(9));

        $html = $this->get(route('settings.index'))->assertOk()->getContent();
        $this->assertCount(15, self::boxes($html), '5 novel + MangaDex + 9 Suwayomi');
        $this->assertSame(1, substr_count($html, 'data-ds-all="1">Show all</button>'));
        $this->assertSame(1, substr_count($html, 'data-ds-all="0">Hide all</button>'));
        $this->assertSame(1, substr_count($html, 'data-ds-filter placeholder'));
        // Only within the Manga group.
        $manga = substr($html, strpos($html, 'data-ds-group="manga"'));
        $this->assertStringContainsString('data-ds-filter placeholder="Filter manga sources…"', $manga);
    }

    public function testSuwayomiDownStillRendersAndSaysSo(): void
    {
        $fetcher = $this->suwayomi(FakeFetcher::fail('exception', null));

        $html = $this->get(route('settings.index'))->assertOk()->getContent();
        $this->assertSame(['novelarrow' => true, 'empirenovel' => true, 'novelfull' => true, 'novelfire' => true, 'royalroad' => true, 'mangadex' => true], self::boxes($html));
        $this->assertStringContainsString('<div class="form-text" id="discoverSuwayomiMissing">The Suwayomi sources could not be loaded right now.</div>', $html);
        $this->assertCount(1, $fetcher->calls, 'one source listing per page load, nothing more');
        $this->assertSame(15, $fetcher->calls[0]['timeout']);
    }

    public function testSavingStoresTheUntickedKeysAsHidden(): void
    {
        $this->suwayomi();

        $offered = ['novelarrow', 'empirenovel', 'novelfull', 'novelfire', 'royalroad', 'mangadex', self::FLAMESCANS, self::MANGAPILL];
        $this->save($offered, ['novelarrow', 'empirenovel', 'novelfire', 'royalroad', 'mangadex', self::FLAMESCANS])
            ->assertRedirect(route('settings.index'))->assertSessionHasNoErrors();
        $this->assertSame(['novelfull', self::MANGAPILL], $this->storedHidden());

        // Ticking them again shows them again.
        $this->save($offered, $offered)->assertRedirect(route('settings.index'));
        $this->assertSame([], $this->storedHidden());
        $this->assertSame($offered, array_keys(app(Discover::class)->sources()));
    }

    public function testKeysNotOfferedOnTheFormKeepTheirState(): void
    {
        $this->suwayomi();
        $this->hide([self::MANGAPILL, 'novelfull', 'stale:1']);

        // The form was rendered while Suwayomi was down: only these were offered.
        $this->save(['novelarrow', 'empirenovel', 'novelfull', 'mangadex'], ['novelarrow', 'novelfull', 'mangadex'])
            ->assertRedirect(route('settings.index'));

        $hidden = $this->storedHidden();
        sort($hidden);
        $this->assertSame(['empirenovel', 'stale:1', self::MANGAPILL], $hidden, 'MangaPill stays hidden; FlameScans stays shown');
        $this->assertArrayHasKey(self::FLAMESCANS, app(Discover::class)->sources());
    }

    public function testKeysThatAreNotKnownNowAreIgnored(): void
    {
        $this->suwayomi();
        $this->hide(['novelfull']);

        // Offered when the page rendered, but not known at save time (or forged).
        $this->save(['novelfull', 'suwayomi:999', 'made-up'], ['novelfull'])->assertRedirect(route('settings.index'));
        $this->assertSame([], $this->storedHidden(), 'only known keys are hidden or shown');
    }

    public function testSaveWithoutThePickerLeavesTheListAlone(): void
    {
        $this->suwayomi();
        $this->hide(['novelfull']);

        $this->post(route('settings.update'), ['scrape_min_delay' => '20'])->assertRedirect(route('settings.index'));
        $this->assertSame(['novelfull'], $this->storedHidden());
    }

    public function testPostedKeysAreValidated(): void
    {
        $this->suwayomi();
        $this->hide(['novelfull']);

        $this->from(route('settings.index'))->save([str_repeat('x', 300)], [])->assertSessionHasErrors('discover_offered.0');
        $this->from(route('settings.index'))->save(['novelfull'], [['nested']])->assertSessionHasErrors('discover_shown.0');
        $this->from(route('settings.index'))->post(route('settings.update'), ['discover_offered' => 'novelfull'])->assertSessionHasErrors('discover_offered');
        $this->assertSame(['novelfull'], $this->storedHidden());
    }
}
