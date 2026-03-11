<template>
    <div
        v-if="show"
        class="fixed bottom-24 right-6 w-72 bg-slate-800 border border-indigo-500/50 rounded-xl shadow-2xl z-9999 overflow-hidden flex flex-col font-mono text-sm"
    >
        <div
            class="bg-indigo-600/20 border-b border-indigo-500/30 px-3 py-2 flex items-center justify-between cursor-move select-none"
        >
            <div class="flex items-center gap-2">
                <Icon name="bug" size="14" class="text-indigo-400" />
                <span
                    class="font-bold text-indigo-300 tracking-tight text-xs uppercase"
                    >Dev Simulator</span
                >
            </div>
            <button
                @click="$emit('update:show', false)"
                class="text-slate-400 hover:text-white transition-colors"
            >
                <Icon
                    name="x"
                    size="16"
                />
            </button>
        </div>

        <div
            v-show="isOpen"
            class="p-3 space-y-4 max-h-[60vh] overflow-y-auto custom-scrollbar"
        >
            <!-- Realtime Stats -->
            <div class="space-y-1.5 p-2 bg-slate-900/50 rounded-lg text-xs">
                <div class="flex justify-between">
                    <span class="text-slate-400">Echo Status:</span>
                    <span
                        :class="
                            meetingStore.echoChannel
                                ? 'text-green-400'
                                : 'text-red-400'
                        "
                    >
                        {{
                            meetingStore.echoChannel
                                ? "Subscribed"
                                : "Disconnected"
                        }}
                    </span>
                </div>
                <!-- SFU STATES -->
                <div class="flex justify-between">
                    <span class="text-slate-400">SFU Connection:</span>
                    <span :class="sfuColor(meetingStore.sfuConnectionState)">
                        {{ meetingStore.sfuConnectionState }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">SFU ICE:</span>
                    <span :class="sfuColor(meetingStore.sfuIceState)">
                        {{ meetingStore.sfuIceState }}
                    </span>
                </div>

                <div class="flex justify-between">
                    <span class="text-slate-400">Active Peers:</span>
                    <span class="text-sky-300 font-bold">{{
                        meetingStore.activeParticipantIds.size
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Remote Streams:</span>
                    <span class="text-emerald-300 font-bold">{{
                        meetingStore.remoteStreams.size
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Role:</span>
                    <span class="text-amber-300">{{
                        meetingStore.isHost ? "Host" : "Participant"
                    }}</span>
                </div>
            </div>

            <hr class="border-slate-700" />

            <!-- Remote Participants Simulation -->
            <div class="space-y-2">
                <h4
                    class="text-xs font-bold text-slate-300 uppercase tracking-widest mb-2"
                >
                    Simulate Peers
                </h4>
                <div class="flex gap-2">
                    <button
                        @click="addDummyParticipant"
                        class="flex-1 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded text-xs transition-colors flex items-center justify-center gap-1"
                    >
                        <Icon name="user-plus" size="12" /> Add
                    </button>
                    <button
                        v-if="dummyParticipants.length > 0"
                        @click="removeDummyParticipant"
                        class="flex-1 py-1.5 bg-red-900/50 hover:bg-red-800/80 text-red-200 border border-red-800/50 rounded text-xs transition-colors"
                    >
                        Remove
                    </button>
                </div>
            </div>

            <hr class="border-slate-700" />

            <!-- Local Role Simulation -->
            <div class="space-y-2">
                <h4
                    class="text-xs font-bold text-slate-300 uppercase tracking-widest mb-2"
                >
                    Simulate Locals
                </h4>

                <button
                    @click="toggleHostRole"
                    class="w-full py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded text-xs transition-colors flex items-center justify-center gap-1.5"
                >
                    <Icon
                        name="shield"
                        size="12"
                        :class="
                            meetingStore.isHost
                                ? 'text-amber-400'
                                : 'text-slate-400'
                        "
                    />
                    Make me {{ meetingStore.isHost ? "Participant" : "Host" }}
                </button>
            </div>

            <hr class="border-slate-700" />

            <!-- Track Simulation -->
            <div class="space-y-2">
                <h4
                    class="text-xs font-bold text-slate-300 uppercase tracking-widest mb-2"
                >
                    Simulate Tracks
                </h4>
                <button
                    @click="simulateScreenShare"
                    class="w-full py-1.5 bg-slate-700 hover:bg-slate-600 border border-slate-600 text-white rounded text-xs transition-colors flex items-center justify-center gap-1.5"
                >
                    <Icon name="monitor-up" size="12" class="text-sky-400" />
                    Inject Screenshare
                </button>
                <div
                    v-if="hasSimulatedScreen"
                    class="text-[10px] text-sky-400/80 text-center"
                >
                    Screenshare active from "Dev Screenshare"
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { useMeetingStore } from "@/stores/meeting";
import { Icon } from "@/components/ui";

const props = defineProps<{
    show: boolean;
}>();

const emit = defineEmits(['update:show']);

const meetingStore = useMeetingStore();
const isOpen = ref(true);

function sfuColor(state: string) {
    if (state === 'connected' || state === 'completed') return 'text-green-400';
    if (state === 'failed' || state === 'disconnected') return 'text-red-400';
    return 'text-amber-400';
}

// State for dummies so we can selectively remove them
const dummyParticipants = ref<string[]>([]);
const hasSimulatedScreen = ref(false);

const addDummyParticipant = () => {
    meetingStore.addMockParticipant();
};

const removeDummyParticipant = () => {
    meetingStore.removeMockParticipant();
};

const toggleHostRole = () => {
    const wasHost = meetingStore.isHost;
    meetingStore.setSimulatedRole(wasHost ? 'participant' : 'host');
};

const simulateScreenShare = () => {
    if (hasSimulatedScreen.value) return; // Prevent multiple for simplicity

    const id = `dummy-screen-${Math.random().toString(36).substring(2, 9)}`;

    // 1. Create a dummy participant representing the screenshare bot
    const newParticipant = {
        id: Math.floor(Math.random() * 10000),
        meeting_id: meetingStore.meeting?.id || 0,
        user_id: null,
        public_id: id,
        role: "participant",
        status: "admitted",
        metadata: {
            guest_name: "Bob's Presentation",
        },
        user: undefined,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
    };

    meetingStore.participants.push(newParticipant);
    dummyParticipants.value.push(id);

    // 2. Generate a fake video stream (canvas) to simulate screenshare
    const canvas = document.createElement("canvas");
    canvas.width = 1280;
    canvas.height = 720;
    const ctx = canvas.getContext("2d")!;

    let hue = 0;
    const draw = () => {
        ctx.fillStyle = `hsl(${hue}, 70%, 50%)`;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.fillStyle = "white";
        ctx.font = "bold 80px monospace";
        ctx.textAlign = "center";
        ctx.fillText(
            "SIMULATED SCREENSHARE",
            canvas.width / 2,
            canvas.height / 2 - 40,
        );

        ctx.font = "40px monospace";
        ctx.fillText(
            new Date().toLocaleTimeString(),
            canvas.width / 2,
            canvas.height / 2 + 50,
        );

        hue = (hue + 1) % 360;
        if (hasSimulatedScreen.value) {
            requestAnimationFrame(draw);
        }
    };

    hasSimulatedScreen.value = true;
    draw();

    const stream = canvas.captureStream(30); // 30 fps

    // 3. Inject stream into the store where the MeetingRoomView will see it and bind it
    meetingStore.remoteStreams.set(id, stream);

    // 4. Mark as screenshare and force spotlight so it acts like a real screenshare
    meetingStore.screenShares.add(id);
    meetingStore.setSpotlight(id);

    // Trigger reactivity for the map size
    meetingStore.remoteStreams = new Map(meetingStore.remoteStreams);
};
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 10px;
}
</style>
