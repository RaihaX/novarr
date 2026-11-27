<?php

namespace App\Sources;

use App\Books\BookLookup;
use App\Comics\ComicMetadata;
use App\Comics\ComicVine;
use App\Novel;

/**
 * The "source" a reader's request search of medium 'comic' asks
 * (App\Services\Requests\RequestSearch): Comic Vine's series (with a key,
 * App\Comics\ComicVine) first, then the book catalogues' comics and graphic
 * novels (App\Books\BookLookup results whose categories say "Comics &
 * Graphic Novels", "Comics", "Graphic novels"…). A pasted Goodreads /
 * Amazon link is read by RequestSearch itself (BookLinkReader), as for books.
 *
 * Search only: it claims no novel and no URL, is not in the source registry,
 * and nothing is downloaded from it. A request for one of its comics is
 * fulfilled by a download or a drop-in to the comics inbox.
 *
 * No Discover shelf: Comic Vine has no cheap "popular / new" list (its
 * issues sorted by date are every publisher's weekly floppies), so the
 * Requests page shows none for comics.
 */
class ComicCatalogueSource extends AbstractSource
{
    public const KEY = 'comic-catalogue';

    public function name(): string
    {
        return self::KEY;
    }

    public function medium(): string
    {
        return Novel::MEDIUM_COMIC;
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
        return 'Comic catalogues';
    }

    public function discoverHost(): string
    {
        return ComicVine::enabled() ? 'Comic Vine and the book catalogues' : 'the book catalogues';
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

    /** Discover-shaped items (name, author, cover, description, url, status = start year), Comic Vine first. */
    public function discover(string $type, ?string $query = null): ?array
    {
        $query = trim((string) $query);
        if ($type !== 'search' || $query === '') {
            return [];
        }
        $items = [];
        $seen = [];
        foreach (app(ComicVine::class)->searchVolumes($query, null, 12) as $volume) {
            if ($volume['url'] === null) {
                continue;
            }
            $items[] = [
                'name' => $volume['name'],
                'author' => $volume['people'] !== [] ? implode(', ', array_slice($volume['people'], 0, 3)) : null,
                'cover' => $volume['cover_url'],
                'description' => trim(implode(' · ', array_filter([$volume['publisher'], $volume['issues'] ? $volume['issues'] . ' issues' : null])) . ($volume['description'] ? "\n\n" . mb_substr($volume['description'], 0, 600) : '')) ?: null,
                'url' => $volume['url'],
                'status' => $volume['start_year'] !== null ? (string) $volume['start_year'] : null,
            ];
            $seen[mb_strtolower($volume['name'])] = true;
        }

        if (BookLookup::sources() !== []) {
            $digits = preg_replace('/[^0-9X]/', '', strtoupper($query)) ?? '';
            $isIsbn = in_array(strlen($digits), [10, 13], true) && preg_match('/^[0-9X\s-]+$/i', $query);
            try {
                $books = app(BookLookup::class)->candidates($isIsbn ? ['isbn' => $digits] : ['title' => $query], 15);
            } catch (\Throwable) {
                $books = [];
            }
            foreach ($books as $book) {
                // An ISBN names one book: trust it; a title search keeps only comics.
                if (!$isIsbn && !ComicMetadata::isComicBook($book)) {
                    continue;
                }
                $url = $book['url'] ?? (!empty($book['isbn']) ? 'https://openlibrary.org/isbn/' . $book['isbn'] : null);
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
        }

        return $items;
    }
}
