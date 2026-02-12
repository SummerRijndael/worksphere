<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SSRFProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_access_to_localhost_ipv4()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/link/unfurl', [
            'url' => 'http://127.0.0.1',
        ]);

        // Service catches exception and returns {url: ..., error: true}
        $response->assertStatus(200);
        $response->assertJsonFragment(['error' => true]);
        $response->assertJsonMissing(['title' => 'WorkSphere']); // Ensure it didn't fetch local content
    }

    public function test_it_blocks_access_to_metadata_service()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/link/unfurl', [
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['error' => true]);
    }

    public function test_it_blocks_access_to_localhost_ipv6()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/link/unfurl', [
            'url' => 'http://[::1]',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['error' => true]);
    }
}
