<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\ReadingList;
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
 *   GET    /api/v1/lists                          {lists: [{id, name, count, novel_ids, kind, position, hidden_on_home}]},
 *                                                  in the reader's order (position, 0 first); before they reorder:
 *                                                  "Want to read", "Favourites" (both made on first use), then by name
 *   POST   /api/v1/lists {name}                   201 {list}
 *   PATCH  /api/v1/lists/{list} {name?, hidden_on_home?}  {list} (a built-in list: hidden_on_home only)
 *   PATCH  /api/v1/lists/order {ids: [...]}       {lists} — these first, in this order, the rest after as they were
 *   PUT    /api/v1/lists/memberships {novel_ids, add, remove}  {lists} — many series in / out of many
 *                                                  lists in one go (built-ins too; idempotent)
 *   DELETE /api/v1/lists/{list}                   204
 *   PUT    /api/v1/lists/{list}/novels/{novel}    {list} (idempotent)
 *   DELETE /api/v1/lists/{list}/novels/{novel}    {list} (idempotent)
 *
 *   GET    /api/v1/want-to-read[?medium=]         {list, items: [{novel, added_at, started}]}, newest first
 *   PUT    /api/v1/novels/{novel}/want-to-read    {want_to_read: true, list} (idempotent)
 *   DELETE /api/v1/novels/{novel}/want-to-read    {want_to_read: false, list} (idempotent)
 *
 *   GET    /api/v1/favourites[?medium=]           {list, items: [{novel, added_at, started}]}, newest first
 *   PUT    /api/v1/novels/{novel}/favourite       {favourite: true, list} (idempotent)
 *   DELETE /api/v1/novels/{novel}/favourite       {favourite: false, list} (idempotent)
 *
 * A blank, too long (> 40) or duplicate name (case and spacing ignored), a
 * 31st list or a 2,001st series: 422 {message, errors}. kind is null for the
 * reader's own lists, "want_to_read" or "favourites" for the built-in ones,
 * which can't be renamed or deleted (422) and don't count towards the 30.
 */
class ReadingListController extends Controller
{
    /** Series in one multi-select call (memberships, NovelBulkController). */
    public const MAX_BULK = 500;

    public function index(): JsonResponse
    {
        return response()->json(['lists' => ReadingLists::forReader(static::readerId())]);
    }

    public function store(Request $request): JsonResponse
    {
        $list = ReadingLists::create(static::readerId(), $request->input('name'));

        return response()->json(['list' => ReadingLists::present($list)], 201);
    }

    /** Rename (name), and/or show or hide on Home (hidden_on_home); at least one. */
    public function update(Request $request, $list): JsonResponse
    {
        $request->validate(['hidden_on_home' => 'nullable|boolean']);
        $list = ReadingLists::find(static::readerId(), $list);
        // Without hidden_on_home it is a rename, as it always was (a missing name: 422).
        if ($request->has('name') || !$request->filled('hidden_on_home')) {
            $list = ReadingLists::rename($list, $request->input('name'));
        }
        if ($request->filled('hidden_on_home')) {
            $list = ReadingLists::setHiddenOnHome($list, $request->boolean('hidden_on_home'));
        }

        return response()->json(['list' => ReadingLists::present($list)]);
    }

    /** PATCH /api/v1/lists/order {ids}: the reader's order of their categories. */
    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'present|array|max:' . (ReadingList::MAX_LISTS + count(ReadingList::BUILT_IN)),
            'ids.*' => 'integer',
        ]);
        ReadingLists::reorder(static::readerId(), $data['ids']);

        return response()->json(['lists' => ReadingLists::forReader(static::readerId())]);
    }

    /** PUT /api/v1/lists/memberships {novel_ids, add, remove}: the multi-select "Set categories". */
    public function memberships(Request $request): JsonResponse
    {
        $data = $request->validate([
            'novel_ids' => 'required|array|min:1|max:' . self::MAX_BULK,
            'novel_ids.*' => 'integer',
            'add' => 'present|array|max:' . (ReadingList::MAX_LISTS + count(ReadingList::BUILT_IN)),
            'add.*' => 'integer',
            'remove' => 'present|array|max:' . (ReadingList::MAX_LISTS + count(ReadingList::BUILT_IN)),
            'remove.*' => 'integer',
        ]);
        ReadingLists::setMemberships(static::readerId(), $data['novel_ids'], $data['add'], $data['remove']);

        return response()->json(['lists' => ReadingLists::forReader(static::readerId())]);
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

    /**
     * The whole "Favourites", newest first, of one medium (?medium=, as
     * /novels; default all): as wantToRead().
     */
    public function favourites(Request $request): JsonResponse
    {
        $request->validate(['medium' => 'nullable|in:' . implode(',', Presenter::MEDIA)]);
        $medium = $request->query('medium') ?: 'all';
        $readerId = static::readerId();

        return response()->json([
            'list' => ReadingLists::present(ReadingLists::favourites($readerId)),
            'items' => Presenter::favouriteItems($readerId, $medium),
        ]);
    }

    public function addFavourite($novel): JsonResponse
    {
        return static::setFavourite($novel, true);
    }

    public function removeFavourite($novel): JsonResponse
    {
        return static::setFavourite($novel, false);
    }

    private static function setFavourite($novel, bool $favourite): JsonResponse
    {
        $list = ReadingLists::setFavourite(static::readerId(), $novel, $favourite);

        return response()->json(['favourite' => $favourite, 'list' => ReadingLists::present($list)]);
    }

    /** The current reader, resolved per request. */
    private static function readerId(): int
    {
        return app(CurrentReader::class)->id();
    }
}
