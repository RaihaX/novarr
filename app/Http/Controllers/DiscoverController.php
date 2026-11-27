<?php

namespace App\Http\Controllers;

use App\Http\Helpers\LibraryMedium;
use App\Services\Discover;
use App\Sources\EmpireNovelSource;
use App\Sources\PagedDiscover;
use Illuminate\Http\Request;

/**
 * Sonarr-style "Add Novel" discovery: search and browse the registered
 * sources that list themselves for Discover (Source::discoverLists(); by
 * default NovelPing — source key "novelarrow" — empirenovel and novelfull),
 * then add a result via the existing novel:create background command.
 */
class DiscoverController extends Controller
{

    /**
     * "Add novel" / "Add manga": the sources of one library's medium
     * (?medium=, else the remembered one — LibraryMedium).
     */
    public function index(Request $request)
    {
        $medium = LibraryMedium::resolve($request);
        // Books have no source to browse: they arrive as uploads. Asked for
        // by name, that is where Discover sends you; remembered from the
        // Books library, Discover opens on novels.
        if ($medium === \App\Novel::MEDIUM_BOOK) {
            if ($request->query('medium') === \App\Novel::MEDIUM_BOOK) {
                return redirect()->route('books.upload');
            }
            $medium = \App\Novel::MEDIUM_NOVEL;
            LibraryMedium::share($medium);
        }
        // Comics have no source to browse either: they arrive through the
        // comics inbox; readers ask for them on the Requests page.
        if ($medium === \App\Novel::MEDIUM_COMIC) {
            if ($request->query('medium') === \App\Novel::MEDIUM_COMIC) {
                return redirect()->route('requests.index', ['medium' => 'comic']);
            }
            $medium = \App\Novel::MEDIUM_NOVEL;
            LibraryMedium::share($medium);
        }
        $sources = $this->sourceOptions($medium);

        // Nothing left to pick because every source of the medium is hidden
        // (Settings → Sources when adding), as opposed to none set up.
        $allHidden = false;
        if ($sources === []) {
            $discover = app(Discover::class);
            $hidden = $discover->hiddenKeys();
            $allHidden = $hidden !== []
                && array_intersect_key(Discover::ofMedium($discover->allSources(), $medium), array_flip($hidden)) !== [];
        }

        return view('novels.discover', [
            'medium' => $medium,
            'sources' => $sources,
            'allHidden' => $allHidden,
            'sourceHosts' => array_map(fn($option) => $option['host'], $sources),
            'sourceLists' => array_map(fn($option) => $option['lists'], $sources),
        ]);
    }

    /**
     * The Discover source picker, in page order (SourceResolver::discoverable()
     * minus the sources hidden in Settings — Discover::sources()).
     *
     * @return array<string, array{label: string, host: string, lists: string[], medium: string}>
     */
    protected function sourceOptions(?string $medium = null): array
    {
        return app(Discover::class)->sourceOptions($medium);
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
     *
     * page: 1-based (default 1, at most PagedDiscover::MAX_PAGE). The answer
     * says whether the source has another page (`has_more`); only paged
     * sources (MangaDex, Suwayomi) ever do — the others answer page 1 only.
     *
     * medium: novel | manga (optional) — the Discover page's library; the
     * source must then be one of that medium's, and the default is its
     * first. Without it (or with any other value, such as "all") any
     * source is accepted, as before.
     */
    public function browse(Request $request)
    {
        $medium = $request->query('medium');

        $discover = app(Discover::class);
        $sources = $discover->sourcesFor(LibraryMedium::isValid($medium) ? $medium : null);

        // A registry in which no source offers Discover has nothing to list.
        if ($sources === []) {
            return response()->json(['success' => true, 'items' => [], 'has_more' => false, 'page' => 1]);
        }

        $data = $request->validate([
            'source' => 'nullable|in:' . implode(',', array_keys($sources)),
            'type' => 'required|in:' . implode(',', Discover::TYPES),
            'q' => 'required_if:type,search|nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:' . PagedDiscover::MAX_PAGE,
        ]);
        $page = (int) ($data['page'] ?? 1);

        // No source given: the page's default (the first one listed).
        $source = $sources[$data['source'] ?? array_key_first($sources)];

        // A list the source doesn't offer (e.g. browse lists on a
        // search-only source) is an empty answer.
        if (!in_array($data['type'], $source->discoverLists(), true)) {
            return response()->json(['success' => true, 'items' => [], 'has_more' => false, 'page' => $page]);
        }

        $result = $discover->page($source, $data['type'], $data['q'] ?? null, $page);
        $sourceLabel = $source->discoverHost();

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => "Could not reach {$sourceLabel} — try again shortly.",
            ], 502);
        }

        // A library item links to the series it is (`novel_url`); how it
        // matched (`library_match`: "source" or "title") decides whether the
        // page offers "Add anyway" (only for a title match from another source).
        $items = array_map(function (array $item) {
            $item['novel_url'] = $item['novel_id'] !== null ? route('novels.show', $item['novel_id']) : null;
            unset($item['novel_id']);
            return $item;
        }, $result['items']);

        $hidden = 0;
        if ($data['type'] !== 'search') {
            $before = count($items);
            $items = array_values(array_filter($items, fn($item) => !$item['in_library']));
            $hidden = $before - count($items);
        }

        return response()->json([
            'success' => true,
            'items' => $items,
            'hidden' => $hidden,
            'has_more' => $result['has_more'],
            'page' => $page,
        ]);
    }

    /** See Discover::normalizeUrl(); kept for existing callers. */
    public static function normalizeUrl(string $url): string
    {
        return Discover::normalizeUrl($url);
    }

    /** See Discover::normalizeName(); kept for existing callers. */
    public static function normalizeName(string $name): string
    {
        return Discover::normalizeName($name);
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
