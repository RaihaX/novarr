<?php

namespace App\Books;

use App\Scraping\Fetcher;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A book from a link a reader pasted into the Requests page's book search
 * (App\Services\Requests\RequestSearch): the page is fetched once and read
 * for the book's details, so a new release the catalogues (BookLookup) do
 * not know yet can still be requested.
 *
 * Supported pages (an allowlist: nothing else is ever fetched, SSRF):
 *   goodreads   www.goodreads.com/book/show/<id>
 *   amazon      amazon.<tld>/dp/<ASIN>, /gp/product/<ASIN>
 *   audible     audible.<tld>/pd/…/<ASIN>
 *   hardcover   hardcover.app/books/<slug>
 *   google      books.google.<tld>/books?id=<id> (read through the Books API)
 *   openlibrary openlibrary.org/works/OL…W, /books/OL…M (read through its JSON)
 * The URL fetched is always one built here from the parsed id (the
 * canonical URL), never the text as pasted; a redirect may only lead to
 * another allowed host.
 *
 * What is read, best first: the page's JSON-LD (@type Book / Audiobook),
 * then OpenGraph / meta tags, then site-specific parts (Goodreads' page
 * data, Amazon's title, byline, series widget and detail bullets). The
 * title's " (Series, #1)" suffix becomes the series and its index. Goodreads
 * and Amazon cover URLs are asked for at full size (their "._SX…_" size
 * tokens removed).
 *
 * Amazon often refuses bots (503, a captcha): then the ASIN, when it is an
 * ISBN-10, is looked up on Open Library and Google Books instead; with
 * nothing found, BookLinkFailed(AMAZON_REFUSED). Any other failure is a
 * BookLinkFailed whose message is for the reader.
 *
 * A book read is cached a day per canonical URL (failures are not).
 *
 * read() returns: {title, authors: string[], series, series_index (a
 * number as a string: "1", "2.5"), isbn (13 digits), isbn10, cover_url,
 * description (plain text), year, language (two-letter code), url (the
 * canonical page), site, site_name} — any of them null / [] when unknown,
 * but title and url always set.
 */
class BookLinkReader
{
    public const SITE_NAMES = [
        'goodreads' => 'Goodreads',
        'amazon' => 'Amazon',
        'audible' => 'Audible',
        'hardcover' => 'Hardcover',
        'google' => 'Google Books',
        'openlibrary' => 'Open Library',
    ];

    public const OTHER_HOST = 'Paste a link from Goodreads, Amazon, Hardcover, Google Books or Open Library.';

    public const AMAZON_REFUSED = 'Amazon refused; paste the Goodreads or Hardcover link instead.';

    public const AUDIBLE_REFUSED = 'Audible refused; paste the Goodreads or Hardcover link instead.';

    public const CACHE_PREFIX = 'book_link_v1_';

    public const CACHE_SECONDS = 86400;

    public const TIMEOUT_SECONDS = 15;

    /** Who the catalogue APIs (Open Library, Google Books) are told is asking. */
    public const API_USER_AGENT = 'Novarr (self-hosted reading server)';

    /** Hosts per site (lower case, matched whole). */
    private const HOSTS = [
        'goodreads' => '/^(www\.|m\.)?goodreads\.com$/',
        'amazon' => '/^(www\.|smile\.)?amazon\.(com|ca|com\.mx|com\.br|co\.uk|de|fr|it|es|nl|se|pl|com\.be|ie|in|co\.jp|com\.au|sg|ae|sa|com\.tr)$/',
        'audible' => '/^(www\.)?audible\.(com|ca|co\.uk|com\.au|de|fr|it|es|co\.jp|in)$/',
        'hardcover' => '/^(www\.)?hardcover\.app$/',
        'google' => '/^books\.google\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/',
        'openlibrary' => '/^(www\.)?openlibrary\.org$/',
    ];

    /** APIs this class calls itself (a redirect may land there too). */
    private const API_HOSTS = ['www.googleapis.com', 'openlibrary.org', 'covers.openlibrary.org'];

    /** Image hosts whose URLs carry a "._SX300_" size token. */
    private const SIZED_IMAGE_HOSTS = '/(^|\.)(media-amazon\.com|ssl-images-amazon\.com|images-amazon\.com|gr-assets\.com)$/';

    // ---------------------------------------------------------------------
    // Recognising a link
    // ---------------------------------------------------------------------

    /**
     * Does the text look like a link (of any host)? "https://…", or a bare
     * "www.goodreads.com/…" / "goodreads.com/book/…". One word, no spaces.
     */
    public static function looksLikeUrl(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || preg_match('/\s/u', $text)) {
            return false;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $text)) {
            return true;
        }
        // Without a scheme only "www.…/…" or a supported site's host counts:
        // a title such as "Dr.Stone/Reboot" stays a search.
        if (!preg_match('#^([a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,})(/\S*)?$#i', $text, $m)) {
            return false;
        }

        return str_starts_with(strtolower($m[1]), 'www.') || static::siteOfHost($m[1]) !== null;
    }

    /**
     * The supported book page a link names: {site, id, url (canonical)}, or
     * null (another host, another page of the host, not http(s), or with a
     * user / password / port). Pure.
     *
     * @return array{site: string, id: string, url: string}|null
     */
    public static function parse(string $text): ?array
    {
        $text = trim($text);
        if (!static::looksLikeUrl($text)) {
            return null;
        }
        if (!preg_match('#^https?://#i', $text)) {
            $text = 'https://' . $text;
        }
        $parts = parse_url($text);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $site = static::siteOfHost($host);
        if ($site === null) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $tld = preg_replace('/^(www\.|smile\.|m\.)/', '', $host);

        switch ($site) {
            case 'goodreads':
                return preg_match('#^/book/show/(\d+)#', $path, $m)
                    ? ['site' => $site, 'id' => $m[1], 'url' => 'https://www.goodreads.com/book/show/' . $m[1]]
                    : null;
            case 'amazon':
                return preg_match('#/(?:dp|gp/product|gp/aw/d|o/ASIN|exec/obidos/ASIN)/([A-Z0-9]{10})(?:[/?]|$)#i', $path, $m)
                    ? ['site' => $site, 'id' => strtoupper($m[1]), 'url' => 'https://www.' . $tld . '/dp/' . strtoupper($m[1])]
                    : null;
            case 'audible':
                return preg_match('#^/pd/(?:[^?]*/)?([A-Z0-9]{10})/?$#i', $path, $m)
                    ? ['site' => $site, 'id' => strtoupper($m[1]), 'url' => 'https://www.' . $tld . '/pd/' . strtoupper($m[1])]
                    : null;
            case 'hardcover':
                return preg_match('#^/books/([a-z0-9][a-z0-9-]*)(?:/|$)#i', $path, $m)
                    ? ['site' => $site, 'id' => strtolower($m[1]), 'url' => 'https://hardcover.app/books/' . strtolower($m[1])]
                    : null;
            case 'google':
                $id = is_string($query['id'] ?? null) ? $query['id'] : null;
                if ($id === null && preg_match('#^/books/edition/[^/]+/([A-Za-z0-9_-]+)#', $path, $m)) {
                    $id = $m[1];
                }

                return $id !== null && preg_match('/^[A-Za-z0-9_-]{8,20}$/', $id)
                    ? ['site' => $site, 'id' => $id, 'url' => 'https://books.google.com/books?id=' . $id]
                    : null;
            case 'openlibrary':
                if (preg_match('#^/works/(OL\d+W)(?:/|\.|$)#i', $path, $m)) {
                    return ['site' => $site, 'id' => strtoupper($m[1]), 'url' => 'https://openlibrary.org/works/' . strtoupper($m[1])];
                }

                return preg_match('#^/books/(OL\d+M)(?:/|\.|$)#i', $path, $m)
                    ? ['site' => $site, 'id' => strtoupper($m[1]), 'url' => 'https://openlibrary.org/books/' . strtoupper($m[1])]
                    : null;
        }

        return null;
    }

    /** The canonical URL of a supported book page, or null. */
    public static function canonical(string $text): ?string
    {
        return static::parse($text)['url'] ?? null;
    }

    /**
     * Why a link is not read (it looks like a URL but parse() refused it):
     * a page of a supported site that is not a book's, or another host. Pure.
     */
    public static function refusal(string $text): string
    {
        $text = trim($text);
        $host = (string) parse_url(preg_match('#^https?://#i', $text) ? $text : 'https://' . $text, PHP_URL_HOST);
        $site = static::siteOfHost($host);

        return $site !== null
            ? 'That is not a book\'s page on ' . self::SITE_NAMES[$site] . '. Open the book itself and paste its link.'
            : self::OTHER_HOST;
    }

    /** The site a host belongs to (goodreads, amazon, …), or null. */
    public static function siteOfHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        foreach (self::HOSTS as $site => $pattern) {
            if (preg_match($pattern, $host)) {
                return $site;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Reading a book
    // ---------------------------------------------------------------------

    /**
     * The book a link names (see the class doc for the shape), from the
     * cache when it was read in the last day.
     *
     * @throws BookLinkFailed  not a supported link, or the page could not be read
     */
    public function read(string $text): array
    {
        $link = static::parse($text);
        if ($link === null) {
            throw new BookLinkFailed(self::OTHER_HOST);
        }
        $key = self::CACHE_PREFIX . sha1($link['url']);
        try {
            $cached = Cache::get($key);
        } catch (\Throwable) {
            $cached = null;
        }
        if (is_array($cached) && ($cached['title'] ?? '') !== '') {
            return $cached;
        }

        $book = match ($link['site']) {
            'goodreads' => $this->goodreads($link),
            'amazon' => $this->amazon($link),
            'audible' => $this->audible($link),
            'hardcover' => $this->hardcover($link),
            'google' => $this->googleBooks($link),
            'openlibrary' => $this->openLibrary($link),
        };
        $book = static::finish($book, $link);

        try {
            Cache::put($key, $book, self::CACHE_SECONDS);
        } catch (\Throwable $e) {
            Log::info('Book link: not cached: ' . $e->getMessage());
        }

        return $book;
    }

    private function goodreads(array $link): array
    {
        $response = $this->page($link['url']);
        $html = $this->bodyOrFail($response, $link);
        $data = static::goodreadsData($html, $link['id']);
        $book = static::merge($data, static::isBookPage($html) ? static::fromHtml($html) : []);
        if (($book['title'] ?? '') === '') {
            throw new BookLinkFailed('Goodreads answered, but the page has no book on it. Check the link.');
        }

        return $book;
    }

    private function amazon(array $link): array
    {
        try {
            $response = $this->page($link['url']);
        } catch (\Throwable) {
            $response = null;
        }
        $html = $response !== null && $response->successful() ? (string) $response->body() : '';
        $blocked = $html === '' || static::amazonBlocked($html);
        if ($response !== null && $response->status() === 404) {
            throw new BookLinkFailed('That page was not found on Amazon. Check the link.');
        }

        $book = $blocked ? [] : static::merge(static::amazonParts($html), static::fromHtml($html));
        if (($book['title'] ?? '') === '') {
            // Refused (or no product on the page): the ASIN of a book is its ISBN-10.
            $isbn = static::isbn13($link['id']);
            $found = $isbn !== null ? $this->isbnLookup($isbn) : null;
            if ($found === null) {
                throw new BookLinkFailed(self::AMAZON_REFUSED);
            }
            $book = $found + ['isbn10' => $link['id']];
        }
        if (empty($book['isbn10']) && static::isbn13($link['id']) !== null) {
            $book['isbn10'] = $link['id'];
        }

        return $book;
    }

    private function audible(array $link): array
    {
        try {
            $response = $this->page($link['url']);
        } catch (\Throwable) {
            throw new BookLinkFailed(self::AUDIBLE_REFUSED);
        }
        if ($response->status() === 404) {
            throw new BookLinkFailed('That page was not found on Audible. Check the link.');
        }
        $html = $response->successful() ? (string) $response->body() : '';
        // An unknown ASIN lands on Audible's home page: only a book's page counts.
        $book = $html !== '' && static::isBookPage($html, true) ? static::merge(static::audibleParts($html), static::fromHtml($html)) : [];
        if (($book['title'] ?? '') === '') {
            throw new BookLinkFailed(self::AUDIBLE_REFUSED);
        }

        return $book;
    }

    private function hardcover(array $link): array
    {
        try {
            $response = $this->page($link['url']);
            $html = $response->successful() ? (string) $response->body() : '';
            $status = $response->status();
        } catch (\Throwable) {
            $html = '';
            $status = 0;
        }
        if ($status === 404) {
            throw new BookLinkFailed('That page was not found on Hardcover. Check the link.');
        }
        // Hardcover sits behind Cloudflare: a challenge goes through FlareSolverr (when set up).
        if ($html === '' || Fetcher::isCloudflareChallenge($html)) {
            $html = $this->throughFlareSolverr($link['url']) ?? '';
        }
        $book = $html !== '' && static::isBookPage($html) ? static::fromHtml($html) : [];
        if (($book['title'] ?? '') === '') {
            throw new BookLinkFailed('Hardcover could not be read right now. Paste the Goodreads link instead.');
        }
        // "Blightfall by Brandon Sanderson | Hardcover" when only the og:title was there.
        $book['title'] = trim(preg_replace('/\s*[|\x{2013}-]\s*Hardcover\s*$/u', '', (string) $book['title']) ?? '');
        if (($book['authors'] ?? []) === [] && preg_match('/^(.+?)\s+by\s+(.+)$/u', $book['title'], $m)) {
            $book['title'] = trim($m[1]);
            $book['authors'] = static::splitNames($m[2]);
        }

        return $book;
    }

    private function googleBooks(array $link): array
    {
        $params = [];
        $key = trim((string) config('novarr.books.google_books_key', ''));
        if ($key !== '') {
            $params['key'] = $key;
        }
        try {
            $response = $this->api('https://www.googleapis.com/books/v1/volumes/' . rawurlencode($link['id']), $params);
        } catch (\Throwable) {
            throw new BookLinkFailed('Google Books could not be reached. Try again later, or paste the Goodreads link instead.');
        }
        if ($response->status() === 404) {
            throw new BookLinkFailed('That book was not found on Google Books. Check the link.');
        }
        $info = $response->successful() ? $response->json('volumeInfo') : null;
        if (!is_array($info)) {
            throw new BookLinkFailed('Google Books is not answering right now. Paste the Goodreads link instead.');
        }

        return static::fromGoogleVolume($info);
    }

    private function openLibrary(array $link): array
    {
        $isWork = str_ends_with($link['id'], 'W');
        try {
            $record = $this->api('https://openlibrary.org/' . ($isWork ? 'works' : 'books') . '/' . $link['id'] . '.json');
        } catch (\Throwable) {
            throw new BookLinkFailed('Open Library could not be reached. Try again later.');
        }
        if ($record->status() === 404) {
            throw new BookLinkFailed('That book was not found on Open Library. Check the link.');
        }
        $json = $record->successful() ? $record->json() : null;
        if (!is_array($json) || trim((string) ($json['title'] ?? '')) === '') {
            throw new BookLinkFailed('Open Library is not answering right now. Try again later.');
        }

        // The work's search entry carries the author names (the record only their keys).
        $workKey = $isWork ? '/works/' . $link['id'] : (string) ($json['works'][0]['key'] ?? '');
        $doc = [];
        if (preg_match('#^/works/OL\d+W$#', $workKey)) {
            try {
                $search = $this->api('https://openlibrary.org/search.json', [
                    'q' => 'key:' . $workKey,
                    'fields' => 'key,title,author_name,first_publish_year,cover_i,language,isbn',
                    'limit' => 1,
                ]);
                $doc = $search->successful() ? (array) ($search->json('docs.0') ?? []) : [];
            } catch (\Throwable) {
                $doc = [];
            }
        }

        $description = $json['description'] ?? null;
        $description = is_array($description) ? ($description['value'] ?? null) : $description;
        $isbns = array_merge((array) ($json['isbn_13'] ?? []), (array) ($json['isbn_10'] ?? []));
        if ($isbns === [] && $isWork) {
            $isbns = array_slice((array) ($doc['isbn'] ?? []), 0, 1);
        }
        $cover = (int) (array_values(array_filter((array) ($json['covers'] ?? []), fn($c) => (int) $c > 0))[0] ?? ($doc['cover_i'] ?? 0));
        $series = null;
        $index = null;
        // An edition's "series": ["Riftwake ; 1"] / ["Riftwake, #1"].
        foreach ((array) ($json['series'] ?? []) as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                if (preg_match('/^(.+?)\s*(?:[;,]\s*#?|#)\s*(\d+(?:\.\d+)?)\s*$/u', trim($entry), $m)) {
                    [$series, $index] = [trim($m[1]), $m[2]];
                } else {
                    $series = trim($entry);
                }
                break;
            }
        }
        $languageKey = (string) ($json['languages'][0]['key'] ?? '');

        return [
            'title' => trim((string) $json['title']),
            'authors' => array_values(array_map('strval', (array) ($doc['author_name'] ?? []))),
            'series' => $series,
            'series_index' => $index,
            'isbn' => static::pickIsbn13($isbns),
            'isbn10' => static::pickIsbn10($isbns),
            'cover_url' => $cover > 0 ? BookLookup::OPEN_LIBRARY_COVERS . '/id/' . $cover . '-L.jpg' : null,
            'description' => is_string($description) ? $description : null,
            // A work: its first publication (the record's dates are its own edits); an edition: its own date.
            'year' => $isWork
                ? (isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : static::year((string) ($json['first_publish_date'] ?? '')))
                : (static::year((string) ($json['publish_date'] ?? '')) ?? (isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null)),
            'language' => $languageKey !== '' ? basename($languageKey) : (($doc['language'][0] ?? null) ?: null),
        ];
    }

    /**
     * An ISBN looked up on Open Library, then Google Books (the Amazon
     * fallback): the book's fields as read() gives them, or null.
     */
    private function isbnLookup(string $isbn): ?array
    {
        try {
            $search = $this->api('https://openlibrary.org/search.json', [
                'q' => 'isbn:' . $isbn,
                'fields' => 'key,title,author_name,first_publish_year,cover_i,language',
                'limit' => 1,
            ]);
            $doc = $search->successful() ? $search->json('docs.0') : null;
            if (is_array($doc) && trim((string) ($doc['title'] ?? '')) !== '') {
                $cover = (int) ($doc['cover_i'] ?? 0);

                return [
                    'title' => trim((string) $doc['title']),
                    'authors' => array_values(array_map('strval', (array) ($doc['author_name'] ?? []))),
                    'isbn' => $isbn,
                    'cover_url' => $cover > 0 ? BookLookup::OPEN_LIBRARY_COVERS . '/id/' . $cover . '-L.jpg' : null,
                    'year' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
                    'language' => ($doc['language'][0] ?? null) ?: null,
                ];
            }
        } catch (\Throwable $e) {
            Log::info('Book link: Open Library ISBN lookup failed: ' . $e->getMessage());
        }

        try {
            $params = ['q' => 'isbn:' . $isbn, 'maxResults' => 1];
            $key = trim((string) config('novarr.books.google_books_key', ''));
            if ($key !== '') {
                $params['key'] = $key;
            }
            $volumes = $this->api('https://www.googleapis.com/books/v1/volumes', $params);
            $info = $volumes->successful() ? $volumes->json('items.0.volumeInfo') : null;
            if (is_array($info) && trim((string) ($info['title'] ?? '')) !== '') {
                return ['isbn' => $isbn] + static::fromGoogleVolume($info);
            }
        } catch (\Throwable $e) {
            Log::info('Book link: Google Books ISBN lookup failed: ' . $e->getMessage());
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Fetching
    // ---------------------------------------------------------------------

    /** A page, as a browser asks for it; a redirect may only go to an allowed host. */
    protected function page(string $url): Response
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'User-Agent' => Fetcher::DEFAULT_HEADERS['User-Agent'],
                'Accept' => Fetcher::DEFAULT_HEADERS['Accept'],
                'Accept-Language' => Fetcher::DEFAULT_HEADERS['Accept-Language'],
            ])
            ->withOptions(['allow_redirects' => static::redirectRules()])
            ->get($url);
    }

    /** A catalogue API's JSON answer (Open Library, Google Books). */
    protected function api(string $url, array $query = []): Response
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withUserAgent(self::API_USER_AGENT)
            ->withOptions(['allow_redirects' => static::redirectRules()])
            ->get($url, $query);
    }

    /** Guzzle's redirect rules: a few hops, http(s), only to a supported site or our APIs. */
    public static function redirectRules(): array
    {
        return [
            'max' => 5,
            'protocols' => ['https', 'http'],
            'on_redirect' => function ($request, $response, $uri) {
                $host = strtolower((string) $uri->getHost());
                if (static::siteOfHost($host) === null && !in_array($host, self::API_HOSTS, true)) {
                    throw new BookLinkFailed('That link leads away from the book page.');
                }
            },
        ];
    }

    /** A Cloudflare-guarded page through FlareSolverr, or null (not set up, or it failed). */
    protected function throughFlareSolverr(string $url): ?string
    {
        if (trim((string) config('novarr.flaresolverr_url', '')) === '') {
            return null;
        }
        try {
            $html = app(Fetcher::class)->page($url);
        } catch (\Throwable $e) {
            Log::info('Book link: FlareSolverr failed for ' . $url . ': ' . $e->getMessage());

            return null;
        }

        return is_string($html) && $html !== '' && !Fetcher::isCloudflareChallenge($html) ? $html : null;
    }

    private function bodyOrFail(Response $response, array $link): string
    {
        $name = self::SITE_NAMES[$link['site']];
        if ($response->status() === 404) {
            throw new BookLinkFailed("That page was not found on {$name}. Check the link.");
        }
        if (!$response->successful() || trim((string) $response->body()) === '') {
            throw new BookLinkFailed("{$name} could not be read right now (it answered {$response->status()}). Try again later.");
        }

        return (string) $response->body();
    }

    // ---------------------------------------------------------------------
    // Reading pages (pure)
    // ---------------------------------------------------------------------

    /**
     * Is this a book's page: JSON-LD of @type Book / Audiobook, or (unless
     * $jsonLdOnly) an og:type of a book ("books.book")? Audible's home page
     * calls itself og:type "book" too, so an Audible page needs the JSON-LD.
     * Pure.
     */
    public static function isBookPage(string $html, bool $jsonLdOnly = false): bool
    {
        $x = static::xpath($html);

        return static::jsonLdBook($x) !== null
            || (!$jsonLdOnly && str_contains(strtolower((string) static::meta($x, 'og:type')), 'book'));
    }

    /**
     * The generic part of a page: JSON-LD Book / Audiobook first, then the
     * OpenGraph and meta tags. Keys as read() (unset when unknown). Pure.
     */
    public static function fromHtml(string $html): array
    {
        $x = static::xpath($html);
        $ld = static::jsonLdBook($x) ?? [];
        $book = [];

        $book['title'] = static::text($ld['name'] ?? null) ?? static::meta($x, 'og:title') ?? static::text($x->evaluate('string(//title)'));
        $authors = static::ldNames($ld['author'] ?? null);
        if ($authors === []) {
            foreach (['books:author', 'book:author'] as $name) {
                foreach (static::metas($x, $name) as $value) {
                    if (!preg_match('#^https?://#i', $value)) {
                        $authors[] = $value;
                    }
                }
            }
        }
        $book['authors'] = array_values(array_unique($authors));
        $isbn = static::text(is_array($ld['isbn'] ?? null) ? ($ld['isbn'][0] ?? null) : ($ld['isbn'] ?? null))
            ?? static::meta($x, 'books:isbn') ?? static::meta($x, 'book:isbn');
        if ($isbn !== null) {
            $book['isbn'] = static::isbn13($isbn);
            $book['isbn10'] = static::isbn10($isbn);
        }
        $image = $ld['image'] ?? null;
        if (is_array($image)) {
            $image = $image['url'] ?? ($image[0] ?? null);
            $image = is_array($image) ? ($image['url'] ?? null) : $image;
        }
        $book['cover_url'] = static::text($image) ?? static::meta($x, 'og:image');
        $book['description'] = static::text($ld['description'] ?? null) ?? static::meta($x, 'og:description') ?? static::meta($x, 'description');
        $book['year'] = static::year((string) ($ld['datePublished'] ?? static::meta($x, 'books:release_date') ?? static::meta($x, 'book:release_date') ?? ''));
        $language = $ld['inLanguage'] ?? null;
        $book['language'] = static::text(is_array($language) ? ($language['name'] ?? ($language[0] ?? null)) : $language);
        $part = $ld['isPartOf'] ?? null;
        if (is_array($part)) {
            $part = isset($part['name']) ? $part : ($part[0] ?? null);
            if (is_array($part) && static::text($part['name'] ?? null) !== null) {
                $book['series'] = static::text($part['name']);
                $book['series_index'] = static::seriesIndex((string) ($ld['position'] ?? $part['position'] ?? ''));
            }
        }

        return array_filter($book, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Goodreads' own page data (__NEXT_DATA__, an Apollo cache): the full
     * description, the series and its position, ISBN-10 / 13, publication
     * year, language and the clean title of the book whose id is $id. Pure.
     */
    public static function goodreadsData(string $html, string $id): array
    {
        if (!preg_match('#<script id="__NEXT_DATA__" type="application/json">(.*?)</script>#s', $html, $m)) {
            return [];
        }
        $data = json_decode($m[1], true);
        $state = is_array($data) ? ($data['props']['pageProps']['apolloState'] ?? null) : null;
        if (!is_array($state)) {
            return [];
        }
        $node = null;
        foreach ($state as $key => $value) {
            if (is_array($value) && str_starts_with((string) $key, 'Book:') && (string) ($value['legacyId'] ?? '') === $id) {
                $node = $value;
                break;
            }
        }
        if ($node === null) {
            return [];
        }

        $book = ['title' => static::text($node['title'] ?? null)];
        $complete = static::text($node['titleComplete'] ?? null);
        foreach ($node as $key => $value) {
            if (is_string($value) && ($key === 'description' || str_starts_with((string) $key, 'description(')) && !str_contains((string) $key, 'stripped')) {
                $book['description'] = $value;
                break;
            }
        }
        $series = (array) ($node['bookSeries'][0] ?? []);
        $ref = (string) ($series['series']['__ref'] ?? '');
        if ($ref !== '' && is_array($state[$ref] ?? null)) {
            $book['series'] = static::text($state[$ref]['title'] ?? null);
            $book['series_index'] = static::seriesIndex((string) ($series['userPosition'] ?? ''));
        } elseif ($complete !== null) {
            [, $book['series'], $book['series_index']] = static::splitSeries($complete);
        }
        $details = (array) ($node['details'] ?? []);
        $book['isbn'] = static::isbn13((string) ($details['isbn13'] ?? ''));
        $book['isbn10'] = static::isbn10((string) ($details['isbn'] ?? ''));
        if (is_numeric($details['publicationTime'] ?? null)) {
            $book['year'] = (int) gmdate('Y', (int) ((float) $details['publicationTime'] / 1000));
        }
        $book['language'] = static::text($details['language']['name'] ?? null);
        $book['cover_url'] = static::text($node['imageUrl'] ?? null);

        return array_filter($book, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Did Amazon answer with its robot check instead of the product? Pure. */
    public static function amazonBlocked(string $html): bool
    {
        return str_contains($html, '/errors/validateCaptcha')
            || stripos($html, 'Type the characters you see') !== false
            || stripos($html, 'api-services-support@amazon.com') !== false
            || (!str_contains($html, 'id="productTitle"') && !str_contains($html, 'id="ebooksProductTitle"'));
    }

    /**
     * An Amazon product page's own parts: #productTitle, the byline's
     * authors (narrators and illustrators left out), the series widget
     * ("Book 1 of 3: Riftwake"), the detail bullets (ISBN-13 / 10,
     * language, publication date), the description and the large cover.
     * Pure.
     */
    public static function amazonParts(string $html): array
    {
        $x = static::xpath($html);
        $book = [];
        $book['title'] = static::text($x->evaluate('string(//*[@id="productTitle" or @id="ebooksProductTitle"])'));

        $authors = [];
        $fallback = [];
        foreach ($x->query('//*[@id="bylineInfo"]//*[contains(concat(" ", normalize-space(@class), " "), " author ")]') ?: [] as $node) {
            $name = static::text($x->evaluate('string(.//a[1])', $node));
            $role = strtolower((string) $x->evaluate('string(.//*[contains(@class, "contribution")])', $node));
            if ($name === null) {
                continue;
            }
            $fallback[] = $name;
            if ($role === '' || str_contains($role, 'author')) {
                $authors[] = $name;
            }
        }
        $book['authors'] = array_values(array_unique($authors ?: $fallback));

        $seriesLine = static::text($x->evaluate('string(//*[@id="seriesBulletWidget_feature_div"]//a[1])'));
        if ($seriesLine !== null && preg_match('/Book\s+(\d+(?:\.\d+)?)\s+of\s+\d+\s*:\s*(.+)$/iu', $seriesLine, $m)) {
            $book['series_index'] = $m[1];
            $book['series'] = trim($m[2]);
        }

        // Detail bullets ("ISBN-13 : 978-…") and the carousel's rows.
        $details = [];
        foreach ($x->query('//*[@id="detailBullets_feature_div"]//li') ?: [] as $li) {
            $label = static::text($x->evaluate('string(.//*[contains(@class, "a-text-bold")])', $li));
            $value = static::text($x->evaluate('string(.//span[contains(@class, "a-list-item")]/span[not(contains(@class, "a-text-bold"))][1])', $li));
            if ($label !== null && $value !== null) {
                $details[strtolower(trim(preg_replace('/[^\p{L}\p{N}\- ]/u', '', $label) ?? ''))] = $value;
            }
        }
        foreach (['isbn13' => 'isbn-13', 'isbn10' => 'isbn-10', 'language' => 'language', 'publication_date' => 'publication date'] as $rpi => $label) {
            $value = static::text($x->evaluate('string(//*[@id="rpi-attribute-book_details-' . $rpi . '"]//*[contains(@class, "rpi-attribute-value")])'));
            if ($value !== null && !isset($details[$label])) {
                $details[$label] = $value;
            }
        }
        $book['isbn'] = static::isbn13((string) ($details['isbn-13'] ?? ''));
        $book['isbn10'] = static::isbn10((string) ($details['isbn-10'] ?? ''));
        $book['language'] = $details['language'] ?? null;
        $book['year'] = static::year((string) ($details['publication date'] ?? ''));

        $description = $x->query('//*[@id="bookDescription_feature_div"]//*[contains(@class, "a-expander-content")]')?->item(0);
        if ($description !== null) {
            $book['description'] = static::text($description->ownerDocument->saveHTML($description));
        }

        foreach (['landingImage', 'imgBlkFront', 'ebooksImgBlkFront', 'main-image'] as $imageId) {
            $img = $x->query('//img[@id="' . $imageId . '"]')?->item(0);
            if (!$img instanceof \DOMElement) {
                continue;
            }
            $src = static::text($img->getAttribute('data-old-hires'));
            if ($src === null && ($dynamic = json_decode(html_entity_decode($img->getAttribute('data-a-dynamic-image')), true))) {
                $src = is_array($dynamic) ? (string) array_key_first($dynamic) : null;
            }
            $src ??= static::text($img->getAttribute('src'));
            if ($src !== null && preg_match('#^https?://#i', $src)) {
                $book['cover_url'] = $src;
                break;
            }
        }

        return array_filter($book, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** An Audible product page's series line ("Series: Riftwake, Book 1") and title. Pure. */
    public static function audibleParts(string $html): array
    {
        $x = static::xpath($html);
        $book = [];
        $line = static::text($x->evaluate('string(//li[contains(@class, "seriesLabel")])'));
        if ($line !== null && preg_match('/Series:\s*(.+?)(?:,\s*Book\s+(\d+(?:\.\d+)?))?\s*$/iu', $line, $m)) {
            $book['series'] = trim($m[1]);
            $book['series_index'] = $m[2] ?? null;
        }

        return array_filter($book, fn($v) => $v !== null && $v !== '');
    }

    /** A Google Books volumeInfo as read()'s fields. Pure. */
    public static function fromGoogleVolume(array $info): array
    {
        $ids = [];
        foreach ((array) ($info['industryIdentifiers'] ?? []) as $identifier) {
            if (is_array($identifier)) {
                $ids[] = (string) ($identifier['identifier'] ?? '');
            }
        }
        $images = (array) ($info['imageLinks'] ?? []);
        $cover = $images['extraLarge'] ?? $images['large'] ?? $images['medium'] ?? $images['thumbnail'] ?? $images['smallThumbnail'] ?? null;

        return array_filter([
            'title' => static::text($info['title'] ?? null),
            'authors' => array_values(array_map('strval', (array) ($info['authors'] ?? []))),
            'isbn' => static::pickIsbn13($ids),
            'isbn10' => static::pickIsbn10($ids),
            'cover_url' => is_string($cover) ? preg_replace('#^http://#', 'https://', $cover) : null,
            'description' => static::text($info['description'] ?? null),
            'year' => static::year((string) ($info['publishedDate'] ?? '')),
            'language' => static::text($info['language'] ?? null),
        ], fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * The book's final shape: the title's series suffix split off (and an
     * Amazon "(Riftwake)" / "(Riftwake Book 1)" suffix that only repeats the
     * series), the cover at full size, the description as plain text, the
     * language as its code, the ISBNs completed from each other.
     */
    public static function finish(array $book, array $link): array
    {
        $title = trim((string) ($book['title'] ?? ''));
        $series = static::text($book['series'] ?? null);
        $index = static::seriesIndex((string) ($book['series_index'] ?? ''));

        [$clean, $suffixSeries, $suffixIndex] = static::splitSeries($title);
        if ($suffixSeries !== null) {
            $title = $clean;
            $series ??= $suffixSeries;
            $index ??= $suffixIndex;
        }
        if ($series !== null) {
            $quoted = preg_quote($series, '/');
            $title = trim(preg_replace('/\s*\(\s*(?:The\s+)?' . $quoted . '(?:\s*(?:,|:)?\s*(?:Book|Vol\.?|Volume|#)\s*\d+(?:\.\d+)?)?\s*\)\s*$/iu', '', $title) ?? $title);
        }
        if ($title === '') {
            throw new BookLinkFailed('That page has no book title on it. Check the link.');
        }

        $isbn = static::isbn13((string) ($book['isbn'] ?? '')) ?? static::isbn13((string) ($book['isbn10'] ?? ''));
        $isbn10 = static::isbn10((string) ($book['isbn10'] ?? '')) ?? static::isbn10((string) ($book['isbn'] ?? ''));
        $description = static::text($book['description'] ?? null);
        if ($description !== null) {
            $description = Epub::plainDescription($description);
            // og:description is cut short with an ellipsis: keep it anyway.
            $description = $description !== '' ? $description : null;
        }
        $year = isset($book['year']) && (int) $book['year'] > 0 ? (int) $book['year'] : null;

        return [
            'title' => $title,
            'authors' => array_values(array_unique(array_filter(array_map(fn($a) => trim((string) $a), (array) ($book['authors'] ?? [])), fn($a) => $a !== ''))),
            'series' => $series,
            'series_index' => $series !== null ? $index : null,
            'isbn' => $isbn,
            'isbn10' => $isbn10,
            'cover_url' => static::upscaleCover(static::text($book['cover_url'] ?? null)),
            'description' => $description,
            'year' => $year,
            'language' => Epub::languageCode(static::text($book['language'] ?? null)),
            'url' => $link['url'],
            'site' => $link['site'],
            'site_name' => self::SITE_NAMES[$link['site']],
        ];
    }

    /**
     * "Blightfall (Riftwake, #1)" → ["Blightfall", "Riftwake", "1"]; also
     * "(Riftwake #2.5)", "(Riftwake, Book 1)", "(Riftwake Book 1)". A title
     * without such a suffix → [title, null, null]. Pure.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    public static function splitSeries(string $title): array
    {
        $title = trim($title);
        if (preg_match('/^(.*\S)\s*\(\s*([^()]+?)\s*(?:,\s*|\s+)(?:#\s*|Book\s+|Vol\.?\s*|Volume\s+)(\d+(?:\.\d+)?)\s*\)\s*$/iu', $title, $m)) {
            return [trim($m[1]), trim($m[2]), static::seriesIndex($m[3])];
        }

        return [$title, null, null];
    }

    /** "1", "#2", "2.50" → "1", "2", "2.5"; anything else (a range "1-3") → null. Pure. */
    public static function seriesIndex(string $value): ?string
    {
        $value = ltrim(trim($value), '#');
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : ltrim($value, '0');

        return $value === '' ? '0' : $value;
    }

    /**
     * A Goodreads / Amazon image at full size: the "._SX318_" / "._SY475_" /
     * "._AC_SL1500_" size token before the extension removed. Other hosts'
     * URLs are kept as they are. Pure.
     */
    public static function upscaleCover(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!preg_match(self::SIZED_IMAGE_HOSTS, $host)) {
            return $url;
        }

        return preg_replace('#\._[A-Za-z0-9,_-]*_(?=\.(?:jpe?g|png|gif|webp)(?:[?\#]|$))#i', '', $url) ?? $url;
    }

    /** An ISBN-13 from an ISBN-10 or -13 (hyphens, spaces allowed) with a valid check digit, or null. Pure. */
    public static function isbn13(string $value): ?string
    {
        $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', $value) ?? '');
        if (strlen($digits) === 13 && ctype_digit($digits) && static::validIsbn13($digits)) {
            return $digits;
        }
        if (strlen($digits) === 10 && static::validIsbn10($digits)) {
            $core = '978' . substr($digits, 0, 9);
            $sum = 0;
            foreach (str_split($core) as $i => $d) {
                $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
            }

            return $core . ((10 - $sum % 10) % 10);
        }

        return null;
    }

    /** An ISBN-10 from an ISBN-10, or a 978- ISBN-13, or null. Pure. */
    public static function isbn10(string $value): ?string
    {
        $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', $value) ?? '');
        if (strlen($digits) === 10 && static::validIsbn10($digits)) {
            return $digits;
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '978') && static::validIsbn13($digits)) {
            $core = substr($digits, 3, 9);
            $sum = 0;
            foreach (str_split($core) as $i => $d) {
                $sum += (int) $d * (10 - $i);
            }
            $check = (11 - $sum % 11) % 11;

            return $core . ($check === 10 ? 'X' : (string) $check);
        }

        return null;
    }

    private static function validIsbn10(string $digits): bool
    {
        if (!preg_match('/^\d{9}[\dX]$/', $digits)) {
            return false;
        }
        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $sum += ($d === 'X' ? 10 : (int) $d) * (10 - $i);
        }

        return $sum % 11 === 0;
    }

    private static function validIsbn13(string $digits): bool
    {
        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return $sum % 10 === 0;
    }

    private static function pickIsbn13(array $values): ?string
    {
        foreach ($values as $value) {
            $digits = preg_replace('/[^0-9X]/i', '', (string) $value) ?? '';
            if (strlen($digits) === 13 && ($isbn = static::isbn13($digits)) !== null) {
                return $isbn;
            }
        }
        foreach ($values as $value) {
            if (($isbn = static::isbn13((string) $value)) !== null) {
                return $isbn;
            }
        }

        return null;
    }

    private static function pickIsbn10(array $values): ?string
    {
        foreach ($values as $value) {
            if (($isbn = static::isbn10((string) $value)) !== null) {
                return $isbn;
            }
        }

        return null;
    }

    /** The first four-digit year (1450–2099) in a date, or null. Pure. */
    public static function year(string $date): ?int
    {
        return preg_match('/\b(1[4-9]\d\d|20\d\d)\b/', $date, $m) ? (int) $m[1] : null;
    }

    /** Fields of $first, with $second's where $first has none. */
    private static function merge(array $first, array $second): array
    {
        foreach ($second as $key => $value) {
            if (!isset($first[$key]) || $first[$key] === '' || $first[$key] === []) {
                $first[$key] = $value;
            }
        }

        return $first;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    /** The first JSON-LD object of @type Book / Audiobook (in a list or @graph too), or null. */
    private static function jsonLdBook(\DOMXPath $x): ?array
    {
        foreach ($x->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode(trim((string) $script->textContent), true);
            if (!is_array($data)) {
                continue;
            }
            $queue = array_is_list($data) ? $data : [$data];
            while ($queue !== []) {
                $item = array_shift($queue);
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['@graph']) && is_array($item['@graph'])) {
                    array_push($queue, ...array_values($item['@graph']));
                }
                $types = array_map('strtolower', array_map('strval', (array) ($item['@type'] ?? [])));
                if (array_intersect($types, ['book', 'audiobook']) !== []) {
                    return $item;
                }
            }
        }

        return null;
    }

    /** Names from a JSON-LD author: a string, {name}, or a list of either. */
    private static function ldNames(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        $list = is_array($value) && !array_is_list($value) ? [$value] : (array) $value;
        $names = [];
        foreach ($list as $entry) {
            $name = static::text(is_array($entry) ? ($entry['name'] ?? null) : $entry);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** "A, B and C" / "A & B" → ["A", "B", "C"]. */
    private static function splitNames(string $names): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|&|\band\b)\s*/u', $names) ?: []), fn($n) => $n !== ''));
    }

    private static function meta(\DOMXPath $x, string $name): ?string
    {
        return static::metas($x, $name)[0] ?? null;
    }

    /** @return string[] */
    private static function metas(\DOMXPath $x, string $name): array
    {
        $values = [];
        foreach ($x->query('//meta[@property="' . $name . '" or @name="' . $name . '"]') ?: [] as $meta) {
            if ($meta instanceof \DOMElement && ($value = static::text($meta->getAttribute('content'))) !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** A string with entities decoded and whitespace squashed; null when empty or not a string. */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Amazon's right-to-left / left-to-right marks.
        $value = str_replace(["\u{200E}", "\u{200F}", "\u{00A0}"], ['', '', ' '], $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value !== '' ? $value : null;
    }
}
