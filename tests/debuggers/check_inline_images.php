
$email = \App\Models\Email::with('media')->where('subject', 'like', '%test email with attachments%')->first();

if ($email) {
    echo 'Email ID: ' . $email->id . PHP_EOL;
    echo 'Subject: ' . $email->subject . PHP_EOL;
    echo 'Has body_raw: ' . (strlen($email->body_raw ?? '') > 0 ? 'Yes' : 'No') . PHP_EOL;
    echo 'Sanitized_at: ' . ($email->sanitized_at ?? 'NULL') . PHP_EOL;
    echo 'Attachments: ' . $email->media->count() . PHP_EOL;
    
    foreach ($email->media as $m) {
        $cid = $m->getCustomProperty('content_id');
        echo '  - ' . $m->file_name . ' | content_id: ' . ($cid ?? 'NULL') . PHP_EOL;
    }
    
    // Check for CID refs in body
    preg_match_all('/cid:([^"\s>]+)/', $email->body_html ?? '', $matches);
    echo 'CID refs in body_html: ' . (empty($matches[1]) ? 'None' : implode(', ', $matches[1])) . PHP_EOL;
    
    // Check body_raw too
    preg_match_all('/cid:([^"\s>]+)/', $email->body_raw ?? '', $rawMatches);
    echo 'CID refs in body_raw: ' . (empty($rawMatches[1]) ? 'None' : implode(', ', $rawMatches[1])) . PHP_EOL;
} else {
    echo 'Email not found with subject containing "test email with attachments"' . PHP_EOL;
}
