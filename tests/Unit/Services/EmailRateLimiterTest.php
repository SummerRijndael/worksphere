<?php

namespace Tests\Unit\Services;

use App\Models\EmailAccount;
use App\Services\EmailRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EmailRateLimiterTest extends TestCase
{
    public function test_check_returns_zero_if_not_locked()
    {
        $limiter = new EmailRateLimiter;
        $account = new EmailAccount(['public_id' => 'abc', 'id' => 1, 'provider' => 'gmail']);
        $account->id = 1;

        Cache::shouldReceive('has')
            ->once()
            ->with('email_rate_limit:1:lockout')
            ->andReturn(false);

        $this->assertEquals(0, $limiter->check($account));
    }

    public function test_acquire_connection_fails_if_limit_reached()
    {
        $limiter = new EmailRateLimiter;
        $account = new EmailAccount(['public_id' => 'abc', 'id' => 1, 'provider' => 'gmail']);
        $account->id = 1;

        // Gmail limit is 2 connections
        Cache::shouldReceive('get')
            ->once()
            ->with('email_conn_count:1', 0)
            ->andReturn(2);

        // It triggers lockout logic
        Log::shouldReceive('warning');
        Cache::shouldReceive('has')->with('email_rate_limit:1:lockout')->andReturn(false);
        Cache::shouldReceive('put')->with('email_rate_limit:1:lockout', \Mockery::any(), 60);

        $this->assertFalse($limiter->acquireConnection($account));
    }

    public function test_acquire_connection_succeeds_if_limit_not_reached()
    {
        $limiter = new EmailRateLimiter;
        $account = new EmailAccount(['public_id' => 'abc', 'id' => 1, 'provider' => 'gmail']);
        $account->id = 1;

        Cache::shouldReceive('get')
            ->once()
            ->with('email_conn_count:1', 0)
            ->andReturn(1);

        Cache::shouldReceive('increment')
            ->once()
            ->with('email_conn_count:1');

        // No lockout trigger

        $this->assertTrue($limiter->acquireConnection($account));
    }
}
