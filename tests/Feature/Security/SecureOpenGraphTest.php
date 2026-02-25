<?php

namespace Tests\Feature\Security;

use App\Services\SecureOpenGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use ReflectionClass;

class SecureOpenGraphTest extends TestCase
{
    /**
     * Test that IPv4-mapped IPv6 addresses are blocked.
     *
     * @return void
     */
    public function test_blocks_ipv4_mapped_ipv6_addresses()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        // IPv4-mapped IPv6 for 127.0.0.1
        $mappedIp = '::ffff:127.0.0.1';

        // Should return true (blocked)
        $isBlocked = $method->invoke($service, $mappedIp);

        $this->assertTrue($isBlocked, "Failed to block IPv4-mapped IPv6 address: {$mappedIp}");
    }

    /**
     * Test that standard private IPs are blocked.
     *
     * @return void
     */
    public function test_blocks_standard_private_ips()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $ips = [
            '127.0.0.1',
            '192.168.1.1',
            '10.0.0.5',
            '::1',
        ];

        foreach ($ips as $ip) {
            $isBlocked = $method->invoke($service, $ip);
            $this->assertTrue($isBlocked, "Failed to block private IP: {$ip}");
        }
    }

    /**
     * Test that public IPs are allowed.
     *
     * @return void
     */
    public function test_allows_public_ips()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $ips = [
            '8.8.8.8',
            '1.1.1.1',
            '2606:4700:4700::1111',
        ];

        foreach ($ips as $ip) {
            $isBlocked = $method->invoke($service, $ip);
            $this->assertFalse($isBlocked, "Incorrectly blocked public IP: {$ip}");
        }
    }
}
