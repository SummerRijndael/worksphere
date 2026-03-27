<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useNavigationStore } from "@/stores/navigation";
import { useAuthStore } from "@/stores/auth";
import { useNotificationsStore } from "@/stores/notifications";
import { appConfig } from "@/config/app";
import { cn } from "@/lib/utils";
import type {
    NavigationItem,
    NavigationChild,
} from "@/types/models/navigation";

// ... (existing imports)

import { usePresence } from "@/composables/usePresence.ts";
import {
    Button,
    Avatar,
    Tooltip,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    DropdownLabel,
} from "@/components/ui";
import StatusSelector from "@/components/ui/StatusSelector.vue";
import ThemeToggle from "@/components/common/ThemeToggle.vue";
import NotificationItem from "@/components/ui/NotificationItem.vue";
import {
    LayoutDashboard,
    BarChart3,
    FolderKanban,
    Bell,
    Loader2,
    Settings,
    Pin,
    PinOff,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    LogOut,
    User,
    Ticket,
    CheckSquare,
    Users,
    FileText,
    UserCog,
    ShieldCheck,
    Sliders,
    MessageSquare,
    Mail,
    Plus,
    BookOpen,
    Calendar,
    Video,
} from "lucide-vue-next";

const route = useRoute();
const router = useRouter();
const navStore = useNavigationStore();
const authStore = useAuthStore();
const notificationsStore = useNotificationsStore();
const { currentStatus } = usePresence({ manageLifecycle: false });

// Icon mapping
const iconMap = {
    "layout-dashboard": LayoutDashboard,
    "chart-bar": BarChart3,
    folder: FolderKanban,
    bell: Bell,
    settings: Settings,
    ticket: Ticket,
    "check-square": CheckSquare,
    users: Users,
    "file-text": FileText,
    "user-cog": UserCog,
    "shield-check": ShieldCheck,
    sliders: Sliders,
    "message-square": MessageSquare,
    mail: Mail,
    plus: Plus,
    "book-open": BookOpen,
    calendar: Calendar,
    video: Video,
};

const isHovered = ref(false);
let hoverTimeout: ReturnType<typeof setTimeout> | null = null;

const handleMouseEnter = () => {
    hoverTimeout = setTimeout(() => {
        isHovered.value = true;
    }, 150);
};

const handleMouseLeave = () => {
    if (hoverTimeout) clearTimeout(hoverTimeout);
    isHovered.value = false;
};

const sidebarClasses = computed(() =>
    cn(
        "fixed left-0 top-0 bottom-0 z-50 flex flex-col",
        // Minimalist Base
        "bg-[var(--surface-primary)] border-r border-[var(--border-muted)]",
        "transition-all duration-300 ease-[var(--ease-bounce)]",
        navStore.isSidebarCollapsed && !isHovered.value
            ? "w-[var(--sidebar-width-collapsed)]"
            : "w-[var(--sidebar-width)]",
        // Mobile
        "max-lg:w-[var(--sidebar-width)]",
        navStore.isMobileSidebarOpen
            ? "max-lg:translate-x-0"
            : "max-lg:-translate-x-full",
    ),
);

// Helper functions
function isActive(itemRoute: string | undefined): boolean {
    return route.path === itemRoute;
}

function isChildActive(item: NavigationItem): boolean {
    if (!item.children) return false;
    return item.children.some(
        (child: NavigationChild) => route.path === child.route,
    );
}

function navigate(path: string, newTab: boolean = false): void {
    if (newTab) {
        window.open(path, '_blank');
    } else {
        router.push(path);
    }
    navStore.closeMobileSidebar();
}

function getIcon(iconName: string | undefined) {
    if (!iconName) return LayoutDashboard;
    return iconMap[iconName as keyof typeof iconMap] || LayoutDashboard;
}

async function handleLogout() {
    await authStore.logout();
    router.push("/auth/login");
}

// Helper to determine if we should show expanded content
const showExpanded = computed(
    () =>
        !navStore.isSidebarCollapsed ||
        isHovered.value ||
        navStore.isMobileSidebarOpen,
);
const isCompactRail = computed(
    () =>
        navStore.isSidebarCollapsed &&
        !isHovered.value &&
        !navStore.isMobileSidebarOpen,
);

function handleItemClick(item: NavigationItem): void {
    if (navStore.hasChildren(item) && !showExpanded.value) {
        navStore.toggleExpanded(item.id);
    } else if (navStore.hasChildren(item) && showExpanded.value) {
        navStore.toggleExpanded(item.id);
    } else if (item.route) {
        navigate(item.route);
    }
}

onMounted(() => {
    navStore.fetchNavigation();
    notificationsStore.fetchNotifications(true);
    notificationsStore.fetchUnreadCount();
    notificationsStore.startRealtimeListeners();
});

onUnmounted(() => {
    notificationsStore.stopRealtimeListeners();
});
</script>

<template>
    <aside
        :class="sidebarClasses"
        @mouseenter="handleMouseEnter"
        @mouseleave="handleMouseLeave"
    >
        <!-- Header -->
        <div
            :class="
                cn(
                    'flex items-center justify-between transition-all duration-200',
                    showExpanded ? 'h-14 px-3 pb-1.5 pt-2.5' : 'h-11 px-1.5 py-1.5',
                )
            "
        >
            <!-- Logo -->
            <a
                href="/"
                target="_blank"
                :class="
                    cn(
                        'flex items-center transition-all duration-300',
                        showExpanded ? 'gap-2.5' : 'gap-0',
                        !showExpanded && 'justify-center w-full',
                    )
                "
            >
                <div
                    :class="
                        cn(
                            'bg-[var(--color-primary-600)] flex items-center justify-center shrink-0 transition-all duration-200',
                            showExpanded
                                ? 'h-8 w-8 rounded-lg'
                                : 'h-7 w-7 rounded-md',
                        )
                    "
                >
                    <svg
                        :class="
                            cn(
                                'text-white transition-all duration-200',
                                showExpanded ? 'h-4.5 w-4.5' : 'h-4 w-4',
                            )
                        "
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2.5"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M13 10V3L4 14h7v7l9-11h-7z"
                        />
                    </svg>
                </div>
                <div
                    :class="
                        cn(
                            'flex flex-col transition-all duration-300 ease-in-out overflow-hidden whitespace-nowrap',
                            !showExpanded
                                ? 'max-lg:opacity-100 max-lg:w-auto lg:max-w-0 lg:opacity-0'
                                : 'max-w-[200px] opacity-100',
                        )
                    "
                >
                    <span
                        class="text-[14px] font-semibold text-[var(--text-primary)] leading-none tracking-tight"
                    >
                        {{ appConfig.name }}
                    </span>
                    <span
                        class="text-[10px] font-medium text-[var(--text-tertiary)] mt-0.5"
                    >
                        Team Workspace
                    </span>
                </div>
            </a>

            <!-- Collapse Toggle -->
            <button
                @click="navStore.toggleSidebar()"
                :class="
                    cn(
                        'hidden lg:flex items-center justify-center rounded-lg border border-[var(--border-muted)] bg-[var(--surface-secondary)] text-[var(--text-muted)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)] transition-all duration-200',
                        showExpanded
                            ? 'h-8 w-8'
                            : 'h-6 w-6 border-none bg-transparent hover:bg-[var(--surface-tertiary)]',
                    )
                "
            >
                <component
                    :is="
                        navStore.isSidebarCollapsed ? ChevronRight : ChevronLeft
                    "
                    :class="showExpanded ? 'h-4 w-4' : 'h-3.5 w-3.5'"
                />
            </button>
        </div>

        <!-- Navigation -->
        <nav
            :class="
                cn(
                    'flex-1 overflow-y-auto overflow-x-hidden scrollbar-thin hover:scrollbar-thumb-[var(--scrollbar-thumb)]',
                    showExpanded ? 'p-2.5 space-y-4' : 'px-1.5 py-1.5 space-y-2.5',
                )
            "
        >
            <!-- Pinned Items -->
            <div v-if="navStore.pinnedItems.length" class="space-y-0.5">
                <template v-for="item in navStore.pinnedItems" :key="item.id">
                    <!-- Parent Item -->
                    <Tooltip
                        :content="item.label"
                        side="right"
                        :delay-duration="200"
                        :side-offset="10"
                        content-class="font-medium bg-[var(--text-primary)] text-[var(--text-inverse)] border-none shadow-md px-3 py-1.5 text-xs rounded-lg"
                    >
                        <div class="relative group/item">
                            <button
                                :class="
                                    cn(
                                        'group relative flex w-full items-center rounded-lg transition-colors duration-200 border border-transparent',
                                        // Base spacing
                                        !showExpanded
                                            ? 'justify-center h-8 w-8 p-0 mx-auto rounded-md'
                                            : 'px-2.5 py-1.5 gap-2.5',
                                        // Active State (Pill)
                                        isActive(item.route) ||
                                            isChildActive(item)
                                            ? 'bg-[var(--surface-tertiary)] text-[var(--text-primary)] font-medium'
                                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]',
                                    )
                                "
                                @click="handleItemClick(item)"
                            >
                                <component
                                    :is="getIcon(item.icon)"
                                    :class="
                                        cn(
                                            showExpanded
                                                ? 'h-4 w-4 shrink-0 transition-colors duration-200'
                                                : 'h-3.5 w-3.5 shrink-0 transition-colors duration-200',
                                            isActive(item.route) ||
                                                isChildActive(item)
                                                ? 'text-[var(--text-primary)]'
                                                : 'text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]',
                                        )
                                    "
                                    stroke-width="2"
                                />
                                <span
                                    :class="
                                        cn(
                                            'flex-1 text-left whitespace-nowrap overflow-hidden text-[13px] transition-all duration-300 ease-in-out',
                                            !showExpanded
                                                ? 'max-w-0 opacity-0'
                                                : 'max-w-[200px] opacity-100',
                                        )
                                    "
                                >
                                    {{ item.label }}
                                </span>

                                <!-- Badge -->
                                <span
                                    v-if="
                                        navStore.getBadge(item) && showExpanded
                                    "
                                    class="rounded-md px-1.5 py-0.5 text-[10px] font-medium transition-colors ml-2 bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-subtle)]"
                                >
                                    {{ navStore.getBadge(item) }}
                                </span>

                                <!-- Badge (collapsed) -->
                                <div
                                    v-if="
                                        navStore.getBadge(item) && !showExpanded
                                    "
                                    class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-[var(--color-primary-500)] ring-2 ring-[var(--surface-primary)]"
                                ></div>

                                <!-- Expand/Collapse Arrow -->
                                <ChevronDown
                                    v-if="
                                        navStore.hasChildren(item) &&
                                        showExpanded
                                    "
                                    :class="
                                        cn(
                                            'h-3 w-3 shrink-0 text-[var(--text-muted)] transition-transform duration-200',
                                            navStore.isExpanded(item.id) &&
                                                'rotate-180',
                                        )
                                    "
                                />
                            </button>

                            <!-- Unpin Button (shows on hover) -->
                            <button
                                v-if="showExpanded && item.pinnable !== false"
                                @click.stop="navStore.togglePin(item.id)"
                                :class="
                                    cn(
                                        'absolute top-1/2 -translate-y-1/2 p-1 rounded opacity-0 group-hover/item:opacity-100 transition-opacity text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--surface-secondary)]',
                                        navStore.hasChildren(item)
                                            ? 'right-8'
                                            : 'right-1',
                                    )
                                "
                                title="Unpin"
                            >
                                <PinOff class="h-3 w-3" />
                            </button>
                        </div>
                    </Tooltip>

                    <!-- Children -->
                    <div
                        v-if="navStore.hasChildren(item) && showExpanded"
                        class="grid transition-all duration-200 ease-in-out"
                        :class="
                            navStore.isExpanded(item.id)
                                ? 'grid-rows-[1fr] opacity-100 mb-1'
                                : 'grid-rows-[0fr] opacity-0'
                        "
                    >
                        <div class="overflow-hidden">
                            <div
                                class="ml-[1rem] pl-2.5 border-l border-[var(--border-muted)] space-y-0.5 pt-0.5"
                            >
                                <template
                                    v-for="child in item.children"
                                    :key="child.id"
                                >
                                    <div
                                        v-if="child.type === 'divider'"
                                        class="my-1 h-px bg-[var(--border-muted)]/50 mx-2"
                                    ></div>
                                    <div
                                        v-else-if="child.type === 'header'"
                                        class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)] opacity-80 mt-0.5"
                                    >
                                        {{ child.label }}
                                    </div>
                                    <button
                                        v-else
                                        :class="
                                            cn(
                                                'flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-[12.5px] transition-colors duration-200 border border-transparent font-medium',
                                                isActive(child.route)
                                                    ? 'text-[var(--text-primary)] bg-[var(--surface-secondary)]'
                                                    : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)]/50',
                                                'cursor-pointer',
                                            )
                                        "
                                        @click="
                                            child.route && navigate(child.route, !!child.new_tab)
                                        "
                                    >
                                        <component
                                            v-if="child.icon"
                                            :is="getIcon(child.icon)"
                                            class="h-3 w-3 shrink-0 text-[var(--text-muted)]"
                                        />
                                        <span class="truncate">{{
                                            child.label
                                        }}</span>
                                        <span
                                            v-if="child.team_badge"
                                            class="ml-auto text-[10px] px-1.5 py-0.5 rounded bg-[var(--surface-tertiary)] text-[var(--text-muted)] border border-[var(--border-subtle)] shrink-0"
                                        >
                                            {{ child.team_badge }}
                                        </span>
                                    </button>
                                </template>
                                <!-- Static Team Actions -->
                                <template v-if="item.id === 'teams'">
                                    <div
                                        class="my-1 h-px bg-[var(--border-muted)]/50 mx-2"
                                    ></div>
                                    <button
                                        class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1 text-[11.5px] transition-colors duration-200 border border-transparent font-medium text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/5"
                                        @click="navigate('/teams?create=true')"
                                    >
                                        <Plus class="h-3 w-3 shrink-0" />
                                        <span class="truncate text-[11.5px]"
                                            >Create New Team</span
                                        >
                                    </button>
                                    <button
                                        class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1 text-[11.5px] transition-colors duration-200 border border-transparent font-medium text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/5"
                                        @click="navigate('/teams')"
                                    >
                                        <Sliders class="h-3 w-3 shrink-0" />
                                        <span class="truncate text-[11.5px]"
                                            >Manage Teams</span
                                        >
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Unpinned Items -->
            <div v-if="navStore.unpinnedItems.length" class="space-y-0.5">
                <p
                    v-if="showExpanded"
                    class="px-2.5 py-1.5 text-[10px] font-semibold text-[var(--text-muted)] opacity-60"
                >
                    More
                </p>
                <template v-for="item in navStore.unpinnedItems" :key="item.id">
                    <Tooltip
                        :content="item.label"
                        side="right"
                        :delay-duration="200"
                        :side-offset="10"
                        content-class="font-medium bg-[var(--text-primary)] text-[var(--text-inverse)] border-none shadow-md px-3 py-1.5 text-xs rounded-lg"
                    >
                        <div class="relative group/item">
                            <button
                                :class="
                                    cn(
                                        'group relative flex w-full items-center rounded-lg transition-colors duration-200 border border-transparent',
                                        // Base spacing
                                        !showExpanded
                                            ? 'justify-center h-8 w-8 p-0 mx-auto rounded-md'
                                            : 'px-2.5 py-1.5 gap-2.5',
                                        // Active State (Pill)
                                        isActive(item.route) ||
                                            isChildActive(item)
                                            ? 'bg-[var(--surface-tertiary)] text-[var(--text-primary)] font-medium'
                                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]',
                                    )
                                "
                                @click="handleItemClick(item)"
                            >
                                <component
                                    :is="getIcon(item.icon)"
                                    :class="
                                        cn(
                                            showExpanded
                                                ? 'h-4 w-4 shrink-0 transition-colors duration-200'
                                                : 'h-3.5 w-3.5 shrink-0 transition-colors duration-200',
                                            isActive(item.route) ||
                                                isChildActive(item)
                                                ? 'text-[var(--text-primary)]'
                                                : 'text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]',
                                        )
                                    "
                                    stroke-width="2"
                                />
                                <span
                                    :class="
                                        cn(
                                            'flex-1 text-left whitespace-nowrap overflow-hidden text-[13px] transition-all duration-300 ease-in-out',
                                            !showExpanded
                                                ? 'max-w-0 opacity-0'
                                                : 'max-w-[200px] opacity-100',
                                        )
                                    "
                                >
                                    {{ item.label }}
                                </span>

                                <!-- Badge -->
                                <span
                                    v-if="
                                        navStore.getBadge(item) && showExpanded
                                    "
                                    class="rounded-md px-1.5 py-0.5 text-[10px] font-medium transition-colors ml-2 bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-subtle)]"
                                >
                                    {{ navStore.getBadge(item) }}
                                </span>

                                <!-- Badge (collapsed) -->
                                <div
                                    v-if="
                                        navStore.getBadge(item) && !showExpanded
                                    "
                                    class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-[var(--color-primary-500)] ring-2 ring-[var(--surface-primary)]"
                                ></div>

                                <!-- Expand/Collapse Arrow -->
                                <ChevronDown
                                    v-if="
                                        navStore.hasChildren(item) &&
                                        showExpanded
                                    "
                                    :class="
                                        cn(
                                            'h-3 w-3 shrink-0 text-[var(--text-muted)] transition-transform duration-200',
                                            navStore.isExpanded(item.id) &&
                                                'rotate-180',
                                        )
                                    "
                                />
                            </button>

                            <!-- Pin Button (shows on hover) -->
                            <button
                                v-if="showExpanded && item.pinnable !== false"
                                @click.stop="navStore.togglePin(item.id)"
                                :class="
                                    cn(
                                        'absolute top-1/2 -translate-y-1/2 p-1 rounded opacity-0 group-hover/item:opacity-100 transition-opacity text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--surface-secondary)]',
                                        navStore.hasChildren(item)
                                            ? 'right-8'
                                            : 'right-1',
                                    )
                                "
                                title="Pin"
                            >
                                <Pin class="h-3 w-3" />
                            </button>
                        </div>
                    </Tooltip>

                    <!-- Children -->
                    <div
                        v-if="navStore.hasChildren(item) && showExpanded"
                        class="grid transition-all duration-200 ease-in-out"
                        :class="
                            navStore.isExpanded(item.id)
                                ? 'grid-rows-[1fr] opacity-100 mb-1'
                                : 'grid-rows-[0fr] opacity-0'
                        "
                    >
                        <div class="overflow-hidden">
                            <div
                                class="ml-[1rem] pl-2.5 border-l border-[var(--border-muted)] space-y-0.5 pt-0.5"
                            >
                                <template
                                    v-for="child in item.children"
                                    :key="child.id"
                                >
                                    <div
                                        v-if="child.type === 'divider'"
                                        class="my-1 h-px bg-[var(--border-muted)]/50 mx-2"
                                    ></div>
                                    <div
                                        v-else-if="child.type === 'header'"
                                        class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)] opacity-80 mt-0.5"
                                    >
                                        {{ child.label }}
                                    </div>
                                    <button
                                        v-else
                                        :class="
                                            cn(
                                                'flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-[12.5px] transition-colors duration-200 border border-transparent font-medium',
                                                isActive(child.route)
                                                    ? 'text-[var(--text-primary)] bg-[var(--surface-secondary)]'
                                                    : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)]/50',
                                                'cursor-pointer',
                                            )
                                        "
                                        @click="
                                            child.route && navigate(child.route, !!child.new_tab)
                                        "
                                    >
                                        <component
                                            v-if="child.icon"
                                            :is="getIcon(child.icon)"
                                            class="h-3 w-3 shrink-0 text-[var(--text-muted)]"
                                        />
                                        <span class="truncate">{{
                                            child.label
                                        }}</span>
                                        <span
                                            v-if="child.team_badge"
                                            class="ml-auto text-[10px] px-1.5 py-0.5 rounded bg-[var(--surface-tertiary)] text-[var(--text-muted)] border border-[var(--border-subtle)] shrink-0"
                                        >
                                            {{ child.team_badge }}
                                        </span>
                                    </button>
                                </template>
                                <!-- Static Team Actions -->
                                <template v-if="item.id === 'teams'">
                                    <div
                                        class="my-1 h-px bg-[var(--border-muted)]/50 mx-2"
                                    ></div>
                                    <button
                                        class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1 text-[11.5px] transition-colors duration-200 border border-transparent font-medium text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/5"
                                        @click="navigate('/teams?create=true')"
                                    >
                                        <Plus class="h-3 w-3 shrink-0" />
                                        <span class="truncate text-[11.5px]"
                                            >Create New Team</span
                                        >
                                    </button>
                                    <button
                                        class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1 text-[11.5px] transition-colors duration-200 border border-transparent font-medium text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/5"
                                        @click="navigate('/teams')"
                                    >
                                        <Sliders class="h-3 w-3 shrink-0" />
                                        <span class="truncate text-[11.5px]"
                                            >Manage Teams</span
                                        >
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </nav>

        <!-- Unified User Context Bar -->
        <div :class="cn(!isCompactRail ? 'p-2.5 mt-auto' : 'px-1.5 pb-1.5 pt-1 mt-auto')">
            <div
                :class="
                    cn(
                        'flex',
                        !isCompactRail
                            ? 'items-center gap-1.5'
                            : 'flex-col items-center gap-1',
                    )
                "
            >
                <Dropdown
                    align="start"
                    side="top"
                    :side-offset="12"
                    :class="cn(!isCompactRail ? 'min-w-0 flex-1' : '')"
                >
                    <template #trigger>
                        <button
                            :class="
                                cn(
                                    'flex w-full items-center rounded-lg p-1.5 transition-colors duration-200 cursor-pointer',
                                    'hover:bg-[var(--surface-secondary)] active:scale-[0.98]',
                                    isCompactRail
                                        ? 'justify-center gap-0 p-0 h-9 w-9'
                                        : 'gap-2',
                                )
                            "
                        >
                            <div class="relative">
                                <Avatar
                                    :src="authStore.avatarUrl"
                                    :fallback="authStore.initials"
                                    :status="currentStatus"
                                    size="sm"
                                    class="rounded-md"
                                />
                            </div>

                            <div
                                :class="
                                    cn(
                                        'flex-1 min-w-0 text-left overflow-hidden whitespace-nowrap transition-all duration-300 ease-in-out',
                                        isCompactRail
                                            ? 'max-w-0 opacity-0'
                                            : 'max-w-[200px] opacity-100',
                                    )
                                "
                            >
                                <p
                                    class="truncate text-[12.5px] font-medium leading-tight text-[var(--text-primary)]"
                                >
                                    {{ authStore.displayName }}
                                </p>
                            </div>
                        </button>
                    </template>

                    <DropdownLabel>Status</DropdownLabel>
                    <StatusSelector size="sm" />
                    <DropdownSeparator />
                    <DropdownLabel>Account</DropdownLabel>
                    <DropdownItem @select="navigate('/profile')">
                        <User class="h-4 w-4" />
                        <span>Profile</span>
                    </DropdownItem>
                    <DropdownItem @select="navigate('/settings')">
                        <Settings class="h-4 w-4" />
                        <span>Settings</span>
                    </DropdownItem>
                    <DropdownSeparator />
                    <DropdownItem destructive @select="handleLogout">
                        <LogOut class="h-4 w-4" />
                        <span>Log out</span>
                    </DropdownItem>
                </Dropdown>

                <div class="shrink-0">
                    <ThemeToggle />
                </div>

                <Dropdown align="start" side="top" :side-offset="12">
                    <template #trigger>
                        <button
                            class="relative flex h-9 w-9 items-center justify-center rounded-lg text-[var(--text-muted)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]"
                        >
                            <Bell class="h-4 w-4 shrink-0" />
                            <span
                                v-if="notificationsStore.unreadCount > 0"
                                class="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-[var(--color-error)] px-1 text-[10px] font-bold text-white"
                            >
                                {{
                                    notificationsStore.unreadCount > 9
                                        ? "9+"
                                        : notificationsStore.unreadCount
                                }}
                            </span>
                        </button>
                    </template>

                    <div
                        class="w-80 overflow-hidden rounded-xl bg-[var(--surface-elevated)] shadow-xl ring-1 ring-black/5 dark:ring-white/10 sm:w-96"
                    >
                        <div
                            class="flex items-center justify-between border-b border-[var(--border-default)] bg-[var(--surface-secondary)]/50 px-4 py-3 backdrop-blur-sm"
                        >
                            <span class="font-semibold text-[var(--text-primary)]">
                                Notifications
                            </span>
                            <button
                                v-if="notificationsStore.unreadCount > 0"
                                class="text-xs font-semibold text-[var(--interactive-primary)] transition-colors hover:text-[var(--interactive-primary-hover)]"
                                @click="notificationsStore.markAllRead"
                            >
                                Mark all read
                            </button>
                        </div>

                        <div
                            class="max-h-[28rem] overflow-y-auto overscroll-contain scrollbar-thin scrollbar-track-transparent scrollbar-thumb-[var(--border-default)]"
                        >
                            <div
                                v-if="
                                    notificationsStore.isLoading &&
                                    notificationsStore.notifications.length === 0
                                "
                                class="flex flex-col items-center justify-center py-8 text-[var(--text-muted)]"
                            >
                                <Loader2 class="mb-2 h-6 w-6 animate-spin opacity-60" />
                                <span class="text-xs">Loading...</span>
                            </div>

                            <div
                                v-else-if="
                                    notificationsStore.notifications.length === 0
                                "
                                class="flex flex-col items-center justify-center px-4 py-12 text-center"
                            >
                                <div
                                    class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--surface-secondary)]"
                                >
                                    <Bell class="h-6 w-6 text-[var(--text-muted)]" />
                                </div>
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                >
                                    No notifications
                                </p>
                                <p class="mt-1 text-xs text-[var(--text-secondary)]">
                                    We'll let you know when something arrives.
                                </p>
                            </div>

                            <template v-else>
                                <NotificationItem
                                    v-for="notification in notificationsStore.notifications"
                                    :key="notification.id"
                                    :notification="notification"
                                    @read="notificationsStore.markAsRead"
                                />

                                <div
                                    v-if="notificationsStore.hasMore"
                                    class="p-2 text-center"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="w-full text-xs text-[var(--text-muted)]"
                                        :loading="notificationsStore.isLoading"
                                        @click="notificationsStore.fetchNotifications()"
                                    >
                                        Load older notifications
                                    </Button>
                                </div>
                            </template>
                        </div>
                    </div>
                </Dropdown>
            </div>
        </div>
    </aside>
</template>
