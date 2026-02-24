<template>
    <div class="tile-root" :class="{ 'tile-speaking': isSpeaking }">
        <!-- Local Video -->
        <video
            v-if="isLocal"
            autoplay
            muted
            playsinline
            :ref="bindLocalVideo"
            class="tile-video"
            :class="[
                { 'tile-video--hidden': !localCameraOn && !isScreenShare },
                isSpotlight || isScreenShare ? 'tile-video--contain' : 'tile-video--cover',
            ]"
        ></video>

        <!-- Remote Video -->
        <video
            v-else-if="hasActiveVideo"
            autoplay
            playsinline
            :ref="bindRemoteVideo"
            class="tile-video"
            :class="isSpotlight || isScreenShare ? 'tile-video--contain' : 'tile-video--cover'"
            :data-participant="participant.public_id"
            :data-screen="isScreenShare ? 'true' : 'false'"
        ></video>

        <!-- Avatar Fallback (Initials) -->
        <div v-if="!hasActiveVideo" class="tile-avatar-wrap">
            <div class="tile-avatar">
                {{ initials }}
            </div>
        </div>

        <!-- Participant Name Label (Bottom-Left) -->
        <div class="tile-name-bar">
            <Icon
                v-if="isLocal && !localMicOn"
                name="mic-off"
                size="14"
                class="tile-mic-muted"
            />
            <span class="tile-name-text">{{ displayName }}</span>
        </div>

        <!-- Hover Pin Button (Top-Right) -->
        <button
            v-if="!isSpotlight"
            @click.stop="meetingStore.setSpotlight(participant.public_id)"
            class="tile-pin-btn"
            title="Pin"
        >
            <Icon name="pin" size="14" />
        </button>
        <button
            v-else
            @click.stop="meetingStore.setSpotlight(null)"
            class="tile-pin-btn tile-pin-btn--active"
            title="Unpin"
        >
            <Icon name="pin-off" size="14" />
        </button>
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
    isLocal?: boolean;
}>();

const meetingStore = useMeetingStore();

const isLocal = computed(() => {
    if (props.isLocal) return true;
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

const isSpeaking = computed(() => {
    return meetingStore.activeSpeakerId === props.participant.public_id && !props.isScreenShare;
});

const displayName = computed(() => {
    let name = isLocal.value
        ? "You"
        : props.participant.user?.name ||
          props.participant.metadata?.guest_name ||
          "Participant";
    if (props.isScreenShare) name += " (presenting)";
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

<style scoped>
.tile-root {
    width: 100%;
    height: 100%;
    position: relative;
    background: #3c4043;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-radius: 8px;
}

.tile-speaking {
    box-shadow: 0 0 0 3px #8ab4f8;
}

.tile-video {
    width: 100%;
    height: 100%;
    transition: opacity 0.3s ease;
}
.tile-video--cover { object-fit: cover; }
.tile-video--contain { object-fit: contain; background: #000; }
.tile-video--hidden { opacity: 0; position: absolute; }

/* Avatar */
.tile-avatar-wrap {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
}

.tile-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: #5f6368;
    color: #e8eaed;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 500;
    font-family: 'Google Sans', 'Roboto', sans-serif;
    user-select: none;
}

/* Name Bar */
.tile-name-bar {
    position: absolute;
    bottom: 0;
    left: 0;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 10px;
    z-index: 5;
    pointer-events: none;
}

.tile-mic-muted {
    color: #ea4335;
}

.tile-name-text {
    font-size: 12px;
    font-weight: 500;
    color: #e8eaed;
    text-shadow: 0 1px 4px rgba(0,0,0,0.7);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
}

/* Pin Button */
.tile-pin-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: none;
    background: rgba(0,0,0,0.5);
    color: #e8eaed;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s, background 0.15s;
    z-index: 10;
}

.tile-root:hover .tile-pin-btn {
    opacity: 1;
}

.tile-pin-btn:hover {
    background: rgba(0,0,0,0.7);
}

.tile-pin-btn--active {
    opacity: 1;
    background: #8ab4f8;
    color: #202124;
}
.tile-pin-btn--active:hover {
    background: #aecbfa;
}
</style>
