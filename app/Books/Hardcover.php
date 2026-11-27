<?php

namespace App\Books;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hardcover (hardcover.app, the Goodreads alternative) through its GraphQL
 * API: POST https://api.hardcover.app/v1/graphql with
 * `authorization: Bearer <token>` (config novarr.books.hardcover_token,
 * env NOVARR_HARDCOVER_TOKEN; a token pasted with its "Bearer " prefix
 * works too). Without a token everything here is off and answers empty,
 * without a request.
 *
 * Used for:
 *   - book candidates (BookLookup::candidates()): the `search` endpoint
 *     (query_type "Book", Hardcover's own Typesense index: title, ISBNs,
 *     series and author names), with series + position, rating and how
 *     many readers saved it;
 *   - series for books without one (BookSeries::backfillFromHardcover(),
 *     `books:series --hardcover`);
 *   - the Requests page's Discover shelf (BookCatalogueSource):
 *     "Trending on Hardcover" = `books_trending(duration: week)`, the books
 *     the most readers finished this week; "Popular on Hardcover" =
 *     `books_trending(duration: all)`, the most finished of all time. Both
 *     answer ranked ids only; a second query (`books(where: {id: {_in:}})`)
 *     reads their titles, authors and covers.
 *
 * Hardcover allows 60 requests a minute (a burst of 10): calls are spaced
 * by novarr.books.hardcover_interval_ms (1 s) and answers are cached a day
 * (CatalogueHttp). Every failure (no token, 401/403/429, a GraphQL error,
 * a timeout) is an empty answer, logged.
 */
class Hardcover
{
    public const ENDPOINT = 'https://api.hardcover.app/v1/graphql';

    public const BOOK_URL = 'https://hardcover.app/books/';

    /** books_trending's windows (a GraphQL enum, written into the query). */
    public const DURATIONS = ['week', 'month', 'three_month', 'one_year', 'all'];

    /** The fields read of a book in the `books` table (the Discover lists). */
    private const BOOK_FIELDS = 'id title subtitle slug release_year description users_count rating image { url } contributions { author { name } }';

    public static function token(): string
    {
        $token = trim((string) config('novarr.books.hardcover_token', ''));
        // "Bearer eyJ…" as copied from Hardcover's API page, or the bare token.
        $token = preg_replace('/^bearer\s+/i', '', $token) ?? $token;

        return trim($token);
    }

    public static function enabled(): bool
    {
        return static::token() !== '';
    }

    /** The authorization header's value, always with one "Bearer ". */
    public static function authorization(): string
    {
        return 'Bearer ' . static::token();
    }

    /**
     * One GraphQL request (throttled, cached); its `data`, or null.
     */
    public function query(string $query, array $variables = []): ?array
    {
        if (!static::enabled()) {
            return null;
        }
        $json = CatalogueHttp::postJson(
            'Hardcover',
            self::ENDPOINT,
            ['query' => $query, 'variables' => $variables === [] ? new \stdClass() : $variables],
            ['authorization' => static::authorization()],
            fn() => static::throttle(),
        );

        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /**
     * Keep calls at least novarr.books.hardcover_interval_ms apart (across
     * processes, through the cache): 60 a minute.
     */
    public static function throttle(): void
    {
        $interval = max(0, (int) config('novarr.books.hardcover_interval_ms', 1000));
        if ($interval === 0) {
            return;
        }
        try {
            $last = (float) Cache::get('hardcover_last_call', 0);
            $wait = $last > 0 ? (int) round(($last + $interval / 1000 - microtime(true)) * 1_000_000) : 0;
            if ($wait > 0) {
                usleep(min($wait, $interval * 1000));
            }
            Cache::put('hardcover_last_call', microtime(true), 60);
        } catch (\Throwable) {
            // No cache: no spacing across processes.
        }
    }

    /**
     * Search hits (Typesense documents) for a free-text query, or null when
     * Hardcover could not be asked.
     *
     * @return ?list<array>
     */
    public function searchBooks(string $query, int $perPage = 10): ?array
    {
        $query = trim($query);
        if ($query === '' || !static::enabled()) {
            return null;
        }
        $data = $this->query(
            'query Search($q: String!, $n: Int!) { search(query: $q, query_type: "Book", per_page: $n, page: 1) { results } }',
            ['q' => mb_substr($query, 0, 200), 'n' => max(1, min(25, $perPage))],
        );
        if ($data === null) {
            return null;
        }
        $results = $data['search']['results'] ?? null;
        // `results` is Typesense's answer (a JSON scalar: an object, or a string of one).
        if (is_string($results)) {
            $results = json_decode($results, true);
        }
        $hits = [];
        foreach ((array) ($results['hits'] ?? []) as $hit) {
            $doc = is_array($hit) ? ($hit['document'] ?? $hit) : null;
            if (is_array($doc) && trim((string) ($doc['title'] ?? '')) !== '') {
                $hits[] = $doc;
            }
        }

        return $hits;
    }

    /**
     * BookLookup candidates for a title (+ author) or an ISBN.
     *
     * @return list<array>
     */
    public function candidates(string $title, string $author, ?string $isbn, int $limit = 8): array
    {
        $query = $isbn ?? trim(BookLookup::cleanTitle($title) . ' ' . $author);
        $out = [];
        foreach ($this->searchBooks($query, $limit) ?? [] as $doc) {
            $out[] = static::candidate($doc, $isbn);
        }

        return $out;
    }

    /** A search document as a BookLookup candidate. */
    public static function candidate(array $doc, ?string $isbn = null): array
    {
        $title = trim((string) $doc['title']);
        $subtitle = trim((string) ($doc['subtitle'] ?? ''));
        $authors = static::authors($doc);
        $isbns = array_values(array_filter(array_map('strval', (array) ($doc['isbns'] ?? [])), fn($v) => strlen($v) === 13 && Epub::validIsbn($v)));
        $series = static::series($doc);
        $slug = trim((string) ($doc['slug'] ?? ''));
        $genres = array_values(array_filter(array_map(fn($g) => is_array($g) ? (string) ($g['tag'] ?? $g['name'] ?? '') : (string) $g, (array) ($doc['genres'] ?? []))));
        $year = $doc['release_year'] ?? null;

        return BookLookup::shape([
            'source' => 'hardcover',
            'source_name' => 'Hardcover',
            'key' => 'hardcover:' . (string) ($doc['id'] ?? ($slug !== '' ? $slug : md5($title))),
            'title' => $title . ($subtitle !== '' ? ': ' . $subtitle : ''),
            'authors' => $authors,
            'description' => !empty($doc['description']) ? Epub::plainDescription((string) $doc['description']) : null,
            'genres' => normalizeGenres($genres),
            'year' => is_numeric($year) && (int) $year > 0 ? (int) $year : null,
            // The searched ISBN when it is one of the book's, else its first.
            'isbn' => $isbn !== null && in_array($isbn, $isbns, true) ? $isbn : ($isbns[0] ?? ($isbn !== null && strlen($isbn) === 13 ? $isbn : null)),
            'isbns' => $isbns,
            'cover_url' => static::image($doc),
            'url' => $slug !== '' ? self::BOOK_URL . $slug : null,
            'series' => $series['series'] ?? null,
            'series_index' => $series['series_index'] ?? null,
            'rating' => is_numeric($doc['rating'] ?? null) && (float) $doc['rating'] > 0 ? round((float) $doc['rating'], 2) : null,
            'ratings_count' => is_numeric($doc['ratings_count'] ?? null) ? (int) $doc['ratings_count'] : null,
            'users_count' => is_numeric($doc['users_count'] ?? null) ? (int) $doc['users_count'] : null,
        ]);
    }

    /**
     * The series of a book (title + author) on Hardcover: [] when Hardcover
     * knows the book but no series (or does not know the book), null when it
     * could not be asked (no token, a failure: ask again later).
     *
     * @return ?array{series?: string, series_index?: ?float}
     */
    public function seriesFor(string $title, ?string $author): ?array
    {
        $hits = $this->searchBooks(trim(BookLookup::cleanTitle($title) . ' ' . (string) $author), 5);
        if ($hits === null) {
            return null;
        }
        foreach ($hits as $doc) {
            if (!BookLookup::sameTitleAndAuthor($title, $author !== null && trim($author) !== '' ? [$author] : [], (string) $doc['title'], static::authors($doc))) {
                continue;
            }
            $series = static::series($doc);

            return $series ?? [];
        }

        return [];
    }

    /**
     * One page of a Discover list as BookCatalogueSource items, or null when
     * Hardcover could not be asked.
     *
     * @param  string  $duration  books_trending's window: week, month, three_month, one_year, all
     * @return ?array{items: list<array>, has_more: bool}
     */
    public function trending(string $duration, int $page, int $perPage): ?array
    {
        if (!static::enabled() || !in_array($duration, self::DURATIONS, true)) {
            return null;
        }
        $data = $this->query(
            'query Trending($limit: Int!, $offset: Int!) { books_trending(duration: ' . $duration . ', limit: $limit, offset: $offset) { ids error } }',
            ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );
        $ids = array_values(array_filter(array_map('intval', (array) ($data['books_trending']['ids'] ?? [])), fn($id) => $id > 0));
        if ($data === null || (!empty($data['books_trending']['error']) && $ids === [])) {
            return null;
        }
        if ($ids === []) {
            return ['items' => [], 'has_more' => false];
        }

        $books = $this->query('query Books($ids: [Int!]) { books(where: {id: {_in: $ids}}) { ' . self::BOOK_FIELDS . ' } }', ['ids' => $ids]);
        if ($books === null) {
            return null;
        }
        $byId = [];
        foreach ((array) ($books['books'] ?? []) as $book) {
            if (is_array($book) && isset($book['id'])) {
                $byId[(int) $book['id']] = $book;
            }
        }
        $items = [];
        // In Hardcover's ranked order.
        foreach ($ids as $id) {
            if (isset($byId[$id]) && ($item = static::discoverItem($byId[$id])) !== null) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'has_more' => count($ids) >= $perPage];
    }

    /** A `books` row (or a search document) as a Discover item; null without a title or slug. */
    public static function discoverItem(array $book): ?array
    {
        $title = trim((string) ($book['title'] ?? ''));
        $slug = trim((string) ($book['slug'] ?? ''));
        if ($title === '' || $slug === '') {
            return null;
        }
        $year = $book['release_year'] ?? null;

        return [
            'name' => $title,
            'author' => implode(', ', array_slice(static::authors($book), 0, 3)) ?: null,
            'cover' => static::image($book),
            'description' => !empty($book['description']) ? Epub::plainDescription((string) $book['description']) : null,
            'url' => self::BOOK_URL . $slug,
            'status' => is_numeric($year) && (int) $year > 0 ? (string) (int) $year : null,
        ];
    }

    /** @return list<string> */
    public static function authors(array $doc): array
    {
        $names = [];
        if (!empty($doc['author_names'])) {
            $names = (array) $doc['author_names'];
        } else {
            foreach ((array) ($doc['contributions'] ?? $doc['cached_contributors'] ?? []) as $c) {
                if (!is_array($c)) {
                    continue;
                }
                // Only authors when the role is given ("Narrator", "Illustrator" are not).
                $role = strtolower(trim((string) ($c['contribution'] ?? '')));
                if ($role !== '' && $role !== 'author') {
                    continue;
                }
                $names[] = $c['author']['name'] ?? $c['name'] ?? null;
            }
        }

        return array_values(array_unique(array_filter(array_map(fn($n) => trim((string) $n), $names), fn($n) => $n !== '')));
    }

    public static function image(array $doc): ?string
    {
        $image = $doc['image'] ?? $doc['cached_image'] ?? null;
        $url = is_array($image) ? ($image['url'] ?? null) : $image;
        $url = is_string($url) ? trim($url) : '';

        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://') ? str_replace('http://', 'https://', $url) : null;
    }

    /**
     * The featured series and the book's position in it.
     *
     * @return ?array{series: string, series_index: ?float}
     */
    public static function series(array $doc): ?array
    {
        $featured = $doc['featured_series'] ?? $doc['featured_book_series'] ?? null;
        $name = null;
        $position = null;
        if (is_array($featured)) {
            $name = $featured['series']['name'] ?? $featured['name'] ?? null;
            $position = $featured['position'] ?? null;
        }
        if (($name === null || trim((string) $name) === '') && !empty($doc['series_names'])) {
            $name = ((array) $doc['series_names'])[0] ?? null;
        }
        $position ??= $doc['featured_series_position'] ?? null;
        $name = mb_substr(trim((string) $name), 0, 255);
        if ($name === '') {
            return null;
        }

        return ['series' => $name, 'series_index' => BookSeries::index($position)];
    }
}
