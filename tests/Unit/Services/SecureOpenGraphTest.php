<?php

namespace Tests\Unit\Services;

use App\Services\SecureOpenGraph;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SecureOpenGraphTest extends TestCase
{
    /** @test */
    public function it_blocks_ipv4_mapped_ipv6_addresses_pointing_to_localhost()
    {
        $service = new SecureOpenGraph;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        // ::ffff:127.0.0.1 should be blocked now
        $this->assertTrue($method->invoke($service, '::ffff:127.0.0.1'));
    }

    /** @test */
    public function it_blocks_ipv4_mapped_ipv6_addresses_alternative_format()
    {
        $service = new SecureOpenGraph;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        // 0:0:0:0:0:ffff:7f00:1 is equivalent to ::ffff:127.0.0.1
        $this->assertTrue($method->invoke($service, '0:0:0:0:0:ffff:7f00:1'));
    }

    /** @test */
    public function it_blocks_standard_localhost_ipv4()
    {
        $service = new SecureOpenGraph;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, '127.0.0.1'));
    }

    /** @test */
    public function it_blocks_standard_localhost_ipv6()
    {
        $service = new SecureOpenGraph;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, '::1'));
    }
}
