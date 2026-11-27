<?php

namespace App\Comics;

use App\Audiobooks\AudiobookInbox;
use App\Downloads\AudiobookGrabs;
use App\Downloads\ComicGrabs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * The comics drop-in / download folder (config novarr.comics.inbox,
 * /mnt/comics-inbox in production; NZBGet's Novarr-Comics category and
 * Transmission's /downloads/Comics finish here), read by `comics:scan`
 * every 10 minutes and the Manage comics page's "Scan now".
 *
 * Each top-level entry is:
 *   a file      .cbz .cbr .cb7 .cbt .pdf — one issue or volume;
 *   a folder    its comic files (any depth, natural order) — one issue or
 *               volume each; or, with none, its folders of page images —
 *               one issue each (images straight in it: the folder is one).
 * Hidden entries and {inbox}/_failed are not entries.
 *
 * As the audiobook inbox (App\Audiobooks\AudiobookInbox): an entry is
 * imported once unchanged for novarr.comics.settle_seconds (download
 * leftovers keep it waiting); a grabbed download (App\Downloads\ComicGrabs)
 * waits while it downloads and is imported for the series / request it was
 * grabbed for; a torrent's files are only read (it keeps seeding) and
 * skipped while they stay. After a usenet or dropped-in import the entry is
 * deleted (its pages are the library copy now); what could not be imported
 * goes to {inbox}/_failed with a "{name}.reason.txt" (a torrent's stays,
 * its grab says why). Parts of a folder that failed go to _failed on their
 * own; the rest is imported.
 */
class ComicInbox
{
    public const FAILED_DIR = '_failed';

    public const REASON_SUFFIX = '.reason.txt';

    private const SCAN_LOCK = 'comics_scan';

    public const LAST_SCAN_KEY = 'comics.last_scan';

    public const COPYING = 'copying';
    public const SETTLING = 'settling';
    public const READY = 'ready';

    public function __construct(private ComicImporter $importer)
    {
    }

    /**
     * Import every stable entry (or only $only). $report hears (name,
     * result, message): imported | failed | waiting | would_import | skipped.
     *
     * @return array{imported: int, failed: int, waiting: int, problem: ?string, busy: bool}
     */
    public function scan(?string $only = null, bool $dryRun = false, ?callable $report = null): array
    {
        $counts = ['imported' => 0, 'failed' => 0, 'waiting' => 0, 'problem' => null, 'busy' => false];
        $say = function (string $name, string $result, string $message = '') use ($report) {
            if ($report) {
                $report($name, $result, $message);
            }
        };

        $lock = Cache::lock(self::SCAN_LOCK, 3600);
        if (!$lock->get()) {
            $counts['busy'] = true;

            return $counts;
        }

        try {
            if (!$dryRun) {
                $this->ensureFolders();
            }
            $entries = $this->entries();
            if ($only !== null) {
                $only = basename(rtrim($only, '/'));
                $entries = array_values(array_filter($entries, fn($e) => $e['name'] === $only));
                if ($entries === []) {
                    $counts['problem'] = "Nothing called “{$only}” is in the comics inbox.";

                    return $counts;
                }
            }

            foreach ($entries as $entry) {
                if (($held = ComicGrabs::hold($entry['name'])) !== null) {
                    if ($held[0] === AudiobookGrabs::DOWNLOADING) {
                        $counts['waiting']++;
                    }
                    $say($entry['name'], $held[0] === AudiobookGrabs::DOWNLOADING ? 'waiting' : 'skipped', $held[1]);

                    continue;
                }
                $state = $this->observe($entry);
                if ($state !== self::READY) {
                    $counts['waiting']++;
                    $say($entry['name'], 'waiting', $state === self::COPYING ? 'still being copied' : 'waiting for the copy to settle');

                    continue;
                }
                if ($dryRun) {
                    $say($entry['name'], 'would_import', count($entry['units']) . ' ' . (count($entry['units']) === 1 ? 'comic' : 'comics'));

                    continue;
                }

                $options = ComicGrabs::options($entry['name']);
                try {
                    $result = $this->importer->import($entry, $options);
                } catch (ComicImportFailed $e) {
                    if (!ComicGrabs::failed($entry['name'], $e->getMessage())) {
                        $this->fail($entry['path'], $e->getMessage());
                    }
                    $this->forget($entry['name']);
                    $counts['failed']++;
                    $say($entry['name'], 'failed', $e->getMessage());

                    continue;
                }
                ComicGrabs::imported($entry['name'], $result['novels'][0] ?? null);
                $this->forget($entry['name']);
                $counts['imported']++;
                if (empty($options['copy'])) {
                    $this->tidyImported($entry, $result);
                }
                $names = implode(', ', array_map(fn($n) => ComicLibrary::label($n), $result['novels']));
                $say($entry['name'], 'imported', count($result['chapters']) . ' ' . (count($result['chapters']) === 1 ? 'issue' : 'issues') . " into {$names}"
                    . ($result['skipped'] !== [] ? ' (' . count($result['skipped']) . ' already there)' : '')
                    . ($result['failed'] !== [] ? ' (' . count($result['failed']) . ' could not be read)' : ''));
            }
        } finally {
            $lock->release();
        }

        if (!$dryRun) {
            Cache::forever(self::LAST_SCAN_KEY, ['at' => now()->toIso8601String()] + array_intersect_key($counts, array_flip(['imported', 'failed', 'waiting', 'problem'])));
        }

        return $counts;
    }

    /**
     * After a usenet / dropped-in import: the failed parts to _failed, the
     * rest deleted (the pages are the library copy now).
     */
    private function tidyImported(array $entry, array $result): void
    {
        foreach ($result['failed'] as $failed) {
            if ($entry['kind'] === 'folder' && file_exists($failed['path'])) {
                $this->fail($failed['path'], $failed['reason'], $entry['name'] . ' – ' . basename($failed['path']));
            }
        }
        $ok = is_link($entry['path']) || is_file($entry['path']) ? @unlink($entry['path']) : File::deleteDirectory($entry['path']);
        if (!$ok) {
            Log::warning("Comics inbox: imported {$entry['path']} could not be deleted.");
        }
    }

    /**
     * The inbox's entries, oldest name first: name, path, kind (file |
     * folder), units (each {name, path, type: file | images, images?}),
     * size_bytes, signature, partial.
     *
     * @return list<array>
     */
    public function entries(): array
    {
        $inbox = ComicLibrary::inbox();
        if (!is_dir($inbox)) {
            return [];
        }
        $out = [];
        foreach (scandir($inbox) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === self::FAILED_DIR || str_starts_with($name, '.')) {
                continue;
            }
            $path = $inbox . '/' . $name;
            if (is_link($path)) {
                continue;
            }
            $entry = is_dir($path) ? self::describeFolder($name, $path) : self::describeFile($name, $path);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    public static function describeFile(string $name, string $path): ?array
    {
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        return [
            'name' => $name,
            'path' => $path,
            'kind' => 'file',
            'units' => ComicLibrary::isComicFile($name) ? [['name' => $name, 'path' => $path, 'type' => 'file']] : [],
            'size_bytes' => (int) $stat['size'],
            'signature' => sha1($name . ':' . $stat['size'] . ':' . $stat['mtime']),
            'partial' => AudiobookInbox::isPartial($name),
        ];
    }

    public static function describeFolder(string $name, string $path): array
    {
        $comics = [];
        $imageDirs = [];
        $size = 0;
        $signature = [];
        $partial = false;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($path) + 1);
                $signature[] = $relative . ':' . $file->getSize() . ':' . $file->getMTime();
                $size += (int) $file->getSize();
                if (AudiobookInbox::isPartial($file->getFilename())) {
                    $partial = true;
                }
                if (ComicLibrary::ignored($relative)) {
                    continue;
                }
                if (ComicLibrary::isComicFile($relative)) {
                    $comics[$relative] = $file->getPathname();
                } elseif (ComicLibrary::isImage($relative)) {
                    $dir = dirname($relative);
                    $imageDirs[$dir][$relative] = $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Comics inbox: cannot read {$path}: " . $e->getMessage());
            $partial = true;
        }

        $units = [];
        uksort($comics, 'strnatcasecmp');
        foreach ($comics as $relative => $abs) {
            $units[] = ['name' => $relative, 'path' => $abs, 'type' => 'file'];
        }
        if ($comics === []) {
            uksort($imageDirs, 'strnatcasecmp');
            foreach ($imageDirs as $dir => $images) {
                uksort($images, 'strnatcasecmp');
                $units[] = [
                    'name' => $dir === '.' ? $name : $dir,
                    'path' => $dir === '.' ? $path : $path . '/' . $dir,
                    'type' => 'images',
                    'images' => array_values($images),
                ];
            }
        }
        sort($signature);

        return [
            'name' => $name,
            'path' => $path,
            'kind' => 'folder',
            'units' => $units,
            'size_bytes' => $size,
            'signature' => sha1(implode("\n", $signature)),
            'partial' => $partial,
        ];
    }

    /** copying | settling | ready (as AudiobookInbox::observe()). */
    public function observe(array $entry): string
    {
        $settle = max(0, (int) config('novarr.comics.settle_seconds', 120));
        $key = $this->seenKey($entry['name']);
        $seen = Cache::get($key);
        $now = now()->getTimestamp();

        if ($entry['partial']) {
            Cache::put($key, ['signature' => $entry['signature'], 'since' => $now], 86400 * 7);

            return self::COPYING;
        }
        if ($settle === 0) {
            return self::READY;
        }
        if (!is_array($seen) || ($seen['signature'] ?? null) !== $entry['signature']) {
            Cache::put($key, ['signature' => $entry['signature'], 'since' => $now], 86400 * 7);

            return is_array($seen) ? self::COPYING : self::SETTLING;
        }

        return $now - (int) $seen['since'] >= $settle ? self::READY : self::SETTLING;
    }

    private function forget(string $name): void
    {
        Cache::forget($this->seenKey($name));
    }

    private function seenKey(string $name): string
    {
        return 'comics.inbox.seen.' . sha1($name);
    }

    /** Move to {inbox}/_failed with its reason beside it; never deletes. */
    public function fail(string $path, string $reason, ?string $as = null): ?string
    {
        $this->ensureFolders();
        $target = AudiobookInbox::uniquePath(ComicLibrary::failedDir(), str_replace('/', ' – ', $as ?? basename($path)));
        if (!@rename($path, $target)) {
            Log::warning("Comics inbox: could not move {$path} to the failed folder ({$reason}).");

            return null;
        }
        @file_put_contents(
            $target . self::REASON_SUFFIX,
            'Not imported on ' . now()->toDayDateTimeString() . ":\n" . $reason . "\n\n"
            . "Fix it and move it back into the inbox (or use Retry on the Manage comics page).\n"
        );
        Log::info("Comics inbox: {$path} moved to _failed: {$reason}");

        return $target;
    }

    /** @return list<array{name: string, reason: string, failed_at: ?int, kind: string}> */
    public function failed(): array
    {
        $dir = ComicLibrary::failedDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.') || str_ends_with($name, self::REASON_SUFFIX)) {
                continue;
            }
            $reasonFile = $dir . '/' . $name . self::REASON_SUFFIX;
            $reason = is_file($reasonFile) ? (string) @file_get_contents($reasonFile) : '';
            $lines = array_values(array_filter(array_map('trim', explode("\n", $reason))));
            $out[] = [
                'name' => $name,
                'reason' => $lines[1] ?? ($lines[0] ?? 'No reason was written down.'),
                'failed_at' => is_file($reasonFile) ? (int) filemtime($reasonFile) : null,
                'kind' => is_dir($dir . '/' . $name) ? 'folder' : 'file',
            ];
        }
        usort($out, fn($a, $b) => ($b['failed_at'] ?? 0) <=> ($a['failed_at'] ?? 0));

        return $out;
    }

    /** Put a failed entry back, settled: @return ?string null when done, else why not */
    public function retry(string $name): ?string
    {
        if ($name === '' || $name !== basename($name) || $name === '.' || $name === '..' || str_ends_with($name, self::REASON_SUFFIX)) {
            return 'That is not an entry of the failed folder.';
        }
        $from = ComicLibrary::failedDir() . '/' . $name;
        if (!file_exists($from)) {
            return 'It is no longer in the failed folder.';
        }
        $to = AudiobookInbox::uniquePath(ComicLibrary::inbox(), $name);
        if (!@rename($from, $to)) {
            return 'It could not be moved back into the inbox (check the folder’s permissions).';
        }
        @unlink($from . self::REASON_SUFFIX);
        $entry = is_dir($to) ? self::describeFolder(basename($to), $to) : self::describeFile(basename($to), $to);
        if ($entry !== null) {
            Cache::put($this->seenKey($entry['name']), ['signature' => $entry['signature'], 'since' => 0], 86400 * 7);
        }

        return null;
    }

    /** What the Manage comics page shows. */
    public function status(): array
    {
        $pending = [];
        foreach ($this->entries() as $entry) {
            $held = ComicGrabs::hold($entry['name']);
            $state = $held !== null ? $held[0] : $this->observe($entry);
            $pending[] = [
                'name' => $entry['name'],
                'kind' => $entry['kind'],
                'comics' => count($entry['units']),
                'size_bytes' => $entry['size_bytes'],
                'state' => $state,
            ] + ($held !== null ? ['note' => $held[1]] : []);
        }
        return [
            'inbox' => ComicLibrary::inbox(),
            'settle_seconds' => max(0, (int) config('novarr.comics.settle_seconds', 120)),
            'pending' => $pending,
            'failed' => $this->failed(),
            'last_scan' => Cache::get(self::LAST_SCAN_KEY),
            'comicvine' => ComicVine::enabled(),
        ];
    }

    public function ensureFolders(): void
    {
        foreach ([ComicLibrary::inbox(), ComicLibrary::failedDir()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }
}
