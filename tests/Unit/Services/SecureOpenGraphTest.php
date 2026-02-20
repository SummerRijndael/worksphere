<?php

namespace Tests\Unit\Services;

use App\Services\SecureOpenGraph;
use ReflectionClass;
use Tests\TestCase;

class SecureOpenGraphTest extends TestCase
{
    public function test_it_blocks_private_ips()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, '127.0.0.1'));
        $this->assertTrue($method->invoke($service, '10.0.0.1'));
        $this->assertTrue($method->invoke($service, '192.168.1.1'));
        $this->assertTrue($method->invoke($service, '169.254.1.1'));
        $this->assertTrue($method->invoke($service, '::1'));
    }

    public function test_it_allows_public_ips()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, '8.8.8.8'));
        $this->assertFalse($method->invoke($service, '1.1.1.1'));
    }

    public function test_it_blocks_ipv4_mapped_ipv6_localhost()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        // Ensure IPv4-mapped IPv6 addresses (e.g., ::ffff:127.0.0.1) are blocked to prevent SSRF bypass
        $this->assertTrue($method->invoke($service, '::ffff:127.0.0.1'), '::ffff:127.0.0.1 should be blocked');
    }

    public function test_resolve_and_validate_blocks_ipv4_mapped_ipv6()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('resolveAndValidate');
        $method->setAccessible(true);

        // This checks if resolveAndValidate correctly handles the mapped IP
        $this->assertNull($method->invoke($service, 'http://[::ffff:127.0.0.1]'), 'URL with ::ffff:127.0.0.1 should be blocked/return null');
    }
}
