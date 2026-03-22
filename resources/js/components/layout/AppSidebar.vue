<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useNavigationStore } from "@/stores/navigation";
import { useAuthStore } from "@/stores/auth";
import { appConfig } from "@/config/app";
import { cn } from "@/lib/utils";
import type {
    NavigationItem,
    NavigationChild,
} from "@/types/models/navigation";

// ... (existing imports)

import { usePresence } from "@/composables/usePresence.ts";
import {
    Avatar,
    Tooltip,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    DropdownLabel,
} from "@/components/ui";
import StatusSelector from "@/components/ui/StatusSelector.vue";
import {
    LayoutDashboard,
    BarChart3,
    FolderKanban,
    Bell,
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
    Phone,
    Video,
} from "lucide-vue-next";
// DialerModal removed in favor of popup window

const route = useRoute();
const router = useRouter();
const navStore = useNavigationStore();
const authStore = useAuthStore();
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

function handleItemClick(item: NavigationItem): void {
    if (navStore.hasChildren(item) && !showExpanded.value) {
        navStore.toggleExpanded(item.id);
    } else if (navStore.hasChildren(item) && showExpanded.value) {
        navStore.toggleExpanded(item.id);
    } else if (item.route) {
        navigate(item.route);
    }
}

function openDialerPopup() {
    const width = 340;
    const height = 540;
    const left = window.screenX + (window.outerWidth - width) / 2;
    const top = window.screenY + (window.outerHeight - height) / 2;

    window.open(
        "/dialer",
        "WorkSphereDialer",
        `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no,status=no,location=no,toolbar=no,menubar=no`,
    );
}

onMounted(() => {
    navStore.fetchNavigation();
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

        <!-- Dialer Quick Action (Demo) -->
        <div :class="cn(showExpanded ? 'px-2.5 mb-1.5' : 'px-1.5 mb-1')">
            <Tooltip
                content="Phone Dialer (Demo)"
                side="right"
                :delay-duration="200"
                :side-offset="10"
                content-class="font-medium bg-[var(--text-primary)] text-[var(--text-inverse)] border-none shadow-md px-3 py-1.5 text-xs rounded-lg"
            >
                <button
                    @click="openDialerPopup"
                    :class="
                        cn(
                            'group flex items-center rounded-lg transition-all duration-300 border cursor-pointer',
                            'bg-emerald-500/5 border-emerald-500/10 hover:border-emerald-500/30 hover:bg-emerald-500/10',
                            'text-emerald-600 dark:text-emerald-400',
                            !showExpanded
                                ? 'justify-center p-0 w-9 h-9 mx-auto rounded-md'
                                : 'w-full px-2.5 py-1.5 gap-2.5',
                        )
                    "
                >
                    <Phone
                        :class="
                            cn(
                                'shrink-0',
                                showExpanded ? 'h-4 w-4' : 'h-3.5 w-3.5',
                            )
                        "
                        stroke-width="2.5"
                    />
                    <span
                        :class="
                            cn(
                                'text-[12.5px] font-semibold truncate transition-all duration-300',
                                !showExpanded
                                    ? 'max-w-0 opacity-0'
                                    : 'max-w-[200px] opacity-100',
                            )
                        "
                    >
                        Dialer
                        <span class="text-[10px] opacity-70 ml-1">DEMO</span>
                    </span>
                </button>
            </Tooltip>
        </div>

        <!-- User Section -->
        <div :class="cn(showExpanded ? 'p-2.5 mt-auto' : 'px-1.5 pb-1.5 pt-1 mt-auto')">
            <Dropdown align="start" side="top" :side-offset="12" class="w-full">
                <template #trigger>
                    <button
                        :class="
                            cn(
                                'flex w-full items-center rounded-lg p-1.5 transition-colors duration-200',
                                'hover:bg-[var(--surface-secondary)] active:scale-[0.98]',
                                'cursor-pointer',
                                !showExpanded
                                    ? 'justify-center gap-0 p-0 w-8 h-8 mx-auto'
                                    : 'gap-2.5',
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
                                    'flex-1 text-left min-w-0 transition-all duration-300 ease-in-out overflow-hidden whitespace-nowrap',
                                    !showExpanded
                                        ? 'max-w-0 opacity-0'
                                        : 'max-w-[200px] opacity-100',
                                )
                            "
                        >
                            <p
                                class="text-[12.5px] font-medium text-[var(--text-primary)] truncate leading-tight"
                            >
                                {{ authStore.displayName }}
                            </p>
                            <p
                                class="text-[11px] text-[var(--text-muted)] truncate"
                            ></p>
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
        </div>
    </aside>
</template>
