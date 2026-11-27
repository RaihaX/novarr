<?php

namespace App\Audiobooks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The drop-in folder (config novarr.audiobooks.inbox), read by
 * `audiobooks:scan` every 10 minutes.
 *
 * Each top-level entry is one audiobook: a single audio file, or a folder
 * whose audio files (natural order of their paths, so "CD1/…" plays before
 * "CD2/…" and "2.mp3" before "10.mp3") are its parts. Hidden entries and
 * {inbox}/_failed are not entries.
 *
 * Still copying? An entry is imported only once its files' sizes and
 * modification times (and the list of files) have not changed for
 * novarr.audiobooks.settle_seconds (2 minutes): each scan remembers the
 * entry's signature and since when it has been the same (the cache), so a
 * copy over the network that takes an hour is never imported half way.
 * Download leftovers (.part, .crdownload …) keep it waiting. With the
 * scan every 10 minutes an entry usually goes in on the scan after the one
 * that first saw it.
 *
 * What cannot be imported (App\Audiobooks\ImportFailed: unreadable, no
 * audio in it, already in the library) is moved to {inbox}/_failed/ with
 * a "{name}.reason.txt" beside it; nothing is ever deleted. The owner can
 * put it back (retry()) from the Audiobooks page.
 */
class AudiobookInbox
{
    public const FAILED_DIR = '_failed';

    public const REASON_SUFFIX = '.reason.txt';

    /** Names a download / copy in progress gives its files. */
    public const PARTIAL_EXTENSIONS = ['part', 'partial', 'tmp', 'crdownload', 'download', '!qb', '!ut', 'filepart'];

    private const SCAN_LOCK = 'audiobooks_scan';

    public const LAST_SCAN_KEY = 'audiobooks.last_scan';

    /** Entry states (status()). */
    public const COPYING = 'copying';
    public const SETTLING = 'settling';
    public const READY = 'ready';

    public function __construct(private AudiobookImporter $importer, private AudioProbe $probe)
    {
    }

    /**
     * Import every stable entry (or only $only, an entry's name). A dry
     * run imports and moves nothing (it still learns the signatures).
     * $report is told about each entry: (name, result, message), result
     * one of imported | failed | waiting | would_import | skipped.
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

        if (($problem = $this->probe->problem()) !== null) {
            $counts['problem'] = $problem;
            $this->remember($counts);

            return $counts;
        }

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
                    $counts['problem'] = "Nothing called “{$only}” is in the audiobooks inbox.";

                    return $counts;
                }
            }

            foreach ($entries as $entry) {
                $state = $this->observe($entry);
                if ($state !== self::READY) {
                    $counts['waiting']++;
                    $say($entry['name'], 'waiting', $state === self::COPYING ? 'still being copied' : 'waiting for the copy to settle');

                    continue;
                }
                if ($dryRun) {
                    $say($entry['name'], 'would_import', count($entry['audio']) . ' audio ' . (count($entry['audio']) === 1 ? 'file' : 'files'));

                    continue;
                }

                try {
                    $audiobook = $this->importer->import($entry);
                    $this->forget($entry['name']);
                    $counts['imported']++;
                    $say($entry['name'], 'imported', "#{$audiobook->id} “{$audiobook->title}”" . ($audiobook->novel_id ? ' (matched to a book)' : ' (unmatched: ' . $audiobook->match_note . ')'));
                } catch (ImportFailed $e) {
                    $this->fail($entry['path'], $e->getMessage());
                    $this->forget($entry['name']);
                    $counts['failed']++;
                    $say($entry['name'], 'failed', $e->getMessage());
                }
            }
        } finally {
            $lock->release();
        }

        if (!$dryRun) {
            $this->remember($counts);
        }

        return $counts;
    }

    /**
     * The inbox's entries, oldest name first. Each: name, path, kind
     * (file | folder), audio (paths relative to the entry, natural order),
     * others (the rest), size_bytes, signature, partial (download
     * leftovers in it).
     *
     * @return list<array{name: string, path: string, kind: string, audio: list<string>, others: list<string>, size_bytes: int, signature: string, partial: bool}>
     */
    public function entries(): array
    {
        $inbox = AudiobookLibrary::inbox();
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
            $entry = is_dir($path) ? $this->describeFolder($name, $path) : $this->describeFile($name, $path);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    private function describeFile(string $name, string $path): ?array
    {
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        return [
            'name' => $name,
            'path' => $path,
            'kind' => 'file',
            'audio' => AudiobookLibrary::isAudio($name) ? [''] : [],
            'others' => AudiobookLibrary::isAudio($name) ? [] : [''],
            'size_bytes' => (int) $stat['size'],
            'signature' => sha1($name . ':' . $stat['size'] . ':' . $stat['mtime']),
            'partial' => self::isPartial($name),
        ];
    }

    private function describeFolder(string $name, string $path): array
    {
        $audio = [];
        $others = [];
        $size = 0;
        $signature = [];
        $partial = false;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($path) + 1);
                $signature[] = $relative . ':' . $file->getSize() . ':' . $file->getMTime();
                $base = $file->getFilename();
                if (self::isPartial($base)) {
                    $partial = true;
                }
                // Hidden files (".DS_Store", rsync's ".name.XXXX" while it
                // copies) are never parts; they are carried along as extras.
                if (!str_starts_with($base, '.') && AudiobookLibrary::isAudio($base)) {
                    $audio[] = $relative;
                    $size += (int) $file->getSize();
                } else {
                    $others[] = $relative;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Audiobooks inbox: cannot read {$path}: " . $e->getMessage());
            $partial = true;
        }

        usort($audio, 'strnatcasecmp');
        sort($signature);

        return [
            'name' => $name,
            'path' => $path,
            'kind' => 'folder',
            'audio' => $audio,
            'others' => $others,
            'size_bytes' => $size,
            'signature' => sha1(implode("\n", $signature)),
            'partial' => $partial,
        ];
    }

    public static function isPartial(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::PARTIAL_EXTENSIONS, true);
    }

    /**
     * Note the entry's signature now and say where it stands: copying (a
     * download leftover in it, or changed since it was last seen),
     * settling (unchanged, not for long enough yet) or ready.
     */
    public function observe(array $entry): string
    {
        $settle = max(0, (int) config('novarr.audiobooks.settle_seconds', 120));
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

    /** Seconds the entry has been unchanged (0 when not seen before). */
    public function stableFor(string $name): int
    {
        $seen = Cache::get($this->seenKey($name));

        if (!is_array($seen)) {
            return 0;
        }
        // Put back by Retry ("since forever"): as long as it needs.
        if ((int) $seen['since'] === 0) {
            return max(0, (int) config('novarr.audiobooks.settle_seconds', 120));
        }

        return max(0, now()->getTimestamp() - (int) $seen['since']);
    }

    private function forget(string $name): void
    {
        Cache::forget($this->seenKey($name));
    }

    private function seenKey(string $name): string
    {
        return 'audiobooks.inbox.seen.' . sha1($name);
    }

    /**
     * Move an entry to {inbox}/_failed with its reason beside it. Never
     * deletes: when the move itself fails the entry stays where it is
     * (logged) and is tried again next scan.
     */
    public function fail(string $path, string $reason): ?string
    {
        $this->ensureFolders();
        $target = self::uniquePath(AudiobookLibrary::failedDir(), basename($path));
        if (!@rename($path, $target)) {
            Log::warning("Audiobooks inbox: could not move {$path} to the failed folder ({$reason}).");

            return null;
        }
        @file_put_contents(
            $target . self::REASON_SUFFIX,
            'Not imported on ' . now()->toDayDateTimeString() . ":\n" . $reason . "\n\n"
            . "Fix it and move it back into the inbox (or use Retry on the Audiobooks page).\n"
        );
        Log::info("Audiobooks inbox: {$path} moved to _failed: {$reason}");

        return $target;
    }

    /**
     * The entries in {inbox}/_failed: name, reason, failed_at (unix time).
     *
     * @return list<array{name: string, reason: string, failed_at: ?int, kind: string}>
     */
    public function failed(): array
    {
        $dir = AudiobookLibrary::failedDir();
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

    /**
     * Put a failed entry back in the inbox, to be imported on the next scan
     * (counted as settled already: it has not changed in _failed).
     *
     * @return ?string  null when done, else why not
     */
    public function retry(string $name): ?string
    {
        if ($name === '' || $name !== basename($name) || $name === '.' || $name === '..' || str_ends_with($name, self::REASON_SUFFIX)) {
            return 'That is not an entry of the failed folder.';
        }
        $from = AudiobookLibrary::failedDir() . '/' . $name;
        if (!file_exists($from)) {
            return 'It is no longer in the failed folder.';
        }
        $to = self::uniquePath(AudiobookLibrary::inbox(), $name);
        if (!@rename($from, $to)) {
            return 'It could not be moved back into the inbox (check the folder’s permissions).';
        }
        @unlink($from . self::REASON_SUFFIX);

        $entry = is_dir($to) ? $this->describeFolder(basename($to), $to) : $this->describeFile(basename($to), $to);
        if ($entry !== null) {
            // Unchanged "since forever": settled whatever settle_seconds is.
            Cache::put($this->seenKey($entry['name']), ['signature' => $entry['signature'], 'since' => 0], 86400 * 7);
        }

        return null;
    }

    /**
     * What the Audiobooks page and GET /api/v1/audiobooks/inbox show:
     * the entries waiting (with their state), the failed ones and the last
     * scan.
     */
    public function status(): array
    {
        $pending = [];
        foreach ($this->entries() as $entry) {
            $state = $this->observe($entry);
            $pending[] = [
                'name' => $entry['name'],
                'kind' => $entry['kind'],
                'audio_files' => count($entry['audio']),
                'size_bytes' => $entry['size_bytes'],
                'state' => $state,
                'stable_seconds' => $state === self::COPYING ? 0 : $this->stableFor($entry['name']),
            ];
        }

        return [
            'inbox' => AudiobookLibrary::inbox(),
            'settle_seconds' => max(0, (int) config('novarr.audiobooks.settle_seconds', 120)),
            'pending' => $pending,
            'failed' => $this->failed(),
            'last_scan' => Cache::get(self::LAST_SCAN_KEY),
            'problem' => $this->probe->problem(),
        ];
    }

    private function remember(array $counts): void
    {
        Cache::forever(self::LAST_SCAN_KEY, [
            'at' => now()->toIso8601String(),
            'imported' => $counts['imported'],
            'failed' => $counts['failed'],
            'waiting' => $counts['waiting'],
            'problem' => $counts['problem'],
        ]);
    }

    /** The inbox, its _failed folder and the library root, made when missing. */
    public function ensureFolders(): void
    {
        foreach ([AudiobookLibrary::inbox(), AudiobookLibrary::failedDir(), AudiobookLibrary::root()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    /** $dir/$name, or "$name (2)" … when that is taken (the extension kept). */
    public static function uniquePath(string $dir, string $name): string
    {
        $path = $dir . '/' . $name;
        if (!file_exists($path)) {
            return $path;
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $ext !== '' && !is_dir($path) ? substr($name, 0, -strlen($ext) - 1) : $name;
        $suffix = $ext !== '' && !is_dir($path) ? '.' . $ext : '';
        for ($i = 2; ; $i++) {
            $candidate = "{$dir}/{$stem} ({$i}){$suffix}";
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
    }
}
