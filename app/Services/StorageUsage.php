<?php

namespace App\Services;

use App\Audiobook;
use App\Audiobooks\AudiobookInbox;
use App\ChapterPage;
use App\Console\Commands\GenerateePub;
use App\Downloads\Downloads;
use App\Downloads\Transmission;
use App\Novel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where the disk goes: per series (manga page images, novel chapter text,
 * cover, ePub), per bucket (database, covers, ePubs, backups, logs, failure
 * snapshots, framework cache, other) and per disk volume (total / free).
 *
 * measure() is slow on a big library (it walks every manga page file and
 * runs one aggregate per series), so it only runs from `php artisan
 * storage:measure` (daily, or "Measure now" on Health → Storage); the page
 * reads the stored figures (report()). store() replaces the previous
 * measurement as a whole.
 *
 * Where things live (see also config/filesystems.php):
 *   manga pages   the `manga` disk, {novel_id}/{chapter_id}/{page}.{ext}
 *                 (App\ChapterPage; NOVARR_MANGA_PATH, may be another mount)
 *   novel text    the database: chapter_texts.content (longtext), with a
 *                 stored word_count per chapter
 *   covers        the `public` disk: the original (files.file_path) and two
 *                 thumbnails covers/{sm,md}/{name}.jpg (App\Services\CoverThumbnails)
 *   ePubs         storage/app/ePub/{name - author}.epub (GenerateePub::epubPath);
 *                 build leftovers in storage/app/epub-build and storage/app/Novel
 *   snapshots     config novarr.snapshots.path (storage/app/snapshots)
 *   backups       each local disk named in config backup.backup.destination.disks,
 *                 folder backup.backup.name (storage/app/private/Novarr)
 *   logs          storage/logs; framework cache, sessions, views: storage/framework
 *   audiobooks    config novarr.audiobooks.path/{dir}/part-NNN.ext (the big disk);
 *                 readers' uploads waiting for the owner in {path}/_uploads
 *                 (their own bucket, not the library's)
 *   inboxes       the download inboxes (novarr.audiobooks.inbox,
 *                 novarr.books.download_inbox): in production NFS mounts of
 *                 the Unraid array's Download share, so not Novarr's disks —
 *                 kept out of the totals (`external`), walked with a time
 *                 limit (`partial` when it ran out), and what a Transmission
 *                 torrent still seeds is told apart (a second copy of an
 *                 imported audiobook or book)
 *
 * Soft-deleted series and chapters are counted: their rows, text and files
 * stay until a hard delete, so they still use the disk. Sizes are file sizes
 * (apparent), not allocated blocks.
 */
class StorageUsage
{
    public const SCOPE_SERIES = 'series';
    public const SCOPE_BUCKET = 'bucket';
    public const SCOPE_DISK = 'disk';
    public const SCOPE_RUN = 'run';
    /** One row per audiobook (key = audiobook id, novel_id = its book). */
    public const SCOPE_AUDIOBOOK = 'audiobook';

    /** The medium key of audiobooks in report()['mediums'] and ['top']. */
    public const MEDIUM_AUDIOBOOK = 'audiobook';

    /** Readers' uploads waiting for the owner, inside the audiobook library folder. */
    public const UPLOADS_DIR = '_uploads';

    /** The share's name on the page and in the low-space alert. */
    public const SHARE_LABEL = 'Unraid Download share';

    /** Physical buckets: they do not overlap, so they add up (the stacked bar). */
    public const BUCKETS = [
        'manga_pages' => 'Manga pages',
        'database' => 'Database',
        'covers' => 'Covers & uploads',
        'epubs' => 'ePubs',
        'books' => 'Book files',
        'audiobooks' => 'Audiobook library',
        'audiobook_uploads' => 'Audiobook uploads waiting',
        'download_inboxes' => 'Download inboxes',
        'backups' => 'Backups',
        'logs' => 'Logs',
        'snapshots' => 'Failure snapshots',
        'cache' => 'Framework cache',
        'other' => 'Other',
    ];

    /** Novel chapter text: part of the database bucket, shown on its own. */
    public const NOVEL_TEXT = 'novel_text';

    /** Chapters sampled per novel to turn its stored word count into bytes. */
    public const TEXT_SAMPLE = 25;

    private ?string $lengthExpression = null;

    // ---------------------------------------------------------------------
    // Measuring
    // ---------------------------------------------------------------------

    /**
     * Measure everything. Returns rows ready for store() and nothing is
     * written. $exact sums every chapter's text length instead of
     * estimating it from stored word counts (reads every chapter body).
     *
     * @param  callable(string): void|null  $progress  told what is being measured
     * @return array{rows: array<int, array>, duration_ms: int, exact: bool}
     */
    public function measure(bool $exact = false, ?callable $progress = null): array
    {
        $started = microtime(true);
        $say = $progress ?? fn(string $m) => null;
        $rows = [];

        $say('Manga pages');
        $manga = $this->mangaUsage();

        $say('Covers');
        $covers = $this->coverUsage();

        $say('Audiobooks');
        $audiobooks = $this->audiobookUsage();
        foreach ($audiobooks['rows'] as $row) {
            $rows[] = $row;
        }

        $say('Series');
        $textTotal = ['bytes' => 0, 'chapters' => 0, 'estimated' => false];
        $knownIds = [];
        Novel::withTrashed()
            ->select(['id', 'name', 'author', 'medium', 'deleted_at'])
            ->lazyById(200)
            ->each(function (Novel $novel) use (&$rows, &$textTotal, &$knownIds, $manga, $covers, $audiobooks, $exact) {
                $id = (int) $novel->id;
                $knownIds[$id] = true;
                $isManga = $novel->medium === Novel::MEDIUM_MANGA;
                $medium = in_array($novel->medium, [Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK], true) ? $novel->medium : Novel::MEDIUM_NOVEL;

                $pages = $manga['series'][$id] ?? ['bytes' => 0, 'files' => 0, 'chapters' => 0];
                $text = $this->textUsage($id, $exact);
                $cover = $covers[$id] ?? ['bytes' => 0, 'files' => 0];
                $epubPath = GenerateePub::epubPath($novel);
                $epub = is_file($epubPath) ? (int) @filesize($epubPath) : 0;

                $textTotal['bytes'] += $text['bytes'];
                $textTotal['chapters'] += $text['chapters'];
                $textTotal['estimated'] = $textTotal['estimated'] || $text['estimated'];

                $content = $pages['bytes'] + $text['bytes'];
                // Its audiobook is a row of its own (not in these bytes, so the
                // medium totals never count it twice); the rankings add it.
                $audio = $audiobooks['by_novel'][$id] ?? null;
                $rows[] = [
                    'scope' => self::SCOPE_SERIES,
                    'key' => (string) $id,
                    'medium' => $medium,
                    'novel_id' => $id,
                    'bytes' => $content + $cover['bytes'] + $epub,
                    'files' => $pages['files'] + $cover['files'] + ($epub > 0 ? 1 : 0),
                    // Chapters with stored content: page folders for manga,
                    // text rows for novels (a series could have both).
                    'chapters' => $pages['chapters'] + $text['chapters'],
                    'meta' => [
                        'name' => (string) $novel->name,
                        'deleted' => $novel->deleted_at !== null,
                        'pages_bytes' => $pages['bytes'],
                        'text_bytes' => $text['bytes'],
                        'text_estimated' => $text['estimated'],
                        'cover_bytes' => $cover['bytes'],
                        'epub_bytes' => $epub,
                        'audiobook_id' => $audio['id'] ?? null,
                        'audiobook_bytes' => (int) ($audio['bytes'] ?? 0),
                    ],
                ];
            });

        // Page folders of series that no longer exist (hard-deleted, or
        // left by a failed clean-up): counted in the manga bucket only.
        $unattributed = $manga['loose'];
        foreach ($manga['series'] as $id => $usage) {
            if (!isset($knownIds[$id])) {
                $unattributed['bytes'] += $usage['bytes'];
                $unattributed['files'] += $usage['files'];
            }
        }

        $say('Buckets');
        $paths = $this->paths();
        $bucket = function (string $key, array $usage, array $meta = []) use (&$rows) {
            $rows[] = [
                'scope' => self::SCOPE_BUCKET,
                'key' => $key,
                'medium' => null,
                'novel_id' => null,
                'bytes' => (int) $usage['bytes'],
                'files' => (int) $usage['files'],
                'chapters' => null,
                'meta' => $meta + ['errors' => (int) ($usage['errors'] ?? 0), 'missing' => (bool) ($usage['missing'] ?? false)],
            ];
        };

        $bucket('manga_pages', $manga['total'], [
            'paths' => [$paths['manga']],
            'unattributed_bytes' => $unattributed['bytes'],
            'unattributed_files' => $unattributed['files'],
        ]);
        $bucket('covers', $this->walk($paths['public']), ['paths' => [$paths['public']]]);
        $bucket('epubs', $this->walkAll($paths['epubs']), ['paths' => $paths['epubs']]);
        // The Books library's original ePubs: uploads, and Calibre's (on the big disk).
        $bucket('books', $this->walkAll($paths['books']), ['paths' => $paths['books']]);
        // Audiobook files (App\Audiobooks: the library root, on the big disk),
        // without the readers' uploads waiting for the owner: their own bucket.
        $bucket('audiobooks', $this->walk($paths['audiobooks'][0], [$paths['audiobook_uploads']]), [
            'paths' => $paths['audiobooks'],
            'count' => count($audiobooks['rows']),
        ]);
        $bucket('audiobook_uploads', $this->walk($paths['audiobook_uploads']), ['paths' => [$paths['audiobook_uploads']]]);
        $say('Download inboxes');
        $inboxes = $this->inboxUsage();
        $bucket('download_inboxes', $inboxes['usage'], $inboxes['meta']);
        $bucket('backups', $this->walkAll($paths['backups']), ['paths' => $paths['backups'], 'remote' => $paths['remote_backups']]);
        $bucket('logs', $this->walk($paths['logs']), ['paths' => [$paths['logs']]]);
        $bucket('snapshots', $this->walk($paths['snapshots']), ['paths' => [$paths['snapshots']]]);
        $bucket('cache', $this->walk($paths['cache']), ['paths' => [$paths['cache']]]);
        // Everything else under storage/: the APK, stray uploads, temp files.
        $claimed = array_merge(
            [$paths['manga'], $paths['public'], $paths['logs'], $paths['snapshots'], $paths['cache']],
            $paths['epubs'],
            $paths['books'],
            $paths['audiobooks'],
            $paths['inboxes'],
            $paths['backups'],
        );
        $bucket('other', $this->walk($paths['storage'], $claimed), ['paths' => [$paths['storage']]]);

        $database = $this->databaseUsage();
        $bucket('database', ['bytes' => $database['bytes'], 'files' => 0], $database['meta']);
        $bucket(self::NOVEL_TEXT, ['bytes' => $textTotal['bytes'], 'files' => 0], [
            'within' => 'database',
            'chapters' => $textTotal['chapters'],
            'estimated' => $textTotal['estimated'],
        ]);

        $say('Disks');
        foreach ($this->volumes() as $volume) {
            $rows[] = $volume;
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        $rows[] = [
            'scope' => self::SCOPE_RUN,
            'key' => 'last',
            'medium' => null,
            'novel_id' => null,
            'bytes' => 0,
            'files' => 0,
            'chapters' => null,
            'meta' => ['duration_ms' => $duration, 'exact' => $exact],
        ];

        return ['rows' => $rows, 'duration_ms' => $duration, 'exact' => $exact];
    }

    /** Replace the stored measurement with $result['rows'] (one transaction). */
    public function store(array $result, ?Carbon $at = null): void
    {
        $at = ($at ?? now())->copy();
        $records = array_map(fn(array $row) => [
            'scope' => $row['scope'],
            'key' => $row['key'],
            'medium' => $row['medium'],
            'novel_id' => $row['novel_id'],
            'bytes' => max(0, (int) $row['bytes']),
            'files' => max(0, (int) $row['files']),
            'chapters' => $row['chapters'],
            'meta' => json_encode($row['meta'] ?? []),
            'measured_at' => $at,
        ], $result['rows']);

        DB::transaction(function () use ($records) {
            DB::table('storage_usage')->delete();
            foreach (array_chunk($records, 200) as $chunk) {
                DB::table('storage_usage')->insert($chunk);
            }
        });
    }

    // ---------------------------------------------------------------------
    // Reading the stored figures
    // ---------------------------------------------------------------------

    /**
     * The stored measurement for Health → Storage, or null when never measured.
     *
     * buckets are Novarr's own (they add up: the bar and the total); a
     * bucket on the Unraid share (the download inboxes, `external`) is in
     * share_buckets instead, never in the total. mediums has an `audiobook`
     * entry (count, hours, MB per listening hour); top has an `audiobook`
     * list. A series' place in top counts its audiobook with it
     * (total_bytes), the split shown beside it.
     *
     * @return array{measured_at: Carbon, duration_ms: int, exact: bool, disks: array, buckets: array, share_buckets: array,
     *               novel_text: ?array, mediums: array, top: array{all: array, novel: array, manga: array, book: array, audiobook: array}}|null
     */
    public function report(int $top = 25): ?array
    {
        $run = DB::table('storage_usage')->where('scope', self::SCOPE_RUN)->where('key', 'last')->first();
        if ($run === null) {
            return null;
        }
        $runMeta = self::meta($run);

        $buckets = [];
        $novelText = null;
        foreach (DB::table('storage_usage')->where('scope', self::SCOPE_BUCKET)->get() as $row) {
            $item = ['key' => $row->key, 'label' => self::BUCKETS[$row->key] ?? $row->key,
                'bytes' => (int) $row->bytes, 'files' => (int) $row->files, 'meta' => self::meta($row)];
            if ($row->key === self::NOVEL_TEXT) {
                $novelText = $item;
            } else {
                $buckets[$row->key] = $item;
            }
        }
        // In BUCKETS order, so the bar and the table are stable.
        $buckets = array_values(array_filter(array_map(fn($k) => $buckets[$k] ?? null, array_keys(self::BUCKETS))));
        $shareBuckets = array_values(array_filter($buckets, fn($b) => !empty($b['meta']['external'])));
        $buckets = array_values(array_filter($buckets, fn($b) => empty($b['meta']['external'])));

        $disks = DB::table('storage_usage')->where('scope', self::SCOPE_DISK)->orderBy('key')->get()
            ->map(fn($row) => ['key' => $row->key, 'used' => (int) $row->bytes] + self::meta($row))->all();

        // Series rows are a few hundred: totals per medium and the rankings in PHP.
        $mediums = [];
        foreach ([Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK] as $medium) {
            $mediums[$medium] = ['series' => 0, 'bytes' => 0, 'chapters' => 0, 'content_bytes' => 0, 'cover_bytes' => 0, 'epub_bytes' => 0];
        }
        $series = [];
        foreach (DB::table('storage_usage')->where('scope', self::SCOPE_SERIES)->cursor() as $row) {
            $item = self::seriesItem($row);
            $m = in_array($item['medium'], [Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK], true) ? $item['medium'] : Novel::MEDIUM_NOVEL;
            $mediums[$m]['series']++;
            $mediums[$m]['bytes'] += $item['bytes'];
            $mediums[$m]['chapters'] += $item['chapters'];
            $mediums[$m]['content_bytes'] += $item['pages_bytes'] + $item['text_bytes'];
            $mediums[$m]['cover_bytes'] += $item['cover_bytes'];
            $mediums[$m]['epub_bytes'] += $item['epub_bytes'];
            if ($item['total_bytes'] > 0) {
                $series[] = $item;
            }
        }
        foreach ($mediums as $m => $totals) {
            $mediums[$m]['average'] = $totals['series'] > 0 ? (int) round($totals['bytes'] / $totals['series']) : 0;
        }

        $audiobooks = DB::table('storage_usage')->where('scope', self::SCOPE_AUDIOBOOK)->get()
            ->map(fn($row) => self::audiobookItem($row))->all();
        $audio = ['series' => count($audiobooks), 'bytes' => 0, 'chapters' => 0, 'content_bytes' => 0, 'cover_bytes' => 0,
            'epub_bytes' => 0, 'duration_ms' => 0, 'deleted' => 0, 'missing_parts' => 0];
        // MB per listening hour over the audiobooks whose files are all there
        // (a missing part's length without its bytes would make it look small).
        $rate = ['bytes' => 0, 'ms' => 0];
        foreach ($audiobooks as $a) {
            $audio['bytes'] += $a['bytes'];
            $audio['chapters'] += $a['parts'];
            $audio['content_bytes'] += $a['parts_bytes'];
            $audio['cover_bytes'] += $a['cover_bytes'];
            $audio['duration_ms'] += $a['duration_ms'];
            $audio['deleted'] += $a['deleted'] ? 1 : 0;
            $audio['missing_parts'] += $a['missing_parts'];
            if ($a['missing_parts'] === 0) {
                $rate['bytes'] += $a['parts_bytes'];
                $rate['ms'] += $a['duration_ms'];
            }
        }
        $audio['average'] = $audio['series'] > 0 ? (int) round($audio['bytes'] / $audio['series']) : 0;
        $audio['hours'] = round($audio['duration_ms'] / 3_600_000, 1);
        $audio['mb_per_hour'] = self::mbPerHour($rate['bytes'], $rate['ms']);
        $mediums[self::MEDIUM_AUDIOBOOK] = $audio;

        // A book and its audiobook together; biggest first, then by id.
        usort($series, fn($a, $b) => [$b['total_bytes'], $a['novel_id']] <=> [$a['total_bytes'], $b['novel_id']]);
        $topRows = fn(?string $medium) => array_slice(array_values(array_filter($series,
            fn($s) => $medium === null || $s['medium'] === $medium)), 0, $top);
        usort($audiobooks, fn($a, $b) => [$b['bytes'], $a['audiobook_id']] <=> [$a['bytes'], $b['audiobook_id']]);

        return [
            'measured_at' => Carbon::parse($run->measured_at),
            'duration_ms' => (int) ($runMeta['duration_ms'] ?? 0),
            'exact' => (bool) ($runMeta['exact'] ?? false),
            'disks' => $disks,
            'buckets' => $buckets,
            'share_buckets' => $shareBuckets,
            'novel_text' => $novelText,
            'mediums' => $mediums,
            'top' => [
                'all' => $topRows(null),
                Novel::MEDIUM_NOVEL => $topRows(Novel::MEDIUM_NOVEL),
                Novel::MEDIUM_MANGA => $topRows(Novel::MEDIUM_MANGA),
                Novel::MEDIUM_BOOK => $topRows(Novel::MEDIUM_BOOK),
                self::MEDIUM_AUDIOBOOK => array_slice(array_values(array_filter($audiobooks, fn($a) => $a['bytes'] > 0)), 0, $top),
            ],
        ];
    }

    /** One series' stored figures (the series page: its audiobook included), or null when not measured. */
    public static function forSeries(int $novelId): ?array
    {
        $row = DB::table('storage_usage')->where('scope', self::SCOPE_SERIES)->where('key', (string) $novelId)->first();

        return $row === null ? null : self::seriesItem($row);
    }

    /** MiB of audio per listening hour, or null without a length. */
    public static function mbPerHour(int $bytes, int $durationMs): ?float
    {
        return $durationMs > 0 ? round(($bytes / 1048576) / ($durationMs / 3_600_000), 1) : null;
    }

    /** The disks under the low-space threshold, from report()['disks'] or volumes(). */
    public static function lowDisks(array $disks): array
    {
        return array_values(array_filter($disks, fn($d) => !empty($d['low'])));
    }

    public static function lowFreePercent(): float
    {
        return max(0.0, (float) config('novarr.storage.low_free_percent', 10));
    }

    private static function seriesItem(object $row): array
    {
        $meta = self::meta($row);
        $chapters = (int) $row->chapters;

        return [
            'novel_id' => (int) $row->novel_id,
            'name' => (string) ($meta['name'] ?? "#{$row->novel_id}"),
            'deleted' => (bool) ($meta['deleted'] ?? false),
            'medium' => (string) $row->medium,
            'bytes' => (int) $row->bytes,
            'files' => (int) $row->files,
            'chapters' => $chapters,
            'per_chapter' => $chapters > 0 ? (int) round((int) $row->bytes / $chapters) : null,
            'pages_bytes' => (int) ($meta['pages_bytes'] ?? 0),
            'text_bytes' => (int) ($meta['text_bytes'] ?? 0),
            'text_estimated' => (bool) ($meta['text_estimated'] ?? false),
            'cover_bytes' => (int) ($meta['cover_bytes'] ?? 0),
            'epub_bytes' => (int) ($meta['epub_bytes'] ?? 0),
            // Its audiobook (a row of its own): with it in total_bytes.
            'audiobook_id' => isset($meta['audiobook_id']) ? (int) $meta['audiobook_id'] : null,
            'audiobook_bytes' => (int) ($meta['audiobook_bytes'] ?? 0),
            'total_bytes' => (int) $row->bytes + (int) ($meta['audiobook_bytes'] ?? 0),
            'measured_at' => $row->measured_at ? Carbon::parse($row->measured_at) : null,
        ];
    }

    private static function audiobookItem(object $row): array
    {
        $meta = self::meta($row);
        $duration = (int) ($meta['duration_ms'] ?? 0);
        $partsBytes = (int) ($meta['parts_bytes'] ?? 0);

        return [
            'audiobook_id' => (int) $row->key,
            'title' => (string) ($meta['title'] ?? "#{$row->key}"),
            'author' => $meta['author'] ?? null,
            'narrator' => $meta['narrator'] ?? null,
            'deleted' => (bool) ($meta['deleted'] ?? false),
            'novel_id' => $row->novel_id !== null ? (int) $row->novel_id : null,
            'novel_name' => $meta['novel_name'] ?? null,
            'novel_deleted' => (bool) ($meta['novel_deleted'] ?? false),
            'bytes' => (int) $row->bytes,
            'files' => (int) $row->files,
            'parts' => (int) ($meta['parts'] ?? $row->chapters ?? 0),
            'parts_bytes' => $partsBytes,
            'recorded_bytes' => (int) ($meta['recorded_bytes'] ?? 0),
            'missing_parts' => (int) ($meta['missing_parts'] ?? 0),
            'cover_bytes' => (int) ($meta['cover_bytes'] ?? 0),
            'duration_ms' => $duration,
            'hours' => round($duration / 3_600_000, 1),
            'mb_per_hour' => self::mbPerHour($partsBytes, $duration),
        ];
    }

    private static function meta(object $row): array
    {
        $meta = json_decode((string) ($row->meta ?? ''), true);

        return is_array($meta) ? $meta : [];
    }

    // ---------------------------------------------------------------------
    // The parts
    // ---------------------------------------------------------------------

    /**
     * Every location measured, resolved now (so tests can point them at a
     * temporary directory through config and storage_path()).
     */
    public function paths(): array
    {
        $backupName = (string) config('backup.backup.name', config('app.name', 'laravel-backup'));
        $backups = [];
        $remote = [];
        foreach ((array) config('backup.backup.destination.disks', []) as $disk) {
            $config = config("filesystems.disks.{$disk}");
            if (is_array($config) && ($config['driver'] ?? null) === 'local' && !empty($config['root'])) {
                $backups[] = rtrim((string) $config['root'], '/') . '/' . $backupName;
            } else {
                $remote[] = (string) $disk;
            }
        }

        return [
            'storage' => rtrim(storage_path(), '/'),
            'manga' => rtrim((string) config('filesystems.disks.' . ChapterPage::DISK . '.root', storage_path('app/manga')), '/'),
            'public' => rtrim((string) config('filesystems.disks.public.root', storage_path('app/public')), '/'),
            'epubs' => [storage_path('app/ePub'), storage_path('app/epub-build'), storage_path('app/Novel')],
            'books' => array_values(array_unique(array_filter([
                rtrim((string) config('novarr.books.path', storage_path('app/books')), '/'),
                config('novarr.books.calibre_path') ? rtrim((string) config('novarr.books.calibre_path'), '/') : null,
            ]))),
            'audiobooks' => [$audiobooks = rtrim((string) config('novarr.audiobooks.path', storage_path('app/audiobooks')), '/')],
            'audiobook_uploads' => $audiobooks . '/' . self::UPLOADS_DIR,
            // The download inboxes (the Unraid Download share over NFS in production).
            'inboxes' => array_values(array_unique([
                rtrim((string) (config('novarr.audiobooks.inbox') ?: storage_path('app/audiobooks-inbox')), '/'),
                Downloads::booksInbox(),
            ])),
            'snapshots' => rtrim((string) (config('novarr.snapshots.path') ?: storage_path('app/snapshots')), '/'),
            'logs' => storage_path('logs'),
            'cache' => storage_path('framework'),
            'backups' => array_values(array_unique($backups)),
            'remote_backups' => $remote,
        ];
    }

    /**
     * The manga disk, one walk: per top-level folder named after a novel id
     * (bytes, page files, chapter folders), files elsewhere ("loose") and the
     * total.
     *
     * @return array{series: array<int, array{bytes: int, files: int, chapters: int}>,
     *               loose: array{bytes: int, files: int}, total: array}
     */
    public function mangaUsage(): array
    {
        $root = $this->paths()['manga'];
        $series = [];
        $loose = ['bytes' => 0, 'files' => 0];
        $total = ['bytes' => 0, 'files' => 0, 'dirs' => 0, 'errors' => 0, 'missing' => !is_dir($root)];

        if (!is_dir($root)) {
            return ['series' => [], 'loose' => $loose, 'total' => $total];
        }

        try {
            $top = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable $e) {
            Log::warning("Storage usage: cannot read the manga folder {$root}: " . $e->getMessage());
            $total['errors']++;

            return ['series' => [], 'loose' => $loose, 'total' => $total];
        }

        // Book originals kept on the same disk (NOVARR_BOOKS_CALIBRE_PATH) are not manga,
        // nor are audiobooks (NOVARR_AUDIOBOOKS_PATH, /mnt/manga/novarr-audiobooks).
        $booksDir = ($books = config('novarr.books.calibre_path')) ? rtrim((string) $books, '/') : null;
        $audiobooksDir = rtrim((string) config('novarr.audiobooks.path', ''), '/') ?: null;
        // Nor a download inbox, should one be kept there (measured as its own bucket).
        $inboxDirs = array_fill_keys($this->paths()['inboxes'], true);

        foreach ($top as $entry) {
            try {
                if ($entry->isLink()) {
                    continue;
                }
                if ($booksDir !== null && rtrim($entry->getPathname(), '/') === $booksDir) {
                    continue;
                }
                if ($audiobooksDir !== null && rtrim($entry->getPathname(), '/') === $audiobooksDir) {
                    continue;
                }
                if (isset($inboxDirs[rtrim($entry->getPathname(), '/')])) {
                    continue;
                }
                if ($entry->isDir()) {
                    $usage = $this->walk($entry->getPathname());
                    $total['bytes'] += $usage['bytes'];
                    $total['files'] += $usage['files'];
                    $total['dirs'] += $usage['dirs'] + 1;
                    $total['errors'] += $usage['errors'];
                    if (ctype_digit($entry->getFilename())) {
                        $series[(int) $entry->getFilename()] = [
                            'bytes' => $usage['bytes'],
                            'files' => $usage['files'],
                            'chapters' => $this->countSubdirectories($entry->getPathname()),
                        ];
                    } else {
                        $loose['bytes'] += $usage['bytes'];
                        $loose['files'] += $usage['files'];
                    }
                } else {
                    $size = (int) $entry->getSize();
                    $total['bytes'] += $size;
                    $total['files']++;
                    $loose['bytes'] += $size;
                    $loose['files']++;
                }
            } catch (\Throwable $e) {
                $total['errors']++;
            }
        }

        return ['series' => $series, 'loose' => $loose, 'total' => $total];
    }

    /**
     * Cover bytes per novel: every File row of the novel (the newest is its
     * cover; older rows are history whose image may still be on disk), the
     * original on the public disk and both thumbnails. An image shared by
     * two rows is counted once.
     *
     * @return array<int, array{bytes: int, files: int}>
     */
    public function coverUsage(): array
    {
        $root = $this->paths()['public'];
        $thumbs = app(CoverThumbnails::class);
        $seen = [];
        $usage = [];

        DB::table('files')
            ->where('file_type', (new Novel())->getMorphClass())
            ->select(['id', 'file_id', 'file_path'])
            ->lazyById(500)
            ->each(function ($file) use ($root, $thumbs, &$seen, &$usage) {
                $stored = (string) $file->file_path;
                if ($stored === '') {
                    return;
                }
                $id = (int) $file->file_id;
                $usage[$id] ??= ['bytes' => 0, 'files' => 0];
                $relative = CoverThumbnails::relative($stored);
                $candidates = [$relative];
                foreach (array_keys(CoverThumbnails::SIZES) as $size) {
                    $candidates[] = $thumbs->path($stored, $size);
                }
                foreach ($candidates as $path) {
                    if (isset($seen[$path])) {
                        continue;
                    }
                    $seen[$path] = true;
                    $absolute = $root . '/' . $path;
                    if (is_file($absolute)) {
                        $usage[$id]['bytes'] += (int) @filesize($absolute);
                        $usage[$id]['files']++;
                    }
                }
            });

        return $usage;
    }

    /**
     * Bytes of one novel's chapter text (chapter_texts.content), soft-deleted
     * chapters included. One aggregate over the novel's chapters (index on
     * novel_chapters starting with novel_id, then chapter_texts by primary
     * key).
     *
     * Estimated (default): the stored word counts, times the bytes per word
     * of up to TEXT_SAMPLE of the novel's chapters (so HTML markup and the
     * source's style are in the ratio); chapters without a word count get
     * the novel's average counted chapter. A novel with no counted
     * chapter at all is summed exactly. $exact reads every body.
     *
     * @return array{bytes: int, chapters: int, estimated: bool}
     */
    public function textUsage(int $novelId, bool $exact = false): array
    {
        $base = fn() => DB::table('novel_chapters as nc')
            ->join('chapter_texts as t', 't.novel_chapter_id', '=', 'nc.id')
            ->where('nc.novel_id', $novelId);

        $length = $this->lengthExpression();
        $agg = $base()->selectRaw('COUNT(*) AS chapters, COALESCE(SUM(t.word_count), 0) AS words, '
            . 'COALESCE(SUM(CASE WHEN t.word_count IS NULL THEN 1 ELSE 0 END), 0) AS uncounted')->first();
        $chapters = (int) ($agg->chapters ?? 0);
        if ($chapters === 0) {
            return ['bytes' => 0, 'chapters' => 0, 'estimated' => false];
        }

        $sum = fn() => (int) $base()->selectRaw("COALESCE(SUM({$length}), 0) AS bytes")->value('bytes');
        if ($exact) {
            return ['bytes' => $sum(), 'chapters' => $chapters, 'estimated' => false];
        }

        $sample = $base()->whereNotNull('t.word_count')
            ->selectRaw("{$length} AS bytes, t.word_count AS words")
            ->limit(self::TEXT_SAMPLE)
            ->get();
        if ($sample->isEmpty()) {
            return ['bytes' => $sum(), 'chapters' => $chapters, 'estimated' => false];
        }

        $sampleBytes = (int) $sample->sum('bytes');
        $sampleWords = (int) $sample->sum('words');
        $counted = $chapters - (int) $agg->uncounted;

        if ($sampleWords > 0) {
            $countedBytes = ((int) $agg->words) * ($sampleBytes / $sampleWords);
            // Uncounted chapters: the novel's average counted chapter.
            $bytes = $countedBytes + ((int) $agg->uncounted) * ($countedBytes / max(1, $counted));
        } else {
            $bytes = $chapters * ($sampleBytes / $sample->count());
        }

        // The sample is exact for itself: with every chapter sampled, no estimate.
        $estimated = !($sample->count() === $chapters && $counted === $chapters);

        return ['bytes' => $estimated ? (int) round($bytes) : $sampleBytes, 'chapters' => $chapters, 'estimated' => $estimated];
    }

    /** Bytes of a text column, per driver (sqlite's LENGTH counts characters). */
    private function lengthExpression(): string
    {
        return $this->lengthExpression ??= match (DB::connection()->getDriverName()) {
            'sqlite' => 'LENGTH(CAST(t.content AS BLOB))',
            'pgsql' => 'OCTET_LENGTH(t.content)',
            default => 'LENGTH(t.content)', // MySQL / MariaDB: bytes
        };
    }

    /**
     * The database's size: MariaDB/MySQL from information_schema (data +
     * indexes of this schema; the chapter_texts table on its own in meta),
     * sqlite the file (+ -wal/-shm), PostgreSQL pg_database_size.
     *
     * @return array{bytes: int, meta: array}
     */
    public function databaseUsage(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $tables = DB::table('information_schema.TABLES')
                    ->whereRaw('table_schema = DATABASE()')
                    ->selectRaw('COALESCE(SUM(data_length + index_length), 0) AS bytes, '
                        . "COALESCE(SUM(CASE WHEN table_name = 'chapter_texts' THEN data_length + index_length ELSE 0 END), 0) AS texts")
                    ->first();

                return ['bytes' => (int) $tables->bytes, 'meta' => [
                    'driver' => $driver,
                    'source' => 'information_schema',
                    'chapter_texts_bytes' => (int) $tables->texts,
                ]];
            }

            if ($driver === 'sqlite') {
                $path = (string) $connection->getConfig('database');
                if ($path === '' || $path === ':memory:' || !is_file($path)) {
                    return ['bytes' => 0, 'meta' => ['driver' => $driver, 'source' => 'in-memory']];
                }
                $bytes = 0;
                foreach (['', '-wal', '-shm'] as $suffix) {
                    if (is_file($path . $suffix)) {
                        $bytes += (int) @filesize($path . $suffix);
                    }
                }

                return ['bytes' => $bytes, 'meta' => ['driver' => $driver, 'source' => 'file', 'paths' => [$path]]];
            }

            if ($driver === 'pgsql') {
                $bytes = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes;

                return ['bytes' => $bytes, 'meta' => ['driver' => $driver, 'source' => 'pg_database_size']];
            }
        } catch (\Throwable $e) {
            Log::warning('Storage usage: could not read the database size: ' . $e->getMessage());

            return ['bytes' => 0, 'meta' => ['driver' => $driver, 'error' => $e->getMessage()]];
        }

        return ['bytes' => 0, 'meta' => ['driver' => $driver, 'source' => 'unsupported']];
    }

    /**
     * Total / free space of each volume holding app storage, manga pages,
     * audiobooks, Calibre's books or local backups. Paths on the same device
     * (stat dev) are one volume; `title` names it ("App storage", or "Big
     * drive: manga, audiobooks, Calibre books, backups").
     *
     * The download inboxes on the Unraid Download share (NFS) are a volume
     * of their own (`share`, titled SHARE_LABEL), so a full array shows
     * before downloads fail; two inbox mounts of the same share (same device,
     * or the same total and free space) are one. A share that is not mounted
     * is a row saying so (`missing`), never low. Inboxes on one of Novarr's
     * own disks are named on that disk's line instead.
     *
     * @return array<int, array> disk rows for store()
     */
    public function volumes(): array
    {
        $paths = $this->paths();
        $places = ['App storage' => $paths['storage'], 'Manga pages' => $paths['manga'], 'Audiobooks' => $paths['audiobooks'][0]];
        if (config('novarr.books.calibre_path')) {
            $places['Calibre books'] = rtrim((string) config('novarr.books.calibre_path'), '/');
        }
        foreach ($paths['backups'] as $i => $path) {
            $places['Backups' . ($i > 0 ? ' ' . ($i + 1) : '')] = $path;
        }

        $volumes = [];
        foreach ($places as $label => $path) {
            $existing = $this->nearestExisting($path);
            if ($existing === null) {
                continue;
            }
            $stat = @stat($existing);
            $key = $stat !== false ? 'dev-' . $stat['dev'] : 'path-' . md5($existing);
            if (isset($volumes[$key])) {
                $volumes[$key]['meta']['labels'][] = $label;
                $volumes[$key]['meta']['paths'][] = $path;
                continue;
            }
            $volumes[$key] = $this->volumeRow($key, $label, $path, $existing);
        }

        $shares = [];
        $missing = [];
        foreach ($paths['inboxes'] as $path) {
            if (!$this->onShare($path)) {
                // On one of Novarr's own disks: named on that disk's line.
                $existing = $this->nearestExisting($path);
                $stat = $existing !== null ? @stat($existing) : false;
                $key = $stat !== false ? 'dev-' . $stat['dev'] : null;
                if ($key !== null && isset($volumes[$key])) {
                    $volumes[$key]['meta']['labels'][] = 'Download inboxes';
                    $volumes[$key]['meta']['paths'][] = $path;
                }
                continue;
            }
            if (!is_dir($path)) {
                $missing[] = $path;
                continue;
            }
            $stat = @stat($path);
            $key = 'share-' . ($stat !== false ? $stat['dev'] : md5($path));
            $row = $this->volumeRow($key, self::SHARE_LABEL, $path, $path);
            foreach ($shares as $k => $other) {
                $same = $k === $key || ($row['meta']['total'] !== null && $row['meta']['total'] === $other['meta']['total']
                    && $row['meta']['free'] === $other['meta']['free']);
                if ($same) {
                    $shares[$k]['meta']['paths'][] = $path;
                    continue 2;
                }
            }
            $shares[$key] = $row;
        }
        if ($shares === [] && $missing !== []) {
            $row = $this->volumeRow('share-missing', self::SHARE_LABEL, $missing[0], null);
            $row['meta']['paths'] = $missing;
            $shares['share-missing'] = $row;
        }

        foreach ($volumes as $key => $volume) {
            $volumes[$key]['meta']['title'] = self::volumeTitle($volume['meta']['labels']);
        }
        foreach ($shares as $key => $share) {
            $shares[$key]['meta']['title'] = self::SHARE_LABEL;
            $shares[$key]['meta']['share'] = true;
        }

        return array_values($volumes + $shares);
    }

    /**
     * Free / total space of the volume holding $path, as the Storage page
     * reads it (the reader upload limit can use the same reading). Null
     * figures when they cannot be read.
     *
     * @return array{total: ?int, free: ?int, free_percent: ?float}
     */
    public static function diskSpace(string $path): array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $total = $total === false ? null : (int) $total;
        $free = $free === false ? null : (int) $free;

        return ['total' => $total, 'free' => $free, 'free_percent' => $total ? round(100 * $free / $total, 1) : null];
    }

    /** One disk row; $existing null = not there (nothing read, never low). */
    private function volumeRow(string $key, string $label, string $path, ?string $existing): array
    {
        $space = $existing !== null ? self::diskSpace($existing) : ['total' => null, 'free' => null, 'free_percent' => null];
        $threshold = self::lowFreePercent();

        return [
            'scope' => self::SCOPE_DISK,
            'key' => $key,
            'medium' => null,
            'novel_id' => null,
            'bytes' => $space['total'] !== null && $space['free'] !== null ? max(0, $space['total'] - $space['free']) : 0,
            'files' => 0,
            'chapters' => null,
            'meta' => [
                'labels' => [$label],
                'paths' => [$path],
                'total' => $space['total'],
                'free' => $space['free'],
                'free_percent' => $space['free_percent'],
                'low' => $space['free_percent'] !== null && $space['free_percent'] < $threshold,
                'threshold' => $threshold,
                'missing' => $existing === null,
            ],
        ];
    }

    /** "App storage + Backups", or "Big drive: manga, audiobooks, Calibre books, backups". */
    public static function volumeTitle(array $labels): string
    {
        if (in_array('App storage', $labels, true) || array_intersect($labels, ['Manga pages', 'Audiobooks', 'Calibre books']) === []) {
            return implode(' + ', $labels);
        }
        $short = array_map(fn(string $l) => match (true) {
            $l === 'Manga pages' => 'manga',
            $l === 'Audiobooks' => 'audiobooks',
            $l === 'Calibre books' => 'Calibre books',
            str_starts_with($l, 'Backups') => 'backups',
            default => mb_strtolower($l),
        }, $labels);

        return 'Big drive: ' . implode(', ', array_values(array_unique($short)));
    }

    /**
     * Whether a download inbox is on the Unraid share rather than one of
     * Novarr's disks: config novarr.storage.share_external when set, else
     * whether it is on a device none of Novarr's places is on. A missing
     * inbox outside app storage is taken as an unmounted share.
     */
    public function onShare(string $path): bool
    {
        $forced = config('novarr.storage.share_external');
        if (is_bool($forced)) {
            return $forced;
        }
        $paths = $this->paths();
        if (!is_dir($path)) {
            return !($path === $paths['storage'] || str_starts_with($path, $paths['storage'] . '/'));
        }
        $stat = @stat($path);
        if ($stat === false) {
            return false;
        }
        $places = array_merge([$paths['storage'], $paths['manga'], $paths['audiobooks'][0]], $paths['books'], $paths['backups']);
        foreach ($places as $place) {
            $existing = $this->nearestExisting($place);
            $other = $existing !== null ? @stat($existing) : false;
            if ($other !== false && $other['dev'] === $stat['dev']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every audiobook, soft-deleted ones too (their files stay on disk until
     * the owner removes the folder): its parts' bytes as they are on disk (a
     * part whose file is gone counts nothing and is told in missing_parts;
     * audiobook_parts' recorded sizes kept beside), its cover (the original
     * and both thumbnails on the public disk), part count, length and book.
     * One query for the parts and one for the books per 200 audiobooks.
     *
     * @return array{rows: list<array>, by_novel: array<int, array{id: int, bytes: int}>}
     */
    public function audiobookUsage(): array
    {
        $public = $this->paths()['public'];
        $thumbs = app(CoverThumbnails::class);
        $rows = [];
        $byNovel = [];

        Audiobook::withTrashed()
            ->select(['id', 'novel_id', 'title', 'author', 'narrator', 'duration_ms', 'cover_path', 'dir', 'deleted_at'])
            ->chunkById(200, function ($chunk) use (&$rows, &$byNovel, $public, $thumbs) {
                $parts = DB::table('audiobook_parts')->whereIn('audiobook_id', $chunk->pluck('id')->all())
                    ->orderBy('idx')->get(['audiobook_id', 'path', 'size_bytes', 'duration_ms'])->groupBy('audiobook_id');
                $novelIds = $chunk->pluck('novel_id')->filter()->unique()->values()->all();
                $novels = $novelIds === [] ? collect() : Novel::withTrashed()->whereIn('id', $novelIds)->get(['id', 'name', 'deleted_at'])->keyBy('id');

                foreach ($chunk as $audiobook) {
                    $dir = $audiobook->directory();
                    $partsBytes = 0;
                    $recorded = 0;
                    $present = 0;
                    $missing = 0;
                    $partsDuration = 0;
                    $count = 0;
                    foreach ($parts->get($audiobook->id, collect()) as $part) {
                        $count++;
                        $recorded += (int) $part->size_bytes;
                        $partsDuration += (int) $part->duration_ms;
                        $file = $dir . '/' . ltrim((string) $part->path, '/');
                        if (is_file($file)) {
                            $partsBytes += (int) @filesize($file);
                            $present++;
                        } else {
                            $missing++;
                        }
                    }

                    $coverBytes = 0;
                    $coverFiles = 0;
                    if ($audiobook->cover_path) {
                        $candidates = [CoverThumbnails::relative((string) $audiobook->cover_path)];
                        foreach (array_keys(CoverThumbnails::SIZES) as $size) {
                            $candidates[] = $thumbs->path((string) $audiobook->cover_path, $size);
                        }
                        foreach (array_unique($candidates) as $path) {
                            if (is_file($public . '/' . $path)) {
                                $coverBytes += (int) @filesize($public . '/' . $path);
                                $coverFiles++;
                            }
                        }
                    }

                    $novel = $audiobook->novel_id ? $novels->get($audiobook->novel_id) : null;
                    $bytes = $partsBytes + $coverBytes;
                    $rows[] = [
                        'scope' => self::SCOPE_AUDIOBOOK,
                        'key' => (string) $audiobook->id,
                        'medium' => self::MEDIUM_AUDIOBOOK,
                        'novel_id' => $novel !== null ? (int) $novel->id : null,
                        'bytes' => $bytes,
                        'files' => $present + $coverFiles,
                        'chapters' => $count,
                        'meta' => [
                            'title' => (string) $audiobook->title,
                            'author' => $audiobook->author,
                            'narrator' => $audiobook->narrator,
                            'deleted' => $audiobook->deleted_at !== null,
                            'novel_name' => $novel?->name,
                            'novel_deleted' => $novel !== null && $novel->deleted_at !== null,
                            'parts' => $count,
                            'parts_bytes' => $partsBytes,
                            'recorded_bytes' => $recorded,
                            'missing_parts' => $missing,
                            'cover_bytes' => $coverBytes,
                            'duration_ms' => (int) $audiobook->duration_ms > 0 ? (int) $audiobook->duration_ms : $partsDuration,
                        ],
                    ];
                    if ($novel !== null) {
                        $byNovel[(int) $novel->id] = ['id' => (int) $audiobook->id, 'bytes' => $bytes];
                    }
                }
            });

        return ['rows' => $rows, 'by_novel' => $byNovel];
    }

    /**
     * The download inboxes (novarr.audiobooks.inbox, novarr.books.download_inbox),
     * top-level entry by entry, within novarr.storage.share_walk_seconds
     * (`partial` when the time ran out: the share is NFS and can be slow).
     *
     * What a torrent still seeds is told apart, by the tidy-up's rule
     * (App\Downloads\InboxTidy): an entry whose name is ANY Transmission
     * torrent's name, in any state. That part is a second copy of what was
     * imported (imports copy, so the torrent can go on seeding). When
     * Transmission is set up but cannot be asked, the seeding share is
     * unknown (`seeding.status` unknown), never guessed.
     *
     * @return array{usage: array, meta: array}
     */
    public function inboxUsage(): array
    {
        $paths = $this->paths()['inboxes'];
        $limit = max(0.0, (float) config('novarr.storage.share_walk_seconds', 120));
        $deadline = microtime(true) + $limit;
        $usage = ['bytes' => 0, 'files' => 0, 'errors' => 0, 'missing' => true];
        $partial = false;
        $inboxes = [];
        $entries = []; // [name, bytes, files]

        foreach ($paths as $path) {
            $inbox = ['path' => $path, 'bytes' => 0, 'files' => 0, 'missing' => !is_dir($path), 'external' => $this->onShare($path)];
            if (!$inbox['missing'] && !$partial) {
                $usage['missing'] = false;
                $names = @scandir($path);
                if ($names === false) {
                    $usage['errors']++;
                    $names = [];
                }
                foreach ($names as $name) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    if (microtime(true) > $deadline) {
                        $partial = true;
                        break;
                    }
                    $walk = $this->walk("{$path}/{$name}", [], $deadline);
                    $inbox['bytes'] += $walk['bytes'];
                    $inbox['files'] += $walk['files'];
                    $usage['errors'] += $walk['errors'];
                    if ($name !== AudiobookInbox::FAILED_DIR) {
                        $entries[] = [$name, $walk['bytes'], $walk['files']];
                    }
                    if ($walk['partial']) {
                        $partial = true;
                        break;
                    }
                }
            }
            $usage['bytes'] += $inbox['bytes'];
            $usage['files'] += $inbox['files'];
            $inboxes[] = $inbox;
        }

        $seeding = ['status' => 'off', 'bytes' => 0, 'files' => 0, 'entries' => 0, 'error' => null];
        if (Downloads::transmissionConfigured()) {
            $seeding['status'] = 'ok';
            if ($entries !== []) {
                try {
                    $torrents = [];
                    foreach (app(Transmission::class)->all() as $torrent) {
                        $torrents[mb_strtolower(trim((string) ($torrent['name'] ?? '')))] = true;
                    }
                    foreach ($entries as [$name, $bytes, $files]) {
                        if (isset($torrents[mb_strtolower($name)])) {
                            $seeding['bytes'] += $bytes;
                            $seeding['files'] += $files;
                            $seeding['entries']++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Storage usage: Transmission could not be asked, seeding share unknown: ' . $e->getMessage());
                    $seeding = ['status' => 'unknown', 'bytes' => 0, 'files' => 0, 'entries' => 0, 'error' => $e->getMessage()];
                }
            }
        }

        return ['usage' => $usage, 'meta' => [
            'paths' => $paths,
            'inboxes' => $inboxes,
            // On the Unraid share (every inbox is): kept out of Novarr's totals.
            'external' => $inboxes !== [] && count(array_filter($inboxes, fn($i) => $i['external'])) === count($inboxes),
            'seeding' => $seeding,
            'partial' => $partial,
            'time_limit_s' => $limit,
        ]];
    }

    private function nearestExisting(string $path): ?string
    {
        $path = rtrim($path, '/') ?: '/';
        for ($i = 0; $i < 64; $i++) {
            if (file_exists($path)) {
                return $path;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }
            $path = $parent;
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Directory walks
    // ---------------------------------------------------------------------

    /**
     * Size and file count under $root, iteratively (an explicit stack of
     * directories, one FilesystemIterator open at a time: memory stays flat
     * however many files). Symlinks are not followed or counted. Folders in
     * $exclude (and anything under them) are skipped. An unreadable folder
     * or file is counted in `errors` and skipped. A missing $root is zero.
     * With a $deadline (microtime(true) value) the walk stops once it is
     * passed and says `partial` (what it counted so far is returned): a slow
     * network mount never holds the whole measurement up.
     *
     * @param  string[]  $exclude
     * @return array{bytes: int, files: int, dirs: int, errors: int, missing: bool, partial: bool}
     */
    public function walk(string $root, array $exclude = [], ?float $deadline = null): array
    {
        $result = ['bytes' => 0, 'files' => 0, 'dirs' => 0, 'errors' => 0, 'missing' => false, 'partial' => false];
        $root = rtrim($root, '/');
        if (is_link($root)) {
            $root = (string) realpath($root);
        }
        if ($root === '' || !file_exists($root)) {
            $result['missing'] = true;

            return $result;
        }
        if (is_file($root)) {
            $result['bytes'] = (int) @filesize($root);
            $result['files'] = 1;

            return $result;
        }

        $skip = [];
        foreach ($exclude as $path) {
            $real = realpath((string) $path);
            if ($real !== false) {
                $skip[$real] = true;
            }
        }

        $stack = [$root];
        $seen = 0;
        while ($stack !== []) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $result['partial'] = true;

                break;
            }
            $dir = array_pop($stack);
            try {
                $entries = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable $e) {
                $result['errors']++;

                continue;
            }

            foreach ($entries as $entry) {
                if ($deadline !== null && (++$seen % 256) === 0 && microtime(true) > $deadline) {
                    $result['partial'] = true;

                    break 2;
                }
                try {
                    if ($entry->isLink()) {
                        continue;
                    }
                    if ($entry->isDir()) {
                        if ($skip !== [] && isset($skip[(string) $entry->getRealPath()])) {
                            continue;
                        }
                        $result['dirs']++;
                        $stack[] = $entry->getPathname();
                    } else {
                        $result['bytes'] += (int) $entry->getSize();
                        $result['files']++;
                    }
                } catch (\Throwable $e) {
                    $result['errors']++;
                }
            }
        }

        return $result;
    }

    /** walk() of several roots, summed (a folder inside another is counted once). */
    public function walkAll(array $roots): array
    {
        $total = ['bytes' => 0, 'files' => 0, 'dirs' => 0, 'errors' => 0, 'missing' => true, 'partial' => false];
        $seen = [];
        foreach ($roots as $root) {
            $real = realpath((string) $root);
            if ($real === false) {
                continue;
            }
            foreach (array_keys($seen) as $other) {
                if ($real === $other || str_starts_with($real, $other . '/')) {
                    continue 2;
                }
            }
            $seen[$real] = true;
            $usage = $this->walk($real);
            foreach (['bytes', 'files', 'dirs', 'errors'] as $k) {
                $total[$k] += $usage[$k];
            }
            $total['missing'] = false;
        }

        return $total;
    }

    private function countSubdirectories(string $dir): int
    {
        $count = 0;
        try {
            foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if (!$entry->isLink() && $entry->isDir()) {
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            return $count;
        }

        return $count;
    }
}
