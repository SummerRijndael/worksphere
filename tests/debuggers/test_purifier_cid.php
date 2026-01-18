
// Test HTMLPurifier CID handling
$testHtml = '<p>Test email</p><img src="cid:ii_mk60kpi90" alt="inline">';

$purifier = app(\Mews\Purifier\Purifier::class);
$cleaned = $purifier->clean($testHtml, 'email');

echo "Input:  $testHtml" . PHP_EOL;
echo "Output: $cleaned" . PHP_EOL;

// Check if cid: is preserved
if (str_contains($cleaned, 'cid:')) {
    echo "✓ CID preserved!" . PHP_EOL;
} else {
    echo "✗ CID stripped!" . PHP_EOL;
}
