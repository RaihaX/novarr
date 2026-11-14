<?php

namespace Tests\Unit;

use App\Services\CoverThumbnails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CoverThumbnails: 2:3 centre crop, two sizes, no upscaling, white behind
 * transparency, baseline JPEG, fail-soft and idempotent.
 */
class CoverThumbnailsTest extends TestCase
{
    private CoverThumbnails $thumbs;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->thumbs = new CoverThumbnails();
    }

    /**
     * Write a GD image to the fake public disk. $paint gets the canvas and
     * draws on it; returns the "public/…" file_path a File row would hold.
     */
    private function source(string $name, int $w, int $h, callable $paint, string $format = 'jpg'): string
    {
        $img = imagecreatetruecolor($w, $h);
        if ($format === 'png') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $paint($img);

        ob_start();
        match ($format) {
            'png' => imagepng($img),
            'gif' => imagegif($img),
            'webp' => imagewebp($img, null, 90),
            default => imagejpeg($img, null, 95),
        };
        Storage::disk('public')->put($name, ob_get_clean());

        return 'public/' . $name;
    }

    private function fill(int $r, int $g, int $b): callable
    {
        return fn($img) => imagefilledrectangle($img, 0, 0, imagesx($img), imagesy($img), imagecolorallocate($img, $r, $g, $b));
    }

    private function thumb(string $stored, string $size): \GdImage
    {
        $file = Storage::disk('public')->path($this->thumbs->path($stored, $size));
        $this->assertFileExists($file);

        return imagecreatefromjpeg($file);
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(\GdImage $img, int $x, int $y): array
    {
        $c = imagecolorat($img, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    private function assertColour(array $expected, array $actual, int $tolerance = 24): void
    {
        foreach ($expected as $i => $channel) {
            $this->assertEqualsWithDelta($channel, $actual[$i], $tolerance, 'rgb(' . implode(',', $actual) . ')');
        }
    }

    public function test_paths_use_the_stored_basename_under_covers_size(): void
    {
        $this->assertSame('covers/sm/abc123.jpg', $this->thumbs->path('public/abc123.png', 'sm'));
        $this->assertSame('covers/md/abc123.jpg', $this->thumbs->path('abc123.webp', 'md'));
        $this->assertSame('abc.jpg', CoverThumbnails::relative('public/abc.jpg'));
        $this->assertSame('abc.jpg', CoverThumbnails::relative('abc.jpg'));

        $this->expectException(\InvalidArgumentException::class);
        $this->thumbs->path('abc.jpg', 'xl');
    }

    public function test_landscape_source_is_centre_cropped_to_portrait_at_both_sizes(): void
    {
        // 1800×1200: the 2:3 crop is the middle 800px (x 500…1300), painted
        // blue; the red side bands must not survive.
        $stored = $this->source('land.jpg', 1800, 1200, function ($img) {
            imagefilledrectangle($img, 0, 0, 1799, 1199, imagecolorallocate($img, 220, 0, 0));
            imagefilledrectangle($img, 500, 0, 1299, 1199, imagecolorallocate($img, 0, 0, 220));
        });

        $this->assertTrue($this->thumbs->generate($stored));

        foreach (['sm' => [280, 420], 'md' => [480, 720]] as $size => [$w, $h]) {
            $img = $this->thumb($stored, $size);
            $this->assertSame([$w, $h], [imagesx($img), imagesy($img)]);
            $this->assertColour([0, 0, 220], $this->rgb($img, 3, intdiv($h, 2)));
            $this->assertColour([0, 0, 220], $this->rgb($img, $w - 4, intdiv($h, 2)));
            $this->assertTrue($this->thumbs->exists($stored, $size));
        }
    }

    public function test_tall_portrait_source_is_cropped_top_and_bottom(): void
    {
        // 600×1500: the crop is 600×900 from y 300…1200; green bands outside.
        $stored = $this->source('tall.jpg', 600, 1500, function ($img) {
            imagefilledrectangle($img, 0, 0, 599, 1499, imagecolorallocate($img, 0, 200, 0));
            imagefilledrectangle($img, 0, 300, 599, 1199, imagecolorallocate($img, 0, 0, 220));
        });

        $this->assertTrue($this->thumbs->generate($stored));

        $img = $this->thumb($stored, 'md');
        $this->assertSame([480, 720], [imagesx($img), imagesy($img)]);
        $this->assertColour([0, 0, 220], $this->rgb($img, 240, 3));
        $this->assertColour([0, 0, 220], $this->rgb($img, 240, 716));
    }

    public function test_small_source_is_never_upscaled_but_is_still_re_encoded_as_jpeg(): void
    {
        // 120×150 crops to 100×150, which is below both targets.
        $stored = $this->source('tiny.png', 120, 150, $this->fill(10, 120, 200), 'png');

        $this->assertTrue($this->thumbs->generate($stored));

        foreach (['sm', 'md'] as $size) {
            $file = Storage::disk('public')->path($this->thumbs->path($stored, $size));
            $info = getimagesize($file);
            $this->assertSame([100, 150, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        }
    }

    public function test_source_between_the_sizes_keeps_its_own_size_for_md_only(): void
    {
        $stored = $this->source('mid.jpg', 400, 600, $this->fill(90, 90, 90));

        $this->assertTrue($this->thumbs->generate($stored));

        $this->assertSame([280, 420], array_slice(getimagesize(Storage::disk('public')->path($this->thumbs->path($stored, 'sm'))), 0, 2));
        $this->assertSame([400, 600], array_slice(getimagesize(Storage::disk('public')->path($this->thumbs->path($stored, 'md'))), 0, 2));
    }

    public function test_transparent_png_is_flattened_onto_white(): void
    {
        $stored = $this->source('alpha.png', 600, 900, function ($img) {
            imagefilledrectangle($img, 0, 0, 599, 899, imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagefilledrectangle($img, 200, 300, 399, 599, imagecolorallocatealpha($img, 200, 0, 0, 0));
        }, 'png');

        $this->assertTrue($this->thumbs->generate($stored));

        $img = $this->thumb($stored, 'sm');
        $this->assertColour([255, 255, 255], $this->rgb($img, 5, 5), 6);
        $this->assertColour([200, 0, 0], $this->rgb($img, 140, 210));
    }

    public function test_output_is_baseline_not_progressive_jpeg(): void
    {
        $stored = $this->source('base.jpg', 600, 900, $this->fill(40, 80, 120));
        $this->thumbs->generate($stored);

        $bytes = Storage::disk('public')->get($this->thumbs->path($stored, 'md'));
        $this->assertStringContainsString("\xFF\xC0", $bytes, 'baseline SOF0 marker');
        $this->assertStringNotContainsString("\xFF\xC2", $bytes, 'no progressive SOF2 marker');
    }

    public function test_gif_and_webp_sources_are_read_when_gd_supports_them(): void
    {
        foreach (['gif' => IMG_GIF, 'webp' => IMG_WEBP] as $format => $bit) {
            if (!(imagetypes() & $bit)) {
                continue;
            }
            $stored = $this->source("src.{$format}", 600, 900, $this->fill(0, 0, 220), $format);
            $this->assertTrue($this->thumbs->generate($stored), $format);
            $this->assertColour([0, 0, 220], $this->rgb($this->thumb($stored, 'sm'), 140, 210));
        }
    }

    public function test_corrupt_source_fails_soft_and_warns_once(): void
    {
        // Unique per run: warnings are remembered per path for the process.
        $name = 'corrupt-' . uniqid() . '.jpg';
        Storage::disk('public')->put($name, "\xFF\xD8\xFF\xE0 this is not really a jpeg");
        $stored = 'public/' . $name;

        Log::shouldReceive('warning')->once()->withArgs(fn($msg) => str_contains($msg, $stored));

        $this->assertFalse($this->thumbs->generate($stored));
        $this->assertFalse($this->thumbs->generate($stored));
        $this->assertFalse($this->thumbs->exists($stored, 'sm'));
        $this->assertFalse($this->thumbs->exists($stored, 'md'));
    }

    public function test_missing_source_fails_soft(): void
    {
        Log::shouldReceive('warning')->once();

        $this->assertFalse($this->thumbs->generate('public/missing-' . uniqid() . '.jpg'));
    }

    public function test_oversized_source_is_skipped_without_decoding(): void
    {
        // A real 10×10 PNG whose header claims 8000×6000 (48 MP):
        // getimagesize() trusts IHDR, so the guard trips before any decode.
        $stored = $this->source('huge-' . uniqid() . '.png', 10, 10, $this->fill(1, 2, 3), 'png');
        $relative = CoverThumbnails::relative($stored);
        $bytes = Storage::disk('public')->get($relative);
        $bytes = substr_replace($bytes, pack('NN', 8000, 6000), 16, 8);
        Storage::disk('public')->put($relative, $bytes);

        Log::shouldReceive('warning')->once()->withArgs(fn($msg) => str_contains($msg, 'megapixels'));

        $this->assertFalse($this->thumbs->generate($stored));
    }

    public function test_second_run_is_idempotent_and_force_regenerates(): void
    {
        $stored = $this->source('idem.jpg', 600, 900, $this->fill(0, 0, 220));
        $this->assertTrue($this->thumbs->generate($stored));

        // Mark the existing thumbnail: a normal run must leave it alone…
        $path = $this->thumbs->path($stored, 'sm');
        Storage::disk('public')->put($path, 'sentinel');
        $this->assertTrue($this->thumbs->generate($stored));
        $this->assertSame('sentinel', Storage::disk('public')->get($path));

        // …a missing size is filled in on its own…
        Storage::disk('public')->delete($this->thumbs->path($stored, 'md'));
        $this->assertTrue($this->thumbs->generate($stored));
        $this->assertTrue($this->thumbs->exists($stored, 'md'));
        $this->assertSame('sentinel', Storage::disk('public')->get($path));

        // …and force rewrites both.
        $this->assertTrue($this->thumbs->generate($stored, true));
        $this->assertNotSame('sentinel', Storage::disk('public')->get($path));
        $this->assertSame([280, 420], array_slice(getimagesize(Storage::disk('public')->path($path)), 0, 2));
    }

    public function test_delete_removes_both_thumbnails_and_keeps_the_original(): void
    {
        $stored = $this->source('del.jpg', 600, 900, $this->fill(0, 0, 220));
        $this->thumbs->generate($stored);

        $this->thumbs->delete($stored);

        $this->assertFalse($this->thumbs->exists($stored, 'sm'));
        $this->assertFalse($this->thumbs->exists($stored, 'md'));
        Storage::disk('public')->assertExists('del.jpg');
    }
}
