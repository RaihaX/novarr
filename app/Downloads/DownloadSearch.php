<?php

namespace App\Downloads;

use App\DownloadGrab;
use App\Novel;
use App\NovelRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One owner search (Prowlarr) and its results, kept on the server for an
 * hour: "Grab" names a result by the search's id and the result's guid, so
 * the download link handed to a client is always the one Prowlarr gave,
 * never one the browser sent.
 *
 * What the results are ranked against: the wanted book's title and author
 * (a request's, a book's), else the typed query.
 */
class DownloadSearch
{
    public const TTL = 3600;

    private const KEY = 'downloads.search.';

    public function __construct(private Prowlarr $prowlarr)
    {
    }

    /**
     * @return array{id: string, kind: string, query: string, request_id: ?int, novel_id: ?int, results: list<array>}
     *
     * @throws DownloadFailed
     */
    public function run(string $kind, string $query, ?NovelRequest $request = null, ?Novel $novel = null): array
    {
        $kind = Downloads::kind($kind);
        $query = trim($query) !== '' ? trim($query) : self::defaultQuery($request, $novel, $kind);
        SearchBudget::record();
        $results = $this->prowlarr->search($query, $kind, self::wanted($request, $novel));

        $blocklist = \App\DownloadBlock::query()->get(['guid', 'title', 'indexer'])->all();
        foreach ($results as &$result) {
            $result['grabbable'] = Downloads::clientFor($result['protocol']) !== null;
            $result['not_grabbable'] = Downloads::clientMissing($result['protocol']);
            $result['blocklisted'] = \App\DownloadBlock::blocks($result, $blocklist);
        }
        unset($result);

        $search = [
            'id' => (string) Str::uuid(),
            'kind' => $kind,
            'query' => $query,
            'request_id' => $request?->id,
            'novel_id' => $novel?->id,
            'results' => $results,
        ];
        Cache::put(self::KEY . $search['id'], $search, self::TTL);

        return $search;
    }

    /** A remembered search, or null (expired, unknown). */
    public static function find(string $id): ?array
    {
        if (!Str::isUuid($id)) {
            return null;
        }
        $search = Cache::get(self::KEY . $id);

        return is_array($search) ? $search : null;
    }

    /** "Title Author" (the first author's name): what the owner would type. */
    public static function defaultQuery(?NovelRequest $request, ?Novel $novel, ?string $kind = null): string
    {
        $wanted = self::wanted($request, $novel);
        // Comic releases are named for the series (and number), not the writer.
        if ($kind === DownloadGrab::KIND_COMIC || $request?->isComic() || $novel?->isComic()) {
            return trim((string) ($wanted['title'] ?? ''));
        }
        $author = trim(explode(',', (string) ($wanted['author'] ?? ''))[0]);

        return trim(($wanted['title'] ?? '') . ' ' . $author);
    }

    /**
     * Is the wanted book a graphic novel / comic (its request's catalogue
     * details, its title or description, its tags say so)? An ebook search
     * for one then asks Prowlarr's Books/Comics category too and takes comic
     * archives as the book, so a graphic novel is never missed.
     */
    public static function graphicNovel(?NovelRequest $request, ?Novel $novel): bool
    {
        $pattern = '/\b(graphic novels?|comics?|comic books?|manga|bande dessin[ée]e)\b/iu';
        if ($request !== null) {
            $first = (array) ($request->candidateList()[0] ?? []);
            $text = implode(' | ', array_map('strval', array_merge(
                [(string) $request->title, (string) $request->description, (string) ($first['source_name'] ?? '')],
                (array) ($first['genres'] ?? []),
                (array) ($first['categories'] ?? []),
            )));
            if (preg_match('/\bgraphic novels?\b/iu', $text) || preg_match($pattern, implode(' ', (array) ($first['genres'] ?? [])))) {
                return true;
            }
        }
        if ($novel !== null) {
            if (preg_match('/\bgraphic novels?\b/iu', (string) $novel->name . ' ' . (string) $novel->description)) {
                return true;
            }
            $tags = $novel->relationLoaded('tags') ? $novel->tags : $novel->tags()->get(['tags.name']);
            foreach ($tags as $tag) {
                if (preg_match($pattern, (string) $tag->name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What results are ranked against. A request's series_index and isbn
     * come from its first candidate: set when it was asked for from a pasted
     * book link (RequestSearch::askLink()), so ReleaseRanking can tell book
     * 1 from book 6 of the series; null otherwise.
     *
     * @return array{title: ?string, author: ?string, language: ?string, series_index?: mixed, isbn?: ?string}
     */
    public static function wanted(?NovelRequest $request, ?Novel $novel): array
    {
        if ($request !== null) {
            $first = (array) ($request->candidateList()[0] ?? []);
            if ($request->isComic()) {
                return [
                    'title' => (string) $request->title,
                    'author' => $request->author,
                    'language' => \App\Books\Epub::languageCode($first['language'] ?? null),
                    'year' => isset($first['year']) && is_numeric($first['year']) ? (int) $first['year'] : (isset($first['status']) && preg_match('/^(19|20)\d{2}$/', (string) $first['status']) ? (int) $first['status'] : null),
                ];
            }

            return [
                'title' => (string) $request->title,
                'author' => $request->author,
                'language' => \App\Books\Epub::languageCode($first['language'] ?? null),
                'series_index' => $first['series_index'] ?? null,
                'isbn' => $first['isbn'] ?? null,
                'graphic_novel' => self::graphicNovel($request, null),
            ];
        }
        if ($novel !== null && $novel->isComic()) {
            // "Find more issues": the series; the next number after the newest one it has.
            $last = \App\NovelChapter::where('novel_id', $novel->id)->orderByDesc('chapter')->first(['chapter', 'unique_id']);

            return [
                'title' => (string) $novel->name,
                'author' => $novel->author,
                'language' => null,
                'year' => $novel->start_year ? (int) $novel->start_year : null,
                'number' => null,
                'number_kind' => $last !== null && str_starts_with((string) $last->unique_id, 'comic:v:') ? 'volume' : 'issue',
            ];
        }
        if ($novel !== null) {
            return ['title' => (string) $novel->name, 'author' => $novel->author, 'language' => null, 'series_index' => $novel->series_index, 'graphic_novel' => self::graphicNovel(null, $novel)];
        }

        return ['title' => null, 'author' => null, 'language' => null];
    }
}
