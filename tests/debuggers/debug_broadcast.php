<?php

use App\Events\Email\EmailReceived;
use App\Models\Email;

echo "1. Creating dummy email...\n";
$email = new Email;
$email->id = 999999;
$email->subject = 'Test Broadcast';

echo "2. Dispatching event...\n";
try {
    $start = microtime(true);
    broadcast(new EmailReceived($email));
    $end = microtime(true);
    echo 'Success! Took '.round($end - $start, 4)."s\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}
echo "Done.\n";
