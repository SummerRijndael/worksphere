<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Statuses
    |--------------------------------------------------------------------------
    |
    | Define the possible user account statuses. These are used to control
    | user access and display appropriate messages.
    |
    */

    'statuses' => [
        'active' => [
            'label' => 'Active',
            'color' => 'success',
            'can_login' => true,
        ],
        'pending' => [
            'label' => 'Pending Verification',
            'color' => 'warning',
            'can_login' => false,
        ],
        'suspended' => [
            'label' => 'Suspended',
            'color' => 'error',
            'can_login' => false,
        ],
        'blocked' => [
            'label' => 'Blocked',
            'color' => 'error',
            'can_login' => false,
        ],
        'disabled' => [
            'label' => 'Disabled',
            'color' => 'secondary',
            'can_login' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Definitions
    |--------------------------------------------------------------------------
    |
    | Define the roles available in the application. Each role has a name,
    | description, and a list of permissions assigned to it.
    |
    */

    'roles' => [
        'administrator' => [
            'label' => 'Administrator',
            'description' => 'Full system access with all permissions',
            'color' => 'error',
            'level' => 100,
            'permissions' => ['*'], // All permissions
        ],
        'it_support' => [
            'label' => 'IT Support',
            'description' => 'Support related permissions (tickets, user management)',
            'color' => 'warning',
            'level' => 50,
            'permissions' => [
                'dashboard.view',
                'users.view',
                'users.update', // Reset password/MFA often falls under update
                'users.manage_status',
                'users.manage_status',
                'tickets.manage',
                'reports.view',
            ],
        ],
        'user' => [
            'label' => 'User',
            'description' => 'Regular user permissions for self-service actions',
            'color' => 'secondary',
            'level' => 10,
            'permissions' => [
                'dashboard.view',
                'tickets.view_own',
                'tickets.create',
                'tickets.update_own',
                'projects.view_assigned',
                'tasks.view_assigned',
                'tasks.update_assigned',
                'notes.view',
                'notes.create',
                'notes.update',
                'notes.delete',
                'teams.view',
                'teams.create',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Definitions
    |--------------------------------------------------------------------------
    |
    | Define all available permissions in the system. Organized by module
    | for easier management.
    |
    */

    'permissions' => [
        // Dashboard Module
        'dashboard' => [
            'dashboard.view' => ['label' => 'View dashboard', 'scope' => 'global'],
            'dashboard.analytics' => ['label' => 'View analytics data', 'scope' => 'global'],
            'dashboard.widgets' => ['label' => 'Manage dashboard widgets', 'scope' => 'global'],
        ],

        // User Management
        'users' => [
            'users.view' => ['label' => 'View users', 'scope' => 'global'],
            'users.create' => ['label' => 'Create users', 'scope' => 'global'],
            'users.update' => ['label' => 'Update users', 'scope' => 'global'],
            'users.delete' => ['label' => 'Delete users', 'scope' => 'global'],
            'users.impersonate' => ['label' => 'Impersonate users', 'scope' => 'global'],
            'users.manage_roles' => ['label' => 'Manage user roles', 'scope' => 'global'],
            'users.manage_status' => ['label' => 'Manage user status', 'scope' => 'global'],
            'users.manage_permissions' => ['label' => 'Manage user permission overrides', 'scope' => 'global'],
            'user_manage' => ['label' => 'Manage all user settings', 'scope' => 'global'],
        ],

        // Role Management
        'roles' => [
            'roles.view' => ['label' => 'View roles', 'scope' => 'global'],
            'roles.create' => ['label' => 'Create roles', 'scope' => 'global'],
            'roles.update' => ['label' => 'Update roles', 'scope' => 'global'],
            'roles.delete' => ['label' => 'Delete roles', 'scope' => 'global'],
            'roles.assign' => ['label' => 'Assign roles to users', 'scope' => 'global'],
        ],

        // Project Management
        'projects' => [
            'projects.view' => ['label' => 'View all projects', 'scope' => 'team'],
            'projects.view_assigned' => ['label' => 'View assigned projects', 'scope' => 'team'],
            'projects.create' => ['label' => 'Create projects', 'scope' => 'team'],
            'projects.update' => ['label' => 'Update projects', 'scope' => 'team'],
            'projects.delete' => ['label' => 'Delete projects', 'scope' => 'team'],
            'projects.archive' => ['label' => 'Archive projects', 'scope' => 'team'],
            'projects.assign' => ['label' => 'Assign team members to projects', 'scope' => 'team'],
            'projects.manage_members' => ['label' => 'Manage project members', 'scope' => 'team'],
            'projects.manage_files' => ['label' => 'Manage project files', 'scope' => 'team'],
        ],

        // Task Management
        'tasks' => [
            'tasks.view' => ['label' => 'View all tasks', 'scope' => 'team'],
            'tasks.view_assigned' => ['label' => 'View assigned tasks', 'scope' => 'team'],
            'tasks.create' => ['label' => 'Create tasks', 'scope' => 'team'],
            'tasks.update' => ['label' => 'Update tasks', 'scope' => 'team'],
            'tasks.update_assigned' => ['label' => 'Update assigned tasks', 'scope' => 'team'],
            'tasks.delete' => ['label' => 'Delete tasks', 'scope' => 'team'],
            'tasks.assign' => ['label' => 'Assign tasks to users', 'scope' => 'team'],
            'tasks.submit' => ['label' => 'Submit tasks', 'scope' => 'team'],
            'tasks.qa_review' => ['label' => 'Review tasks (QA)', 'scope' => 'team'],
            'tasks.approve' => ['label' => 'Approve tasks', 'scope' => 'team'],
            'tasks.reject' => ['label' => 'Reject tasks', 'scope' => 'team'],
            'tasks.send_to_client' => ['label' => 'Send tasks to client', 'scope' => 'team'],
            'tasks.archive' => ['label' => 'Archive tasks', 'scope' => 'team'],
        ],

        // Ticket Management
        'tickets' => [
            'tickets.view' => ['label' => 'View all tickets', 'scope' => 'team'],
            'tickets.manage' => ['label' => 'Manage all tickets', 'scope' => 'global'],
            'tickets.view_own' => ['label' => 'View own tickets', 'scope' => 'team'],
            'tickets.create' => ['label' => 'Create tickets', 'scope' => 'team'],
            'tickets.update' => ['label' => 'Update tickets', 'scope' => 'team'],
            'tickets.update_own' => ['label' => 'Update own tickets', 'scope' => 'team'],
            'tickets.delete' => ['label' => 'Delete tickets', 'scope' => 'team'],
            'tickets.assign' => ['label' => 'Assign tickets', 'scope' => 'team'],
            'tickets.close' => ['label' => 'Close tickets', 'scope' => 'team'],
            'tickets.internal_notes' => ['label' => 'View internal notes', 'scope' => 'team'],
        ],

        // Reports
        'reports' => [
            'reports.view' => ['label' => 'View reports', 'scope' => 'team'],
            'reports.create' => ['label' => 'Create reports', 'scope' => 'team'],
            'reports.export' => ['label' => 'Export reports', 'scope' => 'team'],
        ],

        // Team Management
        'teams' => [
            'teams.view' => ['label' => 'View teams', 'scope' => 'team'],
            'teams.create' => ['label' => 'Create teams', 'scope' => 'global'], // Creating a team is often global context
            'teams.update' => ['label' => 'Update teams', 'scope' => 'team'],
            'teams.delete' => ['label' => 'Delete teams', 'scope' => 'team'],
            'teams.manage_members' => ['label' => 'Manage team members', 'scope' => 'team'],
        ],

        // Settings
        'settings' => [
            'settings.view' => ['label' => 'View settings', 'scope' => 'global'],
            'settings.update' => ['label' => 'Update settings', 'scope' => 'global'],
            'settings.system' => ['label' => 'Manage system settings', 'scope' => 'global'],
        ],

        // Audit
        'audit' => [
            'audit.view' => ['label' => 'View audit logs', 'scope' => 'global'],
            'audit.export' => ['label' => 'Export audit logs', 'scope' => 'global'],
        ],

        // System Administration
        'system' => [
            'system.maintenance' => ['label' => 'Access maintenance tools', 'scope' => 'global'],
            'system.settings' => ['label' => 'Manage system settings', 'scope' => 'global'],
            'system.logs' => ['label' => 'View system logs', 'scope' => 'global'],
            'system.manage_blocklist' => ['label' => 'Manage blocked URLs', 'scope' => 'global'],
            'system.manage_email' => ['label' => 'Manage system email accounts', 'scope' => 'global'],
        ],

        // Team Roles
        'team_roles' => [
            'team_roles.view' => ['label' => 'View team roles', 'scope' => 'team'],
            'team_roles.create' => ['label' => 'Create team roles', 'scope' => 'team'],
            'team_roles.update' => ['label' => 'Update team roles', 'scope' => 'team'],
            'team_roles.delete' => ['label' => 'Delete team roles', 'scope' => 'team'],
            'team_roles.assign' => ['label' => 'Assign roles to team members', 'scope' => 'team'],
        ],

        // Task Templates
        'task_templates' => [
            'task_templates.view' => ['label' => 'View task templates', 'scope' => 'team'],
            'task_templates.create' => ['label' => 'Create task templates', 'scope' => 'team'],
            'task_templates.update' => ['label' => 'Update task templates', 'scope' => 'team'],
            'task_templates.delete' => ['label' => 'Delete task templates', 'scope' => 'team'],
        ],

        // QA Check Templates
        'qa_checks' => [
            'qa_checks.view' => ['label' => 'View QA check templates', 'scope' => 'team'],
            'qa_checks.create' => ['label' => 'Create QA check templates', 'scope' => 'team'],
            'qa_checks.update' => ['label' => 'Update QA check templates', 'scope' => 'team'],
            'qa_checks.delete' => ['label' => 'Delete QA check templates', 'scope' => 'team'],
        ],

        // Clients
        'clients' => [
            'clients.view' => ['label' => 'View clients', 'scope' => 'team'],
            'clients.create' => ['label' => 'Create clients', 'scope' => 'team'],
            'clients.update' => ['label' => 'Update clients', 'scope' => 'team'],
            'clients.delete' => ['label' => 'Delete clients', 'scope' => 'team'],
            'clients.manage_portal' => ['label' => 'Manage client portal access', 'scope' => 'team'],
        ],

        // Invoices
        'invoices' => [
            'invoices.view' => ['label' => 'View invoices', 'scope' => 'team'],
            'invoices.create' => ['label' => 'Create invoices', 'scope' => 'team'],
            'invoices.update' => ['label' => 'Update invoices', 'scope' => 'team'],
            'invoices.delete' => ['label' => 'Delete invoices', 'scope' => 'team'],
            'invoices.send' => ['label' => 'Send invoices to clients', 'scope' => 'team'],
            'invoices.record_payment' => ['label' => 'Record invoice payments', 'scope' => 'team'],
        ],

        // Invoice Templates
        'invoice_templates' => [
            'invoice_templates.view' => ['label' => 'View invoice templates', 'scope' => 'team'],
            'invoice_templates.create' => ['label' => 'Create invoice templates', 'scope' => 'team'],
            'invoice_templates.update' => ['label' => 'Update invoice templates', 'scope' => 'team'],
            'invoice_templates.delete' => ['label' => 'Delete invoice templates', 'scope' => 'team'],
        ],

        // Personal Notes
        'notes' => [
            'notes.view' => ['label' => 'View personal notes', 'scope' => 'global'],
            'notes.create' => ['label' => 'Create personal notes', 'scope' => 'global'],
            'notes.update' => ['label' => 'Update personal notes', 'scope' => 'global'],
            'notes.delete' => ['label' => 'Delete personal notes', 'scope' => 'global'],
        ],

        // Chat Management
        'chats' => [
            'chats.manage' => ['label' => 'Manage active and deleted chats', 'scope' => 'global'],
        ],

        // FAQ Management
        'faq' => [
            'faq.view' => ['label' => 'View FAQ articles (Admin)', 'scope' => 'global'],
            'faq.manage' => ['label' => 'Manage FAQ categories and articles', 'scope' => 'global'],
        ],

        // Service Plans Management
        'services' => [
            'services.view' => ['label' => 'View service plans', 'scope' => 'global'],
            'services.manage' => ['label' => 'Manage service plans and pricing', 'scope' => 'global'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Role
    |--------------------------------------------------------------------------
    |
    | The default role assigned to new users upon registration.
    |
    */

    'default_role' => 'user',

    /*
    |--------------------------------------------------------------------------
    | Super Admin Role
    |--------------------------------------------------------------------------
    |
    | The role that has access to everything. This role bypasses all
    | permission checks.
    |
    */

    'super_admin_role' => 'administrator',

    /*
    |--------------------------------------------------------------------------
    | Team Role Permissions
    |--------------------------------------------------------------------------
    |
    | Define the default permissions for each team role. These permissions
    | are inherited by users based on their role within a specific team.
    |
    */

    'team_role_permissions' => [
        'team_lead' => [
            // Full team management
            'teams.view',
            'teams.update',
            'teams.delete',
            'teams.manage_members',

            // Team roles
            'team_roles.view',
            'team_roles.create',
            'team_roles.update',
            'team_roles.delete',
            'team_roles.assign',

            // Projects within team
            'projects.view',
            'projects.create',
            'projects.update',
            'projects.delete',
            'projects.archive',
            'projects.assign',
            'projects.manage_members',
            'projects.manage_files',

            // Tasks within team
            'tasks.view',
            'tasks.create',
            'tasks.update',
            'tasks.delete',
            'tasks.assign',
            'tasks.submit',
            'tasks.qa_review',
            'tasks.approve',
            'tasks.reject',
            'tasks.send_to_client',
            'tasks.archive',

            // Task templates
            'task_templates.view',
            'task_templates.create',
            'task_templates.update',
            'task_templates.delete',

            // QA checks
            'qa_checks.view',
            'qa_checks.create',
            'qa_checks.update',
            'qa_checks.delete',

            // Clients
            'clients.view',
            'clients.create',
            'clients.update',
            'clients.delete',
            'clients.manage_portal',

            // Invoices
            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.delete',
            'invoices.send',
            'invoices.record_payment',

            // Invoice templates
            'invoice_templates.view',
            'invoice_templates.create',
            'invoice_templates.update',
            'invoice_templates.delete',

            // Tickets within team
            'tickets.view_own',
            'tickets.create',
            'tickets.update_own',
            'tickets.internal_notes',

            // Reports
            'reports.view',
            'reports.create',
            'reports.export',
        ],
        'subject_matter_expert' => [
            // Team management (limited)
            'teams.view',
            'teams.update',
            'teams.manage_members',

            // Team roles (limited - cannot delete or manage lead)
            'team_roles.view',
            'team_roles.assign',

            // Projects within team
            'projects.view',
            'projects.create',
            'projects.update',
            'projects.archive',
            'projects.assign',
            'projects.manage_members',
            'projects.manage_files',

            // Tasks within team
            'tasks.view',
            'tasks.create',
            'tasks.update',
            'tasks.delete',
            'tasks.assign',
            'tasks.submit',
            'tasks.qa_review',
            'tasks.approve',
            'tasks.reject',
            'tasks.send_to_client',
            'tasks.archive',

            // Task templates
            'task_templates.view',
            'task_templates.create',
            'task_templates.update',

            // QA checks
            'qa_checks.view',
            'qa_checks.create',
            'qa_checks.update',

            // Clients
            'clients.view',
            'clients.create',
            'clients.update',

            // Invoices
            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.send',
            'invoices.record_payment',

            // Invoice templates
            'invoice_templates.view',

            // Tickets within team
            'tickets.view_own',
            'tickets.create',
            'tickets.internal_notes',

            // Reports
            'reports.view',
            'reports.export',
        ],
        'quality_assessor' => [
            // Limited view
            'teams.view',
            'projects.view',
            'clients.view',

            // Tasks - QA focus
            'tasks.view',
            'tasks.qa_review', // Can review
            'tasks.approve',   // Can approve
            'tasks.reject',    // Can reject

            // Cannot create/delete tasks, but can see them
        ],
        'operator' => [
            // Assigned work only
            'teams.view',
            'projects.view_assigned',
            'tasks.view_assigned',

            // CANNOT update task details, add/remove checklist (controlled by policies/UI)
            // But can likely "complete" subtasks if assigned? Instructions said "cannot update task details"

            'tickets.view_own', // Or assigned
            'reports.view', // Maybe?
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Change Approval Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the multi-admin approval workflow.
    |
    */

    'role_change_approval_count' => 2,

    'approval_required_roles' => [
        'administrator',
        'it_support',
    ],

];
