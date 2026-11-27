<?php

namespace App\Sources;

use App\Books\BookLookup;
use App\Books\Hardcover;
use App\Novel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The book catalogues (Google Books, Open Library, Hardcover, Apple Books: App\Books\BookLookup)
 * as the one "source" a reader's request search of medium 'book' asks
 * (App\Services\Requests\RequestSearch). Goodreads has had no public API
 * since 2020; these cover the same books, with covers and ISBNs (Hardcover,
 * its successor in spirit, with a token).
 *
 * Search only: it claims no novel and no URL, is not in the source registry
 * (so never on Discover, the health page or a scrape), and nothing is ever
 * downloaded from it. A request for one of its books is fulfilled by the
 * owner uploading the ePub (App\Books\BookUploads::attach()).
 *
 * It also lists Open Library's charts for the Requests page's Discover
 * shelf (App\Services\Requests\RequestBrowse): 'popular' (trending this
 * week), 'trending' (trending today) and 'classics' (the classics subject),
 * paged (PagedDiscover), and with a Hardcover token 'hardcover_trending'
 * (the books most Hardcover readers finished this week) and
 * 'hardcover_popular' (most finished of all time). RequestBrowse caches
 * the pages.
 */
class BookCatalogueSource extends AbstractSource implements PagedDiscover
{
    public const KEY = 'book-catalogue';

    public const OPEN_LIBRARY = 'https://openlibrary.org';

    /** Items asked of Open Library per shelf page. */
    public const PAGE_SIZE = 24;

    /** Shelf list id => Open Library path (trending answers `works` with cover_i / author_name; a subject `works` with cover_id / authors). */
    public const LISTS = [
        'popular' => '/trending/weekly.json',
        'trending' => '/trending/daily.json',
        'classics' => '/subjects/classics.json',
    ];

    /**
     * Shelf list id => Hardcover's books_trending window (App\Books\Hardcover::trending()):
     * the books the most readers finished this week, and of all time. Offered
     * only with a Hardcover token.
     */
    public const HARDCOVER_LISTS = [
        'hardcover_trending' => 'week',
        'hardcover_popular' => 'all',
    ];

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
        $names = array_map(fn($s) => BookLookup::SOURCE_NAMES[$s], BookLookup::sources());

        return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : ($names[0] ?? 'Google Books and Open Library');
    }

    public function discoverLists(): array
    {
        return ['search', ...array_keys(self::LISTS), ...(BookLookup::sourceEnabled('hardcover') ? array_keys(self::HARDCOVER_LISTS) : [])];
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
        if (isset(self::LISTS[$type]) || isset(self::HARDCOVER_LISTS[$type])) {
            $page = $this->discoverPage($type, null, 1);

            return $page === null ? null : $page['items'];
        }
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

    /**
     * One page of an Open Library chart, as discover() items (the url is
     * the work's page, as a book search hit's). Null when Open Library
     * could not be reached.
     */
    public function discoverPage(string $type, ?string $query, int $page): ?array
    {
        if (isset(self::HARDCOVER_LISTS[$type])) {
            if (!BookLookup::sourceEnabled('hardcover') || $page < 1 || $page > self::MAX_PAGE) {
                return ['items' => [], 'has_more' => false];
            }
            $answer = app(Hardcover::class)->trending(self::HARDCOVER_LISTS[$type], $page, self::PAGE_SIZE);

            return $answer === null ? null : ['items' => $answer['items'], 'has_more' => $answer['has_more'] && $page < self::MAX_PAGE];
        }
        if (!isset(self::LISTS[$type]) || $page < 1 || $page > self::MAX_PAGE) {
            return ['items' => [], 'has_more' => false];
        }
        $subject = str_starts_with(self::LISTS[$type], '/subjects/');
        $params = $subject
            ? ['limit' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE]
            : ['limit' => self::PAGE_SIZE, 'page' => $page];

        try {
            $response = Http::timeout(max(1, (int) config('novarr.books.timeout', 15)))
                ->acceptJson()
                ->withUserAgent('Novarr (self-hosted reading server)')
                ->get(self::OPEN_LIBRARY . self::LISTS[$type], $params);
        } catch (\Throwable $e) {
            Log::info("Book catalogue: Open Library {$type} failed: " . $e->getMessage());

            return null;
        }
        $json = $response->successful() ? $response->json() : null;
        if (!is_array($json) || !is_array($json['works'] ?? null)) {
            Log::info("Book catalogue: Open Library {$type} answered " . $response->status());

            return null;
        }

        $items = [];
        foreach ($json['works'] as $work) {
            if (is_array($work) && ($item = self::workItem($work)) !== null) {
                $items[] = $item;
            }
        }
        $hasMore = $subject
            ? ($page * self::PAGE_SIZE) < (int) ($json['work_count'] ?? 0)
            : count($json['works']) >= self::PAGE_SIZE;

        return ['items' => $items, 'has_more' => $hasMore && $page < self::MAX_PAGE];
    }

    /** An Open Library work (trending or subject shape) as a discover() item; null without a title or key. */
    public static function workItem(array $work): ?array
    {
        $title = trim((string) ($work['title'] ?? ''));
        $key = (string) ($work['key'] ?? '');
        if ($title === '' || !str_starts_with($key, '/works/')) {
            return null;
        }
        $authors = isset($work['author_name'])
            ? (array) $work['author_name']
            : array_map(fn($a) => is_array($a) ? (string) ($a['name'] ?? '') : (string) $a, (array) ($work['authors'] ?? []));
        $authors = array_values(array_filter(array_map('trim', array_map('strval', $authors)), fn($a) => $a !== ''));
        $cover = (int) ($work['cover_i'] ?? $work['cover_id'] ?? 0);
        $year = $work['first_publish_year'] ?? null;

        return [
            'name' => $title,
            'author' => implode(', ', array_slice($authors, 0, 3)) ?: null,
            'cover' => $cover > 0 ? BookLookup::OPEN_LIBRARY_COVERS . '/id/' . $cover . '-M.jpg' : null,
            'description' => null,
            'url' => self::OPEN_LIBRARY . $key,
            'status' => is_numeric($year) ? (string) (int) $year : null,
        ];
    }
}
