<?php

namespace App\Services\Requests;

use App\NovelRequest;
use App\ReadingList;
use App\Services\ReadingLists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The categories (App\ReadingList) a reader chose for a series they asked
 * for (a long-press in Browse → "Add to category"), remembered per request
 * and reader in novel_request_categories (ReadingList::REQUEST_CATEGORIES)
 * until the request becomes available: then apply() puts the series in
 * each, for the requester and every "Me too" follower who chose some.
 *
 * Rows go with their request (forgetRequest()), with a reader leaving it
 * (forgetReader()), with the category (App\ReadingList's hook) and with
 * the reader's account (ReadingList::forgetUser()).
 */
class RequestCategories
{
    /** Categories one reader may choose for one request. */
    public const MAX = 32;

    /**
     * The reader's own lists with these ids, else 404 (the same answer for
     * someone else's as for none). Checked before a request is made, so a
     * bad id never leaves a request behind.
     *
     * @param  array<mixed>  $listIds
     * @return list<int>
     */
    public static function validIds(int $userId, array $listIds): array
    {
        $ids = [];
        foreach ($listIds as $id) {
            $ids[(int) ReadingLists::find($userId, $id)->id] = true;
        }

        return array_keys($ids);
    }

    /** Replace the reader's choice for this request ($listIds already valid; [] clears it). */
    public static function remember(NovelRequest $request, int $userId, array $listIds): void
    {
        DB::transaction(function () use ($request, $userId, $listIds) {
            DB::table(ReadingList::REQUEST_CATEGORIES)
                ->where('novel_request_id', $request->id)
                ->where('user_id', $userId)
                ->delete();
            if ($listIds !== []) {
                $now = now();
                DB::table(ReadingList::REQUEST_CATEGORIES)->insertOrIgnore(array_map(fn(int $listId) => [
                    'novel_request_id' => (int) $request->id,
                    'user_id' => $userId,
                    'reading_list_id' => $listId,
                    'created_at' => $now,
                ], array_values($listIds)));
            }
        });
    }

    /**
     * The reader's remembered categories for each of these requests:
     * request id => list ids (requests without any left out). One statement.
     *
     * @param  list<int>  $requestIds
     * @return array<int, list<int>>
     */
    public static function forReader(int $userId, array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table(ReadingList::REQUEST_CATEGORIES)
            ->where('user_id', $userId)
            ->whereIn('novel_request_id', $requestIds)
            ->orderBy('id')
            ->get(['novel_request_id', 'reading_list_id']) as $row) {
            $out[(int) $row->novel_request_id][] = (int) $row->reading_list_id;
        }

        return $out;
    }

    /**
     * The request's series is readable: put it in every category its
     * readers chose. A category that is gone, no longer the reader's, or
     * full is skipped (logged); nothing here fails the caller. Returns how
     * many memberships were made or already there.
     */
    public static function apply(NovelRequest $request): int
    {
        if ($request->novel_id === null) {
            return 0;
        }
        $applied = 0;
        $rows = DB::table(ReadingList::REQUEST_CATEGORIES)->where('novel_request_id', $request->id)->get(['user_id', 'reading_list_id']);
        foreach ($rows as $row) {
            try {
                $list = ReadingLists::findOrNull((int) $row->user_id, (int) $row->reading_list_id);
                if ($list === null) {
                    continue;
                }
                ReadingLists::add($list, (int) $request->novel_id);
                $applied++;
            } catch (ValidationException|HttpException $e) {
                Log::info("Request {$request->id}: series {$request->novel_id} not put in category {$row->reading_list_id}: " . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning("Request {$request->id}: putting the series in category {$row->reading_list_id} failed: " . $e->getMessage());
            }
        }

        return $applied;
    }

    /** A request is gone: its remembered categories with it. */
    public static function forgetRequest(int $requestId): void
    {
        DB::table(ReadingList::REQUEST_CATEGORIES)->where('novel_request_id', $requestId)->delete();
    }

    /** A reader left (or handed over) a request: their choice for it goes. */
    public static function forgetReader(int $requestId, int $userId): void
    {
        DB::table(ReadingList::REQUEST_CATEGORIES)->where('novel_request_id', $requestId)->where('user_id', $userId)->delete();
    }
}
