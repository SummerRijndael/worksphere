<?php

namespace Tests\Feature\Security;

use App\Services\SecureOpenGraph;
use Tests\TestCase;

class SecureOpenGraphTest extends TestCase
{
    /**
     * @test
     */
    public function it_blocks_private_ipv4_addresses()
    {
        $service = new SecureOpenGraph;

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveAndValidate');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Blocked IP address');
        $method->invoke($service, 'http://127.0.0.1');
    }

    /**
     * @test
     */
    public function it_blocks_another_private_ipv4_address()
    {
        $service = new SecureOpenGraph;

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveAndValidate');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $method->invoke($service, 'http://192.168.1.1');
    }

    /**
     * @test
     */
    public function it_blocks_private_ipv6_addresses()
    {
        $service = new SecureOpenGraph;

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveAndValidate');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Blocked IP address');
        $method->invoke($service, 'http://[::1]');
    }

    /**
     * @test
     */
    public function it_blocks_ipv6_link_local_addresses()
    {
        $service = new SecureOpenGraph;

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveAndValidate');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Blocked IP address');
        $method->invoke($service, 'http://[fe80::1]');
    }

    /**
     * @test
     */
    public function it_allows_public_domains()
    {
        $service = new SecureOpenGraph;

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveAndValidate');
        $method->setAccessible(true);

        // This assumes example.com is resolvable and not blocked
        $ip = $method->invoke($service, 'http://example.com');
        $this->assertNotEmpty($ip);
        $this->assertTrue(filter_var($ip, FILTER_VALIDATE_IP) !== false);
    }
}
