<?php

namespace App\Books;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fills what an ePub leaves out from two free catalogues: Google Books
 * (description, categories, publisher, year) and Open Library (covers,
 * which are larger than Google's). Looked up by ISBN when the ePub has
 * one, else by title and author; a result is used only when its title
 * (and author, when both are known) agree with the book's.
 *
 * Every failure (no network, a quota answer, nothing found) is an empty
 * answer: a book is fully readable without any of this.
 */
class BookLookup
{
    public const GOOGLE = 'https://www.googleapis.com/books/v1/volumes';
    public const OPEN_LIBRARY_SEARCH = 'https://openlibrary.org/search.json';
    public const OPEN_LIBRARY_COVERS = 'https://covers.openlibrary.org/b';

    /** Lowest title similarity (0–1) for a catalogue result to count as the book. */
    public const MIN_TITLE_SIMILARITY = 0.8;

    /**
     * @param  array{title: ?string, authors: string[], isbn: ?string, language?: ?string}  $book
     * @return array{description: ?string, genres: string[], publisher: ?string, year: ?int, isbn: ?string, cover_candidates: string[]}
     */
    public function find(array $book): array
    {
        $found = ['description' => null, 'genres' => [], 'publisher' => null, 'year' => null, 'isbn' => $book['isbn'] ?? null, 'cover_candidates' => []];
        if (!config('novarr.books.lookup', true) || (trim((string) ($book['title'] ?? '')) === '' && empty($book['isbn']))) {
            return $found;
        }

        $google = $this->google($book);
        if ($google !== null) {
            $found['description'] = $google['description'];
            $found['genres'] = $google['genres'];
            $found['publisher'] = $google['publisher'];
            $found['year'] = $google['year'];
            $found['isbn'] ??= $google['isbn'];
        }

        // Covers: Open Library by ISBN, then its search, then Google's own (small) image.
        if ($found['isbn'] !== null) {
            $found['cover_candidates'][] = self::OPEN_LIBRARY_COVERS . "/isbn/{$found['isbn']}-L.jpg?default=false";
        }
        $openLibrary = $this->openLibrary($book);
        if ($openLibrary !== null) {
            if ($openLibrary['cover'] !== null) {
                $found['cover_candidates'][] = $openLibrary['cover'];
            }
            $found['year'] ??= $openLibrary['year'];
        }
        if ($google !== null && $google['cover'] !== null) {
            $found['cover_candidates'][] = $google['cover'];
        }
        $found['cover_candidates'] = array_values(array_unique($found['cover_candidates']));

        return $found;
    }

    /** @return ?array{description: ?string, genres: string[], publisher: ?string, year: ?int, isbn: ?string, cover: ?string} */
    private function google(array $book): ?array
    {
        $query = !empty($book['isbn'])
            ? 'isbn:' . $book['isbn']
            : trim('intitle:"' . self::cleanTitle((string) $book['title']) . '"' . (!empty($book['authors'][0]) ? ' inauthor:"' . $book['authors'][0] . '"' : ''));

        $params = ['q' => $query, 'maxResults' => 5, 'printType' => 'books'];
        if ($key = (string) config('novarr.books.google_books_key', '')) {
            $params['key'] = $key;
        }

        $json = $this->getJson(self::GOOGLE, $params);
        foreach ((array) ($json['items'] ?? []) as $item) {
            $info = (array) ($item['volumeInfo'] ?? []);
            if (empty($book['isbn']) && !$this->sameBook($book, (string) ($info['title'] ?? ''), (array) ($info['authors'] ?? []))) {
                continue;
            }

            $isbn = null;
            foreach ((array) ($info['industryIdentifiers'] ?? []) as $identifier) {
                if (($identifier['type'] ?? '') === 'ISBN_13' && Epub::validIsbn((string) ($identifier['identifier'] ?? ''))) {
                    $isbn = (string) $identifier['identifier'];
                }
            }
            $cover = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
            $year = preg_match('/^(\d{4})/', (string) ($info['publishedDate'] ?? ''), $m) ? (int) $m[1] : null;

            return [
                'description' => !empty($info['description']) ? Epub::plainDescription((string) $info['description']) : null,
                'genres' => self::genresFromCategories((array) ($info['categories'] ?? [])),
                'publisher' => !empty($info['publisher']) ? (string) $info['publisher'] : null,
                'year' => $year,
                'isbn' => $isbn,
                // Google serves http thumbnails with a page-curl; https and no curl, a bit larger.
                'cover' => $cover ? str_replace(['http://', '&edge=curl'], ['https://', ''], (string) $cover) : null,
            ];
        }

        return null;
    }

    /** @return ?array{cover: ?string, year: ?int} */
    private function openLibrary(array $book): ?array
    {
        $title = trim((string) ($book['title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $params = ['title' => self::cleanTitle($title), 'limit' => 5, 'fields' => 'title,author_name,cover_i,first_publish_year'];
        if (!empty($book['authors'][0])) {
            $params['author'] = $book['authors'][0];
        }

        $json = $this->getJson(self::OPEN_LIBRARY_SEARCH, $params);
        foreach ((array) ($json['docs'] ?? []) as $doc) {
            if (!$this->sameBook($book, (string) ($doc['title'] ?? ''), (array) ($doc['author_name'] ?? []))) {
                continue;
            }

            return [
                'cover' => !empty($doc['cover_i']) ? self::OPEN_LIBRARY_COVERS . '/id/' . (int) $doc['cover_i'] . '-L.jpg' : null,
                'year' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
            ];
        }

        return null;
    }

    private function getJson(string $url, array $params): ?array
    {
        try {
            $response = Http::timeout(max(1, (int) config('novarr.books.timeout', 15)))
                ->acceptJson()
                ->withUserAgent('Novarr (self-hosted reading server)')
                ->get($url, $params);
        } catch (\Throwable $e) {
            Log::info('Book lookup failed: ' . $e->getMessage(), ['url' => $url]);

            return null;
        }
        if (!$response->successful()) {
            Log::info('Book lookup answered ' . $response->status(), ['url' => $url]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /** Same title (and the same author when both sides name one). */
    private function sameBook(array $book, string $title, array $authors): bool
    {
        if (self::similarity((string) ($book['title'] ?? ''), $title) < self::MIN_TITLE_SIMILARITY) {
            return false;
        }
        $ours = array_map([self::class, 'normalize'], (array) ($book['authors'] ?? []));
        $theirs = array_map([self::class, 'normalize'], $authors);
        if ($ours === [] || $theirs === []) {
            return true;
        }
        foreach ($ours as $a) {
            foreach ($theirs as $b) {
                // "Mary Shelley" / "Mary Wollstonecraft Shelley": the same surname is enough.
                if ($a === $b || self::surname($a) === self::surname($b)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function similarity(string $a, string $b): float
    {
        $a = self::normalize(self::cleanTitle($a));
        $b = self::normalize(self::cleanTitle($b));
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /** A title without its subtitle or a bracketed note ("Dune (Dune #1)", "Title: A Novel"). */
    public static function cleanTitle(string $title): string
    {
        $title = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*/u', ' ', $title) ?? $title;
        $title = preg_split('/\s*:\s+/u', $title)[0];

        return trim($title);
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(\Illuminate\Support\Str::ascii($value));
        $value = preg_replace('/^(the|a|an)\s+/', '', $value) ?? $value;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');
    }

    private static function surname(string $name): string
    {
        $parts = explode(' ', $name);

        return (string) end($parts);
    }

    /**
     * Google's "Fiction / Science Fiction / Space Opera" as tags, without
     * the catch-alls ("General").
     *
     * @return string[]
     */
    public static function genresFromCategories(array $categories): array
    {
        $genres = [];
        foreach ($categories as $category) {
            foreach (preg_split('#\s*/\s*#', (string) $category) as $part) {
                $part = trim($part);
                if ($part !== '' && strcasecmp($part, 'General') !== 0 && mb_strlen($part) <= 40) {
                    $genres[] = $part;
                }
            }
        }

        return normalizeGenres($genres);
    }
}
