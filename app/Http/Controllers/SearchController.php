<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchController extends Controller
{

    /**
     * Command palette data (resources/js/palette.js): novels whose name or
     * author match every word of the query, plus — when the query reads as
     * "<novel words> <number>" (e.g. "ascending 142") — that chapter of each
     * matching novel. Commands and navigation are matched client-side.
     *
     * Shape: { novels: [{id, name, author, url, progress}], chapters: [{id,
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
                    ->get(['id', 'novel_id', 'chapter', 'book', 'label', 'status', 'read_at'])
                    ->map(fn($c) => [
                        'id' => $c->id,
                        'novel_id' => $c->novel_id,
                        'novel' => $names[$c->novel_id] ?? '',
                        'number' => $c->chapter + 0,
                        'label' => $c->label,
                        'url' => route('chapters.show', $c->id),
                        'downloaded' => (int) $c->status === 1,
                        'read' => $c->read_at !== null,
                    ])
                    ->values();
            }
        }

        return response()->json([
            'novels' => $novels->map(fn($n) => [
                'id' => $n->id,
                'name' => $n->name,
                'author' => $n->author,
                'url' => route('novels.show', $n->id),
                // Reading progress, 0–100 (read / non-blacklisted chapters).
                'progress' => $n->total_chapters_count > 0
                    ? (int) floor($n->read_chapters_count * 100 / $n->total_chapters_count)
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
                'chapters as read_chapters_count' => fn($c) => $c->where('blacklist', 0)->whereNotNull('read_at'),
            ])
            ->orderByRaw("CASE WHEN name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [self::escapeLike($q) . '%'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'author']);
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

            $isMysql = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql';

            $paginator = NovelChapter::with(['novel:id,name', 'text'])
                ->where('status', 1)
                ->where('blacklist', 0)
                ->when($novelId, fn($query) => $query->where('novel_id', $novelId))
                ->when($isMysql,
                    fn($query) => $query->where(fn($w) => $w
                        ->whereFullText('label', $q)
                        ->orWhereHas('text', fn($t) => $t->whereFullText('content', $q))),
                    fn($query) => $query->where(fn($w) => $w
                        ->whereRaw("label LIKE ? ESCAPE '!'", ['%' . self::escapeLike($q) . '%'])
                        ->orWhereHas('text', fn($t) => $t->whereRaw("content LIKE ? ESCAPE '!'", ['%' . self::escapeLike($q) . '%'])))
                )
                // Stable order so pagination never repeats or skips rows.
                ->orderBy('novel_id')
                ->orderBy('book')
                ->orderBy('chapter')
                ->orderBy('id')
                ->paginate(40, ['id', 'novel_id', 'chapter', 'book', 'label'])
                ->withQueryString();

            $results = collect($paginator->items())
                ->map(function ($c) use ($q) {
                    return [
                        'novel' => $c->novel,
                        'chapter' => $c,
                        'snippet' => $this->snippet($c->rawText(), $q),
                    ];
                })
                ->filter(fn($r) => $r['novel'] !== null);
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

    /**
     * A short plain-text excerpt around the first match of the query.
     */
    private function snippet(?string $html, string $q): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html ?? '')));
        if ($text === '') {
            return '';
        }

        $pos = stripos($text, $q);
        if ($pos === false) {
            return Str::limit($text, 160);
        }

        $start = max(0, $pos - 60);
        $excerpt = ($start > 0 ? '… ' : '') . mb_substr($text, $start, 200) . ' …';

        return $excerpt;
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
