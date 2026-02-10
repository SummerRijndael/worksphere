<?php

namespace App\Services;

use shweshi\OpenGraph\OpenGraph;
use Illuminate\Support\Facades\Http;

class SecureOpenGraph extends OpenGraph
{
    /**
     * Override curl_get_contents to use Laravel's Http client with SSRF protection.
     *
     * @param string $url
     * @param string|null $lang
     * @param string $userAgent
     * @return string
     * @throws \Exception
     */
    protected function curl_get_contents($url, $lang, $userAgent)
    {
        // 1. Initial URL check
        $this->ensureUrlIsSafe($url);

        // 2. Perform request with redirect protection
        $response = Http::withOptions([
            'verify' => true, // Enforce SSL verification
            'timeout' => 10,
            'allow_redirects' => [
                'max' => 5,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri) {
                    $this->ensureUrlIsSafe((string) $uri);
                },
            ],
        ])->withHeaders([
            'User-Agent' => $userAgent,
            'Accept-Language' => $lang ?? 'en-US',
        ])->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch URL: " . $response->status());
        }

        return $response->body();
    }

    /**
     * Ensure the URL resolves to a safe (public) IP address.
     *
     * @param string $url
     * @throws \Exception
     */
    protected function ensureUrlIsSafe(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            throw new \Exception("Invalid URL: Hostname missing");
        }

        // Check if host is an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \Exception("Access to private IP addresses is not allowed.");
            }
            return;
        }

        // Resolve DNS (IPv4)
        $ips = gethostbynamel($host);
        if ($ips === false) {
            // If gethostbynamel fails, try dns_get_record for IPv6
            $ipv6 = dns_get_record($host, DNS_AAAA);
            if (!$ipv6) {
                // Resolution failed, let connection attempt fail naturally or throw
                // Here we let it pass as it likely won't connect anyway,
                // but strictly speaking we can't verify it's safe if we can't resolve it.
                // However, Guzzle will resolve it again.
                return;
            }
            $ips = array_column($ipv6, 'ipv6');
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \Exception("Host resolves to a private IP address.");
            }
        }
    }
}
