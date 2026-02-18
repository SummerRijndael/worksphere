<?php

namespace Tests\Unit\Services;

use App\Services\SecureOpenGraph;
use ReflectionClass;
use Tests\TestCase;

class SecureOpenGraphTest extends TestCase
{
    protected function getService()
    {
        return new SecureOpenGraph;
    }

    protected function isBlockedIp(SecureOpenGraph $service, string $ip)
    {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        return $method->invoke($service, $ip);
    }

    public function test_it_blocks_standard_private_ips()
    {
        $service = $this->getService();
        $this->assertTrue($this->isBlockedIp($service, '127.0.0.1'));
        $this->assertTrue($this->isBlockedIp($service, '192.168.1.1'));
        $this->assertTrue($this->isBlockedIp($service, '::1'));
    }

    public function test_it_blocks_ipv4_mapped_ipv6_addresses()
    {
        $service = $this->getService();
        // This is the IPv4-mapped IPv6 address for 127.0.0.1
        $ip = '::ffff:127.0.0.1';

        // Assert that it IS blocked (currently it returns false, so this should fail)
        $this->assertTrue($this->isBlockedIp($service, $ip), "IPv4-mapped IPv6 address {$ip} should be blocked");
    }
}
