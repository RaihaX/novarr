<?php

namespace Tests\Feature;

use App\Books\BookCovers;
use App\Jobs\PushBookToCalibre;
use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Better covers for the Books library (App\Books\BookCovers, `books:covers`,
 * "Find a better cover"): placeholder detection (missing, tiny, generated
 * text cards), candidates only of the same book, the largest portrait one,
 * the replace / upgrade rules, the owner's covers left alone, Calibre told.
 * Images are made with GD; every catalogue is faked.
 */
class BookCoversTest extends TestCase
{
    use RefreshDatabase;

    private const OL_COVER = 'https://covers.openlibrary.org/b/id/42-L.jpg';

    private const GOOGLE_COVER = 'https://books.google.com/books/content?id=g1&printsec=frontcover&img=1&zoom=1';

    /** url => image bytes the fake answers. */
    private array $images = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::put('dashboard_attention', [], 900);
        Bus::fake([PushBookToCalibre::class]);
        config(['novarr.books.sources' => ['google', 'openlibrary']]);
    }

    // --- Images ----------------------------------------------------------------

    /** A photo-like image: noise over a gradient (hundreds of colours). */
    public static function photo(int $w, int $h, string $type = 'jpeg'): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand($w * 7 + $h);
        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                $c = imagecolorallocate($im, ($x * 255 / $w + mt_rand(0, 80)) % 256, ($y * 255 / $h + mt_rand(0, 80)) % 256, mt_rand(0, 255));
                imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $c);
            }
        }
        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    /** A generated title card: a flat background, a band and some text (Calibre / FanFicFare style). */
    public static function textCard(int $w = 600, int $h = 900): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 120));
        imagefilledrectangle($im, 0, (int) ($h * 0.3), $w, (int) ($h * 0.45), imagecolorallocate($im, 240, 240, 240));
        $ink = imagecolorallocate($im, 10, 10, 10);
        for ($i = 0; $i < 4; $i++) {
            imagestring($im, 5, 40, (int) ($h * 0.32) + $i * 20, 'A Very Long Title Of A Fanfic ' . $i, $ink);
        }
        imagestring($im, 5, 40, (int) ($h * 0.8), 'by Some Author', imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    // --- Fixtures --------------------------------------------------------------

    private function book(string $name = 'Dune', ?string $author = 'Frank Herbert', ?string $cover = null, ?string $coverSource = null): Novel
    {
        $book = Novel::create([
            'name' => $name, 'author' => $author, 'status' => 1, 'group_id' => 1,
            'medium' => Novel::MEDIUM_BOOK, 'translator_url' => 'book://library/' . sha1($name . $author . microtime()),
        ]);
        if ($cover !== null) {
            Storage::disk('public')->put("old-{$book->id}.jpg", $cover);
            $book->attachCover("old-{$book->id}.jpg");
        }
        if ($coverSource !== null) {
            $book->forceFill(['cover_source' => $coverSource])->save();
        }

        return $book->fresh('file');
    }

    /** Google and Open Library know "Dune" by Frank Herbert (and a "Dune" by someone else); covers from $this->images. */
    private function fakeCatalogues(array $images): void
    {
        $this->images = $images;
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            if (str_starts_with($url, 'https://www.googleapis.com/')) {
                return Http::response(['items' => [
                    ['id' => 'g1', 'volumeInfo' => ['title' => 'Dune', 'authors' => ['Frank Herbert'], 'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id=g1&printsec=frontcover&img=1&zoom=1&edge=curl']]],
                    ['id' => 'g2', 'volumeInfo' => ['title' => 'Dune', 'authors' => ['Somebody Else'], 'imageLinks' => ['thumbnail' => 'https://books.google.com/books/content?id=g2&zoom=1']]],
                ]]);
            }
            if (str_starts_with($url, 'https://openlibrary.org/search.json')) {
                return Http::response(['docs' => [['key' => '/works/OL1W', 'title' => 'Dune', 'author_name' => ['Frank Herbert'], 'cover_i' => 42]]]);
            }
            foreach ($this->images as $prefix => $bytes) {
                if (str_starts_with($url, $prefix)) {
                    return $bytes === null ? Http::response('', 404) : Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']);
                }
            }

            return Http::response('', 404);
        });
    }

    private function coverSize(Novel $book): array
    {
        $file = $book->fresh()->file()->first();
        $info = getimagesizefromstring(Storage::disk('public')->get(\App\Services\CoverThumbnails::relative($file->file_path)));

        return [$info[0], $info[1]];
    }

    // --- Placeholders ----------------------------------------------------------

    public function test_generated_text_cards_are_told_from_real_covers(): void
    {
        $this->assertTrue(BookCovers::textCard(self::textCard()));
        $this->assertTrue(BookCovers::textCard(self::textCard(400, 640)));
        $this->assertFalse(BookCovers::textCard(self::photo(600, 900)));
        $this->assertFalse(BookCovers::textCard(self::photo(300, 450, 'png')));
        $this->assertFalse(BookCovers::textCard('not an image'));

        $flat = BookCovers::colourStats(self::textCard());
        $photo = BookCovers::colourStats(self::photo(600, 900));
        $this->assertLessThanOrEqual(BookCovers::CARD_MAX_COLOURS, $flat['colours']);
        $this->assertGreaterThanOrEqual(BookCovers::FLAT_BACKGROUND_SHARE, $flat['background_share']);
        $this->assertGreaterThan(BookCovers::CARD_MAX_COLOURS, $photo['colours']);
    }

    public function test_the_current_cover_is_a_placeholder_when_missing_tiny_or_a_card(): void
    {
        $covers = app(BookCovers::class);
        $this->assertSame([false, true, 'no cover'], array_values(array_intersect_key($covers->current($this->book('A', 'X')), array_flip(['exists', 'placeholder', 'reason']))));
        $this->assertTrue($covers->current($this->book('B', 'X', self::photo(120, 180)))['placeholder']);
        $this->assertSame('a generated text cover', $covers->current($this->book('C', 'X', self::textCard()))['reason']);
        $real = $covers->current($this->book('D', 'X', self::photo(400, 600)));
        $this->assertSame([true, 400, 600, false], [$real['exists'], $real['width'], $real['height'], $real['placeholder']]);
    }

    // --- books:covers ----------------------------------------------------------

    public function test_a_book_without_a_cover_gets_the_largest_portrait_cover_of_the_same_book(): void
    {
        $this->fakeCatalogues([
            self::OL_COVER => self::photo(500, 750),
            // Google's zoom=0 is larger but landscape: refused; zoom=1 too small.
            'https://books.google.com/books/content?id=g1&printsec=frontcover&img=1&zoom=0' => self::photo(1200, 800),
            self::GOOGLE_COVER => self::photo(128, 192),
            // Another author's "Dune": never downloaded.
            'https://books.google.com/books/content?id=g2' => self::photo(2000, 3000),
        ]);
        $book = $this->book();

        $this->artisan('books:covers')->expectsOutputToContain('1 new covers')->assertSuccessful();

        $this->assertSame([500, 750], $this->coverSize($book));
        $fresh = $book->fresh();
        $this->assertSame(BookCovers::SOURCE_CATALOGUE, $fresh->cover_source);
        $this->assertNotNull($fresh->cover_checked_at);
        Http::assertNotSent(fn(HttpRequest $r) => str_contains($r->url(), 'id=g2'));
        Bus::assertNotDispatched(PushBookToCalibre::class, "Calibre is not set up");

        // Checked once: the next run asks nothing.
        $sent = count(Http::recorded());
        $this->artisan('books:covers')->expectsOutputToContain('0 books')->assertSuccessful();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_text_card_cover_is_replaced_and_a_calibre_book_is_pushed(): void
    {
        config(['novarr.calibre_web.url' => 'http://calibre.test', 'novarr.calibre_web.username' => 'novarr']);
        $this->fakeCatalogues([self::OL_COVER => self::photo(500, 750)]);
        $book = $this->book('Dune', 'Frank Herbert', self::textCard());

        $result = app(BookCovers::class)->check($book);

        $this->assertSame(['replaced', 'a generated text cover'], [$result['action'], $result['reason']]);
        $this->assertSame([500, 750], $this->coverSize($book));
        Bus::assertDispatched(PushBookToCalibre::class, fn($job) => $job->novelId === $book->id);
    }

    public function test_a_real_cover_is_upgraded_only_by_a_much_larger_one(): void
    {
        // Unknown origin: needs 2× the area.
        $this->fakeCatalogues([self::OL_COVER => self::photo(400, 600)]);
        $book = $this->book('Dune', 'Frank Herbert', self::photo(320, 480));
        $this->assertSame('kept', app(BookCovers::class)->check($book)['action'], '1.56× is not enough for a cover of unknown origin');

        // A catalogue's cover: 1.5× is.
        $book->forceFill(['cover_source' => BookCovers::SOURCE_CATALOGUE])->save();
        $this->assertSame('replaced', app(BookCovers::class)->check($book->fresh('file'))['action']);
        $this->assertSame([400, 600], $this->coverSize($book));

        // 2× and more replaces one of unknown origin.
        Cache::flush();
        $this->fakeCatalogues([self::OL_COVER => self::photo(800, 1200)]);
        $other = $this->book('Dune', 'Frank Herbert', self::photo(320, 480));
        $this->assertSame('replaced', app(BookCovers::class)->check($other)['action']);
    }

    public function test_the_owners_cover_is_never_replaced_and_modes_and_dry_runs_change_nothing(): void
    {
        $this->fakeCatalogues([self::OL_COVER => self::photo(800, 1200)]);
        $manual = $this->book('Dune', 'Frank Herbert', self::photo(120, 180), BookCovers::SOURCE_MANUAL);
        $this->assertSame(['skipped', 'the owner chose this cover'], array_slice(array_values(app(BookCovers::class)->check($manual)), 0, 2));

        $real = $this->book('Dune', 'Frank Herbert', self::photo(320, 480));
        $this->assertSame('skipped', app(BookCovers::class)->check($real, 'missing')['action']);
        $none = $this->book('Dune', 'Frank Herbert');
        $this->assertSame('skipped', app(BookCovers::class)->check($none, 'upgrade')['action']);

        $this->artisan('books:covers --dry-run')->expectsOutputToContain('[dry run]')->assertSuccessful();
        $this->assertNull($none->fresh()->file()->first());
        $this->assertNull($none->fresh()->cover_checked_at);

        // --missing leaves the real cover due for a full run.
        $this->artisan('books:covers --missing')->assertSuccessful();
        $this->assertNotNull($none->fresh()->file()->first());
        $this->assertNull($real->fresh()->cover_checked_at);
        $this->artisan('books:covers --id=' . $real->id)->assertSuccessful();
        $this->assertSame([800, 1200], $this->coverSize($real));
        $this->assertSame([120, 180], $this->coverSize($manual));
    }

    public function test_without_an_author_only_the_same_isbn_is_trusted(): void
    {
        $this->fakeCatalogues([self::OL_COVER => self::photo(500, 750)]);
        $book = $this->book('Dune', null);
        $this->assertSame(['kept', 'no catalogue cover of this book'], array_slice(array_values(app(BookCovers::class)->check($book)), 0, 2));
    }

    // --- "Find a better cover" -------------------------------------------------

    public function test_the_owner_picks_a_cover_from_the_book_page(): void
    {
        $this->fakeCatalogues([self::OL_COVER => self::photo(500, 750), self::GOOGLE_COVER => self::photo(300, 450)]);
        $book = $this->book('Dune', 'Frank Herbert', self::photo(320, 480));

        $this->get(route('novels.show', $book->id))->assertOk()->assertSee(route('novels.covers', $book->id), false);
        $page = $this->get(route('novels.covers', $book->id))->assertOk();
        $page->assertSee('Open Library · 500×750')->assertSee('Google Books · 300×450')->assertSee('320×480');

        $this->post(route('novels.covers.choose', $book->id), ['choice' => 1])->assertRedirect(route('novels.show', $book->id));
        $this->assertSame([300, 450], $this->coverSize($book));
        $this->assertSame(BookCovers::SOURCE_MANUAL, $book->fresh()->cover_source, 'the owner\'s choice: never replaced by books:covers');

        $this->post(route('novels.covers.choose', $book->id), ['choice' => 9])->assertRedirect(route('novels.covers', $book->id))->assertSessionHas('error');
        $web = Novel::create(['name' => 'A Web Novel', 'status' => 0, 'group_id' => 1]);
        $this->get(route('novels.covers', $web->id))->assertNotFound();
    }

    public function test_an_uploaded_cover_on_the_edit_page_is_the_owners(): void
    {
        $book = $this->book('Dune', 'Frank Herbert');
        $this->put(route('novels.update', $book->id), [
            'name' => 'Dune',
            'image' => \Illuminate\Http\UploadedFile::fake()->image('mine.jpg', 400, 600),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(BookCovers::SOURCE_MANUAL, $book->fresh()->cover_source);
    }
}
