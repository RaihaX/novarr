<?php

namespace App\Http\Controllers\Api\V1;

use App\Audiobook;
use App\AudiobookBookmark;
use App\AudiobookChapter;
use App\AudiobookPart;
use App\Audiobooks\ListeningProgress;
use App\Support\SignedUrls;

/**
 * JSON shapes of the audiobooks API (the audiobooks contract, v1):
 *
 *   Summary  {id, novel_id, title, author, narrator, cover_url, duration_ms, progress}
 *   Detail   Summary + {description, year, size_bytes, parts, chapters, bookmarks, series, series_index, asin}
 *   progress null | {position_ms, finished, updated_at}
 *
 * title / author / cover are the matched book's when it has one (the
 * audiobook's own tags otherwise). A part's url is absolute, on the
 * request's origin, and signed for the reader for a day
 * (SignedUrls::temporaryRoute()), so <audio> and ExoPlayer stream it
 * without headers; the bearer header works on it too.
 */
class AudiobookPresenter
{
    /**
     * Load what summary() reads (the book and its cover) on these audiobooks.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Audiobook>|Audiobook  $audiobooks
     */
    public static function load($audiobooks)
    {
        if ($audiobooks instanceof Audiobook) {
            return $audiobooks->loadMissing('novel.file');
        }
        // A soft-deleted book reads as none: the audiobook is standalone meanwhile.
        $audiobooks->loadMissing('novel.file');

        return $audiobooks;
    }

    public static function summary(Audiobook $audiobook, ?object $progress): array
    {
        return [
            'id' => (int) $audiobook->id,
            'novel_id' => $audiobook->novel_id !== null && $audiobook->novel !== null ? (int) $audiobook->novel_id : null,
            'title' => $audiobook->displayTitle(),
            'author' => $audiobook->displayAuthor(),
            'narrator' => $audiobook->narrator ?: null,
            'cover_url' => Presenter::url($audiobook->coverUrl('md')),
            'duration_ms' => (int) $audiobook->duration_ms,
            'progress' => ListeningProgress::present($progress),
            // From Audible (App\Audiobooks\AudiobookEnricher): the Listen tiles show "Series #N".
            'series' => $audiobook->series ?: null,
            'series_index' => $audiobook->series_index !== null ? (float) $audiobook->series_index : null,
        ];
    }

    /**
     * @param  iterable<AudiobookBookmark>  $bookmarks  the reader's, in position order
     */
    public static function detail(Audiobook $audiobook, ?object $progress, iterable $bookmarks): array
    {
        $parts = $audiobook->parts->map(fn(AudiobookPart $p) => [
            'index' => (int) $p->idx,
            'url' => Presenter::url(SignedUrls::temporaryRoute('api.v1.audiobooks.parts.show', ['id' => $audiobook->id, 'index' => $p->idx])),
            'mime' => (string) $p->mime,
            'duration_ms' => (int) $p->duration_ms,
            'start_ms' => (int) $p->start_ms,
            'size_bytes' => (int) $p->size_bytes,
        ])->values()->all();

        $chapters = $audiobook->chapters->map(fn(AudiobookChapter $c) => [
            'index' => (int) $c->idx,
            'title' => (string) $c->title,
            'start_ms' => (int) $c->start_ms,
            'end_ms' => (int) $c->end_ms,
        ])->values()->all();

        // The detail keeps its shape: the summary's keys, then these (series last, with the asin).
        return array_diff_key(static::summary($audiobook, $progress), ['series' => 1, 'series_index' => 1]) + [
            'description' => $audiobook->description ?: null,
            'year' => $audiobook->year !== null ? (int) $audiobook->year : null,
            'size_bytes' => (int) $audiobook->size_bytes,
            'parts' => $parts,
            'chapters' => $chapters,
            'bookmarks' => collect($bookmarks)->map(fn(AudiobookBookmark $b) => static::bookmark($b))->values()->all(),
            // From Audible (App\Audiobooks\AudiobookEnricher), null when not looked up or not found.
            'series' => $audiobook->series ?: null,
            'series_index' => $audiobook->series_index !== null ? (float) $audiobook->series_index : null,
            'asin' => $audiobook->asin ?: null,
        ];
    }

    public static function bookmark(AudiobookBookmark $bookmark): array
    {
        return [
            'id' => (int) $bookmark->id,
            'position_ms' => (int) $bookmark->position_ms,
            'note' => $bookmark->note !== null && $bookmark->note !== '' ? (string) $bookmark->note : null,
            'created_at' => Presenter::timestamp($bookmark->created_at),
        ];
    }
}
