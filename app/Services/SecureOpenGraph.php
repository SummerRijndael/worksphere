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
        '::1/128', // Loopback
        '::/128', // Unspecified
        'fc00::/7', // Unique Local Address
        'fe80::/10', // Link-local
        'ff00::/8', // Multicast
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

            $curl = curl_init($currentUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // Manual redirect handling
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
            curl_setopt($curl, CURLOPT_USERAGENT, 'WorkSphere Link Crawler / 1.0');

            // Pin the resolved IP to prevent DNS rebinding attacks
            $parts = parse_url($currentUrl);
            $host = $parts['host'];
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            curl_setopt($curl, CURLOPT_RESOLVE, ["{$host}:{$port}:{$resolvedIp}"]);

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
     * Resolve and validate URL. Returns the safe IP to use.
     *
     * @throws \Exception
     */
    protected function resolveAndValidate(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['host']) || ! in_array($parts['scheme'], ['http', 'https'])) {
            throw new \Exception('Invalid URL scheme or format: '.$url);
        }

        $host = $parts['host'];

        // Resolve all IPs (A and AAAA)
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (empty($records)) {
            // Fallback to gethostbynamel for IPv4 if dns_get_record fails or returns empty
            // (though for localhost/hosts file entries gethostbynamel is better)
            $ipv4s = gethostbynamel($host);
            if ($ipv4s === false || empty($ipv4s)) {
                throw new \Exception('Could not resolve host: '.$host);
            }
            // Convert to consistent structure
            foreach ($ipv4s as $ip) {
                $records[] = ['type' => 'A', 'ip' => $ip];
            }
        }

        $validIps = [];

        foreach ($records as $record) {
            $ip = null;
            if (isset($record['ip'])) {
                $ip = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ip = $record['ipv6'];
            }

            if (! $ip) {
                continue;
            }

            if (IpUtils::checkIp($ip, $this->blockedRanges)) {
                Log::warning("SSRF Attempt Blocked: Host {$host} resolved to blocked IP {$ip}");
                throw new \Exception('Invalid or prohibited URL: '.$url);
            }

            $validIps[] = $ip;
        }

        if (empty($validIps)) {
            throw new \Exception('Could not resolve valid IP for host: '.$host);
        }

        // Return the first valid IP to be pinned
        return $validIps[0];
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
