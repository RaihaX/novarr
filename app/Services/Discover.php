<?php

namespace App\Services;

use App\Sources\PagedDiscover;
use App\Sources\Source;
use App\Sources\SourceResolver;

/**
 * Discover ("Add Novel"): the sources that list themselves for Discover and
 * their result lists, each item marked when the novel is already in the
 * library. Shared by the web Discover page (DiscoverController) and the
 * native API (Api\V1\DiscoverController).
 */
class Discover
{
    /** The lists a source may offer (Source::discoverLists()). */
    public const TYPES = ['search', 'popular', 'new', 'random', 'completed'];

    /**
     * Setting: JSON array of source keys left off Discover ("Sources when
     * adding" on the Settings page). Hidden rather than shown is stored, so
     * a source installed later appears by default.
     */
    public const HIDDEN_SETTING = 'discover_hidden_sources';

    /**
     * Sources on the Discover page, keyed by source key, in page order (text
     * sources first); the first one is the default. Hidden sources
     * (hiddenKeys()) are left out.
     *
     * @return array<string, Source>
     */
    public function sources(): array
    {
        return array_diff_key($this->allSources(), array_flip($this->hiddenKeys()));
    }

    /**
     * Every discoverable source, hidden ones included (the Settings page's
     * source picker).
     *
     * @return array<string, Source>
     */
    public function allSources(): array
    {
        return SourceResolver::discoverable();
    }

    /**
     * The source keys hidden from Discover. A missing or malformed setting
     * is []; unknown (stale) keys are kept but match nothing.
     *
     * @return string[]
     */
    public function hiddenKeys(): array
    {
        $decoded = json_decode((string) setting(self::HIDDEN_SETTING, ''), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter($decoded, fn($key) => is_string($key) && $key !== '')));
    }

    /**
     * sources() of one medium: 'novel' / 'manga' keeps that medium, null or
     * 'all' every source.
     *
     * @return array<string, Source>
     */
    public function sourcesFor(?string $medium): array
    {
        return self::ofMedium($this->sources(), $medium);
    }

    /**
     * @param  array<string, Source>  $sources
     * @return array<string, Source>
     */
    public static function ofMedium(array $sources, ?string $medium): array
    {
        if ($medium === null || $medium === 'all') {
            return $sources;
        }

        return array_filter($sources, fn(Source $source) => $source->medium() === $medium);
    }

    /**
     * The Discover source picker, in page order.
     *
     * @return array<string, array{label: string, host: string, lists: string[], medium: string}>
     */
    public function sourceOptions(?string $medium = null): array
    {
        return array_map(fn(Source $source) => [
            'label' => $source->discoverLabel(),
            'host' => $source->discoverHost(),
            'lists' => $source->discoverLists(),
            'medium' => $source->medium(),
        ], $this->sourcesFor($medium));
    }

    /**
     * One list of a source, every item carrying `in_library` (bool) and
     * `novel_id` (the matching library novel, or null). [] for a list the
     * source doesn't offer; null when the source could not be reached.
     *
     * The library match runs after the source's own cache read: that cache
     * is keyed by the source query alone, since the library changes far
     * more often than the lists.
     */
    public function results(Source $source, string $type, ?string $query = null): ?array
    {
        if (!in_array($type, $source->discoverLists(), true)) {
            return [];
        }

        $items = $source->discover($type, $query);

        return $items === null ? null : $this->decorate($source, $items);
    }

    /**
     * One page (1-based) of a list, items as results(), plus whether the
     * source has another page. A PagedDiscover source pages itself; any
     * other source has page 1 (results()) and nothing after it. Null when
     * the source could not be reached.
     *
     * @return ?array{items: array<int, array>, has_more: bool}
     */
    public function page(Source $source, string $type, ?string $query, int $page): ?array
    {
        if (!in_array($type, $source->discoverLists(), true) || $page < 1) {
            return ['items' => [], 'has_more' => false];
        }

        if (!$source instanceof PagedDiscover) {
            if ($page > 1) {
                return ['items' => [], 'has_more' => false];
            }
            $items = $this->results($source, $type, $query);

            return $items === null ? null : ['items' => $items, 'has_more' => false];
        }

        $result = $source->discoverPage($type, $query, $page);
        if ($result === null) {
            return null;
        }

        return ['items' => $this->decorate($source, $result['items']), 'has_more' => (bool) $result['has_more']];
    }

    /**
     * Library flags and proxied covers on a source's items (see results()).
     * The library is read once for the whole list (libraryIndex()), so
     * marking a page of results is one query. `library_match` says how an
     * item matched: "source" (the same page) or "title" (the same title of
     * the medium, from any source) — DuplicateSeries.
     */
    private function decorate(Source $source, array $items): array
    {
        $library = $this->libraryIndex();
        foreach ($items as &$item) {
            $match = $this->libraryMatch($item, $library, $source->medium());
            $item['novel_id'] = $match['id'] ?? null;
            $item['in_library'] = $item['novel_id'] !== null;
            $item['library_match'] = $match['by'] ?? null;
            // A host that refuses hotlinked images: the cover comes through
            // Novarr (after the source's cache read, so the URL is built on
            // this request's origin).
            foreach (['cover', 'cover_thumb'] as $key) {
                if (!empty($item[$key]) && \App\Http\Controllers\DiscoverCoverController::proxied((string) $item[$key])) {
                    $item[$key] = route('discover.cover', ['u' => (string) $item[$key]]);
                }
            }
        }
        unset($item);

        return $items;
    }

    /**
     * The library in one query, for any number of match() calls: per-source
     * slugs, normalised URLs and normalised titles per medium
     * (DuplicateSeries::index()).
     *
     * @return array{slugs: array<string, array<string, int>>, urls: array<string, int>, titles: array<string, array<string, int>>}
     */
    public function libraryIndex(): array
    {
        return app(DuplicateSeries::class)->index();
    }

    /**
     * The library novel a result item (['name', 'url', optional
     * 'alt_titles', …]) is: the same source page, else the same title in
     * the medium (DuplicateSeries::match()); null when it isn't in the
     * library. The item URL's source decides the medium when not given.
     */
    public function match(array $item, array $library, ?string $medium = null): ?int
    {
        return $this->libraryMatch($item, $library, $medium)['id'] ?? null;
    }

    /**
     * match() and how it matched.
     *
     * @return ?array{id: int, by: string}
     */
    public function libraryMatch(array $item, array $library, ?string $medium = null): ?array
    {
        $url = (string) ($item['url'] ?? '');
        $medium ??= $url !== '' ? SourceResolver::forUrl($url)->medium() : null;
        $titles = array_merge([(string) ($item['name'] ?? '')], array_map('strval', (array) ($item['alt_titles'] ?? [])));

        return app(DuplicateSeries::class)->match($library, $medium, $titles, $url);
    }

    /** Scheme, "www.", case and a trailing "/" or "_" don't make a different novel. */
    public static function normalizeUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/_');
    }

    /** Lowercase, punctuation stripped, whitespace collapsed: "Re:Zero!" ≈ "re zero". */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = str_replace(["'", "\u{2019}"], '', $name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';

        return trim($name);
    }
}
