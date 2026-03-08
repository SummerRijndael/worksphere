<template>
    <div class="tile-root" :class="{ 'tile-speaking': isSpeaking }">
        <!-- Core Video Content Box -->
        <div v-if="actualHasVideo" class="tile-video-container">
            <div class="tile-video-content" :style="videoContentStyle">
                <!-- Local Video (Persistent to avoid iOS Safari play failures) -->
                <video
                    v-if="isLocal"
                    autoplay
                    muted
                    playsinline
                    :ref="bindLocalVideo"
                    class="tile-video"
                ></video>

                <!-- Remote Video (Persistent) -->
                <video
                    v-if="!isLocal"
                    autoplay
                    muted
                    playsinline
                    :ref="bindRemoteVideo"
                    class="tile-video"
                    :data-participant="participant.public_id"
                    :data-screen="isScreenShare ? 'true' : 'false'"
                ></video>

                <!-- Targeted Laser Pointer Overlay -->
                <!-- Renders relative to the video aspect-ratio box -->
                <LaserPointerOverlay
                    v-if="meetingStore.laserPointerMode !== 'off'"
                    :target-participant-id="participant.public_id"
                    :is-screen-share="isScreenShare"
                />

                <!-- Screenshare Annotation Overlay -->
                <!-- Renders relative to the video aspect-ratio box -->
                <AnnotationOverlay
                    v-if="actualHasVideo && isScreenShare"
                    :ref="bindAnnotationOverlay"
                    :participant-id="participant.public_id"
                    :is-local="isLocal"
                />
            </div>
        </div>

        <!-- Remote Audio -->
        <audio
            v-if="!isLocal"
            autoplay
            playsinline
            :ref="bindRemoteAudio"
            class="hidden"
        ></audio>

        <!-- Avatar Fallback -->
        <div v-if="!actualHasVideo" class="tile-avatar-wrap">
            <div class="tile-avatar-content">
                <Avatar
                    :src="
                        participant.user?.avatar_url ||
                        participant.metadata?.avatar_url
                    "
                    :fallback="initials"
                    :color="participant.user?.color"
                    size="3xl"
                    class="tile-avatar-comp"
                />
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
            <span
                v-if="qualityScore > 0 && qualityScore <= 2 && !isLocal"
                class="tile-quality-dot"
                :class="
                    qualityScore === 1
                        ? 'tile-quality-critical'
                        : 'tile-quality-poor'
                "
                :title="
                    qualityScore === 1
                        ? 'Critical connection'
                        : 'Poor connection'
                "
            ></span>
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

        <!-- Laser pointer moved inside video container -->
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, onMounted, onUnmounted } from "vue";
import { useMeetingStore } from "@/stores/meeting";
import { Icon, Avatar } from "@/components/ui";
import LaserPointerOverlay from "./LaserPointerOverlay.vue";
import AnnotationOverlay from "./AnnotationOverlay.vue";

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
    const pid = props.participant.public_id?.toLowerCase() || "";
    return props.isScreenShare ? `${pid}:screen` : pid;
});

const activeStream = computed(() => {
    if (isLocal.value) {
        return props.isScreenShare && props.localStreamOverride
            ? props.localStreamOverride
            : meetingStore.localStream;
    }
    return meetingStore.remoteStreams.get(streamIdLookup.value);
});

const actualHasVideo = ref(false);

const checkVideoStatus = () => {
    const hasLiveVideo = (s: MediaStream | null | undefined) => {
        if (!s) return false;
        return s
            .getVideoTracks()
            .some((t) => t.readyState === "live" && t.enabled && !t.muted);
    };

    if (isLocal.value) {
        // Use real track state so UI recovers when a track is externally stopped.
        if (props.isScreenShare) {
            actualHasVideo.value = hasLiveVideo(activeStream.value);
        } else {
            actualHasVideo.value = !!props.localCameraOn && hasLiveVideo(activeStream.value);
        }
        return;
    }

    const s = activeStream.value;
    if (!s) {
        actualHasVideo.value = false;
        return;
    }

    // For remote tracks, rely on live/non-muted state across any video track.
    actualHasVideo.value = s
        .getVideoTracks()
        .some((t) => t.readyState === "live" && !t.muted);
};

let videoStatusInterval: ReturnType<typeof setInterval>;

onMounted(() => {
    videoStatusInterval = setInterval(checkVideoStatus, 500);
});

onUnmounted(() => {
    if (videoStatusInterval) {
        clearInterval(videoStatusInterval);
    }
});

watch(
    [activeStream, () => props.localCameraOn, () => props.isScreenShare],
    () => {
        checkVideoStatus();
    },
    { immediate: true },
);

watch(
    actualHasVideo,
    (hasVideo) => {
        if (hasVideo) return;

        // Force-clear stale last-frame rendering when video is considered off.
        if (isLocal.value && !props.isScreenShare && localVideo.value) {
            localVideo.value.srcObject = null;
        }
        if (!isLocal.value && remoteVideo.value) {
            remoteVideo.value.srcObject = null;
        }
    },
    { immediate: true },
);

const isSpeaking = computed(() => {
    return (
        meetingStore.talkingParticipants.has(props.participant.public_id) &&
        !props.isScreenShare
    );
});

const qualityScore = computed(() => {
    const scores = meetingStore.stream?.participantQualityScores?.value;
    if (!scores) return 0;
    const pid = props.participant.public_id?.toLowerCase();
    return scores.get(pid)?.score ?? 0;
});

// -- Aspect Ratio & Content Scaling Logic --
const streamAspectRatio = ref(16 / 9);

function updateAspectRatio(el: HTMLVideoElement | null) {
    if (el && el.videoWidth && el.videoHeight) {
        streamAspectRatio.value = el.videoWidth / el.videoHeight;
    }
}

const videoContentStyle = computed(() => {
    // If not in spotlight/screenshare, we want to cover the tile (fill)
    if (!props.isSpotlight && !props.isScreenShare) {
        return {
            width: "100%",
            height: "100%",
            objectFit: "cover" as any,
        };
    }

    // In spotlight/screenshare, we want to contain (show all pixels)
    // We use aspect-ratio to ensure the container matches the video EXACTLY.
    // By using width/height: auto and max-width/max-height: 100%,
    // the browser will scale the box to fit perfectly while maintaining the ratio.
    return {
        aspectRatio: `${streamAspectRatio.value}`,
        width: "auto",
        height: "auto",
        maxWidth: "100%",
        maxHeight: "100%",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        flexShrink: 1,
        flexGrow: 0,
    };
});

const displayName = computed(() => {
    let name = isLocal.value
        ? "You"
        : props.participant.user?.name ||
          props.participant.metadata?.guest_name ||
          "Participant";

    const isGuest = !props.participant.user?.public_id && !props.participant.user?.id;
    if (!isLocal.value && isGuest) {
        name = `${name} (Guest)`;
    }

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
    if (!el && localVideo.value) {
        localVideo.value.srcObject = null;
    }
    localVideo.value = el as HTMLVideoElement | null;
    if (el) {
        el.addEventListener("resize", () =>
            updateAspectRatio(el as HTMLVideoElement),
        );
        updateAspectRatio(el as HTMLVideoElement);
    }
};

watch(
    () => [
        localVideo.value,
        meetingStore.localStream,
        props.isScreenShare,
        props.localStreamOverride,
    ],
    ([videoEl, camStream, isScreen, overrideStream]) => {
        const stream = isScreen && overrideStream ? overrideStream : camStream;
        if (videoEl && stream) {
            const el = videoEl as HTMLVideoElement;
            if (el.srcObject !== stream) {
                el.srcObject = stream as MediaStream;
                el.play().catch((e) =>
                    console.warn("[LocalVideo] Auto-play prevented", e),
                );
            }
        } else if (videoEl && !stream) {
            (videoEl as HTMLVideoElement).srcObject = null;
        }
    },
    { immediate: true, flush: "post" },
);

// -- Remote Video Binding --
const remoteVideo = ref<HTMLVideoElement | null>(null);
const remoteAudio = ref<HTMLAudioElement | null>(null);

const bindRemoteVideo = (el: any) => {
    if (!el && remoteVideo.value) {
        remoteVideo.value.srcObject = null;
    }
    remoteVideo.value = el as HTMLVideoElement | null;
    if (el) {
        el.addEventListener("resize", () =>
            updateAspectRatio(el as HTMLVideoElement),
        );
        updateAspectRatio(el as HTMLVideoElement);
    }
    updateRemoteStream();
};

const bindRemoteAudio = (el: any) => {
    remoteAudio.value = el as HTMLAudioElement | null;
    updateRemoteStream();
};

function updateRemoteStream() {
    const stream = activeStream.value;
    if (stream) {
        if (remoteVideo.value && remoteVideo.value.srcObject !== stream) {
            remoteVideo.value.srcObject = stream;
        }
        if (remoteAudio.value && remoteAudio.value.srcObject !== stream) {
            remoteAudio.value.srcObject = stream;
        }
    } else {
        if (remoteVideo.value) remoteVideo.value.srcObject = null;
        if (remoteAudio.value) remoteAudio.value.srcObject = null;
    }
}

watch(
    [activeStream, remoteVideo, remoteAudio],
    ([newStream, videoEl, audioEl]) => {
        if (newStream) {
            if (videoEl && videoEl.srcObject !== newStream) {
                videoEl.srcObject = newStream;
            }
            if (audioEl && audioEl.srcObject !== newStream) {
                audioEl.srcObject = newStream;
                audioEl.play().catch((e: any) => {
                    console.warn(
                        `[AudioPlayback] Playback failed for ${props.participant.public_id}:`,
                        e,
                    );
                });
            }
        } else {
            if (videoEl) videoEl.srcObject = null;
            if (audioEl) audioEl.srcObject = null;
        }
    },
    { immediate: true },
);

onMounted(() => {
    if (!isLocal.value) {
        updateRemoteStream();
    }
});

// -- Annotation Handling --
const annotationOverlay = ref<any>(null);
const bindAnnotationOverlay = (el: any) => {
    annotationOverlay.value = el;
};

// Handle remote annotation updates
watch(
    () => meetingStore.lastAnnotationSignal,
    (data) => {
        if (!data) return;

        const signalSenderId = data.participant_id?.toLowerCase();
        const myId = props.participant.public_id?.toLowerCase();

        // Normal case: Update for this tile's strokes (sender == tile owner)
        if (signalSenderId === myId) {
            annotationOverlay.value?.handleRemoteUpdate(data);
        }
        // SPECIAL CASE: Someone is requesting sync from US (we are the presenter)
        else if (
            isLocal.value &&
            data.type === "request-sync" &&
            data.target_participant_id?.toLowerCase() === myId
        ) {
            annotationOverlay.value?.handleRemoteUpdate(data);
        }
    },
);
</script>

<style scoped>
.tile-root {
    width: 100%;
    height: 100%;
    position: relative;
    background: transparent; /* Seamless blend */
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-radius: 0; /* Match grid-tile */
}

.tile-speaking {
    box-shadow: 0 0 0 3px #8ab4f8;
}

.tile-video-container {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    z-index: 0;
}

.tile-video-content {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}

.tile-video {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: inherit;
}

/* Avatar */
.tile-avatar-wrap {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
}

.tile-avatar-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    width: 100%;
}

.tile-avatar-name {
    color: #e8eaed;
    font-size: 16px;
    font-weight: 500;
    font-family: "Google Sans", "Roboto", sans-serif;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.7);
    max-width: 90%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tile-avatar-comp {
    width: 80px;
    height: 80px;
    border: 2px solid rgba(255, 255, 255, 0.1);
    background: #3c4043;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
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
    font-family: "Google Sans", "Roboto", sans-serif;
    user-select: none;
}

/* Name Bar */
.tile-name-bar {
    position: absolute;
    bottom: 8px;
    left: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 8px;
    background: rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(4px);
    border-radius: 4px;
    z-index: 5;
    pointer-events: none;
    max-width: calc(100% - 16px);
}

.tile-mic-muted {
    color: #ea4335;
}

.tile-name-text {
    font-size: 11px;
    font-weight: 500;
    color: #e8eaed;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
    background: rgba(0, 0, 0, 0.5);
    color: #e8eaed;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition:
        opacity 0.15s,
        background 0.15s;
    z-index: 10;
}

.tile-root:hover .tile-pin-btn {
    opacity: 1;
}

.tile-pin-btn:hover {
    background: rgba(0, 0, 0, 0.7);
}

.tile-pin-btn--active {
    opacity: 1;
    background: #8ab4f8;
    color: #202124;
}
.tile-pin-btn--active:hover {
    background: #aecbfa;
}

/* Quality Indicator */
.tile-quality-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.tile-quality-poor {
    background: #f9ab00;
    box-shadow: 0 0 4px rgba(249, 171, 0, 0.6);
}
.tile-quality-critical {
    background: #ea4335;
    box-shadow: 0 0 4px rgba(234, 67, 53, 0.6);
    animation: quality-pulse 1.5s ease-in-out infinite;
}
@keyframes quality-pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}
</style>
