<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    protected $analyticsService;

    protected $analyticsTracker;

    public function __construct(
        \App\Services\AnalyticsService $analyticsService,
        \App\Services\AnalyticsTracker $analyticsTracker
    ) {
        $this->analyticsService = $analyticsService;
        $this->analyticsTracker = $analyticsTracker;
        // Permission check ideally handled by middleware in route definition
    }

    public function track(Request $request)
    {
        $this->analyticsTracker->trackManual($request, [
            'path' => $request->input('path'),
            'url' => $request->input('url'),
            'referer' => $request->input('referer'),
        ]);

        return response()->json(['success' => true]);
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

    public function demographics(Request $request)
    {
        $period = $request->input('period', '7d');

        return response()->json([
            'data' => $this->analyticsService->getDemographics($period),
        ]);
    }

    public function geoStats(Request $request)
    {
        $period = $request->input('period', '7d');

        return response()->json([
            'data' => $this->analyticsService->getGeoStats($period),
        ]);
    }

    public function export(Request $request)
    {
        $type = $request->input('type', 'traffic');
        $period = $request->input('period', '7d');

        $csv = match ($type) {
            'pages' => $this->analyticsService->exportTopPages($period),
            'sources' => $this->analyticsService->exportSources($period),
            default => $this->analyticsService->exportTraffic($period),
        };

        $filename = "analytics_{$type}_{$period}_".date('Ymd').'.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}
