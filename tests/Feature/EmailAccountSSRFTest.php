<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailAccountSSRFTest extends TestCase
{
    use RefreshDatabase;

    protected function getIgnoredMiddleware(): array
    {
        return [
            \App\Http\Middleware\EnforceTwoFactor::class,
            \App\Http\Middleware\CheckDemoMode::class,
            \Akaunting\Firewall\Middleware\Ip::class,
            \Akaunting\Firewall\Middleware\Agent::class,
            \Akaunting\Firewall\Middleware\Bot::class,
            \Akaunting\Firewall\Middleware\Lfi::class,
            \Akaunting\Firewall\Middleware\Php::class,
            \Akaunting\Firewall\Middleware\Referrer::class,
            \Akaunting\Firewall\Middleware\Rfi::class,
            \Akaunting\Firewall\Middleware\Sqli::class,
            \Akaunting\Firewall\Middleware\Xss::class,
        ];
    }

    public function test_blocks_private_ip_addresses_in_configuration_test()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withoutMiddleware($this->getIgnoredMiddleware())
            ->postJson('/api/email-accounts/test-configuration', [
            'provider' => 'custom',
            'auth_type' => 'password',
            'email' => 'test@example.com',
            'username' => 'user',
            'password' => 'pass',
            'smtp_host' => '127.0.0.1', // Private IP
            'smtp_port' => 25,
            'smtp_encryption' => 'none',
            'imap_host' => '127.0.0.1', // Private IP
            'imap_port' => 143,
            'imap_encryption' => 'none',
        ]);

        $response->assertOk();
        $content = $response->json();

        // Assert that the request failed (success: false)
        $this->assertFalse($content['success'], 'Response should indicate failure');

        // Assert message indicates security block, not just connection failure
        // Without the fix, this might be "Connection refused"
        // With the fix, it should be "Access to private IP addresses is not allowed"
        $this->assertTrue(
            str_contains($content['message'], 'private IP') || str_contains($content['message'], 'blocked'),
            'Response message should indicate blocking of private IP. Actual: '.$content['message']
        );
    }

    public function test_blocks_localhost_resolution_in_configuration_test()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withoutMiddleware($this->getIgnoredMiddleware())
            ->postJson('/api/email-accounts/test-configuration', [
            'provider' => 'custom',
            'auth_type' => 'password',
            'email' => 'test@example.com',
            'username' => 'user',
            'password' => 'pass',
            'smtp_host' => 'localhost', // Resolves to 127.0.0.1
            'smtp_port' => 25,
            'smtp_encryption' => 'none',
            'imap_host' => 'localhost',
            'imap_port' => 143,
            'imap_encryption' => 'none',
        ]);

        $response->assertOk();
        $content = $response->json();

        $this->assertFalse($content['success'], 'Response should indicate failure');

        $this->assertTrue(
            str_contains($content['message'], 'private IP') || str_contains($content['message'], 'blocked'),
            'Response message should indicate blocking of localhost. Actual: '.$content['message']
        );
    }

    public function test_blocks_zero_host_bypass_in_configuration_test()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withoutMiddleware($this->getIgnoredMiddleware())
            ->postJson('/api/email-accounts/test-configuration', [
            'provider' => 'custom',
            'auth_type' => 'password',
            'email' => 'test@example.com',
            'username' => 'user',
            'password' => 'pass',
            'smtp_host' => '0', // Resolves to 0.0.0.0 (localhost equivalent)
            'smtp_port' => 25,
            'smtp_encryption' => 'none',
            'imap_host' => '0',
            'imap_port' => 143,
            'imap_encryption' => 'none',
        ]);

        $response->assertOk();
        $content = $response->json();

        $this->assertFalse($content['success'], 'Response should indicate failure for host "0"');

        $this->assertTrue(
            str_contains($content['message'], 'private IP') || str_contains($content['message'], 'blocked'),
            'Response message should indicate blocking of host "0". Actual: '.$content['message']
        );
    }
}
