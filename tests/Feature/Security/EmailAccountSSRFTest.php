<?php

namespace Tests\Feature\Security;

use App\Services\EmailAccountService;
use ReflectionMethod;
use Tests\TestCase;
use Exception;

class EmailAccountSSRFTest extends TestCase
{
    /**
     * Test that IPv4-mapped IPv6 addresses are blocked by ensureHostIsSafe.
     */
    public function test_ipv4_mapped_ipv6_addresses_are_blocked()
    {
        $service = new EmailAccountService();
        $method = new ReflectionMethod(EmailAccountService::class, 'ensureHostIsSafe');
        $method->setAccessible(true);

        $ipv4Mapped = '::ffff:127.0.0.1';

        try {
            $method->invoke($service, $ipv4Mapped);
            $this->fail('IPv4-mapped IPv6 address was not blocked.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Access to private IP addresses is not allowed', $e->getMessage());
        }
    }

     /**
     * Test that standard IPv4 private addresses are blocked.
     */
    public function test_private_ipv4_addresses_are_blocked()
    {
        $service = new EmailAccountService();
        $method = new ReflectionMethod(EmailAccountService::class, 'ensureHostIsSafe');
        $method->setAccessible(true);

        try {
            $method->invoke($service, '127.0.0.1');
            $this->fail('127.0.0.1 was not blocked.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Access to private IP addresses is not allowed', $e->getMessage());
        }
    }

     /**
     * Test that standard IPv6 private addresses are blocked.
     */
    public function test_private_ipv6_addresses_are_blocked()
    {
        $service = new EmailAccountService();
        $method = new ReflectionMethod(EmailAccountService::class, 'ensureHostIsSafe');
        $method->setAccessible(true);

        try {
            $method->invoke($service, '::1');
            $this->fail('::1 was not blocked.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Access to private IP addresses is not allowed', $e->getMessage());
        }
    }
}
