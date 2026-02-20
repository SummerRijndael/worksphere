<script setup lang="ts">
import { ref } from "vue";
import { 
    Phone, 
    PhoneOff, 
    Delete, 
    History, 
    
    
    Plus,
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
import Button from "@/components/ui/Button.vue";
import { toast } from "vue-sonner";

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

const activeTab = ref("actions");
const callDuration = ref("04:10");

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
            description: "Twilio PBX integration coming soon!",
        });
    }
}
</script>

<template>
    <div class="dialer-standalone min-h-screen bg-[var(--surface-primary)] p-6 selection:bg-emerald-500/30">
        <!-- Window Title Bar Decor -->
        <div class="mb-4 flex items-center gap-2">
            <div class="flex h-6 w-6 items-center justify-center rounded-lg bg-[var(--interactive-primary)] text-white shadow-sm">
                <Phone class="h-3 w-3" />
            </div>
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-[var(--text-muted)] opacity-70">WorkSphere Phone</span>
            <div class="ml-auto flex gap-1.5">
                <div class="h-2 w-2 rounded-full bg-slate-200 dark:bg-slate-800"></div>
                <div class="h-2 w-2 rounded-full bg-slate-200 dark:bg-slate-800"></div>
            </div>
        </div>

        <div class="dialer-content flex flex-col">
            <!-- Active Call View (Zendesk Style) -->
            <template v-if="isCalling">
                <!-- Header Card -->
                <div class="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-[#77a9e0] to-[#5d94cf] p-5 text-white shadow-2xl shadow-blue-500/20">
                    <div class="mb-4 flex items-center justify-between">
                        <span class="rounded-full bg-white/20 px-3 py-1 text-[11px] font-bold backdrop-blur-md ring-1 ring-white/20">
                            +1 213-394-0731
                        </span>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-full bg-white/30 backdrop-blur-md flex items-center justify-center text-xl font-black ring-2 ring-white/20 shadow-inner">
                            NC
                        </div>
                        <div class="flex flex-col">
                            <h3 class="text-xl font-black tracking-tight leading-tight">New contact</h3>
                            <div class="flex items-center gap-1.5 text-xs font-bold opacity-80">
                                <span>🇺🇸</span>
                                <span>+1 201-500-3169</span>
                            </div>
                        </div>
                        <div class="ml-auto text-sm font-black tabular-nums opacity-90">
                            {{ callDuration }}
                        </div>
                    </div>

                    <!-- Inner Card / Tabs -->
                    <div class="mt-5 overflow-hidden rounded-2xl bg-white/95 p-1 text-[#4a5568] shadow-inner ring-1 ring-white/50">
                        <div class="flex gap-1 p-1">
                            <button 
                                @click="activeTab = 'actions'"
                                :class="[
                                    'flex-1 rounded-xl py-2 text-xs font-black transition-all',
                                    activeTab === 'actions' ? 'bg-blue-500/10 text-blue-600' : 'text-slate-400 hover:text-slate-600'
                                ]"
                            >
                                Actions
                            </button>
                            <button 
                                @click="activeTab = 'history'"
                                :class="[
                                    'flex-1 rounded-xl py-2 text-xs font-black transition-all',
                                    activeTab === 'history' ? 'bg-blue-500/10 text-blue-600' : 'text-slate-400 hover:text-slate-600'
                                ]"
                            >
                                History
                            </button>
                        </div>

                        <!-- Action Grid -->
                        <div v-if="activeTab === 'actions'" class="grid grid-cols-4 gap-4 p-4 grayscale-[0.2]">
                            <div v-for="action in [
                                { icon: PhoneForwarded, label: 'transfer' },
                                { icon: Pause, label: 'hold' },
                                { icon: FileText, label: 'note' },
                                { icon: Tag, label: 'tags' }
                            ]" :key="action.label" class="flex flex-col items-center gap-2">
                                <button class="h-10 w-10 rounded-full bg-white shadow-sm ring-1 ring-slate-100 flex items-center justify-center text-slate-600 hover:bg-slate-50 active:scale-90 transition-all">
                                    <component :is="action.icon" class="h-4 w-4" />
                                </button>
                                <span class="text-[9px] font-black uppercase tracking-wider text-slate-400 opacity-80">{{ action.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Control Grid -->
                <div class="mt-6 rounded-3xl bg-[var(--surface-secondary)]/30 p-5 ring-1 ring-[var(--border-muted)] grid grid-cols-3 gap-y-8 shadow-inner">
                    <div v-for="ctrl in [
                        { icon: MicOff, label: 'mute' },
                        { icon: Grid3X3, label: 'keypad' },
                        { icon: CircleStop, label: 'rec.', color: 'text-rose-500' },
                        { icon: Plus, label: 'call' },
                        { icon: Share2, label: 'share' },
                        { icon: Smartphone, label: 'mobile' }
                    ]" :key="ctrl.label" class="flex flex-col items-center gap-2">
                        <button 
                            :class="[
                                'h-11 w-11 rounded-full bg-[var(--surface-primary)] shadow-sm ring-1 ring-[var(--border-subtle)] flex items-center justify-center hover:bg-white active:scale-95 transition-all',
                                ctrl.color || 'text-[var(--text-secondary)]'
                            ]"
                        >
                            <component :is="ctrl.icon" class="h-4 w-4" />
                        </button>
                        <span class="text-[10px] font-black uppercase tracking-widest text-[var(--text-muted)] opacity-60">{{ ctrl.label }}</span>
                    </div>
                </div>

                <!-- Main Call Actions -->
                <div class="mt-auto pt-10 flex items-center justify-around pb-6">
                    <button class="h-12 w-12 rounded-full bg-[var(--surface-secondary)] ring-1 ring-[var(--border-muted)] flex items-center justify-center text-[var(--text-secondary)] hover:text-emerald-500 transition-colors">
                        <Mic class="h-5 w-5" />
                    </button>
                    
                    <button 
                        @click="handleCall"
                        class="h-16 w-16 rounded-full bg-rose-500 flex items-center justify-center text-white shadow-2xl shadow-rose-500/40 active:scale-90 transition-all hover:bg-rose-600 ring-4 ring-rose-500/10"
                    >
                        <PhoneOff class="h-7 w-7" />
                    </button>

                    <button class="h-12 w-12 rounded-full bg-[var(--surface-secondary)] ring-1 ring-[var(--border-muted)] flex items-center justify-center text-[var(--text-secondary)] hover:text-blue-500 transition-colors">
                        <Volume2 class="h-5 w-5" />
                    </button>
                </div>
            </template>

            <!-- Keypad View (Input State) -->
            <template v-else>
                <!-- Display -->
                <div class="mb-8 flex h-20 w-full flex-col items-center justify-center rounded-[2rem] bg-[var(--surface-secondary)]/50 px-6 ring-2 ring-[var(--border-muted)] shadow-inner">
                    <div 
                        class="w-full overflow-hidden text-center text-3xl font-black tracking-tight text-[var(--text-primary)] transition-all duration-300"
                        :class="{ 'opacity-20 scale-95': !phoneNumber }"
                    >
                        {{ phoneNumber || '000-000-0000' }}
                    </div>
                </div>

                <!-- Keypad -->
                <div class="grid w-full grid-cols-3 gap-4">
                    <button
                        v-for="key in keypad"
                        :key="key.main"
                        @click="addDigit(key.main)"
                        class="group flex h-16 flex-col items-center justify-center rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-primary)] hover:border-emerald-500/40 hover:bg-emerald-500/[0.02] active:scale-95 transition-all shadow-sm"
                    >
                        <span class="text-2xl font-black text-[var(--text-primary)] group-hover:text-emerald-600 dark:group-hover:text-emerald-400">
                            {{ key.main }}
                        </span>
                        <span v-if="key.sub" class="text-[10px] font-black tracking-tight text-[var(--text-muted)] group-hover:text-emerald-500/70 uppercase">
                            {{ key.sub }}
                        </span>
                    </button>
                </div>

                <div class="mt-12 flex w-full items-center justify-between gap-6 px-2">
                    <Button variant="ghost" size="icon" class="h-14 w-14 rounded-full text-[var(--text-muted)] hover:bg-[var(--surface-secondary)]">
                        <History class="h-6 w-6" />
                    </Button>

                    <button
                        @click="handleCall"
                        :disabled="!phoneNumber"
                        class="flex h-16 flex-1 items-center justify-center gap-3 rounded-full bg-emerald-500 font-black text-white shadow-xl shadow-emerald-500/30 hover:bg-emerald-600 active:scale-[0.98] transition-all disabled:opacity-20 disabled:grayscale ring-4 ring-emerald-500/10"
                    >
                        <Phone class="h-6 w-6 fill-current" />
                        <span class="text-sm uppercase tracking-[0.2em]">Dial</span>
                    </button>

                    <Button
                        variant="ghost"
                        size="icon"
                        @click="removeDigit"
                        class="h-14 w-14 rounded-full text-[var(--text-muted)] hover:text-rose-500 hover:bg-rose-500/5"
                        :disabled="!phoneNumber"
                    >
                        <Delete class="h-6 w-6" />
                    </Button>
                </div>
                
                <div class="mt-auto pt-10 text-center opacity-40">
                   <p class="text-[9px] font-black uppercase tracking-widest text-[var(--text-muted)]">Encrypted SIP Gateway v2.4</p>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.dialer-standalone {
    max-width: 320px;
    margin: 0 auto;
}
</style>
