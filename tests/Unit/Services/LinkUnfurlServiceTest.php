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

    protected $openGraph;

    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = Mockery::mock(AuditService::class);
        $this->openGraph = Mockery::mock(SecureOpenGraph::class);
        $this->service = new LinkUnfurlService($this->auditService, $this->openGraph);
    }

    public function test_it_unfurls_valid_url()
    {
        $url = 'https://example.com';
        $cacheKey = 'link_unfurl:'.md5($url);

        Cache::shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn(null);

        $this->openGraph->shouldReceive('fetch')
            ->once()
            ->with($url)
            ->andReturn([
                'title' => 'Example',
                'description' => 'Desc',
                'image' => 'img.jpg',
                'url' => $url,
                'site_name' => 'Site',
            ]);

        Cache::shouldReceive('put')
            ->once()
            ->with($cacheKey, Mockery::type('array'), Mockery::any());

        $this->auditService->shouldReceive('log')
            ->once()
            ->withArgs(function ($action) {
                return $action === AuditAction::LinkUnfurled;
            });

        $result = $this->service->fetch($url);

        $this->assertEquals('Example', $result['title']);
    }

    public function test_it_throws_exception_for_blocked_url()
    {
        BlockedUrl::create(['pattern' => 'malicious.com']);
        $url = 'https://malicious.com/foo';

        $this->auditService->shouldReceive('log')
            ->once()
            ->withArgs(function ($action) {
                return $action === AuditAction::LinkBlocked;
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsafe_content_blocked');

        $this->service->fetch($url);
    }

    public function test_it_throws_exception_for_wildcard_blocked_url()
    {
        BlockedUrl::create(['pattern' => '*.unsafe.org']);
        $url = 'https://sub.unsafe.org/page';

        $this->auditService->shouldReceive('log')
            ->once()
            ->withArgs(function ($action) {
                return $action === AuditAction::LinkBlocked;
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsafe_content_blocked');

        $this->service->fetch($url);
    }

    public function test_it_throws_exception_for_regex_blocked_url()
    {
        $this->auditService->shouldReceive('log')
            ->once()
            ->withArgs(function ($action, $category, $auditable, $user, $oldValues, $newValues, $context) {
                return $action === AuditAction::LinkBlocked
                    && isset($context['reason'])
                    && str_contains($context['reason'], 'Regex Safety Net');
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsafe_content_blocked');

        $this->service->fetch('javascript:alert(1)');
    }
}
