import type { User } from "@/types/models/user";

const SUPPORT_AGENT_PERMISSIONS = [
    "support.chats.view",
    "support.chats.reply",
    "support.chats.assign",
    "support.chats.resolve",
    "tickets.manage",
] as const;

const SUPPORT_LEAD_ROLE_NAMES = new Set([
    "administrator",
    "support_lead",
    "support_manager",
    "team_lead",
    "sme",
    "qa",
]);

const SUPPORT_AGENT_ROLE_NAMES = new Set([
    "support_agent",
    "agent",
]);

type PermissionChecker = (permission: string | string[]) => boolean;

function normalizedRoleNames(user: User | null | undefined): Set<string> {
    const roles = Array.isArray(user?.roles) ? user.roles : [];
    return new Set(
        roles
            .map((role) => String(role?.name || "").trim().toLowerCase())
            .filter((role) => role !== ""),
    );
}

function hasAnyRole(
    user: User | null | undefined,
    roleNames: Set<string>,
): boolean {
    const roles = normalizedRoleNames(user);
    for (const role of roles) {
        if (roleNames.has(role)) {
            return true;
        }
    }

    return false;
}

function hasSupportAccess(hasPermission: PermissionChecker): boolean {
    return hasPermission([...SUPPORT_AGENT_PERMISSIONS]);
}

export function isSupportLeadWorkspaceUser(
    user: User | null | undefined,
    hasPermission: PermissionChecker,
): boolean {
    if (!user || !hasSupportAccess(hasPermission)) {
        return false;
    }

    if (
        hasPermission([
            "support.chats.assign",
            "support.chats.resolve",
            "tickets.manage",
        ])
    ) {
        return true;
    }

    return hasAnyRole(user, SUPPORT_LEAD_ROLE_NAMES);
}

export function isSupportAgentWorkspaceUser(
    user: User | null | undefined,
    hasPermission: PermissionChecker,
): boolean {
    if (!user || !hasSupportAccess(hasPermission)) {
        return false;
    }

    if (isSupportLeadWorkspaceUser(user, hasPermission)) {
        return false;
    }

    if (hasAnyRole(user, SUPPORT_AGENT_ROLE_NAMES)) {
        return true;
    }

    return hasPermission(["support.chats.reply", "support.chats.view"]);
}

export function preferredSupportWorkspaceRoute(
    user: User | null | undefined,
    hasPermission: PermissionChecker,
): "/support/inbox" | "/support/workbench" | "/support" {
    if (!user || !hasSupportAccess(hasPermission)) {
        return "/support";
    }

    if (isSupportLeadWorkspaceUser(user, hasPermission)) {
        return "/support/inbox";
    }

    if (isSupportAgentWorkspaceUser(user, hasPermission)) {
        return "/support/workbench";
    }

    return "/support/inbox";
}
