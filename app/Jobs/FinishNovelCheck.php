<?php

namespace App\Jobs;

use App\Services\NovelCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Last link of an API chapter check's chain (see NovelCheck): records the
 * outcome while the scrapes' results are still cached (they expire after
 * an hour), so a client polling later still sees done / failed.
 */
class FinishNovelCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public int $novelId,
        public string $checkId,
    ) {}

    public function handle(NovelCheck $checks): void
    {
        $checks->state($this->novelId, $this->checkId);
    }
}
