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
    ];

    /**
     * Fetch OpenGraph data securely.
     */
    public function fetch(string $url): array
    {
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($i = 0; $i < $maxRedirects; $i++) {
            $validated = $this->resolveAndValidate($currentUrl);
            if (! $validated) {
                throw new \Exception('Invalid or prohibited URL: '.$currentUrl);
            }

            [$resolvedIp, $port] = $validated;

            // Build the URL parts to extract host for CURLOPT_RESOLVE
            $parts = parse_url($currentUrl);
            $host = $parts['host'];

            $curl = curl_init($currentUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // Manual redirect handling
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
            curl_setopt($curl, CURLOPT_USERAGENT, 'WorkSphere Link Crawler / 1.0');

            // Force resolution to the validated IP to prevent DNS Rebinding (TOCTOU)
            // Format: array("example.com:443:10.0.0.1")
            $resolveHost = sprintf('%s:%d:%s', $host, $port, $resolvedIp);
            curl_setopt($curl, CURLOPT_RESOLVE, [$resolveHost]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $redirectUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            $error = curl_error($curl);
            curl_close($curl);

            if ($error) {
                // Don't leak detail, but log it
                Log::error("Curl error fetching $currentUrl: $error");
                throw new \Exception('Failed to fetch URL');
            }

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
     * Validate URL and return resolved IP and port.
     *
     * @return array|false [ip, port] or false on failure
     */
    protected function resolveAndValidate(string $url)
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['host']) || ! in_array($parts['scheme'], ['http', 'https'])) {
            return false;
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        // Check if host is already an IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (IpUtils::checkIp($host, $this->blockedRanges)) {
                Log::warning("SSRF Attempt Blocked: Host {$host} is a blocked IP");

                return false;
            }

            return [$host, $port];
        }

        // Resolve DNS (A and AAAA)
        // Use dns_get_record for comprehensive resolution
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || empty($records)) {
            // Fallback to gethostbynamel if dns_get_record fails
            $ips = @gethostbynamel($host);
            if ($ips === false || empty($ips) || $ips === [$host]) {
                Log::warning("SSRF: Failed to resolve host {$host}");

                return false;
            }
            // Convert to uniform format
            $records = [];
            foreach ($ips as $ip) {
                $records[] = ['type' => 'A', 'ip' => $ip];
            }
        }

        $validIp = null;

        foreach ($records as $record) {
            $ip = null;
            if (isset($record['ip'])) {
                $ip = $record['ip']; // A record
            } elseif (isset($record['ipv6'])) {
                $ip = $record['ipv6']; // AAAA record
            }

            if ($ip) {
                if (IpUtils::checkIp($ip, $this->blockedRanges)) {
                    Log::warning("SSRF Attempt Blocked: Host {$host} resolved to blocked IP {$ip}");

                    // If any IP is blocked, we skip it.
                    continue;
                }

                // Found a valid IP, use the first one
                if (! $validIp) {
                    $validIp = $ip;
                    // We could break here, but checking all IPs for logging purposes is also fine.
                    // For performance, we break.
                    break;
                }
            }
        }

        if (! $validIp) {
            Log::warning("SSRF: No valid IPs found for host {$host}");

            return false;
        }

        return [$validIp, $port];
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
