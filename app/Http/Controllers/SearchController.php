<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    /**
     * Navbar autocomplete: novels whose name matches, as lightweight JSON.
     */
    public function suggest(Request $request)
    {
        $q = trim($request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $novels = Novel::whereRaw("name LIKE ? ESCAPE '\\'", ['%' . self::escapeLike($q) . '%'])
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'author']);

        return response()->json($novels->map(fn($n) => [
            'id' => $n->id,
            'name' => $n->name,
            'author' => $n->author,
            'url' => route('novels.show', $n->id),
        ]));
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
                        ->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("author LIKE ? ESCAPE '\\'", [$like]))
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
                        ->whereRaw("label LIKE ? ESCAPE '\\'", ['%' . self::escapeLike($q) . '%'])
                        ->orWhereHas('text', fn($t) => $t->whereRaw("content LIKE ? ESCAPE '\\'", ['%' . self::escapeLike($q) . '%'])))
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

    /** Literal LIKE: "100%" must match the text "100%", not everything. */
    public static function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }
}
