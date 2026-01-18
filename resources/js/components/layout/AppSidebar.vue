<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useNavigationStore } from "@/stores/navigation";
import { useAuthStore } from "@/stores/auth";
import { appConfig } from "@/config/app";
import { cn } from "@/lib/utils";
import type { NavigationItem, NavigationChild } from "@/types/models/navigation";

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
    ChevronLeft,
    ChevronRight,
    ChevronDown,
    LogOut,
    User,
    MoreVertical,
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
} from "lucide-vue-next";

const route = useRoute();
const router = useRouter();
const navStore = useNavigationStore();
const authStore = useAuthStore();
const { currentStatus } = usePresence();

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
            : "max-lg:-translate-x-full"
    )
);

// Helper functions
function isActive(itemRoute: string | undefined): boolean {
    return route.path === itemRoute;
}

function isChildActive(item: NavigationItem): boolean {
    if (!item.children) return false;
    return item.children.some((child: NavigationChild) => route.path === child.route);
}

function navigate(path: string): void {
    router.push(path);
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
const showExpanded = computed(() => !navStore.isSidebarCollapsed || isHovered.value || navStore.isMobileSidebarOpen);

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
            class="flex h-16 items-center justify-between px-4 pb-2 pt-4"
        >
            <!-- Logo -->
            <a
                href="/"
                target="_blank"
                :class="
                    cn(
                        'flex items-center gap-3 transition-all duration-300',
                        !showExpanded && 'justify-center w-full'
                    )
                "
            >
                <div
                    class="h-9 w-9 rounded-lg bg-[var(--color-primary-600)] flex items-center justify-center shrink-0"
                >
                    <svg
                        class="h-5 w-5 text-white"
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
                            'flex flex-col transition-all duration-300',
                            !showExpanded
                                ? 'max-lg:opacity-100 max-lg:w-auto lg:hidden lg:opacity-0 lg:w-0 overflow-hidden'
                                : 'opacity-100'
                        )
                    "
                >
                    <span class="text-[15px] font-semibold text-[var(--text-primary)] leading-none tracking-tight">
                        {{ appConfig.name }}
                    </span>
                    <span class="text-[11px] font-medium text-[var(--text-tertiary)] mt-0.5">
                        Team Workspace
                    </span>
                </div>
            </a>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto overflow-x-hidden p-3 space-y-6 scrollbar-thin hover:scrollbar-thumb-[var(--scrollbar-thumb)]">

            <!-- Pinned Items -->
            <div v-if="navStore.pinnedItems.length" class="space-y-0.5">
                <template v-for="item in navStore.pinnedItems" :key="item.id">
                    <!-- Parent Item -->
                    <Tooltip
                        :content="!showExpanded ? item.label : ''"
                        :disabled="showExpanded"
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
                                            ? 'justify-center p-2 mx-auto' 
                                            : 'px-3 py-2 gap-3',
                                        // Active State (Pill)
                                        isActive(item.route) || isChildActive(item)
                                            ? 'bg-[var(--surface-tertiary)] text-[var(--text-primary)] font-medium'
                                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]'
                                    )
                                "
                                @click="handleItemClick(item)"
                            >
                                <component
                                    :is="getIcon(item.icon)"
                                    :class="
                                        cn(
                                            'h-[1.2rem] w-[1.2rem] shrink-0 transition-colors duration-200',
                                            isActive(item.route) || isChildActive(item)
                                                ? 'text-[var(--text-primary)]'
                                                : 'text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]'
                                        )
                                    "
                                    stroke-width="2"
                                />
                                <span
                                    :class="
                                        cn(
                                            'flex-1 text-left whitespace-nowrap overflow-hidden text-[13.5px]',
                                            !showExpanded
                                                ? 'w-0 opacity-0 hidden'
                                                : 'w-auto opacity-100 block'
                                        )
                                    "
                                >
                                    {{ item.label }}
                                </span>

                                <!-- Badge -->
                                <span
                                    v-if="navStore.getBadge(item) && showExpanded"
                                    class="rounded-md px-1.5 py-0.5 text-[10px] font-medium transition-colors ml-2 bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-subtle)]"
                                >
                                    {{ navStore.getBadge(item) }}
                                </span>

                                <!-- Badge (collapsed) -->
                                <div
                                    v-if="navStore.getBadge(item) && !showExpanded"
                                    class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-[var(--color-primary-500)] ring-2 ring-[var(--surface-primary)]"
                                ></div>

                                <!-- Expand/Collapse Arrow -->
                                <ChevronDown
                                    v-if="navStore.hasChildren(item) && showExpanded"
                                    :class="
                                        cn(
                                            'h-3.5 w-3.5 shrink-0 text-[var(--text-muted)] transition-transform duration-200',
                                            navStore.isExpanded(item.id) && 'rotate-180'
                                        )
                                    "
                                />
                            </button>
                            
                            <!-- Unpin Button (shows on hover) -->
                            <button
                                v-if="showExpanded && item.pinnable !== false"
                                @click.stop="navStore.togglePin(item.id)"
                                :class="cn('absolute top-1/2 -translate-y-1/2 p-1 rounded opacity-0 group-hover/item:opacity-100 transition-opacity text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--surface-secondary)]', navStore.hasChildren(item) ? 'right-8' : 'right-1')"
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
                        :class="navStore.isExpanded(item.id) ? 'grid-rows-[1fr] opacity-100 mb-1' : 'grid-rows-[0fr] opacity-0'"
                    >
                        <div class="overflow-hidden">
                            <div class="ml-[1.1rem] pl-3 border-l border-[var(--border-muted)] space-y-0.5 pt-0.5">
                                <button
                                    v-for="child in item.children"
                                    :key="child.id"
                                    :class="
                                        cn(
                                            'flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-[13px] transition-colors duration-200 border border-transparent font-medium',
                                            isActive(child.route)
                                                ? 'text-[var(--text-primary)] bg-[var(--surface-secondary)]'
                                                : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)]/50',
                                            'cursor-pointer'
                                        )
                                    "
                                    @click="navigate(child.route)"
                                >
                                    <component
                                        v-if="child.icon"
                                        :is="getIcon(child.icon)"
                                        class="h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]"
                                    />
                                    <span class="truncate">{{ child.label }}</span>
                                    <span
                                        v-if="child.team_badge"
                                        class="ml-auto text-[10px] px-1.5 py-0.5 rounded bg-[var(--surface-tertiary)] text-[var(--text-muted)] border border-[var(--border-subtle)] shrink-0"
                                    >
                                        {{ child.team_badge }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Unpinned Items -->
            <div v-if="navStore.unpinnedItems.length" class="space-y-0.5">
                <p
                    v-if="showExpanded"
                    class="px-3 py-2 text-[11px] font-semibold text-[var(--text-muted)] opacity-60"
                >
                    More
                </p>
                <template v-for="item in navStore.unpinnedItems" :key="item.id">
                    <Tooltip
                        :content="!showExpanded ? item.label : ''"
                        :disabled="showExpanded"
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
                                            ? 'justify-center p-2 mx-auto' 
                                            : 'px-3 py-2 gap-3',
                                        // Active State (Pill)
                                        isActive(item.route) || isChildActive(item)
                                            ? 'bg-[var(--surface-tertiary)] text-[var(--text-primary)] font-medium'
                                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]'
                                    )
                                "
                                @click="handleItemClick(item)"
                            >
                                <component
                                    :is="getIcon(item.icon)"
                                    :class="
                                        cn(
                                            'h-[1.2rem] w-[1.2rem] shrink-0 transition-colors duration-200',
                                            isActive(item.route) || isChildActive(item)
                                                ? 'text-[var(--text-primary)]'
                                                : 'text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]'
                                        )
                                    "
                                    stroke-width="2"
                                />
                                <span
                                    :class="
                                        cn(
                                            'flex-1 text-left whitespace-nowrap overflow-hidden text-[13.5px]',
                                            !showExpanded
                                                ? 'w-0 opacity-0 hidden'
                                                : 'w-auto opacity-100 block'
                                        )
                                    "
                                >
                                    {{ item.label }}
                                </span>

                                <!-- Badge -->
                                <span
                                    v-if="navStore.getBadge(item) && showExpanded"
                                    class="rounded-md px-1.5 py-0.5 text-[10px] font-medium transition-colors ml-2 bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-subtle)]"
                                >
                                    {{ navStore.getBadge(item) }}
                                </span>

                                <!-- Badge (collapsed) -->
                                <div
                                    v-if="navStore.getBadge(item) && !showExpanded"
                                    class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-[var(--color-primary-500)] ring-2 ring-[var(--surface-primary)]"
                                ></div>

                                <!-- Expand/Collapse Arrow -->
                                <ChevronDown
                                    v-if="navStore.hasChildren(item) && showExpanded"
                                    :class="
                                        cn(
                                            'h-3.5 w-3.5 shrink-0 text-[var(--text-muted)] transition-transform duration-200',
                                            navStore.isExpanded(item.id) && 'rotate-180'
                                        )
                                    "
                                />
                            </button>
                            
                            <!-- Pin Button (shows on hover) -->
                            <button
                                v-if="showExpanded && item.pinnable !== false"
                                @click.stop="navStore.togglePin(item.id)"
                                :class="cn('absolute top-1/2 -translate-y-1/2 p-1 rounded opacity-0 group-hover/item:opacity-100 transition-opacity text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--surface-secondary)]', navStore.hasChildren(item) ? 'right-8' : 'right-1')"
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
                        :class="navStore.isExpanded(item.id) ? 'grid-rows-[1fr] opacity-100 mb-1' : 'grid-rows-[0fr] opacity-0'"
                    >
                         <div class="overflow-hidden">
                            <div class="ml-[1.1rem] pl-3 border-l border-[var(--border-muted)] space-y-0.5 pt-0.5">
                                <button
                                    v-for="child in item.children"
                                    :key="child.id"
                                    :class="
                                        cn(
                                            'flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-[13px] transition-colors duration-200 border border-transparent font-medium',
                                            isActive(child.route)
                                                ? 'text-[var(--text-primary)] bg-[var(--surface-secondary)]'
                                                : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)]/50',
                                            'cursor-pointer'
                                        )
                                    "
                                    @click="navigate(child.route)"
                                >
                                    <component
                                        v-if="child.icon"
                                        :is="getIcon(child.icon)"
                                        class="h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]"
                                    />
                                    <span class="truncate">{{ child.label }}</span>
                                    <span
                                        v-if="child.team_badge"
                                        class="ml-auto text-[10px] px-1.5 py-0.5 rounded bg-[var(--surface-tertiary)] text-[var(--text-muted)] border border-[var(--border-subtle)] shrink-0"
                                    >
                                        {{ child.team_badge }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </nav>

        <!-- Sidebar Toggle -->
        <button
            class="hidden lg:flex absolute -right-3 top-[1.9em] -translate-y-1/2 z-50 h-8 w-8 items-center justify-center rounded-full border border-[var(--border-muted)] bg-[var(--surface-primary)] text-[var(--text-secondary)] shadow-md hover:text-[var(--text-primary)] hover:border-[var(--border-default)] transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--interactive-primary)] cursor-pointer"
            @click="navStore.toggleSidebar"
        >
            <ChevronRight
                v-if="navStore.isSidebarCollapsed"
                class="h-3 w-3"
                stroke-width="2"
            />
            <ChevronLeft v-else class="h-3 w-3" stroke-width="2" />
        </button>

        <!-- User Section -->
        <div class="p-3 mt-auto">
            <Dropdown align="start" side="top" :side-offset="12" class="w-full">
                <template #trigger>
                    <button
                        :class="
                            cn(
                                'flex w-full items-center rounded-lg p-2 transition-colors duration-200',
                                'hover:bg-[var(--surface-secondary)] active:scale-[0.98]',
                                'cursor-pointer',
                                !showExpanded
                                    ? 'justify-center gap-0'
                                    : 'gap-3'
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
                                    'flex-1 text-left min-w-0 transition-opacity duration-200',
                                    !showExpanded
                                        ? 'hidden opacity-0'
                                        : 'block opacity-100'
                                )
                            "
                        >
                            <p
                                class="text-[13px] font-medium text-[var(--text-primary)] truncate leading-tight"
                            >
                                {{ authStore.displayName }}
                            </p>
                            <p
                                class="text-[11px] text-[var(--text-muted)] truncate"
                            >
                                {{ authStore.user?.email }}
                            </p>
                        </div>
                        <MoreVertical
                            :class="
                                cn(
                                    'h-4 w-4 text-[var(--text-muted)] transition-opacity duration-200',
                                    !showExpanded
                                        ? 'hidden opacity-0'
                                        : 'block opacity-100'
                                )
                            "
                        />
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
