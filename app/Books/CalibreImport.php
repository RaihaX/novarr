<?php

namespace App\Books;

use App\Novel;
use App\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Books from the owner's Calibre-Web into the Books library. Calibre's
 * metadata is already curated, so a book is added straight away (no review):
 * title, authors, description, tags and cover from Calibre, chapters from
 * its ePub. The book remembers where it came from (source_ref
 * "calibre:{uuid}"), so it is never imported twice, also when the owner
 * deleted it (deleted books stay skipped).
 *
 * sync(): books added to Calibre since the last run (Calibre's ids only
 * grow; the high-water mark is the setting calibre_sync_after_id). The very
 * first run only sets the mark, so turning sync on does not pull the whole
 * library; `--all` (everything) does that on purpose.
 */
class CalibreImport
{
    public const MARK = 'calibre_sync_after_id';

    public const IMPORTED = 'imported';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** Letters (normalised) a title needs before it can match a web novel loosely. */
    private const NEAR_MIN_LENGTH = 15;

    /** similar_text percentage at which two long titles are the same work. */
    private const NEAR_SIMILARITY = 92;

    /** @var ?array<string, int> normalised web-novel titles => id, built once per instance */
    private ?array $webNovels = null;

    /** @var ?array<string, int> Calibre uuids already imported => id, kept current by import() */
    private ?array $imported = null;

    public function __construct(private CalibreWeb $calibre, private BookLibrary $library)
    {
    }

    /**
     * The web novel in the library this Calibre book is a copy of, or null.
     * Calibre often holds a web novel split into volumes ("Shadow Slave
     * Vol. 3", "Book 2: …", "Chapters 1-200"), so it compares the title, the
     * title without its volume numbering or subtitle, and the Calibre series.
     */
    public function webNovelFor(array $entry): ?Novel
    {
        if ($this->webNovels === null) {
            $this->webNovels = [];
            foreach (Novel::where('medium', Novel::MEDIUM_NOVEL)->orderBy('id')->get(['id', 'name']) as $novel) {
                $this->webNovels[\App\Services\DuplicateSeries::normalize((string) $novel->name)] ??= (int) $novel->id;
            }
            unset($this->webNovels['']);
        }

        $keys = [];
        foreach (self::titleVariants($entry) as $variant) {
            $key = \App\Services\DuplicateSeries::normalize($variant);
            if ($key !== '' && isset($this->webNovels[$key])) {
                return Novel::find($this->webNovels[$key]);
            }
            $keys[] = $key;
        }

        // Near misses: a misspelt copy ("Patriach") or the web novel's
        // subtitle alone ("Circle of Inevitability" for "Lord of Mysteries 2
        // Circle of Inevitability"). Short titles never match this way.
        foreach (array_filter($keys, fn($k) => strlen($k) >= self::NEAR_MIN_LENGTH) as $key) {
            foreach ($this->webNovels as $webKey => $id) {
                if (strlen($webKey) < self::NEAR_MIN_LENGTH) {
                    continue;
                }
                similar_text($key, $webKey, $percent);
                if ($percent >= self::NEAR_SIMILARITY || str_contains($webKey, $key)) {
                    return Novel::find($id);
                }
            }
        }

        return null;
    }

    /** @return string[] the title, without volume numbering, without its subtitle, and the series */
    public static function titleVariants(array $entry): array
    {
        $title = trim((string) ($entry['title'] ?? ''));
        $base = $title;
        $patterns = [
            '/\s*[\(\[]?\s*(?:ch(?:apters?)?\.?|c\.)\s*\d+\s*(?:-|–|to)\s*\d+.*$/iu',   // "Chapters 1-200"
            '/\s*[\(\[,:\-–—]*\s*\b(?:vol(?:ume)?|book|bk|part|tome)\.?\s*(?:\d+|[ivxlc]+|one|two|three|four|five|six|seven|eight|nine|ten)\b.*$/iu',
            '/\s*#\s*\d+(?:\.\d+)?\s*$/u',                                                // "Title #3"
            '/\s+\d+(?:\.\d+)?\s*$/u',                                                     // "Title 3"
        ];
        foreach ($patterns as $pattern) {
            $base = trim(preg_replace($pattern, '', $base) ?? $base);
        }
        $noSubtitle = trim(preg_split('/\s*:\s+/u', $base)[0]);

        return array_values(array_unique(array_filter([
            $title, $base, $noSubtitle, trim((string) ($entry['series'] ?? '')),
        ], fn($t) => mb_strlen($t) >= 3)));
    }

    /** Calibre uuids already in the library (deleted ones included) => novel id. */
    public static function importedUuids(bool $withDeleted = true): array
    {
        $query = $withDeleted ? Novel::withTrashed() : Novel::query();

        return $query->where('medium', Novel::MEDIUM_BOOK)
            ->where('source_ref', 'like', BookLibrary::CALIBRE_REF . '%')
            ->pluck('id', 'source_ref')
            ->mapWithKeys(fn($id, $ref) => [substr((string) $ref, strlen(BookLibrary::CALIBRE_REF)) => (int) $id])
            ->all();
    }

    /**
     * Add one Calibre book.
     *
     * @return array{result: string, novel: ?Novel, message: string}
     */
    public function import(array $entry, bool $force = false): array
    {
        $uuid = (string) ($entry['uuid'] ?? '');
        $this->imported ??= self::importedUuids();
        if ($uuid !== '' && ($id = ($this->imported[$uuid] ?? null)) !== null) {
            return ['result' => self::SKIPPED, 'novel' => Novel::withTrashed()->find($id), 'message' => 'already in the library'];
        }
        if (empty($entry['epub_href'])) {
            return ['result' => self::SKIPPED, 'novel' => null, 'message' => 'no ePub in Calibre'];
        }
        // A web novel the library already follows is not added again as a book.
        if (!$force && ($webNovel = $this->webNovelFor($entry)) !== null) {
            return ['result' => self::SKIPPED, 'novel' => $webNovel, 'message' => "a web novel in the library: {$webNovel->name}"];
        }

        try {
            $file = $this->calibre->downloadEpub($entry);
        } catch (\Throwable $e) {
            return ['result' => self::FAILED, 'novel' => null, 'message' => $e->getMessage()];
        }

        try {
            $details = [
                'title' => $entry['title'],
                'author' => implode(', ', (array) $entry['authors']),
                'source_ref' => $uuid !== '' ? BookLibrary::CALIBRE_REF . $uuid : null,
            ];
            if (!empty($entry['description'])) {
                $details['description'] = $entry['description'];
            }
            if (!empty($entry['tags'])) {
                $details['genres'] = $entry['tags'];
            }
            // Calibre's series is curated: it wins over the ePub's own.
            if (trim((string) ($entry['series'] ?? '')) !== '') {
                $details['series'] = $entry['series'];
                $details['series_index'] = $entry['series_index'] ?? null;
                $details['series_source'] = BookSeries::CALIBRE;
            }
            if ($cover = $this->calibre->cover($entry)) {
                $details['cover_bytes'] = $cover;
            }

            // Only a book Calibre has no description for asks the catalogues.
            $outcome = $this->library->import($file, $entry['title'] . '.epub', $force, empty($entry['description']), $details);
        } catch (EpubException $e) {
            return ['result' => self::FAILED, 'novel' => null, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error("Calibre import of “{$entry['title']}” failed: " . $e->getMessage());

            return ['result' => self::FAILED, 'novel' => null, 'message' => $e->getMessage()];
        } finally {
            @unlink($file);
        }

        if ($uuid !== '' && $outcome['result'] === BookLibrary::CREATED) {
            $this->imported[$uuid] = (int) $outcome['novel']->id;
        }

        return match ($outcome['result']) {
            BookLibrary::CREATED => ['result' => self::IMPORTED, 'novel' => $outcome['novel'], 'message' => 'imported'],
            BookLibrary::EXISTING => ['result' => self::SKIPPED, 'novel' => $outcome['novel'], 'message' => 'the same file is already in the library'],
            default => ['result' => self::SKIPPED, 'novel' => $outcome['novel'], 'message' => 'a book with this title is already in the library'],
        };
    }

    /** What import() would do, without downloading anything. */
    public function preview(array $entry): array
    {
        $this->imported ??= self::importedUuids();
        if (isset($this->imported[(string) ($entry['uuid'] ?? '')])) {
            return ['result' => self::SKIPPED, 'novel' => null, 'message' => 'already in the library'];
        }
        if (empty($entry['epub_href'])) {
            return ['result' => self::SKIPPED, 'novel' => null, 'message' => 'no ePub in Calibre'];
        }
        if (($webNovel = $this->webNovelFor($entry)) !== null) {
            return ['result' => self::SKIPPED, 'novel' => $webNovel, 'message' => "a web novel in the library: {$webNovel->name}"];
        }

        return ['result' => self::IMPORTED, 'novel' => null, 'message' => 'would import'];
    }

    /**
     * Walk Calibre's newest books and import what is new.
     *
     * @param  bool  $all  the whole library, not just what is past the mark
     * @param  ?callable  $progress  fn(array $entry, array $outcome)
     * @return array{imported: int, skipped: int, failed: int, mark_set: bool}
     */
    public function sync(bool $all = false, ?int $limit = null, bool $dryRun = false, ?callable $progress = null): array
    {
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'mark_set' => false];
        $mark = Setting::get(self::MARK);
        $highest = (int) ($mark ?? 0);

        $offset = 0;
        $done = false;
        while (!$done) {
            $page = $this->calibre->recent($offset);
            if ($page['entries'] === []) {
                break;
            }
            foreach ($page['entries'] as $entry) {
                $id = (int) ($entry['id'] ?? 0);
                $highest = max($highest, $id);
                // First run: only remember where "new" starts.
                if (!$all && $mark === null) {
                    $done = true;
                    break;
                }
                if (!$all && $id <= (int) $mark) {
                    $done = true;
                    break;
                }
                if ($limit !== null && $counts['imported'] >= $limit) {
                    $done = true;
                    break;
                }

                $outcome = $dryRun ? $this->preview($entry) : $this->import($entry);
                $counts[$outcome['result']]++;
                if ($progress) {
                    $progress($entry, $outcome);
                }
            }
            if ($page['next'] === null || $page['next'] <= $offset) {
                break;
            }
            $offset = $page['next'];
        }

        // The mark only moves forward, and only on a real run.
        if (!$dryRun && ($mark === null || $highest > (int) $mark) && $highest > 0) {
            Setting::put(self::MARK, (string) $highest);
            $counts['mark_set'] = $mark === null;
        }

        return $counts;
    }
}
