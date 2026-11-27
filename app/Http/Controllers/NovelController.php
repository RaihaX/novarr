<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use App\Http\Helpers\CacheHelper;
use App\Http\Helpers\LibraryMedium;
use App\Console\Commands\GenerateePub;
use App\Services\DuplicateSeries;
use App\Services\ReadState;
use App\Services\ResumePoint;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NovelController extends Controller
{
    /** Chapter rows per page on the novel page. */
    public const CHAPTERS_PER_PAGE = 50;

    /**
     * The novel repository instance.
     */
    protected $novels;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     * @return void
     */
    // Stateless (the reader is resolved per call), so safe to hold here even
    // though Octane keeps controller instances across requests.
    protected ReadState $readState;

    public function __construct(Novel $novels, ReadState $readState)
    {
        $this->novels = $novels;
        $this->readState = $readState;
    }


    /**
     * A person in a browser following a link (not fetch(), an OPDS reader or
     * an API client): the browser says it is navigating (Sec-Fetch-Mode, sent
     * on HTTPS and localhost), or — plain http on the home network, where
     * browsers send no Sec-Fetch-* — it asks for HTML and came from a page
     * of this server.
     */
    private static function isBrowserNavigation(Request $request): bool
    {
        if ($request->expectsJson()) {
            return false;
        }
        $mode = $request->header('Sec-Fetch-Mode');
        if ($mode !== null) {
            return $mode === 'navigate';
        }
        $referer = (string) $request->header('Referer');

        return str_contains((string) $request->header('Accept'), 'text/html')
            && $referer !== ''
            && parse_url($referer, PHP_URL_HOST) === $request->getHost();
    }

    public function download_epub($id, Request $request) {
        $object = $this->novels->findOrFail($id);
        abort_if($object->isManga(), 404, 'A manga has no ePub: ePubs are made from chapter text.');

        // Same sanitised name the generator writes (reserved characters
        // stripped, "Unknown" for a missing author).
        $epub = GenerateePub::epubPath($object);

        if (!is_file($epub)) {
            $message = 'No ePub has been generated for this novel yet. Use “Generate ePub” first.';
            // A person clicking a link goes back with a message; OPDS readers
            // and API clients keep the plain 404.
            if (self::isBrowserNavigation($request)) {
                return redirect()->back(302, [], route('novels.show', $object->id))->with('error', $message);
            }
            abort(404, $message);
        }

        // Conditional download: the ETag changes whenever the generator
        // rewrites the file, so an unchanged ePub answers 304 with no body.
        clearstatcache(true, $epub);
        $etag = md5(filemtime($epub) . '-' . filesize($epub));

        // Named after the book, not the file: an uploaded book is kept as "{id}.epub".
        $response = response()->download($epub, GenerateePub::epubFilename($object))->setEtag($etag)->setPrivate();
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Return the list of downloaded chapters for a novel so the PWA can
     * pre-cache them for offline reading. Cover + novel page are included
     * so the offline library tile renders without a connection.
     *
     * Optional filters keep big series manageable:
     *   ?unread=1            only downloaded-but-unread chapters
     *   ?from=X&to=Y         chapter-number range (either bound optional)
     *   ?limit=N             cap to the first N (in reading order)
     */
    /**
     * Lightweight chapter list for the in-reader table of contents.
     */
    public function chaptersJson($id)
    {
        abort_unless(Novel::whereKey($id)->exists(), 404);

        // Also feeds the novel page's chapter filter (resources/js/novel.js),
        // which filters this one list in memory instead of asking the server
        // per keystroke; the conditional-GET middleware keeps repeats at 304.
        $chapters = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->ordered()
            ->get(['id', 'chapter', 'book', 'label', 'status', 'kind', 'download_date', 'attempts', 'last_failure_reason', 'page_count', 'unavailable_until']);
        $this->readState->load($chapters, null, (int) $id);

        $chapters = $chapters->map(fn($c) => [
                'id' => $c->id,
                'chapter' => $c->chapter,
                'book' => $c->book,
                'label' => $c->label,
                'downloaded' => (bool) $c->status,
                'read' => $c->isRead(),
                'note' => $c->isNote(),
                'state' => static::chapterState($c),
                'reason' => static::chapterState($c) === 'failed' ? static::failureReasonText($c->last_failure_reason) : null,
                'review' => !$c->status && !$c->isSetAside() && (int) $c->attempts >= NovelChapter::REVIEW_ATTEMPTS,
                // Set aside by the manga fallback (no source has it): when it is checked again.
                'unavailable' => $c->isSetAside() ? \App\Scraping\MangaFallback::dateText($c->unavailable_until) : null,
                'attempts' => $c->status ? 0 : (int) $c->attempts,
                'date' => $c->download_date?->format('Y-m-d H:i'),
                // Manga: pages of a downloaded chapter (null for text).
                'pages' => $c->page_count === null ? null : (int) $c->page_count,
            ]);

        return response()->json(['chapters' => $chapters]);
    }

    /**
     * Plain-words explanations of NovelChapter::last_failure_reason, as the
     * chapter scraper records it (ChapterScraper::recordFailure / its hints).
     */
    public const FAILURE_REASONS = [
        'fetch_failed' => 'The chapter page could not be fetched.',
        'cloudflare' => 'The source answered with a Cloudflare check instead of the page.',
        'rate_limited' => 'The source is rate-limiting requests; it will be retried later.',
        'no_content' => 'The page loaded but contains no chapter text.',
        'short_content' => 'The page had too little text to be the chapter.',
        'not_found' => 'The source answers 404 for this chapter; it may have been removed or renumbered there.',
        'exception' => 'The scraper hit an error while reading the page.',
        'source_missing' => 'The source no longer lists this chapter.',
        'partial_pages' => 'Some page images could not be downloaded (missing, not an image, or too large).',
    ];

    public static function failureReasonText(?string $reason): string
    {
        return self::FAILURE_REASONS[$reason] ?? 'The download failed.';
    }

    /**
     * A chapter row's download state for the novel page: 'downloaded',
     * 'failed' (not downloaded and the last fetch recorded a failure) or
     * 'queued' (waiting for its first fetch).
     */
    public static function chapterState(object $chapter): string
    {
        if (!empty($chapter->status)) {
            return 'downloaded';
        }

        return !empty($chapter->last_failure_reason) ? 'failed' : 'queued';
    }

    public function offlineManifest($id, Request $request)
    {
        $novel = $this->novels->with('file')->findOrFail($id);

        $query = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->where('status', 1)
            ->ordered();

        if ($request->boolean('unread')) {
            $this->readState->whereUnread($query);
        }
        if ($request->filled('from')) {
            $query->where('chapter', '>=', (float) $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('chapter', '<=', (float) $request->query('to'));
        }
        if ($request->filled('limit')) {
            $query->limit(max(1, (int) $request->query('limit')));
        }

        $chapters = $query->get(['id', 'chapter', 'book', 'label']);

        return response()->json([
            'id' => $novel->id,
            'name' => $novel->name,
            'author' => $novel->author,
            'cover' => $novel->coverUrl('md'),
            'url' => route('novels.show', $novel->id),
            'chapterCount' => $chapters->count(),
            'chapters' => $chapters->map(fn($c) => [
                'id' => $c->id,
                'chapter' => $c->chapter,
                'book' => $c->book,
                'label' => $c->label,
                'url' => route('chapters.show', $c->id),
            ])->values(),
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Candidate novels: every filter that is a plain condition on novels
        // (text, status, tag, chip id sets) stays in SQL, and the rows come
        // back in the database's name order (its collation decides case and
        // accents), with id as a final tie-break. The sort below is a stable
        // PHP sort on cached per-novel chapter aggregates, so ties keep this
        // name order, exactly like the old `ORDER BY <key>, name`.
        $query = Novel::query()->select(['id', 'name', 'no_of_chapters', 'series', 'series_index']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('tags.id', $request->tag));
        }

        // Library: Novels or Manga (?medium=, else the one remembered in the
        // session, novels by default). Everything on the page — list, header
        // summary, chips, shelves — belongs to that medium only.
        $medium = LibraryMedium::resolve($request);
        $query->where('medium', $medium);
        $isBooks = $medium === Novel::MEDIUM_BOOK;

        // Search: the title; in Books also the series' name, so "Mistborn"
        // finds the books of the series (shown one by one, see below). Also
        // the series' other names (alternative_titles: NovelUpdates'
        // associated names, kept for a book moved to Novels as a web novel).
        if ($request->filled('search')) {
            $like = '%' . $request->search . '%';
            $query->where(fn($q) => $q->where('name', 'like', $like)
                ->orWhere('alternative_titles', 'like', $like)
                ->when($isBooks, fn($q) => $q->orWhere('series', 'like', $like)));
        }

        // Series (?series=<name>, in the URL like ?tag=; Books only): its
        // books, listed in series order.
        $activeSeries = $isBooks && $request->filled('series') ? (string) $request->query('series') : null;
        if ($activeSeries !== null) {
            $query->where('series', $activeSeries);
        }

        // --- Reading index -------------------------------------------------
        // One grouped pass over the chapter table gives every novel's read /
        // new / TOC counts: it feeds the chip counts, the chip filters and the
        // grid tiles' sublines and reading edges. "New" = downloaded, unread
        // chapters that arrived after you last read the novel (so a backlog
        // you haven't reached yet doesn't count as new).
        // Whole-table aggregates: cached briefly so a big library doesn't pay
        // for them on every page/filter change; a download drops them for
        // every reader, a read only the reader's own (see CacheHelper).
        // Both caches are library-wide; one statement (this medium's ids, with
        // the origin the chips need) scopes them to the library shown.
        $members = Novel::where('medium', $medium)->get(['id', 'origin', 'origin_language', 'origin_source', 'origin_confidence', 'medium']);
        $memberIds = array_flip($members->pluck('id')->map(fn($id) => (int) $id)->all());

        // Origin filter (?origin=jp|kr|cn|en|unknown, in the URL like ?tag=):
        // Novel::scopeOriginCountry(), the SQL twin of originCountry(), which
        // gives the dropdown's counts for this medium from the same rows.
        $originCounts = array_fill_keys([...array_map('strtolower', Novel::ORIGIN_COUNTRIES), 'unknown'], 0);
        foreach ($members as $member) {
            $originCounts[strtolower($member->originCountry() ?? 'unknown')]++;
        }
        $activeOrigin = strtolower((string) $request->query('origin', ''));
        if (array_key_exists($activeOrigin, $originCounts)) {
            $query->originCountry($activeOrigin === 'unknown' ? null : strtoupper($activeOrigin));
        } else {
            $activeOrigin = null;
        }
        $reading = array_intersect_key(static::libraryReading()['reading'], $memberIds);
        $aggregates = static::libraryAggregates();
        $totals = static::libraryTotals(array_intersect_key($aggregates, $memberIds));

        // Download health is the admin's business: readers get no attention chip or badges.
        $attentionIds = \Illuminate\Support\Facades\Gate::allows('admin') ? \App\Services\NovelHealth::attentionIds() : [];

        $sets = [
            'reading' => array_keys(array_filter($reading, fn($r) => $r['read'] > 0 && !$r['finished'])),
            'new' => array_keys(array_filter($reading, fn($r) => $r['new'] > 0)),
            'attention' => array_values(array_intersect(array_keys($attentionIds), array_keys($reading))),
            'finished' => array_keys(array_filter($reading, fn($r) => $r['finished'])),
        ];
        // Origin chips (translated web novels vs original English ones) only
        // apply to text novels: the Manga library has none.
        $originChips = $medium === Novel::MEDIUM_NOVEL ? ['translated', 'original'] : [];
        foreach ($originChips as $origin) {
            $sets[$origin] = array_values(array_intersect(
                $members->where('origin', $origin)->pluck('id')->map(fn($id) => (int) $id)->all(),
                array_keys($reading)
            ));
        }
        // Books with an audiobook (App\Audiobooks): the Books library's Audiobooks chip.
        if ($isBooks) {
            $sets['audiobooks'] = array_values(array_intersect(array_keys(\App\Audiobooks\AudiobookLibrary::novelMap()), array_keys($memberIds)));
        }
        $chipCounts = array_map('count', $sets) + ['all' => count($reading)];

        // Chip filter. "Offline" lives in this browser's IndexedDB, so the
        // page script resolves it and comes back with ?filter=offline&ids=….
        $filter = $request->query('filter');
        if (!in_array($filter, ['reading', 'new', 'attention', 'offline', 'finished', ...$originChips, ...($isBooks ? ['audiobooks'] : [])], true)) {
            $filter = 'all';
        }
        $offlinePending = false;
        if ($filter === 'offline') {
            $offlinePending = !$request->has('ids');
            $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
            $query->whereIn('id', $ids ?: [0]);
        } elseif ($filter !== 'all') {
            $query->whereIn('id', $sets[$filter] ?: [0]);
        }

        // Reading list (?list=<id>, in the URL like ?tag=): the reader's own
        // lists only — another reader's or a deleted list's id is ignored.
        // Combines with the chips, search and tag like any other filter.
        $readerId = app(\App\Support\CurrentReader::class)->id();
        $readingLists = \App\Services\ReadingLists::forLibrary($readerId, $medium);
        $activeList = null;
        if ($listModel = \App\Services\ReadingLists::findOrNull($readerId, $request->query('list'))) {
            $activeList = collect($readingLists)->firstWhere('id', (int) $listModel->id);
            $query->whereIn('id', \App\Services\ReadingLists::novelIds($listModel) ?: [0]);
        }

        // Sort: explicit ?sort= wins, otherwise the last choice in session.
        $sort = $request->query('sort');
        if (!in_array($sort, ['read', 'name', 'progress', 'updated', 'chapters'], true)) {
            $sort = session('novels_sort', 'read');
        }
        session(['novels_sort' => $sort]);

        $candidates = $query->orderBy('name')->orderBy('id')->get();
        $orderedIds = $activeSeries !== null
            ? $candidates->sort([\App\Books\SeriesCard::class, 'compare'])->map(fn(Novel $n) => (int) $n->id)->values()->all()
            : static::sortLibraryIds($candidates, $aggregates, $sort);

        // Grid/table toggle: explicit ?view= wins (the old ?view=list means
        // table), otherwise the last choice remembered in the session; the
        // cover grid is the default.
        $view = $request->query('view');
        $view = $view === 'list' ? 'table' : $view;
        if (!in_array($view, ['grid', 'table'], true)) {
            $view = session('library_view', 'grid');
        }
        session(['library_view' => $view]);

        // Shelves (Reading / Not started / Finished) only head the grid in its
        // default state: last-read sort, no chip, search, tag or status
        // filter. They group the current page's tiles (pagination and order
        // are unchanged); any explicit sort or filter shows one flat list.
        $shelved = $view === 'grid' && $sort === 'read' && $filter === 'all'
            && !$request->filled('search') && !$request->filled('tag') && !$request->filled('status')
            && $activeList === null && $activeOrigin === null && $activeSeries === null;

        // Books grid: a series is one card (seriesCards()), counted as one
        // by the pagination. A search lists the matching books themselves
        // (a hit is never hidden inside a card), and the table stays flat.
        $grouped = $isBooks && $view === 'grid' && $activeSeries === null && !$request->filled('search');
        $perPage = $view === 'grid' ? 42 : 25;

        return view('novels.index', [
            'shelved' => $shelved,
            'novels' => $grouped
                ? static::paginateLibrary(static::seriesCards($orderedIds, $candidates, $sort), $aggregates, $perPage)
                : static::paginateLibrary($orderedIds, $aggregates, $perPage),
            'activeSeries' => $activeSeries,
            'activeSeriesCount' => $activeSeries !== null ? $candidates->count() : 0,
            'view' => $view,
            'sort' => $sort,
            'filter' => $filter,
            'medium' => $medium,
            'originChips' => $originChips,
            'offlinePending' => $offlinePending,
            'chipCounts' => $chipCounts,
            'totals' => $totals,
            'reading' => $reading,
            'attentionIds' => $attentionIds,
            'tags' => \App\Tag::orderBy('name')->get(['id', 'name']),
            'activeTag' => $request->query('tag'),
            'originCounts' => $originCounts,
            'activeOrigin' => $activeOrigin,
            // The reader's lists ({id, name, count in this library, other}) and the chosen one.
            'readingLists' => $readingLists,
            'activeList' => $activeList,
        ]);
    }

    /**
     * The Library's reading index for a reader (default: the current one):
     * per novel read / new / total / percent / finished, plus library-wide
     * downloaded and queued totals.
     *
     * @return array{reading: array<int, array{read:int, new:int, total:int, percent:int, finished:bool}>, totals: array{downloaded:int, queued:int}}
     */
    public static function libraryReading(?int $userId = null): array
    {
        $index = static::readerLibrary(app(ReadState::class)->userId($userId));

        return ['reading' => $index['reading'], 'totals' => $index['totals']];
    }

    /**
     * One reader's Library index — libraryReading() plus each novel's last
     * read (for libraryAggregates()) — built from the global chapter counts
     * (chapterAggregates()) and the reader's read state (ReadState::
     * libraryState(), two grouped statements). One cache entry per reader,
     * kept briefly: a download makes every reader's stale, a read only the
     * reader's (CacheHelper).
     *
     * @return array{reading: array, totals: array{downloaded:int, queued:int}, last_read: array<int, ?string>}
     */
    private static function readerLibrary(int $userId): array
    {
        return CacheHelper::rememberForReader(CacheHelper::libraryReadingKey($userId), CacheHelper::epoch(), 60, function () use ($userId) {
            $aggregates = static::chapterAggregates();
            $state = app(ReadState::class)->libraryState($userId);

            $reading = [];
            foreach (Novel::get(['id', 'no_of_chapters']) as $n) {
                $a = $aggregates[$n->id] ?? null;
                $s = $state[$n->id] ?? null;
                $read = (int) ($s['read'] ?? 0);
                // Same denominator as the download progress everywhere else.
                $total = \App\Services\NovelHealth::downloadProgress(
                    (int) ($a['downloaded'] ?? 0),
                    (int) ($a['source'] ?? 0),
                    (int) ($n->no_of_chapters ?? 0)
                )['total'];
                $total = max($total, $read);

                $reading[$n->id] = [
                    'read' => $read,
                    'new' => (int) ($s['new'] ?? 0),
                    'total' => $total,
                    'percent' => $total > 0 ? (int) min(100, floor($read / $total * 100)) : 0,
                    'finished' => $total > 0 && $read >= $total,
                ];
            }

            return [
                'reading' => $reading,
                'totals' => static::libraryTotals($aggregates),
                'last_read' => array_map(fn($s) => $s['last_read'], $state),
            ];
        });
    }

    /**
     * Downloaded and queued chapter totals over some novels' aggregates
     * (libraryAggregates(), already narrowed to one library): the same
     * figures as libraryReading()'s library-wide totals — non-blacklisted
     * chapters, downloaded or still waiting.
     *
     * @param  array<int, array{downloaded:int, source:int}>  $aggregates
     * @return array{downloaded:int, queued:int}
     */
    public static function libraryTotals(array $aggregates): array
    {
        $downloaded = 0;
        $queued = 0;
        foreach ($aggregates as $a) {
            $downloaded += $a['downloaded'];
            $queued += $a['source'] - $a['downloaded'];
        }

        return ['downloaded' => $downloaded, 'queued' => $queued];
    }

    /**
     * Per-novel chapter aggregates for the Library: the global chapter
     * counts (chapterAggregates()) with a reader's (default: the current
     * one) last read of each novel.
     *
     * @return array<int, array{chapters:int, downloaded:int, source:int, last_read:?string, last_download:?string}>
     */
    public static function libraryAggregates(?int $userId = null): array
    {
        $lastRead = static::readerLibrary(app(ReadState::class)->userId($userId))['last_read'];

        $out = [];
        foreach (static::chapterAggregates() as $id => $a) {
            $out[$id] = [
                'chapters' => $a['chapters'],
                'downloaded' => $a['downloaded'],
                'source' => $a['source'],
                'last_read' => $lastRead[$id] ?? null,
                'last_download' => $a['last_download'],
            ];
        }

        return $out;
    }

    /**
     * Per-novel chapter counts, the same for every reader, from one grouped
     * pass over novel_chapters (soft-deleted rows excluded, as the old
     * correlated subqueries did), answered from idx_library_aggregates.
     * Cached briefly; a download drops it (CacheHelper::clearNovelCache()).
     *
     * @return array<int, array{chapters:int, downloaded:int, source:int, last_download:?string}>
     */
    private static function chapterAggregates(): array
    {
        return Cache::remember(CacheHelper::LIBRARY_AGGREGATES_KEY, 60, function () {
            $rows = DB::table('novel_chapters')
                ->whereNull('deleted_at')
                ->groupBy('novel_id')
                ->selectRaw('novel_id,
                    COUNT(*) as chapters,
                    SUM(CASE WHEN status = 1 AND blacklist = 0 THEN 1 ELSE 0 END) as downloaded,
                    SUM(CASE WHEN blacklist = 0 THEN 1 ELSE 0 END) as source,
                    MAX(download_date) as last_download')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r->novel_id] = [
                    'chapters' => (int) $r->chapters,
                    'downloaded' => (int) $r->downloaded,
                    'source' => (int) $r->source,
                    'last_download' => $r->last_download !== null ? (string) $r->last_download : null,
                ];
            }

            return $out;
        });
    }

    /**
     * Order the candidate novels (already in SQL `name, id` order) by $sort.
     * usort is stable, so equal keys keep the name order — the old
     * `ORDER BY <key> DESC, name`. NULL keys sort last, as NULLs do in a DESC
     * ORDER BY on both MariaDB and SQLite.
     *
     * @param  \Illuminate\Support\Collection<int, Novel>  $candidates
     * @return int[]
     */
    public static function sortLibraryIds($candidates, array $aggregates, string $sort): array
    {
        $agg = fn(int $id) => $aggregates[$id] ?? ['chapters' => 0, 'downloaded' => 0, 'source' => 0, 'last_read' => null, 'last_download' => null];
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        $keyOf = match ($sort) {
            'name' => null,
            'chapters' => fn(Novel $n) => $agg($n->id)['chapters'],
            'updated' => fn(Novel $n) => $agg($n->id)['last_download'],
            'progress' => function (Novel $n) use ($agg, $mysql) {
                // Was: downloaded * 1.0 / NULLIF(CASE WHEN COALESCE(no_of_chapters, 0)
                // > source THEN no_of_chapters ELSE source END, 0)
                $a = $agg($n->id);
                $advertised = (int) ($n->no_of_chapters ?? 0);
                $total = $advertised > $a['source'] ? $advertised : $a['source'];
                if ($total <= 0) {
                    return null;
                }
                // MariaDB divides DECIMALs at scale 1 (from "* 1.0") +
                // div_precision_increment (default 4) = 5 places, rounded half
                // up, so ratios that agree to 5 places tie there and fall back
                // to name. SQLite divides as a double.
                return $mysql
                    ? intdiv(2 * $a['downloaded'] * 100000 + $total, 2 * $total)
                    : $a['downloaded'] / $total;
            },
            default => fn(Novel $n) => $agg($n->id)['last_read'],
        };

        $items = $candidates->all();
        if ($keyOf !== null) {
            $keys = [];
            foreach ($items as $n) {
                $keys[$n->id] = $keyOf($n);
            }
            usort($items, function (Novel $x, Novel $y) use ($keys) {
                $a = $keys[$x->id];
                $b = $keys[$y->id];
                if ($a === null || $b === null) {
                    return ($a === null) <=> ($b === null); // NULLs last
                }

                return is_string($a) ? strcmp($b, $a) : $b <=> $a; // DESC
            });
        }

        return array_map(fn(Novel $n) => (int) $n->id, $items);
    }

    /**
     * The Books grid's cards from the ordered book ids: a series with two or
     * more of the listed books becomes one card ['series' => name, 'ids' =>
     * its books in series order, the first being the cover], placed where
     * its first book came in the order (its most recently read book for
     * "last read", and so on); every other book stays an id. Sorted by name,
     * a card goes by the series' name. PHP over rows already loaded: no
     * query per card.
     *
     * @param  int[]  $orderedIds
     * @param  \Illuminate\Support\Collection<int, Novel>  $candidates  with series and series_index
     * @return array<int, int|array{series: string, ids: int[]}>
     */
    public static function seriesCards(array $orderedIds, $candidates, string $sort): array
    {
        $byId = $candidates->keyBy('id');
        $members = [];
        foreach ($orderedIds as $id) {
            $series = (string) ($byId[$id]->series ?? '');
            if ($series !== '') {
                $members[$series][] = $byId[$id];
            }
        }
        $members = array_filter($members, fn($books) => count($books) >= 2);

        $cards = [];
        $placed = [];
        foreach ($orderedIds as $id) {
            $series = (string) ($byId[$id]->series ?? '');
            if (!isset($members[$series])) {
                $cards[] = $id;
                continue;
            }
            if (isset($placed[$series])) {
                continue;
            }
            $placed[$series] = true;
            $books = $members[$series];
            usort($books, [\App\Books\SeriesCard::class, 'compare']);
            $cards[] = ['series' => $series, 'ids' => array_map(fn(Novel $n) => (int) $n->id, $books)];
        }

        if ($sort === 'name') {
            $name = fn($card) => mb_strtolower(is_array($card) ? $card['series'] : (string) $byId[$card]->name);
            usort($cards, fn($a, $b) => strnatcmp($name($a), $name($b)));
        }

        return $cards;
    }

    /**
     * Paginate the ordered id list and load only that page's novels, with the
     * columns the list renders and the counts the view reads.
     */
    public static function paginateLibrary(array $orderedIds, array $aggregates, int $perPage): \Illuminate\Pagination\LengthAwarePaginator
    {
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage('page');
        // An entry is a novel id or a series card (seriesCards()): one load
        // for the page's novels and the cards' covers.
        $pageIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);
        $loadIds = array_map(fn($entry) => is_array($entry) ? $entry['ids'][0] : $entry, $pageIds);

        $novels = $loadIds === [] ? collect() : Novel::with('file')
            ->select(['id', 'name', 'author', 'status', 'paused_at', 'group_id', 'language_id', 'no_of_chapters', 'scrape_failures', 'toc_failures', 'origin', 'origin_language', 'origin_source', 'origin_confidence', 'medium', 'series', 'series_index'])
            ->whereIn('id', $loadIds)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($pageIds as $entry) {
            $id = is_array($entry) ? $entry['ids'][0] : $entry;
            $novel = $novels->get($id);
            if ($novel === null) {
                continue; // deleted between the two queries
            }
            $a = $aggregates[$id] ?? null;
            $novel->setAttribute('chapters_count', $a['chapters'] ?? 0);
            $novel->setAttribute('downloaded_chapters_count', $a['downloaded'] ?? 0);
            $novel->setAttribute('source_chapters_count', $a['source'] ?? 0);
            $items[] = is_array($entry) ? new \App\Books\SeriesCard($entry['series'], $entry['ids'], $novel) : $novel;
        }

        return new \Illuminate\Pagination\LengthAwarePaginator($items, count($orderedIds), $perPage, $page, [
            'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Manual add, opened from one library (?medium=, else the remembered
     * one). The created series' medium still comes from its URL's source.
     */
    public function create(Request $request)
    {
        $medium = LibraryMedium::resolve($request);
        // A book is added from its ePub, not by hand.
        if ($medium === Novel::MEDIUM_BOOK) {
            return redirect()->route('books.upload');
        }

        return view('novels.create', ['medium' => $medium]);
    }

    public function edit($id)
    {
        $novel = $this->novels->with('tags')->findOrFail($id);
        LibraryMedium::share($novel->medium);

        return view('novels.edit', [
            'novel' => $novel,
            'groups' => \App\Group::orderBy('label')->get(['id', 'label']),
        ]);
    }

    /**
     * Jump to a chapter by its number — opens the reader for the matching
     * chapter, so big novels don't require paging through the table.
     */
    public function jumpChapter(Request $request, $id)
    {
        $number = (float) $request->query('n');

        // The structured number first ("12" finds chapter 12 even when its
        // legacy value carries a part fraction), then the legacy value.
        $chapter = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->where(fn($q) => $q->where('number', $number)->orWhere('chapter', $number))
            ->orderByDesc('status')
            ->ordered()
            ->first(['id']);

        if (!$chapter) {
            return redirect()->route('novels.show', $id)
                ->with('status', "No chapter {$request->query('n')} found.");
        }

        return redirect()->route('chapters.show', $chapter->id);
    }

    /**
     * Undo: a book that books:webnovel-check moved to Novels goes back to
     * Books exactly as it was (App\Books\WebNovelCheck::undo()), and is never
     * moved again. Admin (RouteAccess).
     */
    public function undoConversion($id, \App\Books\WebNovelCheck $check)
    {
        $novel = $this->novels->findOrFail($id);
        try {
            $check->undo($novel);
        } catch (\RuntimeException $e) {
            return redirect()->route('novels.show', $novel->id)->with('error', $e->getMessage());
        }

        return redirect()->route('novels.show', $novel->id)
            ->with('status', "Back in Books as \"{$novel->name}\". It will not be moved to Novels again.");
    }

    /**
     * Remove duplicate chapters (same novel + chapter + book), keeping the
     * best copy: prefer a downloaded one, then the earliest.
     */
    public function removeDuplicates($id)
    {
        $groups = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->select('chapter', 'book')
            ->groupBy('chapter', 'book')
            ->havingRaw('count(id) > 1')
            ->get();

        if ($groups->isEmpty()) {
            return response()->json(['success' => true, 'removed' => 0]);
        }

        // Rows are about to change outside a TOC run: force the next run to
        // do a full sync rather than trust the unchanged-TOC fingerprint.
        Novel::whereKey($id)->update(['toc_fingerprint' => null]);

        // Pull every row for the affected chapter numbers in a single query, then
        // partition by exact (chapter, book) in PHP — instead of one query per
        // duplicate group.
        $candidates = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->whereIn('chapter', $groups->pluck('chapter')->unique()->all())
            ->orderByDesc('status')
            ->orderBy('id')
            ->get(['id', 'chapter', 'book', 'status']);

        // Keep the best copy of each group (downloaded first, then earliest id);
        // collect the rest for a single bulk soft-delete.
        $loserIds = $candidates
            ->groupBy(fn($c) => $c->chapter . '|' . $c->book)
            ->filter(fn($rows) => $rows->count() > 1)
            ->flatMap(fn($rows) => $rows->slice(1)->pluck('id'))
            ->all();

        if ($loserIds) {
            NovelChapter::whereIn('id', $loserIds)->delete();
        }

        CacheHelper::clearNovelCache($id);

        return response()->json(['success' => true, 'removed' => count($loserIds)]);
    }

    /**
     * Replace a novel's tags from a comma-separated list, creating any new
     * tag names on the fly.
     */
    public function syncTags(Request $request, $id)
    {
        $novel = $this->novels->findOrFail($id);

        $data = $request->validate([
            'tags' => 'array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        $novel->tags()->sync($data['tags'] ?? []);

        return response()->json([
            'success' => true,
            // id + name so the page can rebuild the tag links in place.
            'tags' => $novel->tags()->orderBy('name')->get(['tags.id', 'name']),
        ]);
    }

    /**
     * Create a tag from the picker's "add new" box, returning its id/name.
     */
    public function storeTag(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:50']);
        $tag = \App\Tag::firstOrCreate(['name' => trim($data['name'])]);

        return response()->json(['success' => true, 'id' => $tag->id, 'name' => $tag->name]);
    }

    /**
     * Bulk actions from the novels list: delete (novel + chapters,
     * soft-deleted) or mark as complete.
     */
    public function bulk(Request $request)
    {
        // One route, three actions: "mark read" is the reader's own state;
        // delete / complete change the library (admin; App\Support\RouteAccess).
        if ($request->input('action') !== 'read') {
            \Illuminate\Support\Facades\Gate::authorize('admin');
        }

        $data = $request->validate([
            'action' => 'required|in:delete,complete,read',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:novels,id',
        ]);

        if ($data['action'] === 'delete') {
            // Soft-delete chapters + novels in two batched queries rather than a
            // find()/delete() pair per id.
            NovelChapter::whereIn('novel_id', $data['ids'])->delete();
            Novel::whereIn('id', $data['ids'])->delete();
        } elseif ($data['action'] === 'read') {
            // Every chapter of the selected novels becomes read for this
            // reader. Chapters already read keep their original timestamp, so
            // reading stats for earlier days are not rewritten. Only the
            // reader's caches change (markMany() forgets them).
            $marked = $this->readState->markMany(
                NovelChapter::whereIn('novel_id', $data['ids'])->where('blacklist', 0),
                true,
                now(),
                onlyUnread: true,
                client: \App\Support\ReadingClient::from($request)
            );
        } else {
            Novel::whereIn('id', $data['ids'])
                ->where('status', 0)
                ->update(['status' => 1, 'completed_at' => now()]);
        }

        if ($data['action'] !== 'read') {
            foreach ($data['ids'] as $id) {
                CacheHelper::clearNovelCache($id);
            }
        }

        Cache::forget('dashboard_stats');
        Cache::forget('dashboard_attention');

        return response()->json(array_filter([
            'success' => true,
            'count' => count($data['ids']),
            'chapters' => $marked ?? null,
        ], fn($v) => $v !== null));
    }

    /**
     * Pause/resume automatic scraping for a novel ("ignore" on the
     * dashboard). Paused novels are skipped by the scheduled sweeps and by
     * needs-attention alerts; explicit per-novel commands still run.
     */
    public function togglePause($id)
    {
        $novel = $this->novels->findOrFail($id);
        $novel->paused_at = $novel->paused_at ? null : now();
        $novel->save();

        Cache::forget('dashboard_attention');
        CacheHelper::clearNovelCache($id);

        return response()->json([
            'success' => true,
            'paused' => (bool) $novel->paused_at,
        ]);
    }

    /**
     * Toggle hourly TOC checks for an actively-updating novel (see the
     * frequent_toc_check schedule).
     */
    public function toggleFrequent($id)
    {
        $novel = $this->novels->findOrFail($id);
        $novel->frequent_toc = !$novel->frequent_toc;
        $novel->save();

        return response()->json([
            'success' => true,
            'frequent' => (bool) $novel->frequent_toc,
        ]);
    }

    /**
     * Toggle a novel's completed status (mark complete / reopen).
     */
    public function toggleComplete($id)
    {
        $novel = $this->novels->findOrFail($id);
        $novel->status = $novel->status ? 0 : 1;
        $novel->completed_at = $novel->status ? now() : null;
        $novel->save();

        CacheHelper::clearNovelCache($id);
        Cache::forget('dashboard_stats');

        return response()->json([
            'success' => true,
            'completed' => (bool) $novel->status,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'translator' => 'nullable|string|max:255',
            'translator_url' => 'nullable|url|max:2048',
            'novelupdates_url' => 'nullable|url|max:2048',
            'chapter_url' => 'nullable|url|max:2048',
            'alternative_url' => 'nullable|url|max:2048',
            'no_of_chapters' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'group_id' => 'nullable|integer|exists:groups,id',
            'language_id' => 'nullable|integer|exists:languages,id',
            'unique_id' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
            'force' => 'nullable|boolean',
        ]);

        // Already in the library (DuplicateSeries)? Back to the form, which
        // names and links the existing series. A title match can be added
        // anyway (force — two works can share a title); the same source
        // page cannot.
        $medium = $request->filled('translator_url')
            ? \App\Sources\SourceResolver::forUrl((string) $request->translator_url)->medium()
            : Novel::MEDIUM_NOVEL;
        $existing = app(DuplicateSeries::class)->find((string) $request->name, $medium, [], $request->input('translator_url'));
        if ($existing !== null && ($existing['by'] === DuplicateSeries::BY_SOURCE || !$request->boolean('force'))) {
            $novel = $existing['novel'];

            return redirect()->route('novels.create', ['medium' => $medium])
                ->withInput($request->except(['image', 'force']))
                ->with('duplicate', DuplicateSeries::describe($novel) + [
                    'url' => route('novels.show', $novel->id),
                    'match' => $existing['by'],
                ]);
        }

        $object = $this->novels;

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('author') ) {
            $object->author = $request->author;
        }

        if ( $request->has('translator') ) {
            $object->translator = $request->translator;
        }

        // An uploaded book's identity (book://library/{sha1}) is its file: never replaced.
        if ( $request->has('translator_url') && !$object->isBook() ) {
            $object->translator_url = $request->translator_url;
        }

        if ( $request->has('novelupdates_url') ) {
            // A hand-edited NovelUpdates URL is a deliberate choice: treat it
            // as a confirmed match, as the edit page's "Use this" does. Left
            // unscored it would be auto-scored (often low) and completion
            // refused for good.
            $newUrl = $request->novelupdates_url ?: null;
            if ($newUrl !== $object->novelupdates_url) {
                $object->novelupdates_match_score = $newUrl === null ? null : 1.0;
            }
            $object->novelupdates_url = $request->novelupdates_url ?: null;
        }

        if ( $request->has('chapter_url') ) {
            $object->chapter_url = $request->chapter_url;
        }

        if ( $request->has('no_of_chapters') ) {
            $object->no_of_chapters = $request->no_of_chapters == "" ? 0 : $request->no_of_chapters;
        }

        if ( $request->has('status') ) {
            $object->status = $request->status;
        }

        if ( $request->has('group_id') ) {
            $object->group_id = $request->group_id;
        }

        if ( $request->has('json') ) {
            $object->json = $request->json;
        }

        $object->unique_id = $request->has('unique_id') ? $request->unique_id : 0;

        if ( $request->has('language_id') ) {
            $object->language_id = $request->language_id;
        }

        if ( $request->has('alternative_url') ) {
            $object->alternative_url = $request->alternative_url;
        }

        // Novel or manga: decided once, by the source that claims the URL.
        // A row whose text is a library ePub keeps its medium: a book, or a
        // book the web-novel check moved to Novels (App\Books\WebNovelCheck),
        // which the book:// URL alone would turn back into a book.
        if (!$object->hasLibraryFile()) {
            $object->medium = $object->translator_url
                ? \App\Sources\SourceResolver::forUrl((string) $object->translator_url)->medium()
                : Novel::MEDIUM_NOVEL;
        }

        $object->save();

        // Origin guess from the form's author / URLs (no network); not for a manga.
        if (!$object->isManga()) {
            $object->inferOrigin();
        }

        if ( $request->hasFile('image') ) {
            $storedPath = $request->file('image')->store('', ['disk' => 'public']);
            @chmod(storage_path('app/public/' . $storedPath), 0644);
            $object->attachCover($storedPath, $request->file('image')->getClientOriginalName());
        }

        $object->tags()->sync($request->input('tags', []));

        return redirect()->route('novels.show', $object->id);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $data = $this->novels->with(['file', 'group', 'language', 'tags'])->findOrFail($id);
        // The desktop nav highlights this series' library.
        LibraryMedium::share($data->medium);

        $stats = $this->novelStats($id);

        $progress = $this->calculateProgress($data, $stats);

        $chapters = NovelChapter::where('novel_id', $id)
            ->where('blacklist', 0)
            ->ordered()
            ->paginate(self::CHAPTERS_PER_PAGE, ['id', 'novel_id', 'chapter', 'book', 'label', 'status', 'download_date', 'kind', 'attempts', 'last_failure_reason', 'page_count', 'unavailable_until']);
        $this->readState->load($chapters->getCollection());

        // Manga: a chapter is only marked read at its last page, so the one
        // being read has progress and no read date; the resume point
        // (novelStats()) resumes it and the button names the saved page.
        $continuePage = null;
        if ($data->isManga() && ($stats['continue_kind'] ?? null) === ResumePoint::RESUME) {
            $continuePage = HomeController::mangaPage($stats['continue_progress'] ?? null, $stats['continue_page_count'] ?? null);
        }

        // A book's series: its books in series order (one small query on the
        // indexed column), for "Book 2 of …" and the previous / next book.
        $seriesBooks = collect();
        if ($data->isBook() && (string) $data->series !== '') {
            $seriesBooks = Novel::books()->where('series', $data->series)
                ->get(['id', 'name', 'series', 'series_index'])
                ->sort([\App\Books\SeriesCard::class, 'compare'])
                ->values();
        }

        return view('novels.show', [
            'data' => $data,
            'series_books' => $seriesBooks,
            'continue_page_number' => $continuePage,
            'synopsis' => $this->cleanSynopsis($data->description, $data->name),
            'chapters' => $chapters,
            'new_chapters' => $stats['new_chapters'],
            'duplicate_chapters' => $this->countOf($stats['duplicate_count']),
            'missing_chapters' => $this->countOf($stats['missing_count']),
            'current_chapters' => $stats['count'],
            'current_chapters_not_downloaded' => $stats['not_downloaded_count'],
            'read_count' => $stats['read_count'] ?? 0,
            'continue_chapter_id' => $stats['continue_chapter_id'] ?? null,
            'continue_number' => $stats['continue_number'] ?? null,
            // "Continue" rather than "Start reading": the reader has read or
            // started something here (ResumePoint found an anchor).
            'continue_started' => ($stats['continue_kind'] ?? null) !== null && $stats['continue_kind'] !== ResumePoint::START,
            // The list page holding the continue chapter, for "Jump to chapter N".
            'continue_page' => intdiv((int) ($stats['continue_index'] ?? 0), self::CHAPTERS_PER_PAGE) + 1,
            'failed_count' => $stats['failed_count'] ?? 0,
            'review_count' => $stats['review_count'] ?? 0,
            'progress' => round($progress),
            'epub_built_at' => $this->epubBuiltAt($data),
            'sendTo' => app(\App\Support\CurrentReader::class)->isGuest() ? null : \App\Services\SendEpub::address(app(\App\Support\CurrentReader::class)->user()),
            // The reader's reading lists and whether this series is in each (the Lists menu).
            'reading_lists' => \App\Services\ReadingLists::forNovel(app(\App\Support\CurrentReader::class)->id(), (int) $data->id),
            // On the reader's "Want to read" (the header's bookmark button).
            'want_to_read' => \App\Services\ReadingLists::isWanted(app(\App\Support\CurrentReader::class)->id(), (int) $data->id),
            // Its audiobook and where the reader is in it (the Listen panel), or null.
            'audiobook' => $this->audiobookPanel((int) $data->id),
        ]);
    }

    /**
     * The book page's Listen panel: its audiobook (App\Audiobook) and the
     * reader's progress row, or null when it has none. Read from the
     * cached novel map first, so a book without one costs no query.
     *
     * @return array{audiobook: \App\Audiobook, progress: ?object}|null
     */
    private function audiobookPanel(int $novelId): ?array
    {
        if (!isset(\App\Audiobooks\AudiobookLibrary::novelMap()[$novelId])) {
            return null;
        }
        $audiobook = \App\Audiobooks\AudiobookLibrary::forNovel($novelId);
        if ($audiobook === null) {
            return null;
        }

        return [
            'audiobook' => $audiobook,
            'progress' => app(\App\Audiobooks\ListeningProgress::class)->get(app(\App\Support\CurrentReader::class)->id(), (int) $audiobook->id),
        ];
    }

    /**
     * When the novel's ePub file was built, or null when there is no file to
     * download (manga never have one). epub_generated is set by every build;
     * a file without it falls back to the file's own time.
     */
    private function epubBuiltAt(Novel $novel): ?Carbon
    {
        if ($novel->isManga()) {
            return null;
        }
        $path = GenerateePub::epubPath($novel);
        if (!is_file($path)) {
            return null;
        }

        return $novel->epub_generated
            ? Carbon::parse($novel->epub_generated)
            : Carbon::createFromTimestamp(filemtime($path));
    }

    /**
     * Scraped descriptions sometimes contain only heading junk or just the
     * novel's own title. Strip the noise and return null when nothing real
     * remains, so the view can fall back to "No summary available".
     */
    public static function cleanSynopsis(?string $html, string $name): ?string
    {
        if (empty($html)) {
            return null;
        }

        $html = preg_replace('/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', $html);
        // Rendered raw ({!! !!}) — sanitise here too, since rows stored
        // before ingest-time sanitising may still carry scripts/attributes.
        $html = sanitizeSynopsisHtml($html);

        $text = trim(html_entity_decode(strip_tags($html)));

        if ($text === '' || mb_strlen($text) < 20 || mb_strtolower($text) === mb_strtolower(trim($name))) {
            return null;
        }

        return $html;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'translator' => 'nullable|string|max:255',
            'translator_url' => 'nullable|url|max:2048',
            'novelupdates_url' => 'nullable|url|max:2048',
            'chapter_url' => 'nullable|url|max:2048',
            'alternative_url' => 'nullable|url|max:2048',
            'no_of_chapters' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'group_id' => 'nullable|integer|exists:groups,id',
            'language_id' => 'nullable|integer|exists:languages,id',
            'unique_id' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
            'origin' => 'nullable|in:auto,ja,ko,zh,en,unknown,original,translated',
            'origin_language' => 'nullable|in:en,zh,ko,ja,vi,th,id,tl,es,other',
            'series' => 'nullable|string|max:255',
            'series_index' => 'nullable|numeric|min:0|max:999999',
        ]);

        $object = $this->novels->findOrFail($id);

        // A book's series, set by the owner (series_source manual): a weaker
        // source never changes it. Cleared, it stays "no series" ('' and
        // manual) so the inference doesn't add one back. Unchanged fields
        // leave Calibre's, the ePub's or a guess alone.
        if ($object->isBook() && $request->has('series')) {
            $series = trim((string) $request->input('series'));
            $index = $series !== '' ? \App\Books\BookSeries::index($request->input('series_index')) : null;
            $before = trim((string) $object->series);
            if ($series !== $before || ($series !== '' && $index !== \App\Books\BookSeries::index($object->series_index))) {
                $object->forceFill([
                    'series' => $series,
                    'series_index' => $index,
                    'series_source' => \App\Books\BookSeries::MANUAL,
                ]);
            }
        }

        if ( $request->has('name') ) {
            $object->name = $request->name;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('author') ) {
            $object->author = $request->author;
        }

        // A renamed novel or corrected author can match NovelUpdates where the
        // old one did not: let the next metadata run look it up again.
        if ($object->isDirty(['name', 'author'])) {
            $object->novelupdates_checked_at = null;
        }

        if ( $request->has('translator') ) {
            $object->translator = $request->translator;
        }

        // A book's identity (book://library/{sha1}) is its file: never
        // replaced, also once it was moved to Novels as a web novel.
        if ( $request->has('translator_url') && !$object->hasLibraryFile() ) {
            $object->translator_url = $request->translator_url;
        }

        if ( $request->has('novelupdates_url') ) {
            // A hand-edited NovelUpdates URL is a deliberate choice: treat it
            // as a confirmed match, as the edit page's "Use this" does. Left
            // unscored it would be auto-scored (often low) and completion
            // refused for good.
            $newUrl = $request->novelupdates_url ?: null;
            if ($newUrl !== $object->novelupdates_url) {
                $object->novelupdates_match_score = $newUrl === null ? null : 1.0;
            }
            $object->novelupdates_url = $request->novelupdates_url ?: null;
        }

        if ( $request->has('chapter_url') ) {
            $object->chapter_url = $request->chapter_url;
        }

        if ( $request->has('no_of_chapters') ) {
            // The column is NOT NULL: a cleared field means 0, as in store().
            $object->no_of_chapters = $request->no_of_chapters == "" ? 0 : $request->no_of_chapters;
        }

        if ( $request->has('status') ) {
            $object->status = $request->status;
        }

        if ( $request->has('group_id') ) {
            $object->group_id = $request->group_id;
        }

        if ( $request->has('json') ) {
            $object->json = $request->json;
        }

        if ( $request->has('unique_id') ) {
            $object->unique_id = $request->unique_id;
        }

        if ( $request->has('language_id') ) {
            $object->language_id = $request->language_id;
        }

        if ( $request->has('alternative_url') ) {
            $object->alternative_url = $request->alternative_url;
        }

        // Origin override: a value chosen here is the user's call and sticks
        // (origin_source = manual, confidence high — no detection overwrites
        // it); "Unknown" is a choice too. "Auto" drops a manual origin and
        // runs detection again (after the save below, with the new tags).
        $redetectOrigin = false;
        if ( $request->has('origin') ) {
            $raw = (string) ($request->input('origin') ?: 'auto');
            $choice = static::originChoice($raw, $request->input('origin_language'));
            $same = $choice !== null
                && $choice['origin'] === ($object->origin ?? 'unknown')
                && $choice['origin_language'] === ($choice['origin'] === 'unknown' ? null : $object->origin_language);
            // The older pair (translated / original + language) only takes
            // over when it differs from what is stored, as before.
            $legacy = in_array($raw, ['translated', 'original'], true);
            if ($choice === null) {
                $redetectOrigin = $object->origin_source === 'manual';
            } elseif (!$same || ($object->origin_source !== 'manual' && !$legacy)) {
                $object->forceFill([
                    'origin' => $choice['origin'],
                    'origin_language' => $choice['origin_language'],
                    'origin_source' => 'manual',
                    'origin_confidence' => \App\Scraping\OriginInference::HIGH,
                ]);
            }
        }

        $object->save();

        CacheHelper::clearNovelCache($id);

        if ( $request->hasFile('image') ) {
            $storedPath = $request->file('image')->store('', ['disk' => 'public']);
            @chmod(storage_path('app/public/' . $storedPath), 0644);
            $object->attachCover($storedPath, $request->file('image')->getClientOriginalName());
            // The owner's cover: books:covers never replaces it (App\Books\BookCovers).
            \Illuminate\Support\Facades\DB::table('novels')->where('id', $object->id)->update(['cover_source' => \App\Books\BookCovers::SOURCE_MANUAL]);
        }

        if ($request->has('tags')) {
            $object->tags()->sync($request->input('tags', []));
        }

        if ($redetectOrigin) {
            $object->unsetRelation('tags');
            $object->clearManualOrigin();
            CacheHelper::clearNovelCache($id);
        }

        // Two-way Calibre sync: a book's edited details go to Calibre too.
        \App\Jobs\PushBookToCalibre::for($object->fresh());

        return redirect()->route('novels.show', $id)->with('status', 'Novel updated.');
    }

    /**
     * The edit form's origin choice → origin attributes, or null for "auto".
     * auto | ja | ko | zh | en | unknown, plus the older values the form
     * also sends: original (English) and translated (with origin_language,
     * 'other' = not known).
     *
     * @return array{origin: string, origin_language: ?string}|null
     */
    public static function originChoice(string $choice, $language = null): ?array
    {
        $language = is_string($language) && preg_match('/^[a-z]{2}$/', $language) ? $language : null;

        return match ($choice) {
            'ja', 'ko', 'zh' => ['origin' => 'translated', 'origin_language' => $choice],
            'en', 'original' => ['origin' => 'original', 'origin_language' => $choice === 'original' ? ($language ?? 'en') : 'en'],
            'translated' => ['origin' => 'translated', 'origin_language' => $language === 'en' ? null : $language],
            'unknown' => ['origin' => 'unknown', 'origin_language' => null],
            default => null,
        };
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $object = $this->novels->findOrFail($id);

        // Soft-delete the chapters too — orphaned chapters would otherwise
        // keep counting toward pending/missing stats on the dashboard.
        NovelChapter::where('novel_id', $id)->delete();
        $object->delete();

        // Clear caches after deletion
        CacheHelper::clearNovelCache($id);
        Cache::forget('dashboard_stats');
        Cache::forget('dashboard_attention');

        return response()->json(['success' => true]);
    }

    /**
     * Per-novel stats for show() (counts, the reader's continue target,
     * duplicate-group and missing-chapter counts), per reader. Cached for 5
     * minutes; a change to the novel's chapters makes every reader's stale
     * (its novel epoch), a read only the reader's (CacheHelper).
     */
    protected function novelStats(int $id): array
    {
        $userId = $this->readState->userId();

        return CacheHelper::rememberForReader(CacheHelper::novelStatsKey($id, $userId), CacheHelper::novelEpoch($id), now()->addMinutes(5), function () use ($id, $userId) {
            // Consolidate the counts into a single aggregate query.
            $aggregates = NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->selectRaw('
                    COUNT(CASE WHEN status = 1 THEN 1 END) as downloaded_count,
                    COUNT(CASE WHEN status = 0 THEN 1 END) as not_downloaded_count,
                    COUNT(CASE WHEN status = 0 AND last_failure_reason IS NOT NULL THEN 1 END) as failed_count,
                    COUNT(CASE WHEN status = 0 AND attempts >= ' . (int) NovelChapter::REVIEW_ATTEMPTS . ' THEN 1 END) as review_count,
                    MAX(chapter) as latest_chapter
                ')
                ->first();

            $count = $aggregates->downloaded_count ?? 0;
            $not_downloaded_count = $aggregates->not_downloaded_count ?? 0;
            // Downloaded chapters the reader has read.
            $read_count = $this->readState->readCounts([$id], $userId)[$id]['read_downloaded'] ?? 0;
            $latestChapter = (int) ($aggregates->latest_chapter ?? 0);

            // "Continue" / "Start reading" target: the resume point every
            // device shares (App\Services\ResumePoint) — the unfinished
            // chapter read last, else the first unread chapter after it, the
            // first chapter when nothing is read; none when caught up. Two
            // statements: the reader's rows of this novel on chapter_reads
            // (user_id, novel_id), then a walk of idx_reading_order (skipped
            // when the chapter read last is unfinished).
            $point = app(ResumePoint::class)->forNovel($id, ['id', 'chapter', 'book', 'sort_key', 'number', 'part', 'page_count'], $userId);
            $continue = $point['chapter'] ?? null;
            $resumed = ($point['kind'] ?? null) === ResumePoint::RESUME;
            $continueChapterId = $continue?->id;

            // Its position in the chapter list (rows before it in reading
            // order), so the page can link straight to the list page holding it.
            $continueIndex = $continue ? NovelChapter::where('novel_id', $id)
                ->where('blacklist', 0)
                ->relativeTo($continue, '<')
                ->count() : 0;

            // Duplicate (chapter, book) groups — counted in SQL; the page only
            // shows how many there are.
            $duplicateCount = DB::query()->fromSub(
                NovelChapter::where('novel_id', $id)
                    ->where('blacklist', 0)
                    ->groupBy('chapter', 'book')
                    ->havingRaw('count(id) > 1')
                    ->selectRaw('1 as g'),
                'dupes'
            )->count();

            $missingCount = $this->missingChapterCount($id, $latestChapter);

            return [
                'count' => $count,
                'not_downloaded_count' => $not_downloaded_count,
                'read_count' => $read_count,
                'continue_chapter_id' => $continueChapterId,
                'continue_number' => $continue?->displayNumber() ?: null,
                'continue_index' => $continueIndex,
                'continue_kind' => $point['kind'] ?? null,
                // A resumed chapter's position and pages (a manga's "page 12").
                'continue_progress' => $resumed ? $continue->readProgress() : null,
                'continue_page_count' => $continue?->page_count,
                'failed_count' => (int) ($aggregates->failed_count ?? 0),
                'review_count' => (int) ($aggregates->review_count ?? 0),
                'new_chapters' => $not_downloaded_count,
                'duplicate_count' => $duplicateCount,
                'missing_count' => $missingCount,
            ];
        });
    }

    /**
     * Gaps in the 1..latest sequence: how many integers in that range no
     * chapter covers (a chapter covers intval(chapter); a double chapter also
     * covers the next number). Counted in SQL, so one misparsed number such
     * as 20231105 costs a query, not a 20-million-element array.
     */
    protected function missingChapterCount(int $id, int $latestChapter): int
    {
        // No chapters at all (or only chapter 0 / specials): nothing missing.
        if ($latestChapter < 1) {
            return 0;
        }

        // intval() semantics: truncate toward zero. MySQL's CAST(... AS
        // SIGNED) rounds, so it uses TRUNCATE(); SQLite's CAST truncates.
        $number = DB::connection()->getDriverName() === 'sqlite'
            ? 'CAST(chapter AS INTEGER)'
            : 'TRUNCATE(chapter, 0)';

        $base = fn() => NovelChapter::where('novel_id', $id)->where('blacklist', 0);

        $covered = DB::query()
            ->fromSub(
                $base()->selectRaw("{$number} as n")
                    ->unionAll($base()->where('double_chapter', 1)->selectRaw("{$number} + 1 as n")),
                'covered'
            )
            ->whereBetween('n', [1, $latestChapter])
            ->distinct()
            ->count('n');

        return max(0, $latestChapter - $covered);
    }

    /**
     * Something whose count() is $n, for views that only count a list the
     * controller no longer materialises.
     */
    protected function countOf(int $n): \Countable
    {
        return new class($n) implements \Countable {
            public function __construct(private int $n) {}

            public function count(): int
            {
                return $this->n;
            }
        };
    }

    /**
     * Download progress percentage — delegates to the single shared definition
     * (NovelHealth::downloadProgress: downloaded ÷ chapters known to the
     * source) so the novel page and the novels list never disagree.
     */
    protected function calculateProgress($novel, array $stats): int
    {
        return \App\Services\NovelHealth::downloadProgress(
            (int) $stats['count'],
            (int) $stats['count'] + (int) $stats['not_downloaded_count'],
            (int) ($novel->no_of_chapters ?? 0)
        )['percent'];
    }
}
