<?php

namespace Tests\Feature\Security;

use App\Services\SecureOpenGraph;
use Tests\TestCase;

class SsrfTest extends TestCase
{
    public function test_blocks_localhost_ip()
    {
        $service = new SecureOpenGraph;

        $this->expectException(\Exception::class);
        // The message in current code is "Invalid or prohibited URL: <url>"
        // checking for partial match
        $this->expectExceptionMessage('Invalid or prohibited URL');

        $service->fetch('http://127.0.0.1');
    }

    public function test_blocks_private_ip()
    {
        $service = new SecureOpenGraph;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or prohibited URL');

        $service->fetch('http://192.168.1.1');
    }

    public function test_blocks_aws_metadata_ip()
    {
        $service = new SecureOpenGraph;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or prohibited URL');

        $service->fetch('http://169.254.169.254');
    }
}
