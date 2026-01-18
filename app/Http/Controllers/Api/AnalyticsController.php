<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    protected $analyticsService;

    public function __construct(\App\Services\AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
        // Permission check ideally handled by middleware in route definition
    }

    public function overview(Request $request)
    {
        $period = $request->input('period', '7d');

        return response()->json([
            'data' => $this->analyticsService->getOverviewStats($period),
        ]);
    }

    public function chart(Request $request)
    {
        $period = $request->input('period', '7d');

        return response()->json([
            'data' => $this->analyticsService->getTrafficChart($period),
        ]);
    }

    public function topPages(Request $request)
    {
        $period = $request->input('period', '7d');

        return response()->json([
            'data' => $this->analyticsService->getTopPages($period),
        ]);
    }

    public function sources(Request $request)
    {
        $period = $request->input('period', '7d');

        return response()->json([
            'data' => $this->analyticsService->getTrafficSources($period),
        ]);
    }
}
