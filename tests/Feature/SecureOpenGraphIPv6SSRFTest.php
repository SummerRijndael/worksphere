<?php

namespace Tests\Feature;

use App\Services\SecureOpenGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecureOpenGraphIPv6SSRFTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_ipv4_mapped_ipv6_addresses_in_open_graph()
    {
        $service = new SecureOpenGraph();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or prohibited URL: http://[::ffff:127.0.0.1]/test');

        // This will attempt to fetch from the blocked IP and should throw an exception
        $service->fetch('http://[::ffff:127.0.0.1]/test');
    }
}
