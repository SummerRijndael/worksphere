<?php

use App\Services\MaintenanceService;
use Illuminate\Support\Facades\Config;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new MaintenanceService;

echo 'Cache Default Config: '.Config::get('cache.default')."\n";
echo 'Cache Config Stores: '.json_encode(Config::get('cache.stores.'.Config::get('cache.default')))."\n";

try {
    $info = $service->getDetailedCacheInfo();
    echo "Service Output:\n";
    print_r($info);
} catch (Throwable $e) {
    echo 'Service Error: '.$e->getMessage()."\n";
}
