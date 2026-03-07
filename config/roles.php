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
            'error_message' => 'Your account is pending verification. Please check your email for a verification link.',
            'color' => 'warning',
            'can_login' => false,
        ],
        'suspended' => [
            'label' => 'Suspended',
            'error_message' => 'Your account has been suspended. Please contact support for more information.',
            'color' => 'error',
            'can_login' => false,
        ],
        'blocked' => [
            'label' => 'Blocked',
            'error_message' => 'Your account has been blocked due to a policy violation. Please contact support.',
            'color' => 'error',
            'can_login' => false,
        ],
        'disabled' => [
            'label' => 'Disabled',
            'error_message' => 'Your account is currently disabled. Please contact your administrator.',
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
                'dashboard.analytics',
                'users.view',
                'users.update',
                'users.manage_status',
                'tickets.view',
                'tickets.manage',
                'tickets.create',
                'tickets.reports',
                'clients.view_all',
                'reports.view_all',
                'notes.view',
                'notes.create',
                'notes.update',
                'notes.delete',
                'teams.view',
                'teams.create',
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

    'global_permissions' => [
        // Dashboard Module
        'dashboard' => [
            'dashboard.view' => 'View dashboard',
            'dashboard.analytics' => 'View analytics data',
            'dashboard.widgets' => 'Manage dashboard widgets',
        ],

        // User Management
        'users' => [
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.update' => 'Update users',
            'users.delete' => 'Delete users',
            'users.impersonate' => 'Impersonate users',
            'users.manage_roles' => 'Manage user roles',
            'users.manage_status' => 'Manage user status',
            'users.manage_permissions' => 'Manage user permission overrides',
            'user_manage' => 'Manage all user settings',
        ],

        // Role Management
        'roles' => [
            'roles.view' => 'View roles',
            'roles.create' => 'Create roles',
            'roles.update' => 'Update roles',
            'roles.delete' => 'Delete roles',
            'roles.manage' => 'Manage global role definitions and critical permissions',
            'roles.assign' => 'Assign roles to users',
        ],

        // Ticket Management (Global level)
        'tickets' => [
            'tickets.view' => 'View all tickets',
            'tickets.view_own' => 'View own tickets',
            'tickets.create' => 'Create tickets',
            'tickets.update' => 'Update tickets',
            'tickets.update_own' => 'Update own tickets',
            'tickets.manage' => 'Manage all tickets',
            'tickets.assign' => 'Assign tickets',
            'tickets.close' => 'Close tickets',
            'tickets.delete' => 'Delete tickets',
            'tickets.internal_notes' => 'View internal notes',
            'tickets.reports' => 'View ticket analytics and reports',
        ],

        // Team Management (Global context)
        'teams' => [
            'teams.create' => 'Create teams',
        ],

        // Settings
        'settings' => [
            'settings.view' => 'View settings',
            'settings.update' => 'Update settings',
            'settings.system' => 'Manage system settings',
        ],

        // Audit
        'audit' => [
            'audit.view' => 'View audit logs',
            'audit.export' => 'Export audit logs',
        ],

        // System Administration
        'system' => [
            'system.maintenance' => 'Access maintenance tools',
            'system.settings' => 'Manage system settings',
            'system.logs' => 'View system logs',
            'system.manage_blocklist' => 'Manage blocked URLs',
            'system.manage_email' => 'Manage system email accounts',
        ],

        // Personal Notes
        'notes' => [
            'notes.view' => 'View personal notes',
            'notes.create' => 'Create personal notes',
            'notes.update' => 'Update personal notes',
            'notes.delete' => 'Delete personal notes',
        ],

        // Chat Management
        'chats' => [
            'chats.manage' => 'Manage active and deleted chats',
        ],

        // FAQ Management
        'faq' => [
            'faq.view' => 'View FAQ articles (Admin)',
            'faq.manage' => 'Manage FAQ categories and articles',
        ],

        // Service Plans Management
        'services' => [
            'services.view' => 'View service plans',
            'services.manage' => 'Manage service plans and pricing',
        ],

        // Client Management (Global context)
        'clients' => [
            'clients.view_all' => 'View all clients (Support)',
            'clients.manage' => 'Full client management override (Admin)',
            'clients.manage_portal' => 'Manage client portal access',
        ],

        // Reports (Global context)
        'reports' => [
            'reports.view_all' => 'View all reports (Support)',
            'reports.manage' => 'Full report management override (Admin)',
            'reports.create' => 'Create reports',
            'reports.export' => 'Export reports',
        ],
    ],

    'team_permissions' => [
        // Project Management
        'projects' => [
            'projects.view' => 'View all projects',
            'projects.view_assigned' => 'View assigned projects',
            'projects.create' => 'Create projects',
            'projects.update' => 'Update projects',
            'projects.delete' => 'Delete projects',
            'projects.archive' => 'Archive projects',
            'projects.assign' => 'Assign team members to projects',
            'projects.manage_members' => 'Manage project members',
            'projects.manage_files' => 'Manage project files',
        ],

        // Task Management
        'tasks' => [
            'tasks.view' => 'View all tasks',
            'tasks.view_assigned' => 'View assigned tasks',
            'tasks.create' => 'Create tasks',
            'tasks.edit_all' => 'Edit all task fields',
            'tasks.update_assigned' => 'Update assigned tasks (limited)',
            'tasks.delete' => 'Delete tasks',
            'tasks.assign' => 'Assign tasks to users',
            'tasks.manage_checklist' => 'Add/Remove checklist items',
            'tasks.complete_items' => 'Complete checklist items',
            'tasks.submit' => 'Submit tasks',
            'tasks.qa_review' => 'Review tasks (QA)',
            'tasks.approve' => 'Approve tasks',
            'tasks.reject' => 'Reject tasks',
            'tasks.send_to_client' => 'Send tasks to client',
            'tasks.archive' => 'Archive tasks',
            'tasks.comment' => 'Add comments to tasks',
            'tasks.client_response' => 'Record client approval/rejection',
        ],


        // Team Management (Team context)
        'teams' => [
            'teams.view' => 'View teams',
            'teams.update' => 'Update teams',
            'teams.delete' => 'Delete teams',
            'teams.manage_members' => 'Manage team members',
        ],

        // Team Roles
        'team_roles' => [
            'team_roles.view' => 'View team roles',
            'team_roles.create' => 'Create team roles',
            'team_roles.update' => 'Update team roles',
            'team_roles.delete' => 'Delete team roles',
            'team_roles.assign' => 'Assign roles to team members',
        ],

        // Task Templates
        'task_templates' => [
            'task_templates.view' => 'View task templates',
            'task_templates.create' => 'Create task templates',
            'task_templates.update' => 'Update task templates',
            'task_templates.delete' => 'Delete task templates',
        ],

        // QA Check Templates
        'qa_checks' => [
            'qa_checks.view' => 'View QA check templates',
            'qa_checks.create' => 'Create QA check templates',
            'qa_checks.update' => 'Update QA check templates',
            'qa_checks.delete' => 'Delete QA check templates',
        ],

        'invoices' => [
            'invoices.view' => 'View invoices only',
            'invoices.create' => 'Create invoices',
            'invoices.update' => 'Update invoices',
            'invoices.record_payment' => 'Record invoice payments and send',
            'invoices.send' => 'Send invoices to clients',
            'invoices.manage' => 'Full invoice management (create, update, delete, record, void)',
        ],

        // Invoice Templates
        'invoice_templates' => [
            'invoice_templates.view' => 'View invoice templates',
            'invoice_templates.create' => 'Create invoice templates',
            'invoice_templates.update' => 'Update invoice templates',
            'invoice_templates.delete' => 'Delete invoice templates',
        ],

        // Client Management (Team context)
        'clients' => [
            'clients.view' => 'View team clients',
            'clients.create' => 'Create team clients',
            'clients.update' => 'Update team clients',
            'clients.delete' => 'Delete team clients',
        ],

        // Reports (Team context)
        'reports' => [
            'reports.view' => 'View team reports',
            'reports.create' => 'Create team reports',
            'reports.export' => 'Export team reports',
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
            'tasks.edit_all',
            'tasks.delete',
            'tasks.assign',
            'tasks.submit',
            'tasks.qa_review',
            'tasks.approve',
            'tasks.reject',
            'tasks.send_to_client',
            'tasks.archive',
            'tasks.comment',
            'tasks.client_response',

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

            // Invoices
            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.manage',

            // Invoice templates
            'invoice_templates.view',
            'invoice_templates.create',
            'invoice_templates.update',
            'invoice_templates.delete',

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
            'projects.update', // Can update details but NOT create/delete
            'projects.archive',
            'projects.assign',
            // Removed projects.create and projects.manage_members per scoping requirements
            'projects.manage_files',

            // Tasks within team
            'tasks.view',
            'tasks.create',
            'tasks.update',
            'tasks.edit_all',
            'tasks.delete',
            'tasks.assign',
            'tasks.submit',
            'tasks.qa_review',
            'tasks.approve',
            'tasks.reject',
            'tasks.send_to_client',
            'tasks.archive',
            'tasks.comment',
            'tasks.client_response',

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
            'clients.update',

            // Invoices
            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.record_payment',
            'invoices.send',

            // Invoice templates
            'invoice_templates.view',

            // Reports
            'reports.view',
            'reports.create',
            'reports.export',
        ],
        'quality_assessor' => [
            // Limited view
            'teams.view',
            'projects.view',
            'clients.view',

            // Tasks - QA focus
            'tasks.view',
            'tasks.create',    // Added per scoping requirements
            'tasks.update',    // Added per scoping requirements
            'tasks.edit_all',
            'tasks.assign',    // Added per scoping requirements
            'tasks.qa_review', // Can review
            'tasks.approve',   // Can approve
            'tasks.reject',    // Can reject
            'tasks.comment',
            'tasks.complete_items', // Occasionally need to fix/complete something in QA context?

            // Cannot delete tasks (reserved for Lead/SME)
        ],
        'operator' => [
            // Assigned work only
            'teams.view',
            'projects.view_assigned',
            'tasks.view_assigned',
            'tasks.complete_items',
            'tasks.submit',
            'tasks.comment',

            // CANNOT update task details, add/remove checklist (controlled by policies/UI)
            // But can likely "complete" subtasks if assigned? Instructions said "cannot update task details"

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
        'user',
    ],

];
