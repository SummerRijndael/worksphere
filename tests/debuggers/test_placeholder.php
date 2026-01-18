
// Test what HTMLPurifier does with the placeholder
$purifier = app(\Mews\Purifier\Purifier::class);

$tests = [
    '<p>Test</p><span data-cid-placeholder="0"></span>' => 'span with data attr',
    '<p>Test</p><span class="cid-0">X</span>' => 'span with class and content',
    '<p>Test</p>[[CID_0]]' => 'text placeholder',
    '<p>Test</p><div data-cid="0"></div>' => 'div with data attr',
];

foreach ($tests as $input => $desc) {
    $output = $purifier->clean($input, 'email');
    echo "[$desc]" . PHP_EOL;
    echo "  In:  $input" . PHP_EOL;
    echo "  Out: $output" . PHP_EOL . PHP_EOL;
}
