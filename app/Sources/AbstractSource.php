<?php

namespace App\Sources;

use App\Novel;

/**
 * Defaults for the optional parts of Source, so an adapter only overrides
 * what differs for its site: content-extraction hints, URL matching by
 * host, no chapter API fast path, and no Discover listing.
 */
abstract class AbstractSource implements Source
{
    /**
     * Known chapter-body containers across supported (and look-alike)
     * sites, tried after the adapter's own selectors.
     */
    public const GENERIC_CONTENT_SELECTORS = [
        '#chr-content',
        '.chr-c',
        '.chapter-content',
        '#chapter-content',
        '.entry-content',
        '#read-novel',
        '.reader-page',
        'article',
        '.text',
        '#content',
    ];

    /** Noise removed on every site. */
    public const GENERIC_REMOVE_SELECTORS = [
        'script', 'style', 'noscript', 'iframe', 'template', 'button',
        'ins.adsbygoogle',
        '[data-format]',
    ];

    /**
     * Host names this source serves, e.g. ['novelfull.com']. The default
     * matches()/matchesUrl() accept a URL that contains one of them
     * (case-insensitive), and the first is the Discover host.
     *
     * @return string[]
     */
    public function hosts(): array
    {
        return [];
    }

    public function matches(Novel $novel): bool
    {
        return $this->matchesUrl((string) ($novel->translator_url ?? ''));
    }

    public function matchesUrl(string $url): bool
    {
        foreach ($this->hosts() as $host) {
            if ($host !== '' && stripos($url, $host) !== false) {
                return true;
            }
        }

        return false;
    }

    public function contentSelectors(): array
    {
        return self::GENERIC_CONTENT_SELECTORS;
    }

    public function removeSelectors(): array
    {
        return self::GENERIC_REMOVE_SELECTORS;
    }

    public function supportsMultiPage(): ?string
    {
        return null;
    }

    /** No API fast path: chapters are fetched as pages. */
    public function chapterContent(string $url, ?string &$failure = null): ?array
    {
        $failure = null;

        return null;
    }

    public function discoverLabel(): string
    {
        return $this->discoverHost();
    }

    public function discoverHost(): string
    {
        return $this->hosts()[0] ?? $this->name();
    }

    /** Not on the Discover page unless an adapter lists something. */
    public function discoverLists(): array
    {
        return [];
    }

    public function discover(string $type, ?string $query = null): ?array
    {
        return [];
    }

    public function librarySlug(string $url, bool $stored = false): ?string
    {
        return null;
    }

    /** The site's own metadata for the novel (merged by metadata()). */
    protected function siteMetadata(Novel $novel): array
    {
        return [];
    }

    /** NovelUpdates metadata for the novel (see getMetadata()). */
    protected function novelUpdatesMetadata(Novel $novel): array
    {
        return getMetadata($novel);
    }

    /**
     * Source synopses arrive as HTML fragments; discover cards show plain
     * text. Block-level tags become spaces so paragraphs don't run together,
     * and the result is capped — a card never needs the whole thing.
     */
    public static function plainSynopsis(string $html, int $limit = 900): string
    {
        // Opening tags count too: sources emit unclosed <p>s, and stripping
        // those without a separator welds the last word to the next sentence.
        $text = preg_replace('/<\/?(br|p|div|li|ul|ol|h[1-6]|blockquote)\b[^>]*>/i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Cut on a word boundary rather than mid-word.
        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace > $limit / 2 ? mb_substr($cut, 0, $lastSpace) : $cut, " \t\n.,;:") . '…';
    }

    /** "Ongoing" / "Completed" from a source status string, else null. */
    public static function statusLabel($status): ?string
    {
        if (!is_string($status) || trim($status) === '') {
            return null;
        }
        $status = strtolower(trim($status));

        return match (true) {
            str_contains($status, 'complete') => 'Completed',
            str_contains($status, 'ongoing') => 'Ongoing',
            default => ucfirst($status),
        };
    }
}
