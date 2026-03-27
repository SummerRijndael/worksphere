<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import {
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    Phone,
    PhoneOff,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import { useDialerStore } from "@/stores/dialer";

const dialerStore = useDialerStore();
const props = withDefaults(
    defineProps<{
        inline?: boolean;
    }>(),
    {
        inline: false,
    },
);
const nowTick = ref(Date.now());
let tickTimer: ReturnType<typeof setInterval> | null = null;

const activeCall = computed(() => dialerStore.activeCall);
const hasActiveCall = computed(() => Boolean(activeCall.value));

const panelLabel = computed(() =>
    dialerStore.launchMode === "popup"
        ? "Open popup dialer"
        : dialerStore.isDockedOpen
          ? "Hide dialer"
          : "Show dialer",
);

const callName = computed(() => {
    if (!activeCall.value) {
        return "Dialer";
    }
    return activeCall.value.contact_name || activeCall.value.to_number;
});

const statusDotClass = computed(() => {
    const tone = activeCall.value?.status_tone;
    if (tone === "success") return "bg-emerald-400";
    if (tone === "warning") return "bg-amber-400";
    if (tone === "danger") return "bg-rose-400";
    if (tone === "info") return "bg-cyan-400";
    return "bg-slate-400";
});

const containerClass = computed(() => {
    if (props.inline) {
        return "max-w-[calc(100vw-1rem)]";
    }

    return "fixed right-3 top-[4.75rem] z-[90] max-w-[calc(100vw-1rem)] max-lg:hidden";
});

function durationLabel(): string {
    const call = activeCall.value;
    if (!call?.started_at) return "--:--";

    const startedAt = Date.parse(call.started_at);
    let seconds = call.duration_seconds ?? 0;
    if (call.can_hangup) {
        seconds = Math.max(0, Math.floor((nowTick.value - startedAt) / 1000));
    }

    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    const hrs = Math.floor(seconds / 3600);

    if (hrs > 0) {
        return `${String(hrs).padStart(2, "0")}:${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
    }

    return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

const subtitle = computed(() => {
    if (!activeCall.value) {
        return "Ready for calls";
    }
    return `${activeCall.value.status_label} · ${durationLabel()}`;
});

async function quickHangup(): Promise<void> {
    const ok = await dialerStore.hangupActiveCall();
    if (ok) {
        toast.success("Call dropped");
        return;
    }
    toast.error("Unable to drop call");
}

function toggleDock(): void {
    if (dialerStore.isDockedOpen) {
        dialerStore.closeDocked();
        return;
    }
    dialerStore.openDialer();
}

onMounted(() => {
    tickTimer = setInterval(() => {
        nowTick.value = Date.now();
    }, 1000);
});

onUnmounted(() => {
    if (tickTimer) {
        clearInterval(tickTimer);
    }
});
</script>

<template>
    <div :class="containerClass">
        <div
            class="flex items-center gap-2 rounded-xl border border-white/10 bg-black px-2 py-1.5 shadow-xl shadow-black/35"
        >
            <button
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-md border border-white/10 bg-white/5 text-slate-200 transition-colors hover:bg-white/10 hover:text-white"
                :title="panelLabel"
                @click="toggleDock"
            >
                <ExternalLink
                    v-if="dialerStore.launchMode === 'popup'"
                    class="h-4 w-4"
                />
                <ChevronRight v-if="dialerStore.isDockedOpen" class="h-4 w-4" />
                <ChevronLeft
                    v-else-if="dialerStore.launchMode === 'docked'"
                    class="h-4 w-4"
                />
            </button>

            <div class="min-w-0 w-[180px]">
                <p class="truncate text-[11px] font-semibold text-white">
                    {{ callName }}
                </p>
                <p class="mt-0.5 flex items-center gap-1 truncate text-[10px] text-slate-300">
                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass" />
                    {{ subtitle }}
                </p>
            </div>

            <button
                v-if="hasActiveCall && activeCall?.can_hangup"
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-md border border-rose-500/40 bg-rose-500/15 text-rose-200 transition-colors hover:bg-rose-500/25 hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="dialerStore.isHangingUp"
                title="Drop call"
                @click.stop="quickHangup"
            >
                <PhoneOff class="h-4 w-4" />
            </button>
            <div
                v-else
                class="flex h-8 w-8 items-center justify-center rounded-md border border-emerald-500/25 bg-emerald-500/10 text-emerald-300"
                title="Dialer ready"
            >
                <Phone class="h-4 w-4" />
            </div>
        </div>
    </div>
</template>
