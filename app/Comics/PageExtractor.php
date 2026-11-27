<?php

namespace App\Comics;

/**
 * Unpacks one comic file into a directory: the archive's files as they are
 * (pages, ComicInfo.xml, whatever else), or a PDF's pages as JPEGs. The
 * importer then picks the page images out of the directory (natural order
 * of their paths). The source file is only read, never moved or changed
 * (a torrent's stays seeding).
 *
 * The default is App\Comics\ShellPageExtractor (ZipArchive, PharData,
 * unar / 7z, pdftoppm); tests bind a fake.
 */
interface PageExtractor
{
    /**
     * @throws ComicImportFailed  not a comic it can read (the reason in plain words)
     */
    public function extract(string $file, string $directory): void;

    /** Why files of this kind cannot be read on this server (a missing tool), or null. */
    public function missing(string $file): ?string;
}
