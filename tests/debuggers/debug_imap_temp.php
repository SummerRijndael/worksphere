<?php

use App\Models\EmailAccount;
use Webklex\PHPIMAP\ClientManager;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$account = EmailAccount::first();

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

try {
    $client->connect();
    $folder = $client->getFolder('INBOX');
    $message = $folder->query()->limit(1)->get()->first();

    if ($message) {
        $from = $message->getFrom();
        echo 'Type of getFrom(): '.gettype($from)."\n";
        echo 'Class: '.get_class($from)."\n";

        try {
            $first = $from[0];
            echo 'Access [0] result type: '.gettype($first)."\n";
            if (is_object($first)) {
                echo 'Access [0] class: '.get_class($first)."\n";
                echo 'Mail: '.$first->mail."\n";
            } else {
                echo 'Value: '.$first."\n";
            }
        } catch (\Throwable $e) {
            echo 'Array access failed: '.$e->getMessage()."\n";
        }
    }
} catch (\Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
}
