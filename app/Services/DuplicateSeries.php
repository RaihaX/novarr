<?php

namespace App\Services;

use App\Novel;
use App\Sources\SourceResolver;

/**
 * Is a series already in the library? The one matcher every add path uses
 * (Discover marking, the web add forms, POST /api/v1/novels, novel:create),
 * so the same work is not added twice from two sources.
 *
 * A candidate is the library series it duplicates when:
 *   - its URL is the same source page (per-source slug, else the normalised
 *     URL) — `by` "source"; or
 *   - its title, or one of its alternative titles, normalises (normalize())
 *     to the title of a series of the same medium — `by` "title".
 *
 * Exact match on the normalised form only, never similarity: "Solo
 * Leveling" and "Solo Leveling: Ragnarok" stay different works. Two works
 * can still share a title, so a title match is a warning the add paths let
 * the user override (force); a source match is the same page and is not.
 *
 * Soft-deleted novels are not in the library and never match.
 */
class DuplicateSeries
{
    /**
     * Trailing bracketed tags that say how a copy is published, not which
     * work it is: "Solo Leveling (Official)", "Berserk [Colored]". Only
     * these words are dropped, so "Title (2019)" stays apart from "Title".
     */
    public const TAGS = [
        'official', 'colored', 'coloured', 'full color', 'full colour', 'color', 'colour',
        'manga', 'manhwa', 'manhua', 'webtoon', 'comic', 'novel', 'light novel', 'web novel',
        'wn', 'ln', 'raw', 'english', 'eng', 'en', 'digital', 'remastered', 'uncensored',
    ];

    /** The kinds of match match() reports. */
    public const BY_SOURCE = 'source';
    public const BY_TITLE = 'title';

    /**
     * The comparable form of a title: lower case, accents and punctuation
     * dropped, no whitespace at all, "&" read as "and", a leading "the"
     * ignored, trailing publication tags (TAGS) removed. '' when nothing
     * comparable is left.
     */
    public static function normalize(string $title): string
    {
        $title = html_entity_decode(trim($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = mb_strtolower($title);

        // Accents: decompose, then drop the combining marks ("é" → "e").
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($title, \Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $title = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $title;
            }
        }

        // Trailing "(Official)" / "[Colored]" / "(WN)" … possibly several.
        $tags = implode('|', array_map(fn($tag) => preg_quote($tag, '/'), self::TAGS));
        do {
            $before = $title;
            $title = preg_replace('/\s*[\(\[\{]\s*(?:' . $tags . ')\s*[\)\]\}]\s*$/u', '', $title) ?? $title;
        } while ($title !== $before);

        $title = str_replace('&', ' and ', $title);
        // Words only: punctuation becomes a separator, for the "the" check.
        $title = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace(["'", "\u{2019}"], '', $title)) ?? '');
        $title = preg_replace('/^the\s+/u', '', $title) ?? $title;

        return str_replace(' ', '', $title);
    }

    /**
     * The library, for any number of match() calls from one query: per-source
     * slugs (Source::librarySlug()), normalised URLs, and normalised titles
     * per medium, each mapping to a novel id. The lowest id wins a shared key.
     *
     * @return array{slugs: array<string, array<string, int>>, urls: array<string, int>, titles: array<string, array<string, int>>}
     */
    public function index(): array
    {
        $index = ['slugs' => [], 'urls' => [], 'titles' => []];
        $sources = SourceResolver::all();

        foreach (Novel::query()->orderBy('id')->get(['id', 'name', 'translator_url', 'medium']) as $novel) {
            $id = (int) $novel->id;
            $url = (string) $novel->translator_url;
            if ($url !== '') {
                $index['urls'][Discover::normalizeUrl($url)] ??= $id;
                foreach ($sources as $key => $source) {
                    if (($slug = $source->librarySlug($url, stored: true)) !== null) {
                        $index['slugs'][$key][$slug] ??= $id;
                    }
                }
            }

            // The Novel model keeps no alternative titles: its name is the one.
            $title = self::normalize((string) $novel->name);
            if ($title !== '') {
                $index['titles'][(string) ($novel->medium ?: Novel::MEDIUM_NOVEL)][$title] ??= $id;
            }
        }

        return $index;
    }

    /**
     * The library series a candidate is, against an index(): the same source
     * page first, then a title (or alternative title) of the same medium.
     * A null medium compares titles across both libraries.
     *
     * @param  string[]  $titles  the title, then any alternative titles
     * @return ?array{id: int, by: string}
     */
    public function match(array $index, ?string $medium, array $titles, ?string $url = null): ?array
    {
        $url = trim((string) $url);
        if ($url !== '') {
            foreach ($index['slugs'] as $key => $slugs) {
                $slug = SourceResolver::get($key)?->librarySlug($url);
                if ($slug !== null && isset($slugs[$slug])) {
                    return ['id' => $slugs[$slug], 'by' => self::BY_SOURCE];
                }
            }
            $normalized = Discover::normalizeUrl($url);
            if (isset($index['urls'][$normalized])) {
                return ['id' => $index['urls'][$normalized], 'by' => self::BY_SOURCE];
            }
        }

        $byMedium = $medium !== null ? [$index['titles'][$medium] ?? []] : array_values($index['titles']);
        foreach ($titles as $title) {
            $key = self::normalize((string) $title);
            if ($key === '') {
                continue;
            }
            foreach ($byMedium as $known) {
                if (isset($known[$key])) {
                    return ['id' => $known[$key], 'by' => self::BY_TITLE];
                }
            }
        }

        return null;
    }

    /**
     * The library series a new title/URL would duplicate, or null. The
     * medium is the URL's source's when not given (novel without a URL).
     *
     * @param  string[]  $alternativeTitles
     * @return ?array{novel: Novel, by: string}
     */
    public function find(string $title, ?string $medium = null, array $alternativeTitles = [], ?string $url = null): ?array
    {
        $medium ??= self::mediumFor($url);
        $match = $this->match($this->index(), $medium, array_merge([$title], $alternativeTitles), $url);
        if ($match === null) {
            return null;
        }

        $novel = Novel::find($match['id']);

        return $novel === null ? null : ['novel' => $novel, 'by' => $match['by']];
    }

    /** The medium a series from this URL is (its source's); a novel without one. */
    public static function mediumFor(?string $url): string
    {
        $url = trim((string) $url);

        return $url !== '' ? SourceResolver::mediumForUrl($url) : Novel::MEDIUM_NOVEL;
    }

    /**
     * The existing series as the API and the web refusal describe it:
     * id, title and source (the host of its source URL; null without one).
     *
     * @return array{id: int, title: string, source: ?string}
     */
    public static function describe(Novel $novel): array
    {
        return [
            'id' => (int) $novel->id,
            'title' => (string) $novel->name,
            'source' => self::sourceHost((string) $novel->translator_url),
        ];
    }

    /** "mangadex.org" for "https://www.mangadex.org/title/…"; null without a host. */
    public static function sourceHost(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower($host));
    }

    /**
     * "“Solo Leveling” is already in your library (from mangadex.org)." —
     * the one sentence every add path shows for a duplicate.
     */
    public static function message(Novel $novel): string
    {
        $source = self::sourceHost((string) $novel->translator_url);

        return "\u{201C}{$novel->name}\u{201D} is already in your library" . ($source !== null ? " (from {$source})" : '') . '.';
    }
}
