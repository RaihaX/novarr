<?php

namespace Tests\Feature;

use App\Audiobook;
use App\AudiobookChapter;
use App\Audiobooks\Audible;
use App\Audiobooks\AudiobookEnricher;
use App\Audiobooks\AudiobookInbox;
use App\Audiobooks\AudiobookLibrary;
use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAudioProbe;
use Tests\TestCase;

/**
 * Audiobook metadata from Audible + Audnexus (and Apple Books' covers):
 * App\Audiobooks\AudiobookEnricher, on import, "Look up on Audible" and
 * `audiobooks:enrich`. The files are fake audio (FakeAudioProbe); every
 * catalogue is faked (Http::fake).
 */
class AudiobookEnrichTest extends TestCase
{
    use RefreshDatabase;

    private const ASIN = 'B002V0QCYU';

    /** Audible's runtime: 1479 min; Audnexus' chapters: 88 767 485 ms. */
    private const RUNTIME_MS = 88_767_485;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::put('dashboard_attention', [], 900);
        File::deleteDirectory(dirname(AudiobookLibrary::inbox()));
        File::makeDirectory(AudiobookLibrary::inbox(), 0775, true);
        config(['novarr.audiobooks.settle_seconds' => 0, 'novarr.audiobooks.sources' => ['audible', 'itunes']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname(AudiobookLibrary::inbox()));
        parent::tearDown();
    }

    // --- Fixtures --------------------------------------------------------------

    private static function product(array $overrides = []): array
    {
        return array_merge([
            'asin' => self::ASIN,
            'title' => 'The Final Empire',
            'subtitle' => 'Mistborn Book 1',
            'authors' => [['asin' => 'B001IGFHW6', 'name' => 'Brandon Sanderson']],
            'narrators' => [['name' => 'Michael Kramer']],
            'runtime_length_min' => 1479,
            'release_date' => '2008-12-28',
            'series' => [
                ['asin' => 'B0DMXTJ8WH', 'sequence' => '', 'title' => 'The Cosmere'],
                ['asin' => 'B006K1P698', 'sequence' => '1', 'title' => 'The Mistborn Saga'],
            ],
            'product_images' => ['500' => 'https://m.media-amazon.com/images/I/61rYqiz8yJL._SL500_.jpg'],
            'publisher_summary' => '<p><b>For a thousand years</b> the ash fell.</p>',
        ], $overrides);
    }

    private static function audnexusBook(): array
    {
        return [
            'asin' => self::ASIN, 'title' => 'The Final Empire', 'subtitle' => 'Mistborn Book 1',
            'authors' => [['name' => 'Brandon Sanderson']], 'narrators' => [['name' => 'Michael Kramer']],
            'seriesPrimary' => ['asin' => 'B006K1P698', 'name' => 'The Mistborn Saga', 'position' => '1'],
            'image' => 'https://m.media-amazon.com/images/I/917NNRCArfL.jpg',
            'summary' => '<p>For a thousand years the ash fell and no flowers bloomed.</p>',
            'runtimeLengthMin' => 1479, 'releaseDate' => '2008-12-28T00:00:00.000Z',
        ];
    }

    private static function audnexusChapters(int $runtime = self::RUNTIME_MS): array
    {
        return [
            'asin' => self::ASIN, 'isAccurate' => true, 'runtimeLengthMs' => $runtime,
            'chapters' => [
                ['lengthMs' => 10231, 'startOffsetMs' => 0, 'title' => 'Opening Credits'],
                ['lengthMs' => 2155890, 'startOffsetMs' => 10231, 'title' => 'Prologue'],
                ['lengthMs' => 40000000, 'startOffsetMs' => 2166121, 'title' => 'Chapter 1'],
                ['lengthMs' => $runtime - 42166121, 'startOffsetMs' => 42166121, 'title' => 'Epilogue'],
            ],
        ];
    }

    /** What the faked Audible search answers (changeable mid-test: a second Http::fake() would not replace the first). */
    private ?array $products = null;

    private function fakeCatalogues(array $products = [], ?array $chapters = null, array $itunes = []): void
    {
        $this->products = $products ?: [self::product()];
        Http::fake([
            'api.audible.com/*' => fn() => Http::response(['products' => $this->products, 'total_results' => count($this->products)]),
            'api.audnex.us/books/' . self::ASIN . '/chapters*' => Http::response($chapters ?? self::audnexusChapters()),
            'api.audnex.us/books/' . self::ASIN . '*' => Http::response(self::audnexusBook()),
            'm.media-amazon.com/*' => Http::response(FakeAudioProbe::jpeg(500, 500), 200, ['Content-Type' => 'image/jpeg']),
            'itunes.apple.com/*' => Http::response(['results' => $itunes]),
            'is1-ssl.mzstatic.com/*' => Http::response(FakeAudioProbe::jpeg(600, 600), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function drop(string $relative, string $bytes): void
    {
        $path = AudiobookLibrary::inbox() . '/' . $relative;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $bytes);
    }

    private function importOne(string $name, int $durationMs, array $tags = [], array $chapters = [], bool $cover = false): Audiobook
    {
        $this->drop($name, FakeAudioProbe::audioFile($durationMs, $tags, $chapters, $cover));
        app(AudiobookInbox::class)->scan();

        return Audiobook::where('source_name', $name)->sole();
    }

    private static function titles(Audiobook $audiobook): array
    {
        return AudiobookChapter::where('audiobook_id', $audiobook->id)->orderBy('idx')->pluck('title')->all();
    }

    // --- Import ----------------------------------------------------------------

    public function test_an_import_without_chapters_gets_audibles_details_cover_and_chapters(): void
    {
        $this->fakeCatalogues();
        $duration = 88_700_000; // 0.08 % from Audible's runtime
        $audiobook = $this->importOne('final-empire.m4b', $duration, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);

        $this->assertSame(self::ASIN, $audiobook->asin);
        $this->assertSame('audible', $audiobook->metadata_source);
        $this->assertNotNull($audiobook->enriched_at);
        $this->assertSame('Michael Kramer', $audiobook->narrator);
        $this->assertSame('For a thousand years the ash fell and no flowers bloomed.', $audiobook->description, 'Audnexus\' summary, as text');
        $this->assertSame(['The Mistborn Saga', 1.0], [$audiobook->series, $audiobook->series_index], 'the primary series, not the Cosmere');
        $this->assertSame(2008, $audiobook->year);
        // The cover: Audnexus' full-size image, stored with thumbnails.
        $this->assertSame('audible', $audiobook->cover_source);
        Storage::disk('public')->assertExists($audiobook->cover_path);
        Http::assertSent(fn(HttpRequest $r) => $r->url() === 'https://m.media-amazon.com/images/I/917NNRCArfL.jpg');

        // "Part 1" became Audible's chapters, at Audible's offsets; the last ends with the files.
        $this->assertSame('audible', $audiobook->chapters_source);
        $this->assertSame(['Opening Credits', 'Prologue', 'Chapter 1', 'Epilogue'], self::titles($audiobook));
        $chapters = AudiobookChapter::where('audiobook_id', $audiobook->id)->orderBy('idx')->get();
        $this->assertSame([0, 10231, 2166121, 42166121], $chapters->pluck('start_ms')->all());
        $this->assertSame($duration, $chapters->last()->end_ms);
        $this->assertSame(10231, $chapters->first()->end_ms);

        // The search: title + author, the region's host, the documented response groups.
        Http::assertSent(fn(HttpRequest $r) => str_starts_with($r->url(), 'https://api.audible.com/1.0/catalog/products?')
            && $r['title'] === 'The Final Empire' && $r['author'] === 'Brandon Sanderson' && $r['products_sort_by'] === 'Relevance'
            && $r['response_groups'] === Audible::RESPONSE_GROUPS);

        // The API detail carries it (new fields at the end).
        $detail = $this->getJson("/api/v1/audiobooks/{$audiobook->id}")->assertOk()->json('audiobook');
        $this->assertSame(['The Mistborn Saga', 1, self::ASIN], [$detail['series'], $detail['series_index'], $detail['asin']]);
    }

    public function test_embedded_chapters_narrator_and_cover_are_never_overwritten(): void
    {
        $this->fakeCatalogues();
        $audiobook = $this->importOne('final-empire.m4b', 88_700_000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson', 'composer' => 'Someone Else', 'description' => 'The files\' own description, long enough.'], [
            ['title' => 'Prologue', 'start_ms' => 0, 'end_ms' => 1_000_000],
            ['title' => 'One', 'start_ms' => 1_000_000, 'end_ms' => 88_700_000],
        ], true);

        $this->assertSame(self::ASIN, $audiobook->asin);
        $this->assertSame('embedded', $audiobook->chapters_source);
        $this->assertSame(['Prologue', 'One'], self::titles($audiobook));
        $this->assertSame('Someone Else', $audiobook->narrator);
        $this->assertSame('The files\' own description, long enough.', $audiobook->description);
        $this->assertSame('files', $audiobook->cover_source);
        Http::assertNotSent(fn(HttpRequest $r) => str_contains($r->url(), '/chapters'));
        Http::assertNotSent(fn(HttpRequest $r) => str_contains($r->url(), 'media-amazon'));

        // The owner may still take Audible's cover.
        $old = $audiobook->cover_path;
        $this->post(route('audiobooks.manage.lookup', $audiobook->id), ['cover' => 1])->assertRedirect(route('audiobooks.manage'))->assertSessionHas('success');
        $audiobook->refresh();
        $this->assertSame('audible', $audiobook->cover_source);
        $this->assertNotSame($old, $audiobook->cover_path);
        Storage::disk('public')->assertMissing($old);
        $this->assertSame(['Prologue', 'One'], self::titles($audiobook), 'still never the chapters');
    }

    public function test_a_folder_of_one_file_per_chapter_gets_audibles_chapter_names(): void
    {
        $this->fakeCatalogues();
        $half = intdiv(self::RUNTIME_MS, 2);
        $this->drop('Brandon Sanderson - The Final Empire/01.mp3', FakeAudioProbe::audioFile($half, ['album' => 'The Final Empire', 'artist' => 'Brandon Sanderson', 'title' => 'Track 1']));
        $this->drop('Brandon Sanderson - The Final Empire/02.mp3', FakeAudioProbe::audioFile(self::RUNTIME_MS - $half, ['album' => 'The Final Empire', 'artist' => 'Brandon Sanderson', 'title' => 'Track 2']));
        app(AudiobookInbox::class)->scan();
        $audiobook = Audiobook::sole();

        $this->assertSame(['Opening Credits', 'Prologue', 'Chapter 1', 'Epilogue'], self::titles($audiobook));
        $this->assertSame(2, $audiobook->parts()->count(), 'the files are untouched');
    }

    public function test_chapters_need_the_runtimes_within_two_percent_and_a_match_within_three(): void
    {
        $this->fakeCatalogues();
        // 2.6 % shorter: the same recording (within 3 %), but its chapters would not line up.
        $close = $this->importOne('close.m4b', 86_500_000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);
        $this->assertSame(self::ASIN, $close->asin);
        $this->assertSame(['Part 1'], self::titles($close));
        $this->assertSame('files', $close->chapters_source);

        // 10 % shorter: another recording (abridged…): not matched at all.
        $other = $this->importOne('abridged.m4b', 80_000_000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);
        $this->assertNull($other->asin);
        $this->assertNotNull($other->enriched_at, 'Audible answered: looked up');
        $this->assertSame(['Part 1'], self::titles($other));
    }

    public function test_another_title_or_author_on_audible_is_not_a_match(): void
    {
        $this->fakeCatalogues([self::product(['title' => 'The Well of Ascension']), self::product(['authors' => [['name' => 'Someone Else']]])]);
        $audiobook = $this->importOne('final-empire.m4b', 88_700_000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);
        $this->assertNull($audiobook->asin);
        $this->assertNull($audiobook->narrator);
    }

    public function test_audible_down_never_fails_an_import_and_apple_books_gives_a_cover(): void
    {
        Http::fake([
            'api.audible.com/*' => Http::response('down', 503),
            'itunes.apple.com/*' => Http::response(['results' => [[
                'collectionId' => 1, 'collectionName' => 'The Final Empire (Unabridged)', 'artistName' => 'Brandon Sanderson',
                'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/Music/v4/fe.jpg/100x100bb.jpg',
            ]]]),
            'is1-ssl.mzstatic.com/*' => Http::response(FakeAudioProbe::jpeg(600, 600), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $audiobook = $this->importOne('final-empire.m4b', 88_700_000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);

        $this->assertSame(Audiobook::STATUS_UNMATCHED, $audiobook->status, 'imported as usual');
        $this->assertNull($audiobook->asin);
        $this->assertSame('itunes', $audiobook->cover_source);
        Http::assertSent(fn(HttpRequest $r) => $r->url() === 'https://is1-ssl.mzstatic.com/image/thumb/Music/v4/fe.jpg/1200x1200bb.jpg');
        Http::assertSent(fn(HttpRequest $r) => str_contains($r->url(), 'itunes.apple.com/search') && $r['media'] === 'audiobook');
    }

    public function test_nothing_is_asked_when_the_sources_are_off(): void
    {
        config(['novarr.audiobooks.sources' => []]);
        Http::fake();
        $audiobook = $this->importOne('final-empire.m4b', 88_700_000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);
        $this->assertNull($audiobook->enriched_at);
        Http::assertNothingSent();
        $this->artisan('audiobooks:enrich')->expectsOutputToContain('No audiobook catalogue is switched on')->assertSuccessful();
    }

    public function test_audibles_title_and_author_help_match_the_book(): void
    {
        $this->fakeCatalogues();
        $book = Novel::create(['name' => 'The Final Empire', 'author' => 'Brandon Sanderson', 'status' => 1, 'group_id' => 1,
            'medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1('tfe')]);
        // A title the library's rule cannot match ("…: Mistborn, Book 1" is not "The Final Empire");
        // Audible finds it, and its title matches the book.
        $audiobook = $this->importOne('mistborn1.m4b', 88_700_000, ['title' => 'The Final Empire: Mistborn, Book 1', 'artist' => 'Brandon Sanderson']);

        $this->assertSame(self::ASIN, $audiobook->asin);
        $this->assertSame($book->id, $audiobook->novel_id);
        $this->assertSame(Audiobook::STATUS_READY, $audiobook->status);

        // Without Audible, the same files stay unmatched.
        $this->products = [];
        Cache::flush();
        $other = $this->importOne('mistborn1-again.m4b', 88_700_001, ['title' => 'The Final Empire: Mistborn, Book 1', 'artist' => 'Brandon Sanderson']);
        $this->assertNull($other->novel_id);
    }

    public function test_the_manage_page_offers_the_lookup_and_the_command_skips_looked_up_ones(): void
    {
        $this->fakeCatalogues();
        $audiobook = $this->importOne('final-empire.m4b', 88_700_000, ['title' => 'The Final Empire', 'artist' => 'Brandon Sanderson']);
        $this->get(route('audiobooks.manage'))->assertOk()
            ->assertSee('data-audiobook-lookup="' . $audiobook->id . '"', false)
            ->assertSee('Look up on Audible');

        $sent = count(Http::recorded());
        $this->artisan('audiobooks:enrich')->expectsOutputToContain('0 looked up')->assertSuccessful();
        $this->assertCount($sent, Http::recorded());

        // Not found: said so.
        Cache::flush();
        $this->products = [];
        $audiobook->forceFill(['asin' => null])->save();
        $this->post(route('audiobooks.manage.lookup', $audiobook->id))->assertSessionHas('error');
    }

    public function test_placeholder_chapters_are_recognised_for_audiobooks_imported_before(): void
    {
        $audiobook = Audiobook::create(['title' => 'X', 'dir' => '1', 'status' => 'ready', 'fingerprint' => sha1('x'), 'duration_ms' => 1000]);
        $chapter = fn(string $title, int $idx = 0) => AudiobookChapter::create(['audiobook_id' => $audiobook->id, 'idx' => $idx, 'title' => $title, 'start_ms' => $idx * 100, 'end_ms' => ($idx + 1) * 100]);

        $this->assertTrue(AudiobookEnricher::placeholderChapters($audiobook), 'none');
        $chapter('Part 1');
        $chapter('Track 02', 1);
        $chapter('03', 2);
        $this->assertTrue(AudiobookEnricher::placeholderChapters($audiobook));
        $chapter('The Real Chapter', 3);
        $this->assertFalse(AudiobookEnricher::placeholderChapters($audiobook));
        $audiobook->chapters_source = 'files';
        $this->assertTrue(AudiobookEnricher::placeholderChapters($audiobook));
        $audiobook->chapters_source = 'embedded';
        $this->assertFalse(AudiobookEnricher::placeholderChapters($audiobook));
    }

    public function test_the_region_picks_the_store(): void
    {
        config(['novarr.audiobooks.audible_region' => 'AU']);
        Http::fake(['api.audible.com.au/*' => Http::response(['products' => [self::product()]])]);
        $this->assertSame(self::ASIN, app(Audible::class)->search('The Final Empire')[0]['asin']);
        config(['novarr.audiobooks.audible_region' => 'mars']);
        $this->assertSame('us', Audible::region());
        $this->assertSame('https://m.media-amazon.com/images/I/61rYqiz8yJL.jpg', Audible::bigImage('https://m.media-amazon.com/images/I/61rYqiz8yJL._SL500_.jpg'));
    }
}
