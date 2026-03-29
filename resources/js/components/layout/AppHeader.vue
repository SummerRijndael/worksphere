<script setup>
import { useRouter } from "vue-router";
import { useNavigationStore } from "@/stores/navigation";
import { useAuthStore } from "@/stores/auth";
import { useDialerStore } from "@/stores/dialer";
import {
    Button,
    Avatar,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    DropdownLabel,
} from "@/components/ui";
import DialerDockBadge from "@/components/dialer/DialerDockBadge.vue";
import DialerPanel from "@/components/dialer/DialerPanel.vue";
import {
    Menu,
    Search,
    User,
    Settings,
    LogOut,
    Command,
} from "lucide-vue-next";

const router = useRouter();
const navStore = useNavigationStore();
const authStore = useAuthStore();
const dialerStore = useDialerStore();

const handleLogout = async () => {
    await authStore.logout();
    router.push("/auth/login");
};
</script>

<template>
    <div
        class="relative sticky top-0 z-30 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]/95 backdrop-blur-md"
    >
        <header class="flex h-16 items-center justify-between gap-4 px-6">
            <!-- Left Section -->
            <div class="flex min-w-0 items-center gap-2">
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-9 w-9 lg:hidden"
                    @click="navStore.toggleMobileSidebar"
                >
                    <Menu class="h-5 w-5" />
                </Button>

                <Button
                    variant="ghost"
                    size="icon"
                    class="h-9 w-9 sm:hidden"
                    @click="navStore.openSearch()"
                >
                    <Search class="h-5 w-5" />
                </Button>

                <div class="ml-[-0.5em] relative hidden sm:block">
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

                <div
                    v-if="authStore.isImpersonating"
                    class="hidden items-center gap-2 rounded-lg border border-yellow-500/20 bg-yellow-500/10 px-3 py-1.5 text-sm font-medium text-yellow-600 xl:flex"
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
                <DialerDockBadge inline class="hidden lg:block" />

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
                v-if="dialerStore.launchMode === 'docked' && dialerStore.isDockedOpen"
                class="pointer-events-none absolute right-6 top-full z-[90] hidden pt-2 lg:block"
            >
                <div class="pointer-events-auto w-[336px] max-w-[calc(100vw-2rem)]">
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
