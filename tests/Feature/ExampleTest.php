<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The dashboard loads for everyone — the app has no authentication layer.
     *
     * @return void
     */
    public function testDashboardLoads()
    {
        $response = $this->get('/');

        $response->assertOk();
    }
}
