<?php

namespace App\Comics;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The real PageExtractor. The format is told by the file's first bytes, not
 * its name (a ".cbr" that is really a ZIP is common, and the other way
 * round):
 *
 *   ZIP  (PK..)       ZipArchive, built in (.cbz)
 *   TAR  (ustar)      PharData, built in (.cbt)
 *   RAR  (Rar!)       unar, else 7z (.cbr)
 *   7z   (7z¼¯)       7z, else unar (.cb7)
 *   PDF  (%PDF)       pdftoppm -jpeg -r {pdf_dpi} (150): one JPEG a page
 *
 * Archive entries whose path climbs out ("../", absolute) are never
 * written; ZIP entries are extracted one by one under safe names.
 */
class ShellPageExtractor implements PageExtractor
{
    public const ZIP = 'zip';
    public const TAR = 'tar';
    public const RAR = 'rar';
    public const SEVENZIP = '7z';
    public const PDF = 'pdf';

    /** The archive kind of a file by its first bytes (then its name), or null. */
    public static function kind(string $file): ?string
    {
        $head = (string) @file_get_contents($file, false, null, 0, 512);
        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            return self::ZIP;
        }
        if (str_starts_with($head, 'Rar!')) {
            return self::RAR;
        }
        if (str_starts_with($head, "7z\xBC\xAF\x27\x1C")) {
            return self::SEVENZIP;
        }
        if (str_starts_with(ltrim($head), '%PDF')) {
            return self::PDF;
        }
        if (strlen($head) >= 262 && substr($head, 257, 5) === 'ustar') {
            return self::TAR;
        }

        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'cbt', 'tar' => self::TAR,
            default => null,
        };
    }

    public function missing(string $file): ?string
    {
        $kind = self::kind($file);
        if ($kind === self::RAR || $kind === self::SEVENZIP) {
            return $this->have($this->unar()) || $this->have($this->sevenZip())
                ? null
                : 'CBR / CB7 files need unar or 7z on the server (NOVARR_UNAR, NOVARR_7Z).';
        }
        if ($kind === self::PDF) {
            return $this->have($this->pdftoppm()) ? null : 'PDF comics need pdftoppm (poppler-utils) on the server (NOVARR_PDFTOPPM).';
        }

        return null;
    }

    public function extract(string $file, string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            throw new ComicImportFailed('Novarr could not make a working folder to unpack it in.');
        }
        $kind = self::kind($file);
        match ($kind) {
            self::ZIP => $this->zip($file, $directory),
            self::TAR => $this->tar($file, $directory),
            self::RAR, self::SEVENZIP => $this->archive($file, $directory, $kind),
            self::PDF => $this->pdf($file, $directory),
            default => throw new ComicImportFailed('Not a comic archive Novarr can read (not CBZ, CBR, CB7, CBT or PDF inside).'),
        };
    }

    private function zip(string $file, string $directory): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new ComicImportFailed('The CBZ (ZIP) archive is damaged or incomplete.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $safe = self::safeRelative($name);
                if ($safe === null || str_ends_with($name, '/')) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                $target = $directory . '/' . $safe;
                @mkdir(dirname($target), 0775, true);
                $out = @fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new ComicImportFailed('Novarr could not write the unpacked pages (is the disk full?).');
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    private function tar(string $file, string $directory): void
    {
        try {
            // PharData needs a known extension to open a tar.
            $link = $directory . '/.source.tar';
            if (!@symlink($file, $link) && !@copy($file, $link)) {
                throw new ComicImportFailed('The CBT archive could not be opened.');
            }
            $tar = new \PharData($link);
            foreach (new \RecursiveIteratorIterator($tar) as $entry) {
                /** @var \PharFileInfo $entry */
                $relative = substr($entry->getPathname(), strlen('phar://' . $link) + 1);
                $safe = self::safeRelative($relative);
                if ($safe === null || $entry->isDir()) {
                    continue;
                }
                $target = $directory . '/' . $safe;
                @mkdir(dirname($target), 0775, true);
                file_put_contents($target, $entry->getContent());
            }
            @unlink($link);
        } catch (ComicImportFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            @unlink($directory . '/.source.tar');
            throw new ComicImportFailed('The CBT (TAR) archive is damaged or incomplete.');
        }
    }

    private function archive(string $file, string $directory, string $kind): void
    {
        $tools = $kind === self::RAR ? ['unar', '7z'] : ['7z', 'unar'];
        $tried = false;
        foreach ($tools as $tool) {
            $binary = $tool === 'unar' ? $this->unar() : $this->sevenZip();
            if (!$this->have($binary)) {
                continue;
            }
            $tried = true;
            // unar: -o where, -D no folder of its own, -f overwrite, -q quiet; 7z: x keeps paths.
            $command = $tool === 'unar'
                ? [$binary, '-q', '-f', '-D', '-o', $directory, $file]
                : [$binary, 'x', '-y', '-bd', '-o' . $directory, $file];
            $result = Process::timeout($this->timeout())->run($command);
            if ($result->successful()) {
                self::dropUnsafe($directory);

                return;
            }
            Log::info("Comics: {$tool} could not unpack {$file}: " . trim($result->errorOutput() ?: $result->output()));
        }
        if (!$tried) {
            throw new ComicImportFailed($this->missing($file) ?? 'No tool to unpack it.');
        }
        throw new ComicImportFailed($kind === self::RAR ? 'The CBR (RAR) archive is damaged, incomplete or password-protected.' : 'The CB7 (7z) archive is damaged or incomplete.');
    }

    private function pdf(string $file, string $directory): void
    {
        $binary = $this->pdftoppm();
        if (!$this->have($binary)) {
            throw new ComicImportFailed((string) $this->missing($file));
        }
        $dpi = max(50, min(600, (int) config('novarr.comics.pdf_dpi', 150)));
        $result = Process::timeout($this->timeout())->run([$binary, '-jpeg', '-jpegopt', 'quality=85', '-r', (string) $dpi, $file, $directory . '/page']);
        if (!$result->successful()) {
            Log::info("Comics: pdftoppm could not read {$file}: " . trim($result->errorOutput()));
            throw new ComicImportFailed('The PDF could not be read (damaged, or password-protected).');
        }
    }

    /** A safe relative path inside the target (no "..", no absolute, no NUL), or null. */
    public static function safeRelative(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#^[a-z]:/#i', $name)) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    /** Symlinks a tool may have written are removed (a page is never a link out of the folder). */
    private static function dropUnsafe(string $directory): void
    {
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                if ($file->isLink()) {
                    @unlink($file->getPathname());
                }
            }
        } catch (\Throwable) {
        }
    }

    private function have(string $binary): bool
    {
        static $found = [];
        if (!array_key_exists($binary, $found)) {
            if (str_contains($binary, '/')) {
                $found[$binary] = is_executable($binary);
            } else {
                try {
                    $found[$binary] = Process::timeout(10)->run(['sh', '-c', 'command -v ' . escapeshellarg($binary)])->successful();
                } catch (\Throwable) {
                    $found[$binary] = false;
                }
            }
        }

        return $found[$binary];
    }

    private function unar(): string
    {
        return (string) config('novarr.comics.unar', 'unar');
    }

    private function sevenZip(): string
    {
        return (string) config('novarr.comics.sevenzip', '7z');
    }

    private function pdftoppm(): string
    {
        return (string) config('novarr.comics.pdftoppm', 'pdftoppm');
    }

    private function timeout(): int
    {
        return max(30, (int) config('novarr.comics.extract_timeout', 600));
    }
}
