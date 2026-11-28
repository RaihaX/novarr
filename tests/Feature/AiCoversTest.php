<?php

namespace Tests\Feature;

use App\Books\BookCovers;
use App\Books\WebNovelCheck;
use App\Covers\CoverBrief;
use App\Covers\CoverComposer;
use App\Covers\CoverGenerator;
use App\Covers\CoverPainter;
use App\Covers\PainterBusy;
use App\File;
use App\Jobs\GenerateCover;
use App\Jobs\PushBookToCalibre;
use App\Novel;
use App\NovelChapter;
use App\Support\RouteAccess;
use App\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * AI covers (App\Covers): the LLM brief (parsing, <think> stripping, the
 * genre fallback), the SDXL prompt and seed, the painter (busy retries,
 * validation), the composition (2:3, sizes, long titles), which series get
 * one (placeholders yes, the owner's never, real covers no, books after the
 * online search), precedence (a catalogue cover replaces a generated one),
 * `covers:generate` (limit, backoff, stop after failures), the series-page
 * routes and job. Ollama and the painter are faked; images are made with GD.
 */
class AiCoversTest extends TestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://ollama.test:11434';
    private const PAINTER = 'http://painter.test:7861';

    private const BRIEF = [
        'genre' => 'xianxia',
        'setting' => 'a misty mountain sect',
        'mood' => 'ominous',
        'era' => 'ancient',
        'palette' => ['jade green', 'gold', 'black'],
        'key_scene' => 'a young swordsman kneeling in the snow before a shattered stone gate, a crimson moon above',
        'subject' => 'a pale young man in torn white robes',
        'style' => 'donghua-style digital painting',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::put('dashboard_attention', [], 900);
        Bus::fake([PushBookToCalibre::class]);
        Sleep::fake();
        config([
            'novarr.covers.ai.ollama_url' => self::OLLAMA,
            'novarr.covers.ai.painter_url' => self::PAINTER,
            'novarr.covers.ai.brief_model' => 'qwen3:8b',
            'novarr.covers.ai.busy_retries' => 2,
            'novarr.covers.ai.busy_wait' => 5,
            'novarr.covers.ai.max_failures' => 2,
            'novarr.books.sources' => ['openlibrary'],
        ]);
    }

    // --- Fixtures --------------------------------------------------------------

    /** A small painted-looking PNG (what the painter sends back). */
    private static function painting(int $w = 320, int $h = 468): string
    {
        return BookCoversTest::photo($w, $h, 'png');
    }

    /** A white page with a few lines of small black text (an ePub title page). */
    private static function blankPage(int $w = 600, int $h = 900): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 252, 252, 250));
        $ink = imagecolorallocate($im, 20, 20, 20);
        imagestring($im, 5, 60, 120, 'A Good Man', $ink);
        imagestring($im, 3, 60, 150, 'by Pars001', $ink);
        imagestring($im, 2, 60, 800, 'Copyright 2019 - all rights reserved', $ink);
        ob_start();
        imagejpeg($im, null, 85);

        return (string) ob_get_clean();
    }

    private function series(array $attributes = [], ?string $cover = null): Novel
    {
        $novel = Novel::create(array_merge([
            'name' => 'Sword of the Shattered Gate', 'author' => 'Er Gen', 'status' => 1, 'group_id' => 1,
            'medium' => Novel::MEDIUM_NOVEL, 'translator_url' => 'https://example.test/n/' . uniqid(),
            'description' => '<p>A disciple cast out of his sect seeks revenge.</p>',
        ], array_intersect_key($attributes, array_flip(['name', 'author', 'medium', 'translator_url', 'description']))));
        $extra = array_diff_key($attributes, array_flip(['name', 'author', 'medium', 'translator_url', 'description']));
        $extra['created_at'] ??= now()->subDays(10);
        $novel->forceFill($extra)->saveQuietly();
        if ($cover !== null) {
            Storage::disk('public')->put("old-{$novel->id}.jpg", $cover);
            $novel->attachCover("old-{$novel->id}.jpg");
        }

        return $novel->fresh('file');
    }

    /** Ollama answers $answer (string = the "response"), the painter a painting (or the given responses in turn). */
    private function fakeServices(string|int $answer = 'brief', array $painter = []): void
    {
        $answer = $answer === 'brief' ? '<think>The story is a xianxia revenge tale…</think>' . json_encode(self::BRIEF) : $answer;
        $painter = $painter ?: [Http::response(self::painting(), 200, ['Content-Type' => 'image/png'])];
        $sequence = Http::sequence();
        foreach ($painter as $response) {
            $sequence->pushResponse($response);
        }
        $sequence->whenEmpty(Http::response(self::painting(), 200, ['Content-Type' => 'image/png']));
        Http::fake([
            self::OLLAMA . '/api/generate' => is_int($answer) ? Http::response('down', $answer) : Http::response(['model' => 'qwen3:8b', 'response' => $answer, 'done' => true]),
            self::PAINTER . '/health' => Http::response(['ok' => true, 'model' => 'sdxl-base-1.0', 'busy' => false]),
            self::PAINTER . '/generate' => $sequence,
            '*' => Http::response('', 404),
        ]);
    }

    // --- The brief -------------------------------------------------------------

    public function test_the_brief_is_parsed_from_a_thinking_models_answer(): void
    {
        $raw = "<think>\nLet me think about the genre {not json}…\n</think>\n```json\n" . json_encode([
            'Genre' => 'Space Opera',
            'setting' => "a derelict\nstation",
            'mood' => 'tense',
            'era' => 'far future',
            'palette' => 'cobalt, silver and rust, teal, violet',
            'keyScene' => 'a lone pilot floating before a cracked viewport, "BOOK TITLE" written on a hull',
            'subject' => 'none',
            'style' => 'sci-fi concept art',
        ]) . "\n```";

        $brief = CoverBrief::parse($raw);

        $this->assertSame('Space Opera', $brief['genre']);
        $this->assertSame('a derelict station', $brief['setting']);
        $this->assertSame(['cobalt', 'silver', 'rust', 'teal'], $brief['palette']);
        $this->assertSame('a lone pilot floating before a cracked viewport, BOOK TITLE written on a hull', $brief['key_scene'], 'quotes are dropped');
        $this->assertSame('', $brief['subject']);
        $this->assertSame(CoverBrief::FIELDS, array_keys($brief));

        // An unopened think block, a long scene cut at a word.
        $long = CoverBrief::parse('…reasoning</think>{"key_scene": "' . str_repeat('a vast battlefield ', 30) . '"}');
        $this->assertLessThanOrEqual(CoverBrief::MAX_LENGTHS['key_scene'], mb_strlen($long['key_scene']));

        $this->assertNull(CoverBrief::parse('no json here'));
        $this->assertNull(CoverBrief::parse('{"genre": "fantasy"}'), 'no key scene: no brief');
        $this->assertNull(CoverBrief::parse('<think>{"key_scene": "only in the thoughts of the model"}</think>'));
    }

    public function test_the_llm_reads_the_details_and_the_opening_chapters(): void
    {
        config(['novarr.covers.ai.brief_words' => 200]);
        $this->fakeServices();
        $novel = $this->series(['origin' => 'translated', 'origin_language' => 'zh']);
        $novel->tags()->attach(Tag::create(['name' => 'Xianxia'])->id);
        foreach ([1, 2, 3] as $n) {
            $chapter = new NovelChapter(['novel_id' => $novel->id, 'chapter' => $n, 'label' => "Chapter {$n}", 'status' => 1]);
            $chapter->forceFill(['blacklist' => 0, 'url' => "https://example.test/c/{$n}"]);
            $chapter->description = '<p>' . str_repeat("word{$n} ", 150) . '</p>';
            $chapter->save();
        }

        $result = app(CoverBrief::class)->build($novel->fresh());

        $this->assertSame(CoverBrief::SOURCE_LLM, $result['source']);
        $this->assertSame(self::BRIEF['key_scene'], $result['brief']['key_scene']);
        Http::assertSent(function (HttpRequest $r) {
            if ($r->url() !== self::OLLAMA . '/api/generate') {
                return false;
            }
            $prompt = $r['prompt'];

            return $r['model'] === 'qwen3:8b' && $r['format'] === 'json' && $r['stream'] === false && $r['think'] === false
                && str_contains($prompt, 'Title: Sword of the Shattered Gate')
                && str_contains($prompt, 'Origin: translated from Chinese')
                && str_contains($prompt, 'Genres: Xianxia')
                && str_contains($prompt, 'A disciple cast out of his sect')
                && substr_count($prompt, 'word1') === 150 && substr_count($prompt, 'word2') === 50
                && !str_contains($prompt, 'word3');
        });
    }

    public function test_an_ollama_without_think_is_asked_again_without_it(): void
    {
        Http::fake([
            self::OLLAMA . '/api/generate' => Http::sequence()
                ->push(['error' => 'think value "false" is not supported for this model'], 400)
                ->push(['response' => json_encode(self::BRIEF)]),
        ]);

        $result = app(CoverBrief::class)->build($this->series());

        $this->assertSame(CoverBrief::SOURCE_LLM, $result['source']);
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertArrayNotHasKey('think', $requests[1][0]->data());
    }

    public function test_the_genres_brief_stands_in_when_the_llm_fails(): void
    {
        $this->fakeServices(500);
        $novel = $this->series(['origin' => 'translated', 'origin_language' => 'ko', 'description' => 'He awakens as an S-rank hunter in a world of gates.']);
        $novel->tags()->attach(Tag::create(['name' => 'Action'])->id);

        $result = app(CoverBrief::class)->build($novel->fresh());

        $this->assertSame(CoverBrief::SOURCE_FALLBACK, $result['source']);
        $this->assertStringContainsString('500', $result['error']);
        $this->assertSame('fantasy', $result['brief']['genre']);
        $this->assertSame('manhwa-style illustration', $result['brief']['style'], 'Korean: manhwa style');

        // No model at all: nothing asked.
        config(['novarr.covers.ai.ollama_url' => '']);
        $asked = count(Http::recorded());
        $none = app(CoverBrief::class)->build($this->series(['description' => 'Cultivation, sects and qi.']));
        $this->assertSame('cultivation fantasy', $none['brief']['genre']);
        $this->assertCount($asked, Http::recorded());
    }

    // --- The prompt and the painter ---------------------------------------------

    public function test_the_sdxl_prompt_is_built_from_the_brief(): void
    {
        $prompt = CoverPainter::prompt(self::BRIEF);

        $this->assertSame(
            'book cover illustration, donghua-style digital painting, a young swordsman kneeling in the snow before a shattered stone gate, a crimson moon above, '
            . 'a pale young man in torn white robes, a misty mountain sect, ancient era, ominous mood, jade green, gold and black palette, '
            . 'dramatic lighting, highly detailed, no text',
            $prompt,
        );
        // Empty parts are left out; a timeless era too.
        $this->assertSame(
            'book cover illustration, painterly digital art, a lighthouse in a storm, teal palette, dramatic lighting, highly detailed, no text',
            CoverPainter::prompt(['key_scene' => 'a lighthouse in a storm', 'era' => 'timeless', 'palette' => ['teal'], 'subject' => '']),
        );
        // Too long: trimmed, the style and scene kept.
        $long = CoverPainter::prompt(self::BRIEF + ['setting' => str_repeat('a very detailed place ', 40)]);
        $this->assertLessThanOrEqual(CoverPainter::MAX_PROMPT, mb_strlen($long));
        $this->assertStringContainsString(self::BRIEF['key_scene'], $long);
        $this->assertStringContainsString('no text', CoverPainter::NEGATIVE_PROMPT . $long);

        $this->assertSame(CoverPainter::seed(7), CoverPainter::seed(7), 'the same seed every time');
        $this->assertNotSame(CoverPainter::seed(7), CoverPainter::seed(7, 1), 'a repaint gets another');
        $this->assertNotSame(CoverPainter::seed(7), CoverPainter::seed(8));
        $this->assertLessThanOrEqual(0x7FFFFFFF, CoverPainter::seed(123456));
    }

    public function test_the_painter_is_asked_again_while_busy(): void
    {
        $this->fakeServices('brief', [Http::response('busy', 503), Http::response('busy', 503)]);

        $png = app(CoverPainter::class)->paint('a prompt', 42);

        $this->assertStringStartsWith("\x89PNG", $png);
        Sleep::assertSleptTimes(2);
        Http::assertSent(fn(HttpRequest $r) => $r->url() === self::PAINTER . '/generate'
            && $r['seed'] === 42 && $r['width'] === 832 && $r['height'] === 1216 && $r['steps'] === 30
            && $r['guidance'] === 6.5 && str_contains($r['negative_prompt'], 'watermark'));
    }

    public function test_a_painter_busy_for_too_long_is_given_up(): void
    {
        $this->fakeServices('brief', array_fill(0, 5, Http::response('busy', 503)));
        $this->expectException(PainterBusy::class);
        app(CoverPainter::class)->paint('a prompt', 42);
    }

    public function test_a_painting_that_is_not_a_usable_png_is_refused(): void
    {
        foreach (['<html>error</html>', BookCoversTest::photo(320, 468), BookCoversTest::photo(100, 100, 'png')] as $bad) {
            try {
                CoverPainter::validate($bad);
                $this->fail('accepted a bad painting');
            } catch (\RuntimeException $e) {
                $this->assertNotInstanceOf(PainterBusy::class, $e);
            }
        }
        $black = imagecreatetruecolor(400, 600);
        ob_start();
        imagepng($black);
        $this->expectExceptionMessage('blank');
        CoverPainter::validate((string) ob_get_clean());
    }

    // --- The composition -------------------------------------------------------

    public function test_the_cover_is_composed_at_two_by_three_with_the_title_set(): void
    {
        $composer = new CoverComposer();
        $art = BookCoversTest::photo(832, 1216, 'png');

        foreach (['book', 'novel', 'manga'] as $style) {
            $png = $composer->compose($art, 'The Iron Sky', 'Jane Doe', $style, 'png');
            $info = getimagesizefromstring($png);
            $this->assertSame([CoverComposer::WIDTH, CoverComposer::HEIGHT, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], $style);
        }
        $jpg = $composer->compose(self::painting(), str_repeat('A Very Long Title Indeed ', 10), str_repeat('Author ', 20), 'novel');
        $info = getimagesizefromstring($jpg);
        $this->assertSame([1000, 1500, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        $this->assertEqualsWithDelta(2 / 3, $info[0] / $info[1], 0.001);

        // The title is set: the top differs from the bare artwork's.
        $bare = imagecreatetruecolor(1000, 1500);
        imagefill($bare, 0, 0, imagecolorallocate($bare, 40, 80, 120));
        ob_start();
        imagepng($bare);
        $flat = (string) ob_get_clean();
        $withTitle = imagecreatefromstring($composer->compose($flat, 'Iron', null, 'manga', 'png'));
        $light = 0;
        for ($x = 300; $x < 700; $x += 4) {
            for ($y = 90; $y < 260; $y += 4) {
                $light += ((imagecolorat($withTitle, $x, $y) >> 8) & 0xFF) > 200 ? 1 : 0;
            }
        }
        $this->assertGreaterThan(50, $light, 'light title strokes at the top');
    }

    public function test_titles_are_fitted_to_at_most_four_lines(): void
    {
        $composer = new CoverComposer();
        $short = $composer->fitTitle('Dune', CoverComposer::STYLES['book']);
        $this->assertSame([['Dune'], CoverComposer::STYLES['book']['max']], [$short['lines'], $short['size']]);

        $long = $composer->fitTitle(CoverComposer::displayTitle(str_repeat('Reincarnated as the Strongest Sword Saint ', 4), true), CoverComposer::STYLES['novel']);
        $this->assertLessThanOrEqual(CoverComposer::LONG_LINES, count($long['lines']));
        $this->assertStringEndsWith('…', end($long['lines']));

        $this->assertSame('', CoverComposer::displayTitle('全职法师'), 'no faces for CJK: left off');
        $this->assertSame('SOLO LEVELING', CoverComposer::displayTitle('Solo  Leveling', true));
        $this->assertSame('', CoverComposer::displayAuthor('Unknown'));
        $this->assertSame(['book', 'manga', 'novel'], [CoverComposer::styleFor('book'), CoverComposer::styleFor('manga'), CoverComposer::styleFor('novel')]);
    }

    // --- Which series ----------------------------------------------------------

    public function test_blank_pages_with_a_little_text_are_placeholders(): void
    {
        $this->assertTrue(BookCovers::blankPage(self::blankPage()));
        $this->assertFalse(BookCovers::blankPage(BookCoversTest::photo(600, 900)));
        $this->assertFalse(BookCovers::blankPage(BookCoversTest::textCard()), 'a coloured card is not a blank page');
        $this->assertFalse(BookCovers::blankPage('nope'));

        $current = app(BookCovers::class)->current($this->series([], self::blankPage()));
        $this->assertTrue($current['placeholder']);
        $this->assertSame('a blank page with a little text', $current['reason']);
    }

    public function test_which_series_get_an_ai_cover(): void
    {
        $generator = app(CoverGenerator::class);
        $ok = fn(Novel $n, ...$args) => $generator->eligibility($n, ...$args)['ok'];

        $this->assertTrue($ok($this->series()), 'no cover');
        $this->assertTrue($ok($this->series([], self::blankPage())), 'a blank page');

        // A source's own art is real even when small or plain (2026-11-28: the first
        // nightly run painted over Berserk's and Webnovel's ~200×280 thumbnails).
        $this->assertFalse($ok($this->series([], BookCoversTest::photo(200, 280))), 'a small source thumbnail');
        $this->assertFalse($ok($this->series(['medium' => Novel::MEDIUM_MANGA], BookCoversTest::photo(200, 284))), 'a manga thumbnail');
        $this->assertFalse($ok($this->series([], BookCoversTest::textCard())), 'a plain cover from a source');
        $this->assertTrue($ok($this->series([], BookCoversTest::photo(60, 90))), 'an icon-sized image');
        $smallBook = $this->series(['medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1('small'), 'cover_checked_at' => now()], BookCoversTest::photo(193, 278));
        $this->assertFalse($ok($smallBook), 'a book\'s small real cover');
        $this->assertTrue($ok($this->series([], BookCoversTest::textCard()), false, true), 'the owner can still ask by id');
        // Text cards come from ePubs: a series converted from a book still gets painted.
        $converted = $this->series(['converted_from' => Novel::MEDIUM_BOOK], BookCoversTest::textCard());
        $this->assertTrue($ok($converted), 'a converted book\'s text card');

        $real = $this->series([], BookCoversTest::photo(400, 600));
        $this->assertSame(['ok' => false, 'reason' => CoverGenerator::REASON_REAL_COVER], $generator->eligibility($real));

        $manual = $this->series(['cover_source' => BookCovers::SOURCE_MANUAL], BookCoversTest::textCard());
        $this->assertFalse($ok($manual), 'never the owner\'s cover');
        $this->assertFalse($ok($manual, true, true), 'not even by id');
        $this->assertTrue($ok($manual, false, false, true), 'only when the owner asks on the page');

        $generated = $this->series(['cover_source' => BookCovers::SOURCE_GENERATED], BookCoversTest::photo(400, 600));
        $this->assertFalse($ok($generated));
        $this->assertTrue($ok($generated, true), '--regenerate');

        // A new web novel waits for its source's cover, unless named.
        $new = $this->series(['created_at' => now()]);
        $this->assertFalse($ok($new));
        $this->assertTrue($ok($new, false, true));
        // A series converted from a book has no source to wait for.
        $this->assertTrue($ok($this->series(['created_at' => now(), 'converted_from' => Novel::MEDIUM_BOOK], BookCoversTest::textCard())));

        // A book waits for the online search (books:covers) to find nothing.
        $book = $this->series(['medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1('x')], BookCoversTest::textCard());
        $this->assertSame('the online cover search has not looked yet', $generator->eligibility($book)['reason']);
        $book->forceFill(['cover_checked_at' => now()])->saveQuietly();
        $this->assertTrue($ok($book->fresh('file')));
        // …unless no catalogue is switched on.
        config(['novarr.books.sources' => []]);
        $this->assertTrue($ok($this->series(['medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1('y')])));
    }

    public function test_a_real_catalogue_cover_replaces_a_generated_one(): void
    {
        $book = $this->series(['medium' => Novel::MEDIUM_BOOK, 'name' => 'Dune', 'author' => 'Frank Herbert', 'translator_url' => 'book://library/' . sha1('dune')], BookCoversTest::photo(1000, 1500));
        $book->forceFill(['cover_source' => BookCovers::SOURCE_GENERATED, 'cover_generated_at' => now(), 'cover_checked_at' => now()->subDays(BookCovers::GENERATED_RECHECK_DAYS + 1)])->saveQuietly();
        $this->assertTrue(BookCovers::due([], false, 10)->contains('id', $book->id), 'an old AI cover is checked again');
        Http::fake(function (HttpRequest $r) {
            if (str_starts_with($r->url(), 'https://openlibrary.org/search.json')) {
                return Http::response(['docs' => [['key' => '/works/OL1W', 'title' => 'Dune', 'author_name' => ['Frank Herbert'], 'cover_i' => 42]]]);
            }
            if (str_starts_with($r->url(), 'https://covers.openlibrary.org/b/id/42-L.jpg')) {
                return Http::response(BookCoversTest::photo(500, 750), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('', 404);
        });

        $result = app(BookCovers::class)->check($book->fresh('file'));

        $this->assertSame(['replaced', 'an AI-painted cover'], [$result['action'], $result['reason']], 'smaller, but real');
        $fresh = $book->fresh();
        $this->assertSame(BookCovers::SOURCE_CATALOGUE, $fresh->cover_source);
        $this->assertNull($fresh->cover_generated_at);

        // A recent AI cover is not asked about every night.
        $recent = $this->series(['medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1('r'), 'cover_source' => BookCovers::SOURCE_GENERATED, 'cover_checked_at' => now()]);
        $this->assertFalse(BookCovers::due([], false, 10)->contains('id', $recent->id));
    }

    // --- Painting and saving ---------------------------------------------------

    public function test_a_painted_cover_is_saved_with_its_brief_and_not_sent_to_calibre(): void
    {
        config(['novarr.calibre_web.url' => 'http://calibre.test', 'novarr.calibre_web.username' => 'novarr']);
        $this->fakeServices();
        $novel = $this->series(['medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1('s'), 'cover_checked_at' => now()], BookCoversTest::textCard());
        $old = $novel->file;

        $result = app(CoverGenerator::class)->generate($novel);

        $fresh = $novel->fresh('file');
        $this->assertSame(BookCovers::SOURCE_GENERATED, $fresh->cover_source);
        $this->assertNotNull($fresh->cover_generated_at);
        $this->assertNotNull($fresh->cover_ai_checked_at);
        $this->assertSame(self::BRIEF['key_scene'], $fresh->cover_brief['key_scene']);
        $this->assertSame('llm', $fresh->cover_brief['brief_source']);
        $this->assertSame(CoverPainter::seed($novel->id), $fresh->cover_brief['seed']);
        $this->assertSame('book', $fresh->cover_brief['layout']);
        $this->assertSame($result['record']['prompt'], $fresh->cover_brief['prompt']);
        $this->assertNotSame($old->id, $fresh->file->id);
        $this->assertNull(File::find($old->id), 'the placeholder is replaced');
        $info = getimagesizefromstring(Storage::disk('public')->get(\App\Services\CoverThumbnails::relative($fresh->file->file_path)));
        $this->assertSame([1000, 1500], [$info[0], $info[1]]);
        Bus::assertNotDispatched(PushBookToCalibre::class);

        // Repainting uses the next seed.
        app(CoverGenerator::class)->generate($fresh);
        $this->assertSame(CoverPainter::seed($novel->id, 1), $novel->fresh()->cover_brief['seed']);
    }

    public function test_a_converted_book_keeps_its_own_cover_for_undo(): void
    {
        $this->fakeServices();
        $novel = $this->series([
            'converted_from' => Novel::MEDIUM_BOOK,
            'converted_reason' => 'evidence',
            'converted_snapshot' => ['fields' => ['medium' => Novel::MEDIUM_BOOK], 'tags' => []],
            'translator_url' => 'book://library/' . sha1('c'),
        ], BookCoversTest::textCard());
        $own = $novel->file;

        app(CoverGenerator::class)->generate($novel);
        app(CoverGenerator::class)->generate($novel->fresh('file'));

        $fresh = $novel->fresh('file');
        $this->assertNotNull(File::find($own->id), 'the book\'s own cover stays');
        $this->assertSame($fresh->file->id, $fresh->converted_snapshot['generated_cover']);
        $this->assertSame(2, File::where('file_type', Novel::class)->where('file_id', $novel->id)->count(), 'the first AI cover was removed');

        app(WebNovelCheck::class)->undo($fresh);

        $back = $novel->fresh('file');
        $this->assertSame(Novel::MEDIUM_BOOK, $back->medium);
        $this->assertSame($own->id, $back->file->id);
        $this->assertNull($back->cover_source);
    }

    // --- covers:generate -------------------------------------------------------

    public function test_the_command_paints_up_to_the_limit_and_marks_real_covers(): void
    {
        $this->fakeServices();
        $real = $this->series(['name' => 'Real'], BookCoversTest::photo(400, 600));
        $a = $this->series(['name' => 'A']);
        $b = $this->series(['name' => 'B', 'converted_from' => Novel::MEDIUM_BOOK], BookCoversTest::textCard());
        $c = $this->series(['name' => 'C']);
        $manual = $this->series(['name' => 'Mine', 'cover_source' => BookCovers::SOURCE_MANUAL]);

        $this->artisan('covers:generate', ['--limit' => 2])
            ->expectsOutputToContain('2 covers painted, 0 failed')
            ->assertSuccessful();

        $this->assertSame([BookCovers::SOURCE_GENERATED, BookCovers::SOURCE_GENERATED, null], [$a->fresh()->cover_source, $b->fresh()->cover_source, $c->fresh()->cover_source]);
        $this->assertNotNull($real->fresh()->cover_ai_checked_at, 'a real cover is not looked at again');
        $this->assertSame(BookCovers::SOURCE_MANUAL, $manual->fresh()->cover_source);
        $this->assertSame(2, collect(Http::recorded())->filter(fn($pair) => $pair[0]->url() === self::PAINTER . '/generate')->count());

        // A dry run paints nothing.
        $this->artisan('covers:generate', ['--dry-run' => true])->expectsOutputToContain('[dry run] #' . $c->id)->assertSuccessful();
        $this->assertNull($c->fresh()->cover_source);
    }

    public function test_the_command_backs_off_a_busy_gpu_and_stops_after_failures_in_a_row(): void
    {
        $this->fakeServices('brief', array_fill(0, 20, Http::response('busy', 503)));
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $this->series(['name' => $name]);
        }

        $this->artisan('covers:generate')
            ->expectsOutputToContain('the GPU stayed busy')
            ->expectsOutputToContain('Stopping: 2 failures in a row.')
            ->assertFailed();

        // max_failures 2 × (1 try + busy_retries 2) paintings asked, waits doubling.
        $this->assertSame(6, collect(Http::recorded())->filter(fn($pair) => $pair[0]->url() === self::PAINTER . '/generate')->count());
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(10)->seconds(), Sleep::for(5)->seconds(), Sleep::for(10)->seconds()]);
        $this->assertSame(0, Novel::where('cover_source', BookCovers::SOURCE_GENERATED)->count());
    }

    public function test_the_command_explains_when_it_is_off_or_the_painter_is_down(): void
    {
        config(['novarr.covers.ai.painter_url' => '']);
        $this->artisan('covers:generate')->expectsOutputToContain('AI covers are off')->assertSuccessful();

        config(['novarr.covers.ai.painter_url' => self::PAINTER]);
        Http::fake([self::PAINTER . '/health' => Http::response('', 500)]);
        $this->artisan('covers:generate')->expectsOutputToContain('does not answer')->assertFailed();
    }

    public function test_by_id_it_explains_a_skip(): void
    {
        $this->fakeServices();
        $real = $this->series([], BookCoversTest::photo(400, 600));

        $this->artisan('covers:generate', ['--id' => [$real->id]])->expectsOutputToContain('skipped (it has a real cover)')->assertSuccessful();
    }

    // --- The series page -------------------------------------------------------

    public function test_generate_a_cover_is_queued_and_its_progress_reported(): void
    {
        Queue::fake();
        $novel = $this->series([], BookCoversTest::photo(400, 600));

        $this->postJson(route('novels.covers.generate', $novel->id))
            ->assertStatus(202)
            ->assertJson(['state' => 'queued', 'status_url' => route('novels.covers.generate_status', $novel->id)]);
        Queue::assertPushedOn('commands', GenerateCover::class, fn($job) => $job->novelId === $novel->id);

        // A second click while it runs queues nothing more.
        $this->postJson(route('novels.covers.generate', $novel->id))->assertStatus(202);
        Queue::assertPushed(GenerateCover::class, 1);

        // The job paints (the owner asked: a real cover is replaced too).
        $this->fakeServices();
        (new GenerateCover($novel->id))->handle(app(CoverGenerator::class));
        $status = $this->getJson(route('novels.covers.generate_status', $novel->id))->assertOk()->json();
        $this->assertSame('done', $status['state']);
        $this->assertNotEmpty($status['cover_url']);
        $this->assertSame(BookCovers::SOURCE_GENERATED, $novel->fresh()->cover_source);

        // The page: "Regenerate cover" and the owner's AI note.
        $this->get(route('novels.show', $novel->id))->assertOk()->assertSee('Regenerate cover')->assertSee('AI cover', false);
    }

    public function test_a_busy_gpu_is_reported_to_the_page(): void
    {
        $this->fakeServices('brief', array_fill(0, 5, Http::response('busy', 503)));
        $novel = $this->series();

        (new GenerateCover($novel->id))->handle(app(CoverGenerator::class));

        $this->getJson(route('novels.covers.generate_status', $novel->id))->assertJson(['state' => 'failed', 'message' => 'The GPU is busy. Try again in a few minutes.']);
        $this->assertNull($novel->fresh()->cover_source);
    }

    public function test_without_a_painter_nothing_is_offered_or_queued(): void
    {
        Queue::fake();
        config(['novarr.covers.ai.painter_url' => '']);
        $novel = $this->series();

        $this->postJson(route('novels.covers.generate', $novel->id))->assertStatus(503);
        Queue::assertNothingPushed();
        $this->get(route('novels.show', $novel->id))->assertOk()->assertDontSee('Generate a cover');
    }

    public function test_the_routes_are_the_owners(): void
    {
        foreach (['novels.covers.generate', 'novels.covers.generate_status'] as $name) {
            $this->assertContains($name, RouteAccess::ADMIN_ROUTES);
            $this->assertSame(RouteAccess::ADMIN, RouteAccess::classify(app('router')->getRoutes()->getByName($name)), $name);
        }
    }
}
