<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ExampleTest extends TestCase
{
    /**
     * Test that unauthenticated users are redirected to login.
     *
     * @return void
     */
    public function testUnauthenticatedUserIsRedirectedToLogin()
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
