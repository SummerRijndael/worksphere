<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailAccountIPv6SSRFTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_ipv4_mapped_ipv6_addresses()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/email-accounts/test-configuration', [
            'provider' => 'custom',
            'auth_type' => 'password',
            'email' => 'test@example.com',
            'username' => 'user',
            'password' => 'pass',
            'smtp_host' => '::ffff:127.0.0.1', // IPv4 mapped IPv6 to localhost
            'smtp_port' => 25,
            'smtp_encryption' => 'none',
            'imap_host' => '::ffff:127.0.0.1',
            'imap_port' => 143,
            'imap_encryption' => 'none',
        ]);

        $response->assertOk();
        $content = $response->json();

        // The framework should block this
        $this->assertFalse($content['success'], 'Response should indicate failure for ::ffff:127.0.0.1');

        $this->assertTrue(
            str_contains($content['message'], 'private IP') || str_contains($content['message'], 'blocked'),
            'Response message should indicate blocking of ::ffff:127.0.0.1. Actual: '.$content['message']
        );
    }
}
