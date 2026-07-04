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

        $novels = Novel::where('name', 'like', '%' . $q . '%')
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
     * Full-text search across downloaded chapter content + labels.
     */
    public function index(Request $request)
    {
        $q = trim($request->query('q', ''));
        $novelId = (int) $request->query('novel', 0);
        $novelFilter = $novelId ? Novel::find($novelId, ['id', 'name']) : null;
        $results = collect();
        $paginator = null;

        if (mb_strlen($q) >= 2) {
            $isMysql = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql';

            $paginator = NovelChapter::with('novel:id,name')
                ->where('status', 1)
                ->where('blacklist', 0)
                ->when($novelId, fn($query) => $query->where('novel_id', $novelId))
                ->when($isMysql,
                    fn($query) => $query->whereFullText(['label', 'description'], $q),
                    fn($query) => $query->where(fn($w) => $w
                        ->where('label', 'like', '%' . $q . '%')
                        ->orWhere('description', 'like', '%' . $q . '%'))
                )
                ->paginate(40, ['id', 'novel_id', 'chapter', 'book', 'label', 'description'])
                ->withQueryString();

            $results = collect($paginator->items())
                ->map(function ($c) use ($q) {
                    return [
                        'novel' => $c->novel,
                        'chapter' => $c,
                        'snippet' => $this->snippet($c->getRawOriginal('description'), $q),
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
}
