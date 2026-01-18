
// Test the full sanitization flow
$email = \App\Models\Email::where('subject', 'like', '%test email with attachments%')->first();

if (!$email || !$email->body_raw) {
    echo "No email with body_raw found" . PHP_EOL;
    exit;
}

echo "Testing sanitization of body_raw..." . PHP_EOL;

// Check if body_raw has CID refs
preg_match_all('/cid:([^"\'\s>]+)/', $email->body_raw, $matches);
echo "CID refs in body_raw: " . (empty($matches[1]) ? 'None' : implode(', ', $matches[1])) . PHP_EOL;

// Run sanitization
$sanitizer = app(\App\Services\EmailSanitizationService::class);
$sanitizedHtml = $sanitizer->sanitize($email->body_raw, 'imap');

// Check result
preg_match_all('/cid:([^"\'\s>]+)/', $sanitizedHtml, $resultMatches);
echo "CID refs after sanitize(): " . (empty($resultMatches[1]) ? 'None' : implode(', ', $resultMatches[1])) . PHP_EOL;

// Show snippet
$imgMatch = preg_match('/<img[^>]+>/', $sanitizedHtml, $imgMatches);
if ($imgMatch) {
    echo "First img tag: " . substr($imgMatches[0], 0, 200) . PHP_EOL;
}
