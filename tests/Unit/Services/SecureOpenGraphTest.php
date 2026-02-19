<?php

namespace Tests\Unit\Services;

use App\Services\SecureOpenGraph;
use ReflectionClass;
use Tests\TestCase;

class SecureOpenGraphTest extends TestCase
{
    /**
     * Test that isBlockedIp correctly identifies blocked IP addresses,
     * including IPv4-mapped IPv6 addresses.
     */
    public function test_is_blocked_ip_blocks_private_ranges()
    {
        $service = new SecureOpenGraph();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        // IPv4 Private Ranges
        $this->assertTrue($method->invoke($service, '127.0.0.1'), '127.0.0.1 should be blocked');
        $this->assertTrue($method->invoke($service, '10.0.0.1'), '10.0.0.1 should be blocked');
        $this->assertTrue($method->invoke($service, '192.168.1.1'), '192.168.1.1 should be blocked');
        $this->assertTrue($method->invoke($service, '169.254.169.254'), '169.254.169.254 should be blocked');

        // IPv6 Private Ranges
        $this->assertTrue($method->invoke($service, '::1'), '::1 should be blocked');
        $this->assertTrue($method->invoke($service, 'fe80::1'), 'fe80::1 should be blocked');

        // IPv4-mapped IPv6 Private Ranges (This is the vulnerability target)
        $this->assertTrue($method->invoke($service, '::ffff:127.0.0.1'), '::ffff:127.0.0.1 should be blocked');
        $this->assertTrue($method->invoke($service, '::ffff:10.0.0.1'), '::ffff:10.0.0.1 should be blocked');
        $this->assertTrue($method->invoke($service, '::ffff:192.168.1.1'), '::ffff:192.168.1.1 should be blocked');

        // Public IPs should NOT be blocked
        $this->assertFalse($method->invoke($service, '8.8.8.8'), '8.8.8.8 should NOT be blocked');
        $this->assertFalse($method->invoke($service, '1.1.1.1'), '1.1.1.1 should NOT be blocked');
        $this->assertFalse($method->invoke($service, '2606:4700:4700::1111'), '2606:4700:4700::1111 should NOT be blocked');
    }
}
