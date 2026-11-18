<?php

namespace Tests\Feature;

use App\Jobs\RunNovelCommand;
use App\Novel;
use App\Scraping\Fetcher;
use App\Services\DuplicateSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * The same series is not added twice, even from another source
 * (DuplicateSeries): the matcher, every add path (web form, the Discover
 * command runner, novel:create, POST /api/v1/novels) and the Discover
 * "In library" marking.
 */
class DuplicateSeriesTest extends TestCase
{
    use RefreshDatabase;

    private const MANGADEX = 'https://mangadex.org/title/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b';
    private const MANGADEX_OTHER = 'https://mangadex.org/title/32d76d19-8a05-4db0-9fc2-e0b0648fe9d0';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ------------------------------------------------------------------
    // Normalisation
    // ------------------------------------------------------------------

    public static function sameTitles(): array
    {
        return [
            'case and spacing' => ['Solo Leveling', '  solo   LEVELING '],
            'whitespace removed' => ['Solo Leveling', 'SoloLeveling'],
            'punctuation' => ['Re:Zero', 're zero!'],
            'apostrophes' => ["The Hero's Return", 'Hero’s Return'],
            'accents' => ['Pokémon Adventures', 'Pokemon Adventures'],
            'ampersand' => ['Spy & Family', 'Spy and Family'],
            'leading the' => ['The Beginning After the End', 'Beginning After the End'],
            'official tag' => ['Solo Leveling (Official)', 'Solo Leveling'],
            'colored tag' => ['Berserk [Colored]', 'Berserk'],
            'manga tag' => ['Omniscient Reader (Manga)', "Omniscient Reader"],
            'novel tag' => ['Shadow Slave (Novel)', 'Shadow Slave'],
            'wn tag' => ['Shadow Slave (WN)', 'Shadow Slave'],
            'ln tag' => ['Overlord (LN)', 'overlord'],
            'several tags' => ['Tower of God (Official) [Colored]', 'Tower of God'],
            'html entity' => ['Spy &amp; Family', 'Spy and Family'],
        ];
    }

    #[DataProvider('sameTitles')]
    public function testTitlesThatNormaliseAlike(string $a, string $b): void
    {
        $this->assertNotSame('', DuplicateSeries::normalize($a));
        $this->assertSame(DuplicateSeries::normalize($a), DuplicateSeries::normalize($b));
    }

    public static function differentTitles(): array
    {
        return [
            'sequel' => ['Solo Leveling', 'Solo Leveling: Ragnarok'],
            'subtitle' => ['Overlord', 'Overlord: The Undead King'],
            'year is not a tag' => ['Hellsing (2001)', 'Hellsing'],
            'the inside a title' => ['Lord of the Mysteries', 'Lord of Mysteries'],
            'similar is not equal' => ['Solo Levelling', 'Solo Leveling'],
        ];
    }

    #[DataProvider('differentTitles')]
    public function testTitlesThatStayDifferent(string $a, string $b): void
    {
        $this->assertNotSame(DuplicateSeries::normalize($a), DuplicateSeries::normalize($b));
    }

    public function testNormalisedForm(): void
    {
        $this->assertSame('beginningaftertheend',DuplicateSeries::normalize('The Beginning After the End'));
        $this->assertSame('spyandfamily', DuplicateSeries::normalize('Spy & Family'));
        $this->assertSame('rezero', DuplicateSeries::normalize('Re:Zero (Light Novel)'));
        $this->assertSame('', DuplicateSeries::normalize(' (Official) '));
        $this->assertSame('', DuplicateSeries::normalize('!!!'));
    }

    // ------------------------------------------------------------------
    // The matcher
    // ------------------------------------------------------------------

    public function testFindMatchesWithinTheSameMediumOnly(): void
    {
        $manga = $this->manga('Solo Leveling', self::MANGADEX);
        $duplicates = app(DuplicateSeries::class);

        $found = $duplicates->find('Solo Leveling (Official)', Novel::MEDIUM_MANGA, [], 'https://example.test/solo');
        $this->assertSame($manga->id, $found['novel']->id);
        $this->assertSame(DuplicateSeries::BY_TITLE, $found['by']);

        // A novel of the same title is a different series.
        $this->assertNull($duplicates->find('Solo Leveling', Novel::MEDIUM_NOVEL));
        // A sequel is a different series.
        $this->assertNull($duplicates->find('Solo Leveling: Ragnarok', Novel::MEDIUM_MANGA));
        // A deleted series is not in the library.
        $manga->delete();
        $this->assertNull($duplicates->find('Solo Leveling', Novel::MEDIUM_MANGA));
    }

    public function testFindMatchesTheSameSourcePageWhateverTheTitle(): void
    {
        $novel = Novel::create(['name' => 'Shadow Slave', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);

        $found = app(DuplicateSeries::class)->find('Renamed Upstream', null, [], 'https://novelping.com/novel/shadow-slave');
        $this->assertSame($novel->id, $found['novel']->id);
        $this->assertSame(DuplicateSeries::BY_SOURCE, $found['by']);
    }

    public function testFindMatchesAlternativeTitles(): void
    {
        $novel = Novel::create(['name' => 'Na Honjaman Level Up', 'group_id' => 1, 'translator_url' => 'https://example.test/a']);

        $found = app(DuplicateSeries::class)->find('Solo Leveling', Novel::MEDIUM_NOVEL, ['Na Honjaman Level-Up!']);
        $this->assertSame($novel->id, $found['novel']->id);
    }

    public function testDescribeAndMessage(): void
    {
        $manga = $this->manga('Solo Leveling', 'https://www.MangaDex.org/title/x');

        $this->assertSame(['id' => $manga->id, 'title' => 'Solo Leveling', 'source' => 'mangadex.org'], DuplicateSeries::describe($manga));
        $this->assertSame('“Solo Leveling” is already in your library (from mangadex.org).', DuplicateSeries::message($manga));

        $manual = Novel::create(['name' => 'Typed In', 'group_id' => 1]);
        $this->assertNull(DuplicateSeries::describe($manual)['source']);
        $this->assertSame('“Typed In” is already in your library.', DuplicateSeries::message($manual));
    }

    // ------------------------------------------------------------------
    // Web: manual add form
    // ------------------------------------------------------------------

    public function testManualAddOfTheSameTitleFromAnotherSourceIsRefused(): void
    {
        $existing = Novel::create(['name' => 'Shadow Slave', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/shadow-slave']);

        $this->post(route('novels.store'), ['name' => 'Shadow Slave (WN)', 'translator_url' => 'https://www.novelfull.com/shadow-slave.html'])
            ->assertRedirect(route('novels.create', ['medium' => Novel::MEDIUM_NOVEL]))
            ->assertSessionHas('duplicate', fn(array $d) => $d['id'] === $existing->id && $d['match'] === 'title'
                && $d['url'] === route('novels.show', $existing->id) && $d['source'] === 'novelping.com')
            ->assertSessionHasInput('name', 'Shadow Slave (WN)');

        $this->assertSame(1, Novel::count());

        // The form names and links the existing series and offers "Add anyway".
        $this->get(route('novels.create', ['medium' => Novel::MEDIUM_NOVEL]))
            ->assertOk()
            ->assertSee('id="duplicateSeries"', false)
            ->assertSee(route('novels.show', $existing->id), false)
            ->assertSee('is already in your library (from novelping.com)', false)
            ->assertSee('Add anyway');
    }

    public function testManualAddAnywayCreatesTheSecondSeries(): void
    {
        Novel::create(['name' => 'Shadow Slave', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/shadow-slave']);

        $this->post(route('novels.store'), ['name' => 'Shadow Slave', 'translator_url' => 'https://www.novelfull.com/shadow-slave.html', 'force' => '1'])
            ->assertRedirect();

        $this->assertSame(2, Novel::count());
    }

    public function testManualAddOfTheSameSourcePageIsRefusedEvenWhenForced(): void
    {
        $existing = Novel::create(['name' => 'Shadow Slave', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/shadow-slave']);

        $this->post(route('novels.store'), ['name' => 'Something Else', 'translator_url' => 'https://novelping.com/novel/shadow-slave/', 'force' => '1'])
            ->assertRedirect(route('novels.create', ['medium' => Novel::MEDIUM_NOVEL]))
            ->assertSessionHas('duplicate', fn(array $d) => $d['id'] === $existing->id && $d['match'] === 'source');

        $this->assertSame(1, Novel::count());

        // No "Add anyway" for the same page.
        $this->get(route('novels.create'))->assertOk()->assertSee('not added again')->assertDontSee('id="addAnyway"', false);
    }

    public function testManualAddOfAMangaTitleThatIsAlsoANovelIsAllowed(): void
    {
        Novel::create(['name' => 'Solo Leveling', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/solo-leveling']);

        $this->post(route('novels.store'), ['name' => 'Solo Leveling', 'translator_url' => self::MANGADEX])
            ->assertRedirect()
            ->assertSessionMissing('duplicate');

        $this->assertSame(1, Novel::where('medium', Novel::MEDIUM_MANGA)->count());
        $this->assertSame(2, Novel::count());
    }

    // ------------------------------------------------------------------
    // Web: Discover "Add" (the create_novel command runner)
    // ------------------------------------------------------------------

    public function testDiscoverAddOfADuplicateIsRefusedBeforeQueueing(): void
    {
        Bus::fake();
        $existing = $this->manga('Solo Leveling', self::MANGADEX);

        $this->postJson(route('commands.execute-async'), ['command' => 'create_novel', 'name' => 'Solo Leveling (Official)', 'url' => self::MANGADEX_OTHER])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', '“Solo Leveling” is already in your library (from mangadex.org).')
            ->assertJsonPath('duplicate.id', $existing->id)
            ->assertJsonPath('duplicate.title', 'Solo Leveling')
            ->assertJsonPath('duplicate.source', 'mangadex.org')
            ->assertJsonPath('duplicate.url', route('novels.show', $existing->id))
            ->assertJsonPath('duplicate.match', 'title');

        // The synchronous runner too.
        $this->postJson(route('commands.execute'), ['command' => 'create_novel', 'name' => 'Solo Leveling', 'url' => self::MANGADEX_OTHER])
            ->assertStatus(409);

        Bus::assertNotDispatched(RunNovelCommand::class);
    }

    public function testDiscoverAddAnywayQueuesTheCommandWithForce(): void
    {
        Bus::fake();
        $this->manga('Solo Leveling', self::MANGADEX);

        $this->postJson(route('commands.execute-async'), ['command' => 'create_novel', 'name' => 'Solo Leveling', 'url' => self::MANGADEX_OTHER, 'force' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        Bus::assertDispatched(RunNovelCommand::class, fn(RunNovelCommand $job) => $job->artisanCommand === 'novel:create'
            && $job->params === ['name' => 'Solo Leveling', 'url' => self::MANGADEX_OTHER, '--force' => true]);
    }

    public function testDiscoverAddOfTheSameSourcePageIsRefusedEvenWhenForced(): void
    {
        Bus::fake();
        $this->manga('Solo Leveling', self::MANGADEX);

        $this->postJson(route('commands.execute-async'), ['command' => 'create_novel', 'name' => 'Whatever', 'url' => self::MANGADEX, 'force' => 1])
            ->assertStatus(409)
            ->assertJsonPath('duplicate.match', 'source');

        Bus::assertNotDispatched(RunNovelCommand::class);
    }

    public function testTheCommandsFormOffersAddAnyway(): void
    {
        $this->get(route('commands.form', 'create_novel'))->assertOk()->assertSee('name="force"', false)->assertSee('Add anyway');
    }

    // ------------------------------------------------------------------
    // novel:create (queued / console): skip and report, never crash
    // ------------------------------------------------------------------

    public function testCreateCommandSkipsADuplicateAndReportsIt(): void
    {
        $existing = $this->manga('Solo Leveling', self::MANGADEX);

        $this->artisan('novel:create', ['name' => 'Solo Leveling [Colored]', 'url' => self::MANGADEX_OTHER])
            ->expectsOutputToContain("Skipped: “Solo Leveling” is already in your library (from mangadex.org). Existing ID: {$existing->id}. Run with --force to add it anyway.")
            ->assertExitCode(0);

        $this->artisan('novel:create', ['name' => 'Renamed', 'url' => self::MANGADEX, '--force' => true])
            ->expectsOutputToContain('Skipped:')
            ->assertExitCode(0);

        $this->assertSame(1, Novel::count());
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    public function testApiRefusesTheSameTitleFromAnotherSourceWith409(): void
    {
        Bus::fake();
        $existing = $this->manga('Solo Leveling', self::MANGADEX);

        $this->postJson('/api/v1/novels', ['title' => 'Solo Leveling (Official)', 'url' => self::MANGADEX_OTHER])
            ->assertStatus(409)
            ->assertExactJson([
                'message' => '“Solo Leveling” is already in your library (from mangadex.org).',
                'duplicate' => ['id' => $existing->id, 'title' => 'Solo Leveling', 'source' => 'mangadex.org'],
            ]);

        $this->assertSame(1, Novel::count());
        Bus::assertNotDispatched(RunNovelCommand::class);
    }

    public function testApiForceAddsItWithTheUsualAnswer(): void
    {
        Bus::fake();
        $this->manga('Solo Leveling', self::MANGADEX);

        $this->postJson('/api/v1/novels', ['title' => 'Solo Leveling', 'url' => self::MANGADEX_OTHER, 'force' => 1])
            ->assertCreated()
            ->assertJsonPath('already_in_library', false)
            ->assertJsonPath('novel.name', 'Solo Leveling');

        $this->assertSame(2, Novel::count());
        $this->assertSame(Novel::MEDIUM_MANGA, Novel::latest('id')->first()->medium);
    }

    public function testApiSameSourcePageStillAnswersTheLibraryNovel(): void
    {
        Bus::fake();
        $existing = $this->manga('Solo Leveling', self::MANGADEX);

        foreach ([[], ['force' => 1]] as $extra) {
            $this->postJson('/api/v1/novels', ['title' => 'Solo Leveling', 'url' => self::MANGADEX] + $extra)
                ->assertOk()
                ->assertJsonPath('already_in_library', true)
                ->assertJsonPath('novel.id', $existing->id);
        }

        $this->assertSame(1, Novel::count());
    }

    public function testApiAllowsTheSameTitleInTheOtherMedium(): void
    {
        Bus::fake();
        Novel::create(['name' => 'Solo Leveling', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/solo-leveling']);

        $this->postJson('/api/v1/novels', ['title' => 'Solo Leveling', 'url' => self::MANGADEX])
            ->assertCreated()
            ->assertJsonPath('already_in_library', false);
    }

    // ------------------------------------------------------------------
    // Discover marking
    // ------------------------------------------------------------------

    public function testDiscoverMarksTitleMatchesFromAnotherSourceWithoutAQueryPerItem(): void
    {
        $list = json_encode(['items' => [
            ['novel_id' => 'shadow-slave', 'novel_name' => 'Shadow Slave', 'novel_author' => 'Guiltythree'],
            ['novel_id' => 'the-lord-of-mysteries', 'novel_name' => 'The Lord of Mysteries & Friends', 'novel_author' => 'Cuttlefish'],
            ['novel_id' => 'free-novel', 'novel_name' => 'A Free Novel', 'novel_author' => 'Someone'],
            ['novel_id' => 'solo-leveling', 'novel_name' => 'Solo Leveling', 'novel_author' => 'Chugong'],
        ]]);
        app()->instance(Fetcher::class, new FakeFetcher([], ['https://novelping.com/api-web/novels?' => $list]));

        $same = Novel::create(['name' => 'Shadow Slave', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);
        $other = Novel::create(['name' => 'Lord of Mysteries and Friends (WN)', 'group_id' => 1, 'translator_url' => 'https://www.novelfull.com/lotm.html']);
        // A manga of the same title is not this novel.
        $this->manga('Solo Leveling', self::MANGADEX);

        DB::enableQueryLog();
        $response = $this->getJson('/novels/discover/browse?type=search&q=x&source=novelarrow&medium=novel')->assertOk();
        $novelQueries = array_filter(DB::getQueryLog(), fn(array $q) => str_contains($q['query'], '"novels"'));
        DB::disableQueryLog();

        $items = collect($response->json('items'))->keyBy('name');
        $this->assertTrue($items['Shadow Slave']['in_library']);
        $this->assertSame('source', $items['Shadow Slave']['library_match']);
        $this->assertSame(route('novels.show', $same->id), $items['Shadow Slave']['novel_url']);

        $this->assertTrue($items['The Lord of Mysteries & Friends']['in_library']);
        $this->assertSame('title', $items['The Lord of Mysteries & Friends']['library_match']);
        $this->assertSame(route('novels.show', $other->id), $items['The Lord of Mysteries & Friends']['novel_url']);

        foreach (['A Free Novel', 'Solo Leveling'] as $name) {
            $this->assertFalse($items[$name]['in_library'], $name);
            $this->assertNull($items[$name]['library_match']);
            $this->assertNull($items[$name]['novel_url']);
        }

        // The library is read once for the whole list.
        $this->assertCount(1, $novelQueries);

        // The native API marks the same items, with the library novel's id.
        $api = collect($this->getJson('/api/v1/discover/browse?type=search&q=x&source=novelarrow')->assertOk()->json('items'))->keyBy('title');
        $this->assertSame($other->id, $api['The Lord of Mysteries & Friends']['novel_id']);
        $this->assertFalse($api['Solo Leveling']['in_library']);
    }

    public function testDiscoverPageLinksLibraryResultsAndOffersAddAnyway(): void
    {
        $html = $this->get(route('novels.discover', ['medium' => 'novel']))->assertOk()->getContent();

        $this->assertStringContainsString("markAdded(btn, 'In library', item.novel_url || null);", $html);
        $this->assertStringContainsString("if (item.library_match === 'title') addAnywayButton(card, item);", $html);
        $this->assertStringContainsString('...(force ? { force: 1 } : {}),', $html);
    }

    private function manga(string $name, string $url): Novel
    {
        return Novel::create(['name' => $name, 'group_id' => 1, 'translator_url' => $url, 'medium' => Novel::MEDIUM_MANGA]);
    }
}
