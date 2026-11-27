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
        $kind = $kind === DownloadGrab::KIND_AUDIOBOOK ? DownloadGrab::KIND_AUDIOBOOK : DownloadGrab::KIND_EBOOK;
        $query = trim($query) !== '' ? trim($query) : self::defaultQuery($request, $novel);
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
    public static function defaultQuery(?NovelRequest $request, ?Novel $novel): string
    {
        $wanted = self::wanted($request, $novel);
        $author = trim(explode(',', (string) ($wanted['author'] ?? ''))[0]);

        return trim(($wanted['title'] ?? '') . ' ' . $author);
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

            return [
                'title' => (string) $request->title,
                'author' => $request->author,
                'language' => \App\Books\Epub::languageCode($first['language'] ?? null),
                'series_index' => $first['series_index'] ?? null,
                'isbn' => $first['isbn'] ?? null,
            ];
        }
        if ($novel !== null) {
            return ['title' => (string) $novel->name, 'author' => $novel->author, 'language' => null, 'series_index' => $novel->series_index];
        }

        return ['title' => null, 'author' => null, 'language' => null];
    }
}
