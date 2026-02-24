<?php

namespace Tests\Unit\Services;

use App\Services\SecureOpenGraph;
use ReflectionClass;
use Tests\TestCase;

class SecureOpenGraphTest extends TestCase
{
    /**
     * @var SecureOpenGraph
     */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SecureOpenGraph();
    }

    /**
     * Helper to access protected methods via reflection.
     */
    protected function callProtectedMethod($methodName, array $args = [])
    {
        $class = new ReflectionClass(SecureOpenGraph::class);
        $method = $class->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }

    public function test_it_blocks_ipv4_private_addresses()
    {
        $this->assertTrue($this->callProtectedMethod('isBlockedIp', ['127.0.0.1']));
        $this->assertTrue($this->callProtectedMethod('isBlockedIp', ['10.0.0.1']));
        $this->assertTrue($this->callProtectedMethod('isBlockedIp', ['192.168.1.1']));
    }

    public function test_it_allows_public_ipv4_addresses()
    {
        $this->assertFalse($this->callProtectedMethod('isBlockedIp', ['8.8.8.8']));
        $this->assertFalse($this->callProtectedMethod('isBlockedIp', ['1.1.1.1']));
    }

    public function test_it_blocks_ipv6_loopback()
    {
        $this->assertTrue($this->callProtectedMethod('isBlockedIp', ['::1']));
    }

    public function test_it_blocks_ipv4_mapped_ipv6_addresses()
    {
        // Vulnerability test: ::ffff:127.0.0.1 should be blocked
        $this->assertTrue($this->callProtectedMethod('isBlockedIp', ['::ffff:127.0.0.1']));
        // Entire range check
        $this->assertTrue($this->callProtectedMethod('isBlockedIp', ['::ffff:10.0.0.1']));
        $this->assertTrue($this->callProtectedMethod('isBlockedIp', ['::ffff:192.168.1.1']));
    }
}
