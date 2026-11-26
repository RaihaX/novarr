<?php

namespace App\Sources;

use App\Books\BookLookup;
use App\Novel;

/**
 * The book catalogues (Google Books and Open Library, App\Books\BookLookup)
 * as the one "source" a reader's request search of medium 'book' asks
 * (App\Services\Requests\RequestSearch). Goodreads has had no public API
 * since 2020; these two cover the same books, with covers and ISBNs.
 *
 * Search only: it claims no novel and no URL, is not in the source registry
 * (so never on Discover, the health page or a scrape), and nothing is ever
 * downloaded from it. A request for one of its books is fulfilled by the
 * owner uploading the ePub (App\Books\BookUploads::attach()).
 */
class BookCatalogueSource extends AbstractSource
{
    public const KEY = 'book-catalogue';

    public function name(): string
    {
        return self::KEY;
    }

    public function medium(): string
    {
        return Novel::MEDIUM_BOOK;
    }

    public function matches(Novel $novel): bool
    {
        return false;
    }

    public function matchesUrl(string $url): bool
    {
        return false;
    }

    public function discoverLabel(): string
    {
        return 'Book catalogues';
    }

    public function discoverHost(): string
    {
        return 'Google Books and Open Library';
    }

    public function discoverLists(): array
    {
        return ['search'];
    }

    public function tableOfContents(Novel $novel): array
    {
        return [];
    }

    public function metadata(Novel $novel): array
    {
        return [];
    }

    /** Discover-shaped items (name, author, cover, description, url), best match first. */
    public function discover(string $type, ?string $query = null): ?array
    {
        $query = trim((string) $query);
        if ($type !== 'search' || $query === '') {
            return [];
        }

        // An ISBN typed as the query is looked up as one.
        $digits = preg_replace('/[^0-9X]/', '', strtoupper($query)) ?? '';
        $isIsbn = in_array(strlen($digits), [10, 13], true) && preg_match('/^[0-9X\s-]+$/i', $query);
        $candidates = app(BookLookup::class)->candidates($isIsbn ? ['isbn' => $digits] : ['title' => $query], 15);

        $items = [];
        foreach ($candidates as $book) {
            $url = $book['url'] ?? null;
            if (!$url && !empty($book['isbn'])) {
                $url = 'https://openlibrary.org/isbn/' . $book['isbn'];
            }
            if (!$url && str_starts_with((string) ($book['key'] ?? ''), 'google:')) {
                $url = 'https://books.google.com/books?id=' . rawurlencode(substr((string) $book['key'], 7));
            }
            if (!$url || trim((string) $book['title']) === '') {
                continue;
            }
            $items[] = [
                'name' => (string) $book['title'],
                'author' => implode(', ', array_slice((array) ($book['authors'] ?? []), 0, 3)) ?: null,
                'cover' => $book['cover_url'] ?? null,
                'description' => $book['description'] ?? null,
                'url' => (string) $url,
                'status' => !empty($book['year']) ? (string) $book['year'] : null,
            ];
        }

        return $items;
    }
}
