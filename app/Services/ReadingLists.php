<?php

namespace App\Services;

use App\Novel;
use App\ReadingList;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reading lists: one reader's named groups of series (App\ReadingList). The
 * one place lists are read and changed — the JSON API
 * (App\Http\Controllers\Api\V1\ReadingListController) and the web pages
 * (the novel page's Lists menu, the Library's list filter) go through here.
 *
 * Every method takes the reader's user id: another reader's list is "not
 * found", never "forbidden". A soft-deleted series stays in its lists (a
 * restore brings it back) but is never shown or counted; a hard-deleted one
 * leaves them (App\Novel's hook).
 *
 * "Want to read" (ReadingList::KIND_WANT_TO_READ) and "Favourites"
 * (KIND_FAVOURITES) are every reader's built-in lists (ReadingList::BUILT_IN):
 * made on first use (builtIn()), listed first in that order, never renamed
 * or deleted, not counted against MAX_LISTS, their series newest first.
 * Novels, manga and books alike; reading one keeps it on ("started").
 */
class ReadingLists
{
    /**
     * A reader's lists — "Want to read", then "Favourites" (both made here
     * on first use), then their own by name (case-insensitive) — each with
     * its live series: [{id, name, count, novel_ids, kind}] (the API's
     * shape). novel_ids are by id, except the built-in lists': newest first.
     * Two statements whatever the number of lists (plus the inserts, once
     * per reader).
     *
     * @return list<array{id:int, name:string, count:int, novel_ids:list<int>, kind:?string}>
     */
    public static function forReader(int $userId): array
    {
        foreach (array_keys(ReadingList::BUILT_IN) as $kind) {
            static::builtIn($userId, $kind);
        }
        $lists = static::ordered($userId);

        $members = static::liveMembers()
            ->whereIn('m.reading_list_id', $lists->pluck('id'))
            ->orderBy('m.novel_id')
            ->get(['m.reading_list_id', 'm.novel_id', 'm.added_at'])
            ->groupBy('reading_list_id');

        return $lists->values()->map(fn(ReadingList $list, int $position) => static::shape(
            $list,
            static::memberIds($list, $members->get($list->id) ?? collect()),
            $position
        ))->all();
    }

    /** One list in the API's shape (its position among the reader's lists: one more statement). */
    public static function present(ReadingList $list): array
    {
        $rows = static::liveMembers()
            ->where('m.reading_list_id', $list->id)
            ->orderBy('m.novel_id')
            ->get(['m.novel_id', 'm.added_at']);
        $position = static::ordered((int) $list->user_id)->values()->search(fn(ReadingList $l) => (int) $l->id === (int) $list->id);

        return static::shape($list, static::memberIds($list, $rows), $position === false ? 0 : (int) $position);
    }

    // --- Order, Home visibility, many series at once (Categories) ----------

    /**
     * Put the reader's lists in this order: $ids first as given, then the
     * rest in their current order; every list gets its position. 404 when an
     * id is not one of the reader's lists (nothing changes); duplicates
     * count once.
     *
     * @param  array<mixed>  $ids
     */
    public static function reorder(int $userId, array $ids): void
    {
        $lists = static::ordered($userId);
        $byId = $lists->keyBy(fn(ReadingList $l) => (int) $l->id);
        $first = [];
        foreach ($ids as $id) {
            if (!is_numeric($id) || !$byId->has((int) $id)) {
                throw new NotFoundHttpException('List not found.');
            }
            $first[(int) $id] = true;
        }
        $order = array_merge(
            array_keys($first),
            $lists->map(fn(ReadingList $l) => (int) $l->id)->reject(fn(int $id) => isset($first[$id]))->values()->all()
        );

        DB::transaction(function () use ($order, $byId) {
            foreach ($order as $position => $id) {
                if ($byId->get($id)->position !== $position) {
                    ReadingList::whereKey($id)->update(['position' => $position]);
                }
            }
        });
    }

    /** Show the list on Home, or take it off (built-in lists too). */
    public static function setHiddenOnHome(ReadingList $list, bool $hidden): ReadingList
    {
        if ((bool) $list->hidden_on_home !== $hidden) {
            $list->forceFill(['hidden_on_home' => $hidden])->save();
        }

        return $list;
    }

    /**
     * Put many series in some of the reader's lists and take them out of
     * others, in one go (the apps' multi-select "Set categories"). Built-in
     * lists included. Idempotent. 404 for a list that is not the reader's
     * or an unknown series (or a soft-deleted one); 422 when a list is in
     * both $add and $remove, or would hold more than MAX_NOVELS. Nothing
     * changes on an error.
     *
     * @param  array<mixed>  $novelIds
     * @param  array<mixed>  $add  list ids
     * @param  array<mixed>  $remove  list ids
     */
    public static function setMemberships(int $userId, array $novelIds, array $add, array $remove): void
    {
        $add = array_values(array_unique(array_map('intval', $add)));
        $remove = array_values(array_unique(array_map('intval', $remove)));
        if (array_intersect($add, $remove) !== []) {
            throw ValidationException::withMessages(['add' => 'A category can’t be added and removed at once.']);
        }
        $addLists = array_map(fn(int $id) => static::find($userId, $id), $add);
        $removeLists = array_map(fn(int $id) => static::find($userId, $id), $remove);

        $novelIds = array_values(array_unique(array_map(fn($id) => is_numeric($id) ? (int) $id : 0, $novelIds)));
        $known = $novelIds === [] ? [] : Novel::query()->whereIn('id', $novelIds)->pluck('id')->all();
        if (count($known) !== count($novelIds)) {
            throw new NotFoundHttpException('Series not found.');
        }
        if ($novelIds === []) {
            return;
        }

        foreach ($addLists as $list) {
            $present = DB::table(ReadingList::PIVOT)->where('reading_list_id', $list->id)->whereIn('novel_id', $novelIds)->pluck('novel_id')->map(fn($id) => (int) $id)->all();
            $new = count(array_diff($novelIds, $present));
            if ($new > 0 && static::liveMembers()->where('m.reading_list_id', $list->id)->count() + $new > ReadingList::MAX_NOVELS) {
                throw ValidationException::withMessages([
                    'novel' => '“' . $list->name . '” can hold up to ' . number_format(ReadingList::MAX_NOVELS) . ' series.',
                ]);
            }
        }

        DB::transaction(function () use ($addLists, $removeLists, $novelIds) {
            $now = now();
            foreach ($addLists as $list) {
                // insertOrIgnore: a series already there keeps its added_at.
                $inserted = DB::table(ReadingList::PIVOT)->insertOrIgnore(array_map(fn(int $id) => [
                    'reading_list_id' => $list->id,
                    'novel_id' => $id,
                    'added_at' => $now,
                ], $novelIds));
                if ($inserted > 0) {
                    $list->touch();
                }
            }
            foreach ($removeLists as $list) {
                if (DB::table(ReadingList::PIVOT)->where('reading_list_id', $list->id)->whereIn('novel_id', $novelIds)->delete() > 0) {
                    $list->touch();
                }
            }
        });
    }

    /**
     * For each of these series, the ids of the reader's lists it is in, in
     * the reader's order (series in none are left out). Two statements.
     *
     * @param  list<int>  $novelIds
     * @return array<int, list<int>>
     */
    public static function listIdsByNovel(int $userId, array $novelIds): array
    {
        if ($novelIds === []) {
            return [];
        }
        $rank = static::ordered($userId)->values()->mapWithKeys(fn(ReadingList $l, int $i) => [(int) $l->id => $i])->all();
        $rows = DB::table(ReadingList::PIVOT . ' as m')
            ->join('reading_lists as l', 'l.id', '=', 'm.reading_list_id')
            ->where('l.user_id', $userId)
            ->whereIn('m.novel_id', $novelIds)
            ->get(['m.novel_id', 'm.reading_list_id']);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->novel_id][] = (int) $row->reading_list_id;
        }
        foreach ($out as &$ids) {
            usort($ids, fn(int $a, int $b) => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX));
        }
        unset($ids);

        return $out;
    }

    /** The reader's lists in their order (models: id, name, kind, position, hidden_on_home). */
    public static function inOrder(int $userId)
    {
        return static::ordered($userId);
    }

    /**
     * Home's "summary of the categories": for each of $lists (models from
     * inOrder(), the reader's own) how many live series it holds and the
     * newest $limit of them (novel ids, newest first). Two statements
     * whatever the number of lists; none for no lists.
     *
     * @return array<int, array{count:int, novel_ids: list<int>}>  by list id
     */
    public static function shelves($lists, int $limit): array
    {
        $ids = collect($lists)->map(fn(ReadingList $l) => (int) $l->id)->values()->all();
        if ($ids === []) {
            return [];
        }
        $out = array_fill_keys($ids, ['count' => 0, 'novel_ids' => []]);
        foreach (static::liveMembers()->whereIn('m.reading_list_id', $ids)->groupBy('m.reading_list_id')
            ->get(['m.reading_list_id', DB::raw('COUNT(*) AS c')]) as $row) {
            $out[(int) $row->reading_list_id]['count'] = (int) $row->c;
        }

        // The newest $limit per list in one statement (a window function:
        // SQLite 3.25+, MariaDB 10.2+, MySQL 8).
        $ranked = static::liveMembers()
            ->whereIn('m.reading_list_id', $ids)
            ->select(['m.reading_list_id', 'm.novel_id', 'm.added_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY m.reading_list_id ORDER BY m.added_at DESC, m.novel_id DESC) AS rn');
        $rows = DB::query()->fromSub($ranked, 'r')
            ->where('r.rn', '<=', max(1, $limit))
            ->orderBy('r.reading_list_id')
            ->orderBy('r.rn')
            ->get(['r.reading_list_id', 'r.novel_id']);
        foreach ($rows as $row) {
            $out[(int) $row->reading_list_id]['novel_ids'][] = (int) $row->novel_id;
        }

        return $out;
    }

    // --- Built-in lists ("Want to read", "Favourites") ---------------------

    /**
     * The reader's built-in list of $kind (ReadingList::BUILT_IN), made on
     * first use. Two first uses racing end with one list: the loser's
     * insert hits the (user_id, kind) unique key and it reads the winner's.
     */
    public static function builtIn(int $userId, string $kind): ReadingList
    {
        [$name, $key] = static::builtInSpec($kind);
        if ($list = static::builtInOrNull($userId, $kind)) {
            return $list;
        }
        try {
            return ReadingList::create([
                'user_id' => $userId,
                'name' => $name,
                'name_key' => $key,
                'kind' => $kind,
            ]);
        } catch (QueryException $e) {
            if (static::isUniqueViolation($e) && ($list = static::builtInOrNull($userId, $kind))) {
                return $list;
            }
            throw $e;
        }
    }

    /** The reader's built-in list of $kind if it was ever used (never makes one). */
    public static function builtInOrNull(int $userId, string $kind): ?ReadingList
    {
        return ReadingList::where('user_id', $userId)->where('kind', $kind)->first();
    }

    /**
     * Put a series on, or take it off, the reader's built-in list of $kind
     * (idempotent). 404 for an unknown series (or a soft-deleted one, adding).
     */
    public static function setOfKind(int $userId, string $kind, $novelId, bool $on): ReadingList
    {
        $list = static::builtIn($userId, $kind);
        $on ? static::add($list, $novelId) : static::remove($list, $novelId);

        return $list;
    }

    /**
     * Ids of the live series on the reader's built-in list of $kind, as a
     * set (the summaries' want_to_read / favourite flags). One statement;
     * none made.
     *
     * @return array<int, true>
     */
    public static function idsOfKind(int $userId, string $kind): array
    {
        return array_fill_keys(
            static::liveMembers()
                ->join('reading_lists as l', 'l.id', '=', 'm.reading_list_id')
                ->where('l.user_id', $userId)
                ->where('l.kind', $kind)
                ->pluck('m.novel_id')
                ->map(fn($id) => (int) $id)
                ->all(),
            true
        );
    }

    /**
     * idsOfKind() for every built-in kind at once (the summaries' flags):
     * kind => set of live series ids. One statement; none made.
     *
     * @return array<string, array<int, true>>
     */
    public static function builtInIds(int $userId): array
    {
        $sets = array_fill_keys(array_keys(ReadingList::BUILT_IN), []);
        $rows = static::liveMembers()
            ->join('reading_lists as l', 'l.id', '=', 'm.reading_list_id')
            ->where('l.user_id', $userId)
            ->whereIn('l.kind', array_keys(ReadingList::BUILT_IN))
            ->get(['l.kind', 'm.novel_id']);
        foreach ($rows as $row) {
            $sets[$row->kind][(int) $row->novel_id] = true;
        }

        return $sets;
    }

    /** Whether one series is on the reader's built-in list of $kind. */
    public static function inKind(int $userId, string $kind, int $novelId): bool
    {
        return DB::table(ReadingList::PIVOT . ' as m')
            ->join('reading_lists as l', 'l.id', '=', 'm.reading_list_id')
            ->where('l.user_id', $userId)
            ->where('l.kind', $kind)
            ->where('m.novel_id', $novelId)
            ->exists();
    }

    /**
     * The reader's built-in list of $kind, newest first: how many live
     * series it holds of $media (null: every medium) and the first $limit
     * of them (null: all), each with when it was added and whether the
     * reader has started it (a chapter read, or progress in one). Three
     * statements at most; none made.
     *
     * @param  list<string>|null  $media  novels.medium values (a row without one is a novel)
     * @return array{count:int, items: list<array{novel_id:int, added_at:?string, started:bool}>}
     */
    public static function itemsOfKind(int $userId, string $kind, ?array $media = null, ?int $limit = null): array
    {
        return static::itemsOfList($userId, static::builtInOrNull($userId, $kind), $media, $limit);
    }

    /**
     * Every built-in list the reader has used, by kind (null: never used),
     * in one statement; none made. For a page showing several of them
     * (Home's shelves), with itemsOfList().
     *
     * @return array<string, ?ReadingList>
     */
    public static function builtInListsOrNull(int $userId): array
    {
        $found = ReadingList::where('user_id', $userId)->whereIn('kind', array_keys(ReadingList::BUILT_IN))->get()->keyBy('kind');

        return array_map(fn($kind) => $found->get($kind), array_combine(array_keys(ReadingList::BUILT_IN), array_keys(ReadingList::BUILT_IN)));
    }

    /**
     * itemsOfKind() for a list already in hand (null: never used, empty).
     * $list must be the reader's own (its started flags are theirs).
     */
    public static function itemsOfList(int $userId, ?ReadingList $list, ?array $media = null, ?int $limit = null): array
    {
        if ($list === null) {
            return ['count' => 0, 'items' => []];
        }

        $rows = static::liveMembers()
            ->where('m.reading_list_id', $list->id)
            ->when($media !== null, fn($q) => $q->where(fn($q) => $q->whereIn('n.medium', $media)
                ->when(in_array(Novel::MEDIUM_NOVEL, $media, true), fn($q) => $q->orWhereNull('n.medium'))))
            ->orderByDesc('m.added_at')
            ->orderByDesc('m.novel_id')
            ->get(['m.novel_id', 'm.added_at']);
        $count = $rows->count();
        if ($limit !== null) {
            $rows = $rows->take($limit);
        }

        $ids = $rows->pluck('novel_id')->map(fn($id) => (int) $id)->all();
        $started = $ids === [] ? [] : DB::table(ReadState::TABLE)
            ->where('user_id', $userId)
            ->whereIn('novel_id', $ids)
            ->where(fn($q) => $q->whereNotNull('read_at')->orWhere('progress', '>', 0))
            ->distinct()
            ->pluck('novel_id')
            ->map(fn($id) => (int) $id)
            ->flip()
            ->all();

        return [
            'count' => $count,
            'items' => $rows->map(fn($row) => [
                'novel_id' => (int) $row->novel_id,
                'added_at' => $row->added_at !== null ? (string) $row->added_at : null,
                'started' => isset($started[(int) $row->novel_id]),
            ])->values()->all(),
        ];
    }

    // --- Want to read -------------------------------------------------------

    /** The reader's "Want to read" list, made on first use (builtIn()). */
    public static function wantToRead(int $userId): ReadingList
    {
        return static::builtIn($userId, ReadingList::KIND_WANT_TO_READ);
    }

    /** The reader's "Want to read" list if it was ever used (never makes one). */
    public static function wantToReadOrNull(int $userId): ?ReadingList
    {
        return static::builtInOrNull($userId, ReadingList::KIND_WANT_TO_READ);
    }

    /** Put a series on, or take it off, the reader's "Want to read" (setOfKind()). */
    public static function setWantToRead(int $userId, $novelId, bool $want): ReadingList
    {
        return static::setOfKind($userId, ReadingList::KIND_WANT_TO_READ, $novelId, $want);
    }

    /** @return array<int, true> the live series on the reader's "Want to read" (idsOfKind()) */
    public static function wantToReadIds(int $userId): array
    {
        return static::idsOfKind($userId, ReadingList::KIND_WANT_TO_READ);
    }

    /** Whether one series is on the reader's "Want to read". */
    public static function isWanted(int $userId, int $novelId): bool
    {
        return static::inKind($userId, ReadingList::KIND_WANT_TO_READ, $novelId);
    }

    /** The reader's "Want to read", newest first (itemsOfKind()). */
    public static function wantToReadItems(int $userId, ?array $media = null, ?int $limit = null): array
    {
        return static::itemsOfKind($userId, ReadingList::KIND_WANT_TO_READ, $media, $limit);
    }

    // --- Favourites ---------------------------------------------------------

    /** The reader's "Favourites" list, made on first use (builtIn()). */
    public static function favourites(int $userId): ReadingList
    {
        return static::builtIn($userId, ReadingList::KIND_FAVOURITES);
    }

    /** The reader's "Favourites" list if it was ever used (never makes one). */
    public static function favouritesOrNull(int $userId): ?ReadingList
    {
        return static::builtInOrNull($userId, ReadingList::KIND_FAVOURITES);
    }

    /** Put a series in, or take it out of, the reader's "Favourites" (setOfKind()). */
    public static function setFavourite(int $userId, $novelId, bool $favourite): ReadingList
    {
        return static::setOfKind($userId, ReadingList::KIND_FAVOURITES, $novelId, $favourite);
    }

    /** @return array<int, true> the live series in the reader's "Favourites" (idsOfKind()) */
    public static function favouriteIds(int $userId): array
    {
        return static::idsOfKind($userId, ReadingList::KIND_FAVOURITES);
    }

    /** Whether one series is in the reader's "Favourites". */
    public static function isFavourite(int $userId, int $novelId): bool
    {
        return static::inKind($userId, ReadingList::KIND_FAVOURITES, $novelId);
    }

    /** The reader's "Favourites", newest first (itemsOfKind()). */
    public static function favouriteItems(int $userId, ?array $media = null, ?int $limit = null): array
    {
        return static::itemsOfKind($userId, ReadingList::KIND_FAVOURITES, $media, $limit);
    }

    /**
     * Delete one of the reader's own lists; a built-in one can't be
     * (ValidationException).
     */
    public static function delete(ReadingList $list): void
    {
        if ($list->isBuiltIn()) {
            throw ValidationException::withMessages(['list' => '“' . static::builtInName($list) . '” is built in and can’t be deleted.']);
        }
        $list->delete();
    }

    /** The reader's list, else 404 — the same answer for someone else's as for none. */
    public static function find(int $userId, $listId): ReadingList
    {
        $list = static::findOrNull($userId, $listId);
        if ($list === null) {
            throw new NotFoundHttpException('List not found.');
        }

        return $list;
    }

    /**
     * A new list. ValidationException (422) on a blank, too long or
     * duplicate name, or when the reader already has MAX_LISTS.
     */
    public static function create(int $userId, $name): ReadingList
    {
        $name = static::validName($name);
        if (ReadingList::where('user_id', $userId)->whereNull('kind')->count() >= ReadingList::MAX_LISTS) {
            throw ValidationException::withMessages([
                'name' => 'You can have up to ' . ReadingList::MAX_LISTS . ' lists. Delete one to make room.',
            ]);
        }
        static::assertFree($userId, $name);

        // Once the reader has ordered their lists, a new one goes last.
        $last = ReadingList::where('user_id', $userId)->max('position');

        return static::saving($name, fn() => ReadingList::create([
            'user_id' => $userId,
            'name' => $name,
            'name_key' => ReadingList::key($name),
            'position' => $last !== null ? (int) $last + 1 : null,
        ]));
    }

    /** Rename (a change of case or spacing only is allowed); a built-in list can't be. */
    public static function rename(ReadingList $list, $name): ReadingList
    {
        if ($list->isBuiltIn()) {
            throw ValidationException::withMessages(['name' => '“' . static::builtInName($list) . '” is built in and can’t be renamed.']);
        }
        $name = static::validName($name);
        static::assertFree((int) $list->user_id, $name, (int) $list->id);

        return static::saving($name, function () use ($list, $name) {
            $list->forceFill(['name' => $name, 'name_key' => ReadingList::key($name)])->save();

            return $list;
        });
    }

    /**
     * Put a series in a list (idempotent). 404 for an unknown or
     * soft-deleted series; ValidationException when the list is
     * full.
     */
    public static function add(ReadingList $list, $novelId): void
    {
        $novel = static::novel($novelId);
        $exists = DB::table(ReadingList::PIVOT)->where('reading_list_id', $list->id)->where('novel_id', $novel->id)->exists();
        if ($exists) {
            return;
        }
        if (static::liveMembers()->where('m.reading_list_id', $list->id)->count() >= ReadingList::MAX_NOVELS) {
            throw ValidationException::withMessages([
                'novel' => 'A list can hold up to ' . number_format(ReadingList::MAX_NOVELS) . ' series.',
            ]);
        }
        // insertOrIgnore: two adds racing end with one row, not an error.
        DB::table(ReadingList::PIVOT)->insertOrIgnore([
            'reading_list_id' => $list->id,
            'novel_id' => $novel->id,
            'added_at' => now(),
        ]);
        $list->touch();
    }

    /** Take a series out of a list (idempotent). 404 for an unknown series. */
    public static function remove(ReadingList $list, $novelId): void
    {
        $novel = static::novel($novelId);
        $removed = DB::table(ReadingList::PIVOT)->where('reading_list_id', $list->id)->where('novel_id', $novel->id)->delete();
        if ($removed > 0) {
            $list->touch();
        }
    }

    /**
     * For the Library: the reader's lists with how many live series of
     * $medium each holds (and how many of the other medium, for the empty
     * state), "Want to read" and "Favourites" first once each was used.
     * @return list<array{id:int, name:string, count:int, other:int, kind:?string}>
     */
    public static function forLibrary(int $userId, string $medium): array
    {
        $lists = static::ordered($userId);
        if ($lists->isEmpty()) {
            return [];
        }

        $counts = static::liveMembers()
            ->whereIn('m.reading_list_id', $lists->pluck('id'))
            ->groupBy('m.reading_list_id', 'n.medium')
            ->get(['m.reading_list_id', 'n.medium', DB::raw('COUNT(*) AS c')]);

        return $lists->map(function (ReadingList $list) use ($counts, $medium) {
            $rows = $counts->where('reading_list_id', $list->id);
            $here = (int) $rows->filter(fn($r) => static::mediumOf($r->medium) === $medium)->sum('c');

            return [
                'id' => (int) $list->id,
                'name' => (string) $list->name,
                'count' => $here,
                'other' => (int) $rows->sum('c') - $here,
                'kind' => $list->kind,
                'hidden_on_home' => (bool) $list->hidden_on_home,
            ];
        })->values()->all();
    }

    /**
     * For a series page's Lists menu: the reader's own lists, by name, and
     * whether this series is in each (the built-in lists have their own
     * buttons: isWanted(), isFavourite()). @return list<array{id:int, name:string, in:bool}>
     */
    public static function forNovel(int $userId, int $novelId): array
    {
        $lists = ReadingList::where('user_id', $userId)->whereNull('kind')->orderBy('name_key')->orderBy('id')->get(['id', 'name']);
        if ($lists->isEmpty()) {
            return [];
        }
        $in = DB::table(ReadingList::PIVOT)
            ->whereIn('reading_list_id', $lists->pluck('id'))
            ->where('novel_id', $novelId)
            ->pluck('reading_list_id')
            ->map(fn($id) => (int) $id)
            ->flip();

        return $lists->map(fn(ReadingList $list) => [
            'id' => (int) $list->id,
            'name' => (string) $list->name,
            'in' => $in->has((int) $list->id),
        ])->values()->all();
    }

    /** The reader's list with this id, or null (someone else's, gone, not a number). */
    public static function findOrNull(int $userId, $listId): ?ReadingList
    {
        return is_numeric($listId) && (int) $listId > 0
            ? ReadingList::where('user_id', $userId)->find((int) $listId)
            : null;
    }

    /** Ids of the live series in a list (the Library filter's id set). @return list<int> */
    public static function novelIds(ReadingList $list): array
    {
        return static::liveMembers()->where('m.reading_list_id', $list->id)->pluck('m.novel_id')->map(fn($id) => (int) $id)->all();
    }

    // ---------------------------------------------------------------------

    /**
     * The reader's lists in their order: by position once they reordered
     * them; those without one after: "Want to read", "Favourites", then
     * their own by name.
     */
    private static function ordered(int $userId)
    {
        return ReadingList::where('user_id', $userId)
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderByRaw('CASE kind WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [ReadingList::KIND_WANT_TO_READ, ReadingList::KIND_FAVOURITES])
            ->orderBy('name_key')
            ->orderBy('id')
            ->get(['id', 'user_id', 'name', 'kind', 'position', 'hidden_on_home']);
    }

    /** A list's member ids from its rows: by id, a built-in list's newest first. */
    private static function memberIds(ReadingList $list, $rows): array
    {
        if ($list->isBuiltIn()) {
            $rows = $rows->sort(fn($a, $b) => [(string) $b->added_at, (int) $b->novel_id] <=> [(string) $a->added_at, (int) $a->novel_id]);
        }

        return $rows->map(fn($row) => (int) $row->novel_id)->values()->all();
    }

    /** Members whose series exists and is not soft-deleted (alias m, novels n). */
    private static function liveMembers()
    {
        return DB::table(ReadingList::PIVOT . ' as m')
            ->join('novels as n', 'n.id', '=', 'm.novel_id')
            ->whereNull('n.deleted_at');
    }

    private static function shape(ReadingList $list, array $novelIds, int $position): array
    {
        return [
            'id' => (int) $list->id,
            'name' => (string) $list->name,
            'count' => count($novelIds),
            'novel_ids' => $novelIds,
            // null: the reader's own list; 'want_to_read' / 'favourites': a built-in one.
            'kind' => $list->kind,
            // Categories: where it is in the reader's order (0 first), and whether it is off Home.
            'position' => $position,
            'hidden_on_home' => (bool) $list->hidden_on_home,
        ];
    }

    /** [name, name_key] of a built-in kind; InvalidArgumentException for any other. */
    private static function builtInSpec(string $kind): array
    {
        if (!isset(ReadingList::BUILT_IN[$kind])) {
            throw new \InvalidArgumentException("Not a built-in reading list: {$kind}");
        }

        return ReadingList::BUILT_IN[$kind];
    }

    /** A built-in list's fixed name (its stored one if the kind is unknown). */
    private static function builtInName(ReadingList $list): string
    {
        return ReadingList::BUILT_IN[$list->kind][0] ?? (string) $list->name;
    }

    /** A row from before the medium column is a novel (App\Novel::isManga()). */
    private static function mediumOf(?string $medium): string
    {
        return in_array($medium, [Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK], true) ? $medium : Novel::MEDIUM_NOVEL;
    }

    private static function novel($novelId): Novel
    {
        $novel = is_numeric($novelId) ? Novel::query()->select(['id'])->find((int) $novelId) : null;
        if ($novel === null) {
            throw new NotFoundHttpException('Series not found.');
        }

        return $novel;
    }

    /** The cleaned name, or a ValidationException with a readable message. */
    private static function validName($name): string
    {
        if ($name !== null && !is_string($name)) {
            throw ValidationException::withMessages(['name' => 'The list name must be text.']);
        }
        $name = ReadingList::clean($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the list a name.']);
        }
        if (mb_strlen($name, 'UTF-8') > ReadingList::MAX_NAME) {
            throw ValidationException::withMessages([
                'name' => 'A list name can be up to ' . ReadingList::MAX_NAME . ' characters.',
            ]);
        }

        return $name;
    }

    private static function assertFree(int $userId, string $name, ?int $exceptId = null): void
    {
        // The built-in lists' names are reserved.
        foreach (ReadingList::BUILT_IN as [$builtIn]) {
            if (ReadingList::key($name) === ReadingList::key($builtIn)) {
                throw ValidationException::withMessages(['name' => '“' . $builtIn . '” is already one of your lists.']);
            }
        }
        $taken = ReadingList::where('user_id', $userId)
            ->where('name_key', ReadingList::key($name))
            ->when($exceptId !== null, fn($q) => $q->where('id', '!=', $exceptId))
            ->exists();
        if ($taken) {
            throw static::duplicate($name);
        }
    }

    /**
     * Runs a write that may hit the (user_id, name_key) unique key: a race,
     * or a database collation that equates names the key does not (MariaDB's
     * accent-insensitive collations) — both read as "already have one".
     */
    private static function saving(string $name, callable $write): ReadingList
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (static::isUniqueViolation($e)) {
                throw static::duplicate($name);
            }
            throw $e;
        }
    }

    private static function duplicate(string $name): ValidationException
    {
        return ValidationException::withMessages(['name' => "You already have a list called “{$name}”."]);
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (MySQL / MariaDB 1062, SQLite 19 UNIQUE constraint).
        return (string) $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
