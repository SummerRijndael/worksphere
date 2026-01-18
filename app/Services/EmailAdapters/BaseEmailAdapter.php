<?php

namespace App\Services\EmailAdapters;

use App\Contracts\EmailProviderAdapter;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

/**
 * Base adapter with common functionality for all email providers.
 */
abstract class BaseEmailAdapter implements EmailProviderAdapter
{
    protected ClientManager $clientManager;

    public function __construct()
    {
        $this->clientManager = new ClientManager;
    }

    /**
     * Extract UID from overview item - handles both object and array formats.
     */
    public function extractUidFromOverview(mixed $item): ?int
    {
        if (is_object($item)) {
            return isset($item->uid) ? (int) $item->uid : null;
        }

        if (is_array($item)) {
            return isset($item['uid']) ? (int) $item['uid'] : null;
        }

        return null;
    }

    /**
     * Fetch latest UIDs using overview() with UID range.
     */
    public function fetchLatestUids(Folder $folder, int $count): array
    {
        $totalInfo = $folder->examine();
        $uidnext = $totalInfo['uidnext'] ?? 0;
        $exists = $totalInfo['exists'] ?? 0;

        if ($exists === 0 || $uidnext === 0) {
            return [];
        }

        // Widen the range to account for sparse UIDs
        $rangeStart = max(1, $uidnext - ($count * 2));
        $range = "{$rangeStart}:*";

        try {
            $overview = $this->executeWithBackoff(fn () => $folder->overview($range));
            $uids = [];

            // Overview can return data in two ways:
            // 1. Objects/arrays with 'uid' property/key (some providers)
            // 2. Arrays keyed BY the UID (Gmail) - the key IS the UID
            foreach ($overview as $key => $item) {
                // First try to extract from item content
                $uid = $this->extractUidFromOverview($item);

                // If no uid in content, the array KEY is likely the UID (Gmail behavior)
                if ($uid === null && is_int($key) && $key > 0) {
                    $uid = $key;
                }

                if ($uid !== null) {
                    $uids[] = $uid;
                }
            }

            // Sort descending (newest first) and take requested count
            rsort($uids);

            return array_slice($uids, 0, $count);
        } catch (\Throwable $e) {
            Log::warning("[{$this->getProvider()}Adapter] Overview fetch failed", [
                'folder' => $folder->path,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch UIDs for a sequence range.
     */
    public function fetchUidRange(Folder $folder, int $start, int $end): array
    {
        $range = "{$start}:{$end}";

        try {
            $overview = $folder->overview($range);
            $uids = [];

            foreach ($overview as $key => $item) {
                $uid = $this->extractUidFromOverview($item);

                // Fallback: If no uid in content, use key if integer (Gmail behavior)
                if ($uid === null && is_int($key) && $key > 0) {
                    $uid = $key;
                }

                if ($uid !== null) {
                    $uids[] = $uid;
                }
            }

            return $uids;
        } catch (\Throwable $e) {
            Log::warning("[{$this->getProvider()}Adapter] UID range fetch failed", [
                'folder' => $folder->path,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get folder name from config.
     */
    public function getFolderName(string $folderType): string
    {
        $mapping = $this->getFolderMapping();

        return $mapping[$folderType] ?? strtoupper($folderType);
    }

    /**
     * Default: no OAuth support.
     */
    public function supportsOAuth(): bool
    {
        return false;
    }

    /**
     * Default: no token refresh needed.
     */
    public function refreshTokenIfNeeded(EmailAccount $account): bool
    {
        return true;
    }

    /**
     * Get max parallel folders from config.
     */
    public function getMaxParallelFolders(): int
    {
        return config("email.max_parallel_folders.{$this->getProvider()}", 2);
    }

    /**
     * Build base IMAP client configuration.
     */
    protected function buildBaseConfig(EmailAccount $account): array
    {
        return [
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $account->imap_encryption,
            'validate_cert' => true,
            'username' => $account->username ?? $account->email,
            'protocol' => 'imap',
            'timeout' => 30,
            // Important: Use PEEK mode to avoid marking messages as seen
            // and avoid STORE commands on READ-ONLY folders
            'options' => [
                'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                'sequence' => \Webklex\PHPIMAP\IMAP::ST_UID,
                'fetch_body' => true,
                'fetch_flags' => true,
                'soft_fail' => true, // Ignore certain exceptions when fetching
            ],
        ];
    }

    /**
     * Execute an operation with exponential backoff.
     */
    protected function executeWithBackoff(callable $operation, int $maxRetries = 3)
    {
        $delay = 1;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if ($i === $maxRetries - 1) {
                    throw $e;
                }

                // Only retry on typical connection/rate limit errors
                // We assume any error here *could* be transient for now
                Log::warning("[{$this->getProvider()}Adapter] Operation failed, retrying in {$delay}s", [
                    'error' => $e->getMessage(),
                ]);

                sleep($delay);
                $delay *= 2;
            }
        }
    }

    /**
     * Fetch the latest N messages from a folder.
     *
     * Default implementation: get UIDs then fetch messages.
     * Subclasses can override for more efficient provider-specific methods.
     */
    public function fetchLatestMessages(Folder $folder, int $count): \Illuminate\Support\Collection
    {
        $uids = $this->fetchLatestUids($folder, $count);

        if (empty($uids)) {
            // Fallback: use query with limit
            try {
                return $this->executeWithBackoff(fn () => $folder->query()->limit($count)->get());
            } catch (\Throwable $e) {
                Log::warning("[{$this->getProvider()}Adapter] Fallback query failed", [
                    'folder' => $folder->path,
                    'error' => $e->getMessage(),
                ]);

                return collect();
            }
        }

        // Fetch messages one by one to avoid "invalid sequence set" errors
        $messages = collect();
        foreach ($uids as $uid) {
            try {
                // Fetch individual message with backoff
                $msg = $this->executeWithBackoff(fn () => $folder->query()->getMessageByUid($uid));
                if ($msg) {
                    $messages->push($msg);
                }
            } catch (\Throwable $e) {
                Log::debug("[{$this->getProvider()}Adapter] Failed to fetch UID {$uid}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $messages;
    }
}
