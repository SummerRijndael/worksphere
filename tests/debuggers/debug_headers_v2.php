<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\EmailAccount;
use Webklex\PHPIMAP\ClientManager;

$account = EmailAccount::first();

if (! $account) {
    echo 'No account found'.PHP_EOL;
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

try {
    echo "Connecting to {$account->imap_host}...".PHP_EOL;
    $client->connect();
    echo 'Connected. Getting INBOX...'.PHP_EOL;
    $folder = $client->getFolder('INBOX');
    echo 'Getting message...'.PHP_EOL;
    $message = $folder->query()->limit(1)->get()->first();

    if ($message) {
        echo 'Message Found ID: '.$message->getUid().PHP_EOL;

        $headerObj = $message->getHeader();
        echo 'Header Class: '.get_class($headerObj).PHP_EOL;

        // Inspect object
        // echo print_r($headerObj, true) . PHP_EOL;

        // Inspect Headers Values
        if (method_exists($headerObj, 'getAttributes')) {
            $attrs = $headerObj->getAttributes();
            echo 'Attributes Count: '.count($attrs).PHP_EOL;

            foreach (array_slice($attrs, 0, 5) as $key => $val) {
                echo "Header '$key' Type: ".gettype($val).PHP_EOL;
                if (is_object($val)) {
                    echo '  Class: '.get_class($val).PHP_EOL;
                    if (method_exists($val, 'toArray')) {
                        echo '  As Array: '.print_r($val->toArray(), true).PHP_EOL;
                    }
                    if (method_exists($val, '__toString')) {
                        echo '  As String: '.(string) $val.PHP_EOL;
                    }
                } elseif (is_array($val)) {
                    echo '  Value: '.print_r($val, true).PHP_EOL;
                }
            }
        }

        // Inspect Recipients
        $to = $message->getTo();
        echo 'To Type: '.gettype($to).PHP_EOL;
        if (is_object($to)) {
            echo 'To Class: '.get_class($to).PHP_EOL;
            if (method_exists($to, 'toArray')) {
                echo 'To as Array: '.print_r($to->toArray(), true).PHP_EOL;
            }
        }

    } else {
        echo 'No messages found.'.PHP_EOL;
    }
} catch (\Throwable $e) {
    echo 'Error: '.$e->getMessage().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
}
