<script setup>
import { onMounted, watch, computed } from "vue";
import { RouterView, useRoute } from "vue-router";
import { useThemeStore } from "@/stores/theme";
import { Toaster } from "vue-sonner";
import NetworkStatus from "@/components/ui/NetworkStatus.vue";

// Import Echo to initialize WebSocket connection
import "@/echo";

const themeStore = useThemeStore();
const route = useRoute();

const toasterTheme = computed(() => (themeStore.isDark ? "dark" : "light"));

// Initialize theme on mount
onMounted(() => {
    themeStore.initializeTheme();
});

// Initialize presence system globally
import { usePresence } from "@/composables/usePresence";
usePresence({ manageLifecycle: true });

// Watch for theme changes
watch(
    () => themeStore.isDark,
    (isDark) => {
        document.documentElement.classList.toggle("dark", isDark);
    },
    { immediate: true }
);
</script>

<template>
    <!-- Network status banner (shows when offline) -->
    <NetworkStatus position="top" />

    <RouterView />
    <Toaster
        class="toaster group"
        :theme="toasterTheme"
        position="top-right"
        :close-button="true"
        :expand="false"
        :duration="5000"
        :toast-options="{
            classNames: {
                toast: 'group toast group-[.toaster]:bg-[var(--surface-elevated)] group-[.toaster]:text-[var(--text-primary)] group-[.toaster]:border-[var(--border-default)] group-[.toaster]:shadow-lg',
                description: 'group-[.toast]:text-[var(--text-secondary)]',
                actionButton:
                    'group-[.toast]:bg-[var(--interactive-primary)] group-[.toast]:text-white',
                cancelButton:
                    'group-[.toast]:bg-[var(--surface-tertiary)] group-[.toast]:text-[var(--text-primary)]',
            },
        }"
    />
</template>

