<template>
    <div v-if="meetingStore.isInBreakout" class="fixed top-4 left-1/2 -translate-x-1/2 z-40 pointer-events-none">
        <div class="bg-(--surface-primary)/90 backdrop-blur-xl border border-(--border-muted) rounded-2xl shadow-2xl p-1.5 flex items-center gap-4 pointer-events-auto min-w-[300px]">
            <!-- Status Info -->
            <div class="flex items-center gap-3 px-3 py-1.5 bg-(--color-primary-600)/10 rounded-xl border border-(--color-primary-500)/20">
                <div class="flex flex-col">
                    <span class="text-[10px] text-(--text-muted) uppercase tracking-tighter font-bold">Breakout Room</span>
                    <span class="text-sm font-bold text-(--text-primary)">{{ currentRoomName }}</span>
                </div>
            </div>

            <!-- Timer -->
            <div class="flex items-center gap-3 px-4 flex-1 justify-center">
                <Icon name="clock" size="16" class="text-(--color-primary-500)" />
                <span class="text-xl font-mono font-bold tracking-tight" :class="breakoutTimer < 60 ? 'text-red-400 animate-pulse' : 'text-(--text-primary)'">
                    {{ formatTime(breakoutTimer) }}
                </span>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-2 pr-1.5">
                <button 
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
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import { Icon } from '@/components/ui';
import { toast } from 'vue-sonner';

const meetingStore = useMeetingStore();

const breakoutTimer = computed(() => meetingStore.breakoutTimer);

const currentRoomName = computed(() => {
    const session = meetingStore.activeBreakoutSession;
    if (!session) return 'Unknown Room';
    const myRoom = session.rooms?.find((r: any) => 
        r.participants.some((p: any) => p.public_id === meetingStore.localParticipant?.public_id)
    );
    return myRoom?.name || 'Main Room';
});

function formatTime(seconds: number) {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

async function requestHelp() {
    await meetingStore.requestHostHelp();
}

async function endSession() {
    if (confirm('Are you sure you want to end the breakout session for everyone?')) {
        await meetingStore.endBreakout();
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
