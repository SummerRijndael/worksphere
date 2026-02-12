<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

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
        '::1/128',          // Loopback
        '::/128',           // Unspecified
        'fc00::/7',         // Unique Local
        'fe80::/10',        // Link-local
        'fec0::/10',        // Site-local
        'ff00::/8',         // Multicast
        '2001:db8::/32',    // Documentation
        '::ffff:0:0/96',    // IPv4-mapped IPv6
        '64:ff9b::/96',     // IPv4-Embedded
    ];

    /**
     * Fetch OpenGraph data securely.
     */
    public function fetch(string $url): array
    {
        $maxRedirects = 5;
        $currentUrl = $url;
        
        for ($i = 0; $i < $maxRedirects; $i++) {
            // Resolve and Validate IP
            try {
                $safeIp = $this->getSafeIp($currentUrl);
            } catch (\Exception $e) {
                 throw new \Exception("Invalid or prohibited URL: " . $currentUrl . " (" . $e->getMessage() . ")");
            }

            $curl = curl_init($currentUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // Manual redirect handling
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
            curl_setopt($curl, CURLOPT_USERAGENT, 'WorkSphere Link Crawler / 1.0');
            
            // DNS Pinning: Force cURL to use the validated safe IP
            $parsed = parse_url($currentUrl);
            $host = $parsed['host'];
            $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);

            // Format for CURLOPT_RESOLVE: ["HOST:PORT:IP"]
            curl_setopt($curl, CURLOPT_RESOLVE, ["{$host}:{$port}:{$safeIp}"]);

            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                $error = curl_error($curl);
                curl_close($curl);
                throw new \Exception("cURL error: " . $error);
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $redirectUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            curl_close($curl);

            if ($httpCode >= 300 && $httpCode < 400 && $redirectUrl) {
                $currentUrl = $this->resolveRedirect($currentUrl, $redirectUrl);
                continue;
            }

            if ($httpCode !== 200) {
                throw new \Exception("Failed to fetch URL, HTTP Code: " . $httpCode);
            }

            return $this->parseOpenGraph($response, $currentUrl);
        }

        throw new \Exception("Too many redirects");
    }

    /**
     * Resolve hostname to a safe IP address.
     * Checks both IPv4 and IPv6 records and validates against blocked ranges.
     */
    protected function getSafeIp(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'], ['http', 'https'])) {
            throw new \Exception("Invalid URL structure or scheme");
        }

        $host = $parts['host'];

        // If host is an IP literal
        if (filter_var($host, FILTER_VALIDATE_IP)) {
             if (IpUtils::checkIp($host, $this->blockedRanges)) {
                 Log::warning("SSRF Attempt Blocked: IP {$host} is in blocked range");
                 throw new \Exception("Blocked IP address");
             }
             return $host;
        }

        // Resolve DNS
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        $ips = [];
        if ($records) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        // Fallback to gethostbynamel if dns_get_record returned nothing or failed
        if (empty($ips)) {
             $ipv4s = gethostbynamel($host);
             if ($ipv4s) {
                 $ips = array_merge($ips, $ipv4s);
             }
        }

        if (empty($ips)) {
             throw new \Exception("Could not resolve host");
        }

        foreach ($ips as $ip) {
            if (IpUtils::checkIp($ip, $this->blockedRanges)) {
                 Log::warning("SSRF Warning: Host {$host} resolves to blocked IP {$ip}");
                 continue; // Skip unsafe IPs
            }
            return $ip; // Return first safe IP
        }

        throw new \Exception("All resolved IPs are blocked");
    }

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
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (strpos($redirectUrl, '//') === 0) {
            return $scheme . ':' . $redirectUrl;
        }

        if (strpos($redirectUrl, '/') === 0) {
            return $scheme . '://' . $host . $port . $redirectUrl;
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = substr($path, 0, strrpos($path, '/') + 1);
        
        return $scheme . '://' . $host . $port . $path . $redirectUrl;
    }

    /**
     * Parse OpenGraph tags from HTML.
     */
    protected function parseOpenGraph(string $html, string $url): array
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // Suppress warnings for malformed HTML
        @$doc->loadHTML($html);
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
            if (!$tags['description'] && $name === 'description') {
                $tags['description'] = $content;
            }
        }

        // Fallback for title
        if (!$tags['title']) {
            $titleNodes = $doc->getElementsByTagName('title');
            if ($titleNodes->length > 0) {
                $tags['title'] = $titleNodes->item(0)->textContent;
            }
        }

        return $tags;
    }
}
