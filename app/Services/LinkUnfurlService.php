<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Models\BlockedUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use shweshi\OpenGraph\OpenGraph;

class LinkUnfurlService
{
    protected OpenGraph $openGraph;

    public function __construct(
        protected AuditService $auditService
    ) {
        $this->openGraph = new OpenGraph;
    }

    /**
     * Fetch OpenGraph data for a URL.
     *
     * @throws \Exception
     */
    /**
     * Fetch OpenGraph data for a URL.
     *
     * @throws \Exception
     */
    public function fetch(string $url): array
    {
        $cacheKey = 'link_unfurl:'.md5($url);

        // 1. Check Cache (Full Cache Strategy)
        // We cache both successful fetches and blocked status to prevent repeated DB/API hits.
        $cached = Cache::get($cacheKey);

        if ($cached) {
            if (isset($cached['error']) && $cached['error'] === 'unsafe_content_blocked') {
                throw new \Exception('unsafe_content_blocked');
            }

            return $cached;
        }

        try {
            // 2. Manual Blocklist (Local DB)
            if ($this->isBlocked($url)) {
                $this->logBlock($url, 'Url is in manual blocklist');
                $this->cacheBlock($cacheKey);
                throw new \Exception('unsafe_content_blocked');
            }

            // 3. Regex Safety Net
            if ($reason = $this->checkRegexSafety($url)) {
                $this->logBlock($url, "Regex Safety Net: $reason");
                $this->cacheBlock($cacheKey);
                throw new \Exception('unsafe_content_blocked');
            }

            // 4. Google Safe Browsing
            if ($reason = $this->checkSafeBrowsing($url)) {
                // Auto-Ban Logic: Add to local blocklist to persist the decision
                // We use firstOrCreate to avoid race conditions or duplicates
                try {
                    BlockedUrl::firstOrCreate(
                        ['pattern' => $url],
                        ['reason' => "Auto-banned: $reason"]
                    );
                } catch (\Exception $e) {
                    // Ignore DB errors during auto-ban, logging is enough
                    Log::error("Failed to auto-ban URL $url: ".$e->getMessage());
                }

                $this->logBlock($url, "Google Safe Browsing: $reason");
                $this->cacheBlock($cacheKey);
                throw new \Exception('unsafe_content_blocked');
            }

            // 5. Perform Fetch
            $result = $this->performFetch($url);

            // Cache Success
            Cache::put($cacheKey, $result, now()->addHours(24));

            return $result;

        } catch (\Exception $e) {
            // If it's our specific block exception, rethrow it
            if ($e->getMessage() === 'unsafe_content_blocked') {
                throw $e;
            }

            // For other fetch errors, we might want to cache them too or lets performFetch handle it
            throw $e;
        }
    }

    protected function cacheBlock(string $key): void
    {
        Cache::put($key, ['error' => 'unsafe_content_blocked'], now()->addHours(24));
    }

    protected function logBlock(string $url, string $reason): void
    {
        $this->auditService->log(
            action: AuditAction::LinkBlocked,
            category: AuditCategory::Security,
            context: ['url' => $url, 'reason' => $reason]
        );
    }

    protected function isBlocked(string $url): bool
    {
        $domain = parse_url($url, PHP_URL_HOST);

        // Simple exact match check for now, can be expanded to regex matching from DB
        if (BlockedUrl::where('pattern', $url)->exists()) {
            return true;
        }

        if ($domain && BlockedUrl::where('pattern', $domain)->exists()) {
            return true;
        }

        // Check for wildcards
        // This is a simple implementation, might need optimization for large blocklists
        $blockedPatterns = BlockedUrl::where('pattern', 'LIKE', '%*%')->get();
        foreach ($blockedPatterns as $blocked) {
            if (fnmatch($blocked->pattern, $url) || fnmatch($blocked->pattern, $domain)) {
                return true;
            }
        }

        return false;
    }

    protected function checkRegexSafety(string $url): ?string
    {
        $patterns = config('link_security.patterns', []);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return "Matches pattern: $pattern";
            }
        }

        return null;
    }

    protected function checkSafeBrowsing(string $url): ?string
    {
        $apiKey = config('services.google.safe_browsing_key');

        if (empty($apiKey)) {
            return null; // Feature disabled or not configured
        }

        try {
            $apiUrl = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key={$apiKey}";

            $payload = [
                'client' => [
                    'clientId' => 'coresync-v2',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => [
                        ['url' => $url],
                    ],
                ],
            ];

            Log::channel('google-safe-browsing')->info("Checking URL: {$url}");

            $response = \Illuminate\Support\Facades\Http::post($apiUrl, $payload);

            Log::channel('google-safe-browsing')->info("API Response Code: {$response->status()}");
            Log::channel('google-safe-browsing')->debug('API Body: '.$response->body());

            if ($response->successful()) {
                $matches = $response->json('matches');
                if (! empty($matches)) {
                    $threatType = $matches[0]['threatType'] ?? 'Unknown Threat';
                    Log::channel('google-safe-browsing')->warning("THREAT DETECTED: {$threatType} for URL: {$url}");

                    return "Flagged as $threatType";
                }
                Log::channel('google-safe-browsing')->info("No threats found for URL: {$url}");
            } else {
                Log::channel('google-safe-browsing')->error('API Request Failed: '.$response->body());
            }
        } catch (\Exception $e) {
            // Fail open on API error to avoid blocking legitimate traffic due to infrastructure issues
            Log::channel('google-safe-browsing')->error('Exception: '.$e->getMessage());
            Log::warning('Google Safe Browsing API check failed: '.$e->getMessage());
        }

        return null;
    }

    protected function performFetch(string $url): array
    {
        try {
            $data = $this->openGraph->fetch($url, true); // true = verify SSL

            // Normalize data
            $result = [
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'image' => $data['image'] ?? null,
                'url' => $data['url'] ?? $url,
                'type' => $data['type'] ?? null,
                'site_name' => $data['site_name'] ?? null,
            ];

            // Audit Success
            $this->auditService->log(
                action: AuditAction::LinkUnfurled,
                category: AuditCategory::Communication,
                newValues: ['result' => $result], // Show result in Changes section
                context: [
                    'url' => $url,
                    'title' => $result['title'],
                ]
            );

            return $result;

        } catch (\Exception $e) {
            Log::error("Link unfurl failed for {$url}: ".$e->getMessage());

            // Return minimal data on failure so UI doesn't break
            return [
                'url' => $url,
                'error' => true,
            ];
        }
    }
}
