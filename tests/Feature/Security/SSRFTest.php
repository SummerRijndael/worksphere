<?php

namespace Tests\Feature\Security;

use App\Services\EmailAccountService;
use App\Services\SecureOpenGraph;
use ReflectionClass;
use Tests\TestCase;

class SSRFTest extends TestCase
{
    /** @test */
    public function secure_open_graph_blocks_ipv4_mapped_ipv6_addresses()
    {
        $service = new SecureOpenGraph;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isBlockedIp');
        $method->setAccessible(true);

        // IPv4-mapped IPv6 for localhost
        $this->assertTrue($method->invoke($service, '::ffff:127.0.0.1'), '::ffff:127.0.0.1 should be blocked');
        $this->assertTrue($method->invoke($service, '::ffff:7f00:1'), '::ffff:7f00:1 should be blocked');

        // IPv4-mapped IPv6 for private network 192.168.1.1
        $this->assertTrue($method->invoke($service, '::ffff:c0a8:0101'), '::ffff:c0a8:0101 should be blocked');
        $this->assertTrue($method->invoke($service, '::ffff:192.168.1.1'), '::ffff:192.168.1.1 should be blocked');
    }

    /** @test */
    public function email_account_service_blocks_ipv4_mapped_ipv6_addresses()
    {
        $service = new EmailAccountService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('ensureHostIsSafe');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Access to IPv4-mapped IPv6 addresses is not allowed');

        // This should throw an exception
        $method->invoke($service, '::ffff:127.0.0.1');
    }
}
