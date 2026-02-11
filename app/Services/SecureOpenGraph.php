<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

class SecureOpenGraph
{
    /**
     * Private/Internal IP ranges to block.
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
        'fec0::/10',
        'ff00::/8',
        '2001:db8::/32',
        '::ffff:0:0/96',
    ];

    /**
     * Fetch OpenGraph data securely.
     */
    public function fetch(string $url): array
    {
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($i = 0; $i < $maxRedirects; $i++) {
            $parts = parse_url($currentUrl);
            if (! $parts || ! isset($parts['host']) || ! in_array($parts['scheme'], ['http', 'https'])) {
                throw new \Exception('Invalid or prohibited URL: '.$currentUrl);
            }

            $host = $parts['host'];
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

            // Resolve and Validate IP
            try {
                $validatedIp = $this->resolveAndValidate($host);
            } catch (\Exception $e) {
                throw new \Exception('Invalid or prohibited URL: '.$currentUrl.' - '.$e->getMessage());
            }

            $curl = curl_init($currentUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // Manual redirect handling
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
            curl_setopt($curl, CURLOPT_USERAGENT, 'WorkSphere Link Crawler / 1.0');

            // DNS Pinning: Force resolution to the validated IP to prevent DNS Rebinding (TOC/TOU)
            curl_setopt($curl, CURLOPT_RESOLVE, ["{$host}:{$port}:{$validatedIp}"]);

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
     * Resolve IPs for a host, validate them, and return one safe IP.
     */
    protected function resolveAndValidate(string $host): string
    {
        // Resolve all IPs (A and AAAA)
        $ips = $this->resolveIps($host);

        if (empty($ips)) {
            throw new \Exception("Could not resolve host: {$host}");
        }

        // Validate all IPs
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                Log::warning("SSRF Attempt Blocked: Host {$host} resolved to blocked IP {$ip}");
                throw new \Exception('Host resolved to blocked IP');
            }
        }

        // Return the first IP (safe to use as all are validated)
        return $ips[0];
    }

    /**
     * Validate URL and its resolved IP address.
     * Kept for backward compatibility/testing if needed.
     */
    protected function validateUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['host'])) {
            return false;
        }

        try {
            $this->resolveAndValidate($parts['host']);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Resolve IPs for a host.
     */
    protected function resolveIps(string $host): array
    {
        $ips = [];
        // Silence warnings just in case
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // Fallback for some systems where dns_get_record might fail or be disabled
        // or if dns_get_record returns empty but gethostbynamel might work (e.g. /etc/hosts)
        if (empty($ips)) {
            $fallback = gethostbynamel($host);
            if ($fallback) {
                $ips = array_merge($ips, $fallback);
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Check if an IP address is in a blocked range.
     */
    protected function isBlockedIp(string $ip): bool
    {
        return IpUtils::checkIp($ip, $this->blockedRanges);
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
