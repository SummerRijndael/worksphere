<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(AppSettingsService $settings): Response
    {
        $fallback = file_exists(public_path('robots.txt')) 
            ? file_get_contents(public_path('robots.txt')) 
            : "User-agent: *\nDisallow: /admin\nAllow: /";

        $content = $settings->get('seo.robots_txt', $fallback);

        return response($content, 200)
            ->header('Content-Type', 'text/plain');
    }
}
