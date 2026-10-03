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
    }

    use CreatesApplication;
}
