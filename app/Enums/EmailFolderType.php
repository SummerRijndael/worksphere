<?php

namespace App\Enums;

enum EmailFolderType: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Spam = 'spam';
    case Archive = 'archive';

    /**
     * Get priority for sync ordering.
     * Lower number = higher priority.
     */
    public function syncPriority(): int
    {
        return match ($this) {
            self::Inbox => 1,
            self::Sent => 2,
            self::Drafts => 3,
            self::Trash => 4,
            self::Archive => 5,
            self::Spam => 6,
        };
    }

    /**
     * Get display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Inbox',
            self::Sent => 'Sent',
            self::Drafts => 'Drafts',
            self::Trash => 'Trash',
            self::Spam => 'Spam',
            self::Archive => 'Archive',
        };
    }

    /**
     * Get IMAP folder name for this folder type.
     */
    public function imapFolder(): string
    {
        return match ($this) {
            self::Inbox => 'INBOX',
            self::Sent => '[Gmail]/Sent Mail', // Will be provider-aware
            self::Drafts => '[Gmail]/Drafts',
            self::Trash => '[Gmail]/Trash',
            self::Spam => '[Gmail]/Spam',
            self::Archive => '[Gmail]/All Mail',
        };
    }

    /**
     * Is this a system folder (cannot be deleted).
     */
    public function isSystem(): bool
    {
        return true;
    }

    /**
     * Get all priority folders for initial seed.
     *
     * @return EmailFolderType[]
     */
    public static function priorityFolders(): array
    {
        return [
            self::Inbox,
            self::Sent,
            self::Drafts,
            self::Trash,
        ];
    }

    /**
     * Get all folders ordered by sync priority.
     *
     * @return EmailFolderType[]
     */
    public static function syncOrder(): array
    {
        $folders = self::cases();
        usort($folders, fn ($a, $b) => $a->syncPriority() <=> $b->syncPriority());

        return $folders;
    }
}
