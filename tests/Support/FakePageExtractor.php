<?php

namespace Tests\Support;

use App\Comics\ComicImportFailed;
use App\Comics\PageExtractor;

/**
 * A PageExtractor without unar / 7z / pdftoppm. A file whose bytes are
 *
 *   FAKE:{n}            unpacks to n JPEG pages (tests/fixtures/mangadex/page.jpg)
 *   FAKE:{n}|{xml}      …and that ComicInfo.xml
 *   BAD:{reason}        cannot be read (ComicImportFailed with the reason)
 *
 * Anything else (a real CBZ) goes to the real extractor. $calls lists the
 * files asked for; $missing makes missing() answer for every file.
 */
class FakePageExtractor implements PageExtractor
{
    public array $calls = [];

    public ?string $missing = null;

    public function missing(string $file): ?string
    {
        return $this->missing;
    }

    public function extract(string $file, string $directory): void
    {
        $this->calls[] = basename($file);
        $bytes = (string) file_get_contents($file);
        if (str_starts_with($bytes, 'BAD:')) {
            throw new ComicImportFailed(substr($bytes, 4));
        }
        if (!str_starts_with($bytes, 'FAKE:')) {
            (new \App\Comics\ShellPageExtractor())->extract($file, $directory);

            return;
        }
        [$count, $xml] = array_pad(explode('|', substr($bytes, 5), 2), 2, null);
        @mkdir($directory . '/pages', 0775, true);
        $page = file_get_contents(base_path('tests/fixtures/mangadex/page.jpg'));
        for ($i = 1; $i <= (int) $count; $i++) {
            file_put_contents($directory . '/pages/page-' . $i . '.jpg', $page);
        }
        if ($xml !== null) {
            file_put_contents($directory . '/ComicInfo.xml', $xml);
        }
    }
}
