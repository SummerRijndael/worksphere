<script setup lang="ts">
import { ref, computed } from "vue";
import { Phone, PhoneOff, Delete, History } from "lucide-vue-next";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";
import { toast } from "vue-sonner";

const props = defineProps({
    open: Boolean,
});

const emit = defineEmits(["update:open", "close"]);

const phoneNumber = ref("");
const isCalling = ref(false);

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

import { 
    PhoneForwarded, 
    Pause, 
    FileText, 
    Tag, 
    MicOff, 
    Grid3X3, 
    CircleStop, 
    Share2, 
    Smartphone, 
    Mic, 
    Volume2 
} from "lucide-vue-next";

const activeTab = ref("actions");
// const isMuted = ref(false);
// const isSpeakerOn = ref(false);
const callDuration = ref("04:10");

// const formattedNumber = computed(() => {
//     if (!phoneNumber.value) return "";
//     return phoneNumber.value;
// });

function addDigit(digit: string) {
    if (phoneNumber.value.length < 15) {
        phoneNumber.value += digit;
    }
}

function removeDigit() {
    phoneNumber.value = phoneNumber.value.slice(0, -1);
}

function handleCall() {
    if (!phoneNumber.value && !isCalling.value) return;
    
    if (isCalling.value) {
        isCalling.value = false;
        phoneNumber.value = "";
    } else {
        isCalling.value = true;
        toast.info("Demo Mode Only", {
            description: "Twilio PBX integration coming soon! This dialer is for preview purposes.",
        });
    }
}

const handleOpenChange = (val: boolean) => {
    emit("update:open", val);
    if (!val) {
        phoneNumber.value = "";
        isCalling.value = false;
    }
}
</script>

<template>
    <Modal
        :open="open"
        @update:open="handleOpenChange"
        size="sm"
        class="dialer-modal"
    >
        <template #title>
            <div class="flex items-center gap-2">
                <div class="flex h-6 w-6 items-center justify-center rounded-lg bg-[var(--interactive-primary)] text-white">
                    <Phone class="h-3 w-3" />
                </div>
                <span class="text-xs font-bold uppercase tracking-widest text-[var(--text-muted)]">WorkSphere Phone</span>
            </div>
        </template>

        <div class="dialer-content flex flex-col pt-2">
            <!-- Active Call View (Zendesk Style) -->
            <template v-if="isCalling">
                <!-- Header Card -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[#77a9e0] to-[#5d94cf] p-4 text-white shadow-lg">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="rounded-full bg-white/20 px-3 py-1 text-[11px] font-medium backdrop-blur-md">
                            +1 213-394-0731
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded-full bg-white/30 backdrop-blur-md flex items-center justify-center text-lg font-bold">
                            NC
                        </div>
                        <div class="flex flex-col">
                            <h3 class="text-lg font-bold leading-tight">New contact</h3>
                            <div class="flex items-center gap-1.5 text-xs opacity-90">
                                <span>🇺🇸</span>
                                <span>+1 201-500-3169</span>
                            </div>
                        </div>
                        <div class="ml-auto text-sm font-medium tabular-nums opacity-90">
                            {{ callDuration }}
                        </div>
                    </div>

                    <!-- Inner Card / Tabs -->
                    <div class="mt-4 overflow-hidden rounded-xl bg-white/95 p-1 text-[#4a5568] shadow-sm">
                        <div class="flex gap-1 p-0.5">
                            <button 
                                @click="activeTab = 'actions'"
                                :class="[
                                    'flex-1 rounded-lg py-1.5 text-xs font-bold transition-all',
                                    activeTab === 'actions' ? 'bg-[#77a9e0]/20 text-[#2b6cb0]' : 'text-slate-400'
                                ]"
                            >
                                Actions
                            </button>
                            <button 
                                @click="activeTab = 'history'"
                                :class="[
                                    'flex-1 rounded-lg py-1.5 text-xs font-bold transition-all',
                                    activeTab === 'history' ? 'bg-[#77a9e0]/20 text-[#2b6cb0]' : 'text-slate-400'
                                ]"
                            >
                                History
                            </button>
                        </div>

                        <!-- Action Grid -->
                        <div v-if="activeTab === 'actions'" class="grid grid-cols-4 gap-4 p-4">
                            <div class="flex flex-col items-center gap-1.5">
                                <button class="h-10 w-10 rounded-full bg-white shadow-sm ring-1 ring-slate-100 flex items-center justify-center text-slate-700 hover:bg-slate-50">
                                    <PhoneForwarded class="h-4 w-4" />
                                </button>
                                <span class="text-[10px] font-medium text-slate-500">transfer</span>
                            </div>
                            <div class="flex flex-col items-center gap-1.5">
                                <button class="h-10 w-10 rounded-full bg-white shadow-sm ring-1 ring-slate-100 flex items-center justify-center text-slate-700 hover:bg-slate-50">
                                    <Pause class="h-4 w-4" />
                                </button>
                                <span class="text-[10px] font-medium text-slate-500">hold</span>
                            </div>
                            <div class="flex flex-col items-center gap-1.5">
                                <button class="h-10 w-10 rounded-full bg-white shadow-sm ring-1 ring-slate-100 flex items-center justify-center text-slate-700 hover:bg-slate-50">
                                    <FileText class="h-4 w-4" />
                                </button>
                                <span class="text-[10px] font-medium text-slate-500">note</span>
                            </div>
                            <div class="flex flex-col items-center gap-1.5">
                                <button class="h-10 w-10 rounded-full bg-white shadow-sm ring-1 ring-slate-100 flex items-center justify-center text-slate-700 hover:bg-slate-50">
                                    <Tag class="h-4 w-4" />
                                </button>
                                <span class="text-[10px] font-medium text-slate-500">tags</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Control Grid -->
                <div class="mt-4 rounded-xl bg-[var(--surface-secondary)]/30 p-4 grid grid-cols-3 gap-y-6">
                    <div class="flex flex-col items-center gap-1.5">
                        <button class="h-10 w-10 rounded-full bg-white/50 flex items-center justify-center text-[var(--text-secondary)] hover:bg-white active:scale-95 transition-all">
                            <MicOff class="h-4 w-4" />
                        </button>
                        <span class="text-[10px] font-medium text-[var(--text-muted)]">mute</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <button class="h-10 w-10 rounded-full bg-white/50 flex items-center justify-center text-[var(--text-secondary)] hover:bg-white active:scale-95 transition-all">
                            <Grid3X3 class="h-4 w-4" />
                        </button>
                        <span class="text-[10px] font-medium text-[var(--text-muted)]">keypad</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <button class="h-10 w-10 rounded-full bg-white/50 flex items-center justify-center text-rose-500 hover:bg-white active:scale-95 transition-all">
                            <CircleStop class="h-4 w-4 fill-rose-500/10" />
                        </button>
                        <span class="text-[10px] font-medium text-[var(--text-muted)]">rec.</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <button class="h-10 w-10 rounded-full bg-white/50 flex items-center justify-center text-[var(--text-secondary)] hover:bg-white active:scale-95 transition-all">
                            <Plus class="h-4 w-4" />
                        </button>
                        <span class="text-[10px] font-medium text-[var(--text-muted)]">call</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <button class="h-10 w-10 rounded-full bg-white/50 flex items-center justify-center text-[var(--text-secondary)] hover:bg-white active:scale-95 transition-all">
                            <Share2 class="h-4 w-4" />
                        </button>
                        <span class="text-[10px] font-medium text-[var(--text-muted)]">share</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <button class="h-10 w-10 rounded-full bg-white/50 flex items-center justify-center text-[var(--text-secondary)] hover:bg-white active:scale-95 transition-all">
                            <Smartphone class="h-4 w-4" />
                        </button>
                        <span class="text-[10px] font-medium text-[var(--text-muted)]">mobile</span>
                    </div>
                </div>

                <!-- Footer Controls -->
                <div class="mt-8 flex items-center justify-around pb-2">
                    <button class="h-10 w-10 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center text-[var(--text-secondary)] hover:text-emerald-500">
                        <Mic class="h-5 w-5" />
                    </button>
                    
                    <button 
                        @click="handleCall"
                        class="h-14 w-14 rounded-full bg-rose-500 flex items-center justify-center text-white shadow-lg shadow-rose-500/30 active:scale-95 transition-all hover:bg-rose-600"
                    >
                        <PhoneOff class="h-6 w-6" />
                    </button>

                    <button class="h-10 w-10 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center text-[var(--text-secondary)] hover:text-blue-500">
                        <Volume2 class="h-5 w-5" />
                    </button>
                </div>
            </template>

            <!-- Keypad View (Input State) -->
            <template v-else>
                <!-- Display -->
                <div class="mb-6 flex h-14 w-full flex-col items-center justify-center rounded-xl bg-[var(--surface-secondary)]/50 px-4 ring-1 ring-[var(--border-muted)]">
                    <div 
                        class="w-full overflow-hidden text-center text-2xl font-light tracking-tight text-[var(--text-primary)] transition-all duration-200"
                        :class="{ 'opacity-30': !phoneNumber }"
                    >
                        {{ phoneNumber || '000-000-0000' }}
                    </div>
                </div>

                <!-- Keypad -->
                <div class="grid w-full grid-cols-3 gap-3">
                    <button
                        v-for="key in keypad"
                        :key="key.main"
                        @click="addDigit(key.main)"
                        class="group flex h-14 flex-col items-center justify-center rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-primary)] hover:border-blue-500/50 hover:bg-blue-500/[0.03] active:scale-95 transition-all"
                    >
                        <span class="text-xl font-semibold text-[var(--text-primary)] group-hover:text-blue-600 dark:group-hover:text-blue-400">
                            {{ key.main }}
                        </span>
                        <span v-if="key.sub" class="text-[9px] font-bold tracking-tighter text-[var(--text-muted)] group-hover:text-blue-500/70 uppercase">
                            {{ key.sub }}
                        </span>
                    </button>
                </div>

                <div class="mt-8 flex w-full items-center justify-between gap-4">
                    <Button variant="ghost" size="icon" class="h-12 w-12 rounded-full text-[var(--text-muted)]">
                        <History class="h-5 w-5" />
                    </Button>

                    <button
                        @click="handleCall"
                        :disabled="!phoneNumber"
                        class="flex h-12 flex-1 items-center justify-center gap-2 rounded-full bg-emerald-500 font-bold text-white shadow-lg shadow-emerald-500/20 hover:bg-emerald-600 active:scale-95 transition-all disabled:opacity-30 disabled:grayscale"
                    >
                        <Phone class="h-5 w-5 fill-current" />
                        <span class="text-sm uppercase tracking-wider">Call</span>
                    </button>

                    <Button
                        variant="ghost"
                        size="icon"
                        @click="removeDigit"
                        class="h-12 w-12 rounded-full text-[var(--text-muted)]"
                        :disabled="!phoneNumber"
                    >
                        <Delete class="h-5 w-5" />
                    </Button>
                </div>
            </template>
        </div>
    </Modal>
</template>

<style scoped>
.dialer-modal :deep([data-reka-dialog-content]) {
    max-width: 320px;
    padding: 1.5rem;
    border-radius: 2rem;
}

.dialer-content {
    min-height: 480px;
}
</style>
