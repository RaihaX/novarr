<?php

namespace App\Books;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fills what an ePub leaves out from the book catalogues: Google Books
 * (description, categories, publisher, year), Open Library (covers, which
 * are larger than Google's), Hardcover (App\Books\Hardcover: series,
 * ratings, popularity; needs a token) and Apple Books (App\Books\AppleBooks:
 * 1200 px covers). Looked up by ISBN when the ePub has one, else by title
 * and author; a result is used only when its title (and author, when both
 * are known) agree with the book's.
 *
 * Each catalogue can be switched off (novarr.books.sources, env
 * NOVARR_BOOK_SOURCES="google,openlibrary,hardcover,itunes"). Every
 * failure (no network, a quota answer, nothing found, a source down) is an
 * empty answer from that source: the others still answer, and a book is
 * fully readable without any of this.
 */
class BookLookup
{
    /** Every catalogue, in the order they are asked. */
    public const SOURCES = ['google', 'openlibrary', 'hardcover', 'itunes'];

    /** Whose description a merged candidate keeps, best first. */
    public const DESCRIPTION_ORDER = ['google', 'hardcover', 'openlibrary', 'itunes'];

    /** Whose cover a merged candidate shows, largest first (iTunes 1200 px, Open Library L, Hardcover, Google's thumbnail). */
    public const COVER_ORDER = ['itunes', 'openlibrary', 'hardcover', 'google'];

    public const SOURCE_NAMES = ['google' => 'Google Books', 'openlibrary' => 'Open Library', 'hardcover' => 'Hardcover', 'itunes' => 'Apple Books'];

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

        $google = static::sourceEnabled('google') ? $this->google($book) : null;
        if ($google !== null) {
            $found['description'] = $google['description'];
            $found['genres'] = $google['genres'];
            $found['publisher'] = $google['publisher'];
            $found['year'] = $google['year'];
            $found['isbn'] ??= $google['isbn'];
        }

        // Hardcover and Apple Books: only the same book (title and author agree, or the ISBN).
        $title = trim((string) ($book['title'] ?? ''));
        $authors = array_values(array_filter(array_map('strval', (array) ($book['authors'] ?? []))));
        $isbn = !empty($book['isbn']) ? (string) $book['isbn'] : null;
        $hardcover = null;
        if (static::sourceEnabled('hardcover')) {
            foreach ($this->safely('hardcover', fn() => app(Hardcover::class)->candidates($title, $authors[0] ?? '', $isbn, 5)) as $c) {
                if (($isbn !== null && in_array($isbn, $c['isbns'], true)) || ($title !== '' && $this->sameBook($book, $c['title'], $c['authors']))) {
                    $hardcover = $c;
                    break;
                }
            }
        }
        $apple = null;
        if (static::sourceEnabled('itunes') && $title !== '') {
            foreach ($this->safely('itunes', fn() => app(AppleBooks::class)->candidates($title, $authors[0] ?? '', 5)) as $c) {
                if ($this->sameBook($book, $c['title'], $c['authors'])) {
                    $apple = $c;
                    break;
                }
            }
        }
        $found['description'] ??= $hardcover['description'] ?? null;
        if ($found['genres'] === [] && $hardcover !== null) {
            $found['genres'] = $hardcover['genres'];
        }
        $found['year'] ??= $hardcover['year'] ?? null;

        // Covers: Open Library by ISBN (the edition's own), Apple's 1200 px
        // one, Open Library's search, Hardcover's, then Google's own (small) image.
        if ($found['isbn'] !== null && static::sourceEnabled('openlibrary')) {
            $found['cover_candidates'][] = self::OPEN_LIBRARY_COVERS . "/isbn/{$found['isbn']}-L.jpg?default=false";
        }
        if (!empty($apple['cover_url'])) {
            $found['cover_candidates'][] = $apple['cover_url'];
        }
        $openLibrary = static::sourceEnabled('openlibrary') ? $this->openLibrary($book) : null;
        if ($openLibrary !== null) {
            if ($openLibrary['cover'] !== null) {
                $found['cover_candidates'][] = $openLibrary['cover'];
            }
            $found['year'] ??= $openLibrary['year'];
        }
        if (!empty($hardcover['cover_url'])) {
            $found['cover_candidates'][] = $hardcover['cover_url'];
        }
        if ($google !== null && $google['cover'] !== null) {
            $found['cover_candidates'][] = $google['cover'];
        }
        $found['description'] ??= $apple['description'] ?? null;
        $found['cover_candidates'] = array_values(array_unique($found['cover_candidates']));

        return $found;
    }

    /**
     * The catalogues switched on (novarr.books.sources), in SOURCES order;
     * Hardcover only with a token.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        $configured = config('novarr.books.sources', self::SOURCES);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        $configured = array_map(fn($s) => strtolower(trim((string) $s)), (array) $configured);

        return array_values(array_filter(self::SOURCES, fn($s) => in_array($s, $configured, true)
            && ($s !== 'hardcover' || Hardcover::enabled())));
    }

    public static function sourceEnabled(string $source): bool
    {
        return in_array($source, static::sources(), true);
    }

    /** A catalogue's answer, or [] when it threw (a source down never fails a lookup). */
    private function safely(string $source, callable $ask): array
    {
        try {
            $answer = $ask();

            return is_array($answer) ? $answer : [];
        } catch (\Throwable $e) {
            Log::info("Book lookup: {$source} failed: " . $e->getMessage());

            return [];
        }
    }

    /**
     * A candidate with every key, in one order: what the review page and
     * the request search have always read, then what the newer catalogues
     * add (sources, covers, series, series_index, rating, ratings_count,
     * users_count, isbns).
     */
    public static function shape(array $c): array
    {
        $source = (string) ($c['source'] ?? '');
        $sourceName = (string) ($c['source_name'] ?? (self::SOURCE_NAMES[$source] ?? $source));

        return [
            'source' => $source,
            'source_name' => $sourceName,
            'key' => (string) ($c['key'] ?? ''),
            'title' => (string) ($c['title'] ?? ''),
            'authors' => array_values(array_map('strval', (array) ($c['authors'] ?? []))),
            'description' => $c['description'] ?? null,
            'genres' => array_values((array) ($c['genres'] ?? [])),
            'publisher' => $c['publisher'] ?? null,
            'year' => $c['year'] ?? null,
            'isbn' => $c['isbn'] ?? null,
            'cover_url' => $c['cover_url'] ?? null,
            'url' => $c['url'] ?? null,
            'language' => $c['language'] ?? null,
            'languages' => array_values((array) ($c['languages'] ?? [])),
            'sources' => array_values((array) ($c['sources'] ?? [$source])),
            'covers' => array_values((array) ($c['covers'] ?? (!empty($c['cover_url'])
                ? [['url' => (string) $c['cover_url'], 'source' => $source, 'source_name' => $sourceName]]
                : []))),
            'series' => $c['series'] ?? null,
            'series_index' => $c['series_index'] ?? null,
            'rating' => $c['rating'] ?? null,
            'ratings_count' => $c['ratings_count'] ?? null,
            'users_count' => $c['users_count'] ?? null,
            'isbns' => array_values(array_map('strval', (array) ($c['isbns'] ?? (!empty($c['isbn']) ? [$c['isbn']] : [])))),
        ];
    }

    /**
     * Every plausible match, for the owner to choose from (the review page):
     * the switched-on catalogues' results (sources()) for an ISBN and/or a
     * title and author, merged (merge()), closest title first. Nothing is
     * filtered out by similarity here, so a file with a wrong or missing
     * title can still be matched by searching for the right one.
     *
     * @param  array{title?: ?string, author?: ?string, isbn?: ?string, language?: ?string}  $query
     * @return array<int, array{source: string, source_name: string, key: string, title: string, authors: string[],
     *               description: ?string, genres: string[], publisher: ?string, year: ?int, isbn: ?string,
     *               cover_url: ?string, url: ?string, language: ?string, languages: string[], sources: string[],
     *               covers: list<array{url: string, source: string, source_name: string}>, series: ?string,
     *               series_index: ?float, rating: ?float, ratings_count: ?int, users_count: ?int, isbns: string[]}>
     */
    public function candidates(array $query, int $limit = 10): array
    {
        $title = trim((string) ($query['title'] ?? ''));
        $author = trim((string) ($query['author'] ?? ''));
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper((string) ($query['isbn'] ?? ''))) ?: null;
        if ($title === '' && $isbn === null) {
            return [];
        }

        $found = [];
        foreach (static::sources() as $source) {
            $items = $this->safely($source, fn() => match ($source) {
                'google' => $this->googleItems($title, $author, $isbn),
                'openlibrary' => $this->openLibraryDocs($title, $author, $isbn),
                'hardcover' => app(Hardcover::class)->candidates($title, $author, $isbn),
                // Apple's search is by words: an ISBN alone finds nothing useful.
                'itunes' => $title !== '' ? app(AppleBooks::class)->candidates($title, $author) : [],
            });
            foreach ($items as $item) {
                if (is_array($item)) {
                    $found[] = static::shape($item);
                }
            }
        }

        $list = static::merge($found);

        // Closest title first; among equals, an edition in the book's own
        // language, then the closer full title (subtitle included), then the
        // book more Hardcover readers saved.
        $language = Epub::languageCode($query['language'] ?? null);
        if ($title !== '') {
            $score = fn(array $c): array => [
                round(self::similarity($title, $c['title']), 2),
                $language !== null && (($c['language'] ?? null) === $language || in_array($language, (array) ($c['languages'] ?? []), true)) ? 1 : 0,
                self::fullSimilarity($title, $c['title']),
                (int) ($c['users_count'] ?? 0),
            ];
            usort($list, fn($a, $b) => $score($b) <=> $score($a));
        }

        return array_slice($list, 0, $limit);
    }

    /**
     * One entry per book. Google Books and Open Library results join by
     * ISBN, else by title and first author (as they always have); a
     * Hardcover or Apple Books result joins the entry holding one of its
     * ISBNs, else the first entry with the same title and first author it
     * has not joined yet. An entry keeps its first source's source /
     * source_name / key / title / authors and lists every source in
     * `sources`; its description is the first of DESCRIPTION_ORDER's, its
     * cover the largest (COVER_ORDER; every cover is in `covers`), its
     * series, rating and readers Hardcover's.
     *
     * @param  list<array>  $found  shape()d candidates, in the order asked
     * @return list<array>
     */
    public static function merge(array $found): array
    {
        $groups = [];
        $byKey = [];
        $byIsbn = [];
        $byName = [];
        foreach ($found as $candidate) {
            $nameKey = self::normalize($candidate['title']) . '|' . self::normalize($candidate['authors'][0] ?? '');
            $key = $candidate['isbn'] ?? $nameKey;
            $isbns = array_values(array_unique(array_filter(array_merge([$candidate['isbn']], $candidate['isbns']))));
            $into = null;
            if (in_array($candidate['source'], ['google', 'openlibrary'], true)) {
                $into = $byKey[$key] ?? null;
            } else {
                foreach ($isbns as $isbn) {
                    if (isset($byIsbn[$isbn]) && !in_array($candidate['source'], array_column($groups[$byIsbn[$isbn]], 'source'), true)) {
                        $into = $byIsbn[$isbn];
                        break;
                    }
                }
                // Only into an entry this source has not joined yet (its two editions stay two).
                foreach ($into === null ? ($byName[$nameKey] ?? []) : [] as $g) {
                    if (!in_array($candidate['source'], array_column($groups[$g], 'source'), true)) {
                        $into = $g;
                        break;
                    }
                }
            }
            if ($into === null) {
                $into = count($groups);
                $groups[$into] = [];
                $byKey[$key] ??= $into;
            }
            $groups[$into][] = $candidate;
            foreach ($isbns as $isbn) {
                $byIsbn[$isbn] ??= $into;
            }
            if (!in_array($into, $byName[$nameKey] ?? [], true)) {
                $byName[$nameKey][] = $into;
            }
        }

        return array_values(array_map([static::class, 'combine'], $groups));
    }

    /** @param  non-empty-list<array>  $group  one book's candidates, in the order asked */
    private static function combine(array $group): array
    {
        $entry = $group[0];
        $bySource = [];
        foreach ($group as $c) {
            $bySource[$c['source']] ??= $c;
        }
        foreach (array_slice($group, 1) as $c) {
            foreach (['publisher', 'year', 'isbn', 'url'] as $field) {
                $entry[$field] ??= $c[$field];
            }
            $entry['genres'] = array_values(array_unique(array_merge($entry['genres'], $c['genres'])));
            $entry['isbns'] = array_values(array_unique(array_merge($entry['isbns'], $c['isbns'])));
        }
        $entry['sources'] = array_keys($bySource);

        foreach (self::DESCRIPTION_ORDER as $source) {
            foreach ($group as $c) {
                if ($c['source'] === $source && !empty($c['description'])) {
                    $entry['description'] = $c['description'];
                    break 2;
                }
            }
        }

        $order = array_flip(self::COVER_ORDER);
        $withCover = array_values(array_filter($group, fn($c) => !empty($c['cover_url'])));
        usort($withCover, fn($a, $b) => ($order[$a['source']] ?? 99) <=> ($order[$b['source']] ?? 99));
        $covers = [];
        foreach ($withCover as $c) {
            if (!in_array($c['cover_url'], array_column($covers, 'url'), true)) {
                $covers[] = ['url' => (string) $c['cover_url'], 'source' => $c['source'], 'source_name' => $c['source_name']];
            }
        }
        $entry['covers'] = $covers;
        $entry['cover_url'] = $covers[0]['url'] ?? null;

        if (isset($bySource['hardcover'])) {
            foreach (['series', 'series_index', 'rating', 'ratings_count', 'users_count'] as $field) {
                $entry[$field] = $bySource['hardcover'][$field] ?? $entry[$field];
            }
        }

        return $entry;
    }

    /**
     * Same title (and the same author when both sides name one): the rule a
     * catalogue result must pass to count as the book.
     *
     * @param  string[]  $ours
     * @param  string[]  $theirs
     */
    public static function sameTitleAndAuthor(string $title, array $ours, string $theirTitle, array $theirs): bool
    {
        return (new static())->sameBook(['title' => $title, 'authors' => $ours], $theirTitle, $theirs);
    }

    private function googleItems(string $title, string $author, ?string $isbn): array
    {
        $query = $isbn !== null
            ? 'isbn:' . $isbn
            : 'intitle:"' . self::cleanTitle($title) . '"' . ($author !== '' ? ' inauthor:"' . $author . '"' : '');
        $params = ['q' => $query, 'maxResults' => 8, 'printType' => 'books'];
        if ($key = (string) config('novarr.books.google_books_key', '')) {
            $params['key'] = $key;
        }

        $out = [];
        foreach ((array) ($this->getJson(self::GOOGLE, $params)['items'] ?? []) as $item) {
            $info = (array) ($item['volumeInfo'] ?? []);
            if (trim((string) ($info['title'] ?? '')) === '') {
                continue;
            }
            $foundIsbn = null;
            foreach ((array) ($info['industryIdentifiers'] ?? []) as $identifier) {
                $value = (string) ($identifier['identifier'] ?? '');
                if (($identifier['type'] ?? '') === 'ISBN_13' && Epub::validIsbn($value)) {
                    $foundIsbn = $value;
                }
            }
            $cover = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
            $title = trim((string) $info['title']) . (!empty($info['subtitle']) ? ': ' . trim((string) $info['subtitle']) : '');
            $out[] = [
                'source' => 'google',
                'source_name' => 'Google Books',
                'key' => 'google:' . (string) ($item['id'] ?? md5($title)),
                'title' => $title,
                'authors' => array_values(array_map('strval', (array) ($info['authors'] ?? []))),
                'description' => !empty($info['description']) ? Epub::plainDescription((string) $info['description']) : null,
                'genres' => self::genresFromCategories((array) ($info['categories'] ?? [])),
                'publisher' => !empty($info['publisher']) ? (string) $info['publisher'] : null,
                'year' => preg_match('/^(\d{4})/', (string) ($info['publishedDate'] ?? ''), $m) ? (int) $m[1] : null,
                'isbn' => $foundIsbn,
                'cover_url' => $cover ? str_replace(['http://', '&edge=curl'], ['https://', ''], (string) $cover) : null,
                'url' => !empty($info['infoLink']) ? str_replace('http://', 'https://', (string) $info['infoLink']) : null,
                'language' => Epub::languageCode($info['language'] ?? null),
            ];
        }

        return $out;
    }

    private function openLibraryDocs(string $title, string $author, ?string $isbn): array
    {
        $params = ['limit' => 8, 'fields' => 'key,title,subtitle,author_name,cover_i,first_publish_year,isbn,publisher,language'];
        if ($isbn !== null) {
            $params['isbn'] = $isbn;
        } else {
            $params['title'] = self::cleanTitle($title);
            if ($author !== '') {
                $params['author'] = $author;
            }
        }

        $out = [];
        foreach ((array) ($this->getJson(self::OPEN_LIBRARY_SEARCH, $params)['docs'] ?? []) as $doc) {
            if (trim((string) ($doc['title'] ?? '')) === '') {
                continue;
            }
            $foundIsbn = $isbn;
            foreach ((array) ($doc['isbn'] ?? []) as $value) {
                if (strlen((string) $value) === 13 && Epub::validIsbn((string) $value)) {
                    $foundIsbn ??= (string) $value;
                    break;
                }
            }
            $title = trim((string) $doc['title']) . (!empty($doc['subtitle']) ? ': ' . trim((string) $doc['subtitle']) : '');
            $out[] = [
                'source' => 'openlibrary',
                'source_name' => 'Open Library',
                'key' => 'openlibrary:' . (string) ($doc['key'] ?? md5($title)),
                'title' => $title,
                'authors' => array_values(array_map('strval', (array) ($doc['author_name'] ?? []))),
                'description' => null,
                'genres' => [],
                'publisher' => isset($doc['publisher'][0]) ? (string) $doc['publisher'][0] : null,
                'year' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
                'isbn' => $foundIsbn,
                'cover_url' => !empty($doc['cover_i']) ? self::OPEN_LIBRARY_COVERS . '/id/' . (int) $doc['cover_i'] . '-L.jpg' : null,
                'url' => !empty($doc['key']) ? 'https://openlibrary.org' . $doc['key'] : null,
                // A work in several languages counts as the book's own when it is among them.
                'language' => null,
                'languages' => array_values(array_filter(array_map(fn($l) => Epub::languageCode((string) $l), (array) ($doc['language'] ?? [])))),
            ];
        }

        return $out;
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

    /** Similarity of the whole titles, subtitles included (a tie-break). */
    private static function fullSimilarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' || $b === '') {
            return 0.0;
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
