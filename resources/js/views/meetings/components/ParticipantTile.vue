<template>
    <div
        class="w-full h-full relative group bg-slate-800 flex items-center justify-center"
    >
        <!-- Local Video -->
        <video
            v-if="isLocal"
            autoplay
            muted
            playsinline
            :ref="bindLocalVideo"
            class="w-full h-full transition-opacity duration-300"
            :class="[
                { 'opacity-0': !localCameraOn && !isScreenShare },
                isSpotlight || isScreenShare
                    ? 'object-contain bg-black'
                    : 'object-cover',
            ]"
        ></video>

        <!-- Remote Video -->
        <video
            v-else-if="hasActiveVideo"
            autoplay
            playsinline
            :ref="bindRemoteVideo"
            class="w-full h-full"
            :class="
                isSpotlight || isScreenShare
                    ? 'object-contain bg-black'
                    : 'object-cover'
            "
            :data-participant="participant.public_id"
            :data-screen="isScreenShare ? 'true' : 'false'"
        ></video>

        <!-- Avatar Fallback (Initials) -->
        <div
            v-if="!hasActiveVideo"
            class="absolute inset-0 z-0 flex items-center justify-center bg-slate-800/50"
        >
            <div
                class="flex h-32 w-32 items-center justify-center rounded-full bg-slate-700 text-6xl font-medium text-white shadow-xl ring-4 ring-slate-600/50 transition-transform hover:scale-105"
            >
                {{ initials }}
            </div>
        </div>

        <!-- Participant Info Overlay -->
        <div
            class="absolute bottom-3 left-3 flex items-center gap-1.5 bg-black/60 backdrop-blur-sm px-2.5 py-1 rounded-lg text-xs font-medium text-white transition-opacity shadow-lg"
            :class="{
                'opacity-100': isSpotlight,
                'opacity-0 group-hover:opacity-100': !isSpotlight,
            }"
        >
            <Icon
                v-if="isLocal && !localMicOn"
                name="mic-off"
                size="12"
                class="text-red-400"
            />
            <span>{{ displayName }}</span>
            <span v-if="participant.role === 'host'" class="text-blue-400 ml-1"
                >(Host)</span
            >
            <Icon
                v-if="isSpotlight"
                name="pin"
                size="12"
                class="text-indigo-400 ml-1"
            />
        </div>

        <!-- Hover Pin Button -->
        <button
            v-if="!isSpotlight"
            @click.stop="meetingStore.setSpotlight(participant.public_id)"
            class="absolute top-3 right-3 w-8 h-8 rounded-lg bg-black/60 backdrop-blur-sm flex items-center justify-center text-white opacity-0 group-hover:opacity-100 transition-all hover:bg-slate-700 hover:scale-110"
            title="Spotlight for everyone"
        >
            <Icon name="pin" size="14" />
        </button>
        <button
            v-else
            @click.stop="meetingStore.setSpotlight(null)"
            class="absolute top-3 right-3 w-8 h-8 rounded-lg bg-indigo-600 backdrop-blur-sm flex items-center justify-center text-white hover:bg-indigo-500 shadow-lg transition-all hover:scale-110"
            title="Remove spotlight"
        >
            <Icon name="pin-off" size="14" />
        </button>

        <!-- Active Speaker Outline -->
        <div
            v-if="meetingStore.activeSpeakerId === participant.public_id && !isScreenShare"
            class="absolute inset-0 border-4 border-indigo-500 rounded-2xl pointer-events-none transition-all"
        ></div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, onMounted } from "vue";
import { useMeetingStore } from "@/stores/meeting";
import { Icon } from "@/components/ui";

const props = defineProps<{
    participant: any;
    isSpotlight?: boolean;
    isScreenShare?: boolean;
    localCameraOn?: boolean;
    localMicOn?: boolean;
    localStreamOverride?: MediaStream | null;
}>();

const meetingStore = useMeetingStore();

const isLocal = computed(() => {
    return (
        props.participant.public_id === meetingStore.localParticipant?.public_id
    );
});

const streamIdLookup = computed(() => {
    return props.isScreenShare
        ? `${props.participant.public_id}:screen`
        : props.participant.public_id;
});

const hasActiveVideo = computed(() => {
    if (isLocal.value) return props.localCameraOn || props.isScreenShare;
    return meetingStore.remoteStreams.has(streamIdLookup.value);
});

const displayName = computed(() => {
    let name = isLocal.value
        ? "You"
        : props.participant.user?.name ||
          props.participant.metadata?.guest_name ||
          "Participant";
    if (props.isScreenShare) name += " (Screen)";
    return name;
});

const initials = computed(() => {
    const name =
        props.participant.user?.name ||
        props.participant.metadata?.guest_name ||
        "Y";
    return name[0].toUpperCase();
});

// -- Local Video Binding --
const localVideo = ref<HTMLVideoElement | null>(null);

const bindLocalVideo = (el: any) => {
    localVideo.value = el as HTMLVideoElement | null;
};

watch(
    () => [
        localVideo.value,
        meetingStore.localStream,
        props.localStreamOverride,
        props.isScreenShare,
    ],
    ([videoEl, camStream, overrideStream]) => {
        const stream =
            props.isScreenShare && props.localStreamOverride
                ? props.localStreamOverride
                : camStream;
        if (
            videoEl &&
            stream &&
            (videoEl as HTMLVideoElement).srcObject !== stream
        ) {
            (videoEl as HTMLVideoElement).srcObject = stream as MediaStream;
        }
    },
    { immediate: true },
);

// -- Remote Video Binding --
const remoteVideo = ref<HTMLVideoElement | null>(null);

const bindRemoteVideo = (el: any) => {
    remoteVideo.value = el as HTMLVideoElement | null;
    updateRemoteStream();
};

function updateRemoteStream() {
    const stream = meetingStore.remoteStreams.get(streamIdLookup.value);
    if (remoteVideo.value && stream) {
        if (remoteVideo.value.srcObject !== stream) {
            remoteVideo.value.srcObject = stream;
        }
    }
}

// Watch for stream changes with deep reactivity or regular trigger
watch(
    [() => meetingStore.remoteStreams.get(streamIdLookup.value), remoteVideo],
    ([newStream, videoEl]) => {
        if (newStream && videoEl) {
            if (videoEl.srcObject !== newStream) {
                videoEl.srcObject = newStream;
            }
        }
    },
    { immediate: true },
);

onMounted(() => {
    if (!isLocal.value) {
        updateRemoteStream();
    }
});
</script>
