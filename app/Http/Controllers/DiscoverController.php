<?php

namespace App\Http\Controllers;

use App\Novel;
use App\Sources\EmpireNovelSource;
use App\Sources\Source;
use App\Sources\SourceResolver;
use Illuminate\Http\Request;

/**
 * Sonarr-style "Add Novel" discovery: search and browse the registered
 * sources that list themselves for Discover (Source::discoverLists(); by
 * default NovelPing — source key "novelarrow" — empirenovel and novelfull),
 * then add a result via the existing novel:create background command.
 */
class DiscoverController extends Controller
{

    public function index()
    {
        $sources = $this->sourceOptions();

        return view('novels.discover', [
            'sources' => $sources,
            'sourceHosts' => array_map(fn($option) => $option['host'], $sources),
            'sourceLists' => array_map(fn($option) => $option['lists'], $sources),
        ]);
    }

    /**
     * The Discover source picker, in page order (SourceResolver::discoverable()).
     *
     * @return array<string, array{label: string, host: string, lists: string[]}>
     */
    protected function sourceOptions(): array
    {
        return array_map(fn(Source $source) => [
            'label' => $source->discoverLabel(),
            'host' => $source->discoverHost(),
            'lists' => $source->discoverLists(),
        ], SourceResolver::discoverable());
    }

    /**
     * Fetch a result list from a source.
     * type: search (requires q) | popular | new | random | completed
     *
     * Browse lists (popular/completed) leave out novels already in the
     * library and report how many were hidden; typed searches keep every
     * result and only mark library ones (`in_library`). The filter runs
     * after the cache read — the cache is keyed by the source query alone,
     * since the library changes far more often than the lists.
     */
    public function browse(Request $request)
    {
        $sources = SourceResolver::discoverable();

        // A registry in which no source offers Discover has nothing to list.
        if ($sources === []) {
            return response()->json(['success' => true, 'items' => []]);
        }

        $data = $request->validate([
            'source' => 'nullable|in:' . implode(',', array_keys($sources)),
            'type' => 'required|in:search,popular,new,random,completed',
            'q' => 'required_if:type,search|nullable|string|max:100',
        ]);

        // No source given: the page's default (the first one listed).
        $source = $sources[$data['source'] ?? array_key_first($sources)];

        // A list the source doesn't offer (e.g. browse lists on a
        // search-only source) is an empty answer.
        if (!in_array($data['type'], $source->discoverLists(), true)) {
            return response()->json(['success' => true, 'items' => []]);
        }

        $items = $source->discover($data['type'], $data['q'] ?? null);
        $sourceLabel = $source->discoverHost();

        if ($items === null) {
            return response()->json([
                'success' => false,
                'message' => "Could not reach {$sourceLabel} — try again shortly.",
            ], 502);
        }

        // Mark results that are already in the library (by slug/URL or name).
        $library = $this->libraryIndex();
        foreach ($items as &$item) {
            $item['in_library'] = $this->inLibrary($item, $library);
        }
        unset($item);

        $hidden = 0;
        if ($data['type'] !== 'search') {
            $before = count($items);
            $items = array_values(array_filter($items, fn($item) => !$item['in_library']));
            $hidden = $before - count($items);
        }

        return response()->json(['success' => true, 'items' => $items, 'hidden' => $hidden]);
    }

    /**
     * Library lookup sets: per-source slugs (Source::librarySlug() — for
     * NovelPing either host, legacy novelbin too), normalised URLs for
     * other sources, normalised names.
     *
     * @return array{slugs: array<string, array<string, true>>, urls: array<string, true>, names: array<string, true>}
     */
    protected function libraryIndex(): array
    {
        $index = ['slugs' => [], 'urls' => [], 'names' => []];
        $sources = SourceResolver::all();

        foreach (Novel::query()->get(['name', 'translator_url']) as $novel) {
            $url = (string) $novel->translator_url;
            if ($url !== '') {
                $index['urls'][self::normalizeUrl($url)] = true;
                foreach ($sources as $key => $source) {
                    if (($slug = $source->librarySlug($url, stored: true)) !== null) {
                        $index['slugs'][$key][$slug] = true;
                    }
                }
            }
            $name = self::normalizeName((string) $novel->name);
            if ($name !== '') {
                $index['names'][$name] = true;
            }
        }

        return $index;
    }

    protected function inLibrary(array $item, array $library): bool
    {
        $url = (string) ($item['url'] ?? '');
        if ($url !== '') {
            foreach ($library['slugs'] as $key => $slugs) {
                $slug = SourceResolver::get($key)?->librarySlug($url);
                if ($slug !== null && isset($slugs[$slug])) {
                    return true;
                }
            }
            if (isset($library['urls'][self::normalizeUrl($url)])) {
                return true;
            }
        }

        $name = self::normalizeName((string) ($item['name'] ?? ''));

        return $name !== '' && isset($library['names'][$name]);
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

    /**
     * Parse an Empire Novel search-live response into result items; kept
     * for existing callers — see EmpireNovelSource::parseSearch().
     */
    public function parseEmpireNovelSearch(string $body): array
    {
        return EmpireNovelSource::parseSearch($body);
    }
}
