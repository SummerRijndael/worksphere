<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\GiphyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiphyController extends Controller
{
    public function __construct(
        protected GiphyService $giphyService
    ) {}

    /**
     * Search for GIFs.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1',
            'limit' => 'nullable|integer|max:50',
            'offset' => 'nullable|integer|min:0',
        ]);

        $result = $this->giphyService->search(
            $request->input('q'),
            $request->input('limit', 20),
            $request->input('offset', 0)
        );

        return response()->json($result);
    }

    /**
     * Get trending GIFs.
     */
    public function trending(Request $request): JsonResponse
    {
        $request->validate([
            'limit' => 'nullable|integer|max:50',
            'offset' => 'nullable|integer|min:0',
        ]);

        $result = $this->giphyService->trending(
            $request->input('limit', 20),
            $request->input('offset', 0)
        );

        return response()->json($result);
    }
}
