<?php

use App\Services\SecureOpenGraph;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sog = new class extends SecureOpenGraph
{
    public function testValidateUrl($url)
    {
        return $this->resolveAndValidate($url);
    }

    public function testBlock($ip)
    {
        return $this->isBlockedIp($ip);
    }
};

echo "Testing IP Blocking Logic:\n";
$blockedIps = ['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '::1', 'fe80::1'];
$allowedIps = ['8.8.8.8', '1.1.1.1', '93.184.216.34']; // Example.com

foreach ($blockedIps as $ip) {
    echo "Checking Blocked IP $ip: ".($sog->testBlock($ip) ? 'PASS (Blocked)' : 'FAIL (Allowed)')."\n";
}

foreach ($allowedIps as $ip) {
    echo "Checking Public IP $ip: ".(! $sog->testBlock($ip) ? 'PASS (Allowed)' : 'FAIL (Blocked)')."\n";
}

echo "\nTesting URL Validation:\n";
$urls = [
    'http://localhost' => false,
    'http://127.0.0.1/test' => false,
    'http://google.com' => true,
    'https://example.com' => true,
    'http://169.254.169.254/latest/meta-data' => false,
];

foreach ($urls as $url => $shouldPass) {
    $result = $sog->testValidateUrl($url);
    $status = $result ? "Allowed ($result)" : 'Blocked';
    // For external domains, result depends on network, so we mainly check that *internal* ones are blocked.
    // If network is down, external might return null (blocked/invalid), which is safe fail-closed.

    echo "URL: $url -> $status\n";
}
