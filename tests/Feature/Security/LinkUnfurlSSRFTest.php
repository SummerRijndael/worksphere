<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkUnfurlSSRFTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_private_ip_addresses_in_unfurl()
    {
        $user = User::factory()->create();

        // 127.0.0.1
        $response = $this->actingAs($user)->postJson('/api/link/unfurl', [
            'url' => 'http://127.0.0.1/sensitive-data',
        ]);

        // Expect 403 Forbidden because it should be blocked
        // If it returns 200 (Success) or 500 (Server Error, meaning it tried to connect), it's vulnerable.
        // LinkUnfurlService throws 'unsafe_content_blocked' which becomes a 403.

        if ($response->status() === 500) {
             // If it's 500, check if it's because connection failed (meaning it TRIED to connect)
             $content = $response->json();
             if (isset($content['error']) && $content['error'] === 'failed_to_unfurl') {
                 $this->fail('Vulnerable: The service attempted to connect to a private IP.');
             }
        }

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'unsafe_content_blocked',
        ]);
    }

    public function test_blocks_localhost_resolution_in_unfurl()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/link/unfurl', [
            'url' => 'http://localhost/sensitive-data',
        ]);

        if ($response->status() === 500) {
             $content = $response->json();
             if (isset($content['error']) && $content['error'] === 'failed_to_unfurl') {
                 $this->fail('Vulnerable: The service attempted to connect to localhost.');
             }
        }

        $response->assertStatus(403);
         $response->assertJson([
            'error' => 'unsafe_content_blocked',
        ]);
    }

    public function test_blocks_aws_metadata_in_unfurl()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/link/unfurl', [
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        if ($response->status() === 500) {
             $content = $response->json();
             if (isset($content['error']) && $content['error'] === 'failed_to_unfurl') {
                 $this->fail('Vulnerable: The service attempted to connect to AWS metadata.');
             }
        }

        $response->assertStatus(403);
         $response->assertJson([
            'error' => 'unsafe_content_blocked',
        ]);
    }
}
