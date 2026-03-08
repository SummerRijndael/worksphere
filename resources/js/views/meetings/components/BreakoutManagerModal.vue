<template>
    <div class="fixed inset-0 z-100 flex items-center justify-center p-4 bg-black/60 backdrop-blur-md">
        <div class="bg-(--surface-primary) border border-(--border-muted) w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <!-- Header -->
            <div class="flex justify-between items-center p-6 border-b border-(--border-muted)">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2">
                        <Icon name="layout-grid" size="20" class="text-(--color-primary-500)" />
                        Breakout Rooms
                    </h2>
                    <p class="text-sm text-(--text-muted)">Split the meeting into smaller groups</p>
                </div>
                <button @click="$emit('close')" class="text-(--text-muted) hover:text-(--text-primary) p-2 rounded-full hover:bg-(--surface-tertiary)">
                    <Icon name="x" size="20" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-6 space-y-8">
                <!-- Configuration -->
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div class="flex items-center h-6">
                            <label class="text-sm font-semibold flex items-center gap-2">
                                <Icon name="columns" size="14" />
                                Number of Rooms
                            </label>
                        </div>
                        <select 
                            v-model="roomCount" 
                            class="w-full h-[50px] bg-(--surface-tertiary) border border-(--border-muted) rounded-xl px-3 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50"
                        >
                            <option v-for="n in 9" :key="n+1" :value="n+1">{{ n+1 }} Rooms</option>
                        </select>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between h-6">
                            <label class="text-sm font-semibold flex items-center gap-2">
                                <Icon name="clock" size="14" />
                                Session Timer
                            </label>
                            <div 
                                @click="hasTimer = !hasTimer"
                                class="w-10 h-6 rounded-full p-1 cursor-pointer transition-colors relative"
                                :class="hasTimer ? 'bg-(--color-primary-600)' : 'bg-(--border-muted)'"
                            >
                                <div 
                                    class="w-4 h-4 bg-white rounded-full transition-transform"
                                    :class="hasTimer ? 'translate-x-4' : 'translate-x-0'"
                                ></div>
                            </div>
                        </div>
                        
                        <div class="h-[50px] flex items-center">
                            <div v-show="hasTimer" class="flex items-center gap-3 w-full animate-in fade-in slide-in-from-top-1 duration-200">
                                <input 
                                    v-model.number="duration" 
                                    type="number" 
                                    min="1" 
                                    max="60"
                                    class="w-full bg-(--surface-tertiary) border border-(--border-muted) rounded-xl p-3 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50"
                                />
                                <span class="text-sm text-(--text-muted)">min</span>
                            </div>
                            <div v-show="!hasTimer" class="w-full p-3 bg-(--surface-tertiary) border border-dashed border-(--border-muted) rounded-xl text-xs text-(--text-muted) text-center italic animate-in fade-in duration-200">
                                Rooms will stay open indefinitely
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assignment Strategy -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-semibold">Assign Participants</label>
                        <div class="flex bg-(--surface-tertiary) p-1 rounded-xl border border-(--border-muted)">
                            <button 
                                @click="assignmentMode = 'auto'"
                                class="px-4 py-1.5 rounded-lg text-sm transition-all"
                                :class="assignmentMode === 'auto' ? 'bg-(--color-primary-600) text-white shadow-lg' : 'text-(--text-muted) hover:text-(--text-primary)'"
                            >
                                Automatically
                            </button>
                            <button 
                                @click="assignmentMode = 'manual'"
                                class="px-4 py-1.5 rounded-lg text-sm transition-all"
                                :class="assignmentMode === 'manual' ? 'bg-(--color-primary-600) text-white shadow-lg' : 'text-(--text-muted) hover:text-(--text-primary)'"
                            >
                                Manually
                            </button>
                        </div>
                    </div>

                    <!-- Room List / Drag Area -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div 
                            v-for="(room, index) in rooms" 
                            :key="index"
                            class="bg-(--surface-tertiary) border border-(--border-muted) rounded-2xl p-4 space-y-3"
                        >
                            <div class="flex items-center justify-between">
                                <input 
                                    v-model="room.name"
                                    class="bg-transparent font-semibold text-sm outline-none border-b border-transparent focus:border-(--color-primary-500) w-32"
                                />
                                <span class="text-xs text-(--text-muted)">{{ room.participants.length }} participants</span>
                            </div>

                            <div v-if="assignmentMode === 'manual'" class="space-y-2 min-h-[60px] p-2 rounded-xl bg-(--surface-primary)/50 border border-dashed border-(--border-muted)">
                                <div 
                                    v-for="p in room.participants" 
                                    :key="p.public_id"
                                    class="flex items-center justify-between gap-2 p-2 bg-(--surface-primary) border border-(--border-muted) rounded-xl shadow-sm group"
                                >
                                    <div class="flex items-center gap-2 overflow-hidden flex-1">
                                        <Icon name="grip-vertical" size="14" class="text-(--text-muted) cursor-grab active:cursor-grabbing opacity-50 group-hover:opacity-100" />
                                        <div class="w-6 h-6 rounded-full bg-(--color-primary-500)/20 flex items-center justify-center text-[10px] font-bold text-(--color-primary-500)">
                                            {{ getDisplayName(p).charAt(0) }}
                                        </div>
                                        <span class="text-sm truncate font-medium text-(--text-primary)">{{ getDisplayName(p) }}</span>
                                    </div>
                                    <button @click="unassign(p)" class="opacity-0 group-hover:opacity-100 p-1.5 hover:text-red-400 hover:bg-red-400/10 rounded-lg transition-all">
                                        <Icon name="x" size="14" />
                                    </button>
                                </div>
                                <div v-if="room.participants.length === 0" class="h-full flex items-center justify-center text-[10px] text-(--text-muted) uppercase tracking-wider">
                                    Drop here
                                </div>
                            </div>
                            <div v-else class="text-[11px] text-(--text-muted) italic">
                                Participants will be distributed evenly.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unassigned Participants (Manual Mode) -->
                <div v-if="assignmentMode === 'manual' && unassignedParticipants.length > 0" class="space-y-3">
                    <label class="text-sm font-semibold">Unassigned ({{ unassignedParticipants.length }})</label>
                    <div class="flex flex-wrap gap-2">
                        <button 
                            v-for="p in unassignedParticipants" 
                            :key="p.public_id"
                            @click="assignToNextRoom(p)"
                            class="flex items-center gap-2 px-4 py-2 bg-(--surface-tertiary) border border-(--border-muted) rounded-xl hover:border-(--color-primary-500) hover:bg-(--surface-primary) transition-all group"
                        >
                            <div class="w-5 h-5 rounded-full bg-(--color-primary-500)/20 flex items-center justify-center text-[10px] font-bold text-(--color-primary-500)">
                                {{ getDisplayName(p).charAt(0) }}
                            </div>
                            <span class="text-sm font-medium text-(--text-primary)">{{ getDisplayName(p) }}</span>
                            <Icon name="plus" size="14" class="text-(--text-muted) group-hover:text-(--color-primary-500)" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="p-6 border-t border-(--border-muted) flex items-center justify-between bg-(--surface-tertiary)/30">
                <p class="text-xs text-(--text-muted)">
                    <span v-if="assignmentMode === 'auto'">Participants will be mixed.</span>
                    <span v-else>{{ unassignedParticipants.length }} participants still unassigned.</span>
                </p>
                <div class="flex gap-3">
                    <button @click="$emit('close')" class="px-6 py-2.5 rounded-xl font-medium hover:bg-(--border-muted) transition-all">
                        Cancel
                    </button>
                    <button 
                        @click="start"
                        :disabled="loading || (assignmentMode === 'manual' && unassignedParticipants.length > 0)"
                        class="px-8 py-2.5 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-xl font-bold shadow-lg shadow-primary-500/20 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ loading ? 'Starting...' : 'Create Rooms' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, reactive } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import { Icon } from '@/components/ui';
import { toast } from 'vue-sonner';

const emit = defineEmits(['close']);
const meetingStore = useMeetingStore();

const loading = ref(false);
const roomCount = ref(2);
const duration = ref(10);
const hasTimer = ref(true);
const assignmentMode = ref<'auto' | 'manual'>('auto');

interface Room {
    id: number;
    name: string;
    participants: any[];
}

const rooms = ref<Room[]>([]);

const participantsToAssign = computed(() => {
    // Show all admitted participants except the local user (the host managing the modal)
    // This ensures we see everyone who can be moved to a room.
    const localId = meetingStore.localParticipant?.public_id;
    return meetingStore.allParticipants.filter(p => p.public_id !== localId);
});

const unassignedParticipants = computed(() => {
    const assignedIds = new Set(rooms.value.flatMap(r => r.participants.map(p => p.public_id)));
    return participantsToAssign.value.filter(p => !assignedIds.has(p.public_id));
});

// Initialize rooms
watch(roomCount, (val) => {
    const newRooms: Room[] = [];
    for (let i = 1; i <= val; i++) {
        const existing = rooms.value[i-1];
        newRooms.push({
            id: i,
            name: existing?.name || `Room ${i}`,
            participants: existing?.participants || []
        });
    }
    rooms.value = newRooms;
}, { immediate: true });
 
function getDisplayName(p: any) {
    if (!p) return 'Participant';
    if (p.public_id === meetingStore.localParticipant?.public_id) return 'You';
    const name =
        p.display_name ||
        p.user?.name ||
        (p.metadata && p.metadata.guest_name) ||
        p.name ||
        'Participant';
    const isGuest = !p.user?.public_id && !p.user?.id;
    if (isGuest && !/\(guest\)$/i.test(name)) {
        return `${name} (Guest)`;
    }
    return name;
}

function unassign(participant: any) {
    rooms.value = rooms.value.map(r => ({
        ...r,
        participants: r.participants.filter(p => p.public_id !== participant.public_id)
    }));
}

function assignToNextRoom(participant: any) {
    // Find room with fewest participants
    const sortedRooms = [...rooms.value].sort((a, b) => a.participants.length - b.participants.length);
    if (sortedRooms[0]) {
        // We replace the room object to ensure Vue reactivity is triggered for computed properties
        const roomIdx = rooms.value.findIndex(r => r.name === sortedRooms[0].name);
        if (roomIdx !== -1) {
            const newParticipants = [...rooms.value[roomIdx].participants, participant];
            rooms.value[roomIdx] = { ...rooms.value[roomIdx], participants: newParticipants };
        }
    }
}

async function start() {
    loading.value = true;
    try {
        let finalRooms = rooms.value;
        
        if (assignmentMode.value === 'auto') {
            // Distribute participants
            const shuffled = [...participantsToAssign.value].sort(() => Math.random() - 0.5);
            finalRooms.forEach(r => r.participants = []);
            shuffled.forEach((p, i) => {
                finalRooms[i % finalRooms.length].participants.push(p);
            });
        }

        console.log('Starting breakout with payload:', {
            rooms: finalRooms,
            duration: hasTimer.value ? duration.value : null
        });

        await meetingStore.startBreakout(finalRooms, hasTimer.value ? duration.value : 0);
        toast.success(`Breakout session started with ${finalRooms.length} rooms`);
        emit('close');
    } catch (e: any) {
        console.error('Breakout Error:', e);
        const errorMsg = e.response?.data?.message || 'Failed to start breakout session';
        toast.error(errorMsg);
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
.panel-enter-active, .panel-leave-active {
    transition: all 0.3s ease;
}
.panel-enter-from, .panel-leave-to {
    opacity: 0;
    transform: scale(0.95);
}
</style>
