<?php

use App\Models\EmailAccount;
use App\Services\EmailAdapters\AdapterFactory;

$accountId = 2; // Gmail
$folderName = 'INBOX';
$account = EmailAccount::find($accountId);
$adapter = AdapterFactory::make($account);
$client = $adapter->createClient($account);
$client->connect();
$imapFolderName = $adapter->getFolderName($folderName);
$folder = $client->getFolder($imapFolderName);

// Hardcoded known UIDs for testing
$uids = [4, 23, 28];
$uidString = implode(',', $uids);

echo "1. Testing where('UID', \$array)...\n";
try {
    $messages = $folder->query()->where('UID', $uids)->get();
    echo 'Success! Fetched '.$messages->count()." messages.\n";
} catch (\Exception $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}

echo "2. Testing whereUid(\$string)...\n";
try {
    // Some versions support magic methods
    $messages = $folder->query()->whereUid($uidString)->get();
    echo 'Success! Fetched '.$messages->count()." messages.\n";
} catch (\Exception $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}

echo "3. Testing where('UID', \$string) with explicit no-quote (if possible)...\n";
// No obvious way to force no-quote via standard builder

$client->disconnect();
