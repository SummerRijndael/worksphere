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
        $response = $next($request);

        // Prevent MIME-type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking - allow same origin framing only
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Legacy XSS protection (modern browsers use CSP instead)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Control referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Remove server version disclosure
        $response->headers->remove('X-Powered-By');

        // Permissions Policy (formerly Feature Policy)
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Content Security Policy
        $this->addCspHeader($response);

        return $response;
    }

    protected function addCspHeader(Response $response): void
    {
        $nonce = app(\App\Services\CSPService::class)->getNonce();

        // Allow unsafe-eval in local development for Vue DevTools / Vite HMR
        $scriptSrc = "'self' 'nonce-{$nonce}'";
        $connectSrc = "'self'";
        $styleSrc = "'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com";

        // Vite Dev Server Handling
        if (app()->isLocal()) {
            $scriptSrc .= " 'unsafe-eval'";

            // Check if Vite is running (hot file exists)
            $hotFile = public_path('hot');
            if (file_exists($hotFile)) {
                $viteUrl = trim(file_get_contents($hotFile));
                if ($viteUrl) {
                    // Normalize URL to just origin if needed, or simple add
                    $scriptSrc .= " {$viteUrl}";
                    $styleSrc .= " {$viteUrl}";
                    // Websocket connection for HMR (ws://...)
                    $connectSrc .= ' ws://'.parse_url($viteUrl, PHP_URL_HOST).':'.parse_url($viteUrl, PHP_URL_PORT);
                    $connectSrc .= " {$viteUrl}";
                }
            }
        }

        // Definitions
        $policy = [
            "default-src 'self'",
            "script-src {$scriptSrc} https://www.google.com https://www.gstatic.com",
            // Unsafe-inline for styles is required by many UI libraries (Vue/Tailwind components)
            // Fonts.bunny.net is used for Inter font
            "style-src {$styleSrc}",
            // Allow data: fonts (often used by icon sets or inline fonts)
            "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
            // Allow images from self, data URIs (base64), and S3/R2 (https)
            "img-src 'self' data: https: blob:",
            // Connect to self, Vite HMR, and Reverb WebSockets (port 9000 usually)
            // Adding ws: and wss: schemes generally to allow websocket connections
            "connect-src {$connectSrc} ws: wss: https://www.google.com",
            // Frame src for reCAPTCHA
            "frame-src 'self' https://www.google.com https://www.gstatic.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $policy));
    }
}
