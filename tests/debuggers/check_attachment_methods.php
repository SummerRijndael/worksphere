
// Connect to IMAP and check attachment methods
$account = \App\Models\EmailAccount::first();
if (!$account) {
    echo "No account found" . PHP_EOL;
    exit;
}

// Create client
$client = new \Webklex\IMAP\Client([
    'host' => $account->imap_host,
    'port' => $account->imap_port,
    'encryption' => $account->imap_encryption,
    'validate_cert' => true,
    'username' => $account->email,
    'password' => decrypt($account->password),
    'protocol' => 'imap',
]);

$client->connect();
$folder = $client->getFolder('INBOX');

// Get a message with attachments
$messages = $folder->messages()->subject('test email with attachments')->limit(1)->get();

foreach ($messages as $message) {
    echo "Subject: " . $message->getSubject() . PHP_EOL;
    
    if ($message->hasAttachments()) {
        foreach ($message->getAttachments() as $att) {
            echo "Attachment: " . $att->getName() . PHP_EOL;
            
            // Check available methods
            $methods = get_class_methods($att);
            $cidMethods = array_filter($methods, fn($m) => stripos($m, 'id') !== false || stripos($m, 'content') !== false);
            echo "  Available ID methods: " . implode(', ', $cidMethods) . PHP_EOL;
            
            // Try to get content-id
            if (method_exists($att, 'getContentId')) {
                echo "  getContentId(): " . ($att->getContentId() ?? 'NULL') . PHP_EOL;
            }
            if (method_exists($att, 'getId')) {
                echo "  getId(): " . ($att->getId() ?? 'NULL') . PHP_EOL;
            }
            if (method_exists($att, 'id')) {
                echo "  id: " . ($att->id ?? 'NULL') . PHP_EOL;
            }
            
            // Check if it has a 'content_id' property or attribute
            $attributes = $att->getAttributes();
            if (!empty($attributes)) {
                echo "  Attributes: " . json_encode($attributes) . PHP_EOL;
            }
            
            break; // Just check first attachment
        }
    }
}

$client->disconnect();
