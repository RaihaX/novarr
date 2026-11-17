<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The array cache outlives RefreshDatabase: without this, one test's
        // cached aggregates (library counts, dashboard panels) leak into the next.
        \Illuminate\Support\Facades\Cache::flush();
        // The chapter dispatcher starts lanes as detached processes; tests
        // run them in-process (tests that check the dispatch bind their own).
        $this->app->instance(\App\Services\LaneProcessStarter::class, new \Tests\Support\InlineLaneStarter());
    }

    use CreatesApplication;
}
