<script setup>
import { ref } from 'vue';
import { useNavigationStore } from '@/stores/navigation';
import { useAuthStore } from '@/stores/auth';
import { Button, Avatar, Dropdown, DropdownItem, DropdownSeparator, DropdownLabel, Badge } from '@/components/ui';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import {
    Menu,
    Bell,
    Search,
    User,
    Settings,
    LogOut,
    Command,
} from 'lucide-vue-next';

const navStore = useNavigationStore();
const authStore = useAuthStore();



import NotificationItem from '@/components/ui/NotificationItem.vue';
import { useNotificationsStore } from '@/stores/notifications';

const notificationsStore = useNotificationsStore();

// Initial fetch
import { onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();

const handleLogout = async () => {
    notificationsStore.stopRealtimeListeners(); // Cleanup before logout
    await authStore.logout();
    router.push('/auth/login');
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
    <header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]/95 backdrop-blur-md px-6">
        <!-- Left Section -->
        <div class="flex items-center gap-3">
            <!-- Mobile Menu Button -->
            <Button
                variant="ghost"
                size="icon"
                class="lg:hidden h-9 w-9"
                @click="navStore.toggleMobileSidebar"
            >
                <Menu class="h-5 w-5" />
            </Button>

           
        </div>

        <!-- Right Section -->
        <div class="flex items-center gap-1.5">
            <!-- Mobile Search Button -->
            <Button
                variant="ghost"
                size="icon"
                class="sm:hidden h-9 w-9"
                @click="navStore.openSearch()"
            >
                <Search class="h-5 w-5" />
            </Button>

             <!-- Search -->
            <div class="relative hidden sm:block">
                <Search class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]" />
                <input
                    readonly
                    @click="navStore.openSearch()"
                    type="text"
                    placeholder="Search anything..."
                    class="h-10 w-72 cursor-pointer rounded-xl border border-[var(--border-default)] bg-[var(--surface-secondary)] pl-10 pr-20 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:bg-[var(--surface-elevated)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all"
                />
                <div class="absolute right-3 top-1/2 -translate-y-1/2 hidden lg:flex items-center gap-1 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-1.5 py-1 text-[10px] font-semibold text-[var(--text-muted)]">
                    <Command class="h-3 w-3" />
                    <span>K</span>
                </div>
            </div>

            <!-- Theme Toggle -->
            <ThemeToggle />

            <!-- Notifications -->
            <Dropdown align="end" :side-offset="8" class="z-50">
                <template #trigger>
                    <Button variant="ghost" size="icon" class="relative h-9 w-9">
                        <Bell class="h-5 w-5" />
                        <span
                            v-if="notificationsStore.unreadCount > 0"
                            class="absolute top-1 right-1 flex h-4 w-4 items-center justify-center rounded-full bg-[var(--color-error)] text-[10px] font-bold text-white ring-2 ring-[var(--surface-elevated)]"
                        >
                            {{ notificationsStore.unreadCount > 9 ? '9+' : notificationsStore.unreadCount }}
                        </span>
                    </Button>
                </template>

                <div class="w-80 sm:w-96 rounded-xl shadow-xl bg-[var(--surface-elevated)] ring-1 ring-black/5 dark:ring-white/10 overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--border-default)] bg-[var(--surface-secondary)]/50 backdrop-blur-sm">
                        <span class="font-semibold text-[var(--text-primary)]">Notifications</span>
                        <button
                            v-if="notificationsStore.unreadCount > 0"
                            class="text-xs font-semibold text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] transition-colors"
                            @click="notificationsStore.markAllRead"
                        >
                            Mark all read
                        </button>
                    </div>

                    <div 
                        class="max-h-[28rem] overflow-y-auto overscroll-contain scrollbar-thin scrollbar-thumb-[var(--border-default)] scrollbar-track-transparent"
                    >
                        <div v-if="notificationsStore.isLoading && notificationsStore.notifications.length === 0" class="flex flex-col items-center justify-center py-8 text-[var(--text-muted)]">
                            <ThemeToggle class="h-6 w-6 animate-spin mb-2 opacity-50" /> <!-- Reusing icon as loader if needed, or generic loader -->
                            <span class="text-xs">Loading...</span>
                        </div>
                        
                        <div v-else-if="notificationsStore.notifications.length === 0" class="flex flex-col items-center justify-center py-12 px-4 text-center">
                            <div class="h-12 w-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-3">
                                <Bell class="h-6 w-6 text-[var(--text-muted)]" />
                            </div>
                            <p class="text-sm font-medium text-[var(--text-primary)]">No notifications</p>
                            <p class="text-xs text-[var(--text-secondary)] mt-1">We'll let you know when something arrives.</p>
                        </div>

                        <template v-else>
                            <NotificationItem
                                v-for="notification in notificationsStore.notifications"
                                :key="notification.id"
                                :notification="notification"
                                @read="notificationsStore.markAsRead"
                            />
                            
                            <!-- Load More trigger -->
                            <div v-if="notificationsStore.hasMore" class="p-2 text-center">
                                <Button 
                                    variant="ghost" 
                                    size="sm" 
                                    class="text-xs w-full text-[var(--text-muted)]"
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

            <!-- User Menu (visible on mobile) -->
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
</template>
