<?php

namespace App\Services;

use App\ChapterPage;
use App\Console\Commands\GenerateePub;
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

    /** Physical buckets: they do not overlap, so they add up (the stacked bar). */
    public const BUCKETS = [
        'manga_pages' => 'Manga pages',
        'database' => 'Database',
        'covers' => 'Covers & uploads',
        'epubs' => 'ePubs',
        'books' => 'Book files',
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

        $say('Series');
        $textTotal = ['bytes' => 0, 'chapters' => 0, 'estimated' => false];
        $knownIds = [];
        Novel::withTrashed()
            ->select(['id', 'name', 'author', 'medium', 'deleted_at'])
            ->lazyById(200)
            ->each(function (Novel $novel) use (&$rows, &$textTotal, &$knownIds, $manga, $covers, $exact) {
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
        $bucket('backups', $this->walkAll($paths['backups']), ['paths' => $paths['backups'], 'remote' => $paths['remote_backups']]);
        $bucket('logs', $this->walk($paths['logs']), ['paths' => [$paths['logs']]]);
        $bucket('snapshots', $this->walk($paths['snapshots']), ['paths' => [$paths['snapshots']]]);
        $bucket('cache', $this->walk($paths['cache']), ['paths' => [$paths['cache']]]);
        // Everything else under storage/: the APK, stray uploads, temp files.
        $claimed = array_merge(
            [$paths['manga'], $paths['public'], $paths['logs'], $paths['snapshots'], $paths['cache']],
            $paths['epubs'],
            $paths['books'],
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
     * @return array{measured_at: Carbon, duration_ms: int, exact: bool, disks: array, buckets: array,
     *               novel_text: ?array, mediums: array, top: array{all: array, novel: array, manga: array}}|null
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

        $disks = DB::table('storage_usage')->where('scope', self::SCOPE_DISK)->orderBy('key')->get()
            ->map(fn($row) => ['key' => $row->key, 'used' => (int) $row->bytes] + self::meta($row))->all();

        // Series rows are a few hundred: totals per medium in PHP.
        $mediums = [];
        foreach ([Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK] as $medium) {
            $mediums[$medium] = ['series' => 0, 'bytes' => 0, 'chapters' => 0, 'content_bytes' => 0, 'cover_bytes' => 0, 'epub_bytes' => 0];
        }
        foreach (DB::table('storage_usage')->where('scope', self::SCOPE_SERIES)->select(['medium', 'bytes', 'chapters', 'meta'])->cursor() as $row) {
            $m = in_array($row->medium, [Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK], true) ? $row->medium : Novel::MEDIUM_NOVEL;
            $meta = self::meta($row);
            $mediums[$m]['series']++;
            $mediums[$m]['bytes'] += (int) $row->bytes;
            $mediums[$m]['chapters'] += (int) $row->chapters;
            $mediums[$m]['content_bytes'] += (int) ($meta['pages_bytes'] ?? 0) + (int) ($meta['text_bytes'] ?? 0);
            $mediums[$m]['cover_bytes'] += (int) ($meta['cover_bytes'] ?? 0);
            $mediums[$m]['epub_bytes'] += (int) ($meta['epub_bytes'] ?? 0);
        }
        foreach ($mediums as $m => $totals) {
            $mediums[$m]['average'] = $totals['series'] > 0 ? (int) round($totals['bytes'] / $totals['series']) : 0;
        }

        $topRows = fn(?string $medium) => DB::table('storage_usage')
            ->where('scope', self::SCOPE_SERIES)
            ->when($medium !== null, fn($q) => $q->where('medium', $medium))
            ->where('bytes', '>', 0)
            ->orderByDesc('bytes')->orderBy('novel_id')
            ->limit($top)
            ->get()
            ->map(fn($row) => self::seriesItem($row))
            ->all();

        return [
            'measured_at' => Carbon::parse($run->measured_at),
            'duration_ms' => (int) ($runMeta['duration_ms'] ?? 0),
            'exact' => (bool) ($runMeta['exact'] ?? false),
            'disks' => $disks,
            'buckets' => $buckets,
            'novel_text' => $novelText,
            'mediums' => $mediums,
            'top' => [
                'all' => $topRows(null),
                Novel::MEDIUM_NOVEL => $topRows(Novel::MEDIUM_NOVEL),
                Novel::MEDIUM_MANGA => $topRows(Novel::MEDIUM_MANGA),
                Novel::MEDIUM_BOOK => $topRows(Novel::MEDIUM_BOOK),
            ],
        ];
    }

    /** One series' stored figures (the series page), or null when not measured. */
    public static function forSeries(int $novelId): ?array
    {
        $row = DB::table('storage_usage')->where('scope', self::SCOPE_SERIES)->where('key', (string) $novelId)->first();

        return $row === null ? null : self::seriesItem($row);
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
            'measured_at' => $row->measured_at ? Carbon::parse($row->measured_at) : null,
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

        // Book originals kept on the same disk (NOVARR_BOOKS_CALIBRE_PATH) are not manga.
        $booksDir = ($books = config('novarr.books.calibre_path')) ? rtrim((string) $books, '/') : null;

        foreach ($top as $entry) {
            try {
                if ($entry->isLink()) {
                    continue;
                }
                if ($booksDir !== null && rtrim($entry->getPathname(), '/') === $booksDir) {
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
     * Total / free space of each volume holding app storage, manga pages or
     * local backups. Paths on the same device (stat dev) are one volume.
     *
     * @return array<int, array> disk rows for store()
     */
    public function volumes(): array
    {
        $paths = $this->paths();
        $places = ['App storage' => $paths['storage'], 'Manga pages' => $paths['manga']];
        foreach ($paths['backups'] as $i => $path) {
            $places['Backups' . ($i > 0 ? ' ' . ($i + 1) : '')] = $path;
        }

        $threshold = self::lowFreePercent();
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

            $total = @disk_total_space($existing);
            $free = @disk_free_space($existing);
            $total = $total === false ? null : (int) $total;
            $free = $free === false ? null : (int) $free;
            $freePercent = $total ? round(100 * $free / $total, 1) : null;

            $volumes[$key] = [
                'scope' => self::SCOPE_DISK,
                'key' => $key,
                'medium' => null,
                'novel_id' => null,
                'bytes' => $total !== null && $free !== null ? max(0, $total - $free) : 0,
                'files' => 0,
                'chapters' => null,
                'meta' => [
                    'labels' => [$label],
                    'paths' => [$path],
                    'total' => $total,
                    'free' => $free,
                    'free_percent' => $freePercent,
                    'low' => $freePercent !== null && $freePercent < $threshold,
                    'threshold' => $threshold,
                ],
            ];
        }

        return array_values($volumes);
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
     *
     * @param  string[]  $exclude
     * @return array{bytes: int, files: int, dirs: int, errors: int, missing: bool}
     */
    public function walk(string $root, array $exclude = []): array
    {
        $result = ['bytes' => 0, 'files' => 0, 'dirs' => 0, 'errors' => 0, 'missing' => false];
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
        while ($stack !== []) {
            $dir = array_pop($stack);
            try {
                $entries = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable $e) {
                $result['errors']++;

                continue;
            }

            foreach ($entries as $entry) {
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
        $total = ['bytes' => 0, 'files' => 0, 'dirs' => 0, 'errors' => 0, 'missing' => true];
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
