<?php

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
$message = $folder->query()->limit(1)->get()->first();

if ($message) {
    echo 'Subject Type: '.gettype($message->getSubject())."\n";
    echo 'Subject First: '.gettype($message->getSubject()->first())."\n";
    if (is_object($message->getSubject()->first())) {
        echo 'Subject Class: '.get_class($message->getSubject()->first())."\n";
    }

    $body = $message->getTextBody();
    echo 'Body Type: '.gettype($body)."\n";

    $html = $message->getHTMLBody();
    echo 'HTML Type: '.gettype($html)."\n";

    $msgId = $message->getMessageId();
    echo 'MsgID Type: '.gettype($msgId)."\n";
    echo 'MsgID First: '.gettype($msgId->first())."\n";
}
