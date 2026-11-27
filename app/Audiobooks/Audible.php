<?php

namespace App\Audiobooks;

use App\Books\BookLookup;
use App\Books\CatalogueHttp;
use App\Books\Epub;

/**
 * The Audible catalogue (no key) and Audnexus (api.audnex.us, no key),
 * for audiobook metadata (App\Audiobooks\AudiobookEnricher):
 *
 *   search    GET https://api.audible.{tld}/1.0/catalog/products?title=&author=
 *             &num_results=10&products_sort_by=Relevance&response_groups=…
 *             (products: asin, title, subtitle, authors, narrators, series
 *             [{title, sequence}], runtime_length_min, release_date,
 *             product_images {"500": url}, publisher_summary, …)
 *   details   GET https://api.audnex.us/books/{asin}?region=
 *             (title, authors, narrators, seriesPrimary {name, position},
 *             image (the full-size cover), summary, runtimeLengthMin,
 *             releaseDate)
 *   chapters  GET https://api.audnex.us/books/{asin}/chapters?region=
 *             (runtimeLengthMs, chapters [{title, startOffsetMs, lengthMs}])
 *
 * The store is novarr.audiobooks.audible_region ('us' = audible.com, 'au'
 * = audible.com.au, …). Answers are cached a day; every failure is null
 * (CatalogueHttp).
 */
class Audible
{
    public const AUDNEXUS = 'https://api.audnex.us';

    /** Region => the Audible API's host. */
    public const HOSTS = [
        'us' => 'api.audible.com',
        'ca' => 'api.audible.ca',
        'uk' => 'api.audible.co.uk',
        'au' => 'api.audible.com.au',
        'de' => 'api.audible.de',
        'fr' => 'api.audible.fr',
        'it' => 'api.audible.it',
        'es' => 'api.audible.es',
        'in' => 'api.audible.in',
        'jp' => 'api.audible.co.jp',
    ];

    public const RESPONSE_GROUPS = 'contributors,product_desc,product_extended_attrs,media,series,product_attrs';

    /** Largest runtime difference (fraction of the files' length) for a search result to count as the same recording. */
    public const MATCH_TOLERANCE = 0.03;

    public static function region(): string
    {
        $region = strtolower(trim((string) config('novarr.audiobooks.audible_region', 'us')));
        $region = $region === 'gb' ? 'uk' : $region;

        return isset(self::HOSTS[$region]) ? $region : 'us';
    }

    /**
     * Search products, or null when Audible could not be asked.
     *
     * @return ?list<array>  products as normalised by product()
     */
    public function search(string $title, ?string $author = null, int $limit = 10): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }
        $params = [
            'title' => mb_substr($title, 0, 200),
            'num_results' => max(1, min(25, $limit)),
            'products_sort_by' => 'Relevance',
            'response_groups' => self::RESPONSE_GROUPS,
        ];
        if ($author !== null && trim($author) !== '') {
            $params['author'] = mb_substr(trim($author), 0, 200);
        }
        $json = CatalogueHttp::getJson('Audible', 'https://' . self::HOSTS[static::region()] . '/1.0/catalog/products', $params);
        if ($json === null) {
            return null;
        }
        $out = [];
        foreach ((array) ($json['products'] ?? []) as $product) {
            if (is_array($product) && ($p = static::product($product)) !== null) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * The product for a title and author (and, when known, the files'
     * length: within MATCH_TOLERANCE of Audible's runtime). Null when none
     * agrees or Audible could not be asked.
     */
    public function find(string $title, ?string $author, ?int $durationMs = null): ?array
    {
        $title = AudiobookMatcher::cleanTitle($title);
        $products = $this->search($title, $author) ?? [];
        // Nothing for the author as tagged (a misspelling, "Various"): the title alone.
        if ($products === [] && $author !== null && trim($author) !== '') {
            $products = $this->search($title) ?? [];
        }

        return static::best($products, $title, $author, $durationMs);
    }

    /**
     * The first product (Audible's relevance order) whose title agrees (the
     * title or "title: subtitle"), whose author agrees when both name one,
     * and whose runtime is within MATCH_TOLERANCE of $durationMs when both
     * are known.
     *
     * @param  list<array>  $products
     */
    public static function best(array $products, string $title, ?string $author, ?int $durationMs): ?array
    {
        $ours = $author !== null && trim($author) !== '' ? array_map('trim', preg_split('/\s*(?:,|&|;| and )\s*/u', $author) ?: []) : [];
        foreach ($products as $p) {
            $titles = array_filter([$p['title'], $p['subtitle'] !== null ? $p['title'] . ': ' . $p['subtitle'] : null]);
            $sameTitle = false;
            foreach ($titles as $t) {
                if (BookLookup::sameTitleAndAuthor($title, $ours, $t, $p['authors'])) {
                    $sameTitle = true;
                }
            }
            if (!$sameTitle) {
                continue;
            }
            if ($durationMs !== null && $durationMs > 0 && $p['runtime_ms'] !== null
                && abs($p['runtime_ms'] - $durationMs) > $durationMs * self::MATCH_TOLERANCE) {
                continue;
            }

            return $p;
        }

        return null;
    }

    /**
     * Audnexus' book (a fuller record than the search's: the full-size
     * image, the primary series), normalised like product(); null when it
     * could not be asked.
     */
    public function details(string $asin): ?array
    {
        if (!static::validAsin($asin)) {
            return null;
        }
        $json = CatalogueHttp::getJson('Audnexus', self::AUDNEXUS . '/books/' . $asin, ['region' => static::region()]);
        if ($json === null || empty($json['title'])) {
            return null;
        }
        $series = is_array($json['seriesPrimary'] ?? null) ? $json['seriesPrimary'] : null;

        return [
            'asin' => (string) ($json['asin'] ?? $asin),
            'title' => trim((string) $json['title']),
            'subtitle' => !empty($json['subtitle']) ? trim((string) $json['subtitle']) : null,
            'authors' => static::names($json['authors'] ?? []),
            'narrators' => static::names($json['narrators'] ?? []),
            'series' => $series !== null && trim((string) ($series['name'] ?? '')) !== '' ? trim((string) $series['name']) : null,
            'series_index' => $series !== null ? \App\Books\BookSeries::index($series['position'] ?? null) : null,
            'description' => !empty($json['summary']) ? Epub::plainDescription((string) $json['summary']) : (!empty($json['description']) ? Epub::plainDescription((string) $json['description']) : null),
            'runtime_ms' => is_numeric($json['runtimeLengthMin'] ?? null) ? (int) $json['runtimeLengthMin'] * 60000 : null,
            'year' => preg_match('/^(\d{4})/', (string) ($json['releaseDate'] ?? ''), $m) ? (int) $m[1] : null,
            'cover_url' => static::httpsUrl($json['image'] ?? null),
        ];
    }

    /**
     * Audnexus' chapters: [runtime_ms, chapters [{title, start_ms, end_ms}]]
     * in whole-book positions, or null (no chapters, or it could not be
     * asked).
     *
     * @return ?array{runtime_ms: int, chapters: list<array{title: string, start_ms: int, end_ms: int}>}
     */
    public function chapters(string $asin): ?array
    {
        if (!static::validAsin($asin)) {
            return null;
        }
        $json = CatalogueHttp::getJson('Audnexus', self::AUDNEXUS . '/books/' . $asin . '/chapters', ['region' => static::region()]);
        if ($json === null) {
            return null;
        }
        $runtime = (int) ($json['runtimeLengthMs'] ?? 0);
        $chapters = [];
        foreach ((array) ($json['chapters'] ?? []) as $c) {
            if (!is_array($c) || !is_numeric($c['startOffsetMs'] ?? null)) {
                continue;
            }
            $start = max(0, (int) $c['startOffsetMs']);
            $length = max(0, (int) ($c['lengthMs'] ?? 0));
            $title = trim((string) ($c['title'] ?? ''));
            $chapters[] = [
                'title' => mb_substr($title !== '' ? $title : 'Chapter ' . (count($chapters) + 1), 0, 255),
                'start_ms' => $start,
                'end_ms' => $start + $length,
            ];
        }
        usort($chapters, fn($a, $b) => $a['start_ms'] <=> $b['start_ms']);
        if ($chapters === [] || $runtime <= 0) {
            return null;
        }

        return ['runtime_ms' => $runtime, 'chapters' => $chapters];
    }

    /** A catalogue product, normalised; null without an ASIN or title. */
    public static function product(array $p): ?array
    {
        $asin = (string) ($p['asin'] ?? '');
        $title = trim((string) ($p['title'] ?? ''));
        if (!static::validAsin($asin) || $title === '') {
            return null;
        }
        // The series with a number first ("The Mistborn Saga" 1 before "The Cosmere").
        $series = null;
        $index = null;
        foreach ((array) ($p['series'] ?? []) as $s) {
            $name = is_array($s) ? trim((string) ($s['title'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $position = \App\Books\BookSeries::index($s['sequence'] ?? null);
            if ($series === null || ($index === null && $position !== null)) {
                [$series, $index] = [$name, $position];
            }
        }
        $images = (array) ($p['product_images'] ?? []);
        krsort($images, SORT_NUMERIC);
        $summary = $p['publisher_summary'] ?? $p['merchandising_summary'] ?? null;

        return [
            'asin' => $asin,
            'title' => $title,
            'subtitle' => !empty($p['subtitle']) ? trim((string) $p['subtitle']) : null,
            'authors' => static::names($p['authors'] ?? []),
            'narrators' => static::names($p['narrators'] ?? []),
            'series' => $series,
            'series_index' => $index,
            'description' => !empty($summary) ? Epub::plainDescription((string) $summary) : null,
            'runtime_ms' => is_numeric($p['runtime_length_min'] ?? null) ? (int) $p['runtime_length_min'] * 60000 : null,
            'year' => preg_match('/^(\d{4})/', (string) ($p['release_date'] ?? ''), $m) ? (int) $m[1] : null,
            'cover_url' => static::bigImage(static::httpsUrl(reset($images) ?: null)),
        ];
    }

    /** Amazon's "…._SL500_.jpg" without the size suffix: the original (large) image. */
    public static function bigImage(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_replace('/\._[A-Z]{2}\d+_(\.(?:jpg|png))$/i', '$1', $url) ?? $url;
    }

    public static function validAsin(string $asin): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{10}$/', $asin);
    }

    /** @return list<string> */
    private static function names(mixed $people): array
    {
        $names = [];
        foreach ((array) $people as $person) {
            $name = trim((string) (is_array($person) ? ($person['name'] ?? '') : $person));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private static function httpsUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        return preg_match('#^https?://#i', $url) ? preg_replace('#^http://#i', 'https://', $url) : null;
    }
}
