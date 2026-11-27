<?php

namespace Tests\Feature;

use App\ChapterPage;
use App\Http\Controllers\Api\V1\MangaCbzController;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * GET /api/v1/chapters/{id}/cbz (Api\V1\MangaCbzController): a manga
 * chapter's pages as a stored (uncompressed) CBZ for the KOReader plugin,
 * optionally scaled down and greyed for e-ink, cached on disk.
 */
class MangaCbzApiTest extends TestCase
{
    use RefreshDatabase;

    private Novel $manga;
    private NovelChapter $chapter;
    private NovelChapter $pending;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manga');
        $this->clearCache();

        $this->manga = Novel::create(['name' => 'A Manga', 'group_id' => 1, 'translator_url' => 'https://mangadex.org/title/9bb2d279-a8d1-4c01-9ee5-b2b0674f0ae2']);
        $this->manga->forceFill(['medium' => 'manga'])->save();
        $this->chapter = NovelChapter::create(['novel_id' => $this->manga->id, 'chapter' => 7, 'label' => 'Chapter 7', 'url' => 'https://mangadex.org/chapter/00000000-0000-4000-8000-000000000007']);
        $this->chapter->forceFill(['status' => 1, 'page_count' => 3, 'download_date' => now()])->save();
        $this->pending = NovelChapter::create(['novel_id' => $this->manga->id, 'chapter' => 8, 'label' => 'Chapter 8', 'url' => 'https://mangadex.org/chapter/00000000-0000-4000-8000-000000000008']);
        // Out of order on purpose: the archive follows the page numbers.
        $this->page(2, 'png');
        $this->page(1, 'jpg');
        $this->page(3, 'webp');
    }

    protected function tearDown(): void
    {
        $this->clearCache();
        parent::tearDown();
    }

    private function clearCache(): void
    {
        foreach (glob(storage_path('app/cbz-cache') . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function page(int $n, string $ext): void
    {
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
        $path = "{$this->manga->id}/{$this->chapter->id}/" . ChapterPage::fileName($n, $ext);
        Storage::disk('manga')->put($path, file_get_contents(base_path("tests/fixtures/mangadex/page.{$ext}")));
        ChapterPage::create(['novel_chapter_id' => $this->chapter->id, 'page' => $n, 'path' => $path, 'mime' => $mime, 'bytes' => Storage::disk('manga')->size($path)]);
    }

    /** @return array{0: ZipArchive, 1: string} the opened archive and its temp path */
    private function zipOf($response): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbz');
        file_put_contents($tmp, $response->baseResponse->getFile()->getContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'a valid zip');

        return [$zip, $tmp];
    }

    public function test_meta_advertises_the_cbz_endpoint(): void
    {
        $this->getJson('/api/v1/meta')->assertOk()->assertJsonPath('manga_cbz', true);
    }

    public function test_the_pages_in_order_stored_with_comic_info(): void
    {
        $response = $this->get("/api/v1/chapters/{$this->chapter->id}/cbz")->assertOk();
        $this->assertSame('application/vnd.comicbook+zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('A Manga - Chapter 7.cbz', (string) $response->headers->get('Content-Disposition'));

        [$zip, $tmp] = $this->zipOf($response);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $names[] = $stat['name'];
            $this->assertSame(ZipArchive::CM_STORE, $stat['comp_method'], $stat['name'] . ' is stored');
        }
        // No options: JPEG and PNG unchanged; WebP re-encoded as JPEG when GD reads it.
        $third = function_exists('imagecreatefromwebp') ? '003.jpg' : '003.webp';
        $this->assertSame(['001.jpg', '002.png', $third, 'ComicInfo.xml'], $names);
        $this->assertSame(file_get_contents(base_path('tests/fixtures/mangadex/page.jpg')), $zip->getFromName('001.jpg'));
        $this->assertSame(file_get_contents(base_path('tests/fixtures/mangadex/page.png')), $zip->getFromName('002.png'));
        $info = $zip->getFromName('ComicInfo.xml');
        $this->assertStringContainsString('<Series>A Manga</Series>', $info);
        $this->assertStringContainsString('<Number>7</Number>', $info);
        $this->assertStringContainsString('<Title>Chapter 7</Title>', $info);
        $this->assertStringContainsString('<PageCount>3</PageCount>', $info);
        $this->assertStringContainsString('<Manga>Yes</Manga>', $info);
        $zip->close();
        @unlink($tmp);
    }

    public function test_max_width_and_gray_reencode_as_jpeg(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not installed');
        }
        $response = $this->get("/api/v1/chapters/{$this->chapter->id}/cbz?max_width=200&gray=1")->assertOk();
        [$zip, $tmp] = $this->zipOf($response);
        $this->assertNotFalse($zip->locateName('001.jpg'));
        $this->assertNotFalse($zip->locateName('002.jpg'), 'the PNG became a JPEG');
        $size = getimagesizefromstring($zip->getFromName('002.jpg'));
        $this->assertLessThanOrEqual(200, $size[0]);
        $zip->close();
        @unlink($tmp);
    }

    public function test_built_archives_are_cached_and_rebuilt_when_pages_change(): void
    {
        $this->get("/api/v1/chapters/{$this->chapter->id}/cbz")->assertOk();
        $files = glob(storage_path('app/cbz-cache') . "/{$this->chapter->id}-*.cbz");
        $this->assertCount(1, $files);
        $this->get("/api/v1/chapters/{$this->chapter->id}/cbz")->assertOk();
        $this->assertCount(1, glob(storage_path('app/cbz-cache') . "/{$this->chapter->id}-*.cbz"), 'served from the cache');

        ChapterPage::where('novel_chapter_id', $this->chapter->id)->where('page', 3)->delete();
        $response = $this->get("/api/v1/chapters/{$this->chapter->id}/cbz")->assertOk();
        $this->assertCount(2, glob(storage_path('app/cbz-cache') . "/{$this->chapter->id}-*.cbz"), 'a new key for new pages');
        [$zip, $tmp] = $this->zipOf($response);
        $this->assertSame(3, $zip->numFiles, 'two pages and ComicInfo.xml');
        $zip->close();
        @unlink($tmp);
    }

    public function test_refusals(): void
    {
        // Nothing downloaded yet.
        $this->getJson("/api/v1/chapters/{$this->pending->id}/cbz")->assertStatus(422)->assertJsonPath('code', 'no_pages');
        // Not a manga, unknown.
        $text = Novel::create(['name' => 'A Text Novel', 'group_id' => 1, 'translator_url' => 'https://novelping.com/novel/a-text-novel']);
        $tc = NovelChapter::create(['novel_id' => $text->id, 'chapter' => 1, 'label' => 'Chapter 1', 'url' => 'https://novelping.com/chapter/a/1']);
        $this->getJson("/api/v1/chapters/{$tc->id}/cbz")->assertNotFound();
        $this->getJson('/api/v1/chapters/999999/cbz')->assertNotFound();
        // Options out of range.
        $this->getJson("/api/v1/chapters/{$this->chapter->id}/cbz?max_width=50")->assertStatus(422);
        // Every page file gone: no empty archive.
        Storage::disk('manga')->deleteDirectory((string) $this->manga->id);
        $this->getJson("/api/v1/chapters/{$this->chapter->id}/cbz")->assertStatus(422)->assertJsonPath('code', 'no_pages');
    }

    public function test_file_name_is_ascii_safe(): void
    {
        $this->manga->forceFill(['name' => 'Ränge / Ō: "x"'])->save();
        $this->assertSame('Range  O x - Chapter 7.cbz', MangaCbzController::fileName($this->chapter->fresh('novel')));
    }
}
