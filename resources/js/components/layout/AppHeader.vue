<script setup>
import { computed, onMounted, onUnmounted } from "vue";
import { useRouter } from "vue-router";
import { useNavigationStore } from "@/stores/navigation";
import { useAuthStore } from "@/stores/auth";
import { useNotificationsStore } from "@/stores/notifications";
import { useDialerStore } from "@/stores/dialer";
import {
    Button,
    Avatar,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    DropdownLabel,
} from "@/components/ui";
import ThemeToggle from "@/components/common/ThemeToggle.vue";
import NotificationItem from "@/components/ui/NotificationItem.vue";
import DialerDockBadge from "@/components/dialer/DialerDockBadge.vue";
import DialerPanel from "@/components/dialer/DialerPanel.vue";
import {
    Menu,
    Bell,
    Search,
    User,
    Settings,
    LogOut,
    Command,
} from "lucide-vue-next";

const router = useRouter();
const navStore = useNavigationStore();
const authStore = useAuthStore();
const notificationsStore = useNotificationsStore();
const dialerStore = useDialerStore();

const shouldShowDockedDialer = computed(() => dialerStore.launchMode === "docked");

const handleLogout = async () => {
    notificationsStore.stopRealtimeListeners();
    await authStore.logout();
    router.push("/auth/login");
};

onMounted(() => {
    notificationsStore.fetchNotifications(true);
    notificationsStore.fetchUnreadCount();
    notificationsStore.startRealtimeListeners();
});

onUnmounted(() => {
    notificationsStore.stopRealtimeListeners();
});
</script>

<template>
    <div
        class="sticky top-0 z-30 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]/95 backdrop-blur-md"
    >
        <header class="flex h-16 items-center justify-between gap-4 px-6">
            <!-- Left Section -->
            <div class="flex items-center gap-3">
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-9 w-9 lg:hidden"
                    @click="navStore.toggleMobileSidebar"
                >
                    <Menu class="h-5 w-5" />
                </Button>

                <div
                    v-if="authStore.isImpersonating"
                    class="hidden items-center gap-2 rounded-lg border border-yellow-500/20 bg-yellow-500/10 px-3 py-1.5 text-sm font-medium text-yellow-600 md:flex"
                >
                    <User class="h-4 w-4" />
                    <span>Impersonating {{ authStore.displayName }}</span>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="ml-2 h-6 px-2 text-yellow-700 hover:bg-yellow-500/20"
                        @click="authStore.stopImpersonating()"
                    >
                        Stop
                    </Button>
                </div>
            </div>

            <!-- Right Section -->
            <div class="flex items-center gap-1.5">
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-9 w-9 sm:hidden"
                    @click="navStore.openSearch()"
                >
                    <Search class="h-5 w-5" />
                </Button>

                <div class="relative hidden sm:block">
                    <Search
                        class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
                    />
                    <input
                        readonly
                        type="text"
                        placeholder="Search anything..."
                        class="h-10 w-72 cursor-pointer rounded-xl border border-[var(--border-default)] bg-[var(--surface-secondary)] pl-10 pr-20 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all focus:border-[var(--interactive-primary)] focus:bg-[var(--surface-elevated)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20"
                        @click="navStore.openSearch()"
                    />
                    <div
                        class="absolute right-3 top-1/2 hidden -translate-y-1/2 items-center gap-1 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-1.5 py-1 text-[10px] font-semibold text-[var(--text-muted)] lg:flex"
                    >
                        <Command class="h-3 w-3" />
                        <span>K</span>
                    </div>
                </div>

                <DialerDockBadge
                    v-if="shouldShowDockedDialer"
                    inline
                    class="hidden lg:block"
                />

                <ThemeToggle />

                <Dropdown align="end" :side-offset="8" class="z-50">
                    <template #trigger>
                        <Button variant="ghost" size="icon" class="relative h-9 w-9">
                            <Bell class="h-5 w-5" />
                            <span
                                v-if="notificationsStore.unreadCount > 0"
                                class="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-[var(--color-error)] text-[10px] font-bold text-white ring-2 ring-[var(--surface-elevated)]"
                            >
                                {{
                                    notificationsStore.unreadCount > 9
                                        ? "9+"
                                        : notificationsStore.unreadCount
                                }}
                            </span>
                        </Button>
                    </template>

                    <div
                        class="w-80 overflow-hidden rounded-xl bg-[var(--surface-elevated)] shadow-xl ring-1 ring-black/5 dark:ring-white/10 sm:w-96"
                    >
                        <div
                            class="flex items-center justify-between border-b border-[var(--border-default)] bg-[var(--surface-secondary)]/50 px-4 py-3 backdrop-blur-sm"
                        >
                            <span class="font-semibold text-[var(--text-primary)]"
                                >Notifications</span
                            >
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
                                <ThemeToggle
                                    class="mb-2 h-6 w-6 animate-spin opacity-50"
                                />
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
                                    <Bell
                                        class="h-6 w-6 text-[var(--text-muted)]"
                                    />
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

                <div class="lg:hidden">
                    <Dropdown align="end">
                        <template #trigger>
                            <Button variant="ghost" size="icon" class="h-9 w-9">
                                <Avatar
                                    :src="authStore.avatarUrl"
                                    :fallback="authStore.initials"
                                    size="xs"
                                />
                            </Button>
                        </template>

                        <DropdownLabel>{{ authStore.user?.email }}</DropdownLabel>
                        <DropdownItem @select="router.push({ name: 'profile' })">
                            <User class="h-4 w-4" />
                            <span>Profile</span>
                        </DropdownItem>
                        <DropdownItem @select="router.push({ name: 'settings' })">
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
            </div>
        </header>

        <Transition name="dialer-header-slide">
            <div
                v-if="shouldShowDockedDialer && dialerStore.isDockedOpen"
                class="border-t border-[var(--border-default)] px-6 pb-3 pt-2 max-lg:hidden"
            >
                <div class="ml-auto w-full max-w-[360px]">
                    <DialerPanel embedded />
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.dialer-header-slide-enter-active,
.dialer-header-slide-leave-active {
    transition: opacity 180ms ease, transform 180ms ease;
}

.dialer-header-slide-enter-from,
.dialer-header-slide-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>
