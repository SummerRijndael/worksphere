<template>
    <div
        v-if="show"
        class="fixed bottom-24 right-6 w-72 bg-zinc-900/90 border border-indigo-500/50 rounded-xl shadow-2xl z-9999 overflow-hidden flex flex-col font-mono text-sm backdrop-blur-md"
    >
        <div
            class="bg-indigo-600/20 border-b border-indigo-500/30 px-3 py-2 flex items-center justify-between cursor-move select-none"
        >
            <div class="flex items-center gap-2">
                <Icon name="Bug" size="14" class="text-indigo-400" />
                <span class="font-bold text-indigo-300 tracking-tight text-xs uppercase">Call Dev Sim</span>
            </div>
            <button
                @click="$emit('update:show', false)"
                class="text-zinc-400 hover:text-white transition-colors"
            >
                <Icon name="X" size="16" />
            </button>
        </div>

        <div class="p-3 space-y-4 max-h-[60vh] overflow-y-auto custom-scrollbar">
            <!-- Stats -->
            <div class="space-y-1.5 p-2 bg-black/40 rounded-lg text-xs">
                <div class="flex justify-between">
                    <span class="text-zinc-400">Call State:</span>
                    <span class="text-sky-400 capitalize">{{ store.callState }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-400">Real Peers:</span>
                    <span class="text-emerald-400 font-bold">{{ store.currentCall?.participants.size || 0 }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-400">Mock Peers:</span>
                    <span class="text-amber-400 font-bold">{{ store.mockParticipants.size }}</span>
                </div>
            </div>

            <hr class="border-zinc-800" />

            <!-- Remote Participants Simulation -->
            <div class="space-y-2">
                <h4 class="text-xs font-bold text-zinc-400 uppercase tracking-widest mb-2">Simulate Peers</h4>
                <div class="flex gap-2">
                    <button
                        @click="store.addMockParticipant"
                        class="flex-1 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded text-xs transition-colors flex items-center justify-center gap-1"
                    >
                        <Icon name="UserPlus" size="12" /> Add
                    </button>
                    <button
                        v-if="store.mockParticipants.size > 0"
                        @click="store.removeMockParticipant"
                        class="flex-1 py-1.5 bg-red-900/50 hover:bg-red-800 text-red-100 border border-red-800/50 rounded text-xs transition-colors"
                    >
                        Remove
                    </button>
                </div>
            </div>

            <div class="text-[10px] text-zinc-500 text-center italic">
                Shortcut: Ctrl+Shift+D to toggle
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { useVideoCallStore } from "@/stores/videocall";
import { Icon } from "@/components/ui";

defineProps<{
    show: boolean;
}>();

defineEmits(['update:show']);

const store = useVideoCallStore();
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
}
</style>
