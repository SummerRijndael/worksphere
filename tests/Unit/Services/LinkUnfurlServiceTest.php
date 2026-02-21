<?php

namespace Tests\Unit\Services;

use App\Enums\AuditAction;
use App\Models\BlockedUrl;
use App\Services\AuditService;
use App\Services\LinkUnfurlService;
use App\Services\SecureOpenGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class LinkUnfurlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $auditService;
    protected $secureOpenGraph;

    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = Mockery::mock(AuditService::class);
        $this->secureOpenGraph = Mockery::mock(SecureOpenGraph::class);
        $this->service = new LinkUnfurlService($this->auditService, $this->secureOpenGraph);
    }

    public function test_it_unfurls_valid_url()
    {
        $url = 'https://example.com';

        // Mock Cache::get to avoid network call
        Cache::shouldReceive('get')
            ->once()
            ->with('link_unfurl:'.md5($url))
            ->andReturn([
                'title' => 'Example',
                'url' => $url,
            ]);

        $result = $this->service->fetch($url);

        $this->assertEquals('Example', $result['title']);
    }

    public function test_it_throws_exception_for_blocked_url()
    {
        BlockedUrl::create(['pattern' => 'malicious.com']);
        $url = 'https://malicious.com/foo';

        // Should bypass cache check or mock it to return null
        Cache::shouldReceive('get')->andReturn(null);

        $this->auditService->shouldReceive('log')
            ->once()
            ->withArgs(function ($action) {
                return $action === AuditAction::LinkBlocked;
            });

        // Also mock audit service for the logBlock call which might happen before exception if structured that way
        // Actually logBlock calls auditService->log

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsafe_content_blocked');

        // Also need to mock Cache::put for the blocked cache
        Cache::shouldReceive('put');

        $this->service->fetch($url);
    }

    public function test_it_throws_exception_for_wildcard_blocked_url()
    {
        BlockedUrl::create(['pattern' => '*.unsafe.org']);
        $url = 'https://sub.unsafe.org/page';

        Cache::shouldReceive('get')->andReturn(null);

        $this->auditService->shouldReceive('log')
            ->once()
            ->withArgs(function ($action) {
                return $action === AuditAction::LinkBlocked;
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsafe_content_blocked');

        Cache::shouldReceive('put');

        $this->service->fetch($url);
    }

    public function test_it_throws_exception_for_regex_blocked_url()
    {
        Cache::shouldReceive('get')->andReturn(null);

        $this->auditService->shouldReceive('log')
            ->once()
            ->withArgs(function ($action, $category, $auditable, $user, $oldValues, $newValues, $context) {
                return $action === AuditAction::LinkBlocked
                    && isset($context['reason'])
                    && str_contains($context['reason'], 'Regex Safety Net');
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsafe_content_blocked');

        Cache::shouldReceive('put');

        $this->service->fetch('javascript:alert(1)');
    }
}
