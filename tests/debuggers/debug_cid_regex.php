
// Debug CID regex matching
$email = \App\Models\Email::where('subject', 'like', '%test email with attachments%')->first();

if (!$email || !$email->body_raw) {
    echo "No email found" . PHP_EOL;
    exit;
}

// Find img tags with cid: in body_raw
preg_match_all('/<img[^>]*cid:[^>]*>/i', $email->body_raw, $imgMatches);
echo "Img tags with cid: found: " . count($imgMatches[0]) . PHP_EOL;
foreach ($imgMatches[0] as $i => $match) {
    echo "  [$i]: " . substr($match, 0, 200) . PHP_EOL;
}

// Test the exact regex
$pattern = '/<img\s+[^>]*src\s*=\s*(["\'])cid:([^"\']+)\1[^>]*>/i';
preg_match_all($pattern, $email->body_raw, $exactMatches);
echo PHP_EOL . "Exact regex matches: " . count($exactMatches[0]) . PHP_EOL;
foreach ($exactMatches[0] as $i => $match) {
    echo "  [$i]: " . substr($match, 0, 200) . PHP_EOL;
}
