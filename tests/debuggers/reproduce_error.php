<?php

use App\Models\Email;
use App\Models\EmailAccount;
use Webklex\PHPIMAP\ClientManager;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$account = EmailAccount::first();
if (! $account) {
    echo 'No account';
    exit;
}

$cm = new ClientManager;
$client = $cm->make([
    'host' => $account->imap_host,
    'port' => $account->imap_port,
    'encryption' => $account->imap_encryption,
    'validate_cert' => true,
    'username' => $account->username ?? $account->email,
    'password' => $account->password,
    'protocol' => 'imap',
]);

$client->connect();
$folder = $client->getFolder('INBOX');
$messages = $folder->query()->limit(1)->get();

if ($messages->count() == 0) {
    echo "No messages in INBOX\n";
    exit;
}

$message = $messages->first();
echo 'Fetched message UID: '.$message->getUid()."\n";

// --- LOGIC FROM SeedEmailAccountJob ---
$from = $message->getFrom()[0] ?? null;
$fromEmail = 'unknown@unknown.com';
$fromName = null;

if ($from && is_object($from)) {
    $fromEmail = $from->mail ?? 'unknown@unknown.com';
    $fromName = $from->personal ?? null;
} elseif (is_string($from)) {
    $fromEmail = $from;
}

$to = collect($message->getTo())->map(fn ($addr) => [
    'email' => is_object($addr) ? ($addr->mail ?? '') : (is_array($addr) ? ($addr['mail'] ?? json_encode($addr)) : (string) $addr),
    'name' => is_object($addr) ? ($addr->personal ?? null) : (is_array($addr) ? ($addr['personal'] ?? null) : null),
])->toArray();

$cc = collect($message->getCc())->map(fn ($addr) => [
    'email' => is_object($addr) ? ($addr->mail ?? '') : (is_array($addr) ? ($addr['mail'] ?? json_encode($addr)) : (string) $addr),
    'name' => is_object($addr) ? ($addr->personal ?? null) : (is_array($addr) ? ($addr['personal'] ?? null) : null),
])->toArray();

$textBody = $message->getTextBody() ?? '';
$preview = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $textBody)), 200);

$emailData = [
    'message_id' => (string) ($message->getMessageId()?->first() ?? ''),
    'from_email' => is_array($fromEmail) ? ($fromEmail[0] ?? 'unknown') : (string) $fromEmail,
    'from_name' => is_array($fromName) ? ($fromName[0] ?? null) : ((string) $fromName),
    'to' => $to,
    'cc' => $cc,
    'bcc' => [],
    'subject' => (string) ($message->getSubject()?->first() ?? '(No Subject)'),
    'preview' => (string) $preview,
    'body_html' => (string) ($message->getHTMLBody() ?? ''),
    'body_plain' => (string) $textBody,
    'is_read' => $message->getFlags()->contains('Seen'),
    'is_starred' => $message->getFlags()->contains('Flagged'),
    'has_attachments' => $message->hasAttachments(),
    'imap_uid' => (int) $message->getUid(),
    'date' => $message->getDate()?->first()?->toDate(),
];
// -------------------------------------

echo "Data prepared.\n";
// Dump keys and types
foreach ($emailData as $k => $v) {
    echo "$k: ".gettype($v)."\n";
    if (is_array($v)) {
        echo '  - Array content: '.json_encode($v)."\n";
    }
}

echo "Attempting insert...\n";

try {
    $email = Email::create([
        'email_account_id' => $account->id,
        'user_id' => $account->user_id,
        'folder' => 'inbox',
        // Merge data
        ...$emailData,
        'received_at' => $emailData['date'] ?? now(),
    ]);
    echo 'Insert SUCCESS! ID: '.$email->id."\n";
    $email->delete(); // Cleanup
} catch (\Exception $e) {
    echo 'Insert FAILED: '.$e->getMessage()."\n";
    echo $e->getTraceAsString();
}
