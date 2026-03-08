<template>
    <div v-if="meetingStore.activeBreakoutSession" class="fixed top-4 left-1/2 -translate-x-1/2 z-100 pointer-events-none">
        <!-- Assigned to a room -->
        <div v-if="meetingStore.isInBreakout" class="bg-(--surface-primary)/90 backdrop-blur-xl border border-(--border-muted) rounded-2xl shadow-2xl p-1.5 flex items-center gap-4 pointer-events-auto min-w-[300px]">
            <!-- Status Info -->
            <div class="flex items-center gap-3 px-3 py-1.5 bg-(--color-primary-600)/10 rounded-xl border border-(--color-primary-500)/20">
                <div class="flex flex-col">
                    <span class="text-[10px] text-(--text-muted) uppercase tracking-tighter font-bold">Breakout Room</span>
                    <span class="text-sm font-bold text-(--text-primary)">{{ currentRoomName }}</span>
                </div>
            </div>

            <!-- Timer -->
            <div v-if="breakoutTimer > 0" class="flex items-center gap-3 px-4 flex-1 justify-center border-l border-(--border-muted)">
                <Icon name="clock" size="16" class="text-(--color-primary-500)" />
                <span class="text-xl font-mono font-bold tracking-tight" :class="breakoutTimer < 60 ? 'text-red-400 animate-pulse' : 'text-(--text-primary)'">
                    {{ formatTime(breakoutTimer) }}
                </span>
            </div>
            <div v-else class="flex-1"></div>

            <!-- Actions -->
            <div class="flex items-center gap-2 pr-1.5">
                <button 
                    v-if="!meetingStore.isModerator"
                    @click="requestHelp"
                    class="h-10 px-4 bg-(--surface-tertiary) hover:bg-(--surface-muted) text-(--text-primary) rounded-xl text-xs font-bold transition-all flex items-center gap-2 border border-(--border-muted)"
                >
                    <Icon name="help-circle" size="14" />
                    Ask for Help
                </button>
                <button 
                    v-if="meetingStore.isHost"
                    @click="endSession"
                    class="h-10 px-4 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white rounded-xl text-xs font-bold transition-all border border-red-500/20"
                >
                    End for All
                </button>
            </div>
        </div>

        <!-- Waiting for assignment (Not assigned to any room) -->
        <div v-else class="bg-(--surface-primary)/90 backdrop-blur-xl border border-(--border-muted) rounded-2xl shadow-2xl px-6 py-3 flex items-center gap-4 pointer-events-auto animate-in fade-in slide-in-from-top-4 duration-500">
            <div class="w-10 h-10 rounded-full bg-(--color-primary-500)/10 flex items-center justify-center text-(--color-primary-500)">
                <Icon name="users" size="20" />
            </div>
            <div class="flex flex-col">
                <span class="text-sm font-bold text-(--text-primary)">
                    {{ meetingStore.isHost ? 'Managing Breakout Session' : 'Breakout Session in Progress' }}
                </span>
                <span class="text-[11px] text-(--text-muted)">
                    {{ meetingStore.isHost ? 'You are currently in the main room.' : 'Please wait while the host assigns you to a room.' }}
                </span>
            </div>
            <div v-if="meetingStore.isHost" class="ml-4 pl-4 border-l border-(--border-muted)">
                <button 
                    @click="endSession"
                    class="h-10 px-4 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white rounded-xl text-xs font-bold transition-all border border-red-500/20"
                >
                    End Session
                </button>
            </div>
        </div>

        <ConfirmEndBreakoutModal 
            v-model:open="showEndModal"
            @confirm="handleEndConfirm"
        />
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import { Icon } from '@/components/ui';
import { toast } from 'vue-sonner';
import ConfirmEndBreakoutModal from './ConfirmEndBreakoutModal.vue';
import { ref } from 'vue';

const meetingStore = useMeetingStore();

const breakoutTimer = computed(() => meetingStore.breakoutTimer);

const currentRoomName = computed(() => {
    return meetingStore.currentRoomName || 'Main Room';
});

function formatTime(seconds: number) {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

async function requestHelp() {
    await meetingStore.requestHostHelp();
}

const showEndModal = ref(false);

async function endSession() {
    showEndModal.value = true;
}

async function handleEndConfirm() {
    try {
        await meetingStore.endBreakout();
        toast.success('Breakout session ended');
    } catch (e) {
        toast.error('Failed to end breakout session');
    }
}
</script>

<style scoped>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}
.animate-pulse {
    animation: pulse 1s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>
