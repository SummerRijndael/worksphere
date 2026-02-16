<?php

use App\Models\PageView;
use Illuminate\Support\Str;

PageView::truncate();

// Create 50 random visits
for ($i = 0; $i < 50; $i++) {
    $lat = rand(-90, 90);
    $lon = rand(-180, 180);
    
    // Some fixed locations for clustering
    if ($i < 10) { // New York
        $lat = 40.7128 + (rand(-100, 100) / 1000); 
        $lon = -74.0060 + (rand(-100, 100) / 1000);
        $city = 'New York';
        $country = 'United States';
        $iso = 'US';
    } elseif ($i < 20) { // London
        $lat = 51.5074 + (rand(-100, 100) / 1000);
        $lon = -0.1278 + (rand(-100, 100) / 1000);
        $city = 'London';
        $country = 'United Kingdom';
        $iso = 'GB';
    } else {
        $city = 'Unknown';
        $country = 'Unknown';
        $iso = 'XX';
    }

    PageView::create([
        'session_id' => Str::random(10),
        'path' => '/',
        'url' => 'http://localhost/test',
        'referer' => ($i % 3 == 0) ? 'http://127.0.0.1:8000/' : (($i % 3 == 1) ? 'https://google.com' : 'https://twitter.com'),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'method' => 'GET',
        'device_type' => 'desktop',
        'browser' => 'Chrome',
        'platform' => 'Linux',
        'city' => $city,
        'country' => $country,
        'iso_code' => $iso,
        'lat' => $lat,
        'lon' => $lon,
        'created_at' => now()->subMinutes(rand(1, 1000)),
    ]);
}

echo "Seeded " . PageView::count() . " page views manually.\n";
