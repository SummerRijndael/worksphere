<?php

namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Services\UserSentimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
 
class EngagementController extends Controller
{
    protected UserSentimentService $sentimentService;
 
    public function __construct(UserSentimentService $sentimentService)
    {
        $this->sentimentService = $sentimentService;
    }
 
    /**
     * Get user sentiment and vibe metrics.
     */
    public function sentiment(): JsonResponse
    {
        return response()->json([
            'data' => $this->sentimentService->getSentimentMetrics(),
        ]);
    }
 
    /**
     * Get engagement metrics.
     */
    public function engagement(Request $request): JsonResponse
    {
        $period = $request->input('period', '30d');
        
        return response()->json([
            'data' => $this->sentimentService->getEngagementStats($period),
        ]);
    }
}
