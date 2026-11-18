<?php

namespace Tests\Feature;

use App\File;
use App\Novel;
use App\NovelChapter;
use App\Services\CoverThumbnails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers are served as thumbnails: the newest File is the cover, one write
 * path (Novel::attachCover) that also cleans up the previous cover, and every
 * list view / manifest / OPDS link pointing at covers/{sm,md}.
 */
class CoverThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name = 'Cover Novel', array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Some Author',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
        ], $attrs));
    }

    /** A real image on the fake public disk; returns its disk path. */
    private function image(string $name, int $w = 600, int $h = 900, string $format = 'jpg'): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 60, 200));
        ob_start();
        $format === 'png' ? imagepng($img) : imagejpeg($img, null, 90);
        Storage::disk('public')->put($name, ob_get_clean());

        return $name;
    }

    private function fileRow(Novel $novel, string $path): File
    {
        return File::forceCreate([
            'file_name' => basename($path), 'file_path' => $path,
            'file_type' => Novel::class, 'file_id' => $novel->id,
        ]);
    }

    // ---- relation ---------------------------------------------------------------

    public function test_file_relation_resolves_to_the_newest_row_lazily_and_eagerly(): void
    {
        $a = $this->novel('A');
        $b = $this->novel('B');
        // Interleaved so "newest for the novel" is never "last row in the table".
        $this->fileRow($a, 'public/a-old.jpg');
        $this->fileRow($b, 'public/b-old.jpg');
        $this->fileRow($a, 'public/a-new.jpg');
        $this->fileRow($b, 'public/b-new.jpg');
        $this->fileRow($this->novel('C'), 'public/c-only.jpg');

        $this->assertSame('public/a-new.jpg', Novel::find($a->id)->file->file_path);

        DB::enableQueryLog();
        $paths = Novel::with('file')->orderBy('name')->get()->mapWithKeys(fn($n) => [$n->name => $n->file->file_path])->all();
        $this->assertCount(2, DB::getQueryLog(), 'novels + one query for every cover');

        $this->assertSame(['A' => 'public/a-new.jpg', 'B' => 'public/b-new.jpg', 'C' => 'public/c-only.jpg'], $paths);
        $this->assertSame('public/b-new.jpg', Novel::withRelations()->find($b->id)->file->file_path);
    }

    // ---- attachCover --------------------------------------------------------------

    public function test_attach_cover_creates_the_row_and_both_thumbnails(): void
    {
        $novel = $this->novel();
        $this->image('first.jpg');

        $file = $novel->attachCover('first.jpg', 'my-cover.jpg');

        $this->assertSame(['my-cover.jpg', 'public/first.jpg', Novel::class, $novel->id], [$file->file_name, $file->file_path, $file->file_type, (int) $file->file_id]);
        $this->assertTrue($novel->relationLoaded('file'));
        $this->assertSame($file->id, $novel->file->id);
        Storage::disk('public')->assertExists(['covers/sm/first.jpg', 'covers/md/first.jpg']);

        // Without an original name the stored basename is used.
        $this->image('second.png', 300, 450, 'png');
        $this->assertSame('second.png', $novel->attachCover('public/second.png')->file_name);
    }

    public function test_attach_cover_removes_the_previous_cover_and_its_thumbnails(): void
    {
        $novel = $this->novel();
        $this->image('old.jpg');
        $old = $novel->attachCover('old.jpg');
        $this->image('new.jpg');

        $new = $novel->attachCover('new.jpg');

        $this->assertNull(File::find($old->id));
        Storage::disk('public')->assertMissing(['old.jpg', 'covers/sm/old.jpg', 'covers/md/old.jpg']);
        Storage::disk('public')->assertExists(['new.jpg', 'covers/sm/new.jpg', 'covers/md/new.jpg']);
        $this->assertSame([$new->id], File::where('file_id', $novel->id)->pluck('id')->all());
    }

    public function test_attach_cover_keeps_a_previous_image_another_row_still_uses(): void
    {
        $novel = $this->novel();
        $other = $this->novel('Other');
        $this->image('shared.jpg');
        $old = $novel->attachCover('shared.jpg');
        $this->fileRow($other, 'public/shared.jpg');
        $this->image('mine.jpg');

        $novel->attachCover('mine.jpg');

        $this->assertNotNull(File::find($old->id));
        Storage::disk('public')->assertExists(['shared.jpg', 'covers/sm/shared.jpg', 'covers/md/shared.jpg']);
        $this->assertSame('public/mine.jpg', Novel::find($novel->id)->file->file_path);
    }

    public function test_reattaching_the_same_stored_path_deletes_nothing(): void
    {
        $novel = $this->novel();
        $this->image('same.jpg');
        $first = $novel->attachCover('same.jpg');

        $novel->attachCover('public/same.jpg');

        $this->assertNotNull(File::find($first->id));
        Storage::disk('public')->assertExists(['same.jpg', 'covers/sm/same.jpg', 'covers/md/same.jpg']);
    }

    public function test_an_unreadable_upload_is_still_attached_without_thumbnails(): void
    {
        $novel = $this->novel();
        Storage::disk('public')->put('broken-' . uniqid() . '.jpg', 'not an image');
        $name = collect(Storage::disk('public')->files())->first(fn($f) => str_starts_with($f, 'broken-'));

        $novel->attachCover($name);

        $this->assertSame('public/' . $name, Novel::find($novel->id)->file->file_path);
        $this->assertSame(Storage::url('public/' . $name), $novel->coverUrl());
    }

    public function test_uploading_on_create_and_edit_goes_through_attach_cover(): void
    {
        $this->post(route('novels.store'), [
            'name' => 'Uploaded Novel',
            'image' => UploadedFile::fake()->image('front.png', 600, 900),
        ])->assertRedirect();

        $novel = Novel::with('file')->where('name', 'Uploaded Novel')->first();
        $first = $novel->file;
        $this->assertSame('front.png', $first->file_name);
        $thumbs = new CoverThumbnails();
        $this->assertTrue($thumbs->exists($first->file_path, 'sm'));
        $this->assertTrue($thumbs->exists($first->file_path, 'md'));

        $this->put(route('novels.update', $novel->id), [
            'name' => 'Uploaded Novel',
            'image' => UploadedFile::fake()->image('replacement.jpg', 600, 900),
        ])->assertRedirect();

        $current = Novel::find($novel->id)->file;
        $this->assertSame('replacement.jpg', $current->file_name);
        $this->assertNull(File::find($first->id));
        Storage::disk('public')->assertMissing(CoverThumbnails::relative($first->file_path));
        $this->assertFalse($thumbs->exists($first->file_path, 'sm'));
        $this->assertTrue($thumbs->exists($current->file_path, 'md'));
    }

    // ---- coverUrl -----------------------------------------------------------------

    public function test_cover_url_prefers_the_thumbnail_then_the_original_then_null(): void
    {
        $novel = $this->novel();
        $this->assertNull(Novel::with('file')->find($novel->id)->coverUrl());

        $this->fileRow($novel, 'public/plain.jpg');
        $loaded = Novel::with('file')->find($novel->id);
        $this->assertSame('/storage/plain.jpg', $loaded->coverUrl('md'));
        $this->assertNull($loaded->coverThumbnailUrl('md'));

        $this->image('plain.jpg');
        (new CoverThumbnails())->generate('public/plain.jpg');
        $this->assertSame('/storage/covers/md/plain.jpg', $loaded->coverUrl());
        $this->assertSame('/storage/covers/sm/plain.jpg', $loaded->coverUrl('sm'));
        $this->assertSame('/storage/plain.jpg', $loaded->originalCoverUrl());
    }

    public function test_cover_url_never_lazy_loads_the_relation(): void
    {
        $novel = $this->novel();
        $this->fileRow($novel, 'public/lazy.jpg');
        $fresh = Novel::find($novel->id);

        DB::enableQueryLog();
        $this->assertNull($fresh->coverUrl());
        $this->assertSame([], DB::getQueryLog());
        $this->assertFalse($fresh->relationLoaded('file'));
    }

    // ---- views --------------------------------------------------------------------

    /** A novel with a cover and both thumbnails. */
    private function covered(string $name, string $basename): Novel
    {
        $novel = $this->novel($name);
        $this->image("{$basename}.jpg");
        $novel->attachCover("{$basename}.jpg");

        return $novel;
    }

    public function test_cover_component_uses_thumbnails_and_the_eager_prop(): void
    {
        $novel = Novel::with('file')->find($this->covered('Comp', 'comp')->id);

        $md = Blade::render('<x-cover :novel="$n" size="lg" />', ['n' => $novel]);
        $this->assertMatchesRegularExpression('#<img src="/storage/covers/md/comp\.jpg" alt="Cover of Comp" width="240" height="360"\s+loading="lazy" decoding="async" onerror=#', $md);
        $this->assertStringNotContainsString('srcset', $md);
        $this->assertStringNotContainsString('fetchpriority', $md);

        $sm = Blade::render('<x-cover :novel="$n" size="sm" eager />', ['n' => $novel]);
        $this->assertStringContainsString('<img src="/storage/covers/sm/comp.jpg" srcset="/storage/covers/sm/comp.jpg 1x, /storage/covers/md/comp.jpg 2x"', $sm);
        $this->assertMatchesRegularExpression('#width="72" height="108"\s+loading="eager" fetchpriority="high" decoding="async"#', $sm);
        $this->assertStringNotContainsString('loading="lazy"', $sm);
    }

    public function test_cover_component_falls_back_to_the_original_without_thumbnails(): void
    {
        $novel = $this->novel('Orig');
        $this->fileRow($novel, 'public/orig.jpg');

        $html = Blade::render('<x-cover :novel="$n" size="sm" />', ['n' => Novel::with('file')->find($novel->id)]);

        $this->assertStringContainsString('<img src="/storage/orig.jpg" alt=', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function test_library_grid_uses_md_thumbnails_with_the_first_six_eager(): void
    {
        foreach (range(1, 7) as $i) {
            $this->covered("Novel {$i}", "grid{$i}");
        }

        $html = $this->get(route('novels.index', ['view' => 'grid', 'sort' => 'name']))->assertOk()->getContent();

        preg_match_all('#<img src="([^"]+)"[^>]*?(loading="\w+")#s', $html, $m);
        $this->assertSame(array_map(fn($i) => "/storage/covers/md/grid{$i}.jpg", range(1, 7)), $m[1]);
        $this->assertSame(array_merge(array_fill(0, 6, 'loading="eager"'), ['loading="lazy"']), $m[2]);
        $this->assertSame(6, substr_count($html, 'fetchpriority="high"'));
        $this->assertStringNotContainsString('src="/storage/grid', $html);
    }

    public function test_library_table_uses_sm_thumbnails(): void
    {
        $this->covered('Tabled', 'tabled');

        $html = $this->get(route('novels.index', ['view' => 'table']))->assertOk()->getContent();

        // The narrow list and the wide table both carry the 36×50 thumb.
        $this->assertSame(2, substr_count($html, '<img src="/storage/covers/sm/tabled.jpg" alt="Cover of Tabled" loading="lazy" decoding="async" class="cover-thumb">'));
        $this->assertStringNotContainsString('covers/md/', $html);
    }

    public function test_home_hero_is_eager_md_and_the_continue_row_uses_sm(): void
    {
        foreach (['Hero' => 'hero', 'Shelf' => 'shelf'] as $name => $base) {
            $novel = $this->covered($name, $base);
            foreach ([1, 2] as $n) {
                NovelChapter::create([
                    'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0, 'label' => "Chapter {$n}",
                    'url' => "https://www.novelfull.test/{$base}/chapter-{$n}",
                ])->forceFill(['status' => 1, 'download_date' => now(), 'description' => '<p>words</p>'])->save();
            }
            $this->markRead(NovelChapter::where('novel_id', $novel->id)->where('chapter', 1)->first(), null, $name === 'Hero' ? now() : now()->subDay(), 100);
        }

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('<img src="/storage/covers/md/hero.jpg" alt="" loading="eager" fetchpriority="high" decoding="async">', $html);
        $this->assertStringContainsString('<img src="/storage/covers/sm/shelf.jpg" srcset="/storage/covers/sm/shelf.jpg 1x, /storage/covers/md/shelf.jpg 2x" alt="" loading="eager" decoding="async">', $html);
        $this->assertStringNotContainsString('src="/storage/hero.jpg"', $html);
    }

    public function test_novel_page_shows_the_md_thumbnail(): void
    {
        $novel = $this->covered('Detail', 'detail');

        $this->get(route('novels.show', $novel->id))
            ->assertOk()
            // The hero now uses <x-cover> (lg slot → md thumbnail), loaded eagerly.
            ->assertSee('<img src="/storage/covers/md/detail.jpg" alt="Cover of Detail" width="240" height="360"', false)
            ->assertSee('loading="eager" fetchpriority="high"', false);
    }

    public function test_offline_manifest_caches_the_md_thumbnail(): void
    {
        $novel = $this->covered('Offline', 'offline');

        $this->getJson(route('novels.offline_manifest', $novel->id))
            ->assertOk()
            ->assertJsonPath('cover', '/storage/covers/md/offline.jpg');
    }

    public function test_opds_links_the_original_with_its_type_and_the_sm_thumbnail(): void
    {
        $withThumb = $this->novel('Alpha', []);
        $withThumb->forceFill(['epub_generated' => now()])->save();
        $this->image('alpha.png', 600, 900, 'png');
        $withThumb->attachCover('alpha.png');

        $plain = $this->novel('Beta');
        $plain->forceFill(['epub_generated' => now()])->save();
        $this->fileRow($plain, 'public/beta.webp');

        $xml = simplexml_load_string($this->get(route('opds.index'))->assertOk()->getContent());
        $xml->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        $links = fn(string $title) => collect($xml->xpath("//a:entry[a:title='{$title}']/a:link"))
            ->mapWithKeys(fn($l) => [(string) $l['rel'] => [(string) $l['href'], (string) $l['type']]])
            ->all();

        $alpha = $links('Alpha');
        $this->assertSame([url('/storage/alpha.png'), 'image/png'], $alpha['http://opds-spec.org/image']);
        $this->assertSame([url('/storage/covers/sm/alpha.jpg'), 'image/jpeg'], $alpha['http://opds-spec.org/image/thumbnail']);

        $beta = $links('Beta');
        $this->assertSame([url('/storage/beta.webp'), 'image/webp'], $beta['http://opds-spec.org/image']);
        $this->assertSame([url('/storage/beta.webp'), 'image/webp'], $beta['http://opds-spec.org/image/thumbnail']);
    }

    // ---- covers:thumbs ------------------------------------------------------------

    public function test_backfill_command_reports_and_is_safe_to_re_run(): void
    {
        $good = $this->novel('Good Cover');
        $this->image('good.jpg');
        $this->fileRow($good, 'public/good.jpg');

        $bad = $this->novel('Broken Cover');
        $broken = 'broken-' . uniqid() . '.jpg';
        Storage::disk('public')->put($broken, 'garbage');
        $this->fileRow($bad, 'public/' . $broken);

        $this->novel('No Cover');

        $this->artisan('covers:thumbs')
            ->expectsOutput('Cover thumbnails: 1 generated, 0 skipped, 1 failed.')
            ->expectsOutput('  Failed: Broken Cover (the original cover is still served)')
            ->assertSuccessful();
        Storage::disk('public')->assertExists(['covers/sm/good.jpg', 'covers/md/good.jpg']);

        $this->artisan('covers:thumbs')
            ->expectsOutput('Cover thumbnails: 0 generated, 1 skipped, 1 failed.')
            ->assertSuccessful();

        Storage::disk('public')->put('covers/sm/good.jpg', 'stale');
        $this->artisan('covers:thumbs', ['--force' => true])
            ->expectsOutput('Cover thumbnails: 1 generated, 0 skipped, 1 failed.')
            ->assertSuccessful();
        $this->assertNotSame('stale', Storage::disk('public')->get('covers/sm/good.jpg'));
    }

    // ---- Discover -----------------------------------------------------------------

    public function test_discover_cards_load_the_source_thumbnail_first(): void
    {
        $src = file_get_contents(resource_path('views/novels/discover.blade.php'));

        $this->assertStringContainsString('const first = item.cover_thumb || item.cover;', $src);
        $this->assertStringContainsString('img.src = first;', $src);
        $this->assertStringContainsString('img.src = item.cover;', $src);
        $this->assertStringNotContainsString('img.src = item.cover_thumb;', $src);
    }
}
