<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackPageView;
use App\Jobs\ProcessAnalyticsJob;
use App\Models\PageView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('analytics.ignore_ips', []);
        Config::set('analytics.ignore_admins', false);
    }

    public function test_middleware_tracks_html_responses()
    {
        $request = Request::create('/some-page', 'GET');
        $middleware = new TrackPageView;

        $middleware->handle($request, function () {
            return response('<html><body>Hello</body></html>', 200, ['Content-Type' => 'text/html']);
        });

        Queue::assertPushed(ProcessAnalyticsJob::class, function ($job) {
            return $job->data['path'] === 'some-page';
        });
    }

    public function test_middleware_ignores_json_responses()
    {
        $request = Request::create('/api/data', 'GET');
        $middleware = new TrackPageView;

        $middleware->handle($request, function () {
            return response()->json(['foo' => 'bar']);
        });

        Queue::assertNotPushed(ProcessAnalyticsJob::class);
    }

    public function test_middleware_ignores_asset_responses()
    {
        $request = Request::create('/image.png', 'GET');
        $middleware = new TrackPageView;

        $middleware->handle($request, function () {
            return response('binary-data', 200, ['Content-Type' => 'image/png']);
        });

        Queue::assertNotPushed(ProcessAnalyticsJob::class);
    }

    public function test_middleware_ignores_streamed_responses()
    {
        $request = Request::create('/stream', 'GET');
        $middleware = new TrackPageView;

        $middleware->handle($request, function () {
            return new \Symfony\Component\HttpFoundation\StreamedResponse(function () {
                echo 'stream';
            });
        });

        Queue::assertNotPushed(ProcessAnalyticsJob::class);
    }

    public function test_service_ignores_internal_referrers()
    {
        $service = new \App\Services\AnalyticsService;

        // 1. External
        PageView::create([
            'session_id' => 'abc',
            'url' => 'http://localhost/home',
            'path' => '/home',
            'method' => 'GET',
            'referer' => 'https://google.com',
            'created_at' => now(),
            'ip_address' => '127.0.0.1',
        ]);

        // 2. Internal (Self)
        PageView::create([
            'session_id' => 'abc',
            'url' => 'http://localhost/about',
            'path' => '/about',
            'method' => 'GET',
            'referer' => config('app.url'),
            'created_at' => now(),
            'ip_address' => '127.0.0.1',
        ]);

        // 3. Null referer
        PageView::create([
            'session_id' => 'abc',
            'url' => 'http://localhost/contact',
            'path' => '/contact',
            'method' => 'GET',
            'referer' => null,
            'created_at' => now(),
            'ip_address' => '127.0.0.1',
        ]);

        $sources = $service->getTrafficSources('7d');

        // Should only have google.com, filtering out localhost
        $this->assertCount(1, $sources);
        $this->assertEquals('google.com', $sources[0]['source']);
    }
}
