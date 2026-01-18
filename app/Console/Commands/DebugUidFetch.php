<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Services\EmailAdapters\AdapterFactory;
use Illuminate\Console\Command;

class DebugUidFetch extends Command
{
    protected $signature = 'debug:uid-fetch {account_id} {start} {end}';

    protected $description = 'Debug fetchUidRange logic';

    public function handle()
    {
        $accountId = $this->argument('account_id');
        $start = (int) $this->argument('start');
        $end = (int) $this->argument('end');

        $account = EmailAccount::find($accountId);
        if (! $account) {
            $this->error('Account not found.');

            return;
        }

        $this->info("Connecting to {$account->email}...");
        $adapter = AdapterFactory::make($account);

        // Refresh token if needed
        $adapter->refreshTokenIfNeeded($account);
        $client = $adapter->createClient($account);
        $client->connect();

        $folder = $client->getFolder('INBOX'); // Hardcoded for test
        $this->info("Fetching UID range $start:$end from INBOX...");

        // call raw overview first to show what we are dealing with
        $range = "$start:$end";
        $overview = $folder->overview($range);

        $this->info('Raw Overview (first item):');

        // Force Sequence Mode
        $client->setSequence(\Webklex\PHPIMAP\IMAP::ST_MSGN);
        $this->info('Switched to ST_MSGN');

        $overview = $folder->overview($range);

        // Restore
        $client->setSequence(\Webklex\PHPIMAP\IMAP::ST_UID);

        if (! empty($overview)) {
            foreach ($overview as $key => $item) {
                dump(['key' => $key, 'item' => $item]);
                // Extract UID?
                $uid = isset($item->uid) ? $item->uid : (isset($item['uid']) ? $item['uid'] : 'N/A');
                $this->info("Extracted UID: $uid");
                break; // only show first
            }
        } else {
            $this->info('Overview returned empty.');
        }

        // Call the adapter method
        $uids = $adapter->fetchUidRange($folder, $start, $end);
        $this->info('Adapter fetchUidRange result count: '.count($uids));
        dump($uids);

        $client->disconnect();
    }
}
