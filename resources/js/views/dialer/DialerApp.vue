<script setup lang="ts">
import { computed } from "vue";
import DialerPanel from "@/components/dialer/DialerPanel.vue";
import { useBeforeUnloadGuard } from "@/composables/useBeforeUnloadGuard";
import { useDialerStore } from "@/stores/dialer";

const dialerStore = useDialerStore();
const hasActiveDialerCall = computed(() => Boolean(dialerStore.activeCall));

useBeforeUnloadGuard(hasActiveDialerCall);
</script>

<template>
    <div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.18),_rgba(10,10,10,0.96)_46%),linear-gradient(180deg,_#050607,_#0a0c10)] p-2.5">
        <DialerPanel />
    </div>
</template>
