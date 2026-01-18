<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GiphyService
{
    protected string $baseUrl = 'https://api.giphy.com/v1/gifs';

    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.giphy.key');
    }

    /**
     * Search for GIFs.
     */
    public function search(string $query, int $limit = 20, int $offset = 0): array
    {
        return $this->request('search', [
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'rating' => 'g',
            'lang' => 'en',
        ]);
    }

    /**
     * Get trending GIFs.
     */
    public function trending(int $limit = 20, int $offset = 0): array
    {
        return $this->request('trending', [
            'limit' => $limit,
            'offset' => $offset,
            'rating' => 'g',
        ]);
    }

    /**
     * Make request to Giphy API.
     */
    protected function request(string $endpoint, array $params = []): array
    {
        if (empty($this->apiKey)) {
            Log::warning('Giphy API key is missing.');

            return ['data' => [], 'pagination' => []];
        }

        try {
            $response = Http::get("{$this->baseUrl}/{$endpoint}", array_merge([
                'api_key' => $this->apiKey,
            ], $params));

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Giphy API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['data' => [], 'pagination' => []];
        } catch (\Exception $e) {
            Log::error('Giphy API exception', [
                'message' => $e->getMessage(),
            ]);

            return ['data' => [], 'pagination' => []];
        }
    }
}
