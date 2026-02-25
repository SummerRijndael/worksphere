<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SecureOpenGraph
{
    /**
     * Private/Internal IP ranges to block (IPv4 and IPv6).
     */
    protected array $blockedRanges = [
        // IPv4
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
        // IPv6
        '::1/128',
        '::/128',
        'fc00::/7',
        'fe80::/10',
        '::ffff:0:0/96', // IPv4-mapped IPv6
        '2002::/16', // 6to4
        '64:ff9b::/96', // NAT64
        '100::/64', // Discard
    ];

    /**
     * Fetch OpenGraph data securely.
     */
    public function fetch(string $url): array
    {
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($i = 0; $i < $maxRedirects; $i++) {
            $resolvedIp = $this->resolveAndValidate($currentUrl);

            if (! $resolvedIp) {
                throw new \Exception('Invalid or prohibited URL: '.$currentUrl);
            }

            $curl = curl_init($currentUrl);

            // Force curl to use the validated IP to prevent DNS Rebinding / TOCTOU
            $parts = parse_url($currentUrl);
            if (! $parts || ! isset($parts['host'])) {
                throw new \Exception('Malformed URL');
            }

            $host = $parts['host'];
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            curl_setopt($curl, CURLOPT_RESOLVE, ["{$host}:{$port}:{$resolvedIp}"]);

            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // Manual redirect handling
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
            curl_setopt($curl, CURLOPT_USERAGENT, 'WorkSphere Link Crawler / 1.0');

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $redirectUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            curl_close($curl);

            if ($httpCode >= 300 && $httpCode < 400 && $redirectUrl) {
                $currentUrl = $this->resolveRedirect($currentUrl, $redirectUrl);

                continue;
            }

            if ($httpCode !== 200) {
                throw new \Exception('Failed to fetch URL, HTTP Code: '.$httpCode);
            }

            return $this->parseOpenGraph($response, $currentUrl);
        }

        throw new \Exception('Too many redirects');
    }

    /**
     * Resolve URL to an IP and validate it.
     * Returns the valid IP string or null.
     */
    protected function resolveAndValidate(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['host']) || ! in_array($parts['scheme'], ['http', 'https'])) {
            return null;
        }

        $host = $parts['host'];

        // If host is already an IP, validate directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host) ? null : $host;
        }

        // Resolve DNS (IPv4 and IPv6)
        $ips = [];

        // IPv4
        $dnsA = dns_get_record($host, DNS_A);
        if ($dnsA) {
            foreach ($dnsA as $record) {
                $ips[] = $record['ip'];
            }
        }

        // IPv6
        $dnsAAAA = dns_get_record($host, DNS_AAAA);
        if ($dnsAAAA) {
            foreach ($dnsAAAA as $record) {
                $ips[] = $record['ipv6'];
            }
        }

        if (empty($ips)) {
            // Fallback
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                $ips[] = $ip;
            }
        }

        if (empty($ips)) {
            return null;
        }

        // Validate ALL resolved IPs
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                Log::warning("SSRF Attempt Blocked: Host {$host} resolved to blocked IP {$ip}");

                return null; // Block if ANY IP is bad
            }
        }

        // All good, return the first one for pinning
        return $ips[0];
    }

    /**
     * Check if an IP address is in a blocked range using IpUtils.
     */
    protected function isBlockedIp(string $ip): bool
    {
        return \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $this->blockedRanges);
    }

    // Legacy ipInRage removed in favor of IpUtils

    /**
     * Resolve relative redirect URLs.
     */
    protected function resolveRedirect(string $baseUrl, string $redirectUrl): string
    {
        if (parse_url($redirectUrl, PHP_URL_SCHEME) != '') {
            return $redirectUrl;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (strpos($redirectUrl, '//') === 0) {
            return $scheme.':'.$redirectUrl;
        }

        if (strpos($redirectUrl, '/') === 0) {
            return $scheme.'://'.$host.$port.$redirectUrl;
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = substr($path, 0, strrpos($path, '/') + 1);

        return $scheme.'://'.$host.$port.$path.$redirectUrl;
    }

    /**
     * Parse OpenGraph tags from HTML.
     */
    protected function parseOpenGraph(string $html, string $url): array
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML($html);
        libxml_clear_errors();

        $tags = [
            'title' => '',
            'description' => '',
            'image' => '',
            'url' => $url,
            'site_name' => '',
        ];

        // Try OpenGraph tags first
        $metas = $doc->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            $property = $meta->getAttribute('property');
            $content = $meta->getAttribute('content');

            if (strpos($property, 'og:') === 0) {
                $key = substr($property, 3);
                if (array_key_exists($key, $tags)) {
                    $tags[$key] = $content;
                }
            }

            // Fallback for some common names
            $name = $meta->getAttribute('name');
            if (! $tags['description'] && $name === 'description') {
                $tags['description'] = $content;
            }
        }

        // Fallback for title
        if (! $tags['title']) {
            $titleNodes = $doc->getElementsByTagName('title');
            if ($titleNodes->length > 0) {
                $tags['title'] = $titleNodes->item(0)->textContent;
            }
        }

        return $tags;
    }
}
