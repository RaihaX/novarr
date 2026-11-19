<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SearchController extends Controller
{

    /**
     * Command palette data (resources/js/palette.js): novels whose name or
     * author match every word of the query, plus — when the query reads as
     * "<novel words> <number>" (e.g. "ascending 142") — that chapter of each
     * matching novel. Commands and navigation are matched client-side.
     *
     * Shape: { novels: [{id, name, author, origin_label, url, progress}], chapters: [{id,
     * novel_id, novel, number, label, url, downloaded, read}] }, ≤ 8 each.
     */
    public function palette(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $empty = ['novels' => [], 'chapters' => []];

        if ($q === '' || mb_strlen($q) > 200) {
            return response()->json($empty);
        }

        $novels = $this->paletteNovels($q, 8);

        // "<novel words> <number>": the trailing number is a chapter number,
        // the words before it pick the novel(s).
        $chapters = collect();
        if (preg_match('/^(.+?)\s+(?:ch(?:apter)?\.?\s*)?#?(\d+(?:\.\d+)?)$/iu', $q, $m)) {
            $chapterNovels = $this->paletteNovels(trim($m[1]), 8);
            if ($chapterNovels->isNotEmpty()) {
                $names = $chapterNovels->pluck('name', 'id');
                $chapters = NovelChapter::whereIn('novel_id', $names->keys())
                    ->where('blacklist', 0)
                    ->where('chapter', $m[2])
                    ->orderBy('novel_id')
                    ->orderBy('book')
                    ->orderBy('id')
                    ->limit(8)
                    ->get(['id', 'novel_id', 'chapter', 'book', 'label', 'status']);
                app(\App\Services\ReadState::class)->load($chapters);
                $chapters = $chapters->map(fn($c) => [
                        'id' => $c->id,
                        'novel_id' => $c->novel_id,
                        'novel' => $names[$c->novel_id] ?? '',
                        'number' => $c->chapter + 0,
                        'label' => $c->label,
                        'url' => route('chapters.show', $c->id),
                        'downloaded' => (int) $c->status === 1,
                        'read' => $c->isRead(),
                    ])
                    ->values();
            }
        }

        // The reader's read chapters per novel (non-blacklisted).
        $reads = app(\App\Services\ReadState::class)->readCounts($novels->pluck('id')->all());

        return response()->json([
            'novels' => $novels->map(fn($n) => [
                'id' => $n->id,
                'name' => $n->name,
                'author' => $n->author,
                // "Translated · Korean" / "Original · English" / null.
                'origin_label' => $n->originLabel(),
                'url' => route('novels.show', $n->id),
                // Reading progress, 0–100 (read / non-blacklisted chapters).
                'progress' => $n->total_chapters_count > 0
                    ? (int) floor(($reads[$n->id]['read'] ?? 0) * 100 / $n->total_chapters_count)
                    : 0,
            ])->values(),
            'chapters' => $chapters,
        ]);
    }

    /**
     * Novels where every word (≤ 5) appears in the name or the author, with
     * names that start with the query ranked first.
     */
    private function paletteNovels(string $q, int $limit)
    {
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 5);
        if (!$words) {
            return collect();
        }

        $query = Novel::query();
        foreach ($words as $word) {
            $like = '%' . self::escapeLike($word) . '%';
            $query->where(fn($w) => $w
                ->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("author LIKE ? ESCAPE '!'", [$like]));
        }

        return $query
            ->withCount([
                'chapters as total_chapters_count' => fn($c) => $c->where('blacklist', 0),
            ])
            ->orderByRaw("CASE WHEN name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [self::escapeLike($q) . '%'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'author', 'origin', 'origin_language']);
    }

    /**
     * Unified search: library novels whose title/author match (the same thing
     * the navbar suggests), then downloaded chapters whose label or text match.
     */
    public function index(Request $request)
    {
        $q = trim($request->query('q', ''));
        $novelId = (int) $request->query('novel', 0);
        $novelFilter = $novelId ? Novel::find($novelId, ['id', 'name']) : null;
        $results = collect();
        $paginator = null;
        $novels = collect();

        if (mb_strlen($q) >= 2) {
            // Novels by title or author — skipped when already scoped to one
            // novel, and only on the first page of chapter results.
            if (!$novelId && (int) $request->query('page', 1) <= 1) {
                $like = '%' . self::escapeLike($q) . '%';
                $novels = Novel::where(fn($w) => $w
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("author LIKE ? ESCAPE '!'", [$like]))
                    ->withCount(['chapters as downloaded_chapters_count' => fn($c) => $c->where('status', 1)->where('blacklist', 0)])
                    ->orderBy('name')
                    ->orderBy('id')
                    ->limit(10)
                    ->get(['id', 'name', 'author', 'status', 'paused_at']);
            }

            $page = max(1, (int) $request->query('page', 1));

            // Ids for this page (plus one, to know whether a next page exists):
            // label hits first, then text hits, each from its own index. No
            // COUNT(*): an exact total over a common word cost 15 s on production.
            $ids = self::mergeHitIds(
                self::labelHitsQuery($q, $novelId, $page * self::PER_PAGE + 1)->pluck('novel_chapters.id')->all(),
                self::contentHitsQuery($q, $novelId, $page * self::PER_PAGE + 1)->pluck('chapter_texts.novel_chapter_id')->all(),
                $page,
                self::PER_PAGE
            );

            $paginator = (new Paginator($ids, self::PER_PAGE, $page, [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]))->withQueryString();
            $pageIds = $paginator->items();

            // Slim rows, no chapter bodies: the snippet is cut in SQL.
            $chapters = $pageIds === [] ? collect() : NovelChapter::with('novel:id,name')
                ->whereIn('id', $pageIds)
                ->get(['id', 'novel_id', 'chapter', 'book', 'label'])
                ->keyBy('id');
            $excerpts = $pageIds === [] ? collect() : self::snippetQuery($pageIds, self::needles($q))
                ->get()
                ->keyBy('novel_chapter_id');

            $results = collect($pageIds)
                ->map(fn($id) => $chapters->get($id))
                ->filter()
                ->map(function ($c) use ($q, $excerpts) {
                    $row = $excerpts->get($c->id);

                    return [
                        'novel' => $c->novel,
                        'chapter' => $c,
                        'snippet' => $row ? self::cleanSnippet((string) $row->excerpt, (int) $row->excerpt_start, $q) : '',
                    ];
                })
                ->filter(fn($r) => $r['novel'] !== null)
                ->values();
            $paginator->setCollection($results);
        }

        return view('search.index', [
            'q' => $q,
            'results' => $results,
            'grouped' => $results->groupBy(fn($r) => $r['novel']->name),
            'paginator' => $paginator,
            'novelFilter' => $novelFilter,
            'novels' => $novels,
        ]);
    }

    /** Chapter hits per page. */
    public const PER_PAGE = 40;

    /** Characters of raw chapter HTML fetched around the match for a snippet. */
    private const EXCERPT_BEFORE = 80;
    private const EXCERPT_LENGTH = 300;

    /** Whether the default (or named) connection searches with FULLTEXT. */
    private static function usesFullText(?string $connection = null): bool
    {
        return in_array(DB::connection($connection)->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Ids of readable chapters (downloaded, not blacklisted, not deleted,
     * optionally in one novel) whose label matches, at most $limit.
     * MySQL/MariaDB, whole library: MATCH(label) on ftx_chapter_label;
     * within one novel, and on other drivers, a literal LIKE in reading order.
     */
    public static function labelHitsQuery(string $q, int $novelId, int $limit, ?string $connection = null)
    {
        $fullText = self::usesFullText($connection) && !$novelId;

        return NovelChapter::on($connection)
            ->select('novel_chapters.id')
            ->where('novel_chapters.status', 1)
            ->where('novel_chapters.blacklist', 0)
            ->when($novelId, fn($w) => $w->where('novel_chapters.novel_id', $novelId))
            ->when($fullText,
                fn($w) => $w->whereFullText('novel_chapters.label', $q),
                fn($w) => $w->whereRaw("novel_chapters.label LIKE ? ESCAPE '!'", ['%' . self::escapeLike($q) . '%'])
                    ->orderBy('novel_chapters.novel_id')->orderBy('novel_chapters.book')
                    ->orderBy('novel_chapters.chapter')->orderBy('novel_chapters.id'))
            ->limit($limit);
    }

    /**
     * Ids of readable chapters whose text matches, at most $limit. Driven
     * from chapter_texts (MATCH(content) on ftx_chapter_text) and joined to
     * novel_chapters so the chapter filters, including the novel scope, are
     * applied before the LIMIT: one novel's hits are never cut off by
     * another's.
     */
    public static function contentHitsQuery(string $q, int $novelId, int $limit, ?string $connection = null)
    {
        // Within one novel the FULLTEXT index is the wrong driver: it walks
        // every match in the library looking for this novel's (17 s on
        // production for a small novel with few hits). A LIKE over just that
        // novel's chapters is bounded by the novel's size (about 0.1 s
        // typically, 2 s for a 7,600-chapter novel with no match).
        $fullText = self::usesFullText($connection) && !$novelId;

        return DB::connection($connection)->table('chapter_texts')
            ->join('novel_chapters', 'novel_chapters.id', '=', 'chapter_texts.novel_chapter_id')
            ->select('chapter_texts.novel_chapter_id')
            ->where('novel_chapters.status', 1)
            ->where('novel_chapters.blacklist', 0)
            ->whereNull('novel_chapters.deleted_at')
            ->when($novelId, fn($w) => $w->where('novel_chapters.novel_id', $novelId))
            ->when($fullText,
                fn($w) => $w->whereFullText('chapter_texts.content', $q),
                fn($w) => $w->whereRaw("chapter_texts.content LIKE ? ESCAPE '!'", ['%' . self::escapeLike($q) . '%'])
                    ->orderBy('novel_chapters.novel_id')->orderBy('novel_chapters.book')
                    ->orderBy('novel_chapters.chapter')->orderBy('novel_chapters.id'))
            ->limit($limit);
    }

    /**
     * The ids for one page plus one more when a next page exists: label
     * hits first, then text hits, duplicates dropped (first position wins).
     *
     * @param  int[]  $labelIds
     * @param  int[]  $contentIds
     * @return int[]
     */
    public static function mergeHitIds(array $labelIds, array $contentIds, int $page, int $perPage): array
    {
        $merged = array_values(array_unique(array_map('intval', array_merge($labelIds, $contentIds))));

        return array_slice($merged, (max(1, $page) - 1) * $perPage, $perPage + 1);
    }

    /**
     * What a snippet is centred on, in order of preference: the whole query,
     * then each of its words (≤ 5). FULLTEXT can match a word the literal
     * phrase never appears as, so the first word found wins.
     *
     * @return string[]
     */
    public static function needles(string $q): array
    {
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 5);

        return array_values(array_unique(array_filter([$q, ...$words], fn($w) => $w !== '')));
    }

    /**
     * A raw-HTML excerpt per chapter: EXCERPT_LENGTH characters starting
     * EXCERPT_BEFORE before the first needle found, or the start of the text
     * when none is (LOCATE/INSTR return 0, COALESCE falls through to 1).
     * Selects novel_chapter_id, excerpt and excerpt_start (1-based).
     *
     * @param  int[]  $ids
     * @param  string[]  $needles
     */
    public static function snippetQuery(array $ids, array $needles, ?string $connection = null)
    {
        $fullText = self::usesFullText($connection);
        // MySQL/MariaDB LOCATE follows the column's (case-insensitive)
        // collation; SQLite's INSTR is case-sensitive, so compare lowered.
        $locate = $fullText ? 'NULLIF(LOCATE(?, content), 0)' : 'NULLIF(INSTR(LOWER(content), LOWER(?)), 0)';
        $greatest = $fullText ? 'GREATEST' : 'MAX';
        $start = sprintf('%s(COALESCE(%s, 1) - %d, 1)',
            $greatest,
            implode(', ', array_fill(0, count($needles), $locate)),
            self::EXCERPT_BEFORE);
        $substr = $fullText ? 'SUBSTRING' : 'SUBSTR';

        return DB::connection($connection)->table('chapter_texts')
            ->select('novel_chapter_id')
            ->selectRaw("{$substr}(content, {$start}, " . self::EXCERPT_LENGTH . ') as excerpt', $needles)
            ->selectRaw("{$start} as excerpt_start", $needles)
            ->whereIn('novel_chapter_id', $ids);
    }

    /**
     * A short plain-text snippet from a raw-HTML excerpt that began at
     * character $start of the chapter: tag fragments cut by the excerpt
     * edges are dropped, tags stripped, whitespace collapsed, and the text
     * trimmed to about 200 characters around the first match. Plain text
     * only; the view escapes it.
     */
    public static function cleanSnippet(string $html, int $start, string $q): string
    {
        if ($start > 1) {
            // Started inside a tag ("lass=\"x\">text") or an entity ("quot;").
            $html = preg_replace(['/^[^<>]*>/', '/^[a-z0-9#]{1,10};/i'], '', $html, 1);
        }
        // Ends inside an entity ("&am"); an unclosed "<p cla" goes with strip_tags.
        $html = preg_replace('/&[a-z0-9#]{0,10}$/i', '', $html);

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
        if ($text === '') {
            return '';
        }

        $pos = false;
        foreach (self::needles($q) as $needle) {
            if (($pos = mb_stripos($text, $needle)) !== false) {
                break;
            }
        }
        $lead = $start > 1 ? '… ' : '';
        if ($pos === false) {
            return $lead . Str::limit($text, 160);
        }

        $from = max(0, $pos - 60);

        return ($lead !== '' || $from > 0 ? '… ' : '') . mb_substr($text, $from, 200) . ' …';
    }

    /**
     * Literal LIKE: "100%" must match the text "100%", not everything.
     * "!" is the escape character because MariaDB and SQLite disagree on how
     * a backslash must be written inside ESCAPE '…' (a backslash broke search
     * on production with a syntax error).
     */
    public static function escapeLike(string $q): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    }
}
