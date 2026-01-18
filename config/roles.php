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
        'project_manager' => [
            'label' => 'Project Manager',
            'description' => 'Manage projects, teams, and resources',
            'color' => 'primary',
            'level' => 75,
            'permissions' => [
                // Dashboard
                'dashboard.view',
                'dashboard.analytics',

                // Users (limited)
                'users.view',
                'users.create',
                'users.update',

                // Projects (full)
                'projects.view',
                'projects.create',
                'projects.update',
                'projects.delete',
                'projects.archive',
                'projects.assign',

                // Tasks (full)
                'tasks.view',
                'tasks.create',
                'tasks.update',
                'tasks.delete',
                'tasks.assign',

                // Tickets (full)
                'tickets.view',
                'tickets.create',
                'tickets.update',
                'tickets.delete',
                'tickets.assign',
                'tickets.close',

                // Reports
                'reports.view',
                'reports.export',

                // Teams
                'teams.view',
                'teams.create',
                'teams.update',
                'teams.manage_members',
            ],
        ],
        'operator' => [
            'label' => 'Operator',
            'description' => 'Handle tickets and daily operations',
            'color' => 'warning',
            'level' => 50,
            'permissions' => [
                // Dashboard
                'dashboard.view',

                // Tickets (manage assigned)
                'tickets.view',
                'tickets.create',
                'tickets.update',
                'tickets.assign',
                'tickets.close',

                // Tasks (manage assigned)
                'tasks.view',
                'tasks.create',
                'tasks.update',

                // Projects (view only)
                'projects.view',

                // Reports (view only)
                'reports.view',
            ],
        ],
        'user' => [
            'label' => 'User',
            'description' => 'Basic user access',
            'color' => 'secondary',
            'level' => 10,
            'permissions' => [
                // Dashboard
                'dashboard.view',

                // Tickets (own only)
                'tickets.view_own',
                'tickets.create',
                'tickets.update_own',

                // Projects (view assigned)
                'projects.view_assigned',

                // Tasks (view assigned)
                'tasks.view_assigned',
                'tasks.update_assigned',

                // Personal Notes
                'notes.view',
                'notes.create',
                'notes.update',
                'notes.delete',
            ],
        ],
        'client' => [
            'label' => 'Client',
            'description' => 'Client access to portal',
            'color' => 'success',
            'level' => 1,
            'permissions' => [
                // Dashboard
                'dashboard.view',

                // Tickets
                'tickets.view_own',
                'tickets.create',
                'tickets.update_own',

                // Projects
                'projects.view_assigned',

                // Invoices
                'invoices.view',
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
            'user_manage' => 'Manage all user settings', // New broad permission
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

        // Announcements
        'announcements' => [
            'announcements.view' => 'View announcement management',
            'announcements.create' => 'Create announcements',
            'announcements.update' => 'Edit announcements',
            'announcements.delete' => 'Delete announcements',
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
        'owner' => [
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
        'admin' => [
            // Team management (limited)
            'teams.view',
            'teams.update',
            'teams.manage_members',

            // Team roles (limited)
            'team_roles.view',
            'team_roles.create',
            'team_roles.update',
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
        'member' => [
            // Team view only
            'teams.view',

            // Team roles (view only)
            'team_roles.view',

            // Projects (view + update assigned)
            'projects.view',
            'projects.update',
            'projects.manage_files',

            // Tasks (full for assigned)
            'tasks.view',
            'tasks.create',
            'tasks.update',
            'tasks.submit',

            // Task templates (view only)
            'task_templates.view',

            // QA checks (view only)
            'qa_checks.view',

            // Clients (view only)
            'clients.view',

            // Invoices (view only)
            'invoices.view',

            // Tickets (manage own + view all)
            'tickets.view',
            'tickets.create',
            'tickets.update',

            // Reports (view only)
            'reports.view',
        ],
        'viewer' => [
            // Read-only access
            'teams.view',
            'team_roles.view',
            'projects.view_assigned',
            'tasks.view_assigned',
            'task_templates.view',
            'clients.view',
            'invoices.view',
            'tickets.view_own',
            'reports.view',
        ],
    ],

];
