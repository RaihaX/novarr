<?php

namespace App\Books;

use App\Novel;
use Illuminate\Support\Facades\DB;

/**
 * The series of the Books library: novels.series / series_index /
 * series_source on books (medium 'book').
 *
 * Who sets a series, strongest last (a weaker one never overwrites a
 * stronger one):
 *   inferred  SeriesInference, from titles and authors (re-run freely)
 *   hardcover Hardcover's series for a book that has none or only a
 *             guess (`books:series --hardcover`, each book asked once)
 *   epub      the ePub's own calibre:series / belongs-to-collection
 *   calibre   Calibre-Web's SERIES line, at import or by `books:series --calibre`
 *   manual    the owner, on the edit page; series '' = "no series", kept
 */
class BookSeries
{
    public const CALIBRE = 'calibre';
    public const EPUB = 'epub';
    public const INFERRED = 'inferred';
    public const HARDCOVER = 'hardcover';
    public const MANUAL = 'manual';

    /** Columns the inference reads. */
    private const COLUMNS = ['id', 'name', 'author', 'series', 'series_index', 'series_source'];

    /** A series_index the column (decimal 8,2) can hold, or null. */
    public static function index($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $value = round((float) $value, 2);

        return abs($value) < 1_000_000 ? $value : null;
    }

    /** "2" for 2.0, "2.5" for 2.5. */
    public static function formatIndex(?float $index): ?string
    {
        if ($index === null) {
            return null;
        }

        return rtrim(rtrim(number_format($index, 2, '.', ''), '0'), '.');
    }

    /**
     * Run the inference over every book (or the books of one author) and
     * store what changed: series_source 'inferred' on a new or moved guess,
     * an earlier guess that no longer holds cleared. Never touches a series
     * from Calibre, the ePub or the owner.
     *
     * @return array{books: int, assigned: int, cleared: int, series: int}
     */
    public static function infer(bool $dryRun = false, ?string $author = null, bool $authorScope = false): array
    {
        $query = Novel::books()->select(self::COLUMNS);
        if ($authorScope) {
            $author !== null && trim($author) !== '' ? $query->where('author', $author) : $query->whereNull('author');
        }
        $books = $query->get();

        $rows = $books->map(fn(Novel $b) => [
            'id' => (int) $b->id,
            'name' => (string) $b->name,
            'author' => $b->author,
            'series' => $b->series,
            'series_index' => $b->series_index,
            'series_source' => $b->series_source,
        ])->all();
        $current = $books->keyBy('id');

        $counts = ['books' => count($rows), 'assigned' => 0, 'cleared' => 0, 'series' => 0];
        $names = [];
        foreach (SeriesInference::infer($rows) as $id => $guess) {
            /** @var Novel $book */
            $book = $current[$id];
            if ($guess['series'] !== null) {
                $names[$guess['series']] = true;
            }
            $index = self::index($guess['series_index']);
            if ($guess['series'] === null) {
                if ($book->series_source !== self::INFERRED && ($book->series === null || $book->series === '')) {
                    continue;
                }
                $counts['cleared']++;
                $values = ['series' => null, 'series_index' => null, 'series_source' => null];
            } else {
                if ($book->series_source === self::INFERRED && $book->series === $guess['series']
                    && self::index($book->series_index) === $index) {
                    continue;
                }
                $counts['assigned']++;
                $values = ['series' => $guess['series'], 'series_index' => $index, 'series_source' => self::INFERRED];
            }
            if (!$dryRun) {
                // No updated_at: a guess is no edit of the book.
                DB::table('novels')->where('id', $id)->update($values);
            }
        }
        $counts['series'] = count($names);

        return $counts;
    }

    /**
     * After a book arrives: the inference over its author's books only
     * (cheap: one small query), so "Vol. 2" joins "Vol. 1" straight away.
     * The whole library runs nightly and after a Calibre import, which also
     * catches authors written differently.
     */
    public static function inferFor(Novel $book): array
    {
        return self::infer(false, $book->author, true);
    }

    /**
     * Series from Hardcover for the books without one (no series, or only
     * a guess): at most $limit books not asked before (hardcover_checked_at
     * null), oldest first. A book Hardcover knows in a series gets it
     * (series_source 'hardcover'); asked, found or not, it is marked
     * checked; a request that failed leaves it for the next run. Never
     * touches a series from the ePub, Calibre or the owner (an owner's
     * "no series" included).
     *
     * @return array{asked: int, found: int, updated: int, failed: int}
     */
    public static function backfillFromHardcover(Hardcover $hardcover, int $limit = 150, bool $dryRun = false): array
    {
        $counts = ['asked' => 0, 'found' => 0, 'updated' => 0, 'failed' => 0];
        if (!Hardcover::enabled() || $limit < 1) {
            return $counts;
        }
        $books = Novel::books()
            ->whereNull('hardcover_checked_at')
            ->where(fn($q) => $q->whereNull('series_source')->orWhere('series_source', '')->orWhere('series_source', self::INFERRED))
            ->orderBy('id')
            ->limit($limit)
            ->get(self::COLUMNS);

        foreach ($books as $book) {
            $counts['asked']++;
            $found = $hardcover->seriesFor((string) $book->name, $book->author);
            if ($found === null) {
                $counts['failed']++;
                // Hardcover down or refusing the token: the rest would fail too.
                if ($counts['failed'] >= 3 && $counts['found'] === 0 && $counts['failed'] === $counts['asked']) {
                    break;
                }

                continue;
            }
            $values = ['hardcover_checked_at' => now()];
            if (!empty($found['series'])) {
                $counts['found']++;
                $index = self::index($found['series_index'] ?? null);
                if (!($book->series_source === self::HARDCOVER && $book->series === $found['series'] && self::index($book->series_index) === $index)) {
                    $counts['updated']++;
                    $values += ['series' => $found['series'], 'series_index' => $index, 'series_source' => self::HARDCOVER];
                }
            }
            if (!$dryRun) {
                // No updated_at: a looked-up series is no edit of the book.
                DB::table('novels')->where('id', $book->id)->update($values);
            }
        }

        return $counts;
    }

    /**
     * Series from Calibre-Web for the books imported from it (source_ref
     * "calibre:{uuid}"): walks /opds/new to its end. Calibre's series wins
     * over the ePub's and a guess; the owner's never changes. A book Calibre
     * no longer has in a series loses a series Calibre gave it.
     *
     * @return array{seen: int, matched: int, updated: int, cleared: int}
     */
    public static function backfillFromCalibre(CalibreWeb $calibre, bool $dryRun = false): array
    {
        $byUuid = CalibreImport::importedUuids(false);
        $books = Novel::books()->whereIn('id', array_values($byUuid) ?: [0])->get(self::COLUMNS)->keyBy('id');
        $counts = ['seen' => 0, 'matched' => 0, 'updated' => 0, 'cleared' => 0];

        $offset = 0;
        while (true) {
            $page = $calibre->recent($offset);
            foreach ($page['entries'] as $entry) {
                $counts['seen']++;
                $id = $byUuid[(string) ($entry['uuid'] ?? '')] ?? null;
                $book = $id !== null ? $books->get($id) : null;
                if ($book === null) {
                    continue;
                }
                $counts['matched']++;
                if ($book->series_source === self::MANUAL) {
                    continue;
                }
                $series = mb_substr(trim((string) ($entry['series'] ?? '')), 0, 255);
                $index = self::index($entry['series_index'] ?? null);
                if ($series !== '') {
                    if ($book->series_source === self::CALIBRE && $book->series === $series && self::index($book->series_index) === $index) {
                        continue;
                    }
                    $counts['updated']++;
                    $values = ['series' => $series, 'series_index' => $index, 'series_source' => self::CALIBRE];
                } elseif ($book->series_source === self::CALIBRE) {
                    $counts['cleared']++;
                    $values = ['series' => null, 'series_index' => null, 'series_source' => null];
                } else {
                    continue;
                }
                if (!$dryRun) {
                    DB::table('novels')->where('id', $book->id)->update($values);
                }
            }
            if ($page['entries'] === [] || $page['next'] === null || $page['next'] <= $offset) {
                break;
            }
            $offset = $page['next'];
        }

        return $counts;
    }
}
