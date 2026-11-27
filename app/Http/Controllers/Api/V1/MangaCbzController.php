<?php

namespace App\Http\Controllers\Api\V1;

use App\ChapterPage;
use App\Http\Controllers\Controller;
use App\NovelChapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * One manga chapter (or comic issue / volume: medium 'comic' has pages too) as a CBZ, for readers that open comic archives (the
 * KOReader plugin on a Kindle):
 *
 *   GET /api/v1/chapters/{id}/cbz[?max_width=1236&gray=1]
 *       200 application/vnd.comicbook+zip — the chapter's pages in order
 *           (001.jpg, 002.png, …) and a ComicInfo.xml (series, number,
 *           title, page count, reading direction). Entries are STOREd: page
 *           images are compressed already.
 *       404 not a manga chapter (or unknown)
 *       422 {message, code: "no_pages"} — nothing downloaded yet
 *
 * max_width (200–4000) scales wider pages down to that width, gray=1 turns
 * them grey: an e-ink screen shows neither more pixels nor colour, and both
 * cut the file a lot. Either one (or a page the archive readers may not know,
 * such as WebP) re-encodes the page as JPEG through GD when GD can read it;
 * otherwise the original bytes go in unchanged.
 *
 * Built archives are cached on disk (storage/app/cbz-cache) under a key of
 * the chapter's pages and the options, so a re-downloaded or refetched page
 * makes a new one; files unused for CACHE_DAYS are removed when another is
 * built.
 */
class MangaCbzController extends Controller
{
    /** Bump when the archive layout changes (old cached files are then never served). */
    public const VERSION = 1;

    public const CACHE_DAYS = 7;

    public const JPEG_QUALITY = 80;

    public function show(Request $request, $id): BinaryFileResponse|JsonResponse
    {
        $data = $request->validate([
            'max_width' => 'nullable|integer|min:200|max:4000',
            'gray' => 'nullable|boolean',
        ]);
        $chapter = NovelChapter::with('novel:id,name,medium,reading_mode')->find($id);
        if ($chapter === null || !$chapter->novel?->hasPages()) {
            return response()->json(['message' => 'Not a manga chapter.'], 404);
        }
        $pages = $chapter->status
            ? ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->get(['page', 'path', 'mime', 'bytes'])
            : collect();
        if ($pages->isEmpty()) {
            return response()->json(['message' => 'This chapter has no pages on the server yet.', 'code' => 'no_pages'], 422);
        }

        $maxWidth = isset($data['max_width']) ? (int) $data['max_width'] : null;
        $gray = $request->boolean('gray');
        $key = sha1(json_encode([
            static::VERSION, (int) $chapter->id, $maxWidth, $gray, $chapter->novel->reading_mode,
            $pages->map(fn(ChapterPage $p) => [(int) $p->page, (string) $p->path, (int) $p->bytes])->all(),
        ]));
        $dir = static::cacheDirectory();
        $path = $dir . '/' . (int) $chapter->id . '-' . $key . '.cbz';

        if (is_file($path)) {
            @touch($path);
        } else {
            static::prune($dir);
            $built = static::build($chapter, $pages, $maxWidth, $gray, $path);
            if ($built !== null) {
                return $built;
            }
        }

        return response()->download($path, static::fileName($chapter), [
            'Content-Type' => 'application/vnd.comicbook+zip',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    public static function cacheDirectory(): string
    {
        $dir = storage_path('app/cbz-cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Builds the archive at $path (temp file + rename). Returns an error response, or null when built. */
    private static function build(NovelChapter $chapter, $pages, ?int $maxWidth, bool $gray, string $path): ?JsonResponse
    {
        $tmp = $path . '.' . Str::random(8) . '.tmp';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Log::warning("CBZ: cannot create {$tmp}");

            return response()->json(['message' => 'The server could not build the archive.'], 500);
        }
        $disk = ChapterPage::disk();
        $added = 0;
        foreach ($pages as $page) {
            try {
                $bytes = $disk->get($page->path);
            } catch (\Throwable $e) {
                $bytes = null;
            }
            if (!is_string($bytes) || $bytes === '') {
                Log::warning("CBZ: page {$page->page} of chapter {$chapter->id} is missing on the manga disk");
                continue;
            }
            [$bytes, $ext] = static::prepare($bytes, (string) $page->mime, $maxWidth, $gray);
            $name = str_pad((string) $page->page, 3, '0', STR_PAD_LEFT) . '.' . $ext;
            $zip->addFromString($name, $bytes);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
            $added++;
        }
        if ($added === 0) {
            $zip->close();
            @unlink($tmp);

            return response()->json(['message' => 'This chapter has no pages on the server yet.', 'code' => 'no_pages'], 422);
        }
        $zip->addFromString('ComicInfo.xml', static::comicInfo($chapter, $added));
        $zip->setCompressionName('ComicInfo.xml', ZipArchive::CM_STORE);
        if (!$zip->close() || !@rename($tmp, $path)) {
            @unlink($tmp);
            Log::warning("CBZ: cannot write {$path}");

            return response()->json(['message' => 'The server could not build the archive.'], 500);
        }

        return null;
    }

    /**
     * A page as it goes into the archive: [bytes, extension]. Re-encoded as
     * JPEG when asked to scale or grey it, or when it is not JPEG / PNG / GIF
     * (WebP, AVIF: not every comic reader opens them), and GD can read it.
     */
    public static function prepare(string $bytes, string $mime, ?int $maxWidth, bool $gray): array
    {
        $ext = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'jpg',
        };
        $plain = in_array($ext, ['jpg', 'png', 'gif'], true);
        if (($plain && $maxWidth === null && !$gray) || !function_exists('imagecreatefromstring')) {
            return [$bytes, $ext];
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return [$bytes, $ext];
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($maxWidth !== null && $width > $maxWidth) {
            $scaled = imagescale($image, $maxWidth, max(1, (int) round($height * $maxWidth / $width)), IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        if ($gray) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
        }
        // A transparent PNG goes onto white (JPEG has no alpha).
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);
        ob_start();
        $ok = imagejpeg($canvas, null, static::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);

        return $ok && is_string($jpeg) && $jpeg !== '' ? [$jpeg, 'jpg'] : [$bytes, $ext];
    }

    public static function comicInfo(NovelChapter $chapter, int $pageCount): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $number = Presenter::number($chapter->number ?? $chapter->chapter);
        $rtl = $chapter->novel->effectiveReadingMode() === 'rtl';

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<ComicInfo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">' . "\n"
            . '  <Series>' . $e($chapter->novel->name) . "</Series>\n"
            . '  <Number>' . $e($number) . "</Number>\n"
            . '  <Title>' . $e(Presenter::label($chapter)) . "</Title>\n"
            . '  <PageCount>' . $pageCount . "</PageCount>\n"
            // A comic reads left to right: "No" (readers then never flip it); a manga "Yes".
            . '  <Manga>' . ($rtl ? 'YesAndRightToLeft' : ($chapter->novel->isComic() ? 'No' : 'Yes')) . "</Manga>\n"
            . "</ComicInfo>\n";
    }

    /** "Series - Chapter 12.cbz", ASCII-safe. */
    public static function fileName(NovelChapter $chapter): string
    {
        $name = Str::ascii($chapter->novel->name . ' - ' . Presenter::label($chapter));
        $name = trim(preg_replace('/[^A-Za-z0-9 ._()\-]+/', '', $name) ?? '');

        return ($name !== '' ? Str::limit($name, 150, '') : 'chapter-' . (int) $chapter->id) . '.cbz';
    }

    /** Removes cached archives unused for CACHE_DAYS (and stale temp files). */
    private static function prune(string $dir): void
    {
        $limit = time() - static::CACHE_DAYS * 86400;
        // Two globs: GLOB_BRACE is missing on musl (Alpine) builds.
        foreach (array_merge(glob($dir . '/*.cbz') ?: [], glob($dir . '/*.tmp') ?: []) as $file) {
            $mtime = @filemtime($file);
            $stale = str_ends_with($file, '.tmp') ? $mtime !== false && $mtime < time() - 3600 : $mtime !== false && $mtime < $limit;
            if ($stale) {
                @unlink($file);
            }
        }
    }
}
