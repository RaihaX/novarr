<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Small JPEG copies of uploaded covers, so lists never ship the original.
 *
 * Two sizes, centre-cropped to 2:3 and never upscaled, written to the public
 * disk at covers/{size}/{basename}.jpg. Upload basenames are unique per
 * upload, so a thumbnail URL never changes meaning and can be cached forever.
 *
 * Fails soft: a source GD cannot read returns false (logged once) and callers
 * keep using the original.
 */
class CoverThumbnails
{
    public const SIZES = [
        'sm' => [280, 420],
        'md' => [480, 720],
    ];

    public const QUALITY = 80;

    /** Larger sources are skipped: decoding one would need hundreds of MB. */
    public const MAX_PIXELS = 40_000_000;

    /** Stored paths already warned about in this process. */
    private static array $warned = [];

    /**
     * Write both sizes for a stored cover. Existing thumbnails are kept
     * unless $force; returns true when both thumbnails exist afterwards.
     */
    public function generate(string $storedPath, bool $force = false): bool
    {
        $todo = array_filter(
            array_keys(self::SIZES),
            fn(string $size) => $force || !$this->exists($storedPath, $size)
        );
        if ($todo === []) {
            return true;
        }

        $source = $this->load($storedPath);
        if ($source === null) {
            return false;
        }

        $ok = true;
        foreach ($todo as $size) {
            $ok = $this->write($source, $storedPath, $size) && $ok;
        }

        return $ok;
    }

    /** Path of a thumbnail on the public disk. */
    public function path(string $storedPath, string $size): string
    {
        if (!isset(self::SIZES[$size])) {
            throw new \InvalidArgumentException("Unknown cover thumbnail size: {$size}");
        }

        return "covers/{$size}/" . pathinfo(self::relative($storedPath), PATHINFO_FILENAME) . '.jpg';
    }

    public function exists(string $storedPath, string $size): bool
    {
        return is_file(Storage::disk('public')->path($this->path($storedPath, $size)));
    }

    /** Remove both thumbnails of a stored cover (the original is untouched). */
    public function delete(string $storedPath): void
    {
        foreach (array_keys(self::SIZES) as $size) {
            Storage::disk('public')->delete($this->path($storedPath, $size));
        }
    }

    /**
     * A File row's file_path ("public/abc.jpg", or a bare "abc.jpg") as a
     * path on the public disk.
     */
    public static function relative(string $storedPath): string
    {
        $path = ltrim($storedPath, '/');

        return str_starts_with($path, 'public/') ? substr($path, strlen('public/')) : $path;
    }

    /** Decode the source cover, or null (with one warning) when GD cannot. */
    private function load(string $storedPath): ?\GdImage
    {
        $file = Storage::disk('public')->path(self::relative($storedPath));

        $info = is_file($file) ? @getimagesize($file) : false;
        if (!$info) {
            return $this->fail($storedPath, 'missing or not an image');
        }

        [$width, $height, $type] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            return $this->fail($storedPath, "{$width}x{$height} exceeds " . (self::MAX_PIXELS / 1_000_000) . ' megapixels');
        }

        $readers = [
            IMAGETYPE_JPEG => [IMG_JPG, 'imagecreatefromjpeg'],
            IMAGETYPE_PNG => [IMG_PNG, 'imagecreatefrompng'],
            IMAGETYPE_GIF => [IMG_GIF, 'imagecreatefromgif'],
            IMAGETYPE_WEBP => [IMG_WEBP, 'imagecreatefromwebp'],
        ];
        [$bit, $reader] = $readers[$type] ?? [0, null];
        if (!$reader || !(imagetypes() & $bit) || !function_exists($reader)) {
            return $this->fail($storedPath, 'unsupported image type ' . ($info['mime'] ?? $type));
        }

        // Truncated files raise warnings (and sometimes still return an image
        // that is half grey); either way the original remains the fallback.
        $image = @$reader($file);
        if (!$image instanceof \GdImage) {
            return $this->fail($storedPath, 'could not be decoded');
        }

        return $image;
    }

    private function write(\GdImage $source, string $storedPath, string $size): bool
    {
        $srcW = imagesx($source);
        $srcH = imagesy($source);

        // Centre crop to 2:3.
        if ($srcW * 3 > $srcH * 2) {
            $cropH = $srcH;
            $cropW = max(1, (int) round($srcH * 2 / 3));
        } else {
            $cropW = $srcW;
            $cropH = max(1, (int) round($srcW * 3 / 2));
        }
        $cropX = intdiv($srcW - $cropW, 2);
        $cropY = intdiv($srcH - $cropH, 2);

        // Never upscale: a crop smaller than the target keeps its own size.
        [$targetW, $targetH] = self::SIZES[$size];
        [$outW, $outH] = $cropW < $targetW ? [$cropW, $cropH] : [$targetW, $targetH];

        $canvas = imagecreatetruecolor($outW, $outH);
        // Transparent PNG/GIF/WebP pixels flatten onto white.
        imagefilledrectangle($canvas, 0, 0, $outW, $outH, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $outW, $outH, $cropW, $cropH);
        imageinterlace($canvas, false);

        $dest = Storage::disk('public')->path($this->path($storedPath, $size));
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail($storedPath, "cannot create {$dir}");

            return false;
        }

        // Write beside the target and rename, so a reader never sees half a file.
        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @imagejpeg($canvas, $tmp, self::QUALITY);

        if (!$written || !@rename($tmp, $dest)) {
            @unlink($tmp);
            $this->fail($storedPath, "cannot write {$dest}");

            return false;
        }
        @chmod($dest, 0644);

        return true;
    }

    private function fail(string $storedPath, string $reason): null
    {
        if (!isset(self::$warned[$storedPath])) {
            self::$warned[$storedPath] = true;
            Log::warning("CoverThumbnails: skipped {$storedPath}: {$reason}");
        }

        return null;
    }
}
