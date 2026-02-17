<?php

namespace App\Services\EmailAdapters;

use App\Enums\EmailFolderType;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Services\EmailSanitizationService;
use App\Services\EmailSyncService;
use App\Services\GmailApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Modern Gmail Adapter using Gmail API.
 */
class GmailAdapter extends BaseEmailAdapter
{
    protected GmailApiService $apiService;

    public function __construct()
    {
        parent::__construct();
        $this->apiService = app(GmailApiService::class);
    }

    public function getProvider(): string
    {
        return 'gmail';
    }

    /**
     * Gmail API doesn't use the IMAP client, but we keep this for interface compatibility
     * and potential fallback if needed, though we primarily use the API now.
     */
    public function createClient(EmailAccount $account, bool $fetchBody = true): Client
    {
        // For Gmail, we prefer the API service.
        // We only implement this if some part of the system still strictly requires an IMAP Client object.
        return parent::createClient($account, $fetchBody);
    }

    public function getFolderName(string $folderType): string
    {
        return match ($folderType) {
            EmailFolderType::Inbox->value => 'INBOX',
            EmailFolderType::Sent->value => 'SENT',
            EmailFolderType::Drafts->value => 'DRAFT',
            EmailFolderType::Trash->value => 'TRASH',
            EmailFolderType::Spam->value => 'SPAM',
            EmailFolderType::Archive => 'ALL', // Special mapping or handled by lack of labels
            default => 'INBOX',
        };
    }

    public function getFolderMapping(): array
    {
        return [
            EmailFolderType::Inbox->value => 'INBOX',
            EmailFolderType::Sent->value => 'SENT',
            EmailFolderType::Drafts->value => 'DRAFT',
            EmailFolderType::Trash->value => 'TRASH',
            EmailFolderType::Spam->value => 'SPAM',
        ];
    }

    /**
     * Get high-level folder status via API.
     */
    public function getFolderStatus(EmailAccount $account, string $folderType): array
    {
        try {
            $labelId = $this->getFolderName($folderType);
            $label = $this->apiService->getLabel($account, $labelId);

            return [
                'exists' => $label->getMessagesTotal() ?? 0,
                'unread' => $label->getMessagesUnread() ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to get folder status', [
                'account_id' => $account->id,
                'folder' => $folderType,
                'error' => $e->getMessage(),
            ]);

            return ['exists' => 0];
        }
    }

    /**
     * Fetch a chunk of messages via API.
     */
    public function fetchMessages(EmailAccount $account, string $folderType, int $offset, int $limit, bool $fetchBody = true): Collection
    {
        try {
            $labelId = $this->getFolderName($folderType);

            // Gmail API uses page tokens instead of numeric offsets.
            // For the initial implementation, we'll fetch the first batch.
            // Improvement: Store page tokens in sync_cursor if offset > limit.
            $result = $this->apiService->listMessages($account, $labelId, $limit);
            $messages = collect($result['messages'] ?? []);

            $format = $fetchBody ? 'full' : 'metadata';

            return $messages->map(function ($msgSummary) use ($account, $fetchBody, $format) {
                $fullMsg = $this->apiService->getMessage($account, $msgSummary->getId(), $format);

                return $this->parseGmailMessage($fullMsg, true, $account, $fetchBody);
            });
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to fetch messages', [
                'account_id' => $account->id,
                'folder' => $folderType,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch latest messages for a folder (Gmail API implementation).
     */
    public function fetchLatestMessagesForAccount(EmailAccount $account, string $folderType, int $count, bool $fetchBody = true): Collection
    {
        try {
            $labelId = $this->getFolderName($folderType);
            $result = $this->apiService->listMessages($account, $labelId, $count);

            $format = $fetchBody ? 'full' : 'metadata';
            $messages = collect();

            foreach ($result['messages'] as $msg) {
                $fullMsg = $this->apiService->getMessage($account, $msg->getId(), $format);
                $messages->push($this->parseGmailMessage($fullMsg, true, $account, $fetchBody));
            }

            return $messages;
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to fetch latest messages', [
                'account_id' => $account->id,
                'folder' => $folderType,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch incremental updates (Gmail API implementation).
     */
    public function fetchIncrementalUpdates(EmailAccount $account, bool $fetchBody = true): Collection
    {
        $cursor = $account->sync_cursor ?? [];
        $startHistoryId = $cursor['history_id'] ?? null;
        $format = $fetchBody ? 'full' : 'metadata';

        if (! $startHistoryId) {
            // No history ID yet, fallback to fetching latest from Inbox
            return $this->fetchLatestMessagesForAccount($account, 'inbox', 50, $fetchBody);
        }

        try {
            $result = $this->apiService->listHistory($account, (string) $startHistoryId);
            $newMessages = collect();

            if (! empty($result['history'])) {
                foreach ($result['history'] as $historyRecord) {
                    $messagesAdded = $historyRecord->getMessagesAdded();
                    if ($messagesAdded) {
                        foreach ($messagesAdded as $msgAdded) {
                            $msg = $msgAdded->getMessage();
                            $fullMsg = $this->apiService->getMessage($account, $msg->getId(), $format);
                            $newMessages->push($this->parseGmailMessage($fullMsg, true, $account, $fetchBody));
                        }
                    }
                }
            }

            return $newMessages;
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to fetch incremental updates', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            // If history ID is expired or not found, fallback to fetching latest
            $errorMsg = strtolower($e->getMessage());
            if (str_contains($errorMsg, 'expired') || str_contains($errorMsg, 'not found') || str_contains($errorMsg, 'notfound')) {
                Log::info('[GmailAdapter] History ID expired or not found, falling back to latest fetch', [
                    'account_id' => $account->id,
                    'history_id' => $startHistoryId,
                ]);

                return $this->fetchLatestMessagesForAccount($account, 'inbox', 50, $fetchBody);
            }

            return collect();
        }
    }

    /**
     * Subscribe to real-time notifications (Gmail API implementation).
     */
    public function subscribeToNotifications(EmailAccount $account): bool
    {
        $topicName = config('services.google.pubsub_topic');
        if (! $topicName) {
            Log::warning('[GmailAdapter] Pub/Sub topic not configured');

            return false;
        }

        try {
            $result = $this->apiService->watch($account, $topicName);

            // Store the initial historyId
            $cursor = $account->sync_cursor ?? [];
            $cursor['history_id'] = $result['historyId'];
            $account->update(['sync_cursor' => $cursor]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to setup watch', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Standardized parseMessage for unified interface.
     */
    public function parseMessage($message, bool $skipAttachments = false, bool $fetchBody = true): array
    {
        return $this->parseGmailMessage($message, $skipAttachments, null, $fetchBody);
    }

    /**
     * Parse Gmail API message to standardized array.
     */
    protected function parseGmailMessage(\Google\Service\Gmail\Message $message, bool $skipAttachments = false, ?EmailAccount $account = null, bool $fetchBody = true): array
    {
        $payload = $message->getPayload();
        $headers = collect($payload->getHeaders() ?: []);

        $getHeader = function ($name) use ($headers) {
            $header = $headers->first(fn ($h) => strtolower($h->getName()) === strtolower($name));
            $value = $header ? $header->getValue() : null;

            // Decode MIME encoded headers (e.g. =?UTF-8?Q?...?=)
            if ($value && str_contains($value, '=?')) {
                try {
                    $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

                    return $decoded ?: $value;
                } catch (\Throwable $e) {
                    return $value;
                }
            }

            return $value;
        };

        $from = $getHeader('From');
        $fromEmail = 'unknown@unknown.com';
        $fromName = $from;

        if (preg_match('/^(.*?)\s*<(.*?)>$/', $from, $matches)) {
            $fromName = trim($matches[1], '" ');
            $fromEmail = $matches[2];
        } else {
            $fromEmail = $from;
        }

        $body = ['html' => '', 'plain' => ''];
        $attachments = [];

        if ($fetchBody) {
            $body = $this->extractGmailBody($payload);
        }

        // Always extract attachment metadata regardless of fetchBody
        // When fetchBody=false, attachments are created as lazy placeholders (no content downloaded)
        $attachments = $this->extractGmailAttachments($payload, $body['html'], $skipAttachments, $message->getId(), $account);

        return [
            'message_id' => $getHeader('Message-ID'),
            'thread_id' => $message->getThreadId(),
            'gmail_id' => $message->getId(),
            'from_email' => $fromEmail,
            'from_name' => $fromName ?: $fromEmail,
            'to' => $this->parseGmailRecipients($getHeader('To')),
            'cc' => $this->parseGmailRecipients($getHeader('Cc')),
            'bcc' => [],
            'subject' => $getHeader('Subject') ?? '(No Subject)',
            'preview' => $message->getSnippet() ?: app(EmailSanitizationService::class)->generatePreview($body['plain'] ?: $body['html']),
            'body_html' => $body['html'],
            'body_plain' => $body['plain'],
            'headers' => $headers->mapWithKeys(fn ($h) => [strtolower($h->getName()) => $h->getValue()])->toArray(),
            'history_id' => $message->getHistoryId(),
            'is_read' => ! in_array('UNREAD', $message->getLabelIds() ?? []),
            'is_starred' => in_array('STARRED', $message->getLabelIds() ?? []),
            'has_attachments' => ! empty($attachments) || $this->hasGmailAttachments($payload),
            'attachments' => $attachments,
            'imap_uid' => null,
            'date' => $message->getInternalDate() ? date('Y-m-d H:i:s', $message->getInternalDate() / 1000) : now(),
        ];
    }

    protected function extractGmailBody($payload): array
    {
        $html = '';
        $plain = '';

        $processPart = function ($part) use (&$html, &$plain, &$processPart) {
            $mimeType = $part->getMimeType();
            $body = $part->getBody();
            $data = $body->getData();

            if ($data !== null && ! $part->getFilename()) {
                $decoded = $this->base64url_decode($data);
                if ($mimeType === 'text/html') {
                    $html .= $decoded;
                } elseif ($mimeType === 'text/plain') {
                    $plain .= $decoded;
                }
            }

            if ($part->getParts()) {
                foreach ($part->getParts() as $subPart) {
                    $processPart($subPart);
                }
            }
        };

        $processPart($payload);

        return ['html' => $html, 'plain' => $plain];
    }

    /**
     * Extract attachments from Gmail payload.
     */
    protected function extractGmailAttachments($payload, string $bodyHtml, bool $skipAttachments = false, ?string $messageId = null, ?EmailAccount $account = null): array
    {
        $attachments = [];

        $processPart = function ($part) use (&$attachments, $bodyHtml, $skipAttachments, $messageId, $account, &$processPart) {
            $filename = $part->getFilename();
            $mimeType = $part->getMimeType();
            $body = $part->getBody();
            if (! $body) {
                return;
            }
            $attachmentId = $body->getAttachmentId();
            $contentId = null;

            // Extract Content-ID from headers
            $headers = collect($part->getHeaders() ?: []);
            $cidHeader = $headers->first(fn ($h) => strtolower($h->getName()) === 'content-id');
            if ($cidHeader) {
                $contentId = trim($cidHeader->getValue(), '<>');
            }

            if ($filename || $attachmentId) {
                $isInline = false;
                if ($contentId && $bodyHtml && str_contains($bodyHtml, 'cid:'.$contentId)) {
                    $isInline = true;
                }

                $attachmentData = [
                    'id' => $attachmentId ?: uniqid('gmail_att_'),
                    'name' => $filename ?: 'unnamed_attachment',
                    'mime' => $mimeType,
                    'size' => $body->getSize() ?? 0,
                    'content_id' => $contentId,
                    'is_inline' => $isInline,
                    'attachment_id' => $attachmentId,
                    // Force download for inline images, otherwise respect skipAttachments
                    'is_lazy' => $isInline ? false : $skipAttachments,
                ];

                if (! $attachmentData['is_lazy']) {
                    if ($body->getData()) {
                        $attachmentData['content'] = $this->base64url_decode($body->getData());
                    } elseif ($attachmentId && $messageId && $account) {
                        try {
                            $fullAttachment = $this->apiService->getAttachment($account, $messageId, $attachmentId);
                            if ($fullAttachment->getData()) {
                                $attachmentData['content'] = $this->base64url_decode($fullAttachment->getData());
                            }
                        } catch (\Throwable $e) {
                            Log::warning('[GmailAdapter] Failed to fetch attachment content', [
                                'message_id' => $messageId,
                                'attachment_id' => $attachmentId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                $attachments[] = $attachmentData;
            }

            if ($part->getParts()) {
                foreach ($part->getParts() as $subPart) {
                    $processPart($subPart);
                }
            }
        };

        $processPart($payload);

        return $attachments;
    }

    /**
     * Robust base64url decoding with padding.
     */
    protected function base64url_decode(string $data): string
    {
        $base64 = strtr($data, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        return (string) base64_decode($base64);
    }

    protected function parseGmailRecipients($header): array
    {
        if (! $header) {
            return [];
        }

        $recipients = [];
        $parts = explode(',', $header);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^(.*?)\s*<(.*?)>$/', $part, $matches)) {
                $recipients[] = [
                    'name' => trim($matches[1], '" '),
                    'email' => $matches[2],
                ];
            } else {
                $recipients[] = ['name' => null, 'email' => $part];
            }
        }

        return $recipients;
    }

    protected function hasGmailAttachments($payload): bool
    {
        if ($payload->getFilename()) {
            return true;
        }

        if ($payload->getParts()) {
            foreach ($payload->getParts() as $part) {
                if ($part->getFilename()) {
                    return true;
                }
                if ($this->hasGmailAttachments($part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Backfill implementation for Gmail using API.
     */
    public function backfill(EmailAccount $account, ?string $folderType, int $batchSize, bool $fetchBody = true): array
    {
        try {
            $labelId = $this->getFolderName($folderType ?: EmailFolderType::Inbox->value);
            $cursor = $account->sync_cursor ?? [];
            $nextPageToken = $cursor['backfill_page_token'] ?? null;

            $result = $this->apiService->listMessages($account, $labelId, $batchSize, $nextPageToken);
            $messages = collect($result['messages'] ?? []);

            if ($messages->isEmpty()) {
                // If we have a page token but no messages, we must advance to the next page
                // to avoid getting stuck in an infinite loop.
                if (! empty($result['nextPageToken'])) {
                    $cursor = $account->sync_cursor ?? [];
                    $cursor['backfill_page_token'] = $result['nextPageToken'];
                    $account->update(['sync_cursor' => $cursor]);

                    return [
                        'fetched' => 0,
                        'has_more' => true,
                        'new_cursor' => $result['nextPageToken'],
                    ];
                }

                return ['fetched' => 0, 'has_more' => false];
            }

            // [NEW] Update backfill_page_token on the model immediately
            $cursor = $account->sync_cursor ?? [];
            $cursor['backfill_page_token'] = $result['nextPageToken'] ?? null;
            $account->update(['sync_cursor' => $cursor]);

            $fetched = 0;
            foreach ($messages as $msgSummary) {
                try {
                    // Check if message already exists by provider_id to avoid redundant full fetch
                    $exists = Email::where('email_account_id', $account->id)
                        ->where('provider_id', $msgSummary->getId())
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $fullMsg = $this->apiService->getMessage($account, $msgSummary->getId(), $fetchBody ? 'full' : 'metadata');
                    $emailData = $this->parseGmailMessage($fullMsg, true, $account, $fetchBody);

                    app(EmailSyncService::class)->storeEmail($account, $emailData, $labelId, false);
                    $fetched++;
                } catch (\Throwable $e) {
                    Log::warning('[GmailAdapter] Failed to process single message during backfill', [
                        'account_id' => $account->id,
                        'message_id' => $msgSummary->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Reload cursor from model to include any history_id updates from storeEmail
            $cursor = $account->sync_cursor ?? [];

            if (empty($cursor['labels'][$labelId])) {
                $cursor['labels'][$labelId] = [
                    'total' => $result['resultSizeEstimate'] ?? 0,
                    'synced' => 0,
                    'priority' => 1,
                ];
            }

            $cursor['backfill_page_token'] = $result['nextPageToken'] ?? null;
            $cursor['labels'][$labelId]['total'] = max($cursor['labels'][$labelId]['total'], $result['resultSizeEstimate'] ?? 0);
            $cursor['labels'][$labelId]['synced'] += $fetched;

            $account->sync_cursor = $cursor;
            $account->save();

            Log::info('[GmailAdapter] Backfill batch completed', [
                'account_id' => $account->id,
                'folder' => $labelId,
                'fetched' => $fetched,
                'total_synced' => $cursor['labels'][$labelId]['synced'],
                'estimated_total' => $cursor['labels'][$labelId]['total'],
                'has_more' => ! empty($result['nextPageToken']),
            ]);

            return [
                'fetched' => $fetched,
                'has_more' => ! empty($result['nextPageToken']),
                'new_cursor' => $cursor['backfill_page_token'],
            ];
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Backfill failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return ['fetched' => 0, 'has_more' => false];
        }
    }

    /**
     * List all labels as folders for Gmail.
     */
    public function listFolders(EmailAccount $account): \Illuminate\Support\Collection
    {
        try {
            $response = $this->apiService->listLabels($account);
            $labels = collect($response->getLabels() ?? []);

            return $labels->map(function ($label) {
                return [
                    'id' => $label->getId(),
                    'name' => $label->getName(),
                    'type' => $label->getType(), // system or user
                    'messages_total' => $label->getMessagesTotal(),
                    'messages_unread' => $label->getMessagesUnread(),
                ];
            });
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Failed to list labels', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch the full message (body and attachments) for an existing email.
     */
    public function fetchFullMessage(Email $email): array
    {
        // Use Gmail API if provider_id (gmail_id) is present
        if ($email->provider_id) {
            $fullMsg = $this->apiService->getMessage($email->emailAccount, $email->provider_id, 'full');

            return $this->parseGmailMessage($fullMsg, true, $email->emailAccount, true);
        }

        return parent::fetchFullMessage($email);
    }

    /**
     * Download a specific attachment for a Gmail email using the API.
     */
    public function downloadAttachment(\App\Models\Email $email, int $placeholderIndex): \Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        $placeholders = $email->attachment_placeholders ?? [];
        if (! isset($placeholders[$placeholderIndex])) {
            throw new \InvalidArgumentException('Attachment placeholder not found.');
        }

        $placeholder = $placeholders[$placeholderIndex];
        $account = $email->emailAccount;
        $attachmentId = $placeholder['attachment_id'] ?? null;

        // [Self-Healing] If attachment_id is missing, try to find it in the full message via API
        if (empty($attachmentId) && ! empty($email->provider_id)) {
            try {
                $fullMessage = $this->apiService->getMessage($account, $email->provider_id);
                $foundAttachments = $this->extractGmailAttachments(
                    $fullMessage->getPayload(),
                    $email->body_html ?? '',
                    false, // Don't skip, we specifically want the data
                    $fullMessage->getId(),
                    $account
                );

                foreach ($foundAttachments as $att) {
                    $matchByName = ($att['name'] === ($placeholder['name'] ?? null) && abs($att['size'] - ($placeholder['size'] ?? 0)) < 1024);
                    $matchByCid = (! empty($att['content_id']) && $att['content_id'] === ($placeholder['content_id'] ?? null));

                    if ($matchByCid || $matchByName) {
                        // Found it! If it already has content (small attachment), use it directly
                        if (! empty($att['content'])) {
                            return $this->storeFetchedAttachment($email, $att, $placeholders, $placeholderIndex);
                        }

                        $attachmentId = $att['attachment_id'] ?? null;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[GmailAdapter] Self-healing failed to find attachment_id', [
                    'email_id' => $email->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($attachmentId)) {
            // If still no attachment_id, fallback to parent IMAP if UID is available
            if ($email->imap_uid) {
                return parent::downloadAttachment($email, $placeholderIndex);
            }
            throw new \RuntimeException('Could not identify Gmail attachment ID for download.');
        }

        try {
            $attachmentData = $this->apiService->getAttachment(
                $account,
                $email->provider_id, // Gmail Message ID
                $attachmentId
            );

            $att = $placeholder;
            $att['content'] = $this->base64url_decode($attachmentData->getData());

            return $this->storeFetchedAttachment($email, $att, $placeholders, $placeholderIndex);
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] API attachment download failed', [
                'email_id' => $email->id,
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);

            // Fallback to IMAP if API fails and UID exists
            if ($email->imap_uid) {
                return parent::downloadAttachment($email, $placeholderIndex);
            }
            throw $e;
        }
    }

    /**
     * Helper to store a downloaded attachment into Media Library and cleanup placeholders.
     */
    protected function storeFetchedAttachment(\App\Models\Email $email, array $att, array $placeholders, int $placeholderIndex): \Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        // Store the attachment as Media
        $media = $email->addMediaFromString($att['content'])
            ->usingFileName($att['name'] ?? 'attachment')
            ->usingName($att['name'] ?? 'Attachment')
            ->toMediaCollection('attachments');

        if (! empty($att['content_id'])) {
            $media->setCustomProperty('content_id', $att['content_id']);
            $media->save();
        }

        // Remove from placeholders
        unset($placeholders[$placeholderIndex]);
        $email->update(['attachment_placeholders' => array_values($placeholders)]);

        // If it was an inline image, resolve it now
        if (! empty($att['content_id'])) {
            $sanitizer = app(\App\Services\EmailSanitizationService::class);
            $email->update([
                'body_html' => $sanitizer->resolveInlineImages($email),
            ]);
        }

        return $media;
    }

    /**
     * Fetch the raw RFC822 message source for an existing email.
     */
    public function fetchRawSource(\App\Models\Email $email): string
    {
        // Use Gmail API if provider_id (gmail_id) is present
        if ($email->provider_id) {
            // 'raw' format returns the full RFC822 message in 'raw' field (base64url encoded)
            $rawMsg = $this->apiService->getMessage($email->emailAccount, $email->provider_id, 'raw');

            return $this->base64url_decode($rawMsg->getRaw());
        }

        return parent::fetchRawSource($email);
    }
}
