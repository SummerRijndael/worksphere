<template>
    <div
        class="meeting-room bg-slate-900 min-h-screen relative flex flex-col overflow-hidden text-white font-sans"
    >
        <!-- Header -->
        <header
            class="h-14 flex items-center justify-between px-6 z-10 bg-slate-900/90 border-b border-slate-800"
        >
            <span
                class="text-sm font-medium text-slate-300 truncate max-w-xs"
                >{{ meetingTitle }}</span
            >
            <span class="text-xs text-slate-500"
                >{{ participantCount }} participant{{
                    participantCount !== 1 ? "s" : ""
                }}</span
            >
        </header>

        <!-- Main Video Grid -->
        <main
            class="flex-1 relative flex flex-col items-center justify-center p-4 min-h-0 overflow-hidden space-y-4"
        >
            <!-- Pagination Controls (Grid) -->
            <button
                v-if="!isSpotlightMode && gridPage > 0"
                @click="prevPage"
                class="absolute left-6 top-1/2 -translate-y-1/2 z-50 w-12 h-12 bg-slate-800/80 hover:bg-slate-700 text-white rounded-full flex items-center justify-center shadow-2xl backdrop-blur-sm transition-all hover:scale-110 border border-slate-600"
            >
                <Icon name="chevron-left" size="24" />
            </button>
            <button
                v-if="!isSpotlightMode && gridPage < totalGridPages - 1"
                @click="nextPage"
                class="absolute right-6 top-1/2 -translate-y-1/2 z-50 w-12 h-12 bg-slate-800/80 hover:bg-slate-700 text-white rounded-full flex items-center justify-center shadow-2xl backdrop-blur-sm transition-all hover:scale-110 border border-slate-600"
            >
                <Icon name="chevron-right" size="24" />
            </button>

            <!-- Spotlight Stage -->
            <div
                v-if="isSpotlightMode && spotlightParticipant"
                class="w-full max-w-5xl aspect-video shrink-0 bg-black rounded-2xl overflow-hidden shadow-2xl border border-slate-700 relative"
            >
                <ParticipantTile
                    :participant="spotlightParticipant"
                    :is-spotlight="true"
                    :is-screen-share="meetingStore.screenShares.has(spotlightParticipant.public_id)"
                    :local-camera-on="isCameraOn"
                    :local-mic-on="isMicOn"
                    :local-stream-override="screenStream"
                />
            </div>

            <!-- Grid or Filmstrip Container -->
            <div
                class="w-full flex-1 max-w-7xl mx-auto flex flex-wrap gap-3 items-center justify-center content-center relative"
                :class="{
                    'flex-row flex-nowrap overflow-hidden h-40 max-h-40':
                        isSpotlightMode,
                }"
            >
                <!-- Filmstrip Pagination -->
                <button
                    v-if="isSpotlightMode && filmstripPage > 0"
                    @click="prevPage"
                    class="absolute left-0 z-20 w-8 h-8 bg-slate-800/80 hover:bg-slate-700 text-white rounded-full flex items-center justify-center shadow-lg backdrop-blur-sm transition-all hover:scale-110 -translate-x-2 border border-slate-600"
                >
                    <Icon name="chevron-left" size="18" />
                </button>
                <button
                    v-if="
                        isSpotlightMode &&
                        filmstripPage < totalFilmstripPages - 1
                    "
                    @click="nextPage"
                    class="absolute right-0 z-20 w-8 h-8 bg-slate-800/80 hover:bg-slate-700 text-white rounded-full flex items-center justify-center shadow-lg backdrop-blur-sm transition-all hover:scale-110 translate-x-2 border border-slate-600"
                >
                    <Icon name="chevron-right" size="18" />
                </button>

                <!-- Tiles -->
                <div
                    v-for="p in paginatedParticipants"
                    :key="p.public_id"
                    class="relative rounded-2xl overflow-hidden shadow-xl aspect-video border border-slate-700 transition-all duration-300 transform"
                    :style="gridItemStyle"
                >
                    <ParticipantTile
                        :participant="p"
                        :is-screen-share="meetingStore.screenShares.has(p.public_id)"
                        :local-camera-on="isCameraOn"
                        :local-mic-on="isMicOn"
                        :local-stream-override="screenStream"
                    />
                </div>
            </div>
        </main>

        <!-- Group Call Style Controls Bar (Expandable) -->
        <div 
            class="controls-bar" 
            :class="{ 'collapsed': isControlsCollapsed, 'is-dragging': isDragging }"
            :style="isDragging || hasMoved ? { left: `${controlsPosition.x}px`, top: `${controlsPosition.y}px`, bottom: 'auto', transform: 'none' } : {}"
            @touchstart="startDrag"
            @mousedown="startDrag"
        >
            <!-- Drag Handle -->
            <div class="drag-handle" title="Drag to move">
                <Icon name="grip-vertical" size="20" />
            </div>

            <button
                class="control-btn collapse-toggle"
                @click="isControlsCollapsed = !isControlsCollapsed"
                :title="controlToggleTitle"
            >
                <Icon :name="isControlsCollapsed ? 'chevron-right' : 'chevron-left'" size="20" />
            </button>

            <div class="main-controls" v-show="!isControlsCollapsed">
                <button
                    v-if="meetingStore.isHost"
                    @click="showAdmissionPanel = !showAdmissionPanel"
                    class="relative w-12 h-12 rounded-2xl flex items-center justify-center transition-all duration-300"
                    :class="[
                        showAdmissionPanel
                            ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20'
                            : 'bg-slate-800/80 text-slate-300 hover:bg-slate-700 hover:text-white',
                    ]"
                    :title="waitingRoomTitle"
                >
                    <Icon name="users" size="20" />
                    <span
                        v-if="meetingStore.waitingParticipants.length > 0"
                        class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-slate-900 animate-bounce"
                    >
                        {{ meetingStore.waitingParticipants.length }}
                    </span>
                </button>

                <button
                    class="control-btn"
                    :class="{ 'bg-red-500/20 text-red-500 hover:bg-red-500/30': !isMicOn }"
                    @click="toggleMic"
                    :title="micToggleTitle"
                >
                    <Icon :name="isMicOn ? 'mic' : 'mic-off'" size="24" />
                </button>

                <button
                    class="control-btn"
                    :class="{ 'bg-red-500/20 text-red-500 hover:bg-red-500/30': !isCameraOn }"
                    @click="toggleCamera"
                    :title="cameraToggleTitle"
                >
                    <Icon :name="isCameraOn ? 'video' : 'video-off'" size="24" />
                </button>

                <button
                    class="control-btn"
                    :class="{ 'active': isScreenSharing }"
                    @click="toggleScreenShare"
                    :title="screenShareToggleTitle"
                >
                    <Icon name="monitor" size="24" />
                </button>

                <button
                    class="control-btn"
                    :class="{ 'active': isHandRaised }"
                    @click="meetingStore.toggleHand()"
                    title="Raise Hand"
                >
                    <Icon name="hand" size="24" />
                </button>

                <button
                    class="control-btn"
                    @click="showSettings = true"
                    title="Device Settings"
                >
                    <Icon name="settings" size="24" />
                </button>

                <button
                    class="control-btn hangup"
                    @click="leaveMeeting"
                    title="End Call"
                >
                    <Icon name="phone-off" size="24" />
                </button>
            </div>

            <div class="collapsed-status" v-show="isControlsCollapsed">
                <Icon :name="isMicOn ? 'mic' : 'mic-off'" size="16" :class="{ 'text-red-500': !isMicOn }" />
                <Icon :name="isCameraOn ? 'video' : 'video-off'" size="16" :class="{ 'text-red-500': !isCameraOn }" />
                <button class="control-btn hangup small" @click="leaveMeeting">
                    <Icon name="phone-off" size="16" />
                </button>
            </div>
        </div>

        <!-- Modals and Tools -->
        <DeviceSettingsModal
            v-model:open="showSettings"
            @close="showSettings = false"
        />

        <DevSimulationTool 
            v-if="isDevMode"
            v-model:show="showDevTool" 
        />

        <!-- Admission Management Panel (Host Only) -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="showAdmissionPanel && meetingStore.isHost"
                class="fixed top-0 right-0 w-80 h-full bg-slate-900 border-l border-slate-800 shadow-2xl z-50 flex flex-col"
            >
                <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                    <h3 class="font-semibold text-white">Waiting Room</h3>
                    <button @click="showAdmissionPanel = false" class="text-slate-400 hover:text-white">
                        <Icon name="x" size="20" />
                    </button>
                </div>
                
                <div class="flex-1 overflow-y-auto p-4 space-y-4">
                    <div v-if="meetingStore.waitingParticipants.length === 0" class="text-center py-10">
                        <Icon name="users" size="32" class="mx-auto text-slate-700 mb-2" />
                        <p class="text-slate-500 text-sm">No one is waiting to join.</p>
                    </div>
                    
                    <div
                        v-for="p in meetingStore.waitingParticipants"
                        :key="p.public_id"
                        class="bg-slate-800/50 rounded-xl p-3 border border-slate-700/50"
                    >
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-full bg-indigo-600 flex items-center justify-center text-white font-bold">
                                {{ getParticipantInitial(p) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-white truncate">
                                    {{ getParticipantName(p) }}
                                </p>
                                <p class="text-xs text-slate-500">Wants to join</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="meetingStore.admitParticipant(p.public_id)"
                                class="flex-1 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg transition-colors"
                            >
                                Admit
                            </button>
                            <button
                                @click="meetingStore.rejectParticipant(p.public_id)"
                                class="flex-1 py-1.5 bg-slate-700 hover:bg-red-900/40 text-slate-300 hover:text-red-400 text-xs font-semibold rounded-lg transition-colors border border-slate-600"
                            >
                                Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Waiting Room Overlay (Participant Only) -->
        <Transition
            enter-active-class="transition duration-500 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-300 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="isWaiting"
                class="fixed inset-0 z-100 bg-slate-950 flex flex-col items-center justify-center p-6 text-center"
            >
                <div class="mb-8 relative">
                    <div class="w-24 h-24 rounded-full bg-indigo-600/20 flex items-center justify-center animate-pulse">
                        <Icon name="lock" size="40" class="text-indigo-400" />
                    </div>
                    <div class="absolute -bottom-2 -right-2 w-8 h-8 rounded-full bg-slate-900 flex items-center justify-center border-2 border-slate-950">
                        <div class="w-3 h-3 rounded-full bg-blue-500 animate-ping"></div>
                    </div>
                </div>
                
                <h1 class="text-2xl font-bold text-white mb-2">You're in the Waiting Room</h1>
                <p class="text-slate-400 max-w-md">
                    The meeting host has been notified. They'll let you in shortly.
                    Please keep this window open.
                </p>
                
                <div class="mt-12 flex flex-col items-center gap-4">
                    <div class="flex items-center gap-2 px-4 py-2 bg-slate-900 rounded-full border border-slate-800">
                        <img v-if="meetingStore.meeting?.host?.avatar_url" :src="meetingStore.meeting.host.avatar_url" class="w-6 h-6 rounded-full" />
                        <span class="text-sm text-slate-300">Host: {{ meetingHostName }}</span>
                    </div>
                    
                    <button
                        @click="router.push({ name: 'home' })"
                        class="text-sm text-slate-500 hover:text-white transition-colors underline underline-offset-4"
                    >
                        Cancel and return home
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { meetingService } from "@/services/meeting.service";
import { useMeetingStore } from "@/stores/meeting";
import { useVideoCallStore } from "@/stores/videocall";
import DeviceSettingsModal from "./components/DeviceSettingsModal.vue";
import DevSimulationTool from "./components/DevSimulationTool.vue";
import ParticipantTile from "./components/ParticipantTile.vue";
import { Icon } from "@/components/ui";
import { toast } from "vue-sonner";

const route = useRoute();
const router = useRouter();
const meetingStore = useMeetingStore();
const videoCallStore = useVideoCallStore();

const meetingId = route.params.id as string;
const participantId = route.query.participant as string;

const isCameraOn = ref(false);
const isMicOn = ref(false);
const showSettings = ref(false);
const showAdmissionPanel = ref(false);
const isControlsCollapsed = ref(false);
const showDevTool = ref(false);

const isWaiting = computed(() => meetingStore.localParticipant?.status === 'waiting');

const meetingTitle = computed(() => meetingStore.meeting?.title || 'Meeting');
const participantCount = computed(() => meetingStore.participants.length);
const waitingRoomTitle = computed(() => `Waiting Room (${meetingStore.waitingParticipants.length})`);
const micToggleTitle = computed(() => isMicOn.value ? 'Mute' : 'Unmute');
const cameraToggleTitle = computed(() => isCameraOn.value ? 'Turn off camera' : 'Turn on camera');
const screenShareToggleTitle = computed(() => isScreenSharing.value ? 'Stop sharing' : 'Share Screen');
const controlToggleTitle = computed(() => isControlsCollapsed.value ? 'Expand Controls' : 'Collapse Controls');
const isHandRaised = computed(() => meetingStore.raisedHands.has(meetingStore.localParticipant?.public_id || ''));
const isDevMode = computed(() => !!import.meta.env.DEV);
const meetingHostName = computed(() => meetingStore.meeting?.host?.name || 'Authorized Personnel');

function getParticipantInitial(p: any) {
    const name = p.user?.name || p.metadata?.guest_name || 'G';
    return name[0].toUpperCase();
}

function getParticipantName(p: any) {
    return p.user?.name || p.metadata?.guest_name || 'Guest';
}

// ─── Draggable Controls ─────────────────────────────────────────────────────

const isDragging = ref(false);
const hasMoved = ref(false);
const controlsPosition = reactive({ x: 0, y: 0 });
const dragOffset = reactive({ x: 0, y: 0 });

const startDrag = (event: MouseEvent | TouchEvent) => {
    const target = event.target as HTMLElement;
    if (target.closest("button")) return;

    isDragging.value = true;
    hasMoved.value = false;

    const clientX = "touches" in event ? event.touches[0].clientX : event.clientX;
    const clientY = "touches" in event ? event.touches[0].clientY : event.clientY;

    const controlsEl = document.querySelector(".controls-bar") as HTMLElement;
    if (controlsEl) {
        const rect = controlsEl.getBoundingClientRect();
        dragOffset.x = clientX - rect.left;
        dragOffset.y = clientY - rect.top;
    }

    window.addEventListener("mousemove", onDrag);
    window.addEventListener("mouseup", stopDrag);
    window.addEventListener("touchmove", onDrag, { passive: false });
    window.addEventListener("touchend", stopDrag);
};

const onDrag = (event: MouseEvent | TouchEvent) => {
    if (!isDragging.value) return;

    const clientX = "touches" in event ? event.touches[0].clientX : (event as MouseEvent).clientX;
    const clientY = "touches" in event ? event.touches[0].clientY : (event as MouseEvent).clientY;

    let newX = clientX - dragOffset.x;
    let newY = clientY - dragOffset.y;

    const controlsEl = document.querySelector(".controls-bar") as HTMLElement;
    if (controlsEl) {
        const rect = controlsEl.getBoundingClientRect();
        const maxX = window.innerWidth - rect.width - 16;
        const maxY = window.innerHeight - rect.height - 16;
        newX = Math.max(16, Math.min(newX, maxX));
        newY = Math.max(16, Math.min(newY, maxY));
    }

    controlsPosition.x = newX;
    controlsPosition.y = newY;
    hasMoved.value = true;

    if ("touches" in event && event.cancelable) {
        event.preventDefault();
    }
};

const stopDrag = () => {
    isDragging.value = false;
    window.removeEventListener("mousemove", onDrag);
    window.removeEventListener("mouseup", stopDrag);
    window.removeEventListener("touchmove", onDrag);
    window.removeEventListener("touchend", stopDrag);
};

// ─── Computed / Layout ──────────────────────────────────────────────────────

const GRID_PAGE_SIZE = 12;
const FILMSTRIP_PAGE_SIZE = 6;

const gridPage = ref(0);
const filmstripPage = ref(0);

const spotlightParticipant = computed(() => {
    // 1. Pinned participant always wins
    if (meetingStore.pinnedParticipantId) {
        return meetingStore.allParticipants.find(
            (p) => p.public_id === meetingStore.pinnedParticipantId,
        );
    }
    // 2. Priority: Anyone sharing their screen
    const screenSharer = meetingStore.allParticipants.find(
        (p) => meetingStore.screenShares.has(p.public_id)
    );
    if (screenSharer) return screenSharer;

    // 3. Fallback: Active speaker
    if (meetingStore.activeSpeakerId) {
        return meetingStore.allParticipants.find(
            (p) => p.public_id === meetingStore.activeSpeakerId,
        );
    }
    return null;
});

const isSpotlightMode = computed(() => !!spotlightParticipant.value);

const unspotlightedParticipants = computed(() => {
    if (!spotlightParticipant.value) return meetingStore.allParticipants;
    return meetingStore.allParticipants.filter(
        (p) => p.public_id !== spotlightParticipant.value?.public_id,
    );
});

const totalGridPages = computed(
    () =>
        Math.ceil(unspotlightedParticipants.value.length / GRID_PAGE_SIZE) || 1,
);
const totalFilmstripPages = computed(
    () =>
        Math.ceil(
            unspotlightedParticipants.value.length / FILMSTRIP_PAGE_SIZE,
        ) || 1,
);

watch(totalGridPages, (pages) => {
    if (gridPage.value >= pages) gridPage.value = Math.max(0, pages - 1);
});
watch(totalFilmstripPages, (pages) => {
    if (filmstripPage.value >= pages)
        filmstripPage.value = Math.max(0, pages - 1);
});

const paginatedParticipants = computed(() => {
    if (isSpotlightMode.value) {
        const start = filmstripPage.value * FILMSTRIP_PAGE_SIZE;
        return unspotlightedParticipants.value.slice(
            start,
            start + FILMSTRIP_PAGE_SIZE,
        );
    } else {
        const start = gridPage.value * GRID_PAGE_SIZE;
        return unspotlightedParticipants.value.slice(
            start,
            start + GRID_PAGE_SIZE,
        );
    }
});

const gridItemStyle = computed(() => {
    if (isSpotlightMode.value) {
        const count = Math.min(paginatedParticipants.value.length, FILMSTRIP_PAGE_SIZE);
        return { width: `calc(${100 / (count || 1)}% - 8px)`, maxWidth: '240px' };
    }
    const total = paginatedParticipants.value.length;
    if (total === 1) return { width: '100%', maxWidth: '1000px' };
    if (total <= 2) return { width: 'calc(50% - 8px)', maxWidth: '640px' };
    if (total <= 4) return { width: 'calc(50% - 8px)', maxWidth: '480px' };
    if (total <= 6) return { width: 'calc(33.33% - 8px)', maxWidth: '400px' };
    if (total <= 9) return { width: 'calc(33.33% - 8px)', maxWidth: '340px' };
    return { width: 'calc(25% - 8px)', maxWidth: '280px' }; 
});

function prevPage() {
    if (isSpotlightMode.value && filmstripPage.value > 0) filmstripPage.value--;
    if (!isSpotlightMode.value && gridPage.value > 0) gridPage.value--;
}

function nextPage() {
    if (
        isSpotlightMode.value &&
        filmstripPage.value < totalFilmstripPages.value - 1
    )
        filmstripPage.value++;
    if (!isSpotlightMode.value && gridPage.value < totalGridPages.value - 1)
        gridPage.value++;
}

// ─── Mount / Unmount ─────────────────────────────────────────────────────────

onMounted(async () => {
    if (!participantId) {
        toast.error("No participant ID — returning to lobby.");
        router.push({ name: "meeting-lobby", params: { id: meetingId } });
        return;
    }

    try {
        await meetingStore.initializeMeeting(meetingId, participantId);

        const stream = meetingStore.localStream;
        if (stream) {
            const videoTrack = stream.getVideoTracks()[0];
            const audioTrack = stream.getAudioTracks()[0];
            isCameraOn.value = videoTrack ? videoTrack.enabled : false;
            isMicOn.value = audioTrack ? audioTrack.enabled : false;
            await meetingStore.addLocalStream(stream);
        } else {
            // Cold start: Join without an initial stream (camera/mic off)
            await meetingStore.addLocalStream(null);
            isCameraOn.value = false;
            isMicOn.value = false;
        }

        meetingService.sendSignal(meetingId, {
            signal_type: "participant-joined",
            signal_data: {},
            sender_participant_public_id: participantId,
        });

        window.addEventListener('keydown', handleGlobalKeydown);
    } catch (e) {
        console.error("[MeetingRoom] Failed to initialize:", e);
        toast.error("Failed to initialize meeting room.");
    }
});

function handleGlobalKeydown(e: KeyboardEvent) {
    if (e.ctrlKey && e.altKey && e.key.toLowerCase() === 'd') {
        e.preventDefault();
        showDevTool.value = !showDevTool.value;
        if (showDevTool.value) {
            toast.info("Dev Simulator Activated");
        }
    }
}

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    meetingStore.cleanup();
});

// ─── Controls ────────────────────────────────────────────────────────────────

const isScreenSharing = ref(false);
const screenStream = ref<MediaStream | null>(null);

async function toggleScreenShare() {
    if (isScreenSharing.value) {
        if (screenStream.value) {
            screenStream.value.getTracks().forEach((t) => t.stop());
            screenStream.value = null;
        }
        isScreenSharing.value = false;
        
        await meetingStore.unpublishScreenTrack();
        meetingStore.clearSpotlight(); 
    } else {
        try {
            const stream = await navigator.mediaDevices.getDisplayMedia({
                video: true,
                audio: false, 
            });
            screenStream.value = stream;
            isScreenSharing.value = true;
            
            await meetingStore.publishScreenTrack(stream);
            
            meetingStore.setSpotlight(meetingStore.localParticipant!.public_id); 

            const screenTrack = stream.getVideoTracks()[0];
            screenTrack.onended = () => {
                if (isScreenSharing.value) toggleScreenShare();
            };
        } catch (err) {
            toast.error("Failed to share screen");
            console.error(err);
        }
    }
}

const toggleCamera = async () => {
    if (isScreenSharing.value) {
        toast.info("Stop screen sharing to toggle camera.");
        return;
    }

    let stream = meetingStore.localStream;
    if (!stream) {
        stream = new MediaStream();
        meetingStore.setStream(stream);
    }

    if (!isCameraOn.value) {
        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                video: videoCallStore.selectedVideoDeviceId
                    ? { deviceId: videoCallStore.selectedVideoDeviceId }
                    : true,
            });
            const videoTrack = newStream.getVideoTracks()[0];
            stream.addTrack(videoTrack);
            isCameraOn.value = true;
            meetingStore.replaceTrack("video", videoTrack);
        } catch (e) {
            console.error("Failed to start camera", e);
            toast.error("Could not access camera hardware.");
        }
    } else {
        stream.getVideoTracks().forEach((t) => {
            t.stop();
            stream!.removeTrack(t);
        });
        isCameraOn.value = false;
        meetingStore.replaceTrack("video", null);
    }
};

const toggleMic = async () => {
    let stream = meetingStore.localStream;
    if (!stream) {
        stream = new MediaStream();
        meetingStore.setStream(stream);
    }

    if (!isMicOn.value) {
        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                audio: videoCallStore.selectedAudioDeviceId
                    ? { deviceId: videoCallStore.selectedAudioDeviceId }
                    : true,
            });
            const audioTrack = newStream.getAudioTracks()[0];
            stream.addTrack(audioTrack);
            isMicOn.value = true;
            meetingStore.replaceTrack("audio", audioTrack);
        } catch (e) {
            console.error("Failed to start mic", e);
            toast.error("Could not access microphone hardware.");
        }
    } else {
        stream.getAudioTracks().forEach((t) => {
            t.stop();
            stream!.removeTrack(t);
        });
        isMicOn.value = false;
        meetingStore.replaceTrack("audio", null);
    }
};

watch(
    [
        () => videoCallStore.selectedAudioInput,
        () => videoCallStore.selectedVideoInput,
    ],
    async ([newAudio, newVideo], [oldAudio, oldVideo]) => {
        if (!meetingStore.localStream) return;
        const stream = meetingStore.localStream;

        if (isMicOn.value && newAudio !== oldAudio) {
            stream.getAudioTracks().forEach((t) => {
                t.stop();
                stream.removeTrack(t);
            });
            try {
                const newS = await navigator.mediaDevices.getUserMedia({
                    audio: newAudio ? { deviceId: newAudio } : true,
                });
                const track = newS.getAudioTracks()[0];
                stream.addTrack(track);
                meetingStore.replaceTrack("audio", track);
            } catch (e) {
                console.error(e);
            }
        }

        if (isCameraOn.value && newVideo !== oldVideo) {
            stream.getVideoTracks().forEach((t) => {
                t.stop();
                stream.removeTrack(t);
            });
            try {
                const newS = await navigator.mediaDevices.getUserMedia({
                    video: newVideo ? { deviceId: newVideo } : true,
                });
                const track = newS.getVideoTracks()[0];
                stream.addTrack(track);
                meetingStore.replaceTrack("video", track);
            } catch (e) {
                console.error(e);
            }
        }
    },
);

const leaveMeeting = () => {
    meetingStore.localStream?.getTracks().forEach((t) => t.stop());
    router.push({ name: "meeting-lobby", params: { id: meetingId } });
};
</script>

<style scoped>
.controls-bar {
    position: absolute;
    bottom: calc(32px + env(safe-area-inset-bottom, 0));
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 12px;
    z-index: 500;
    transition: all 0.2s cubic-bezier(0.22, 1, 0.36, 1);
    background: rgba(20, 20, 25, 0.8);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    padding: 14px 28px;
    user-select: none;
    border-radius: 40px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

.controls-bar:active {
    cursor: grabbing;
}

.controls-bar.is-dragging {
    transition: none !important;
    pointer-events: auto;
}

.controls-bar.collapsed {
    padding: 8px 16px;
    gap: 8px;
    border-radius: 24px;
}

.drag-handle {
    cursor: grab;
    color: #a1a1aa;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px;
    margin-right: -4px;
}
.drag-handle:active {
    cursor: grabbing;
    color: white;
}

.collapse-toggle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.05);
    border: none;
    color: #a1a1aa;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}
.collapse-toggle:hover {
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.main-controls {
    display: flex;
    gap: 20px;
}

.collapsed-status {
    display: flex;
    align-items: center;
    gap: 12px;
    color: white;
}

.control-btn {
    width: 48px;
    height: 48px;
    border-radius: 24px;
    border: none;
    background: rgba(255, 255, 255, 0.1);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.22, 1, 0.36, 1);
    position: relative;
    overflow: hidden;
}

.control-btn.small {
    width: 32px;
    height: 32px;
}

.control-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
}

.control-btn.active {
    background: #10b981;
    color: white;
    box-shadow: 0 0 20px rgba(16, 185, 129, 0.4);
}

.control-btn.hangup {
    background: rgba(239, 68, 68, 0.85);
    color: white;
}
.control-btn.hangup:hover {
    background: rgba(239, 68, 68, 1);
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.5);
}
</style>
