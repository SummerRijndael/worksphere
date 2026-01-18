<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use Illuminate\Console\Command;
use Webklex\PHPIMAP\ClientManager;

class DebugImapConnection extends Command
{
    protected $signature = 'debug:imap {accountId}';

    protected $description = 'Debug IMAP connection and fetching';

    public function handle()
    {
        $accountId = $this->argument('accountId');
        $account = EmailAccount::find($accountId);

        if (! $account) {
            $this->error("Account {$accountId} not found.");

            return 1;
        }

        $this->info("Testing account: {$account->email} ({$account->provider})");

        $cm = new ClientManager;
        $config = [
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $account->imap_encryption,
            'validate_cert' => true,
            'username' => $account->username ?? $account->email,
            'password' => $account->password,
            'protocol' => 'imap',
        ];

        if ($account->auth_type === 'oauth') {
            $this->info('Using OAuth...');
            $config['authentication'] = 'oauth';
            $config['password'] = $account->access_token;
        }

        try {
            $client = $cm->make($config);
            $client->connect();
            $this->info('Connected successfully.');

            $folders = ['INBOX', '[Gmail]/Sent Mail', 'Sent', 'inbox'];

            foreach ($folders as $folderName) {
                $this->info("Checking folder: {$folderName}...");
                try {
                    $folder = $client->getFolder($folderName);

                    if (! $folder) {
                        $this->error("Folder {$folderName} not found.");

                        continue;
                    }

                    $totalInfo = $folder->examine();
                    $total = $totalInfo['exists'] ?? 0;
                    $uidnext = $totalInfo['uidnext'] ?? 0;

                    $this->info("Folder found. Messages: $total, UIDNEXT: $uidnext");

                    if ($uidnext > 0) {
                        // Estimate range: UIDNEXT - 50 to *
                        // Since UIDNEXT is the *next* assigned UID, the last one is likely uidnext-1
                        // But UIDs can be sparse. So we might need a larger window to ensure 50 msgs.
                        // Let's try window of 100 UIDs.
                        $start = max(1, $uidnext - 100);
                        $range = "{$start}:*";

                        $this->info("Attempting to fetch OVERVIEW by UID range: {$range}...");

                        // Use overview() which maps to FETCH ... (UIDs usually if ST_UID)
                        $overview = $folder->overview($range);

                        $this->info('Overview fetched. Count: '.count($overview));

                        if (count($overview) > 0) {
                            // Extract UIDs - handle both objects and arrays
                            $uids = [];
                            foreach ($overview as $msg) {
                                $uid = is_object($msg) ? ($msg->uid ?? null) : ($msg['uid'] ?? null);
                                if ($uid) {
                                    $uids[] = $uid;
                                }
                            }

                            $this->info('Fetched UIDs: '.implode(', ', array_slice($uids, -5))); // Show last 5

                            // Now fetch ONE full message using where('UID', $uid) - single UID
                            $lastUid = end($uids);
                            $this->info("Attempting full fetch for Single UID: $lastUid...");
                            $msg = $folder->query()->where('UID', $lastUid)->get()->first();

                            if ($msg) {
                                $this->info('Full fetch SUCCESS. ID: '.$msg->getMessageId());
                            } else {
                                $this->error('Full fetch FAILED (empty result).');
                            }
                        }
                    } else {
                        // Fallback if uidnext is missing (some servers don't provide it?)
                        $this->warn('UIDNEXT missing.');
                    }

                } catch (\Exception $e) {
                    $this->error("Failed to process folder {$folderName}: ".$e->getMessage());
                    // Log trace if needed
                    // $this->error($e->getTraceAsString());
                }
            }

            $client->disconnect();

        } catch (\Exception $e) {
            $this->error('Connection failed: '.$e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}
