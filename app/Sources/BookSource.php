<?php

namespace App\Sources;

use App\Books\BookLibrary;
use App\Books\BookLookup;
use App\Books\Epub;
use App\Novel;

/**
 * Books uploaded as ePubs (App\Books\BookLibrary). Nothing is fetched from
 * a site: the chapters were cut from the ePub when it was added, and a book
 * is complete, so the TOC and chapter sweeps never ask this source for
 * anything. What it does serve is metadata for `novel:metadata`: the
 * ePub's own, with the gaps (description, genres, cover) filled from
 * Google Books and Open Library (App\Books\BookLookup).
 *
 * It claims only book:// URLs, so it can never take a web address from
 * another source, and it is never on Discover.
 */
class BookSource extends AbstractSource
{
    public function name(): string
    {
        return 'book';
    }

    public function medium(): string
    {
        return Novel::MEDIUM_BOOK;
    }

    public function matches(Novel $novel): bool
    {
        return $novel->medium === Novel::MEDIUM_BOOK || $this->matchesUrl((string) ($novel->translator_url ?? ''));
    }

    public function matchesUrl(string $url): bool
    {
        return str_starts_with(trim($url), BookLibrary::URL_PREFIX);
    }

    public function discoverHost(): string
    {
        return 'Uploaded books';
    }

    /** A book's chapters never change: its contents were read once, at upload. */
    public function tableOfContents(Novel $novel): array
    {
        return [];
    }

    public function metadata(Novel $novel): array
    {
        $own = ['title' => $novel->name, 'authors' => array_filter([(string) $novel->author]), 'isbn' => null, 'description' => null, 'subjects' => []];

        $path = BookLibrary::path($novel);
        if (is_file($path)) {
            try {
                $epub = Epub::open($path);
                $own = array_merge($own, array_filter($epub->metadata(), fn($value) => $value !== null && $value !== []));
                $epub->close();
            } catch (\Throwable) {
                // The stored file went bad: look it up by its name and author.
            }
        }

        $found = app(BookLookup::class)->find([
            'title' => $own['title'],
            'authors' => array_values((array) $own['authors']),
            'isbn' => $own['isbn'],
        ]);

        // What the book already has wins (the ePub's own text, or an edit),
        // then the ePub, then the catalogue.
        return [
            'description' => $novel->description ?: ($own['description'] ?: $found['description']),
            'author' => $novel->author ?: implode(', ', (array) $own['authors']),
            'no_of_chapters' => $novel->chapters()->count(),
            'image' => $found['cover_candidates'][0] ?? null,
            'genres' => array_values(array_unique(array_merge(BookLibrary::subjectTags((array) $own['subjects']), $found['genres']))),
            'cover_candidates' => $found['cover_candidates'],
        ];
    }

    public function downloadLane(Novel $novel): ?string
    {
        return 'books';
    }
}
