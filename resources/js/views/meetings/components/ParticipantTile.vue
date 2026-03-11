<template>
    <div
        class="tile-root"
        :class="{
            'tile-speaking': isSpeaking,
            'tile-root--loading': showLoadingOverlay,
        }"
    >
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

        <div v-if="showLoadingOverlay" class="tile-loading-overlay">
            <div class="tile-loading-chip">
                <span class="tile-loading-spinner"></span>
                <span class="tile-loading-text">{{ resolvedLoadingLabel }}</span>
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
    isLoading?: boolean;
    loadingLabel?: string;
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
const lastTileVisualMode = ref<"avatar" | "video" | null>(null);
const lastVideoBindingKey = ref<string | null>(null);
const lastAudioBindingKey = ref<string | null>(null);
const lastResolvedSource = ref<string | null>(null);
const lastResolvedSourceKey = ref<string | null>(null);
const isSourceTransitioning = ref(false);
const SOURCE_TRANSITION_SPINNER_MS = 900;
const SCREEN_FRAME_STALL_MS = 1800;
let sourceTransitionTimer: ReturnType<typeof window.setTimeout> | null = null;
let videoFrameWatchTimer: ReturnType<typeof window.setTimeout> | null = null;
let videoFrameCallbackId: number | null = null;
let videoFrameWatchElement: HTMLVideoElement | null = null;
const lastFrameRepairBindingKey = ref<string | null>(null);

const remoteCameraEnabled = computed<boolean | null>(() => {
    if (isLocal.value || props.isScreenShare) return null;
    return props.participant.camera_enabled ?? null;
});

const hasLiveVideo = (s: MediaStream | null | undefined) => {
    if (!s) return false;
    return s
        .getVideoTracks()
        .some((t) => t.readyState === "live" && t.enabled && !t.muted);
};

const hasLiveAudio = (s: MediaStream | null | undefined) => {
    if (!s) return false;
    return s
        .getAudioTracks()
        .some((t) => t.readyState === "live" && t.enabled && !t.muted);
};

function getTrackDebugSummary(stream: MediaStream | null | undefined) {
    if (!stream) {
        return {
            streamId: null,
            videoTracks: [],
            audioTracks: [],
        };
    }

    return {
        streamId: stream.id,
        videoTracks: stream.getVideoTracks().map((track) => ({
            id: track.id,
            readyState: track.readyState,
            enabled: track.enabled,
            muted: track.muted,
        })),
        audioTracks: stream.getAudioTracks().map((track) => ({
            id: track.id,
            readyState: track.readyState,
            enabled: track.enabled,
            muted: track.muted,
        })),
    };
}

function buildMediaBindingKey(
    stream: MediaStream | null | undefined,
    kind: "audio" | "video",
) {
    if (!stream) return null;
    const tracks =
        kind === "video" ? stream.getVideoTracks() : stream.getAudioTracks();
    if (!tracks.length) return null;
    return `${stream.id}:${kind}:${tracks
        .map((track) => `${track.id}:${track.readyState}:${track.enabled}:${track.muted}`)
        .join(",")}`;
}

function getSurfaceType() {
    return props.isSpotlight ? "stage" : "card";
}

function getResolvedSource(stream: MediaStream | null | undefined) {
    if (props.isScreenShare && actualHasVideo.value && hasLiveVideo(stream)) {
        return "screen";
    }
    if (actualHasVideo.value && hasLiveVideo(stream)) {
        return "video";
    }
    return "avatar";
}

function getResolvedSourceState(stream: MediaStream | null | undefined) {
    const source = getResolvedSource(stream);
    const videoKey = buildMediaBindingKey(stream, "video") || "no-video";
    const audioKey = buildMediaBindingKey(stream, "audio") || "no-audio";
    return {
        source,
        key: [
            getSurfaceType(),
            source,
            streamIdLookup.value || "unknown-stream-slot",
            videoKey,
            audioKey,
        ].join("|"),
        streamIdLookup: streamIdLookup.value,
        stream: getTrackDebugSummary(stream),
    };
}

function logTileVisualState(reason: string, mode: "avatar" | "video") {
    const participantId = props.participant.public_id?.toLowerCase() || "unknown";
    console.log(
        `[ParticipantTile][UI] ${mode === "video" ? "hide avatar show video" : "hide video show avatar"}`,
        {
            reason,
            participantId,
            participantName:
                props.participant.user?.name ||
                props.participant.metadata?.guest_name ||
                "Participant",
            isLocal: isLocal.value,
            isScreenShare: !!props.isScreenShare,
            localCameraOn: props.localCameraOn ?? null,
            remoteCameraEnabled: remoteCameraEnabled.value,
            actualHasVideo: actualHasVideo.value,
            stream: getTrackDebugSummary(activeStream.value),
        },
    );
}

function logMediaBindingChange(
    mediaKind: "audio" | "video",
    action: "bind" | "clear",
    stream: MediaStream | null | undefined,
) {
    const participantId = props.participant.public_id?.toLowerCase() || "unknown";
    console.log(`[ParticipantTile][Media] ${mediaKind} ${action}`, {
        participantId,
        isLocal: isLocal.value,
        isScreenShare: !!props.isScreenShare,
        actualHasVideo: actualHasVideo.value,
        stream: getTrackDebugSummary(stream),
    });
}

function logResolvedSourceChange(
    reason: string,
    state: ReturnType<typeof getResolvedSourceState>,
    previousSource: string | null,
    previousKey: string | null,
) {
    const participantId = props.participant.public_id?.toLowerCase() || "unknown";
    console.log("[ParticipantTile][Source] state changed", {
        reason,
        participantId,
        participantName:
            props.participant.user?.name ||
            props.participant.metadata?.guest_name ||
            "Participant",
        surface: getSurfaceType(),
        currentSource: state.source,
        previousSource,
        currentKey: state.key,
        previousKey,
        isLocal: isLocal.value,
        isSpotlight: !!props.isSpotlight,
        isScreenShare: !!props.isScreenShare,
        localCameraOn: props.localCameraOn ?? null,
        remoteCameraEnabled: remoteCameraEnabled.value,
        actualHasVideo: actualHasVideo.value,
        streamIdLookup: state.streamIdLookup,
        stream: state.stream,
    });
}

function scheduleSourceTransitionSpinner() {
    isSourceTransitioning.value = true;
    if (sourceTransitionTimer) {
        window.clearTimeout(sourceTransitionTimer);
    }
    sourceTransitionTimer = window.setTimeout(() => {
        isSourceTransitioning.value = false;
        sourceTransitionTimer = null;
    }, SOURCE_TRANSITION_SPINNER_MS);
}

const checkVideoStatus = () => {
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

    // Honor the latest explicit remote camera OFF state even if a stale live
    // track object is still hanging around locally.
    if (remoteCameraEnabled.value === false) {
        actualHasVideo.value = false;
        return;
    }

    actualHasVideo.value = hasLiveVideo(s);
};

let videoStatusInterval: ReturnType<typeof setInterval>;

onMounted(() => {
    videoStatusInterval = setInterval(checkVideoStatus, 500);
});

onUnmounted(() => {
    if (videoStatusInterval) {
        clearInterval(videoStatusInterval);
    }
    if (sourceTransitionTimer) {
        window.clearTimeout(sourceTransitionTimer);
    }
    clearVideoFrameWatch();
});

watch(
    [
        activeStream,
        () => props.localCameraOn,
        () => props.isScreenShare,
        () => props.participant.camera_enabled,
    ],
    () => {
        checkVideoStatus();
    },
    { immediate: true },
);

watch(
    actualHasVideo,
    (hasVideo) => {
        const nextMode = hasVideo ? "video" : "avatar";
        if (lastTileVisualMode.value !== nextMode) {
            logTileVisualState("actualHasVideo changed", nextMode);
            lastTileVisualMode.value = nextMode;
        }
    },
    { immediate: true },
);

watch(
    [activeStream, actualHasVideo, () => props.isSpotlight, () => props.isScreenShare],
    () => {
        const state = getResolvedSourceState(activeStream.value);
        if (lastResolvedSourceKey.value !== state.key) {
            if (lastResolvedSourceKey.value !== null) {
                scheduleSourceTransitionSpinner();
            }
            logResolvedSourceChange(
                "resolved source changed",
                state,
                lastResolvedSource.value,
                lastResolvedSourceKey.value,
            );
            lastResolvedSource.value = state.source;
            lastResolvedSourceKey.value = state.key;
        }
    },
    { immediate: true },
);

const resolvedLoadingLabel = computed(() => {
    if (props.loadingLabel) return props.loadingLabel;
    if (props.isSpotlight) {
        return props.isScreenShare ? "Loading presentation..." : "Updating stage...";
    }
    return props.isScreenShare ? "Updating share..." : "Updating video...";
});

const showLoadingOverlay = computed(
    () => !!props.isLoading || isSourceTransitioning.value,
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
const videoContentStyle = computed(() => {
    // If not in spotlight/screenshare, we want to cover the tile (fill)
    if (!props.isSpotlight && !props.isScreenShare) {
        return {
            width: "100%",
            height: "100%",
            objectFit: "cover" as any,
        };
    }

    // In spotlight/screenshare, prefer a simple full-size contain layout.
    // The previous auto-sized aspect-ratio box could collapse during rapid
    // srcObject swaps, which left the stage black even after the stream bound.
    return {
        width: "100%",
        height: "100%",
        objectFit: "contain" as any,
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
};

function updateLocalStream() {
    const videoEl = localVideo.value;
    if (!videoEl) return;

    const stream =
        props.isScreenShare && props.localStreamOverride
            ? props.localStreamOverride
            : meetingStore.localStream;
    const shouldBindVideo = !!stream && hasLiveVideo(stream) && actualHasVideo.value;

    if (shouldBindVideo) {
        const streamChanged = videoEl.srcObject !== stream;
        if (streamChanged) {
            videoEl.srcObject = stream as MediaStream;
        }
        const nextBindingKey = buildMediaBindingKey(stream, "video");
        if (lastVideoBindingKey.value !== nextBindingKey) {
            logMediaBindingChange("video", "bind", stream);
            lastVideoBindingKey.value = nextBindingKey;
        }
        if (shouldKickPlayback(videoEl, streamChanged)) {
            ensureVideoPlayback(
                videoEl,
                `LocalVideo:${props.participant.public_id}:${props.isScreenShare ? "screen" : "camera"}`,
            );
        }
    } else {
        if (lastVideoBindingKey.value !== null) {
            logMediaBindingChange("video", "clear", stream);
            lastVideoBindingKey.value = null;
        }
        videoEl.srcObject = null;
    }
}

watch(
    () => [
        localVideo.value,
        meetingStore.localStream,
        props.isScreenShare,
        props.localStreamOverride,
    ],
    () => updateLocalStream(),
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
    updateRemoteStream();
};

const bindRemoteAudio = (el: any) => {
    remoteAudio.value = el as HTMLAudioElement | null;
    updateRemoteStream();
};

function ensureVideoPlayback(videoEl: HTMLVideoElement, label: string) {
    const attemptPlay = (isRetry = false) => {
        videoEl.play().catch((error: any) => {
            if (!videoEl.srcObject) return;
            if (!isRetry && error?.name === "AbortError") {
                window.setTimeout(() => attemptPlay(true), 50);
                return;
            }
            console.warn(`[${label}] Playback failed`, error);
        });
    };

    attemptPlay(false);
}

function hardReattachVideoStream(
    videoEl: HTMLVideoElement,
    stream: MediaStream,
) {
    videoEl.srcObject = null;
    videoEl.load();
    videoEl.srcObject = stream;
}

function clearVideoFrameWatch() {
    if (videoFrameWatchTimer) {
        window.clearTimeout(videoFrameWatchTimer);
        videoFrameWatchTimer = null;
    }
    if (
        videoFrameCallbackId !== null &&
        videoFrameWatchElement &&
        "cancelVideoFrameCallback" in videoFrameWatchElement
    ) {
        try {
            (videoFrameWatchElement as any).cancelVideoFrameCallback(
                videoFrameCallbackId,
            );
        } catch {}
    }
    videoFrameCallbackId = null;
    videoFrameWatchElement = null;
}

function shouldKickPlayback(
    videoEl: HTMLVideoElement,
    streamChanged: boolean,
) {
    return (
        streamChanged ||
        videoEl.paused ||
        videoEl.readyState < HTMLMediaElement.HAVE_CURRENT_DATA
    );
}

function requestRemoteScreenFrameRepair(bindingKey: string) {
    const participantId = props.participant.public_id?.toLowerCase();
    if (!participantId || isLocal.value || !props.isScreenShare) return;
    if (lastFrameRepairBindingKey.value === bindingKey) return;
    lastFrameRepairBindingKey.value = bindingKey;
    const streamApi = meetingStore.stream as any;
    const cachedPublication = streamApi?.remotePublications?.get?.(participantId);

    console.warn("[ParticipantTile][Media] remote screen frame stalled; requesting repair", {
        participantId,
        bindingKey,
        readyState: remoteVideo.value?.readyState ?? null,
        currentTime: remoteVideo.value?.currentTime ?? null,
        videoWidth: remoteVideo.value?.videoWidth ?? null,
        videoHeight: remoteVideo.value?.videoHeight ?? null,
        sessionId: cachedPublication?.sessionId ?? null,
        screenMid: cachedPublication?.screenMid ?? null,
    });

    try {
        if (cachedPublication?.sessionId && cachedPublication?.screenMid) {
            // Hard reset stale screen binding before force-pull so SFU can attach
            // into a fresh receiver path instead of a potentially wedged transceiver.
            streamApi?.removeParticipantStreams?.(`${participantId}:screen`);
            Promise.resolve(
                streamApi?.pullParticipantTracks?.(
                    participantId,
                    cachedPublication.sessionId,
                    undefined,
                    undefined,
                    cachedPublication.screenMid ?? undefined,
                    undefined,
                    {
                        forceApiPull: true,
                        reason: "ui:screen-frame-stall",
                        pullKinds: { screen: true },
                    },
                ),
            ).catch((error: any) => {
                console.warn(
                    `[ParticipantTile][Media] Screen repair pull failed for ${participantId}`,
                    error,
                );
            });
            return;
        }

        streamApi?.requestMediaInfo?.(participantId, {
            force: true,
            reason: "ui:screen-frame-stall",
        });

        // Follow-up pull: if publication metadata was stale/missing at first read,
        // retry shortly with forceApiPull once request-media-info has had a chance to land.
        window.setTimeout(() => {
            try {
                const latestPublication = streamApi?.remotePublications?.get?.(
                    participantId,
                );
                if (!latestPublication?.sessionId || !latestPublication?.screenMid) {
                    return;
                }

                streamApi?.removeParticipantStreams?.(`${participantId}:screen`);
                Promise.resolve(
                    streamApi?.pullParticipantTracks?.(
                        participantId,
                        latestPublication.sessionId,
                        undefined,
                        undefined,
                        latestPublication.screenMid ?? undefined,
                        undefined,
                        {
                            forceApiPull: true,
                            reason: "ui:screen-frame-stall:follow-up",
                            pullKinds: { screen: true },
                        },
                    ),
                ).catch((error: any) => {
                    console.warn(
                        `[ParticipantTile][Media] Follow-up repair pull failed for ${participantId}`,
                        error,
                    );
                });
            } catch (error) {
                console.warn(
                    `[ParticipantTile][Media] Follow-up screen repair failed for ${participantId}`,
                    error,
                );
            }
        }, 250);
    } catch (error) {
        console.warn(
            `[ParticipantTile][Media] Failed to request remote screen repair for ${participantId}`,
            error,
        );
    }
}

function armVideoFrameWatch(
    videoEl: HTMLVideoElement,
    label: string,
    bindingKey: string | null,
) {
    clearVideoFrameWatch();
    if (!bindingKey || !videoEl.srcObject) return;

    let settled = false;
    videoFrameWatchElement = videoEl;

    const settle = () => {
        if (settled) return;
        settled = true;
        clearVideoFrameWatch();
    };

    if ("requestVideoFrameCallback" in videoEl) {
        videoFrameCallbackId = (videoEl as any).requestVideoFrameCallback(() => {
            settle();
        });
    } else {
        const onLoadedData = () => {
            settle();
        };
        videoEl.addEventListener("loadeddata", onLoadedData, { once: true });
    }

    videoFrameWatchTimer = window.setTimeout(() => {
        if (settled || !videoEl.srcObject) return;
        settle();
        const currentStream = videoEl.srcObject as MediaStream | null;
        if (currentStream) {
            // Decoder nudge: Chromium occasionally stalls on a bound remote screen
            // after upstream renegotiation while keeping the same stream object.
            hardReattachVideoStream(videoEl, currentStream);
            ensureVideoPlayback(videoEl, label);
        }
        console.warn(`[${label}] No video frame rendered after bind`, {
            bindingKey,
            readyState: videoEl.readyState,
            currentTime: videoEl.currentTime,
            videoWidth: videoEl.videoWidth,
            videoHeight: videoEl.videoHeight,
        });
        requestRemoteScreenFrameRepair(bindingKey);
    }, SCREEN_FRAME_STALL_MS);
}

function updateRemoteStream() {
    const stream = activeStream.value;
    const hasVideo = hasLiveVideo(stream) && actualHasVideo.value;
    const hasAudio = hasLiveAudio(stream);

    if (remoteVideo.value) {
        if (hasVideo && stream) {
            const nextBindingKey = buildMediaBindingKey(stream, "video");
            const bindingKeyChanged = lastVideoBindingKey.value !== nextBindingKey;
            const bindingChanged =
                lastVideoBindingKey.value !== null &&
                nextBindingKey !== null &&
                lastVideoBindingKey.value !== nextBindingKey;
            const streamChanged = remoteVideo.value.srcObject !== stream;
            if (streamChanged) {
                remoteVideo.value.srcObject = stream;
            } else if (bindingChanged) {
                // Chromium can stall on restarted remote screen tracks when the
                // MediaStream object stays the same but the underlying video
                // track changes. Force a full element reattach in that case.
                hardReattachVideoStream(remoteVideo.value, stream);
            }
            if (bindingKeyChanged) {
                logMediaBindingChange("video", "bind", stream);
                lastVideoBindingKey.value = nextBindingKey;
            }
            if (shouldKickPlayback(remoteVideo.value, streamChanged || bindingChanged)) {
                ensureVideoPlayback(
                    remoteVideo.value,
                    `RemoteVideo:${props.participant.public_id}:${props.isScreenShare ? "screen" : "camera"}`,
                );
            }
            if (props.isScreenShare) {
                // Rearm stall detection only when the bound stream/track identity changed.
                // Re-arming on every reactive rerender can cause repeated load/play churn
                // and hide the real recovery path behind AbortError noise.
                if (streamChanged || bindingChanged || bindingKeyChanged) {
                    armVideoFrameWatch(
                        remoteVideo.value,
                        `RemoteVideo:${props.participant.public_id}:screen`,
                        nextBindingKey,
                    );
                }
            } else {
                clearVideoFrameWatch();
            }
        } else {
            if (lastVideoBindingKey.value !== null) {
                logMediaBindingChange("video", "clear", stream);
                lastVideoBindingKey.value = null;
            }
            clearVideoFrameWatch();
            lastFrameRepairBindingKey.value = null;
            remoteVideo.value.srcObject = null;
        }
    }

    if (remoteAudio.value) {
        if (hasAudio && stream) {
            if (remoteAudio.value.srcObject !== stream) {
                remoteAudio.value.srcObject = stream;
            }
            const nextBindingKey = buildMediaBindingKey(stream, "audio");
            if (lastAudioBindingKey.value !== nextBindingKey) {
                logMediaBindingChange("audio", "bind", stream);
                lastAudioBindingKey.value = nextBindingKey;
            }
            remoteAudio.value.play().catch((e: any) => {
                console.warn(
                    `[AudioPlayback] Playback failed for ${props.participant.public_id}:`,
                    e,
                );
            });
        } else {
            if (lastAudioBindingKey.value !== null) {
                logMediaBindingChange("audio", "clear", stream);
                lastAudioBindingKey.value = null;
            }
            remoteAudio.value.srcObject = null;
        }
    }
}

watch(
    [activeStream, remoteVideo, remoteAudio],
    () => updateRemoteStream(),
    { immediate: true },
);

watch(
    actualHasVideo,
    () => {
        if (isLocal.value) {
            updateLocalStream();
        } else {
            updateRemoteStream();
        }
    },
    { immediate: true },
);

onMounted(() => {
    if (isLocal.value) {
        updateLocalStream();
    } else {
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

.tile-root--loading::after {
    content: "";
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.12);
    z-index: 2;
    pointer-events: none;
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

.tile-loading-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 6;
    pointer-events: none;
}

.tile-loading-chip {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.76);
    border: 1px solid rgba(148, 163, 184, 0.28);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 10px 30px rgba(2, 6, 23, 0.28);
}

.tile-loading-spinner {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2px solid rgba(226, 232, 240, 0.28);
    border-top-color: #8ab4f8;
    animation: tile-spin 0.8s linear infinite;
}

.tile-loading-text {
    color: #e2e8f0;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
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
    border: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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

@keyframes tile-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
