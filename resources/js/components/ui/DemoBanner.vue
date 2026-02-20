<script setup lang="ts">
import { computed } from "vue";
import { useAuthStore } from "@/stores/auth";
import { ShieldAlert } from "lucide-vue-next";

const authStore = useAuthStore();
const isDemoMode = computed(() => authStore.user?.is_demo_mode || false);
</script>

<template>
    <div
        v-if="isDemoMode"
        class="bg-orange-600 text-white py-2 px-4 text-center text-xs font-bold uppercase tracking-wider flex items-center justify-center gap-2 shadow-md z-[9999] relative"
    >
        <ShieldAlert class="h-4 w-4" />
        <span>
            <strong>DEMO MODE ACTIVE</strong>
        </span>
        <span class="hidden sm:inline normal-case font-medium tracking-normal opacity-90 mx-1">
            &mdash; Changes will not be saved.
        </span>
    </div>
</template>

<style scoped>
/* Optional: add a subtle pulse or animation if desired */
div {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        transform: translateY(-100%);
    }
    to {
        transform: translateY(0);
    }
}
</style>
