<?php

namespace App\Audiobooks;

use App\Audiobook;
use App\AudiobookChapter;
use App\AudiobookPart;
use App\Services\CoverThumbnails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One settled inbox entry (App\Audiobooks\AudiobookInbox::entries()) into
 * the library:
 *
 *  1. every audio file is probed (App\Audiobooks\AudioProbe: length, tags,
 *     embedded chapters); one it cannot read fails the whole entry;
 *  2. the fingerprint (each part's first MB and size) must be new: the same
 *     files dropped in twice fail as "already in the library";
 *  3. the cover: the files' embedded picture, else cover.jpg / folder.jpg …
 *     beside them; stored on the public disk like book covers, with
 *     thumbnails;
 *  4. the rows (audiobook, parts with their offsets, chapters) are written
 *     and the files MOVED to {root}/{id}/part-001.ext … (extension kept);
 *     anything else in the folder goes to {root}/{id}/extras/. A move that
 *     fails half way puts back what it moved and fails the entry;
 *  5. the match to a book (App\Audiobooks\AudiobookMatcher::autoMatch()).
 *
 * Chapters: the embedded ones (each part's, shifted to whole-book
 * positions); else one per file (its title tag, else its name); a single
 * file without chapters is one chapter, "Part 1".
 *
 * Throws ImportFailed (the reason, plain words) for an entry that cannot be
 * imported; the inbox moves it to _failed.
 */
class AudiobookImporter
{
    /** Bytes of each part read for the fingerprint. */
    public const FINGERPRINT_BYTES = 1048576;

    public function __construct(private AudioProbe $probe, private AudiobookMatcher $matcher, private CoverThumbnails $thumbnails)
    {
    }

    public function import(array $entry): Audiobook
    {
        $base = $entry['path'];
        $isFolder = $entry['kind'] === 'folder';
        $files = array_map(fn(string $rel) => $rel === '' ? $base : $base . '/' . $rel, $entry['audio']);

        if ($files === []) {
            throw new ImportFailed($isFolder
                ? 'There are no audio files in this folder (Novarr plays ' . implode(', ', array_map(fn($e) => '.' . $e, array_keys(AudiobookLibrary::FORMATS))) . ').'
                : 'This is not an audio file Novarr can play (' . implode(', ', array_map(fn($e) => '.' . $e, array_keys(AudiobookLibrary::FORMATS))) . ').');
        }
        if (count($files) > 65535) {
            throw new ImportFailed('There are too many audio files in this folder (' . count($files) . ').');
        }

        // 1. Probe every part.
        $probes = [];
        foreach ($files as $i => $file) {
            try {
                $probes[$i] = $this->probe->probe($file);
            } catch (ProbeFailed $e) {
                $who = $isFolder ? '“' . $entry['audio'][$i] . '”' : 'The file';
                throw new ImportFailed("{$who} could not be read: " . rtrim($e->getMessage(), '.') . '.');
            }
        }

        // 2. Seen before?
        $fingerprint = $this->fingerprint($files);
        $existing = Audiobook::withTrashed()->where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            throw new ImportFailed($existing->trashed()
                ? "These files were imported before as “{$existing->title}” (#{$existing->id}), which was removed from the library."
                : "This audiobook is already in the library as “{$existing->title}” (#{$existing->id}).");
        }

        $meta = $this->metadata($entry, $probes);

        // 3–4. Rows, cover and files.
        $moved = [];
        try {
            $audiobook = DB::transaction(function () use ($entry, $files, $probes, $fingerprint, $meta, $isFolder, $base, &$moved) {
                $audiobook = Audiobook::create([
                    'title' => mb_substr($meta['title'], 0, 255),
                    'author' => $meta['author'] !== null ? mb_substr($meta['author'], 0, 255) : null,
                    'narrator' => $meta['narrator'] !== null ? mb_substr($meta['narrator'], 0, 255) : null,
                    'description' => $meta['description'],
                    'year' => $meta['year'],
                    'duration_ms' => array_sum(array_column($probes, 'duration_ms')),
                    'size_bytes' => (int) array_sum(array_map(fn($f) => (int) @filesize($f), $files)),
                    'dir' => '',
                    'status' => Audiobook::STATUS_UNMATCHED,
                    'source_name' => mb_substr($entry['name'], 0, 255),
                    'fingerprint' => $fingerprint,
                    'added_at' => now(),
                ]);
                $audiobook->forceFill(['dir' => (string) $audiobook->id])->save();

                $target = $audiobook->directory();
                if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("cannot make the folder {$target}");
                }

                // The cover is read before the files move (the folder's picture moves too).
                $cover = $this->storeCover($audiobook, $files, $probes, $isFolder ? $base : null, $entry['others']);
                if ($cover !== null) {
                    $audiobook->forceFill(['cover_path' => $cover])->save();
                }

                $start = 0;
                foreach ($files as $i => $file) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $name = sprintf('part-%03d.%s', $i + 1, $ext);
                    $this->move($file, $target . '/' . $name);
                    $moved[] = [$file, $target . '/' . $name];

                    AudiobookPart::create([
                        'audiobook_id' => $audiobook->id,
                        'idx' => $i,
                        'path' => $name,
                        'mime' => AudiobookLibrary::mimeFor($name),
                        'duration_ms' => $probes[$i]['duration_ms'],
                        'size_bytes' => (int) @filesize($target . '/' . $name),
                        'start_ms' => $start,
                    ]);
                    $start += $probes[$i]['duration_ms'];
                }

                foreach ($this->chapters($entry, $probes) as $idx => $chapter) {
                    AudiobookChapter::create(['audiobook_id' => $audiobook->id, 'idx' => $idx] + $chapter);
                }

                // Everything else in the folder goes along (nothing is left behind or deleted).
                if ($isFolder) {
                    foreach ($entry['others'] as $rel) {
                        $to = $target . '/extras/' . $rel;
                        if (!is_dir(dirname($to))) {
                            @mkdir(dirname($to), 0775, true);
                        }
                        $this->move($base . '/' . $rel, $to);
                        $moved[] = [$base . '/' . $rel, $to];
                    }
                }

                return $audiobook;
            });
        } catch (\Throwable $e) {
            // Put back what was moved: the entry goes to _failed whole.
            foreach (array_reverse($moved) as [$from, $to]) {
                if (!is_dir(dirname($from))) {
                    @mkdir(dirname($from), 0775, true);
                }
                @rename($to, $from);
            }
            Log::warning("Audiobooks: importing {$entry['name']} failed: " . $e->getMessage());
            if ($e instanceof ImportFailed) {
                throw $e;
            }

            throw new ImportFailed('The files could not be moved into the audiobooks library (' . $e->getMessage() . '). Check that ' . AudiobookLibrary::root() . ' is writable.');
        }

        if ($isFolder) {
            $this->removeEmptyFolders($base);
        }

        // 5. Its book.
        return $this->matcher->autoMatch($audiobook->fresh(), [
            'title' => $meta['raw_title'],
            'author' => $meta['author'],
            'source_name' => $entry['name'],
        ]);
    }

    /**
     * sha1 over each part's first MB and size, in order: the same files
     * dropped in again (even renamed) give the same fingerprint.
     *
     * @param  list<string>  $files
     */
    public function fingerprint(array $files): string
    {
        $pieces = [];
        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');
            $head = $handle ? (string) fread($handle, self::FINGERPRINT_BYTES) : '';
            if ($handle) {
                fclose($handle);
            }
            $pieces[] = sha1($head) . ':' . (int) @filesize($file);
        }

        return sha1(implode('|', $pieces));
    }

    /**
     * Title, author, narrator, description and year from the first part's
     * tags (the folder / file name when they say nothing).
     *
     * @return array{title: string, raw_title: string, author: ?string, narrator: ?string, description: ?string, year: ?int}
     */
    public function metadata(array $entry, array $probes): array
    {
        $tags = $probes[0]['tags'] ?? [];
        $tag = fn(string ...$keys) => collect($keys)->map(fn($k) => $tags[$k] ?? null)->first(fn($v) => is_string($v) && trim($v) !== '');

        $name = $entry['kind'] === 'file' ? pathinfo($entry['name'], PATHINFO_FILENAME) : $entry['name'];
        $single = count($probes) === 1;
        // A folder of files: their title tags are the chapters', the album is the book.
        $raw = $tag('album') ?? ($single ? $tag('title') : null);
        $author = $tag('album_artist', 'artist', 'author');
        if ($raw === null) {
            $raw = str_replace('_', ' ', $name);
            // No tags at all: "Author - Title" is the usual way to name one.
            $pieces = preg_split('/\s+[-–—]\s+/u', $raw) ?: [];
            if ($author === null && count($pieces) === 2 && trim($pieces[0]) !== '' && trim($pieces[1]) !== '') {
                [$author, $raw] = [trim($pieces[0]), trim($pieces[1])];
            }
        }
        $title = AudiobookMatcher::cleanTitle($raw);

        $narrator = $tag('narrator', 'narrated_by', 'composer', 'performer');
        $comment = $tag('description', 'synopsis', 'comment', 'lyrics');
        if ($narrator === null && $comment !== null && preg_match('/\b(?:read|narrated)\s+by\s+([^.\n]{3,80})/iu', $comment, $m)) {
            $narrator = trim($m[1]);
        }

        $year = null;
        if (preg_match('/\b(1[5-9]\d\d|2\d\d\d)\b/', (string) $tag('date', 'year', 'originaldate', 'tdrc'), $m)) {
            $year = (int) $m[1];
        }

        return [
            'title' => $title !== '' ? $title : trim($raw),
            'raw_title' => trim($raw),
            'author' => $author,
            'narrator' => $narrator,
            'description' => $comment !== null && mb_strlen($comment) >= 20 ? $comment : null,
            'year' => $year,
        ];
    }

    /**
     * Chapters in whole-book positions.
     *
     * @return list<array{title: string, start_ms: int, end_ms: int}>
     */
    public function chapters(array $entry, array $probes): array
    {
        $out = [];
        $start = 0;
        $embedded = collect($probes)->contains(fn($p) => count($p['chapters'] ?? []) > 0);
        $single = count($probes) === 1;

        foreach ($probes as $i => $probe) {
            $duration = (int) $probe['duration_ms'];
            $end = $start + $duration;
            $partTitle = $this->partTitle($entry, $i, $probe);

            if ($embedded && count($probe['chapters']) > 0) {
                foreach ($probe['chapters'] as $c) {
                    $cStart = $start + min($duration, max(0, (int) $c['start_ms']));
                    $cEnd = $start + min($duration, max(0, (int) $c['end_ms']));
                    if ($cEnd <= $cStart) {
                        continue;
                    }
                    $out[] = [
                        'title' => mb_substr($c['title'] ?? ('Chapter ' . (count($out) + 1)), 0, 255),
                        'start_ms' => $cStart,
                        'end_ms' => $cEnd,
                    ];
                }
            } else {
                $out[] = [
                    'title' => mb_substr($single ? 'Part 1' : $partTitle, 0, 255),
                    'start_ms' => $start,
                    'end_ms' => $end,
                ];
            }
            $start = $end;
        }

        // Chapters that leave a gap or overlap (rounding, odd files): each
        // ends where the next begins, the last at the end of the book.
        usort($out, fn($a, $b) => $a['start_ms'] <=> $b['start_ms']);
        for ($i = 0; $i < count($out) - 1; $i++) {
            $out[$i]['end_ms'] = $out[$i + 1]['start_ms'];
        }
        if ($out !== []) {
            $out[count($out) - 1]['end_ms'] = $start;
        }
        if ($out !== [] && $out[0]['start_ms'] > 0) {
            $out[0]['start_ms'] = 0;
        }

        return array_values(array_filter($out, fn($c) => $c['end_ms'] > $c['start_ms']));
    }

    /** A file's chapter title: its title tag (unless it is the book's), else its name. */
    private function partTitle(array $entry, int $i, array $probe): string
    {
        $title = trim((string) ($probe['tags']['title'] ?? ''));
        $album = trim((string) ($probe['tags']['album'] ?? ''));
        if ($title !== '' && mb_strtolower($title) !== mb_strtolower($album)) {
            return $title;
        }
        $rel = $entry['audio'][$i] ?? '';
        $name = pathinfo($rel === '' ? $entry['name'] : $rel, PATHINFO_FILENAME);
        $name = trim(str_replace('_', ' ', $name));

        return $name !== '' ? $name : 'Part ' . ($i + 1);
    }

    /**
     * The cover on the public disk ("ab{hash}.jpg"), or null: the first
     * embedded picture, else the folder's cover image.
     */
    private function storeCover(Audiobook $audiobook, array $files, array $probes, ?string $folder, array $others): ?string
    {
        $bytes = null;
        $ext = 'jpg';

        foreach ($files as $i => $file) {
            if (!($probes[$i]['has_cover'] ?? false)) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'nvab') . '.jpg';
            try {
                if ($this->probe->extractCover($file, $tmp)) {
                    $bytes = (string) file_get_contents($tmp);
                }
            } finally {
                @unlink($tmp);
                @unlink(substr($tmp, 0, -4));
            }
            if ($bytes !== null && $bytes !== '') {
                break;
            }
        }

        if (($bytes === null || $bytes === '') && $folder !== null) {
            $image = $this->folderImage($others);
            if ($image !== null) {
                $bytes = (string) @file_get_contents($folder . '/' . $image);
                $ext = strtolower(pathinfo($image, PATHINFO_EXTENSION)) === 'png' ? 'png' : (strtolower(pathinfo($image, PATHINFO_EXTENSION)) === 'webp' ? 'webp' : 'jpg');
            }
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $name = 'ab' . md5($audiobook->id . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX)) . '.' . $ext;
        try {
            Storage::disk('public')->put($name, $bytes);
            $this->thumbnails->generate($name);
        } catch (\Throwable $e) {
            Log::warning("Audiobook {$audiobook->id}: the cover could not be stored: " . $e->getMessage());

            return null;
        }

        return $name;
    }

    /** cover.jpg, folder.jpg … (COVER_NAMES order), else the folder's only picture. */
    private function folderImage(array $others): ?string
    {
        $images = array_values(array_filter($others, fn($rel) => !str_starts_with(basename($rel), '.')
            && in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), AudiobookLibrary::IMAGE_EXTENSIONS, true)));
        foreach (AudiobookLibrary::COVER_NAMES as $wanted) {
            foreach ($images as $rel) {
                if (strtolower(pathinfo($rel, PATHINFO_FILENAME)) === $wanted) {
                    return $rel;
                }
            }
        }

        return count($images) === 1 ? $images[0] : null;
    }

    /** rename(), which copies across disks for a file; a failure throws. */
    private function move(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            if (!@copy($from, $to) || !@unlink($from)) {
                throw new \RuntimeException('cannot move ' . basename($from));
            }
        }
    }

    /** Remove the entry's folders that the move left empty (deepest first). */
    private function removeEmptyFolders(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $dirs = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    $dirs[] = $item->getPathname();
                }
            }
        } catch (\Throwable) {
            return;
        }
        foreach ($dirs as $dir) {
            @rmdir($dir); // only succeeds when empty
        }
        @rmdir($root);
    }
}
