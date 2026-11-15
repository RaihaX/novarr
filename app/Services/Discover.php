<?php

namespace App\Services;

use App\Novel;
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
     * Sources on the Discover page, keyed by source key, in page order; the
     * first one is the default.
     *
     * @return array<string, Source>
     */
    public function sources(): array
    {
        return SourceResolver::discoverable();
    }

    /**
     * The Discover source picker, in page order.
     *
     * @return array<string, array{label: string, host: string, lists: string[]}>
     */
    public function sourceOptions(): array
    {
        return array_map(fn(Source $source) => [
            'label' => $source->discoverLabel(),
            'host' => $source->discoverHost(),
            'lists' => $source->discoverLists(),
        ], $this->sources());
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
        if ($items === null) {
            return null;
        }

        $library = $this->libraryIndex();
        foreach ($items as &$item) {
            $item['novel_id'] = $this->match($item, $library);
            $item['in_library'] = $item['novel_id'] !== null;
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
     * @return array{slugs: array<string, array<string, int>>, urls: array<string, int>, names: array<string, int>}
     */
    public function libraryIndex(): array
    {
        $index = ['slugs' => [], 'urls' => [], 'names' => []];
        $sources = SourceResolver::all();

        foreach (Novel::query()->orderBy('id')->get(['id', 'name', 'translator_url']) as $novel) {
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
            }
        }

        return $index;
    }

    /**
     * The library novel a result item (['name', 'url', …]) is, by slug, then
     * URL, then name; null when it isn't in the library.
     */
    public function match(array $item, array $library): ?int
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

        return $name !== '' ? ($library['names'][$name] ?? null) : null;
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
