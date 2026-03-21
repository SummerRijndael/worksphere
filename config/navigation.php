<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sidebar Navigation
    |--------------------------------------------------------------------------
    |
    | Define the sidebar navigation structure. Each item can have children
    | for nested navigation. Items can be permission-gated.
    |
    */

    'sidebar' => [
        [
            'id' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'layout-dashboard',
            'route' => '/dashboard',
            'permission' => 'dashboard.view',
            'pinnable' => true,
            'pinned_default' => true,
        ],
        [
            'id' => 'email',
            'label' => 'Email',
            'icon' => 'mail',
            'route' => '/email',
            // 'permission' => 'email.view', // Optional
            'pinnable' => true,
            'pinned_default' => true,
        ],
        [
            'id' => 'analytics',
            'label' => 'Analytics',
            'icon' => 'chart-bar',
            'route' => '/analytics',
            'permission' => 'dashboard.analytics',
            'pinnable' => true,
            'pinned_default' => true,
        ],
        [
            'id' => 'projects',
            'label' => 'Projects',
            'icon' => 'folder',
            'route' => '/projects',
            'permission' => ['projects.view', 'projects.view_assigned'],
            'requires_team' => true,
            'pinnable' => true,
            'pinned_default' => true,
            'children' => [
                [
                    'id' => 'projects-all',
                    'label' => 'All Projects',
                    'route' => '/projects',
                    'permission' => 'projects.view',
                ],
                [
                    'id' => 'projects-my',
                    'label' => 'My Projects',
                    'route' => '/projects/my',
                    'permission' => 'projects.view_assigned',
                ],
                [
                    'id' => 'projects-archived',
                    'label' => 'Archived',
                    'route' => '/projects/archived',
                    'permission' => 'projects.archive',
                ],
            ],
        ],
        [
            'id' => 'clients',
            'label' => 'Clients',
            'icon' => 'users', // using users for now (shared with Teams, but distinct label)
            'route' => '/clients',
            'permission' => ['clients.view', 'clients.view_all'],
            'requires_team' => false,
            'pinnable' => true,
            'pinned_default' => true,
            'children' => [],
        ],
        [
            'id' => 'support',
            'label' => 'Support',
            'icon' => 'life-buoy',
            'route' => '/support',
            'permission' => ['tickets.view_own', 'tickets.view', 'tickets.manage', 'support.chats.view', 'support.chats.reply'],
            'pinnable' => true,
            'pinned_default' => true,
            'children' => [
                [
                    'id' => 'support-overview',
                    'label' => 'Overview',
                    'route' => '/support',
                    'permission' => ['support.chats.view', 'support.chats.reply', 'support.chats.assign', 'support.chats.resolve'],
                ],
                [
                    'id' => 'support-ticket-dashboard',
                    'label' => 'Ticket Dashboard',
                    'route' => '/support/tickets',
                    'permission' => 'tickets.manage',
                ],
                [
                    'id' => 'support-inbox',
                    'label' => 'Lead Inbox',
                    'route' => '/support/inbox',
                    'permission' => ['support.chats.view', 'support.chats.reply', 'tickets.manage'],
                ],
                [
                    'id' => 'support-workbench',
                    'label' => 'Agent Workbench',
                    'route' => '/support/workbench',
                    'permission' => ['support.chats.view', 'support.chats.reply', 'tickets.manage'],
                ],
                [
                    'id' => 'support-livechat-dashboard',
                    'label' => 'Live Chat Dashboard',
                    'route' => '/support/chats/dashboard',
                    'permission' => ['support.chats.view', 'support.chats.reply', 'tickets.manage'],
                ],
                [
                    'id' => 'support-helpdesk',
                    'label' => 'Helpdesk',
                    'route' => '/helpdesk',
                    'permission' => ['tickets.view_own', 'tickets.view', 'tickets.manage'],
                ],
            ],
        ],
        [
            'id' => 'calendar',
            'label' => 'Calendar',
            'icon' => 'calendar', // Make sure this icon exists or use 'calendar-days' or similar if needed. Assuming 'calendar' is valid.
            'route' => '/calendar',
            'pinnable' => true,
            'pinned_default' => true,
        ],
        [
            'id' => 'meetings',
            'label' => 'Meetings',
            'icon' => 'video',
            'route' => '/meetings',
            'pinnable' => true,
            'pinned_default' => true,
        ],
        [
            'id' => 'notes',
            'label' => 'Notes',
            'icon' => 'sticky-note', // Check Lucide icon name. 'sticky-note' is valid.
            'route' => '/notes',
            'pinnable' => true,
            'pinned_default' => true,
        ],
        [
            'id' => 'tasks',
            'label' => 'Tasks',
            'icon' => 'check-square',
            'route' => '/tasks',
            'permission' => ['tasks.view', 'tasks.view_assigned'],
            'requires_team' => true,
            'pinnable' => true,
            'pinned_default' => false,
        ],
        [
            'id' => 'teams',
            'label' => 'Teams',
            'icon' => 'users',
            'route' => '/teams',
            'pinnable' => true,
            'pinned_default' => false,
            'children' => [], // Will be populated dynamically with user's teams
        ],
        [
            'id' => 'reports',
            'label' => 'Reports',
            'icon' => 'file-text', // Check Lucide icon name. 'file-text' is valid.
            'route' => '/reports',
            'permission' => ['reports.view', 'reports.view_all', 'tickets.reports', 'support.chats.view', 'support.chats.reply', 'tickets.manage'],
            'pinnable' => true,
            'pinned_default' => false,
            'children' => [
                [
                    'id' => 'reports-overview',
                    'label' => 'Overview',
                    'route' => '/reports',
                    'permission' => 'reports.view',
                ],
                [
                    'id' => 'reports-projects',
                    'label' => 'Project Reports',
                    'route' => '/reports/projects',
                    'permission' => 'reports.view',
                ],
                [
                    'id' => 'reports-tickets',
                    'label' => 'Ticket Reports',
                    'route' => '/reports/tickets',
                    'permission' => 'tickets.reports',
                ],
                [
                    'id' => 'reports-surveys',
                    'label' => 'Survey Reports',
                    'route' => '/reports/surveys',
                    'permission' => ['support.chats.view', 'support.chats.reply', 'tickets.manage'],
                ],
            ],
        ],
        [
            'id' => 'invoices',
            'label' => 'Invoices',
            'icon' => 'file-text',
            'route' => '/invoices',
            'permission' => 'invoices.view',
            'pinnable' => true,
        ],

        // Divider - Admin Section
        [
            'id' => 'divider-admin',
            'type' => 'divider',
            'label' => 'Administration',
            'permission' => 'users.view',
        ],
        [
            'id' => 'chats',
            'label' => 'Chat Management',
            'icon' => 'message-square',
            'route' => '/admin/chats',
            'permission' => 'chats.manage',
            'pinnable' => true,
        ],
        [
            'id' => 'faq',
            'label' => 'FAQ Management',
            'icon' => 'book-open',
            'route' => '/admin/faq',
            'permission' => 'faq.manage',
            'pinnable' => true,
        ],
        [
            'id' => 'services',
            'label' => 'Services',
            'icon' => 'credit-card',
            'route' => '/services',
            'permission' => 'services.manage',
            'pinnable' => true,
        ],

        // User Management Section
        [
            'id' => 'user-management',
            'label' => 'User Management',
            'icon' => 'user-cog',
            'permission' => 'user_manage',
            'pinnable' => true,
            'pinned_default' => false,
            'children' => [
                [
                    'id' => 'users',
                    'label' => 'Users',
                    'route' => '/admin/users',
                    'permission' => 'user_manage',
                ],
                [
                    'id' => 'teams',
                    'label' => 'Teams',
                    'route' => '/admin/teams',
                    'permission' => 'user_manage',
                ],
                [
                    'id' => 'roles',
                    'label' => 'Roles & Permissions',
                    'route' => '/admin/roles',
                    'permission' => 'user_manage', // Placeholder
                    'badge' => 'Soon',
                ],
                [
                    'id' => 'projects-admin',
                    'label' => 'Projects',
                    'route' => '/admin/projects',
                    'permission' => 'user_manage',
                ],
                [
                    'id' => 'admin-clients',
                    'label' => 'Clients',
                    'route' => '/admin/clients',
                    'permission' => 'user_manage',
                ],
                [
                    'id' => 'admin-invoices',
                    'label' => 'Invoices',
                    'route' => '/admin/invoices',
                    'permission' => 'user_manage',
                ],

            ],
        ],
        [
            'id' => 'system',
            'label' => 'System Settings & Logs',
            'icon' => 'sliders',
            'permission' => ['audit.view', 'settings.system', 'security.view'],
            'pinnable' => true,
            'pinned_default' => false,
            'children' => [
                [
                    'id' => 'system-settings',
                    'label' => 'General Settings',
                    'route' => '/system/settings',
                    'permission' => 'settings.system',
                ],
                [
                    'id' => 'security-dashboard',
                    'label' => 'Security Dashboard',
                    'route' => '/admin/security',
                    'permission' => 'security.view',
                ],
                [
                    'id' => 'engagement-dashboard',
                    'label' => 'User Pulse',
                    'route' => '/admin/engagement',
                    'permission' => 'system.maintenance',
                ],
                [
                    'id' => 'system-logs',
                    'label' => 'System Logs',
                    'route' => '/system/logs',
                    'permission' => 'audit.view',
                ],
                [
                    'id' => 'system-maintenance',
                    'label' => 'Maintenance',
                    'route' => '/system/maintenance',
                    'permission' => 'settings.system',
                ],
            ],
        ],

        // Divider - User Section (bottom)
        [
            'id' => 'divider-user',
            'type' => 'divider',
        ],

        [
            'id' => 'notifications',
            'label' => 'Notifications',
            'icon' => 'bell',
            'route' => '/notifications',
            'pinnable' => false,
            'badge_key' => 'unread_notifications_count',
        ],
        [
            'id' => 'settings',
            'label' => 'Settings',
            'icon' => 'settings',
            'route' => '/settings',
            'pinnable' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Menu
    |--------------------------------------------------------------------------
    |
    | The user dropdown menu items shown when clicking the user avatar.
    |
    */

    'user_menu' => [
        [
            'id' => 'profile',
            'label' => 'Profile',
            'icon' => 'user',
            'route' => '/profile',
        ],
        [
            'id' => 'settings',
            'label' => 'Settings',
            'icon' => 'settings',
            'route' => '/settings',
        ],
        [
            'type' => 'divider',
        ],
        [
            'id' => 'logout',
            'label' => 'Sign Out',
            'icon' => 'log-out',
            'action' => 'logout',
        ],
    ],

];
