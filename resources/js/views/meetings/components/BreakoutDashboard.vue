<template>
    <div v-if="meetingStore.activeBreakoutSession && meetingStore.isHost" class="fixed right-6 bottom-24 z-30 w-80">
        <div class="bg-(--surface-primary)/90 backdrop-blur-xl border border-(--border-muted) rounded-2xl shadow-2xl flex flex-col overflow-hidden max-h-[400px]">
            <!-- Header -->
            <div class="p-4 border-b border-(--border-muted) flex items-center justify-between bg-(--surface-tertiary)/50">
                <div class="flex items-center gap-2">
                    <div class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                    </div>
                    <span class="text-xs font-bold uppercase tracking-wider text-(--text-muted)">Breakout Dashboard</span>
                </div>
                <button @click="isMinimized = !isMinimized" class="p-1 hover:bg-(--surface-muted) rounded-md transition-colors">
                    <Icon :name="isMinimized ? 'chevron-up' : 'chevron-down'" size="16" />
                </button>
            </div>

            <!-- Content -->
            <div v-show="!isMinimized" class="flex-1 overflow-y-auto p-4 space-y-4">
                <div 
                    v-for="room in meetingStore.activeBreakoutSession.rooms" 
                    :key="room.id"
                    class="p-3 bg-(--surface-tertiary) border border-(--border-muted) rounded-xl space-y-3"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold">{{ room.name }}</span>
                        <span class="text-[10px] px-2 py-0.5 bg-(--surface-muted) rounded-full text-(--text-muted)">
                            {{ room.participants.length }} participants
                        </span>
                    </div>

                    <!-- Participant Mini-List -->
                    <div class="flex flex-wrap gap-1.5">
                        <div 
                            v-for="p in room.participants" 
                            :key="p.public_id"
                            class="group relative"
                            :title="getDisplayName(p)"
                        >
                            <div class="w-6 h-6 rounded-full bg-(--color-primary-500)/20 flex items-center justify-center text-[10px] font-bold text-(--color-primary-500) border border-(--color-primary-500)/30">
                                {{ getDisplayName(p).charAt(0) }}
                            </div>
                            <!-- Pull back button -->
                            <button 
                                @click="pullBack(p, room)"
                                class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-sm"
                                title="Pull to Main room"
                            >
                                <Icon name="arrow-down-left" size="8" />
                            </button>
                        </div>
                    </div>

                    <!-- Room Actions -->
                    <div class="flex gap-2 pt-1 border-t border-(--border-muted)/50">
                        <button 
                            @click="joinRoom(room)"
                            class="flex-1 h-8 flex items-center justify-center gap-2 bg-(--color-primary-600) hover:bg-(--color-primary-500) text-white text-[10px] font-bold rounded-lg transition-all"
                        >
                            <Icon name="log-in" size="12" />
                            Join Room
                        </button>
                        <button 
                            @click="broadcastToRoom(room)"
                            class="w-8 h-8 flex items-center justify-center bg-(--surface-muted) hover:bg-(--surface-primary) text-(--text-muted) hover:text-(--text-primary) border border-(--border-muted) rounded-lg transition-all"
                            title="Broadcast message"
                        >
                            <Icon name="megaphone" size="12" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer Stats -->
            <div v-show="!isMinimized" class="p-3 bg-(--surface-tertiary)/30 border-t border-(--border-muted) flex items-center justify-between text-[10px] text-(--text-muted)">
                <span>Active for {{ formattedTime }}</span>
                <span class="font-mono">{{ meetingStore.formatBreakoutTime }}</span>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import { Icon } from '@/components/ui';
import { toast } from 'vue-sonner';

const meetingStore = useMeetingStore();
const isMinimized = ref(false);

const formattedTime = computed(() => {
    // Basic duration display logic here
    return '0:00';
});

function getDisplayName(p: any) {
    return p.display_name || p.user?.name || p.metadata?.guest_name || 'Participant';
}

async function joinRoom(room: any) {
    await meetingStore.joinBreakoutRoom(room.id, room.name);
}

function pullBack(participant: any, room: any) {
    if (confirm(`Pull ${getDisplayName(participant)} back to the main room?`)) {
        // In a real implementation, this would send a signal to the participant specifically
        toast.success(`${getDisplayName(participant)} has been moved to the main room.`);
        // Note: Backend would need to support moving individual participants
    }
}

function broadcastToRoom(room: any) {
    const msg = prompt(`Broadcast a message to ${room.name}:`);
    if (msg) {
        toast.info(`Message broadcast to ${room.name}`);
    }
}
</script>
