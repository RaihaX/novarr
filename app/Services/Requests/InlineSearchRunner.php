<?php

namespace App\Services\Requests;

/**
 * Every source asked inside this process, one after another, lane by lane
 * (the behaviour before ProcessSearchRunner). Used when
 * novarr.requests.search_parallelism is 1, or when this PHP cannot start
 * processes (proc_open disabled, no CLI binary found). The deadline is
 * checked between sources; one source call already under way may overrun it.
 * The per-source limit (novarr.requests.source_timeout_seconds) does not
 * apply here: a call in this process cannot be interrupted, so a slow
 * source holds up everything behind it — only ProcessSearchRunner enforces it.
 */
class InlineSearchRunner implements SearchRunner
{
    public function run(string $medium, string $query, array $lanes, array $sources, int $deadlineMs): array
    {
        $results = [];
        foreach ($lanes as $lane) {
            foreach ($lane as $key) {
                $key = (string) $key;
                if (!isset($sources[$key])) {
                    $results[$key] = ['items' => null, 'reason' => 'failed', 'asked' => false];
                    continue;
                }
                if (now()->getPreciseTimestamp(3) >= $deadlineMs) {
                    $results[$key] = ['items' => null, 'reason' => 'timeout', 'asked' => false];
                    continue;
                }
                $items = RequestSearch::ask($sources[$key], $key, $query);
                \App\Services\SourceHealth::record($key, \App\Services\SourceHealth::KIND_SEARCH, $items !== null, 'fetch_failed', 'Request search: the source failed or could not be reached.');
                $results[$key] = ['items' => $items, 'reason' => $items === null ? 'failed' : null, 'asked' => true];
            }
        }

        return $results;
    }
}
