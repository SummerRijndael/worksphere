import { createRouter, createWebHistory } from "vue-router";
import type {
    RouteRecordRaw,
    Router,
    NavigationGuardNext,
    RouteLocationNormalized,
} from "vue-router";
import { useAuthStore } from "@/stores/auth";

// Layouts
import AuthLayout from "@/layouts/AuthLayout.vue";
import AppLayout from "@/layouts/AppLayout.vue";
import LegalLayout from "@/layouts/LegalLayout.vue";
import { appConfig } from "@/config/app";

// Views
import LoginView from "@/views/auth/LoginView.vue";
import PrivacyView from "@/views/legal/PrivacyView.vue";
import TermsView from "@/views/legal/TermsView.vue";
import DashboardView from "@/views/DashboardView.vue";
import SettingsView from "@/views/SettingsView.vue";
import ProfileView from "@/views/ProfileView.vue";
import NotificationsView from "@/views/NotificationsView.vue";
import AnalyticsView from "@/views/AnalyticsView.vue";
// ProjectsView import removed
import TicketsView from "@/views/TicketsView.vue";
import TicketDetailView from "@/views/TicketDetailView.vue";

// Define custom route meta interface
declare module "vue-router" {
    interface RouteMeta {
        requiresAuth?: boolean;
        requiresGuest?: boolean;
        title?: string;
        breadcrumb?: string;
        transition?: string;
        permission?: string;
        layout?: any;
        layoutFixed?: boolean;
        layoutFullWidth?: boolean;
        showBreadcrumbs?: boolean;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: "/404",
        name: "not-found",
        component: () => import("@/views/errors/ErrorView.vue"),
        props: { code: "404" },
        beforeEnter: (to, from, next) => {
            // Only allow access if redirected or specifically allowed
            // Ideally we just use the wildcard route below and don't expose /404 manually
            // But if we want to support internal redirects to /404:
            if (from.name || to.redirectedFrom) {
                next();
            } else {
                // Direct access -> redirect home or show error?
                // User asked to "prevent users on accessing... if errors dont actually existing"
                // So direct access to /404 implies user typed it. Redirect to home?
                next({ name: "dashboard" });
            }
        },
    },
    {
        path: "/403",
        name: "forbidden",
        component: () => import("@/views/errors/ErrorView.vue"),
        props: { code: "403" },
        beforeEnter: (to, from, next) => {
            // For simplicity, redirect to dashboard if accessed directly
            if (!from.name) next({ name: "dashboard" });
            else next();
        },
    },
    {
        path: "/500",
        name: "server-error",
        component: () => import("@/views/errors/ErrorView.vue"),
        props: { code: "500" },
        beforeEnter: (to, from, next) => {
            if (!from.name) next({ name: "dashboard" });
            else next();
        },
    },
    {
        path: "/503",
        name: "maintenance",
        component: () => import("@/views/errors/ErrorView.vue"),
        props: { code: "503" },
    },
    {
        path: "/p/:slug",
        name: "public-profile",
        component: () => import("@/views/PublicProfileView.vue"),
        meta: {
            title: "Profile",
            transition: "fade",
        },
    },
    {
        path: "/:pathMatch(.*)*",
        component: () => import("@/views/errors/ErrorView.vue"),
        props: { code: "404" },
    },
    {
        path: "/",
        name: "landing",
        component: () => import("@/views/LandingPage.vue"),
        meta: {
            title: `${appConfig.name} - Modern Project Management`,
            transition: "fade",
        },
    },
    {
        path: "/admin/tickets",
        name: "admin.tickets",
        component: () => import("@/views/TicketsView.vue"),
        meta: {
            title: "Tickets",
            requiresAuth: true,
            layout: "app",
            permission: "tickets.view",
        },
    },
    {
        path: "/public/faq",
        name: "public.faq",
        component: () => import("@/views/public/FaqView.vue"),
        meta: {
            title: "Knowledge Base",
            transition: "fade",
        },
    },
    {
        path: "/public/faq/:slug",
        name: "public.faq.article",
        component: () => import("@/views/public/FaqArticleView.vue"),
        meta: {
            title: "Article",
            transition: "fade",
        },
    },
    {
        path: "/auth",
        component: AuthLayout,
        meta: { requiresGuest: true },
        children: [
            {
                path: "",
                redirect: "/auth/login",
            },
            {
                path: "login",
                name: "login",
                component: LoginView,
                meta: {
                    title: "Sign In",
                    transition: "fade",
                },
            },
            {
                path: "reset-password",
                name: "reset-password",
                component: () => import("@/views/auth/ResetPasswordView.vue"),
                meta: {
                    title: "Reset Password",
                    transition: "fade",
                },
                beforeEnter: (to, from, next) => {
                    if (!to.query.token || !to.query.email) {
                        next({ name: "login" });
                    } else {
                        next();
                    }
                },
            },
        ],
    },
    {
        path: "/auth/verify-email",
        name: "verification.notice",
        component: () => import("@/views/auth/VerifyEmailView.vue"),
        meta: {
            layout: AuthLayout,
            title: "Verify Email",
            // requiresAuth: true - Removed to allow guests to see "Verified" status
        },
        beforeEnter: (to, from, next) => {
            const authStore = useAuthStore();
            // Allow if coming from verification link (verified=1) OR if logged in but unverified
            if (to.query.verified === "1") {
                next();
            } else if (
                authStore.isAuthenticated &&
                !authStore.user?.email_verified_at
            ) {
                next();
            } else {
                // If guest accessing without verified=1, redirect to login
                // If authenticated and verified accessing, redirect to dashboard
                if (authStore.isAuthenticated) {
                    next({ name: "dashboard" });
                } else {
                    next({ name: "login" });
                }
            }
        },
    },
    {
        path: "/auth/setup-2fa",
        name: "enforce-2fa-setup",
        component: () => import("@/views/Enforce2FASetupView.vue"),
        meta: {
            layout: AuthLayout,
            title: "Two-Factor Authentication Required",
            requiresAuth: true,
        },
    },

    {
        path: "/privacy",
        component: LegalLayout,
        children: [
            {
                path: "",
                name: "privacy",
                component: PrivacyView,
                meta: {
                    title: "Privacy Policy",
                    transition: "fade",
                },
            },
        ],
    },
    {
        path: "/terms",
        component: LegalLayout,
        children: [
            {
                path: "",
                name: "terms",
                component: TermsView,
                meta: {
                    title: "Terms of Service",
                    transition: "fade",
                },
            },
        ],
    },
    {
        path: "/",
        component: AppLayout,
        meta: { requiresAuth: true },
        children: [
            {
                path: "dashboard",
                name: "dashboard",
                component: DashboardView,
                meta: {
                    title: "Dashboard",
                    breadcrumb: "Dashboard",
                    transition: "slide-fade",
                },
            },
            {
                path: "portal",
                name: "client.portal",
                component: () => import("@/views/client/ClientDashboard.vue"),
                meta: {
                    title: "Client Portal",
                    breadcrumb: "Portal",
                    transition: "slide-fade",
                },
            },
            {
                path: "portal/projects",
                name: "client.projects",
                component: () =>
                    import("@/views/client/ClientProjectsView.vue"),
                meta: {
                    title: "My Projects",
                    breadcrumb: "Projects",
                    transition: "slide-fade",
                },
            },
            {
                path: "portal/projects/:id",
                name: "client.project-detail",
                component: () =>
                    import("@/views/client/ClientProjectDetailView.vue"),
                meta: {
                    title: "Project Details",
                    breadcrumb: "Project",
                    breadcrumbParent: {
                        name: "client.projects",
                        label: "Projects",
                    },
                    transition: "slide-fade",
                },
            },
            {
                path: "portal/invoices",
                name: "client.invoices",
                component: () =>
                    import("@/views/client/ClientInvoicesView.vue"),
                meta: {
                    title: "My Invoices",
                    breadcrumb: "Invoices",
                    transition: "slide-fade",
                },
            },
            {
                path: "portal/invoices/:id",
                name: "client.invoice-detail",
                component: () =>
                    import("@/views/client/ClientInvoiceDetailView.vue"),
                meta: {
                    title: "Invoice Details",
                    breadcrumb: "Invoice",
                    breadcrumbParent: {
                        name: "client.invoices",
                        label: "Invoices",
                    },
                    transition: "slide-fade",
                },
            },
            {
                path: "portal/tickets",
                name: "client.tickets",
                component: () => import("@/views/TicketsView.vue"),
                meta: {
                    title: "My Tickets",
                    breadcrumb: "Tickets",
                    transition: "slide-fade",
                },
            },
            {
                path: "portal/settings",
                name: "client.settings",
                component: () =>
                    import("@/views/client/ClientSettingsView.vue"),
                meta: {
                    title: "Account Settings",
                    breadcrumb: "Settings",
                    transition: "slide-fade",
                },
            },
            {
                path: "analytics",
                name: "analytics",
                component: AnalyticsView,
                meta: {
                    title: "Analytics",
                    breadcrumb: "Analytics",
                    transition: "slide-fade",
                },
            },
            {
                path: "projects",
                name: "projects",
                component: () => import("@/views/admin/ProjectsManageView.vue"),
                meta: {
                    title: "Projects",
                    breadcrumb: "Projects",
                    transition: "slide-fade",
                },
            },
            {
                path: "projects/archived",
                name: "projects-archived",
                component: () => import("@/views/admin/ProjectsManageView.vue"),
                props: { defaultFilter: "archived" },
                meta: {
                    title: "Archived Projects",
                    breadcrumb: "Archived Projects",
                    transition: "slide-fade",
                },
            },
            {
                path: "projects/my",
                name: "projects-my",
                component: () => import("@/views/admin/ProjectsManageView.vue"),
                props: { defaultFilter: "my" },
                meta: {
                    title: "My Projects",
                    breadcrumb: "My Projects",
                    transition: "slide-fade",
                },
            },
            {
                path: "projects/new",
                name: "project-new",
                component: () => import("@/views/admin/ProjectDetailView.vue"),
                props: { isNew: true },
                meta: {
                    title: "New Project",
                    breadcrumb: "New Project",
                    breadcrumbParent: { name: "projects", label: "Projects" },
                    transition: "slide-fade",
                },
            },
            {
                path: "projects/:id",
                name: "project-detail",
                component: () => import("@/views/admin/ProjectDetailView.vue"),
                meta: {
                    title: "Project Details",
                    breadcrumb: "Project",
                    breadcrumbParent: { name: "projects", label: "Projects" },
                    transition: "slide-fade",
                },
            },
            {
                path: "projects/:projectId/tasks/:taskId",
                name: "task-detail",
                component: () => import("@/views/TaskDetailView.vue"),
                meta: {
                    title: "Task Details",
                    breadcrumb: "Task",
                    breadcrumbParent: {
                        name: "project-detail",
                        label: "Project",
                        paramKey: "id",
                        sourceParam: "projectId",
                    },
                    transition: "slide-fade",
                },
            },
            {
                path: "tasks",
                name: "tasks",
                component: () => import("@/views/TasksView.vue"),
                meta: {
                    title: "All Tasks",
                    breadcrumb: "Tasks",
                    transition: "slide-fade",
                },
            },
            {
                path: "tasks/my",
                name: "tasks-my",
                component: () => import("@/views/TasksView.vue"),
                meta: {
                    title: "My Tasks",
                    breadcrumb: "My Tasks",
                    transition: "slide-fade",
                },
            },
            {
                path: "tasks/board",
                name: "tasks-board",
                component: () => import("@/views/TasksView.vue"),
                meta: {
                    title: "Task Board",
                    breadcrumb: "Task Board",
                    transition: "slide-fade",
                },
            },
            {
                path: "tickets",
                name: "tickets",
                component: TicketsView,
                meta: {
                    title: "Tickets",
                    breadcrumb: "Tickets",
                    transition: "slide-fade",
                    permission: "tickets.view",
                },
            },
            {
                path: "support",
                name: "support",
                component: () => import("@/views/SupportView.vue"),
                meta: {
                    title: "Help Desk",
                    breadcrumb: "Help Desk",
                    transition: "slide-fade",
                },
            },
            {
                path: "reports/tickets",
                name: "reports.tickets",
                component: () =>
                    import("@/views/reports/TicketReportsView.vue"),
                meta: {
                    title: "Ticket Reports",
                    breadcrumb: "Reports",
                    transition: "slide-fade",
                    permission: "tickets.view",
                },
            },
            {
                path: "reports/projects",
                name: "reports.projects",
                component: () =>
                    import("@/views/reports/ProjectReportView.vue"),
                meta: {
                    title: "Projects Report",
                    breadcrumb: "Reports",
                    transition: "slide-fade",
                    // permission: 'projects.view'
                },
            },
            {
                path: "calendar",
                name: "calendar",
                component: () => import("@/views/Calendar/Index.vue"),
                alias: "/callback/calendar",
                meta: {
                    title: "Calendar",
                    breadcrumb: "Calendar",
                    transition: "slide-fade",
                },
            },
            {
                path: "notes",
                name: "notes",
                component: () => import("@/views/NotesView.vue"),
                meta: {
                    title: "Personal Notes",
                    breadcrumb: "Notes",
                    transition: "slide-fade",
                },
            },
            {
                path: "notes/:public_id",
                name: "note-detail",
                component: () => import("@/views/NoteDetailView.vue"),
                meta: {
                    title: "Note Details",
                    breadcrumb: "Note Details",
                    breadcrumbParent: { name: "notes", label: "Notes" },
                    transition: "slide-fade",
                    layoutFullWidth: true,
                    showBreadcrumbs: true,
                },
            },
            {
                path: "chat",
                name: "chat",
                component: () => import("@/views/chat/ChatPage.vue"),
                meta: {
                    title: "Messages",
                    breadcrumb: "Messages",
                    transition: "slide-fade",
                    layoutFullWidth: true,
                },
            },
            {
                path: "chat/:chatId",
                name: "chat-detail",
                component: () => import("@/views/chat/ChatPage.vue"),
                meta: {
                    title: "Messages",
                    breadcrumb: "Messages",
                    transition: "slide-fade",
                    layoutFullWidth: true,
                },
            },
            {
                path: "tickets/:id",
                name: "ticket-detail",
                component: TicketDetailView,
                meta: {
                    title: "Ticket Details",
                    breadcrumb: "Ticket Details",
                    breadcrumbParent: { name: "tickets", label: "Tickets" },
                    transition: "slide-fade",
                },
            },
            {
                path: "notifications",
                name: "notifications",
                component: NotificationsView,
                meta: {
                    title: "Notifications",
                    breadcrumb: "Notifications",
                    transition: "slide-fade",
                },
            },
            {
                path: "settings",
                name: "settings",
                component: SettingsView,
                meta: {
                    title: "Settings",
                    breadcrumb: "Settings",
                    breadcrumbParent: { name: "profile", label: "Profile" },
                    transition: "slide-fade",
                },
            },
            {
                path: "profile",
                name: "profile",
                component: ProfileView,
                meta: {
                    title: "My Profile",
                    breadcrumb: "Profile",
                    transition: "slide-fade",
                },
            },

            // Content Management
            {
                path: "/admin/faq",
                name: "admin.faq",
                component: () => import("@/views/admin/FaqManageView.vue"),
                meta: {
                    title: "FAQ Management",
                    breadcrumb: "FAQ",
                    transition: "slide-fade",
                    permission: "faq.manage",
                },
            },
            {
                path: "/admin/faq/articles/create",
                name: "admin.faq.create",
                component: () =>
                    import("@/views/admin/FaqArticleEditorView.vue"),
                meta: {
                    title: "Create Article",
                    breadcrumb: "Create Article",
                    breadcrumbParent: { name: "admin.faq", label: "FAQ" },
                    transition: "slide-fade",
                    permission: "faq.manage",
                    layoutFullWidth: true,
                    showBreadcrumbs: true,
                },
            },
            {
                path: "/admin/faq/articles/:id/edit",
                name: "admin.faq.edit",
                component: () =>
                    import("@/views/admin/FaqArticleEditorView.vue"),
                meta: {
                    title: "Edit Article",
                    breadcrumb: "Edit Article",
                    breadcrumbParent: { name: "admin.faq", label: "FAQ" },
                    transition: "slide-fade",
                    permission: "faq.manage",
                    layoutFullWidth: true,
                    showBreadcrumbs: true,
                },
            },

            // User Management
            {
                path: "/admin/users",
                name: "admin-users",
                component: () => import("@/views/admin/UsersView.vue"),
                meta: {
                    title: "Users",
                    breadcrumb: "Users",
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/admin/users/:public_id",
                name: "admin-user-details",
                component: () => import("@/views/admin/UserDetailsView.vue"),
                meta: {
                    title: "User Details",
                    breadcrumb: "User Details",
                    breadcrumbParent: { name: "admin-users", label: "Users" },
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/admin/teams",
                name: "admin-teams",
                component: () => import("@/views/admin/TeamsView.vue"),
                meta: {
                    title: "Teams",
                    breadcrumb: "Teams",
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/teams/:team/roles",
                name: "team-roles",
                component: () => import("@/views/Teams/Roles/Index.vue"),
                meta: {
                    title: "Team Roles",
                    breadcrumb: "Roles",
                    breadcrumbParent: {
                        name: "team-profile",
                        label: "Team",
                        paramKey: "public_id",
                    },
                    transition: "slide-fade",
                },
            },
            {
                path: "/admin/roles",
                name: "admin-roles",
                component: () => import("@/views/admin/RolesView.vue"),
                meta: {
                    title: "Roles & Permissions",
                    breadcrumb: "Roles",
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/admin/roles/:id",
                name: "admin-role-details",
                component: () => import("@/views/admin/RoleDetailsView.vue"),
                meta: {
                    title: "Role Details",
                    breadcrumb: "Role Details",
                    breadcrumbParent: { name: "admin-roles", label: "Roles" },
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/admin/projects",
                name: "admin-projects",
                component: () => import("@/views/admin/ProjectsManageView.vue"),
                meta: {
                    title: "Manage Projects",
                    breadcrumb: "Projects",
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/admin/projects/:id",
                name: "admin-project-detail",
                component: () => import("@/views/admin/ProjectDetailView.vue"),
                meta: {
                    title: "Project Details",
                    breadcrumb: "Project",
                    breadcrumbParent: {
                        name: "admin-projects",
                        label: "Projects",
                    },
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/clients",
                name: "clients",
                component: () => import("@/views/admin/ClientsView.vue"),
                meta: {
                    title: "Clients",
                    breadcrumb: "Clients",
                    transition: "slide-fade",
                    // No permission required, just auth
                },
            },
            {
                path: "/clients/:public_id",
                name: "client-detail",
                component: () => import("@/views/admin/ClientDetailsView.vue"),
                meta: {
                    title: "Client Details",
                    breadcrumb: "Client Details",
                    breadcrumbParent: { name: "clients", label: "Clients" },
                    transition: "slide-fade",
                },
            },
            {
                path: "/admin/clients",
                name: "admin-clients",
                component: () => import("@/views/admin/ClientsView.vue"),
                meta: {
                    title: "Manage Clients",
                    breadcrumb: "Clients",
                    transition: "slide-fade",
                    // No permission check for now - handled by backend and navigationController
                    // permission: "clients.manage", 
                },
            },
            {
                path: "/admin/clients/:public_id",
                name: "admin-client-detail",
                component: () => import("@/views/admin/ClientDetailsView.vue"),
                meta: {
                    title: "Client Details",
                    breadcrumb: "Client Details",
                    breadcrumbParent: { name: "admin-clients", label: "Clients" },
                    transition: "slide-fade",
                    permission: "user_manage",
                },
            },
            {
                path: "/invoices",
                name: "invoices",
                component: () => import("@/views/admin/InvoicesView.vue"),
                meta: {
                    title: "Invoices",
                    breadcrumb: "Invoices",
                    transition: "slide-fade",
                },
            },
            {
                path: "/invoices/:id",
                name: "invoice-detail",
                component: () => import("@/views/admin/InvoiceDetailView.vue"),
                meta: {
                    title: "Invoice Details",
                    breadcrumb: "Invoice Details",
                    breadcrumbParent: { name: "invoices", label: "Invoices" },
                    transition: "slide-fade",
                },
            },
            {
                path: "/admin/invoices",
                name: "admin-invoices",
                component: () => import("@/views/admin/InvoicesView.vue"),
                meta: {
                    title: "Manage Invoices",
                    breadcrumb: "Invoices",
                    transition: "slide-fade",
                    permission: "invoices.view",
                },
            },
            {
                path: "/admin/invoices/create",
                name: "admin-invoice-create",
                component: () => import("@/views/admin/InvoiceForm.vue"),
                meta: {
                    title: "Create Invoice",
                    breadcrumb: "Create Invoice",
                    breadcrumbParent: {
                        name: "admin-invoices",
                        label: "Invoices",
                    },
                    transition: "slide-fade",
                    permission: "invoices.create",
                },
            },
            {
                path: "/admin/invoices/:id",
                name: "admin-invoice-detail",
                component: () => import("@/views/admin/InvoiceDetailView.vue"),
                meta: {
                    title: "Invoice Details",
                    breadcrumb: "Invoice Details",
                    breadcrumbParent: {
                        name: "admin-invoices",
                        label: "Invoices",
                    },
                    transition: "slide-fade",
                    permission: "invoices.view",
                },
            },
            {
                path: "/admin/invoices/:id/edit",
                name: "admin-invoice-edit",
                component: () => import("@/views/admin/InvoiceForm.vue"),
                meta: {
                    title: "Edit Invoice",
                    breadcrumb: "Edit Invoice",
                    breadcrumbParent: {
                        name: "admin-invoices",
                        label: "Invoices",
                    },
                    transition: "slide-fade",
                    permission: "invoices.update",
                },
            },

            {
                path: "/admin/chats",
                name: "admin-chats",
                component: () => import("@/views/admin/ChatManagementView.vue"),
                meta: {
                    title: "Chat Management",
                    breadcrumb: "Chats",
                    transition: "slide-fade",
                    permission: "chats.manage",
                },
            },

            // System Settings & Logs
            {
                path: "/services",
                name: "services.index",
                component: () => import("@/views/services/ServiceIndex.vue"),
                meta: {
                    title: "Services",
                    breadcrumb: "Services",
                    transition: "slide-fade",
                    permission: "services.manage", // Assumption: permission check logic exists or ignores if undefined
                },
            },
            {
                path: "/services/create",
                name: "services.create",
                component: () => import("@/views/services/ServiceForm.vue"),
                meta: {
                    title: "Create Service",
                    breadcrumb: "Create Service",
                    transition: "slide-fade",
                    permission: "services.manage",
                    layoutFullWidth: true,
                },
            },
            {
                path: "/services/:id/edit",
                name: "services.edit",
                component: () => import("@/views/services/ServiceForm.vue"),
                meta: {
                    title: "Edit Service",
                    breadcrumb: "Edit Service",
                    transition: "slide-fade",
                    permission: "services.manage",
                    layoutFullWidth: true,
                },
            },
            {
                path: "/system/settings",
                name: "system-settings",
                component: () =>
                    import("@/views/system/GeneralSettingsView.vue"),
                meta: {
                    title: "General Settings",
                    breadcrumb: "General Settings",
                    transition: "slide-fade",
                    permission: "settings.system",
                },
            },
            {
                path: "/system/logs/:public_id",
                name: "system-log-details",
                component: () =>
                    import("@/views/system/AuditLogDetailView.vue"),
                meta: {
                    title: "Audit Log Details",
                    breadcrumb: "Log Details",
                    transition: "slide-fade",
                    permission: "audit.view",
                },
            },
            {
                path: "/system/logs",
                name: "system-logs",
                component: () => import("@/views/system/SystemLogsView.vue"),
                meta: {
                    title: "System Logs",
                    breadcrumb: "System Logs",
                    transition: "slide-fade",
                    permission: "audit.view",
                },
            },

            // Email Client
            {
                path: "/email/settings",
                name: "email-settings",
                component: () => import("@/views/Email/EmailSettings.vue"),
                meta: {
                    title: "Email Settings",
                    breadcrumb: "Settings",
                    breadcrumbParent: { name: "email", label: "Email" },
                    showBreadcrumbs: true,
                },
            },
            {
                path: "email",
                name: "email",
                component: () => import("@/views/Email/EmailIndex.vue"),
                meta: {
                    title: "Email",
                    breadcrumb: "Email",
                    transition: "none", // Explicitly disabled to prevent layout thrashing
                    layoutFullWidth: true,
                    layoutFixed: false, // Changed for sanity check: allow page to grow
                },
            },

            {
                path: "/system/maintenance",
                name: "system-maintenance",
                component: () => import("@/views/system/MaintenanceView.vue"),
                meta: {
                    title: "Maintenance",
                    breadcrumb: "Maintenance",
                    transition: "slide-fade",
                    permission: "settings.system",
                },
            },

            {
                path: "users/:public_id",
                name: "user-profile",
                component: () => import("@/views/UserProfileView.vue"),
                meta: {
                    title: "User Profile",
                    breadcrumb: "Profile",
                    transition: "slide-fade",
                },
            },
            {
                path: "teams/:public_id",
                name: "team-profile",
                component: () => import("@/views/TeamProfileView.vue"),
                meta: {
                    title: "Team Profile",
                    breadcrumb: "Team Profile",
                    transition: "slide-fade",
                },
            },
            {
                path: "teams/:id/templates/tasks",
                name: "team-task-templates",
                component: () =>
                    import("@/views/admin/templates/TaskTemplatesView.vue"),
                meta: {
                    title: "Task Templates",
                    breadcrumb: "Task Templates",
                    breadcrumbParent: {
                        name: "team-profile",
                        label: "Team",
                        paramKey: "public_id",
                        sourceParam: "id",
                    },
                    transition: "slide-fade",
                    // Permission check moved to component/API level for team context
                },
            },
            {
                path: "teams/:id/templates/invoices",
                name: "team-invoice-templates",
                component: () =>
                    import("@/views/admin/templates/InvoiceTemplatesView.vue"),
                meta: {
                    title: "Invoice Templates",
                    breadcrumb: "Invoice Templates",
                    breadcrumbParent: {
                        name: "team-profile",
                        label: "Team",
                        paramKey: "public_id",
                        sourceParam: "id",
                    },
                    transition: "slide-fade",
                    // Permission check moved to component/API level for team context
                },
            },

            // Dev Tools (only accessible in development and by admins)
            {
                path: "/dev/debug_tools",
                name: "dev-debug-tools",
                component: () => import("@/views/dev/PresenceDebugView.vue"),
                meta: {
                    title: "Developer Tools",
                    breadcrumb: "Dev Tools",
                    transition: "slide-fade",
                },
                beforeEnter: (
                    _to: RouteLocationNormalized,
                    _from: RouteLocationNormalized,
                    next: NavigationGuardNext,
                ) => {
                    const authStore = useAuthStore();
                    // Check if user has 'administrator' role
                    const isAdmin = authStore.user?.roles?.some(
                        (r) => r.name === "administrator",
                    );

                    if (import.meta.env.DEV || isAdmin) {
                        next();
                    } else {
                        next({ name: "forbidden" });
                    }
                },
            },
            {
                path: "/dev/components",
                name: "dev-components",
                component: () => import("@/views/dev/ComponentGalleryView.vue"),
                meta: {
                    title: "Component Gallery",
                    breadcrumb: "Components",
                    transition: "none",
                },
                beforeEnter: (
                    _to: RouteLocationNormalized,
                    _from: RouteLocationNormalized,
                    next: NavigationGuardNext,
                ) => {
                    const authStore = useAuthStore();
                    const isAdmin = authStore.user?.roles?.some(
                        (r) => r.name === "administrator",
                    );

                    if (import.meta.env.DEV || isAdmin) {
                        next();
                    } else {
                        next({ name: "forbidden" });
                    }
                },
            },
        ],
    },
];

const router: Router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior(_to, _from, savedPosition) {
        if (savedPosition) {
            return savedPosition;
        }
        return { top: 0 };
    },
});

import NProgress from "nprogress";
import "nprogress/nprogress.css";

NProgress.configure({ showSpinner: false });

// Navigation guards
router.beforeEach(
    async (
        to: RouteLocationNormalized,
        from: RouteLocationNormalized,
        next: NavigationGuardNext,
    ) => {
        NProgress.start();
        const authStore = useAuthStore();

        // Update document title
        document.title = to.meta.title
            ? `${to.meta.title} | ${appConfig.name}`
            : appConfig.name;

        // Check authentication requirements
        if (to.meta.requiresAuth) {
            // Always fetch user from server if:
            // 1. Not authenticated in store (restore session)
            // 2. Session not yet verified in this load (prevent stale local storage flash)
            // 3. Coming from social login callback (ensure fresh user data)
            // 3. Coming from social login callback (ensure fresh user data)
            const shouldFetchUser =
                !authStore.isAuthenticated ||
                !authStore.isSessionVerified;
            
            console.log('[Router] Checking auth', { 
                path: to.path, 
                isAuthenticated: authStore.isAuthenticated, 
                isSessionVerified: authStore.isSessionVerified,
                shouldFetchUser 
            });

            if (shouldFetchUser) {
                console.log('[Router] Fetching user...');
                await authStore.fetchUser();
                console.log('[Router] User fetched. Auth:', authStore.isAuthenticated);
            }

            if (!authStore.isAuthenticated) {
                next({ name: "login", query: { redirect: to.fullPath } });
                return;
            }
        }

        // Allow access to login page for 2FA challenge from social login even if
        // the Pinia store thinks user is authenticated (due to persisted localStorage)
        if (to.meta.requiresGuest && authStore.isAuthenticated) {
            // Check if this is a 2FA challenge redirect from social login
            if (to.query.action === "2fa") {
                // Clear the persisted auth state since login is incomplete
                // The backend has logged out the user; we need to clear frontend state
                authStore.user = null;
                // Continue to the login page for 2FA challenge
                next();
                return;
            }
            next({ name: "dashboard" });
            return;
        }

        // Email Verification Check
        if (
            authStore.isAuthenticated &&
            !authStore.user?.email_verified_at &&
            to.name !== "verification.notice" &&
            to.name !== "setup-account"
        ) {
            next({ name: "verification.notice" });
            return;
        }

        // Redirect verified users away from verification page
        if (
            to.name === "verification.notice" &&
            authStore.user?.email_verified_at
        ) {
            next({ name: "dashboard" });
            return;
        }

        // 2FA Enforcement Check - redirect if user needs to set up 2FA
        if (
            authStore.isAuthenticated &&
            authStore.requires2FASetup &&
            to.name !== "enforce-2fa-setup"
        ) {
            next({ name: "enforce-2fa-setup" });
            return;
        }

        // Redirect users away from 2FA setup page if they don't need it
        if (to.name === "enforce-2fa-setup" && !authStore.requires2FASetup) {
            next({ name: "dashboard" });
            return;
        }

        // Permission Check
        if (to.meta.permission) {
            const userPermissions = authStore.user?.permissions || [];
            if (!userPermissions.includes(to.meta.permission)) {
                next({ name: "forbidden" });
                return;
            }
        }

        next();
    },
);

import { analyticsService } from "@/services/analytics.service";

router.afterEach((to) => {
    NProgress.done();

    // Track page visit in SPA
    analyticsService.trackPageVisit(to.path);
});

export default router;
