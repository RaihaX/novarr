<?php

namespace App\Audiobooks;

use App\Audiobook;
use App\AudiobookUpload;
use App\Books\BookLibrary;
use App\Http\Controllers\DownloadController;
use App\Jobs\ProcessAudiobookUpload;
use App\Mail\AdminAlertMail;
use App\Mail\AudiobookUploadAvailable;
use App\Mail\AudiobookUploadDeclined;
use App\Novel;
use App\Services\AdminAlert;
use App\Services\Requests\RequestNotifier;
use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Audiobooks readers upload, through the owner's approval
 * (docs/plans/audiobook-uploads.md):
 *
 *   1. create()    the reader names the files and their sizes: every limit
 *                  is checked here (per file, in all, how many, the kind of
 *                  file, the reader's open uploads, free space on the drive)
 *                  and the folder {audiobooks root}/_uploads/{id}/ is made.
 *   2. putChunk()  each chunk (chunk_size bytes, the last one shorter) is
 *                  written at its own offset of parts/{index}.part (fseek +
 *                  fwrite, so parallel PUTs never touch each other's bytes)
 *                  and recorded by a marker file chunks/{index}/{n}, created
 *                  exclusively: re-sending a chunk rewrites the same bytes
 *                  and counts once. The markers are the resume state.
 *   3. complete()  every chunk in: the files are checked (process()):
 *                  SHA-256 (against the client's when it sent one), moved to
 *                  book/{clean name}, probed (ffprobe through AudioProbe;
 *                  not audio = failed, files deleted), cover, chapters,
 *                  fingerprint, the suggested book. Small uploads are
 *                  checked during the call; larger ones (over inline_mb) by
 *                  a queued job (ProcessAudiobookUpload) while the client
 *                  polls (status "processing").
 *   4. pending     the owner is told (AdminAlert: email + webhook) and it
 *                  shows on Downloads → Needs you.
 *   5. approve()   the files are imported by AudiobookImporter (moved, not
 *                  copied) and linked to the owner's choice of book (or
 *                  standalone); the reader is emailed "in the library".
 *      decline()   files deleted, the reader emailed the reason.
 *
 * File names from the client are display only: on disk a file is
 * parts/{index}.part, then book/{a cleaned, flattened name} — never a path
 * the client chose.
 */
class AudiobookUploads
{
    /** Folder images (cover.jpg) may come along with the audio (the cover when the files carry none). */
    public const IMAGE_MAX_BYTES = 20 * 1048576;

    public const MAX_IMAGES = 5;

    /** Chapters kept in `probe` for the review page. */
    public const KEEP_CHAPTERS = 500;

    /** Public-disk folder of an upload's cover (the review page, the owner's email). */
    public const COVER_DIR = 'audiobook-uploads';

    /** Tests replace the free-space reading (bytes, or null when unknown). */
    public static ?\Closure $freeSpaceUsing = null;

    public function __construct(
        private AudioProbe $probe,
        private AudiobookImporter $importer,
        private AudiobookMatcher $matcher,
    ) {
    }

    // --- Limits ---------------------------------------------------------------

    public static function enabled(): bool
    {
        return (bool) config('novarr.audiobooks.uploads.enabled', true);
    }

    /** Bytes per chunk: chunk_mb (default 8 MB), at most 32 MB (nginx takes 50 MB a request, the tunnel 100 MB). */
    public static function chunkSize(): int
    {
        $mb = (float) config('novarr.audiobooks.uploads.chunk_mb', 8);
        $bytes = (int) round($mb * 1048576);

        return $bytes > 0 ? min($bytes, 32 * 1048576) : 8 * 1048576;
    }

    /** @return array{max_file: int, max_total: int, max_files: int, max_pending: int, min_free: int, inline: int} bytes / counts */
    public static function limits(): array
    {
        $c = (array) config('novarr.audiobooks.uploads', []);

        return [
            'max_file' => (int) (max(1, (int) ($c['max_file_mb'] ?? 3072)) * 1048576),
            'max_total' => (int) (max(1, (int) ($c['max_total_mb'] ?? 6144)) * 1048576),
            'max_files' => max(1, (int) ($c['max_files'] ?? 200)),
            'max_pending' => max(1, (int) ($c['max_pending_per_reader'] ?? 5)),
            'min_free' => (int) round(max(0, (float) ($c['min_free_gb'] ?? 5)) * 1073741824),
            'inline' => (int) (max(0, (int) ($c['inline_mb'] ?? 256)) * 1048576),
        ];
    }

    /** Free bytes on the drive the uploads land on (the audiobooks library's), or null when it cannot be read. */
    public static function freeBytes(): ?float
    {
        if (static::$freeSpaceUsing !== null) {
            return (static::$freeSpaceUsing)();
        }
        // The nearest folder that exists (the uploads folder is made on first use).
        $dir = AudiobookUpload::uploadsRoot();
        while ($dir !== '' && $dir !== '/' && !is_dir($dir)) {
            $dir = dirname($dir);
        }
        $free = @disk_free_space($dir === '' ? '/' : $dir);

        return $free === false ? null : (float) $free;
    }

    // --- 1. Create ------------------------------------------------------------

    /**
     * @param  list<array{name?: mixed, size?: mixed, sha256?: mixed}>  $files
     *
     * @throws UploadRefused
     */
    public function create(User $user, array $files, ?int $novelId = null, ?string $note = null): AudiobookUpload
    {
        $limits = static::limits();
        if ($files === []) {
            throw new UploadRefused(422, 'Choose the audiobook’s files to upload.', 'no_files');
        }
        if (count($files) > $limits['max_files']) {
            throw new UploadRefused(422, "Up to {$limits['max_files']} files in one upload (this one has " . count($files) . ').', 'too_many_files');
        }

        $clean = [];
        $audio = 0;
        $images = 0;
        $total = 0;
        foreach (array_values($files) as $i => $file) {
            $raw = is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '';
            $size = is_array($file) && is_numeric($file['size'] ?? null) ? (int) $file['size'] : -1;
            $name = static::cleanName($raw);
            $base = basename($name);
            if ($name === '' || $size <= 0) {
                throw new UploadRefused(422, 'Each file needs a name and its size in bytes (file ' . ($i + 1) . ').', 'bad_file');
            }
            $isAudio = AudiobookLibrary::isAudio($base);
            $isImage = !$isAudio && in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), AudiobookLibrary::IMAGE_EXTENSIONS, true);
            if (!$isAudio && !$isImage) {
                throw new UploadRefused(422, "“{$base}” is not an audio file Novarr can play (" . static::formatsList() . ').', 'bad_type', ['file' => $i]);
            }
            if ($isImage && ($size > self::IMAGE_MAX_BYTES || ++$images > self::MAX_IMAGES)) {
                throw new UploadRefused(422, "“{$base}”: only a few small pictures (the cover) can come with an audiobook.", 'bad_type', ['file' => $i]);
            }
            if ($size > $limits['max_file']) {
                throw new UploadRefused(422, "“{$base}” is larger than " . static::human($limits['max_file']) . ', the most one file can be.', 'file_too_big', ['file' => $i]);
            }
            $audio += $isAudio ? 1 : 0;
            $total += $size;
            $sha = is_array($file) ? static::cleanSha($file['sha256'] ?? null) : null;
            $clean[] = ['name' => $name, 'size' => $size, 'audio' => $isAudio, 'client_sha256' => $sha];
        }
        if ($audio === 0) {
            throw new UploadRefused(422, 'There is no audio file in this upload (' . static::formatsList() . ').', 'bad_type');
        }
        if ($total > $limits['max_total']) {
            throw new UploadRefused(422, 'These files are ' . static::human($total) . ' together: one upload can be at most ' . static::human($limits['max_total']) . '.', 'too_big');
        }

        $open = AudiobookUpload::where('user_id', $user->id)->whereIn('status', AudiobookUpload::OPEN_STATUSES)->count();
        if ($open >= $limits['max_pending']) {
            throw new UploadRefused(422, "You have {$open} audiobook uploads waiting. Wait for the owner to decide on them before sending more.", 'too_many_pending', ['limit' => $limits['max_pending']]);
        }

        if ($novelId !== null) {
            $book = Novel::find($novelId);
            if ($book === null || !$book->hasLibraryFile()) {
                throw new UploadRefused(422, 'That is not a book in the library.', 'bad_book');
            }
        }

        // The drive must hold this upload, the other uploads still arriving, and the margin.
        $free = static::freeBytes();
        if ($free !== null) {
            $owed = (int) AudiobookUpload::where('status', AudiobookUpload::STATUS_UPLOADING)->sum(DB::raw('total_bytes - received_bytes'));
            if ($free - $owed < $total + $limits['min_free']) {
                Log::warning('Audiobook upload refused: not enough free space', ['free' => $free, 'owed' => $owed, 'total' => $total]);
                throw new UploadRefused(507, 'There is not enough free space on the server for this audiobook right now. Tell the owner, or try again later.', 'no_space');
            }
        }

        $clean = static::diskNames($clean);
        $chunk = static::chunkSize();
        foreach ($clean as $i => $f) {
            $clean[$i]['chunks'] = (int) ceil($f['size'] / $chunk);
        }

        $upload = AudiobookUpload::create([
            'user_id' => (int) $user->id,
            'novel_id' => $novelId,
            'status' => AudiobookUpload::STATUS_UPLOADING,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null,
            'chunk_size' => $chunk,
            'total_bytes' => $total,
            'received_bytes' => 0,
            'files' => $clean,
        ]);
        $upload->forceFill(['dir' => AudiobookUpload::UPLOADS_DIR . '/' . $upload->id])->save();

        $dir = $upload->directory();
        foreach (['parts', 'chunks', 'book'] as $sub) {
            if (!is_dir("{$dir}/{$sub}") && !@mkdir("{$dir}/{$sub}", 0775, true) && !is_dir("{$dir}/{$sub}")) {
                $upload->delete();
                Log::error("Audiobook upload: cannot make {$dir}/{$sub}");
                throw new UploadRefused(500, 'The server could not make room for the upload. Tell the owner.', 'storage_error');
            }
        }
        Log::info('Audiobook upload started', ['upload' => $upload->id, 'user' => $user->id, 'files' => count($clean), 'bytes' => $total]);

        return $upload;
    }

    // --- 2. Chunks ------------------------------------------------------------

    /** Bytes of chunk $n of file $index (the last one is shorter). */
    public static function chunkLength(AudiobookUpload $upload, int $index, int $n): int
    {
        $file = (array) ($upload->files[$index] ?? []);
        $size = (int) ($file['size'] ?? 0);
        $offset = $n * (int) $upload->chunk_size;

        return max(0, min((int) $upload->chunk_size, $size - $offset));
    }

    /**
     * Write chunk $n of file $index from $body (a stream or a string).
     * Idempotent: the same chunk again is written again and counted once.
     *
     * @param  resource|string  $body
     *
     * @throws UploadRefused
     */
    public function putChunk(AudiobookUpload $upload, int $index, int $n, $body, ?string $contentRange = null): void
    {
        if ($upload->status !== AudiobookUpload::STATUS_UPLOADING) {
            throw new UploadRefused(409, 'This upload is not taking files any more (' . strtolower($upload->statusLabel()) . ').', 'not_uploading', ['status' => $upload->status]);
        }
        $files = (array) $upload->files;
        if (!isset($files[$index])) {
            throw new UploadRefused(404, 'This upload has no such file.', 'bad_file');
        }
        $chunks = (int) $files[$index]['chunks'];
        if ($n < 0 || $n >= $chunks) {
            throw new UploadRefused(422, "This file has {$chunks} " . ($chunks === 1 ? 'chunk' : 'chunks') . " (0 to " . ($chunks - 1) . "): there is no chunk {$n}.", 'bad_chunk');
        }
        $expected = static::chunkLength($upload, $index, $n);
        $offset = $n * (int) $upload->chunk_size;

        // Content-Range (optional) must name exactly this chunk.
        if ($contentRange !== null && trim($contentRange) !== '') {
            if (!preg_match('#^bytes\s+(\d+)-(\d+)/(\d+|\*)$#i', trim($contentRange), $m)
                || (int) $m[1] !== $offset || (int) $m[2] !== $offset + $expected - 1
                || ($m[3] !== '*' && (int) $m[3] !== (int) $files[$index]['size'])) {
                throw new UploadRefused(422, "Content-Range does not match chunk {$n}: expected bytes {$offset}-" . ($offset + $expected - 1) . '/' . $files[$index]['size'] . '.', 'bad_range');
            }
        }

        // Never trust the client's length: read one byte more than the chunk may be.
        if (is_resource($body)) {
            $data = (string) stream_get_contents($body, $expected + 1);
        } else {
            $data = substr((string) $body, 0, $expected + 1);
        }
        if (strlen($data) !== $expected) {
            throw new UploadRefused(422, "Chunk {$n} should be {$expected} bytes; " . (strlen($data) > $expected ? 'more' : strlen($data)) . ' arrived.', 'chunk_size', ['expected' => $expected]);
        }

        $dir = $upload->directory();
        $part = "{$dir}/parts/{$index}.part";
        $handle = @fopen($part, 'c+b'); // created when missing, never truncated
        if ($handle === false) {
            throw new UploadRefused(is_dir($dir) ? 500 : 409, is_dir($dir) ? 'The server could not store this chunk. Try again.' : 'This upload was cancelled.', is_dir($dir) ? 'storage_error' : 'not_uploading');
        }
        try {
            if (fseek($handle, $offset) !== 0) {
                throw new UploadRefused(500, 'The server could not store this chunk. Try again.', 'storage_error');
            }
            $written = 0;
            while ($written < $expected) {
                $w = fwrite($handle, $written === 0 ? $data : substr($data, $written));
                if ($w === false || $w === 0) {
                    throw new UploadRefused(500, 'The server could not store this chunk (is the disk full?). Try again.', 'storage_error');
                }
                $written += $w;
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }

        // The marker is made once (exclusive create): only its maker counts the bytes.
        $markerDir = "{$dir}/chunks/{$index}";
        if (!is_dir($markerDir)) {
            @mkdir($markerDir, 0775, true);
        }
        $marker = @fopen("{$markerDir}/{$n}", 'x');
        if ($marker !== false) {
            fwrite($marker, (string) $expected);
            fclose($marker);
            AudiobookUpload::whereKey($upload->id)->update([
                'received_bytes' => DB::raw('received_bytes + ' . (int) $expected),
                'updated_at' => now(),
            ]);
        } elseif (!is_file("{$markerDir}/{$n}")) {
            throw new UploadRefused(500, 'The server could not record this chunk. Try again.', 'storage_error');
        }
    }

    /**
     * Which chunks of each file are in (from the markers).
     *
     * @return array<int, list<int>>  file index => chunk numbers, in order
     */
    public function received(AudiobookUpload $upload): array
    {
        $out = [];
        foreach ((array) $upload->files as $index => $file) {
            $have = [];
            if (!empty($file['done'])) {
                $have = range(0, max(0, (int) $file['chunks'] - 1));
            } else {
                $dir = $upload->directory() . "/chunks/{$index}";
                foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
                    if (ctype_digit($name) && (int) $name < (int) $file['chunks']) {
                        $have[] = (int) $name;
                    }
                }
                sort($have);
            }
            $out[(int) $index] = $have;
        }

        return $out;
    }

    // --- 3. Complete and check ---------------------------------------------------

    /**
     * All chunks are in: check the files (now, or by a queued job for a
     * large upload). Returns the status after the call: pending, failed,
     * processing (the job is still checking), or uploading again (a file
     * arrived damaged and must be sent again: `error` says which).
     *
     * @param  array<int, string>  $hashes  file index => the client's SHA-256 (optional)
     *
     * @throws UploadRefused  not every chunk is in / already decided
     */
    public function complete(AudiobookUpload $upload, array $hashes = []): string
    {
        if (in_array($upload->status, [AudiobookUpload::STATUS_PROCESSING, AudiobookUpload::STATUS_PENDING, AudiobookUpload::STATUS_APPROVED], true)) {
            return $upload->status; // already done: completing again is harmless
        }
        if ($upload->status !== AudiobookUpload::STATUS_UPLOADING) {
            throw new UploadRefused(409, 'This upload was ' . strtolower($upload->statusLabel()) . '.', 'not_uploading', ['status' => $upload->status]);
        }

        $files = (array) $upload->files;
        $missing = [];
        $received = $this->received($upload);
        foreach ($files as $index => $file) {
            $gap = array_values(array_diff(range(0, (int) $file['chunks'] - 1), $received[$index] ?? []));
            if ($gap !== []) {
                $missing[(string) $index] = $gap;
            }
        }
        if ($missing !== []) {
            $count = array_sum(array_map('count', $missing));
            throw new UploadRefused(422, "{$count} " . ($count === 1 ? 'chunk has' : 'chunks have') . ' not arrived yet.', 'incomplete', ['missing' => (object) $missing]);
        }
        // Every chunk is in: the assembled file is exactly its size.
        foreach ($files as $index => $file) {
            $part = $upload->directory() . "/parts/{$index}.part";
            clearstatcache(true, $part);
            if (!is_file($part) || filesize($part) !== (int) $file['size']) {
                $this->resetFile($upload, (int) $index, 'arrived incomplete');
                throw new UploadRefused(422, '“' . basename($file['name']) . '” did not arrive in one piece: send it again.', 'incomplete', ['missing' => (object) [(string) $index => range(0, (int) $file['chunks'] - 1)]]);
            }
        }

        foreach ($hashes as $index => $sha) {
            $sha = static::cleanSha($sha);
            if ($sha !== null && isset($files[$index])) {
                $files[$index]['client_sha256'] = $sha;
            }
        }

        // Exactly one caller moves it on (a second /complete sees processing).
        $moved = AudiobookUpload::whereKey($upload->id)->where('status', AudiobookUpload::STATUS_UPLOADING)
            ->update(['status' => AudiobookUpload::STATUS_PROCESSING, 'files' => json_encode($files), 'error' => null, 'updated_at' => now()]);
        $upload->refresh();
        if ($moved === 0) {
            return $upload->status;
        }

        if ((int) $upload->total_bytes <= static::limits()['inline']) {
            $this->process($upload);
        } else {
            ProcessAudiobookUpload::dispatch((int) $upload->id);
        }

        return (string) (AudiobookUpload::find($upload->id)?->status ?? AudiobookUpload::STATUS_FAILED);
    }

    /** Check an upload in `processing` (complete() or ProcessAudiobookUpload). Never throws. */
    public function process(AudiobookUpload $upload): void
    {
        $upload->refresh();
        if ($upload->status !== AudiobookUpload::STATUS_PROCESSING) {
            return;
        }
        @set_time_limit(0);
        $dir = $upload->directory();
        $files = (array) $upload->files;

        try {
            // SHA-256 of each file, against the client's when it sent one.
            $damaged = [];
            foreach ($files as $index => $file) {
                $part = "{$dir}/parts/{$index}.part";
                $sha = @hash_file('sha256', $part);
                if ($sha === false) {
                    throw new \RuntimeException('cannot read ' . basename((string) $file['name']));
                }
                $files[$index]['sha256'] = $sha;
                if (!empty($file['client_sha256']) && !hash_equals((string) $file['client_sha256'], $sha)) {
                    $damaged[] = (int) $index;
                }
            }
            if ($damaged !== []) {
                $upload->forceFill(['files' => $files])->save();
                $names = [];
                foreach ($damaged as $index) {
                    $this->resetFile($upload, $index, 'arrived damaged');
                    $names[] = '“' . basename((string) $files[$index]['name']) . '”';
                }
                $upload->refresh()->forceFill([
                    'status' => AudiobookUpload::STATUS_UPLOADING,
                    'error' => implode(', ', $names) . ' arrived damaged (the checksum does not match): send ' . (count($names) === 1 ? 'it' : 'them') . ' again.',
                ])->save();

                return;
            }

            // Into book/ under their clean names; then the markers are no longer needed.
            foreach ($files as $index => $file) {
                $to = "{$dir}/book/{$file['disk_name']}";
                if (!@rename("{$dir}/parts/{$index}.part", $to)) {
                    throw new \RuntimeException('cannot move ' . basename((string) $file['name']));
                }
                $files[$index]['path'] = 'book/' . $file['disk_name'];
                $files[$index]['done'] = true;
            }
            File::deleteDirectory("{$dir}/chunks");
            @rmdir("{$dir}/parts");
            $upload->forceFill(['files' => $files])->save();

            $entry = $this->entry($upload);
            $paths = array_map(fn(string $rel) => $rel === '' ? $entry['path'] : $entry['path'] . '/' . $rel, $entry['audio']);

            // Is it audio? Every file must be.
            $probes = [];
            foreach ($paths as $i => $path) {
                try {
                    $probes[$i] = $this->probe->probe($path);
                } catch (ProbeFailed $e) {
                    $who = '“' . ($entry['kind'] === 'folder' ? $entry['audio'][$i] : $entry['name']) . '”';
                    $this->fail($upload, "{$who} is not audio Novarr can play: " . rtrim($e->getMessage(), '.') . '.');

                    return;
                }
            }

            $meta = $this->importer->metadata($entry, $probes);
            $chapters = $this->importer->chapters($entry, $probes);
            $fingerprint = $this->importer->fingerprint($paths);
            $duplicate = Audiobook::withTrashed()->where('fingerprint', $fingerprint)->first();
            $cover = $this->storeCover($upload, $entry, $paths, $probes);

            $tags = $probes[0]['tags'] ?? [];
            $probe = [
                'title' => $meta['title'],
                'raw_title' => $meta['raw_title'],
                'author' => $meta['author'],
                'narrator' => $meta['narrator'],
                'description' => $meta['description'],
                'year' => $meta['year'],
                'album' => $tags['album'] ?? null,
                'duration_ms' => (int) array_sum(array_column($probes, 'duration_ms')),
                'parts' => count($probes),
                'bitrate' => $probes[0]['bitrate'] ?? null,
                'chapter_count' => count($chapters),
                'chapters' => array_slice($chapters, 0, self::KEEP_CHAPTERS),
                'embedded_chapters' => AudiobookImporter::hasEmbeddedChapters($probes),
                'cover_path' => $cover,
                'duplicate_of' => $duplicate?->id,
                'suggested_novel_id' => $this->suggest($upload, $meta, $entry),
            ];

            $upload->forceFill([
                'status' => AudiobookUpload::STATUS_PENDING,
                'title' => mb_substr((string) $meta['title'], 0, 255) ?: null,
                'probe' => $probe,
                'fingerprint' => $fingerprint,
                'received_bytes' => (int) $upload->total_bytes,
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error("Audiobook upload {$upload->id}: checking it failed: " . $e->getMessage());
            $this->fail($upload, 'The server could not check the files (' . $e->getMessage() . '). Try uploading again, or tell the owner.');

            return;
        }

        static::forgetNavCount();
        Log::info('Audiobook upload ready for review', ['upload' => $upload->id]);
        $this->tellOwner($upload->fresh());
    }

    /** It failed (not audio, could not be checked): status failed with the reason, files deleted. */
    public function fail(AudiobookUpload $upload, string $reason): void
    {
        $this->removeFiles($upload);
        $upload->forceFill([
            'status' => AudiobookUpload::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 1000),
        ])->save();
        Log::info("Audiobook upload {$upload->id} failed: {$reason}");
    }

    /** The importer's entry (AudiobookInbox::entries() shape) for an upload's assembled files. */
    public function entry(AudiobookUpload $upload): array
    {
        $base = $upload->directory() . '/book';
        $audio = [];
        $others = [];
        $names = [];
        foreach ((array) $upload->files as $file) {
            if (!empty($file['audio'])) {
                $audio[] = $file;
            } else {
                $others[] = (string) $file['disk_name'];
            }
            $names[] = (string) $file['name'];
        }
        // Playing order: the reader's names, naturally ("CD1/2.mp3" before "CD1/10.mp3").
        usort($audio, fn($a, $b) => strnatcasecmp((string) $a['name'], (string) $b['name']));

        if (count($audio) === 1 && $others === []) {
            return [
                'name' => basename((string) $audio[0]['name']),
                'path' => $base . '/' . $audio[0]['disk_name'],
                'kind' => 'file',
                'audio' => [''],
                'others' => [],
            ];
        }

        // The folder's name: the one folder every file came from, else the upload's title.
        $tops = array_unique(array_map(fn($n) => str_contains($n, '/') ? strstr($n, '/', true) : '', $names));
        $name = count($tops) === 1 && reset($tops) !== '' ? (string) reset($tops) : (trim((string) $upload->title) ?: pathinfo(basename((string) $audio[0]['name']), PATHINFO_FILENAME));

        return [
            'name' => $name,
            'path' => $base,
            'kind' => 'folder',
            'audio' => array_map(fn($f) => (string) $f['disk_name'], $audio),
            'others' => $others,
        ];
    }

    /** The book it seems to be: the reader's choice, else one the library has by the files' title and author. */
    private function suggest(AudiobookUpload $upload, array $meta, array $entry): ?int
    {
        if ($upload->novel_id) {
            return (int) $upload->novel_id;
        }
        $hints = ['title' => $meta['raw_title'], 'author' => $meta['author'], 'source_name' => $entry['name']];
        // Audible's title / author help when the tags are poor (off unless the source is on).
        try {
            if (AudiobookEnricher::enabled('audible')) {
                $product = app(Audible::class)->find((string) $meta['title'], $meta['author']);
                if ($product !== null) {
                    $hints['catalogue_title'] = $product['title'] ?? null;
                    $hints['catalogue_author'] = implode(', ', (array) ($product['authors'] ?? [])) ?: null;
                }
            }
        } catch (\Throwable $e) {
            Log::info("Audiobook upload {$upload->id}: Audible lookup skipped: " . $e->getMessage());
        }
        $transient = new Audiobook(['title' => $meta['title'], 'author' => $meta['author'], 'source_name' => $entry['name']]);
        foreach ($this->matcher->candidates($transient, $hints) as [$title, $author]) {
            $book = BookLibrary::existing($title, $author);
            if ($book !== null) {
                return (int) $book->id;
            }
        }

        return null;
    }

    /** The cover on the public disk (audiobook-uploads/…): the files' embedded picture, else a picture that came with them. */
    private function storeCover(AudiobookUpload $upload, array $entry, array $paths, array $probes): ?string
    {
        $bytes = null;
        $ext = 'jpg';
        foreach ($paths as $i => $path) {
            if (!($probes[$i]['has_cover'] ?? false)) {
                continue;
            }
            $tmp = $upload->directory() . '/cover-extract.jpg';
            if ($this->probe->extractCover($path, $tmp)) {
                $bytes = (string) @file_get_contents($tmp);
            }
            @unlink($tmp);
            if ($bytes !== '' && $bytes !== null) {
                break;
            }
        }
        if (($bytes === null || $bytes === '') && $entry['kind'] === 'folder' && $entry['others'] !== []) {
            $image = $entry['others'][0];
            foreach (AudiobookLibrary::COVER_NAMES as $wanted) {
                foreach ($entry['others'] as $rel) {
                    if (strtolower(pathinfo($rel, PATHINFO_FILENAME)) === $wanted) {
                        $image = $rel;
                        break 2;
                    }
                }
            }
            $bytes = (string) @file_get_contents($entry['path'] . '/' . $image);
            $ext = in_array(strtolower(pathinfo($image, PATHINFO_EXTENSION)), ['png', 'webp'], true) ? strtolower(pathinfo($image, PATHINFO_EXTENSION)) : 'jpg';
        }
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $name = self::COVER_DIR . '/' . $upload->id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        try {
            Storage::disk('public')->put($name, $bytes);
        } catch (\Throwable $e) {
            Log::warning("Audiobook upload {$upload->id}: the cover could not be stored: " . $e->getMessage());

            return null;
        }

        return $name;
    }

    // --- Cancel, approve, decline ---------------------------------------------------

    /** The reader cancels (or clears) their upload: files and row go. An approved one stays (it is in the library). */
    public function cancel(AudiobookUpload $upload): void
    {
        if ($upload->status === AudiobookUpload::STATUS_APPROVED) {
            throw new UploadRefused(409, 'This audiobook is already in the library.', 'decided');
        }
        if ($upload->status === AudiobookUpload::STATUS_PROCESSING) {
            throw new UploadRefused(409, 'The files are being checked right now: cancel it in a minute.', 'processing');
        }
        $this->removeFiles($upload);
        $upload->delete();
        static::forgetNavCount();
        Log::info("Audiobook upload {$upload->id} cancelled");
    }

    /**
     * The owner approves: imported into the library (files moved), linked
     * to $novelId (null = standalone). $replace: the book's current
     * audiobook is removed from the library (soft delete, files kept) and
     * this one takes its place.
     *
     * @throws UploadRefused
     */
    public function approve(AudiobookUpload $upload, User $by, ?int $novelId, bool $replace = false): Audiobook
    {
        $this->mustBePending($upload);
        $existing = null;
        if ($novelId !== null) {
            $book = Novel::find($novelId);
            if ($book === null || !$book->hasLibraryFile()) {
                throw new UploadRefused(422, 'That is not a book in the library.', 'bad_book');
            }
            $existing = Audiobook::withTrashed()->where('novel_id', $novelId)->first();
            if ($existing !== null && !$replace) {
                throw new UploadRefused(409, "“{$book->name}” already has an audiobook (“{$existing->title}”): use that one, or replace it.", 'book_has_audiobook', ['audiobook_id' => $existing->id]);
            }
        }

        $entry = $this->entry($upload);
        try {
            $audiobook = $this->importer->import($entry, ['copy' => false, 'match' => false]);
        } catch (ImportFailed $e) {
            throw new UploadRefused(422, $e->getMessage(), 'import_failed');
        }

        if ($existing !== null) {
            $existing->forceFill(['novel_id' => null])->save();
            if (!$existing->trashed()) {
                $existing->delete();
            }
            Log::info("Audiobook #{$existing->id} replaced by upload {$upload->id} (#{$audiobook->id})");
        }
        $refused = $this->matcher->match($audiobook, $novelId);
        if ($refused !== null) {
            Log::warning("Audiobook upload {$upload->id}: linking #{$audiobook->id} to book {$novelId} failed: {$refused}");
        }
        $audiobook->refresh();

        $this->decided($upload, $by, AudiobookUpload::STATUS_APPROVED, ['audiobook_id' => $audiobook->id, 'novel_id' => $audiobook->novel_id]);
        $this->tellReaderAvailable($upload->fresh(), $audiobook);

        return $audiobook;
    }

    /** It is an audiobook the library already has: closed as that one (the reader can listen now), the files dropped. */
    public function useExisting(AudiobookUpload $upload, User $by, Audiobook $existing): void
    {
        $this->mustBePending($upload);
        $this->decided($upload, $by, AudiobookUpload::STATUS_APPROVED, ['audiobook_id' => $existing->id, 'novel_id' => $existing->novel_id]);
        $this->tellReaderAvailable($upload->fresh(), $existing);
    }

    public function decline(AudiobookUpload $upload, User $by, ?string $reason): void
    {
        $this->mustBePending($upload, true);
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 1000) : null;
        $this->decided($upload, $by, AudiobookUpload::STATUS_DECLINED, ['decline_reason' => $reason]);
        $this->tellReaderDeclined($upload->fresh());
    }

    private function mustBePending(AudiobookUpload $upload, bool $orFailed = false): void
    {
        $ok = $upload->status === AudiobookUpload::STATUS_PENDING || ($orFailed && $upload->status === AudiobookUpload::STATUS_FAILED);
        if (!$ok) {
            throw new UploadRefused(409, 'This upload is ' . strtolower($upload->statusLabel()) . ', not waiting for you.', 'not_pending', ['status' => $upload->status]);
        }
    }

    private function decided(AudiobookUpload $upload, User $by, string $status, array $fields): void
    {
        $this->removeFiles($upload);
        $upload->forceFill($fields + [
            'status' => $status,
            'decided_by' => (int) $by->id,
            'decided_at' => now(),
            'error' => null,
        ])->save();
        static::forgetNavCount();
    }

    // --- Files ----------------------------------------------------------------------

    /** Delete the upload's folder (only ever under _uploads/) and its cover. */
    public function removeFiles(AudiobookUpload $upload): void
    {
        static::deleteDir($upload->directory());
        $cover = $upload->probe['cover_path'] ?? null;
        if (is_string($cover) && str_starts_with($cover, self::COVER_DIR . '/')) {
            try {
                Storage::disk('public')->delete($cover);
            } catch (\Throwable) {
                // best effort
            }
        }
    }

    /** Remove a folder under the uploads root (never anything outside it). */
    public static function deleteDir(string $dir): bool
    {
        $root = realpath(AudiobookUpload::uploadsRoot());
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return !file_exists($dir);
        }

        return is_dir($real) ? File::deleteDirectory($real) : @unlink($real);
    }

    /** A file to send again: its bytes and markers go, the count drops. */
    private function resetFile(AudiobookUpload $upload, int $index, string $why): void
    {
        $dir = $upload->directory();
        File::deleteDirectory("{$dir}/chunks/{$index}");
        @unlink("{$dir}/parts/{$index}.part");
        $files = (array) $upload->files;
        $files[$index]['done'] = false;
        unset($files[$index]['sha256']);
        $upload->forceFill(['files' => $files])->save();
        $received = 0;
        foreach ($this->received($upload->refresh()) as $i => $have) {
            foreach ($have as $n) {
                $received += static::chunkLength($upload, (int) $i, (int) $n);
            }
        }
        $upload->forceFill(['received_bytes' => $received])->save();
        Log::info("Audiobook upload {$upload->id}: file {$index} {$why}, to be sent again");
    }

    /**
     * The nightly tidy-up (downloads:tidy → App\Downloads\InboxTidy):
     * uploads abandoned while uploading (no chunk for abandoned_hours) go,
     * row and files; declined / failed ones' leftover files go; folders under
     * _uploads that belong to no upload still open go. Pending and
     * processing uploads are never touched.
     *
     * @return list<string>  what was (or would be) deleted
     */
    public function tidy(bool $dryRun = false): array
    {
        $deleted = [];
        $hours = max(1, (int) config('novarr.audiobooks.uploads.abandoned_hours', 48));

        foreach (AudiobookUpload::where('status', AudiobookUpload::STATUS_UPLOADING)->where('updated_at', '<', now()->subHours($hours))->get() as $upload) {
            $deleted[] = "_uploads/{$upload->id} — upload abandoned (nothing sent for {$hours} hours)";
            if (!$dryRun) {
                $this->removeFiles($upload);
                $upload->delete();
            }
        }

        $root = AudiobookUpload::uploadsRoot();
        if (is_dir($root)) {
            $open = AudiobookUpload::whereIn('status', AudiobookUpload::OPEN_STATUSES)->pluck('id')->map(fn($id) => (string) $id)->all();
            foreach (scandir($root) ?: [] as $name) {
                if ($name === '.' || $name === '..' || in_array($name, $open, true)) {
                    continue; // an open upload: never touched
                }
                $upload = ctype_digit($name) ? AudiobookUpload::find((int) $name) : null;
                $why = $upload === null ? 'belongs to no upload' : 'left over from a ' . $upload->status . ' upload';
                $deleted[] = "_uploads/{$name} — {$why}";
                if (!$dryRun) {
                    static::deleteDir("{$root}/{$name}");
                }
            }
        }
        if (!$dryRun && $deleted !== []) {
            static::forgetNavCount();
        }

        return $deleted;
    }

    /** A deleted account's uploads go with it (an approved one's audiobook stays in the library). */
    public static function forgetUser(int $userId): void
    {
        $self = app(self::class);
        foreach (AudiobookUpload::where('user_id', $userId)->get() as $upload) {
            $self->removeFiles($upload);
            $upload->delete();
        }
        static::forgetNavCount();
    }

    // --- Telling people -------------------------------------------------------------

    private function tellOwner(AudiobookUpload $upload): void
    {
        $user = $upload->user;
        if ($user !== null && $user->isAdmin()) {
            return; // the owner's own upload is not news to them
        }
        try {
            [$subject, $message, $url, $details] = static::ownerAlert($upload);
            app(AdminAlert::class)->send($subject, $message, $url, $details);
        } catch (\Throwable $e) {
            Log::warning("Audiobook upload {$upload->id}: telling the owner failed: " . $e->getMessage());
        }
    }

    /**
     * The owner's alert: [subject, message, url, mail details] (also the mail preview's).
     *
     * @return array{0: string, 1: string, 2: string, 3: array}
     */
    public static function ownerAlert(AudiobookUpload $upload): array
    {
        $name = RequestNotifier::nameOf($upload->user);
        $title = $upload->displayTitle();
        $probe = (array) ($upload->probe ?? []);
        $url = RequestNotifier::adminBase() . route('audiobooks.uploads.review', $upload->id, false);
        $parts = (int) ($probe['parts'] ?? count((array) $upload->files));
        $meta = implode(' · ', array_filter([
            'Audiobook',
            $probe['author'] ?? null,
            !empty($probe['duration_ms']) ? AudiobookLibrary::humanDuration((int) $probe['duration_ms']) : null,
            $parts . ' ' . ($parts === 1 ? 'file' : 'files'),
            static::human((int) $upload->total_bytes),
        ]));
        $cover = !empty($probe['cover_path']) ? AdminAlertMail::coverUrl(Storage::disk('public')->url($probe['cover_path'])) : null;
        $book = $upload->novel_id ? Novel::find($upload->novel_id) : null;

        return [
            "Audiobook uploaded: {$title}",
            "{$name} uploaded the audiobook “{$title}”" . ($book ? " for “{$book->name}”" : '') . '. Review it, then add it to the library or decline it.',
            $url,
            [
                'eyebrow' => 'Audiobooks', 'heading' => 'Audiobook uploaded',
                'intro' => "{$name} uploaded an audiobook" . ($book ? " for “{$book->name}”" : '') . '. Listen to a sample, check which book it is, then add it to the library or decline it.',
                'cover' => $cover ?? AdminAlertMail::coverOf($book),
                'title' => $title, 'meta' => $meta, 'chip' => 'Waiting for you',
                'note' => $upload->note ?: null, 'note_label' => $upload->note ? "{$name}’s note" : null,
                'button' => 'Review',
                'secondary' => ['text' => 'Open Downloads', 'url' => RequestNotifier::adminBase() . '/downloads'],
            ],
        ];
    }

    private function tellReaderAvailable(AudiobookUpload $upload, Audiobook $audiobook): void
    {
        $user = $upload->user;
        if ($user === null || $user->isAdmin() || !RequestNotifier::hasEmail($user)) {
            return;
        }
        $audiobook->loadMissing('novel.file');
        $cover = $audiobook->coverUrl('md');
        app(RequestNotifier::class)->sendMail((string) $user->email, new AudiobookUploadAvailable(
            RequestNotifier::nameOf($user),
            $audiobook->displayTitle(),
            \App\Http\Controllers\RequestCoverController::url($cover, (int) $user->id, RequestNotifier::readerBase()),
            RequestNotifier::readerBase() . '/audiobooks/' . (int) $audiobook->id,
        ));
    }

    private function tellReaderDeclined(AudiobookUpload $upload): void
    {
        $user = $upload->user;
        if ($user === null || $user->isAdmin() || !RequestNotifier::hasEmail($user)) {
            return;
        }
        app(RequestNotifier::class)->sendMail((string) $user->email, new AudiobookUploadDeclined(
            RequestNotifier::nameOf($user),
            $upload->displayTitle(),
            null,
            $upload->decline_reason ?: null,
            RequestNotifier::readerBase() . '/requests',
        ));
    }

    public static function forgetNavCount(): void
    {
        Cache::forget(DownloadController::NAV_ATTENTION_CACHE);
    }

    // --- Names ----------------------------------------------------------------------

    /**
     * A client's file name (a relative path from a folder picker) made
     * safe for display and for building a disk name: segments without
     * control characters, reserved characters, leading dots or "..".
     */
    public static function cleanName(string $name): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            $segment = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $segment) ?? '';
            $segment = preg_replace('/[<>:"|?*]+/', '_', $segment) ?? '';
            $segment = trim(preg_replace('/\s+/u', ' ', $segment) ?? '', " .\t");
            if ($segment === '' || $segment === '..') {
                continue;
            }
            $segments[] = mb_substr($segment, -150);
        }
        $segments = array_slice($segments, -4); // a few folders deep is plenty

        return mb_substr(implode('/', $segments), -400);
    }

    /**
     * Each file's name on disk (under book/): its name without the folder
     * every file shares, the rest joined with " - ", unique.
     *
     * @param  list<array{name: string}>  $files
     */
    public static function diskNames(array $files): array
    {
        $segments = array_map(fn($f) => explode('/', $f['name']), $files);
        $firsts = array_unique(array_map(fn($s) => count($s) > 1 ? $s[0] : "\0", $segments));
        $dropFirst = count($firsts) === 1 && reset($firsts) !== "\0";

        $seen = [];
        foreach ($files as $i => $file) {
            $parts = $segments[$i];
            if ($dropFirst) {
                array_shift($parts);
            }
            $name = implode(' - ', $parts);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $stem = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 180);
            $candidate = "{$stem}.{$ext}";
            for ($n = 2; isset($seen[mb_strtolower($candidate)]); $n++) {
                $candidate = "{$stem} ({$n}).{$ext}";
            }
            $seen[mb_strtolower($candidate)] = true;
            $files[$i]['disk_name'] = $candidate;
        }

        return $files;
    }

    public static function cleanSha(mixed $sha): ?string
    {
        $sha = is_string($sha) ? strtolower(trim($sha)) : '';

        return preg_match('/^[0-9a-f]{64}$/', $sha) ? $sha : null;
    }

    public static function formatsList(): string
    {
        return implode(', ', array_map(fn($e) => '.' . $e, array_keys(AudiobookLibrary::FORMATS)));
    }

    /** "1.4 GB", "820 MB". */
    public static function human(int|float $bytes): string
    {
        if ($bytes >= 1073741824) {
            return rtrim(rtrim(number_format($bytes / 1073741824, 1), '0'), '.') . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 0) . ' MB';
        }

        return number_format(max(0, $bytes) / 1024, 0) . ' KB';
    }
}
