<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add security headers to all responses.
 *
 * These headers help protect against common web vulnerabilities:
 * - X-Content-Type-Options: Prevents MIME-type sniffing
 * - X-Frame-Options: Prevents clickjacking
 * - X-XSS-Protection: Legacy XSS protection
 * - Referrer-Policy: Controls referrer information
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Host Header Validation (Fixes Red Flag: Cloud Metadata Exposure)
        // Prevent Host Header Poisoning by ensuring the request matches our APP_URL domain
        $host = $request->getHost();
        $allowedHost = parse_url(config('app.url'), PHP_URL_HOST);
        
        // Also allow the actual local IP if scanning via IP
        $allowedIps = ['127.0.0.1', 'localhost', '192.168.37.128'];
        
        if ($host !== $allowedHost && !in_array($host, $allowedIps)) {
            // Block requests with malicious or unexpected Host headers
            return response()->json(['message' => 'Invalid Host header.'], 403);
        }

        $response = $next($request);

        // Prevent MIME-type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking - allow same origin framing only (redundant but safe)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Legacy XSS protection (modern browsers use CSP instead)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Control referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Add HSTS (Strict-Transport-Security) for 1 year
        // Note: Only effective over HTTPS
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // Remove server version disclosure
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        // Permissions Policy
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self), geolocation=(self), payment=(), autoplay=(self), unload=(self)');

        // Content Security Policy
        if (! $request->is('horizon*', 'pulse*')) {
            $this->addCspHeader($response);
        }

        return $response;
    }

    protected function addCspHeader(Response $response): void
    {
        $nonce = app(\App\Services\CSPService::class)->getNonce();

        // Allow unsafe-eval in local development for Vue DevTools / Vite HMR
        $scriptSrc = "'self' 'nonce-{$nonce}'";
        $connectSrc = "'self' https://rtc.live.cloudflare.com"; // Cloudflare Calls Origin
        $styleSrc = "'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com";
        $imgSrc = "'self' data: https: blob: cid:";
        $fontSrc = "'self' https://fonts.bunny.net https://fonts.gstatic.com data: blob:";

        // Vite Dev Server Handling
        if (app()->isLocal()) {
            $scriptSrc .= " 'unsafe-eval'";
            $imgSrc .= ' http: https:';
            $fontSrc .= " http: https: http://localhost:* http://127.0.0.1:*"; // Fallback for various local dev IPs

            // Check if Vite is running (hot file exists)
            $hotFile = public_path('hot');
            if (file_exists($hotFile)) {
                $viteUrl = trim(file_get_contents($hotFile));
                if ($viteUrl) {
                    $scriptSrc .= " {$viteUrl}";
                    $styleSrc .= " {$viteUrl}";
                    $fontSrc .= " {$viteUrl}";
                    $connectSrc .= ' ws://'.parse_url($viteUrl, PHP_URL_HOST).':'.parse_url($viteUrl, PHP_URL_PORT);
                    $connectSrc .= " {$viteUrl}";
                }
            }
        }

        // Reverb WebSocket Connection (Explicitly add Reverb Host)
        // We use the app URL's host or localhost, and the configured Reverb port
        $reverbHost = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $reverbPort = config('reverb.servers.reverb.port', 8080);
        // Also add the REVERB_PORT from .env if it differs (usually 9000)
        $envReverbPort = env('REVERB_PORT', 9000);

        $connectSrc .= " ws://{$reverbHost}:{$reverbPort} wss://{$reverbHost}:{$reverbPort}";
        $connectSrc .= " ws://localhost:{$reverbPort} ws://127.0.0.1:{$reverbPort}";
        
        if ($envReverbPort != $reverbPort) {
             $connectSrc .= " ws://{$reverbHost}:{$envReverbPort} wss://{$reverbHost}:{$envReverbPort}";
             $connectSrc .= " ws://localhost:{$envReverbPort} ws://127.0.0.1:{$envReverbPort}";
        }

        // Definitions
        $policy = [
            "default-src 'self'",
            "script-src {$scriptSrc} 'wasm-unsafe-eval' https://www.google.com https://www.gstatic.com https://cdn.jsdelivr.net https://storage.googleapis.com https://static.cloudflareinsights.com",
            "script-src-elem {$scriptSrc} https://www.google.com https://www.gstatic.com https://cdn.jsdelivr.net https://storage.googleapis.com https://static.cloudflareinsights.com",
            "style-src {$styleSrc}",
            "font-src {$fontSrc}",
            "img-src {$imgSrc}",
            "connect-src {$connectSrc} https://www.google.com https://cdn.jsdelivr.net https://storage.googleapis.com https://static.cloudflareinsights.com",
            "frame-src 'self' https://www.google.com https://www.gstatic.com",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $policy));
    }
}
