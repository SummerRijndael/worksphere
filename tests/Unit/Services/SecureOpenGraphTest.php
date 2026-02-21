<?php

namespace Tests\Unit\Services;

use App\Services\SecureOpenGraph;
use Tests\TestCase;

class SecureOpenGraphTest extends TestCase
{
    public function test_it_blocks_ipv4_mapped_ipv6_loopback()
    {
        $service = new SecureOpenGraph();

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        // This IP maps to 127.0.0.1 which should be blocked
        $ip = '::ffff:127.0.0.1';

        $isBlocked = $method->invoke($service, $ip);

        $this->assertTrue($isBlocked, "IPv4-mapped IPv6 loopback address should be blocked");
    }

    public function test_it_blocks_standard_loopback()
    {
        $service = new SecureOpenGraph();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, '127.0.0.1'));
        $this->assertTrue($method->invoke($service, '::1'));
    }

    public function test_it_allows_public_ips()
    {
        $service = new SecureOpenGraph();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, '8.8.8.8'));
        $this->assertFalse($method->invoke($service, '2606:4700:4700::1111'));
    }
}
