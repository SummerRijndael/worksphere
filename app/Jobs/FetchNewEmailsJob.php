<?php

namespace App\Jobs;

use App\Enums\EmailFolderType;
use App\Enums\EmailSyncStatus;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

/**
 * Job to fetch new emails for accounts that have completed initial sync.
 */
class FetchNewEmailsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public int $accountId
    ) {
        $this->onQueue(config('email.jobs.sync.queue', 'emails'));
    }

    public function handle(EmailSyncService $syncService): void
    {
        $account = EmailAccount::find($this->accountId);

        if (! $account) {
            Log::warning('[FetchNewEmailsJob] Account not found', ['account_id' => $this->accountId]);

            return;
        }

        if ($account->sync_status !== EmailSyncStatus::Completed) {
            return;
        }

        $startTime = microtime(true);
        $totalFetched = 0;

        try {
            $client = $this->createImapClient($account);
            $client->connect();

            // Only check priority folders for incremental sync
            $foldersToCheck = EmailFolderType::priorityFolders();

            foreach ($foldersToCheck as $folderType) {
                $imapFolderName = $syncService->getImapFolderName($account->provider, $folderType->value);

                try {
                    $folder = $client->getFolder($imapFolderName);

                    if (! $folder) {
                        continue;
                    }

                    // Get the last synced UID for this folder
                    $lastUid = $account->emails()
                        ->where('folder', $folderType->value)
                        ->max('imap_uid') ?? 0;

                    // Fetch messages with UID greater than last synced
                    // Use query()->where('UID', 'lastUid+1:*')
                    // Logic: Incremental sync should fetch messages > lastUID.
                    // usage of query()->where('UID', range) can fail on Gmail (BAD command).
                    // So we use overview() to get UIDs first, then fetch specific UIDs.

                    $messages = collect([]);

                    if ($lastUid > 0) {
                        // Check how many exist
                        $totalInfo = $folder->examine();
                        $uidnext = $totalInfo['uidnext'] ?? 0;

                        if ($uidnext > $lastUid + 1) {
                            // There are potential new messages.
                            $range = ($lastUid + 1).':*';
                            try {
                                // Overview to get UIDs
                                $overview = $folder->overview($range);

                                // Take up to 50 UIDs (chunk)
                                $newUids = [];
                                foreach ($overview as $msg) {
                                    $uid = is_object($msg) ? ($msg->uid ?? null) : ($msg['uid'] ?? null);
                                    if ($uid) {
                                        $newUids[] = $uid;
                                    }
                                    if (count($newUids) >= 100) {
                                        break;
                                    }
                                }

                                if (! empty($newUids)) {
                                    $messages = $folder->query()->where('UID', implode(',', $newUids))->get();
                                } else {
                                    // Fallback: Overview returned empty UIDs.
                                    // Use limit() to fetch recent messages and filter by UID in PHP.
                                    Log::warning('[FetchNewEmailsJob] Overview returned empty UIDs, using fallback', [
                                        'account_id' => $this->accountId,
                                        'folder' => $folderType->value,
                                    ]);

                                    // Fetch last 50 messages
                                    $recentMessages = $folder->query()->limit(50)->setFetchOrder('desc')->get();
                                    $messages = $recentMessages->filter(function ($msg) use ($lastUid) {
                                        return $msg->getUid() > $lastUid;
                                    });
                                }
                            } catch (\Throwable $e) {
                                Log::warning('[FetchNewEmailsJob] Overview fetch failed', [
                                    'folder' => $folderType->value,
                                    'error' => $e->getMessage(),
                                ]);

                                // Fallback on error
                                try {
                                    $recentMessages = $folder->query()->limit(50)->setFetchOrder('desc')->get();
                                    $messages = $recentMessages->filter(function ($msg) use ($lastUid) {
                                        return $msg->getUid() > $lastUid;
                                    });
                                } catch (\Throwable $ex) {
                                    // Ignore
                                }
                            }
                        }
                    } else {
                        // Fallback/Initial catch-up if lastUid is 0?
                        // Usually handled by Seed job.
                        if ($account->provider !== 'gmail') {
                            // If lastUID is 0, we might want to fetch *some* emails if overview failed previously?
                            // But let's assume SeedJob handled the initial batch.
                        }
                    }

                    foreach ($messages as $message) {
                        $emailData = $this->parseMessage($message);
                        $syncService->storeEmailFromImap($account, $emailData, $folderType->value);
                        $totalFetched++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[FetchNewEmailsJob] Failed to check folder', [
                        'folder' => $folderType->value,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Piggyback: Update Storage Usage
            try {
                // Try to get quota info from INBOX (or root)
                $quotaResponse = $client->getQuotaRoot('INBOX');

                // Parse raw response if necessary
                // Expected format from debug:
                // [ ["QUOTAROOT", ...], ["QUOTA", "", ["STORAGE", usage, limit]], ["OK", ...] ]

                $usage = null;
                $limit = null;

                if (is_array($quotaResponse)) {
                    // Check for associative array first (some versions/adapters)
                    if (isset($quotaResponse['storage'])) {
                        $usage = ($quotaResponse['storage']['usage'] ?? 0) * 1024;
                        $limit = ($quotaResponse['storage']['limit'] ?? 0) * 1024;
                    } else {
                        // Handle raw array response
                        foreach ($quotaResponse as $line) {
                            if (is_array($line) && isset($line[0]) && $line[0] === 'QUOTA') {
                                // Find the storage array in the quota line
                                foreach ($line as $item) {
                                    if (is_array($item) && isset($item[0]) && $item[0] === 'STORAGE') {
                                        $usageKb = isset($item[1]) ? (int) $item[1] : 0;
                                        $limitKb = isset($item[2]) ? (int) $item[2] : 0;

                                        $usage = $usageKb * 1024;
                                        $limit = $limitKb > 0 ? $limitKb * 1024 : null;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($usage !== null) {
                    $account->update([
                        'storage_used' => $usage,
                        'storage_limit' => $limit,
                        'storage_updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                // Quota not supported or failed, log debug but don't fail sync
                Log::debug('[FetchNewEmailsJob] Quota fetch failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $client->disconnect();

            // Update last sync time
            $account->update(['last_sync_at' => now()]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            EmailSyncLog::create([
                'email_account_id' => $account->id,
                'action' => EmailSyncLog::ACTION_INCREMENTAL_FETCH,
                'details' => [
                    'fetched_count' => $totalFetched,
                    'duration_ms' => $durationMs,
                ],
            ]);

            if ($totalFetched > 0) {
                Log::info('[FetchNewEmailsJob] Fetched new emails', [
                    'account_id' => $this->accountId,
                    'count' => $totalFetched,
                ]);
            }
        } catch (\Throwable $e) {
            EmailSyncLog::logError($account->id, $e->getMessage());

            throw $e;
        }
    }

    protected function createImapClient(EmailAccount $account): \Webklex\PHPIMAP\Client
    {
        // Check for OAuth and refresh token if needed
        if ($account->isOAuth() && $account->needsTokenRefresh()) {
            try {
                app(\App\Services\EmailAccountService::class)->refreshToken($account);
                $account->refresh(); // Reload to get new token
            } catch (\Throwable $e) {
                Log::error('[FetchNewEmailsJob] Failed to refresh token', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
            $config['authentication'] = 'oauth';
            $config['password'] = $account->access_token;
        }

        return $cm->make($config);
    }

    protected function parseMessage($message): array
    {
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

        // Process attachments
        $attachments = [];
        if ($message->hasAttachments()) {
            foreach ($message->getAttachments() as $attachment) {
                // Webklex exposes content_id as $id public property
                $contentId = $attachment->id ?? null;

                // Remove angle brackets if present
                if ($contentId) {
                    $contentId = trim($contentId, '<>');
                }

                $attachments[] = [
                    'name' => $attachment->getName(),
                    'content' => $attachment->getContent(),
                    'mime' => $attachment->getMimeType(),
                    'content_id' => $contentId,
                ];
            }
        }

        // Process headers
        $headers = [];
        try {
            $rawHeaders = $message->getHeader()->getAttributes();
            foreach ($rawHeaders as $key => $value) {
                $headers[$key] = (string) $value;
            }
        } catch (\Throwable $e) {
        }

        return [
            'message_id' => (string) ($message->getMessageId()?->first() ?? ''),
            'from_email' => $fromEmail,
            'from_name' => (string) ($from->personal ?? $fromEmail),
            'to' => $this->formatRecipients($message->getTo()),
            'cc' => $this->formatRecipients($message->getCc()),
            'bcc' => $this->formatRecipients($message->getBcc()),
            'subject' => (string) ($message->getSubject()?->first() ?? '(No Subject)'),
            'preview' => (string) $preview,
            'body_html' => (string) ($message->getHTMLBody() ?? ''),
            'body_plain' => (string) $textBody,
            'headers' => $headers,
            'is_read' => $message->getFlags()->contains('Seen'),
            'is_starred' => $message->getFlags()->contains('Flagged'),
            'has_attachments' => $message->hasAttachments(),
            'attachments' => $attachments,
            'imap_uid' => (int) $message->getUid(),
            'date' => $message->getDate()?->first()?->toDate(),
        ];
    }

    /**
     * Format recipients to array of [name, email].
     */
    protected function formatRecipients($attribute): array
    {
        if (! $attribute || ! method_exists($attribute, 'toArray')) {
            return [];
        }

        $flattened = [];
        foreach ($attribute->toArray() as $recipient) {
            // recipient is Webklex\PHPIMAP\Address
            $flattened[] = [
                'name' => $recipient->personal,
                'email' => $recipient->mail,
            ];
        }

        return $flattened;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[FetchNewEmailsJob] Job failed', [
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['email', 'sync', 'incremental', 'account:'.$this->accountId];
    }
}
