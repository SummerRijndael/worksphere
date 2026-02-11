<?php

namespace Tests\Feature\Security;

use App\Services\SecureOpenGraph;
use Tests\TestCase;

class LinkUnfurlSSRFTest extends TestCase
{
    /**
     * Test that IPv6 addresses are correctly handled and blocked if private.
     * This verifies the fix for IPv6 SSRF bypass and accidental blocking bugs.
     */
    public function test_ipv6_handling()
    {
        $service = new class extends SecureOpenGraph
        {
            // Expose protected methods for testing
            public function exposedValidateUrl($url)
            {
                return $this->validateUrl($url);
            }

            public function exposedIsBlockedIp($ip)
            {
                return $this->isBlockedIp($ip);
            }

            // Mock resolveIps to return controlled IPs
            public $mockIps = [];

            protected function resolveIps(string $host): array
            {
                return $this->mockIps;
            }
        };

        // 1. Verify Private IPv6 is BLOCKED (e.g. Loopback)
        $this->assertTrue($service->exposedIsBlockedIp('::1'), 'Localhost IPv6 (::1) should be blocked');

        // 2. Verify Link-Local IPv6 is BLOCKED
        $this->assertTrue($service->exposedIsBlockedIp('fe80::1'), 'Link-local IPv6 (fe80::1) should be blocked');

        // 3. Verify Public IPv6 is ALLOWED
        // Google's IPv6: 2001:4860:4860::8888
        $this->assertFalse($service->exposedIsBlockedIp('2001:4860:4860::8888'), 'Public IPv6 (2001:4860:4860::8888) should be allowed');

        // 4. Verify that validateUrl respects the blocking
        // Case A: Host resolves to blocked IPv6 (::1)
        $service->mockIps = ['::1'];
        $this->assertFalse($service->exposedValidateUrl('http://localhost-ipv6.com'), 'Should fail if host resolves to ::1');

        // Case B: Host resolves to allowed IPv6
        $service->mockIps = ['2001:4860:4860::8888'];
        $this->assertTrue($service->exposedValidateUrl('http://google-ipv6.com'), 'Should pass if host resolves to public IPv6');

        // Case C: Host resolves to mixed (one blocked, one allowed) -> Should FAIL (fail closed)
        $service->mockIps = ['2001:4860:4860::8888', '::1'];
        $this->assertFalse($service->exposedValidateUrl('http://mixed.com'), 'Should fail if ANY resolved IP is blocked');
    }
}
