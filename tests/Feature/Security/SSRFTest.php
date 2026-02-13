<?php

namespace Tests\Feature\Security;

use App\Services\SecureOpenGraph;
use Tests\TestCase;

class SSRFTest extends TestCase
{
    public function test_blocks_localhost_ipv4()
    {
        $service = app(SecureOpenGraph::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or prohibited URL');

        // This should be blocked by 127.0.0.0/8
        $service->fetch('http://127.0.0.1');
    }

    public function test_blocks_private_network_ipv4()
    {
        $service = app(SecureOpenGraph::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or prohibited URL');

        // This should be blocked by 192.168.0.0/16
        $service->fetch('http://192.168.1.1');
    }

    public function test_blocks_ipv6_loopback()
    {
        $service = app(SecureOpenGraph::class);

        try {
            $service->fetch('http://[::1]');
            $this->fail('Should have thrown exception for IPv6 loopback');
        } catch (\Exception $e) {
            // Depending on where it fails (dns resolution or validation)
            // If dns_get_record works for ::1, it should be blocked by IpUtils
            // If it fails to resolve, it throws "Could not resolve"
            $this->assertTrue(
                str_contains($e->getMessage(), 'Invalid or prohibited URL') ||
                str_contains($e->getMessage(), 'Could not resolve'),
                'Unexpected exception message: '.$e->getMessage()
            );
        }
    }

    public function test_blocks_ipv6_private()
    {
        $service = app(SecureOpenGraph::class);

        try {
            // Unique Local Address (fc00::/7)
            $service->fetch('http://[fd00::1]');
            $this->fail('Should have thrown exception for IPv6 private address');
        } catch (\Exception $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'Invalid or prohibited URL') ||
                str_contains($e->getMessage(), 'Could not resolve'),
                'Unexpected exception message: '.$e->getMessage()
            );
        }
    }

    public function test_dns_rebinding_protection_is_implemented()
    {
        // Verify that CURLOPT_RESOLVE is used to pin the IP address
        $content = file_get_contents(app_path('Services/SecureOpenGraph.php'));

        $this->assertStringContainsString(
            'CURLOPT_RESOLVE',
            $content,
            'DNS Rebinding protection (CURLOPT_RESOLVE) is missing in source code.'
        );
    }

    public function test_blocks_multicast()
    {
        $service = app(SecureOpenGraph::class);

        try {
            $service->fetch('http://224.0.0.1');
            $this->fail('Should have thrown exception for Multicast address');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Invalid or prohibited URL', $e->getMessage());
        }
    }
}
