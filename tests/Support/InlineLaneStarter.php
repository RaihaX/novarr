<?php

namespace Tests\Support;

use App\Services\LaneProcessStarter;

/**
 * LaneProcessStarter for tests: records the lanes the dispatcher starts and,
 * unless $inline is off, runs them in the test's own process (one
 * interleaved sweep over all of them) instead of detached OS processes.
 * Bound by Tests\TestCase for every test, so `novel:chapter` without a novel
 * still downloads in-process.
 */
class InlineLaneStarter extends LaneProcessStarter
{
    /** @var list<string[]> lanes of every startAll() call */
    public array $started = [];

    public function __construct(public bool $inline = true)
    {
    }

    public function startAll(array $lanes, callable $inline): void
    {
        $this->started[] = $lanes;
        if ($this->inline) {
            $inline($lanes);
        }
    }

    public function start(string $lane): void
    {
        throw new \LogicException("A test tried to start a real lane process ({$lane}).");
    }

    /** Every lane started, in order. */
    public function lanes(): array
    {
        return array_merge([], ...$this->started);
    }
}
