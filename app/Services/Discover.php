<?php

namespace App\Services;

use App\Novel;
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
     * Sources on the Discover page, keyed by source key, in page order (text
     * sources first); the first one is the default.
     *
     * @return array<string, Source>
     */
    public function sources(): array
    {
        return SourceResolver::discoverable();
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

    /** Library flags and proxied covers on a source's items (see results()). */
    private function decorate(Source $source, array $items): array
    {
        $library = $this->libraryIndex();
        foreach ($items as &$item) {
            $item['novel_id'] = $this->match($item, $library, $source->medium());
            $item['in_library'] = $item['novel_id'] !== null;
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
     * Library lookup sets, each mapping a key to a novel id: per-source slugs
     * (Source::librarySlug() — for NovelPing either host, legacy novelbin
     * too), normalised URLs for other sources, normalised names. The first
     * (lowest id) novel wins a shared key.
     *
     * Names are kept per medium too (`names_by_medium`), so a manga is never
     * matched to the novel of the same name (and the other way round).
     *
     * @return array{slugs: array<string, array<string, int>>, urls: array<string, int>, names: array<string, int>, names_by_medium: array<string, array<string, int>>}
     */
    public function libraryIndex(): array
    {
        $index = ['slugs' => [], 'urls' => [], 'names' => [], 'names_by_medium' => []];
        $sources = SourceResolver::all();

        foreach (Novel::query()->orderBy('id')->get(['id', 'name', 'translator_url', 'medium']) as $novel) {
            $id = (int) $novel->id;
            $url = (string) $novel->translator_url;
            if ($url !== '') {
                $index['urls'][self::normalizeUrl($url)] ??= $id;
                foreach ($sources as $key => $source) {
                    if (($slug = $source->librarySlug($url, stored: true)) !== null) {
                        $index['slugs'][$key][$slug] ??= $id;
                    }
                }
            }
            $name = self::normalizeName((string) $novel->name);
            if ($name !== '') {
                $index['names'][$name] ??= $id;
                $index['names_by_medium'][(string) ($novel->medium ?: Novel::MEDIUM_NOVEL)][$name] ??= $id;
            }
        }

        return $index;
    }

    /**
     * The library novel a result item (['name', 'url', …]) is, by slug, then
     * URL, then name; null when it isn't in the library. With $medium the
     * name match only looks at novels of that medium (the item's URL decides
     * it when not given).
     */
    public function match(array $item, array $library, ?string $medium = null): ?int
    {
        $url = (string) ($item['url'] ?? '');
        if ($url !== '') {
            foreach ($library['slugs'] as $key => $slugs) {
                $slug = SourceResolver::get($key)?->librarySlug($url);
                if ($slug !== null && isset($slugs[$slug])) {
                    return $slugs[$slug];
                }
            }
            if (isset($library['urls'][self::normalizeUrl($url)])) {
                return $library['urls'][self::normalizeUrl($url)];
            }
        }

        $name = self::normalizeName((string) ($item['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $medium ??= $url !== '' ? SourceResolver::forUrl($url)->medium() : null;
        if ($medium !== null && isset($library['names_by_medium'])) {
            return $library['names_by_medium'][$medium][$name] ?? null;
        }

        return $library['names'][$name] ?? null;
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
