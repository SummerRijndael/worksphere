<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LinkUnfurlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkUnfurlController extends Controller
{
    public function __construct(
        protected LinkUnfurlService $linkUnfurlService
    ) {}

    /**
     * Unfurl a link.
     */
    public function unfurl(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
        ]);

        try {
            $data = $this->linkUnfurlService->fetch($request->input('url'));

            return response()->json($data);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'unsafe_content_blocked') {
                return response()->json(['error' => 'unsafe_content_blocked', 'url' => $request->input('url')], 403);
            }

            return response()->json(['error' => 'failed_to_unfurl', 'message' => $e->getMessage()], 500);
        }
    }
}
