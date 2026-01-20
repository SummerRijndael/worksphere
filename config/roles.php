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
                'tickets.view',
                'tickets.create',
                'tickets.update',
                'tickets.assign',
                'tickets.close',
                'tickets.internal_notes',
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
            'roles.assign' => 'Assign roles to users',
        ],

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
            'tasks.update' => 'Update tasks',
            'tasks.update_assigned' => 'Update assigned tasks',
            'tasks.delete' => 'Delete tasks',
            'tasks.assign' => 'Assign tasks to users',
            'tasks.submit' => 'Submit tasks',
            'tasks.qa_review' => 'Review tasks (QA)',
            'tasks.approve' => 'Approve tasks',
            'tasks.reject' => 'Reject tasks',
            'tasks.send_to_client' => 'Send tasks to client',
            'tasks.archive' => 'Archive tasks',
        ],

        // Ticket Management
        'tickets' => [
            'tickets.view' => 'View all tickets',
            'tickets.view_own' => 'View own tickets',
            'tickets.create' => 'Create tickets',
            'tickets.update' => 'Update tickets',
            'tickets.update_own' => 'Update own tickets',
            'tickets.delete' => 'Delete tickets',
            'tickets.assign' => 'Assign tickets',
            'tickets.close' => 'Close tickets',
            'tickets.internal_notes' => 'View internal notes',
        ],

        // Reports
        'reports' => [
            'reports.view' => 'View reports',
            'reports.create' => 'Create reports',
            'reports.export' => 'Export reports',
        ],

        // Team Management
        'teams' => [
            'teams.view' => 'View teams',
            'teams.create' => 'Create teams',
            'teams.update' => 'Update teams',
            'teams.delete' => 'Delete teams',
            'teams.manage_members' => 'Manage team members',
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

        // Clients
        'clients' => [
            'clients.view' => 'View clients',
            'clients.create' => 'Create clients',
            'clients.update' => 'Update clients',
            'clients.delete' => 'Delete clients',
            'clients.manage_portal' => 'Manage client portal access',
        ],

        // Invoices
        'invoices' => [
            'invoices.view' => 'View invoices',
            'invoices.create' => 'Create invoices',
            'invoices.update' => 'Update invoices',
            'invoices.delete' => 'Delete invoices',
            'invoices.send' => 'Send invoices to clients',
            'invoices.record_payment' => 'Record invoice payments',
        ],

        // Invoice Templates
        'invoice_templates' => [
            'invoice_templates.view' => 'View invoice templates',
            'invoice_templates.create' => 'Create invoice templates',
            'invoice_templates.update' => 'Update invoice templates',
            'invoice_templates.delete' => 'Delete invoice templates',
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
            'tickets.view',
            'tickets.create',
            'tickets.update',
            'tickets.delete',
            'tickets.assign',
            'tickets.close',
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
            'tickets.view',
            'tickets.create',
            'tickets.update',
            'tickets.assign',
            'tickets.close',
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
