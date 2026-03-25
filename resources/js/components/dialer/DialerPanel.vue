<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import {
    AlertTriangle,
    CheckCircle2,
    Clock3,
    Copy,
    Delete,
    Phone,
    PhoneOff,
    RefreshCw,
    Signal,
    XCircle,
} from "lucide-vue-next";
import api from "@/lib/api";
import Button from "@/components/ui/Button.vue";
import { toast } from "vue-sonner";
import { useDialerStore } from "@/stores/dialer";

type Tone = "secondary" | "info" | "warning" | "success" | "danger" | "muted";

interface DialerCall {
    id: string;
    to_number: string;
    from_number: string | null;
    status: string;
    status_label: string;
    status_tone: Tone;
    contact_name: string | null;
    requested_at: string | null;
    started_at: string | null;
    ended_at: string | null;
    duration_seconds: number | null;
    can_hangup: boolean;
}

interface DialerBootstrap {
    adapter: {
        key: string;
        label: string;
        configured: boolean;
        ready: boolean;
        mode: string;
        message: string;
        caller_id: string | null;
        capabilities: Record<string, boolean>;
    };
    acd_pipe: {
        prepared: boolean;
        connected: boolean;
        mode: string;
        message: string;
    };
    active_call: DialerCall | null;
    recent_calls: {
        data: DialerCall[];
        pagination: {
            current_page: number;
            per_page: number;
            total: number;
            last_page: number;
        };
    };
    composer: {
        input_hint: string;
        caller_id: string | null;
    };
}

const bootstrap = ref<DialerBootstrap | null>(null);
const dialerStore = useDialerStore();
const props = withDefaults(
    defineProps<{
        embedded?: boolean;
    }>(),
    {
        embedded: false,
    },
);

const panelCardClass = computed(() =>
    props.embedded
        ? "w-full rounded-xl border border-white/10 bg-black/45 p-2.5 shadow-xl shadow-emerald-900/20 backdrop-blur-xl"
        : "mx-auto w-full max-w-[380px] rounded-xl border border-white/10 bg-black/45 p-2.5 shadow-2xl shadow-emerald-900/20 backdrop-blur-xl",
);

const isLoading = ref(true);
const isCalling = ref(false);
const isHangingUp = ref(false);
const isTransferring = ref(false);
const nowTick = ref(Date.now());

const phoneNumber = ref("");
const contactName = ref("");
const notes = ref("");
const transferTarget = ref("");

const keypad = [
    { main: "1", sub: "" },
    { main: "2", sub: "ABC" },
    { main: "3", sub: "DEF" },
    { main: "4", sub: "GHI" },
    { main: "5", sub: "JKL" },
    { main: "6", sub: "MNO" },
    { main: "7", sub: "PQRS" },
    { main: "8", sub: "TUV" },
    { main: "9", sub: "WXYZ" },
    { main: "*", sub: "" },
    { main: "0", sub: "+" },
    { main: "#", sub: "" },
];

const activeCall = computed(() => bootstrap.value?.active_call ?? null);
const recentCalls = computed(() => bootstrap.value?.recent_calls?.data ?? []);
const adapter = computed(() => bootstrap.value?.adapter ?? null);
const acdPipe = computed(() => bootstrap.value?.acd_pipe ?? null);

const canDial = computed(() => {
    const value = normalizeDialInput(phoneNumber.value);
    return value.length >= 8 && !activeCall.value && !isCalling.value;
});

function normalizeDialInput(value: string): string {
    const trimmed = value.trim();
    const startsWithPlus = trimmed.startsWith("+");
    const digits = trimmed.replace(/[^\d]/g, "");
    return startsWithPlus ? `+${digits}` : digits;
}

function onPhoneInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    const cleaned = target.value.replace(/[^\d#+*]/g, "");
    phoneNumber.value = cleaned.slice(0, 20);
}

function addDigit(digit: string): void {
    if (phoneNumber.value.length < 20) {
        phoneNumber.value += digit;
    }
}

function removeDigit(): void {
    phoneNumber.value = phoneNumber.value.slice(0, -1);
}

function isTypingField(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName.toLowerCase();
    if (tag === "input" || tag === "textarea" || tag === "select") {
        return true;
    }

    return target.isContentEditable;
}

function resolveDialerKey(event: KeyboardEvent): string | null {
    if (/^\d$/.test(event.key)) {
        return event.key;
    }

    if (event.key === "+" || event.key === "#" || event.key === "*") {
        return event.key;
    }

    if (event.code === "NumpadAdd") return "+";
    if (event.code === "NumpadMultiply") return "*";

    return null;
}

function onGlobalDialerKeydown(event: KeyboardEvent): void {
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    if (isTypingField(event.target)) {
        return;
    }

    const mappedKey = resolveDialerKey(event);
    if (mappedKey) {
        event.preventDefault();
        addDigit(mappedKey);
        return;
    }

    if (event.key === "Backspace" || event.key === "Delete") {
        event.preventDefault();
        removeDigit();
        return;
    }

    if (event.key === "Enter" || event.code === "NumpadEnter") {
        event.preventDefault();
        if (activeCall.value?.can_hangup) {
            void hangup(activeCall.value);
            return;
        }
        if (canDial.value) {
            void placeCall();
        }
    }
}

function timeLabel(iso: string | null | undefined): string {
    if (!iso) return "n/a";
    return new Date(iso).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
}

function durationLabel(call: DialerCall): string {
    const startedAt = call.started_at ? Date.parse(call.started_at) : null;
    if (!startedAt) return "--:--";

    let seconds = call.duration_seconds ?? 0;
    if (call.can_hangup) {
        seconds = Math.max(0, Math.floor((nowTick.value - startedAt) / 1000));
    } else if (seconds === 0 && call.ended_at) {
        seconds = Math.max(0, Math.floor((Date.parse(call.ended_at) - startedAt) / 1000));
    }

    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hrs > 0) {
        return `${String(hrs).padStart(2, "0")}:${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
    }

    return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

function toneClass(tone: Tone): string {
    switch (tone) {
        case "success":
            return "bg-emerald-500/10 text-emerald-300 border-emerald-500/30";
        case "warning":
            return "bg-amber-500/10 text-amber-300 border-amber-500/30";
        case "danger":
            return "bg-rose-500/10 text-rose-300 border-rose-500/30";
        case "info":
            return "bg-cyan-500/10 text-cyan-300 border-cyan-500/30";
        case "muted":
            return "bg-zinc-500/10 text-zinc-300 border-zinc-500/20";
        default:
            return "bg-slate-500/10 text-slate-300 border-slate-500/20";
    }
}

async function fetchBootstrap(silent = false): Promise<void> {
    if (!silent) {
        isLoading.value = true;
    }

    try {
        const response = await api.get("/api/dialer/bootstrap");
        const payload = response.data?.data ?? null;
        bootstrap.value = payload;
        dialerStore.syncFromBootstrap(payload);
    } catch (error: any) {
        const message = error?.response?.data?.message ?? "Failed to load dialer data.";
        toast.error("Dialer unavailable", { description: message });
    } finally {
        if (!silent) {
            isLoading.value = false;
        }
    }
}

async function placeCall(): Promise<void> {
    if (!canDial.value) return;

    const toNumber = normalizeDialInput(phoneNumber.value);
    isCalling.value = true;

    try {
        await api.post("/api/dialer/calls", {
            to_number: toNumber,
            contact_name: contactName.value.trim() || null,
            notes: notes.value.trim() || null,
        });

        toast.success("Call started", { description: `Dialing ${toNumber}` });
        await fetchBootstrap(true);
    } catch (error: any) {
        const message = error?.response?.data?.message ?? "Unable to place call.";
        toast.error("Dial failed", { description: message });
    } finally {
        isCalling.value = false;
    }
}

async function hangup(call: DialerCall | null): Promise<void> {
    if (!call || !call.can_hangup || isHangingUp.value) return;

    isHangingUp.value = true;
    try {
        await api.post(`/api/dialer/calls/${call.id}/hangup`);
        toast.success("Call ended");
        await fetchBootstrap(true);
    } catch (error: any) {
        const message = error?.response?.data?.message ?? "Unable to end call.";
        toast.error("Hangup failed", { description: message });
    } finally {
        isHangingUp.value = false;
    }
}

async function transferCall(call: DialerCall | null): Promise<void> {
    if (!call || !call.can_hangup || isTransferring.value) return;

    const target = normalizeDialInput(transferTarget.value);
    if (target.length < 8) {
        toast.error("Transfer failed", { description: "Enter a valid transfer number." });
        return;
    }

    isTransferring.value = true;
    try {
        await api.post(`/api/dialer/calls/${call.id}/transfer`, {
            target_number: target,
            notes: notes.value.trim() || null,
        });
        toast.success("Call transferred", { description: `Transferred to ${target}` });
        transferTarget.value = "";
        await fetchBootstrap(true);
    } catch (error: any) {
        const message = error?.response?.data?.message ?? "Unable to transfer call.";
        toast.error("Transfer failed", { description: message });
    } finally {
        isTransferring.value = false;
    }
}

async function copyCallerId(): Promise<void> {
    const callerId = bootstrap.value?.composer?.caller_id;
    if (!callerId) return;

    try {
        await navigator.clipboard.writeText(callerId);
        toast.success("Caller ID copied");
    } catch {
        toast.error("Copy failed");
    }
}

let tickInterval: number | null = null;
let refreshInterval: number | null = null;

onMounted(async () => {
    await fetchBootstrap();
    window.addEventListener("keydown", onGlobalDialerKeydown);
    tickInterval = window.setInterval(() => {
        nowTick.value = Date.now();
    }, 1000);
    refreshInterval = window.setInterval(() => {
        fetchBootstrap(true);
    }, 8000);
});

onUnmounted(() => {
    window.removeEventListener("keydown", onGlobalDialerKeydown);
    if (tickInterval) window.clearInterval(tickInterval);
    if (refreshInterval) window.clearInterval(refreshInterval);
});
</script>

<template>
    <div :class="panelCardClass">
            <div class="mb-2 flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <div class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/20 text-emerald-300 ring-1 ring-emerald-500/30">
                            <Phone class="h-3.5 w-3.5" />
                        </div>
                        <p class="truncate text-[13px] font-semibold text-white">WorkSphere Dialer</p>
                    </div>
                    <p class="mt-0.5 truncate text-[11px] text-slate-300/80">
                        {{ adapter?.message || "Loading adapter status..." }}
                    </p>
                </div>
                <Button variant="ghost" size="icon-sm" :disabled="isLoading" @click="fetchBootstrap()">
                    <RefreshCw class="h-3.5 w-3.5 text-slate-300" />
                </Button>
            </div>

            <div class="mb-2 flex flex-wrap gap-1.5 text-[10px]">
                <span class="rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-slate-200">
                    {{ adapter?.label || "Adapter" }}
                </span>
                <span class="rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-slate-200">
                    ACD {{ acdPipe?.connected ? "Connected" : "Prepared" }}
                </span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-slate-200 hover:bg-white/10"
                    :disabled="!bootstrap?.composer?.caller_id"
                    @click="copyCallerId"
                >
                    <Copy class="h-2.5 w-2.5" />
                    <span>{{ bootstrap?.composer?.caller_id || "No Caller ID" }}</span>
                </button>
            </div>

            <div v-if="activeCall" class="mb-2 rounded-lg border border-emerald-500/30 bg-emerald-500/8 p-2.5">
                <div class="mb-1.5 flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-medium text-white">
                            {{ activeCall.contact_name || activeCall.to_number }}
                        </p>
                        <p class="text-[11px] text-emerald-200/85">{{ activeCall.to_number }}</p>
                    </div>
                    <span class="rounded-md border px-1.5 py-0.5 text-[10px] font-medium" :class="toneClass(activeCall.status_tone)">
                        {{ activeCall.status_label }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-200">
                    <span class="inline-flex items-center gap-1"><Clock3 class="h-3 w-3" /> {{ durationLabel(activeCall) }}</span>
                    <Button variant="danger" size="sm" :loading="isHangingUp" @click="hangup(activeCall)">
                        <PhoneOff class="h-3.5 w-3.5" />
                        <span>End</span>
                    </Button>
                </div>
                <div
                    v-if="adapter?.capabilities?.transfer"
                    class="mt-2 flex items-center gap-1.5 border-t border-emerald-500/20 pt-2"
                >
                    <input
                        v-model="transferTarget"
                        type="text"
                        inputmode="tel"
                        autocomplete="off"
                        placeholder="Transfer to +63..."
                        class="h-7 flex-1 rounded-md border border-white/15 bg-black/30 px-2 text-[11px] text-white placeholder:text-slate-500 focus:border-emerald-500/60 focus:outline-none"
                        @keydown.enter.prevent="transferCall(activeCall)"
                    />
                    <Button
                        variant="secondary"
                        size="xs"
                        :loading="isTransferring"
                        :disabled="!transferTarget"
                        @click="transferCall(activeCall)"
                    >
                        Transfer
                    </Button>
                </div>
            </div>

            <div class="rounded-lg border border-white/10 bg-white/[0.03] p-2.5">
                <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-300">To</label>
                <input
                    :value="phoneNumber"
                    type="text"
                    inputmode="tel"
                    autocomplete="off"
                    placeholder="+639171234567"
                    class="mb-1.5 h-9 w-full rounded-md border border-white/10 bg-black/35 px-2.5 text-[13px] text-white placeholder:text-slate-500 focus:border-emerald-500/50 focus:outline-none"
                    @input="onPhoneInput"
                />

                <div class="mb-1.5 grid grid-cols-2 gap-1.5">
                    <input
                        v-model="contactName"
                        type="text"
                        placeholder="Contact name (optional)"
                        class="h-8 rounded-md border border-white/10 bg-black/30 px-2.5 text-[11px] text-white placeholder:text-slate-500 focus:border-emerald-500/50 focus:outline-none"
                    />
                    <input
                        v-model="notes"
                        type="text"
                        placeholder="Call note (optional)"
                        class="h-8 rounded-md border border-white/10 bg-black/30 px-2.5 text-[11px] text-white placeholder:text-slate-500 focus:border-emerald-500/50 focus:outline-none"
                    />
                </div>

                <p class="text-[10px] text-slate-400">{{ bootstrap?.composer?.input_hint || "" }}</p>
                <p class="mb-2 text-[9px] text-slate-500">Keyboard: numpad digits/symbols, `Enter` to dial/hang up, `Backspace` to delete.</p>

                <div class="mb-2 grid grid-cols-3 gap-1.5">
                    <button
                        v-for="key in keypad"
                        :key="key.main"
                        type="button"
                        class="rounded-md border border-white/10 bg-black/35 py-1.5 text-center text-white transition hover:border-emerald-500/40 hover:bg-emerald-500/10"
                        @click="addDigit(key.main)"
                    >
                        <div class="text-[15px] font-semibold leading-none">{{ key.main }}</div>
                        <div v-if="key.sub" class="mt-0.5 text-[8px] tracking-[0.12em] text-slate-400">{{ key.sub }}</div>
                    </button>
                </div>

                <div class="flex items-center gap-1.5">
                    <Button variant="ghost" size="icon-sm" :disabled="!phoneNumber" @click="removeDigit">
                        <Delete class="h-4 w-4" />
                    </Button>
                    <Button variant="success" size="sm" :loading="isCalling" :disabled="!canDial" class="flex-1" @click="placeCall">
                        <Phone class="h-3.5 w-3.5" />
                        <span>Dial</span>
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        :disabled="!activeCall?.can_hangup"
                        :loading="isHangingUp"
                        class="flex-1"
                        @click="hangup(activeCall)"
                    >
                        <PhoneOff class="h-3.5 w-3.5" />
                        <span>Hang up</span>
                    </Button>
                </div>
            </div>

            <div class="mt-2 rounded-lg border border-white/10 bg-white/[0.03]">
                <div class="flex items-center justify-between border-b border-white/10 px-2.5 py-1.5">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-300">Recent Calls</p>
                    <span class="text-[10px] text-slate-400">{{ bootstrap?.recent_calls?.pagination?.total ?? 0 }}</span>
                </div>
                <div v-if="isLoading" class="px-2.5 py-4 text-center text-[11px] text-slate-400">
                    Loading calls...
                </div>
                <div v-else-if="recentCalls.length === 0" class="px-2.5 py-4 text-center text-[11px] text-slate-400">
                    No recent calls.
                </div>
                <div v-else class="max-h-40 space-y-1 overflow-y-auto p-1.5">
                    <div
                        v-for="call in recentCalls"
                        :key="call.id"
                        class="rounded-md border border-white/8 bg-black/30 px-2 py-1.5"
                    >
                        <div class="flex items-center justify-between gap-1.5">
                            <div class="min-w-0">
                                <p class="truncate text-[12px] text-white">{{ call.contact_name || call.to_number }}</p>
                                <p class="text-[10px] text-slate-400">{{ call.to_number }}</p>
                            </div>
                            <span class="shrink-0 rounded-md border px-1.5 py-0.5 text-[9px] font-medium" :class="toneClass(call.status_tone)">
                                {{ call.status_label }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center justify-between text-[10px] text-slate-400">
                            <span class="inline-flex items-center gap-1">
                                <Signal class="h-3 w-3" />
                                {{ durationLabel(call) }}
                            </span>
                            <span>{{ timeLabel(call.requested_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-2 flex items-center justify-between text-[10px] text-slate-400">
                <span class="inline-flex items-center gap-1">
                    <CheckCircle2 v-if="adapter?.ready" class="h-3 w-3 text-emerald-400" />
                    <AlertTriangle v-else class="h-3 w-3 text-amber-400" />
                    {{ adapter?.ready ? "Ready to dial" : "Configuration required" }}
                </span>
                <span class="inline-flex items-center gap-1">
                    <XCircle v-if="!acdPipe?.connected" class="h-3 w-3 text-slate-500" />
                    <CheckCircle2 v-else class="h-3 w-3 text-emerald-400" />
                    {{ acdPipe?.connected ? "ACD linked" : "ACD bridge prepared" }}
                </span>
            </div>
    </div>
</template>
