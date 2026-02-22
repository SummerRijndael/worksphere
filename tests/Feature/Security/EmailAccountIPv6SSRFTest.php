<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Services\EmailAccountService;

class EmailAccountIPv6SSRFTest extends TestCase
{
    public function test_ipv4_mapped_ipv6_bypass()
    {
        $service = new EmailAccountService();
        $method = new \ReflectionMethod(EmailAccountService::class, 'ensureHostIsSafe');
        $method->setAccessible(true);

        // This should throw an exception if secured properly
        try {
            $method->invoke($service, '::ffff:127.0.0.1');
            $this->fail('SSRF vulnerability: ::ffff:127.0.0.1 was allowed');
        } catch (\Exception $e) {
            $this->assertEquals('Access to private IP addresses is not allowed.', $e->getMessage());
        }
    }

    public function test_private_ip_blocked()
    {
        $service = new EmailAccountService();
        $method = new \ReflectionMethod(EmailAccountService::class, 'ensureHostIsSafe');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Access to private IP addresses is not allowed.');

        $method->invoke($service, '127.0.0.1');
    }
}
