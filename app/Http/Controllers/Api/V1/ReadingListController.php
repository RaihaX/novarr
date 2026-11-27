<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReadingLists;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Reading lists for the native apps and the web UI (App\Services\ReadingLists).
 * Each reader sees and changes only their own: someone else's list, like an
 * unknown series, answers 404 (never 403, which would say it exists).
 *
 *   GET    /api/v1/lists                          {lists: [{id, name, count, novel_ids, kind}]},
 *                                                  "Want to read" first (made on first use), then by name
 *   POST   /api/v1/lists {name}                   201 {list}
 *   PATCH  /api/v1/lists/{list} {name}            {list}
 *   DELETE /api/v1/lists/{list}                   204
 *   PUT    /api/v1/lists/{list}/novels/{novel}    {list} (idempotent)
 *   DELETE /api/v1/lists/{list}/novels/{novel}    {list} (idempotent)
 *
 *   GET    /api/v1/want-to-read[?medium=]         {list, items: [{novel, added_at, started}]}, newest first
 *   PUT    /api/v1/novels/{novel}/want-to-read    {want_to_read: true, list} (idempotent)
 *   DELETE /api/v1/novels/{novel}/want-to-read    {want_to_read: false, list} (idempotent)
 *
 * A blank, too long (> 40) or duplicate name (case and spacing ignored), a
 * 31st list or a 2,001st series: 422 {message, errors}. kind is null for the
 * reader's own lists and "want_to_read" for the built-in one, which can't be
 * renamed or deleted (422) and doesn't count towards the 30.
 */
class ReadingListController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['lists' => ReadingLists::forReader(static::readerId())]);
    }

    public function store(Request $request): JsonResponse
    {
        $list = ReadingLists::create(static::readerId(), $request->input('name'));

        return response()->json(['list' => ReadingLists::present($list)], 201);
    }

    public function update(Request $request, $list): JsonResponse
    {
        $list = ReadingLists::rename(ReadingLists::find(static::readerId(), $list), $request->input('name'));

        return response()->json(['list' => ReadingLists::present($list)]);
    }

    public function destroy($list): Response
    {
        ReadingLists::delete(ReadingLists::find(static::readerId(), $list));

        return response()->noContent();
    }

    public function addNovel($list, $novel): JsonResponse
    {
        $list = ReadingLists::find(static::readerId(), $list);
        ReadingLists::add($list, $novel);

        return response()->json(['list' => ReadingLists::present($list)]);
    }

    public function removeNovel($list, $novel): JsonResponse
    {
        $list = ReadingLists::find(static::readerId(), $list);
        ReadingLists::remove($list, $novel);

        return response()->json(['list' => ReadingLists::present($list)]);
    }

    /**
     * The whole "Want to read", newest first, of one medium (?medium=, as
     * /novels; default all): each series' summary, when it was added and
     * whether the reader has started it.
     */
    public function wantToRead(Request $request): JsonResponse
    {
        $request->validate(['medium' => 'nullable|in:' . implode(',', Presenter::MEDIA)]);
        $medium = $request->query('medium') ?: 'all';
        $readerId = static::readerId();

        return response()->json([
            'list' => ReadingLists::present(ReadingLists::wantToRead($readerId)),
            'items' => Presenter::wantToReadItems($readerId, $medium),
        ]);
    }

    public function addWantToRead($novel): JsonResponse
    {
        return static::setWantToRead($novel, true);
    }

    public function removeWantToRead($novel): JsonResponse
    {
        return static::setWantToRead($novel, false);
    }

    private static function setWantToRead($novel, bool $want): JsonResponse
    {
        $list = ReadingLists::setWantToRead(static::readerId(), $novel, $want);

        return response()->json(['want_to_read' => $want, 'list' => ReadingLists::present($list)]);
    }

    /** The current reader, resolved per request. */
    private static function readerId(): int
    {
        return app(CurrentReader::class)->id();
    }
}
