<?php

namespace App\Services\Requests;

use App\Sources\Source;

/**
 * Asks the sources of one reader-request search (RequestSearch::run()).
 *
 * $lanes groups the source keys: the keys of one lane are asked one after
 * another (they share a host — every Suwayomi entry is the one Suwayomi
 * server), different lanes may be asked at the same time. Nothing is asked
 * once the deadline (epoch milliseconds, now()) has passed.
 *
 * The answer has an entry for every key of every lane:
 *   items  — the source's raw Discover items, or null when it gave none
 *   reason — null (answered), 'failed' (threw, answered null, or its worker
 *            died) or 'timeout' (not answered by the deadline)
 *   asked  — whether the source was called at all
 *
 * Implementations: ProcessSearchRunner (lanes in parallel PHP processes)
 * and InlineSearchRunner (everything in this process, one after another).
 */
interface SearchRunner
{
    /**
     * @param  array<int, string[]>  $lanes  source keys, in search order within a lane
     * @param  array<string, Source>  $sources  the medium's searchable sources by key
     * @return array<string, array{items: ?array, reason: ?string, asked: bool}>
     */
    public function run(string $medium, string $query, array $lanes, array $sources, int $deadlineMs): array;
}
