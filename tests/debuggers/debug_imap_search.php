<?php

use App\Models\EmailAccount;
use App\Services\EmailAdapters\AdapterFactory;

$accountId = 2; // Gmail
$folderName = 'INBOX';

echo "1. Loading Account $accountId...\n";
$account = EmailAccount::find($accountId);

echo "2. Creating Adapter...\n";
$adapter = AdapterFactory::make($account);

echo "3. Connecting...\n";
$client = $adapter->createClient($account);
$client->connect();
echo "Connected.\n";

$imapFolderName = $adapter->getFolderName($folderName);
$folder = $client->getFolder($imapFolderName);
echo "4. Getting Folder: $imapFolderName\n";

$start = 1;
$end = 50;
echo "5. Fetching UID Range $start - $end...\n";
$uids = $adapter->fetchUidRange($folder, $start, $end);
echo 'Fetched '.count($uids)." UIDs.\n";
if (! empty($uids)) {
    echo 'Sample UIDs: '.implode(', ', array_slice($uids, 0, 5))."\n";
}

echo "6. Testing Query with UIDs...\n";
if (! empty($uids)) {
    try {
        $uidString = implode(',', $uids);
        echo 'Querying with string: '.substr($uidString, 0, 50)."...\n";
        $messages = $folder->query()->where('UID', $uidString)->get();
        echo 'Success! Fetched '.$messages->count()." messages.\n";
    } catch (\Exception $e) {
        echo 'ERROR in UID Query: '.$e->getMessage()."\n";
    }
} else {
    echo "Skipping UID Query (No UIDs).\n";
}

echo "7. Testing Fallback Query (Limit/Offset)...\n";
try {
    $messages = $folder->query()->limit(50)->setOffset(0)->get();
    echo 'Success! Fallback fetched '.$messages->count()." messages.\n";
} catch (\Exception $e) {
    echo 'ERROR in Fallback Query: '.$e->getMessage()."\n";
}

$client->disconnect();
echo "Done.\n";
