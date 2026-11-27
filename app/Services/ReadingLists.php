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
 * "Want to read" (ReadingList::KIND_WANT_TO_READ) is every reader's built-in
 * list: made on first use (wantToRead()), listed first, never renamed or
 * deleted, not counted against MAX_LISTS, its series newest first. Novels,
 * manga and books alike; reading one keeps it on the list ("started").
 */
class ReadingLists
{
    /**
     * A reader's lists — "Want to read" first (made here on first use), then
     * their own by name (case-insensitive) — each with its live series:
     * [{id, name, count, novel_ids, kind}] (the API's shape). novel_ids are
     * by id, except "Want to read"'s: newest first. Two statements whatever
     * the number of lists (plus one insert, once per reader).
     *
     * @return list<array{id:int, name:string, count:int, novel_ids:list<int>, kind:?string}>
     */
    public static function forReader(int $userId): array
    {
        static::wantToRead($userId);
        $lists = static::ordered($userId);

        $members = static::liveMembers()
            ->whereIn('m.reading_list_id', $lists->pluck('id'))
            ->orderBy('m.novel_id')
            ->get(['m.reading_list_id', 'm.novel_id', 'm.added_at'])
            ->groupBy('reading_list_id');

        return $lists->map(fn(ReadingList $list) => static::shape(
            $list,
            static::memberIds($list, $members->get($list->id) ?? collect())
        ))->values()->all();
    }

    /** One list in the API's shape. */
    public static function present(ReadingList $list): array
    {
        $rows = static::liveMembers()
            ->where('m.reading_list_id', $list->id)
            ->orderBy('m.novel_id')
            ->get(['m.novel_id', 'm.added_at']);

        return static::shape($list, static::memberIds($list, $rows));
    }

    // --- Want to read -------------------------------------------------------

    /**
     * The reader's "Want to read" list, made on first use. Two first uses
     * racing end with one list: the loser's insert hits the (user_id, kind)
     * unique key and it reads the winner's.
     */
    public static function wantToRead(int $userId): ReadingList
    {
        if ($list = static::wantToReadOrNull($userId)) {
            return $list;
        }
        try {
            return ReadingList::create([
                'user_id' => $userId,
                'name' => ReadingList::WANT_TO_READ_NAME,
                'name_key' => ReadingList::SYSTEM_KEY,
                'kind' => ReadingList::KIND_WANT_TO_READ,
            ]);
        } catch (QueryException $e) {
            if (static::isUniqueViolation($e) && ($list = static::wantToReadOrNull($userId))) {
                return $list;
            }
            throw $e;
        }
    }

    /** The reader's "Want to read" list if it was ever used (never makes one). */
    public static function wantToReadOrNull(int $userId): ?ReadingList
    {
        return ReadingList::where('user_id', $userId)->where('kind', ReadingList::KIND_WANT_TO_READ)->first();
    }

    /**
     * Put a series on, or take it off, the reader's "Want to read"
     * (idempotent). 404 for an unknown series (or a soft-deleted one, adding).
     */
    public static function setWantToRead(int $userId, $novelId, bool $want): ReadingList
    {
        $list = static::wantToRead($userId);
        $want ? static::add($list, $novelId) : static::remove($list, $novelId);

        return $list;
    }

    /**
     * Ids of the live series on the reader's "Want to read", as a set (the
     * summaries' want_to_read flag). One statement; none made.
     *
     * @return array<int, true>
     */
    public static function wantToReadIds(int $userId): array
    {
        return array_fill_keys(
            static::liveMembers()
                ->join('reading_lists as l', 'l.id', '=', 'm.reading_list_id')
                ->where('l.user_id', $userId)
                ->where('l.kind', ReadingList::KIND_WANT_TO_READ)
                ->pluck('m.novel_id')
                ->map(fn($id) => (int) $id)
                ->all(),
            true
        );
    }

    /** Whether one series is on the reader's "Want to read". */
    public static function isWanted(int $userId, int $novelId): bool
    {
        return DB::table(ReadingList::PIVOT . ' as m')
            ->join('reading_lists as l', 'l.id', '=', 'm.reading_list_id')
            ->where('l.user_id', $userId)
            ->where('l.kind', ReadingList::KIND_WANT_TO_READ)
            ->where('m.novel_id', $novelId)
            ->exists();
    }

    /**
     * The reader's "Want to read", newest first: how many live series it
     * holds of $media (null: every medium) and the first $limit of them
     * (null: all), each with when it was added and whether the reader has
     * started it (a chapter read, or progress in one). Three statements at
     * most; none made.
     *
     * @param  list<string>|null  $media  novels.medium values (a row without one is a novel)
     * @return array{count:int, items: list<array{novel_id:int, added_at:?string, started:bool}>}
     */
    public static function wantToReadItems(int $userId, ?array $media = null, ?int $limit = null): array
    {
        $list = static::wantToReadOrNull($userId);
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

    /**
     * Delete one of the reader's own lists; "Want to read" can't be
     * (ValidationException).
     */
    public static function delete(ReadingList $list): void
    {
        if ($list->isWantToRead()) {
            throw ValidationException::withMessages(['list' => '“' . ReadingList::WANT_TO_READ_NAME . '” is built in and can’t be deleted.']);
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

        return static::saving($name, fn() => ReadingList::create([
            'user_id' => $userId,
            'name' => $name,
            'name_key' => ReadingList::key($name),
        ]));
    }

    /** Rename (a change of case or spacing only is allowed); "Want to read" can't be. */
    public static function rename(ReadingList $list, $name): ReadingList
    {
        if ($list->isWantToRead()) {
            throw ValidationException::withMessages(['name' => '“' . ReadingList::WANT_TO_READ_NAME . '” is built in and can’t be renamed.']);
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
     * state), "Want to read" first once it was used.
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
            ];
        })->values()->all();
    }

    /**
     * For a series page's Lists menu: the reader's own lists, by name, and
     * whether this series is in each ("Want to read" has its own button:
     * isWanted()). @return list<array{id:int, name:string, in:bool}>
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

    /** The reader's lists: "Want to read" first, then by name. */
    private static function ordered(int $userId)
    {
        return ReadingList::where('user_id', $userId)
            ->orderByRaw('CASE WHEN kind IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name_key')
            ->orderBy('id')
            ->get(['id', 'name', 'kind']);
    }

    /** A list's member ids from its rows: by id, "Want to read" newest first. */
    private static function memberIds(ReadingList $list, $rows): array
    {
        if ($list->isWantToRead()) {
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

    private static function shape(ReadingList $list, array $novelIds): array
    {
        return [
            'id' => (int) $list->id,
            'name' => (string) $list->name,
            'count' => count($novelIds),
            'novel_ids' => $novelIds,
            // null: the reader's own list; 'want_to_read': the built-in one.
            'kind' => $list->kind,
        ];
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
        if (ReadingList::key($name) === ReadingList::key(ReadingList::WANT_TO_READ_NAME)) {
            throw ValidationException::withMessages(['name' => '“' . ReadingList::WANT_TO_READ_NAME . '” is already one of your lists.']);
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
