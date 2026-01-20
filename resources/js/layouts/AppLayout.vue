<script setup>
import { computed, onMounted, onBeforeUnmount, watch } from "vue";
import { RouterView, useRoute, useRouter } from "vue-router";
import { useNavigationStore } from "@/stores/navigation";
import { useAuthStore } from "@/stores/auth";
import { usePageTitle } from "@/composables/usePageTitle";
import AppSidebar from "@/components/layout/AppSidebar.vue";
import AppHeader from "@/components/layout/AppHeader.vue";
import Breadcrumbs from "@/components/common/Breadcrumbs.vue";
import GlobalSearch from "@/components/common/GlobalSearch.vue";
import AnnouncementBanner from "@/components/AnnouncementBanner.vue";
import MediaViewer from "@/components/tools/MediaViewer.vue";
import { ScrollToTop } from "@/components/ui";
import AccountBlockedModal from "@/components/AccountBlockedModal.vue";
import RoleChangeNotificationModal from "@/components/RoleChangeNotificationModal.vue";
import MiniChatLauncher from "@/components/minichat/MiniChatLauncher.vue";

const route = useRoute();
const router = useRouter();
const navStore = useNavigationStore();
const authStore = useAuthStore();

// Initialize dynamic page title blinking
usePageTitle();

const isFullWidth = computed(
    () => route.meta.layoutFullWidth || route.path.startsWith("/chat"),
);
const isFullscreen = computed(
    () => route.meta.layout === "fullscreen" || route.path.startsWith("/chat"),
);
const isFixedLayout = computed(
    () => route.meta.layoutFixed || isFullscreen.value,
);

const sidebarWidth = computed(() => {
    if (isFullscreen.value) return "0px";
    return navStore.isSidebarCollapsed
        ? "var(--sidebar-width-collapsed)"
        : "var(--sidebar-width)";
});

const mainStyles = computed(() => ({
    marginLeft: sidebarWidth.value,
    transition: "margin-left 0.3s ease",
    width: isFullscreen.value ? "100%" : `calc(100% - ${sidebarWidth.value})`,
}));

// Start all listeners when the app layout mounts (user is authenticated)
// We add a delay to ensure session cookies are fully propagated after login/2FA
onMounted(async () => {
    printSecurityWarning();

    // Delay listener initialization to prevent race conditions after 2FA session regeneration
    // This gives the browser time to fully process new session/CSRF cookies
    await new Promise((resolve) => setTimeout(resolve, 1000));
    console.log("[AppLayout] Starting all listeners after delay");
    authStore.startAllListeners();
});

function printSecurityWarning() {
    // Use window.console to bypass esbuild drop: ['console']
    if (window.console && window.console.log) {
        window.console.log(
            "%cStop!",
            "color: #ef4444; font-size: 50px; font-weight: bold; -webkit-text-stroke: 1px black;",
        );
        window.console.log(
            "%cThis is a browser feature intended for developers. If someone told you to copy-paste something here to enable a feature or 'hack' someone's account, it is a scam and will give them access to your account.",
            "font-size: 18px;",
        );
    }
}

onBeforeUnmount(() => {
    authStore.stopAllListeners();
});

// Watch for 2FA enforcement and redirect to setup page
watch(
    () => authStore.requires2FASetup,
    (requires) => {
        if (requires && route.name !== "enforce-2fa-setup") {
            router.push({ name: "enforce-2fa-setup" });
        }
    },
    { immediate: true },
);

// Handle role change acknowledgment - user needs to logout for changes to take effect
function handleRoleChangeAcknowledge() {
    authStore.dismissRoleChangeModal();
}

function handleRoleChangeLogout() {
    authStore.dismissRoleChangeModal();
    authStore.logout();
    router.push({ name: "login" });
}
</script>

<template>
    <div class="min-h-screen bg-[var(--surface-primary)]">
        <!-- Sidebar -->
        <Transition name="slide-left">
            <AppSidebar v-if="!isFullscreen" />
        </Transition>

        <!-- Mobile Overlay -->
        <Transition name="fade">
            <div
                v-if="navStore.isMobileSidebarOpen"
                class="fixed inset-0 z-40 bg-black/50 lg:hidden"
                @click="navStore.closeMobileSidebar"
            />
        </Transition>

        <!-- Main Content -->
        <div
            :class="[
                'lg:transition-[margin] lg:duration-300 flex flex-col w-full min-w-0',
                isFixedLayout ? 'h-[100dvh] overflow-hidden' : 'min-h-[100dvh]',
            ]"
            :style="mainStyles"
        >
            <!-- Header -->
            <AppHeader v-if="!isFullscreen" />

            <!-- Announcements (below navbar, above content) -->
            <AnnouncementBanner v-if="!isFullscreen" />

            <!-- Page Content -->
            <main
                :class="[
                    'flex-1 flex flex-col',
                    isFullWidth || isFullscreen ? 'p-0' : 'p-6',
                ]"
            >
                <!-- Breadcrumbs -->
                <Breadcrumbs
                    v-if="
                        !isFullscreen &&
                        (!isFullWidth || route.meta.showBreadcrumbs)
                    "
                    class="mb-6"
                    :class="{ 'px-6 pt-6': isFullWidth }"
                />

                <!-- Route Content -->
                <RouterView v-slot="{ Component, route: currentRoute }">
                    <Transition
                        v-if="currentRoute.meta.transition !== 'none'"
                        :name="currentRoute.meta.transition || 'fade'"
                        mode="out-in"
                    >
                        <component
                            :is="Component"
                            :key="currentRoute.path"
                            class="flex-1 w-full min-w-0"
                        />
                    </Transition>
                    <component
                        v-else
                        :is="Component"
                        :key="currentRoute.path"
                        class="flex-1 w-full min-w-0"
                    />
                </RouterView>
            </main>

            <!-- App Footer -->
            <footer
                v-if="!isFullscreen && !isFixedLayout"
                class="py-6 px-6 border-t border-[var(--border-default)] bg-[var(--surface-primary)]"
            >
                <div
                    class="flex flex-col md:flex-row items-center justify-between gap-4 text-xs text-[var(--text-tertiary)]"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-[var(--text-secondary)]"
                            >CoreSync v2.0.0</span
                        >
                        <span
                            >&copy; {{ new Date().getFullYear() }} All rights
                            reserved.</span
                        >
                    </div>
                    <div class="flex items-center gap-6">
                        <a
                            href="/privacy"
                            class="hover:text-[var(--text-primary)] transition-colors"
                            >Privacy Policy</a
                        >
                        <a
                            href="/terms"
                            class="hover:text-[var(--text-primary)] transition-colors"
                            >Terms of Service</a
                        >
                        <a
                            href="/public/faq"
                            class="hover:text-[var(--text-primary)] transition-colors"
                            >Help Center</a
                        >
                    </div>
                </div>
            </footer>
        </div>

        <GlobalSearch />
        <MediaViewer />
        <ScrollToTop />

        <!-- Mini Chat Launcher (hidden on fullscreen/chat pages) -->
        <MiniChatLauncher v-if="!isFullscreen" />

        <!-- Account Blocked Modal (highest z-index, teleported to body) -->
        <AccountBlockedModal
            v-if="
                authStore.showBlockedModal &&
                authStore.statusChangeEvent?.status
            "
            :status="authStore.statusChangeEvent?.status"
            :reason="authStore.statusChangeEvent?.reason"
            :suspended-until="authStore.statusChangeEvent?.suspended_until"
            :changed-by="authStore.statusChangeEvent?.changed_by?.name"
            @logout="authStore.handleBlockedLogout"
        />

        <!-- Role Change Notification Modal -->
        <RoleChangeNotificationModal
            :show="authStore.showRoleChangeModal"
            :from-role="authStore.roleChangeEvent?.from_role"
            :to-role="authStore.roleChangeEvent?.to_role"
            :reason="authStore.roleChangeEvent?.reason"
            :changed-by="authStore.roleChangeEvent?.changed_by?.name"
            @acknowledge="handleRoleChangeAcknowledge"
            @logout="handleRoleChangeLogout"
        />
    </div>
</template>

<style scoped>
@media (max-width: 1023px) {
    .lg\:transition-\[margin\] {
        margin-left: 0 !important;
        width: 100% !important;
    }
}
</style>
