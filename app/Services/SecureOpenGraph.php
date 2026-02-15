<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

class SecureOpenGraph
{
    /**
     * Private/Internal IP ranges to block.
     * Includes IPv4 and IPv6 private, reserved, loopback, and link-local ranges.
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
        'fe80::/10',        // Link-Local Unicast
        'ff00::/8',         // Multicast
        '2001:db8::/32',    // Documentation
        '::ffff:0:0/96',    // IPv4-mapped IPv6
        '64:ff9b::/96',     // IPv4-Embedded IPv6
    ];

    /**
     * Fetch OpenGraph data securely.
     */
    public function fetch(string $url): array
    {
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($i = 0; $i < $maxRedirects; $i++) {
            // Validate URL and Resolve IP safely
            try {
                $resolvedIp = $this->resolveAndValidate($currentUrl);
            } catch (\Exception $e) {
                throw new \Exception('Invalid or prohibited URL: '.$currentUrl.' ('.$e->getMessage().')');
            }

            $curl = curl_init($currentUrl);

            // Force curl to use the validated IP to prevent DNS Rebinding / TOCTOU
            $parts = parse_url($currentUrl);
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
     * Resolve hostname to IP and validate against blocked ranges.
     * Returns the first valid IP found.
     *
     * @throws \Exception If host cannot be resolved or resolves to a blocked IP.
     */
    protected function resolveAndValidate(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['host']) || ! in_array($parts['scheme'], ['http', 'https'])) {
            throw new \Exception('Invalid URL format or scheme');
        }

        $host = $parts['host'];

        // Handle IPv6 literals (e.g., [::1])
        $ipToCheck = $host;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipToCheck = substr($host, 1, -1);
        }

        // If host is already an IP, validate it directly
        if (filter_var($ipToCheck, FILTER_VALIDATE_IP)) {
            if (IpUtils::checkIp($ipToCheck, $this->blockedRanges)) {
                Log::warning("SSRF Attempt Blocked: Direct IP access to blocked IP {$host}");
                throw new \Exception('Blocked IP address');
            }

            return $ipToCheck;
        }

        // Resolve DNS (IPv4 and IPv6)
        // Suppress warnings as dns_get_record can be noisy on failure
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || empty($records)) {
            // Fallback to gethostbynamel for systems without working dns_get_record or for /etc/hosts entries
            $ips = @gethostbynamel($host);
            if ($ips === false || empty($ips)) {
                throw new \Exception('Could not resolve host');
            }
            // Normalize to record structure
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
                    throw new \Exception('Host resolved to blocked IP');
                }

                // Use the first valid IP we find
                if (! $validIp) {
                    $validIp = $ip;
                }
            }
        }

        if (! $validIp) {
            throw new \Exception('No valid IP addresses found');
        }

        return $validIp;
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
