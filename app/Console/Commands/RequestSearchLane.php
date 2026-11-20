<?php

namespace App\Console\Commands;

use App\Services\Discover;
use App\Services\Requests\ProcessSearchRunner;
use App\Services\Requests\RequestSearch;
use Illuminate\Console\Command;

/**
 * One lane of a reader-request search, started by ProcessSearchRunner (never
 * by hand, never scheduled): the given sources of the medium are asked for
 * the query in NOVARR_SEARCH_QUERY, one after another, and each answer is
 * printed as a result line (ProcessSearchRunner::formatLine()) tagged with
 * the nonce in NOVARR_SEARCH_NONCE. A source not started by --deadline (epoch ms) is
 * reported as a timeout; a source key the medium no longer has as failed.
 */
class RequestSearchLane extends Command
{
    protected $signature = 'requests:search-lane {medium} {sources*} {--deadline=0}';

    protected $description = 'Internal: ask some sources for a reader-request search (query in NOVARR_SEARCH_QUERY).';

    protected $hidden = true;

    /** Longest query taken (the API allows 100 characters). */
    private const MAX_QUERY_CHARS = 200;

    public function handle(Discover $discover): int
    {
        $nonce = (string) (getenv('NOVARR_SEARCH_NONCE') ?: '');
        $query = trim(mb_substr((string) (getenv('NOVARR_SEARCH_QUERY') ?: ''), 0, self::MAX_QUERY_CHARS));
        $deadline = (int) $this->option('deadline');
        $medium = (string) $this->argument('medium');
        if ($nonce === '' || $query === '') {
            $this->error('Nothing to search for.');

            return self::INVALID;
        }

        $available = $discover->sourcesFor($medium);
        foreach ((array) $this->argument('sources') as $key) {
            $key = (string) $key;
            $source = $available[$key] ?? null;
            if ($source === null) {
                $this->emit(ProcessSearchRunner::formatLine($nonce, 'failed', $key));
                continue;
            }
            if ($deadline > 0 && now()->getPreciseTimestamp(3) >= $deadline) {
                $this->emit(ProcessSearchRunner::formatLine($nonce, 'timeout', $key));
                continue;
            }
            $this->emit(ProcessSearchRunner::formatLine($nonce, 'start', $key));
            $items = RequestSearch::ask($source, $key, $query);
            \App\Services\SourceHealth::record($key, \App\Services\SourceHealth::KIND_SEARCH, $items !== null, 'fetch_failed', 'Request search: the source failed or could not be reached.');
            $this->emit($items === null
                ? ProcessSearchRunner::formatLine($nonce, 'failed', $key)
                : ProcessSearchRunner::formatLine($nonce, 'done', $key, $items));
        }

        return self::SUCCESS;
    }

    private function emit(string $line): void
    {
        $this->output->write($line);
    }
}
