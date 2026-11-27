<?php

namespace App\Books;

/**
 * Apple Books through the iTunes Search API (no key):
 * GET https://itunes.apple.com/search?term=…&media=ebook&entity=ebook
 * (media=audiobook for audiobooks). Mainly for its large covers: the
 * artworkUrl100 ("…/100x100bb.jpg") is served at any size, so it is asked
 * for at 1200×1200 (COVER_SIZE); also an extra candidate (title,
 * artistName, description, releaseDate, genres, trackViewUrl).
 *
 * The search is free text and often answers other books by the same
 * author first, so callers keep only results whose title (and author)
 * agree (BookLookup's rule). Failures are empty answers (CatalogueHttp).
 */
class AppleBooks
{
    public const SEARCH = 'https://itunes.apple.com/search';

    /** The size covers are asked for ("1200x1200bb"). */
    public const COVER_SIZE = 1200;

    /** The country's store searched (two letters; config novarr.books.itunes_country). */
    public static function country(): string
    {
        $country = strtolower(trim((string) config('novarr.books.itunes_country', 'us')));

        return preg_match('/^[a-z]{2}$/', $country) ? $country : 'us';
    }

    /**
     * The large version of an iTunes artwork URL: "…/100x100bb.jpg" →
     * "…/1200x1200bb.jpg" (any NNNxNNN size). Null for anything else.
     */
    public static function bigCover(?string $url, int $size = self::COVER_SIZE): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        $big = preg_replace('#/\d{2,4}x\d{2,4}(bb|cc|sr)?(\.(?:jpg|jpeg|png|webp))$#i', "/{$size}x{$size}bb$2", $url, 1, $count);

        return $count > 0 && $big !== null ? str_replace('http://', 'https://', $big) : null;
    }

    /**
     * Raw results for a term, or null when Apple could not be asked.
     *
     * @param  string  $media  'ebook' or 'audiobook'
     * @return ?list<array>
     */
    public function search(string $term, string $media = 'ebook', int $limit = 10): ?array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $json = CatalogueHttp::getJson('Apple Books', self::SEARCH, [
            'term' => mb_substr($term, 0, 200),
            'media' => $media,
            'entity' => $media,
            'limit' => max(1, min(25, $limit)),
            'country' => static::country(),
        ]);
        if ($json === null) {
            return null;
        }

        return array_values(array_filter((array) ($json['results'] ?? []), 'is_array'));
    }

    /**
     * BookLookup candidates (ebooks) for a title and author.
     *
     * @return list<array>
     */
    public function candidates(string $title, string $author, int $limit = 8): array
    {
        if (trim($title) === '') {
            return [];
        }
        $out = [];
        foreach ($this->search(trim(BookLookup::cleanTitle($title) . ' ' . $author), 'ebook', $limit) ?? [] as $result) {
            $candidate = static::candidate($result);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /** An ebook (or audiobook) result as a BookLookup candidate; null without a title. */
    public static function candidate(array $result): ?array
    {
        $title = trim((string) ($result['trackName'] ?? $result['collectionName'] ?? ''));
        if ($title === '') {
            return null;
        }
        $author = trim((string) ($result['artistName'] ?? ''));
        $genres = array_values(array_filter(array_map('strval', (array) ($result['genres'] ?? [])), fn($g) => !in_array($g, ['Books', 'Audiobooks'], true)));
        $id = $result['trackId'] ?? $result['collectionId'] ?? md5($title);

        return BookLookup::shape([
            'source' => 'itunes',
            'source_name' => 'Apple Books',
            'key' => 'itunes:' . $id,
            'title' => $title,
            'authors' => $author !== '' ? array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|&| and )\s*/u', $author) ?: []))) : [],
            'description' => !empty($result['description']) ? Epub::plainDescription((string) $result['description']) : null,
            'genres' => normalizeGenres($genres),
            'year' => preg_match('/^(\d{4})/', (string) ($result['releaseDate'] ?? ''), $m) ? (int) $m[1] : null,
            'cover_url' => static::bigCover($result['artworkUrl100'] ?? $result['artworkUrl60'] ?? null),
            'url' => !empty($result['trackViewUrl'] ?? $result['collectionViewUrl'] ?? null)
                ? (string) ($result['trackViewUrl'] ?? $result['collectionViewUrl'])
                : null,
        ]);
    }

    /**
     * The large cover of the first result whose title (and author) agree,
     * or null.
     *
     * @param  string  $media  'ebook' or 'audiobook'
     */
    public function coverFor(string $title, ?string $author, string $media = 'ebook'): ?string
    {
        foreach ($this->search(trim(BookLookup::cleanTitle($title) . ' ' . (string) $author), $media, 8) ?? [] as $result) {
            $candidate = static::candidate($result);
            if ($candidate === null || $candidate['cover_url'] === null) {
                continue;
            }
            // A dramatisation or one volume of a split release ("(2 of 3) [Dramatized Adaptation]") is another product.
            if (preg_match('/dramati[sz]ed|\(\s*\d+\s+of\s+\d+\s*\)/i', $candidate['title']) && !preg_match('/dramati[sz]ed|\(\s*\d+\s+of\s+\d+\s*\)/i', $title)) {
                continue;
            }
            if (BookLookup::sameTitleAndAuthor($title, $author !== null && trim($author) !== '' ? [$author] : [], $candidate['title'], $candidate['authors'])) {
                return $candidate['cover_url'];
            }
        }

        return null;
    }
}
