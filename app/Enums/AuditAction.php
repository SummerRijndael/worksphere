<?php

namespace App\Enums;

enum AuditAction: string
{
    // Authentication
    case Login = 'login';
    case Logout = 'logout';
    case LoginFailed = 'login_failed';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case TwoFactorChallenged = 'two_factor_challenged';
    case EmailVerified = 'email_verified';
    case ImpersonationStarted = 'impersonation_started';
    case ImpersonationEnded = 'impersonation_ended';

    // CRUD Operations
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case ForceDeleted = 'force_deleted';
    case Archived = 'archived';

    // Member Operations
    case MemberAdded = 'member_added';
    case MemberRemoved = 'member_removed';

    // File Operations
    case FileUploaded = 'file_uploaded';
    case FileDeleted = 'file_deleted';

    // Permissions
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';
    case PermissionGranted = 'permission_granted';
    case PermissionRevoked = 'permission_revoked';
    case TeamPermissionGranted = 'team_permission_granted';
    case TeamPermissionRevoked = 'team_permission_revoked';

    // Team operations
    case TeamJoined = 'team_joined';
    case TeamLeft = 'team_left';
    case TeamMemberRemoved = 'team_member_removed';
    case TeamRoleChanged = 'team_role_changed';
    case TeamInvitationSent = 'team_invitation_sent';
    case TeamInvitationAccepted = 'team_invitation_accepted';
    case TeamInvitationDeclined = 'team_invitation_declined';
    case TeamInvitationCancelled = 'team_invitation_cancelled';

    // Ticket operations
    case TicketCreated = 'ticket_created';
    case TicketViewed = 'ticket_viewed';
    case TicketUpdated = 'ticket_updated';
    case TicketAssigned = 'ticket_assigned';
    case TicketStatusChanged = 'ticket_status_changed';
    case TicketCommentAdded = 'ticket_comment_added';
    case TicketAttachmentAdded = 'ticket_attachment_added';
    case TicketAttachmentRemoved = 'ticket_attachment_removed';

    // Link Unfurl Operations
    case LinkUnfurled = 'link_unfurled';
    case LinkBlocked = 'link_blocked';

    // Chat Operations
    case ChatMemberLeft = 'chat_member_left';
    case ChatMemberKicked = 'chat_member_kicked';
    case ChatDeleted = 'chat_deleted';
    case ChatMarkedForDeletion = 'chat_marked_for_deletion';
    case ChatDeletionCancelled = 'chat_deletion_cancelled';

    // Session management
    case SessionRevoked = 'session_revoked';
    case AllSessionsRevoked = 'all_sessions_revoked';

    // API tokens
    case TokenCreated = 'token_created';
    case TokenRevoked = 'token_revoked';

    // Data export/access
    case DataExported = 'data_exported';
    case DataAccessed = 'data_accessed';

    // System Maintenance
    case MaintenanceEnabled = 'maintenance_enabled';
    case MaintenanceDisabled = 'maintenance_disabled';
    case SystemSettingUpdated = 'system_setting_updated';
    case CacheCleared = 'cache_cleared';
    case SessionsCleared = 'sessions_cleared';
    case LogsCleared = 'logs_cleared';
    case ScheduledTaskRun = 'scheduled_task_run';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case AccountSuspended = 'account_suspended';
    case AccountBanned = 'account_banned';
    case SystemError = 'system_error';

    /**
     * Get the human-readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Login => 'User Logged In',
            self::Logout => 'User Logged Out',
            self::LoginFailed => 'Login Failed',
            self::PasswordChanged => 'Password Changed',
            self::PasswordReset => 'Password Reset',
            self::TwoFactorEnabled => 'Two-Factor Enabled',
            self::TwoFactorDisabled => 'Two-Factor Disabled',
            self::TwoFactorChallenged => 'Two-Factor Challenged',
            self::EmailVerified => 'Email Verified',
            self::ImpersonationStarted => 'Impersonation Started',
            self::ImpersonationEnded => 'Impersonation Ended',
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Restored => 'Restored',
            self::ForceDeleted => 'Permanently Deleted',
            self::Archived => 'Archived',
            self::MemberAdded => 'Member Added',
            self::MemberRemoved => 'Member Removed',
            self::FileUploaded => 'File Uploaded',
            self::FileDeleted => 'File Deleted',
            self::RoleAssigned => 'Role Assigned',
            self::RoleRevoked => 'Role Revoked',
            self::PermissionGranted => 'Permission Granted',
            self::PermissionRevoked => 'Permission Revoked',
            self::TeamPermissionGranted => 'Team Permission Granted',
            self::TeamPermissionRevoked => 'Team Permission Revoked',
            self::TeamJoined => 'Joined Team',
            self::TeamLeft => 'Left Team',
            self::TeamMemberRemoved => 'Removed Team Member',
            self::TeamRoleChanged => 'Team Role Changed',
            self::TeamInvitationSent => 'Team Invitation Sent',
            self::TeamInvitationAccepted => 'Team Invitation Accepted',
            self::TeamInvitationDeclined => 'Team Invitation Declined',
            self::TeamInvitationCancelled => 'Team Invitation Cancelled',
            self::SessionRevoked => 'Session Revoked',
            self::AllSessionsRevoked => 'All Sessions Revoked',
            self::TokenCreated => 'API Token Created',
            self::TokenRevoked => 'API Token Revoked',
            self::DataExported => 'Data Exported',
            self::DataAccessed => 'Data Accessed',
            self::MaintenanceEnabled => 'Maintenance Mode Enabled',
            self::MaintenanceDisabled => 'Maintenance Mode Disabled',
            self::SystemSettingUpdated => 'System Settings Updated',
            self::CacheCleared => 'Cache Cleared',
            self::SessionsCleared => 'All Sessions Cleared',
            self::LogsCleared => 'Logs Cleared',
            self::ScheduledTaskRun => 'Scheduled Task Run',
            self::RateLimitExceeded => 'Rate Limit Exceeded',
            self::AccountSuspended => 'Account Suspended',
            self::AccountBanned => 'Account Banned',
            self::SystemError => 'System Error',
            self::TicketCreated => 'Ticket Created',
            self::TicketViewed => 'Ticket Viewed',
            self::TicketUpdated => 'Ticket Updated',
            self::TicketAssigned => 'Ticket Assigned',
            self::TicketStatusChanged => 'Ticket Status Changed',
            self::TicketCommentAdded => 'Comment Added',
            self::TicketAttachmentAdded => 'Attachment Added',
            self::TicketAttachmentRemoved => 'Attachment Removed',
            self::LinkUnfurled => 'Link Unfurled',
            self::LinkBlocked => 'Link Blocked',
            self::ChatMemberLeft => 'Member Left Chat',
            self::ChatMemberKicked => 'Member Kicked from Chat',
            self::ChatDeleted => 'Chat Deleted',
            self::ChatMarkedForDeletion => 'Chat Marked for Deletion',
            self::ChatDeletionCancelled => 'Chat Deletion Cancelled',
        };
    }

    /**
     * Get the icon name for the action.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Login => 'login',
            self::Logout => 'logout',
            self::LoginFailed => 'alert-circle',
            self::PasswordChanged, self::PasswordReset => 'key',
            self::TwoFactorEnabled, self::TwoFactorDisabled, self::TwoFactorChallenged => 'shield',
            self::EmailVerified => 'mail-check',
            self::ImpersonationStarted => 'user-plus',
            self::ImpersonationEnded => 'user-minus',
            self::Created => 'plus-circle',
            self::Updated => 'edit',
            self::Deleted, self::ForceDeleted => 'trash',
            self::Restored => 'rotate-ccw',
            self::Archived => 'archive',
            self::MemberAdded => 'user-plus',
            self::MemberRemoved => 'user-minus',
            self::FileUploaded => 'upload',
            self::FileDeleted => 'file-x',
            self::RoleAssigned, self::RoleRevoked => 'user-cog',
            self::PermissionGranted, self::PermissionRevoked => 'lock',
            self::TeamPermissionGranted, self::TeamPermissionRevoked => 'users-cog',
            self::TeamJoined, self::TeamLeft, self::TeamMemberRemoved => 'users',
            self::TeamRoleChanged => 'user-check',
            self::TeamInvitationSent, self::TeamInvitationCancelled => 'mail',
            self::TeamInvitationAccepted => 'user-check',
            self::TeamInvitationDeclined => 'user-x',
            self::SessionRevoked, self::AllSessionsRevoked => 'log-out',
            self::TokenCreated, self::TokenRevoked => 'key',
            self::DataExported => 'download',
            self::DataAccessed => 'eye',
            self::MaintenanceEnabled, self::MaintenanceDisabled => 'wrench',
            self::SystemSettingUpdated => 'settings',
            self::CacheCleared => 'database',
            self::SessionsCleared => 'log-out',
            self::LogsCleared => 'file-x',
            self::ScheduledTaskRun => 'play',
            self::RateLimitExceeded => 'alert-triangle',
            self::AccountSuspended => 'lock',
            self::AccountBanned => 'slash',
            self::SystemError => 'alert-triangle',
            self::TicketCreated => 'ticket',
            self::TicketViewed => 'eye',
            self::TicketUpdated => 'edit',
            self::TicketAssigned => 'user-plus',
            self::TicketStatusChanged => 'refresh-cw',
            self::TicketCommentAdded => 'message-square',
            self::TicketAttachmentAdded => 'paperclip',
            self::TicketAttachmentRemoved => 'trash-2',
            self::LinkUnfurled => 'link',
            self::LinkBlocked => 'shield-off',
            self::ChatMemberLeft => 'log-out',
            self::ChatMemberKicked => 'user-x',
            self::ChatDeleted => 'trash-2',
            self::ChatMarkedForDeletion => 'alert-triangle',
            self::ChatDeletionCancelled => 'rotate-ccw',
        };
    }

    /**
     * Determine if this action is security-critical.
     */
    public function isCritical(): bool
    {
        return in_array($this, [
            self::Login,
            self::LoginFailed,
            self::PasswordChanged,
            self::PasswordReset,
            self::TwoFactorDisabled,
            self::AllSessionsRevoked,
            self::ForceDeleted,
            self::RateLimitExceeded, // Critical? Yes, potential DDoS
            self::AccountSuspended,
            self::AccountBanned,
        ]);
    }
}
