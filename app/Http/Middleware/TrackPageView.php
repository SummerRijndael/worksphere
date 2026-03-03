<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackPageView
{
    public function __construct(
        protected \App\Services\AnalyticsTracker $tracker
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Don't track if not a successful page load (or redirect)
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        // Filter out non-HTML response types explicitly
        if ($response instanceof \Illuminate\Http\JsonResponse ||
            $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ||
            $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return $response;
        }

        // Track page view
        if ($this->shouldTrack($request)) {
            $this->tracker->trackRequest($request);
        }

        return $response;
    }

    /**
     * Determine if the request should be tracked based on common asset extensions.
     */
    protected function shouldTrack(Request $request): bool
    {
        $path = $request->path();
        $ignoredExtensions = [
            '.map', '.js', '.css', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', 
            '.json', '.txt', '.xml', '.webmanifest', '.woff', '.woff2', '.ttf'
        ];

        foreach ($ignoredExtensions as $ext) {
            if (str_ends_with(strtolower($path), $ext)) {
                return false;
            }
        }

        return true;
    }
}
