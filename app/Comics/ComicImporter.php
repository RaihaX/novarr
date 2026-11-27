<?php

namespace App\Comics;

use App\ChapterPage;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\NovelChapter;
use App\NovelRequest;
use App\Scraping\PageDownloader;
use App\Services\Discover;
use App\Services\DuplicateSeries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Imports one comics inbox entry (App\Comics\ComicInbox::entries()): each of
 * its units — a comic file, or a folder of page images — becomes one issue
 * or volume (a NovelChapter) of a comic series (a Novel of medium 'comic'),
 * its pages ChapterPage files on the manga disk under the series id, in the
 * natural order of their paths (non-images, __MACOSX, hidden files and
 * thumbnails skipped).
 *
 * What a unit is, from its ComicInfo.xml (ComicInfo) and its name
 * (ComicName; the folder's name when the file's says no series):
 *
 *   a volume   "Saga v01": chapter number = the volume, "Volume 1"
 *   an issue   "Batman 001", ComicInfo Number: chapter number = the issue, "#1"
 *   neither    "Watchmen (1987) TPB": the series' next number, its title
 *
 * The series: the grab's (options novel_id: "Find more issues" on its page),
 * else an existing comic series of the same normalised name (of several, the
 * one of the same start year, else the latest that began before the issue's
 * year), else a new one (complete on arrival, never scraped; reads right to
 * left only when ComicInfo says Manga=YesAndRightToLeft). An issue / volume
 * the series has already is skipped. Every new series and every series that
 * gained an issue has its details filled (App\Comics\ComicMetadata) and the
 * reader requests waiting for it (the grab's, or a pending wanted comic of
 * the same title) are approved and told it is here.
 *
 * The source file is only read: the inbox decides whether the entry goes
 * (usenet / dropped in) or stays (a torrent, seeding).
 *
 * @return array{novels: list<Novel>, chapters: list<NovelChapter>, skipped: list<array{name: string, reason: string}>, failed: list<array{name: string, path: string, reason: string}>}
 */
class ComicImporter
{
    private const LOCK = 'comics_import';

    public function __construct(private PageExtractor $extractor, private ComicMetadata $metadata)
    {
    }

    /**
     * @param  array{name: string, path: string, kind: string, units: list<array{name: string, path: string, type: string, images?: list<string>}>}  $entry
     * @param  array{novel_id?: ?int, request_id?: ?int, copy?: bool}  $options
     *
     * @throws ComicImportFailed  nothing of it could be imported (the reason of the first unit), or every unit is in the library already
     */
    public function import(array $entry, array $options = []): array
    {
        $units = array_values($entry['units'] ?? []);
        if ($units === []) {
            throw new ComicImportFailed('No comic in it (no .cbz, .cbr, .cb7, .cbt or .pdf file, and no page images).');
        }
        $expected = !empty($options['novel_id']) ? Novel::where('medium', Novel::MEDIUM_COMIC)->find((int) $options['novel_id']) : null;
        $request = !empty($options['request_id']) ? NovelRequest::find((int) $options['request_id']) : null;
        $folderName = $entry['kind'] === 'folder' ? (string) $entry['name'] : null;

        $result = ['novels' => [], 'chapters' => [], 'skipped' => [], 'failed' => []];
        $touched = [];
        $created = [];
        $infos = [];

        foreach ($units as $unit) {
            try {
                $outcome = Cache::lock(self::LOCK, 900)->block(60, fn() => $this->importUnit($unit, $folderName, $expected, $request));
            } catch (ComicImportFailed $e) {
                $result['failed'][] = ['name' => $unit['name'], 'path' => $unit['path'], 'reason' => $e->getMessage()];

                continue;
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                $result['failed'][] = ['name' => $unit['name'], 'path' => $unit['path'], 'reason' => 'Another comics import is running; tried again next scan.'];

                continue;
            }
            $novel = $outcome['novel'];
            $touched[$novel->id] = $novel;
            if ($outcome['created']) {
                $created[$novel->id] = true;
            }
            $infos[$novel->id] ??= ['info' => $outcome['info'], 'first_page' => $outcome['first_page'], 'year' => $outcome['year']];
            if ($outcome['chapter'] === null) {
                $result['skipped'][] = ['name' => $unit['name'], 'reason' => $outcome['reason']];
            } else {
                $result['chapters'][] = $outcome['chapter'];
            }
        }

        if ($result['chapters'] === []) {
            if ($result['failed'] !== []) {
                throw new ComicImportFailed($result['failed'][0]['reason'] . (count($result['failed']) > 1 ? ' (and ' . (count($result['failed']) - 1) . ' more)' : ''));
            }
            throw new ComicImportFailed('Already in the library: ' . implode('; ', array_slice(array_map(fn($s) => $s['reason'], $result['skipped']), 0, 3)) . '.');
        }

        foreach ($touched as $id => $novel) {
            $novel->forceFill(['no_of_chapters' => NovelChapter::where('novel_id', $id)->count()])->saveQuietly();
            CacheHelper::clearNovelCache((int) $id);
            try {
                // What the reader asked for fills what the files leave out.
                $asked = $request ?? self::waitingRequests($novel)->first();
                $this->metadata->fill($novel, $infos[$id]['info'] ?? null, $infos[$id]['first_page'] ?? null, isset($created[$id]), $asked);
            } catch (\Throwable $e) {
                Log::warning("Comics: details of series {$id} not filled: " . $e->getMessage());
            }
            $this->approveRequests($novel, $request);
            $result['novels'][] = $novel->refresh();
        }

        return $result;
    }

    /**
     * One unit into its series. ['novel', 'created', 'chapter' (null: skipped), 'reason', 'info', 'first_page', 'year'].
     */
    private function importUnit(array $unit, ?string $folderName, ?Novel $expected, ?NovelRequest $request): array
    {
        $work = self::workDirectory();
        try {
            if ($unit['type'] === 'images') {
                $images = array_values((array) ($unit['images'] ?? []));
                $infoFile = self::findComicInfo((string) $unit['path']);
                $copy = true;
            } else {
                if (($missing = $this->extractor->missing((string) $unit['path'])) !== null) {
                    throw new ComicImportFailed($missing);
                }
                $this->extractor->extract((string) $unit['path'], $work);
                $images = self::images($work);
                $infoFile = self::findComicInfo($work);
                $copy = false;
            }
            $info = $infoFile !== null ? ComicInfo::parse((string) @file_get_contents($infoFile)) : null;
            $pages = self::pages($images);
            if ($pages === []) {
                throw new ComicImportFailed('No page images in it (nothing Novarr can show as a page: JPEG, PNG, WebP, GIF or AVIF).');
            }

            $what = self::identify((string) $unit['name'], $folderName, $info);
            if ($expected !== null) {
                $novel = $expected;
                $created = false;
            } else {
                [$novel, $created] = $this->series($what, $info, $request);
            }

            $existing = NovelChapter::where('novel_id', $novel->id)->where('unique_id', $what['unique_id'])->first();
            if ($existing !== null) {
                return ['novel' => $novel, 'created' => $created, 'chapter' => null, 'reason' => ComicLibrary::label($novel) . ' ' . $existing->label,
                    'info' => $info, 'first_page' => null, 'year' => $what['year']];
            }

            $chapter = $this->store($novel, $what, $pages, $copy, !$created);
            if ($info !== null && $info['rtl'] && $novel->reading_mode === null) {
                $novel->forceFill(['reading_mode' => 'rtl'])->saveQuietly();
            }
            $first = ChapterPage::where('novel_chapter_id', $chapter->id)->orderBy('page')->value('path');

            return ['novel' => $novel, 'created' => $created, 'chapter' => $chapter, 'reason' => null, 'info' => $info,
                'first_page' => $first !== null ? ChapterPage::disk()->path($first) : null, 'year' => $what['year']];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * What a unit is: series, number, label, unique_id, year, start_year.
     *
     * @return array{series: string, kind: string, number: ?float, volume: ?float, issue: ?float, label: string, unique_id: string, year: ?int, start_year: ?int, title: ?string}
     */
    public static function identify(string $name, ?string $folderName, ?array $info): array
    {
        $parsed = ComicName::parse(basename($name));
        $folder = $folderName !== null ? ComicName::parse($folderName) : null;

        $series = trim((string) ($info['series'] ?? '')) ?: $parsed['series'];
        // A file called "001.cbz" in "Saga (2012)/": the folder's name is the series.
        if (($series === '' || preg_match('/^[\d\s#.\-]*$/', $series)) && $folder !== null) {
            $series = $folder['series'];
        }
        if ($series === '') {
            $series = pathinfo($name, PATHINFO_FILENAME) ?: 'Untitled comic';
        }

        $volume = $parsed['volume'] ?? ($folder['volume'] ?? null);
        $issue = $parsed['issue'];
        if ($volume !== null && $issue === null) {
            $kind = 'volume';
        } elseif ($issue !== null || ($info['number'] ?? null) !== null) {
            $kind = 'issue';
            $issue ??= $info['number'];
        } elseif (($info['volume'] ?? null) !== null) {
            $kind = 'volume';
            $volume = $info['volume'];
        } else {
            $kind = 'single';
        }
        // A bare "001.cbz" inside "Saga (2012)": the name is only a number.
        if ($kind === 'single' && preg_match('/^#?\s*0*(\d{1,4}(?:\.\d+)?)$/', trim(pathinfo(basename($name), PATHINFO_FILENAME)), $m)) {
            $kind = 'issue';
            $issue = (float) $m[1];
        }

        $title = trim((string) ($info['title'] ?? '')) ?: $parsed['title'];
        $number = match ($kind) {
            'volume' => (float) $volume,
            'issue' => (float) $issue,
            default => null,
        };
        $fallback = $title ?: ($series !== '' ? $series : pathinfo($name, PATHINFO_FILENAME));
        $label = ComicName::label($kind === 'volume' ? $number : null, $kind === 'issue' ? $number : null, $title, $fallback);
        $n = $number !== null ? rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') : null;
        $uniqueId = match ($kind) {
            'volume' => 'comic:v:' . $n,
            'issue' => 'comic:i:' . $n,
            default => 'comic:t:' . (Discover::normalizeName($fallback) ?: sha1($name)),
        };

        $year = $parsed['year'] ?? ($info['year'] ?? ($folder['year'] ?? null));

        return [
            'series' => mb_substr($series, 0, 250),
            'kind' => $kind,
            'number' => $number,
            'volume' => $kind === 'volume' ? $number : null,
            'issue' => $kind === 'issue' ? $number : null,
            'label' => $label,
            'unique_id' => mb_substr($uniqueId, 0, 190),
            'year' => $year !== null ? (int) $year : null,
            'start_year' => $info['start_year'] ?? ($folder['year'] ?? null),
            'title' => $title,
        ];
    }

    /**
     * The series a unit goes into, and whether it is new.
     *
     * @return array{0: Novel, 1: bool}
     */
    private function series(array $what, ?array $info, ?NovelRequest $request): array
    {
        $match = self::findSeries($what['series'], $what['start_year'], $what['year']);
        if ($match !== null) {
            return [$match, false];
        }

        $novel = new Novel();
        $novel->name = $what['series'];
        $novel->translator_url = ComicLibrary::newUrl($what['series']);
        $novel->medium = Novel::MEDIUM_COMIC;
        $novel->group_id = 0;
        $writers = (array) ($info['writers'] ?? []);
        $novel->author = $writers !== [] ? mb_substr(implode(', ', array_slice($writers, 0, 4)), 0, 250) : ($request?->author ?: null);
        $novel->description = $info['summary'] ?? ($request?->description ?: null);
        $novel->publisher = isset($info['publisher']) ? mb_substr((string) $info['publisher'], 0, 250) : null;
        // The series' first year: ComicInfo's ComicRack-style Volume, the folder's,
        // else the first issue's / volume 1's year.
        $novel->start_year = $what['start_year'] ?? (in_array($what['number'], [null, 1.0, 0.0], true) ? $what['year'] : null);
        $novel->origin_language = $info['language'] ?? null;
        $novel->reading_mode = ($info['rtl'] ?? false) ? 'rtl' : null;
        // Complete on arrival like a book: nothing is ever scraped for it.
        $novel->status = 1;
        $novel->completed_at = now();
        $novel->no_of_chapters = 0;
        $novel->save();
        Log::info('Comics: new series', ['novel' => $novel->id, 'name' => $novel->name]);

        return [$novel, true];
    }

    /**
     * An existing comic series of this name: of several, the one of the same
     * start year; else the latest that began no later than $year; else the
     * first. A known start year no series of the name has: none (a new one).
     */
    public static function findSeries(string $name, ?int $startYear, ?int $year): ?Novel
    {
        $key = DuplicateSeries::normalize($name);
        if ($key === '') {
            return null;
        }
        $candidates = ComicLibrary::series()->get(['id', 'name', 'start_year', 'alternative_titles', 'reading_mode', 'author', 'description', 'publisher', 'medium', 'translator_url', 'cover_source', 'comicvine_id', 'comicvine_checked_at'])
            ->filter(function (Novel $n) use ($key) {
                if (DuplicateSeries::normalize((string) $n->name) === $key) {
                    return true;
                }
                foreach ((array) ($n->alternative_titles ?? []) as $alt) {
                    if (is_string($alt) && DuplicateSeries::normalize($alt) === $key) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
        if ($candidates->isEmpty()) {
            return null;
        }
        if ($startYear !== null) {
            $same = $candidates->first(fn(Novel $n) => (int) $n->start_year === $startYear);
            if ($same !== null) {
                return Novel::find($same->id);
            }
            // Only series with another known year: this is a different one.
            if ($candidates->every(fn(Novel $n) => $n->start_year !== null)) {
                return null;
            }

            return Novel::find($candidates->first(fn(Novel $n) => $n->start_year === null)->id);
        }
        if ($year !== null) {
            $before = $candidates->filter(fn(Novel $n) => $n->start_year !== null && (int) $n->start_year <= $year)->sortByDesc('start_year')->first();
            if ($before !== null) {
                return Novel::find($before->id);
            }
        }

        return Novel::find($candidates->first()->id);
    }

    /** The chapter and its pages, all or nothing. */
    private function store(Novel $novel, array $what, array $pages, bool $copy, bool $announce): NovelChapter
    {
        $number = $what['number'] ?? ((float) NovelChapter::where('novel_id', $novel->id)->max('chapter') + 1);
        $chapter = new NovelChapter([
            'novel_id' => $novel->id,
            'chapter' => $number,
            'number' => $number,
            'sort_key' => $number,
            'book' => 0,
            'label' => $what['label'],
            'title' => $what['title'] !== null ? mb_substr($what['title'], 0, 250) : null,
            'source_label' => $what['label'],
            'url' => mb_substr($novel->translator_url . '/' . $what['unique_id'], 0, 250),
            'unique_id' => $what['unique_id'],
        ]);
        $chapter->status = 1;
        // New issues of a series already here show as new chapters; a whole
        // new series arrives quietly (its request tells the reader).
        $chapter->download_date = $announce ? now() : null;
        $chapter->page_count = count($pages);
        $chapter->save();

        $root = rtrim(ChapterPage::disk()->path(''), '/');
        $relative = ChapterPage::chapterDirectory((int) $novel->id, (int) $chapter->id);
        $dir = $root . '/' . $relative;
        try {
            File::ensureDirectoryExists($dir, 0775);
            $rows = [];
            $now = now();
            foreach ($pages as $i => $page) {
                $file = ChapterPage::fileName($i + 1, $page['ext']);
                $target = $dir . '/' . $file;
                $ok = $copy ? @copy($page['path'], $target) : (@rename($page['path'], $target) || @copy($page['path'], $target));
                if (!$ok) {
                    throw new ComicImportFailed('Novarr could not write the pages onto the manga disk (is it full or read-only?).');
                }
                $rows[] = [
                    'novel_chapter_id' => $chapter->id,
                    'page' => $i + 1,
                    'path' => $relative . '/' . $file,
                    'mime' => $page['mime'],
                    'width' => $page['width'],
                    'height' => $page['height'],
                    'bytes' => $page['bytes'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::transaction(function () use ($rows) {
                foreach (array_chunk($rows, 200) as $batch) {
                    ChapterPage::insert($batch);
                }
            });
        } catch (\Throwable $e) {
            ChapterPage::purgeChapter($chapter);
            $chapter->forceDelete();
            throw $e instanceof ComicImportFailed ? $e : new ComicImportFailed('The pages could not be stored: ' . $e->getMessage());
        }

        return $chapter;
    }

    /**
     * Approve the reader requests waiting for this series: the grab's, and
     * every pending wanted comic of the same normalised title. The
     * requester hears it is approved and available.
     */
    private function approveRequests(Novel $novel, ?NovelRequest $request): void
    {
        $waiting = self::waitingRequests($novel, $request);
        if ($waiting->isEmpty()) {
            return;
        }
        $owner = \App\User::find(\App\Support\CurrentReader::ownerId());
        $requests = app(\App\Services\Requests\Requests::class);
        foreach ($waiting as $wanted) {
            try {
                if ($owner !== null) {
                    $requests->approve($wanted, $owner, ['novel_id' => $novel->id]);
                }
                $requests->markAvailableNow($wanted->refresh());
            } catch (\Throwable $e) {
                Log::warning("Comics: request {$wanted->id} not approved for series {$novel->id}: " . $e->getMessage());
            }
        }
    }

    /** Pending comic requests for this series: the same normalised title (and $request). */
    public static function waitingRequests(Novel $novel, ?NovelRequest $request = null): \Illuminate\Support\Collection
    {
        $key = Discover::normalizeName((string) $novel->name);

        return NovelRequest::where('medium', Novel::MEDIUM_COMIC)
            ->where('status', NovelRequest::STATUS_PENDING)
            ->where(fn($q) => $q->where('normalized_title', $key)->when($request !== null, fn($q) => $q->orWhere('id', $request->id)))
            ->orderBy('id')
            ->get();
    }

    /**
     * The page images of a folder (any depth), natural order of their paths,
     * skipped what is never a page.
     *
     * @return list<string>  absolute paths
     */
    public static function images(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            if (ComicLibrary::ignored($relative) || !ComicLibrary::isImage($relative)) {
                continue;
            }
            $out[$relative] = $file->getPathname();
        }
        uksort($out, 'strnatcasecmp');

        return array_values($out);
    }

    /**
     * The images that are pages: real images of a kept type, not too big.
     *
     * @param  list<string>  $images
     * @return list<array{path: string, mime: string, ext: string, width: ?int, height: ?int, bytes: int}>
     */
    public static function pages(array $images): array
    {
        $max = max(1, (int) config('novarr.comics.max_page_mb', 60)) * 1024 * 1024;
        $pages = [];
        foreach ($images as $path) {
            $check = PageDownloader::inspect($path, $max);
            if ($check['ok']) {
                $pages[] = ['path' => $path, 'mime' => $check['mime'], 'ext' => $check['ext'], 'width' => $check['width'], 'height' => $check['height'], 'bytes' => $check['bytes']];
            }
        }

        return $pages;
    }

    /** ComicInfo.xml anywhere in the folder (any case), or null. */
    public static function findComicInfo(string $dir): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getFilename()) === 'comicinfo.xml' && !ComicLibrary::ignored(substr($file->getPathname(), strlen($dir) + 1))) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /** A scratch folder on the manga disk (same filesystem: pages are renamed into place). */
    private static function workDirectory(): string
    {
        $root = rtrim(ChapterPage::disk()->path(''), '/');
        $dir = $root . '/.tmp/comic-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir, 0775);

        return $dir;
    }
}
