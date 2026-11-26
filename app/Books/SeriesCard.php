<?php

namespace App\Books;

use App\Novel;

/**
 * One card of the Books grid standing for a series' books
 * (NovelController::index): named after the series, shown with the cover of
 * its first book (lowest series_index), opening the library filtered to it.
 */
final class SeriesCard
{
    /**
     * @param  int[]  $ids  the series' books shown by this card, in series order
     */
    public function __construct(
        public readonly string $name,
        public readonly array $ids,
        public readonly Novel $cover,
    ) {
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /** The series' own view of the library (?series=), keeping the view and sort. */
    public function url(array $query = []): string
    {
        return route('novels.index', array_merge($query, ['medium' => Novel::MEDIUM_BOOK, 'series' => $this->name]));
    }

    /**
     * The card's reading figures from the Library's per-book index: chapters
     * read and total over its books, finished when every book is, and how
     * many books are finished.
     *
     * @return array{read: int, new: int, total: int, percent: int, finished: bool, books_finished: int}
     */
    public function reading(array $reading): array
    {
        $read = $total = $booksFinished = 0;
        foreach ($this->ids as $id) {
            $r = $reading[$id] ?? null;
            $read += (int) ($r['read'] ?? 0);
            $total += (int) ($r['total'] ?? 0);
            $booksFinished += !empty($r['finished']) ? 1 : 0;
        }

        return [
            'read' => $read,
            'new' => 0,
            'total' => $total,
            'percent' => $total > 0 ? (int) min(100, floor($read / $total * 100)) : 0,
            'finished' => $booksFinished === count($this->ids),
            'books_finished' => $booksFinished,
        ];
    }

    /**
     * Series order: number first (unnumbered before numbered, as the
     * database sorts NULLs), then name, then id. The same order lists a
     * series' books and picks a book page's previous / next.
     */
    public static function compare(Novel $a, Novel $b): int
    {
        $ai = $a->series_index;
        $bi = $b->series_index;
        if (($ai === null) !== ($bi === null)) {
            return $ai === null ? -1 : 1;
        }

        return [(float) $ai, mb_strtolower((string) $a->name), (int) $a->id] <=> [(float) $bi, mb_strtolower((string) $b->name), (int) $b->id];
    }
}
