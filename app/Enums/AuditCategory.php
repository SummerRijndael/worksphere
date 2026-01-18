<?php

namespace App\Enums;

enum AuditCategory: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case UserManagement = 'user_management';
    case TeamManagement = 'team_management';
    case ProjectManagement = 'project_management';
    case TaskManagement = 'task_management';
    case InvoiceManagement = 'invoice_management';
    case DataModification = 'data_modification';
    case Security = 'security';
    case System = 'system';
    case Api = 'api';
    case Communication = 'communication';

    /**
     * Get the human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Authentication => 'Authentication',
            self::Authorization => 'Authorization',
            self::UserManagement => 'User Management',
            self::TeamManagement => 'Team Management',
            self::ProjectManagement => 'Project Management',
            self::TaskManagement => 'Task Management',
            self::InvoiceManagement => 'Invoice Management',
            self::DataModification => 'Data Modification',
            self::Security => 'Security',
            self::System => 'System',
            self::Api => 'API',
            self::Communication => 'Communication',
        };
    }

    /**
     * Get the color associated with the category.
     */
    public function color(): string
    {
        return match ($this) {
            self::Authentication => 'blue',
            self::Authorization => 'purple',
            self::UserManagement => 'green',
            self::TeamManagement => 'cyan',
            self::ProjectManagement => 'teal',
            self::TaskManagement => 'amber',
            self::InvoiceManagement => 'emerald',
            self::DataModification => 'yellow',
            self::Security => 'red',
            self::System => 'gray',
            self::Api => 'orange',
            self::Communication => 'indigo',
        };
    }

    /**
     * Get the icon name for the category.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Authentication => 'log-in',
            self::Authorization => 'shield',
            self::UserManagement => 'users',
            self::TeamManagement => 'users-2',
            self::ProjectManagement => 'folder-kanban',
            self::TaskManagement => 'check-square',
            self::InvoiceManagement => 'file-text',
            self::DataModification => 'database',
            self::Security => 'alert-triangle',
            self::System => 'settings',
            self::Api => 'code',
            self::Communication => 'message-square',
        };
    }
}
