<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DevRoutesSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that dev routes are accessible in local/testing environment by default.
     */
    public function test_dev_routes_are_accessible_in_testing_environment()
    {
        // In 'testing' environment, the route is registered and Gate should pass.
        $response = $this->getJson('/api/dev/users');

        $response->assertStatus(200);
    }

    /**
     * Test that dev routes are forbidden if the Gate denies access.
     * This confirms the 'can:access-dev-tools' middleware is applied and working.
     */
    public function test_dev_routes_are_forbidden_if_gate_denies_access()
    {
        // Override the Gate to deny access
        Gate::define('access-dev-tools', function () {
            return false;
        });

        $response = $this->getJson('/api/dev/users');

        $response->assertStatus(403);
    }
}
