<script setup lang="ts">
/**
 * CallApp.vue — Group Call (Mesh Topology)
 * Supports 1:1 and Group calls (up to ~6 participants)
 */
import "webrtc-adapter";
import {
    ref,
    computed,
    onMounted,
    onBeforeUnmount,
    watch,
    reactive,
} from "vue";
import Peer from "simple-peer";
import { startEcho, stopEcho } from "@/echo";
import { videoCallService } from "@/services/videocall.service";
import { useVideoCallStore } from "@/stores/videocall";
import { Icon } from "@/components/ui";
import CallSettingsModal from "./components/CallSettingsModal.vue";

// ============================================================================
// Types
// ============================================================================

interface Participant {
    publicId: string;
    name: string;
    avatar: string | null;
    isSelf?: boolean;
}

interface CallData {
    callId: string;
    chatId: string;
    callType: "audio" | "video";
    direction: "outgoing" | "incoming";
    selfPublicId: string;
    remoteUser?: {
        publicId: string;
        name: string;
        avatar: string | null;
    };
    chatType?: "dm" | "group";
    pendingSignals?: any[];
}

// ============================================================================
// State
// ============================================================================

const callData = ref<CallData | null>(null);
const callState = ref<
    "initializing" | "ringing" | "connecting" | "connected" | "ended" | "error"
>("initializing");
const hasJoined = ref(false);
const error = ref<string | null>(null);
const store = useVideoCallStore();

// Media
const localStream = ref<MediaStream | null>(null);
const isMuted = ref(false);
const isCameraOff = ref(true); // Default to Video Off as requested
const videoFallback = ref(false);
const isAudioOnly = computed(() => callData.value?.callType === "audio");

// Screen Sharing
const isScreenSharing = ref(false);
const screenStream = ref<MediaStream | null>(null);
const sfuScreenMid = ref<string | null>(null);

// Hybrid Mode
const callMode = ref<"mesh" | "sfu">("mesh");
const sfuSessionId = ref<string | null>(null);
const isSFUResetting = ref(false);
const sfuAppId = ref<string | null>(null);
const remoteSfuSessions = reactive(new Map<string, string>());
const remoteSfuTracks = reactive(
    new Map<string, { audioMid?: string; videoMid?: string }>(),
);
const isTransportReady = ref(false);
const participantTransceivers = new Map<
    string,
    { audioMid?: string; videoMid?: string; screenMid?: string }
>();

// Participants & Peers
const participants = ref<Participant[]>([]);
const peers = new Map<string, Peer.Instance>();
const iceServers = ref<RTCIceServer[]>([]);
const processedSignals = new Set<string>(); // To prevent duplicate signal processing

// SFU Transceiver Mapping (Robust identification even after SID/MID changes)
const sfuTransceiverMap = new WeakMap<
    RTCRtpTransceiver,
    { participantId: string; trackName: string }
>();

// Voice Activity Detection
const talkingParticipants = reactive(new Set<string>());
const audioAnalysers = new Map<
    string,
    {
        context: AudioContext;
        source: MediaStreamAudioSourceNode;
        analyser: AnalyserNode;
        interval: ReturnType<typeof setInterval>;
    }
>();

// Directive for srcObject property (Vue doesn't bind to .srcObject property by default)
const vSrcObject = {
    updated: (el: any, binding: any) => {
        if (el.srcObject !== binding.value) el.srcObject = binding.value;
    },
    mounted: (el: any, binding: any) => {
        el.srcObject = binding.value;
    },
};

// Helper: Apply Output Device (setSinkId) to all media elements
// This is critical for "Speaker Control" (sc) to work.
async function applyOutputDevice(deviceId: string | null) {
    if (!deviceId) return;
    console.log("[Call] Applying output device:", deviceId);

    // Apply to all video and audio elements in the document
    const elements = document.querySelectorAll("video, audio");
    for (const el of Array.from(elements)) {
        try {
            if ((el as any).setSinkId) {
                await (el as any).setSinkId(deviceId);
            }
        } catch (e) {
            console.warn("[Call] Failed to setSinkId on element", e);
        }
    }

    // Apply to ringtone if active
    if (ringtoneAudio && (ringtoneAudio as any).setSinkId) {
        try {
            await (ringtoneAudio as any).setSinkId(deviceId);
        } catch (e) {
             console.warn("[Call] Failed to setSinkId on ringtoneAudio", e);
        }
    }
}

// Watch for output device changes and apply
watch(() => store.selectedOutputDeviceId, (newId) => {
    applyOutputDevice(newId);
});

// Directive for setSinkId (output device selection)
const vSinkId = {
    mounted: (el: HTMLMediaElement) => {
        if (store.selectedOutputDeviceId && (el as any).setSinkId) {
            (el as any).setSinkId(store.selectedOutputDeviceId).catch(() => {});
        }
    },
    updated: (el: HTMLMediaElement) => {
         if (store.selectedOutputDeviceId && (el as any).setSinkId) {
            (el as any).setSinkId(store.selectedOutputDeviceId).catch(() => {});
        }
    }
};

// UI Refs
// Timers & Channels
let durationTimer: ReturnType<typeof setInterval> | null = null;
const callDuration = ref(0);
let echoChannel: any = null;
let broadcastChannel: BroadcastChannel | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;

// ============================================================================
// Computed
// ============================================================================

const localHasVideo = computed(() => {
    if (!localStream.value) return false;
    return localStream.value.getVideoTracks().length > 0;
});

const stateLabel = computed(() => {
    switch (callState.value) {
        case "initializing":
            return "Preparing...";
        case "ringing":
            return "Waiting...";
        case "connecting":
            return "Connecting...";
        case "connected":
            return formattedDuration.value;
        case "ended":
            return "Call ended";
        case "error":
            return error.value || "Error";
        default:
            return "";
    }
});

// Settings Modal
const showSettings = ref(false);

const formattedDuration = computed(() => {
    const mins = Math.floor(callDuration.value / 60);
    const secs = callDuration.value % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
});



// UI Polish: Dynamic Layout Mode
const layoutMode = computed(() => {
    // If anyone (local or remote) is sharing screen -> Spotlight Mode
    if (isScreenSharing.value || store.remoteScreenStreams.size > 0) {
        return "spotlight";
    }
    return "grid";
});

const gridClass = computed(() => {
    if (layoutMode.value === "spotlight") return "layout-spotlight";
    
    // Standard Grid Logic
    const count = participants.value.length;
    if (count <= 1) return "grid-1-1";
    if (count === 2) return "grid-1-1"; // PiP logic handles this
    if (count <= 4) return "grid-2-2";
    return "grid-3-3";
});

// Helper: Get Initials from Name
function getInitials(name: string) {
    return name
        .split(" ")
        .map((n) => n[0])
        .join("")
        .substring(0, 2)
        .toUpperCase();
}

// Helper: Get Avatar Color based on name (consistent)
function getAvatarColor(name: string) {
    const colors = [
        "linear-gradient(135deg, #ef4444 0%, #b91c1c 100%)", // Red
        "linear-gradient(135deg, #f97316 0%, #c2410c 100%)", // Orange
        "linear-gradient(135deg, #eab308 0%, #a16207 100%)", // Yellow
        "linear-gradient(135deg, #22c55e 0%, #15803d 100%)", // Green
        "linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)", // Blue
        "linear-gradient(135deg, #a855f7 0%, #7e22ce 100%)", // Purple
        "linear-gradient(135deg, #ec4899 0%, #be185d 100%)", // Pink
    ];
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
}



// Helper: Check for Remote Audio (Mute State)
function remoteHasAudio(publicId: string) {
    const stream = store.remoteStreams.get(publicId);
    return stream && stream.getAudioTracks().length > 0 && stream.getAudioTracks()[0].enabled;
}

const toggleChat = () => {
    console.log("[Call] Toggle Chat not implemented yet.");
    // alert("Chat coming soon!"); // visible feedback
};

const previewRemoteName = computed(() => {
    if (callData.value?.remoteUser) return callData.value?.remoteUser.name;
    return "Group Call";
});

// ============================================================================
// Watchers
// ============================================================================

// Local stream handling is now unified via v-src-object directive or ref in template

async function acquireMedia(): Promise<MediaStream | null> {
    if (!callData.value) return null;
    const type = callData.value.callType;
    console.log("[Call] acquireMedia, type:", type);

    try {
        if (type === "video") {
            try {
                // Pre-check for camera availability
                const devices = await navigator.mediaDevices.enumerateDevices();
                const hasCamera = devices.some(
                    (device) => device.kind === "videoinput",
                );

                if (!hasCamera) {
                    console.warn("[Call] No camera found on this device.");
                    videoFallback.value = true;
                } else {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        audio: true,
                        video: {
                            width: { ideal: 1280 },
                            height: { ideal: 720 },
                            facingMode: "user",
                        },
                    });
                    localStream.value = stream;

                    // Apply default video state (Off)
                    if (isCameraOff.value) {
                         stream.getVideoTracks().forEach(t => t.enabled = false);
                    }

                    console.log("[Call] Local media acquired:", {
                        audio:
                            stream.getAudioTracks().length > 0 ? "YES" : "NO",
                        video:
                            stream.getVideoTracks().length > 0 ? "YES" : "NO",
                    });

                    return stream;
                }
            } catch (e) {
                console.warn(
                    "[Call] Camera access error or unavailable, fallback to audio",
                    e,
                );
                videoFallback.value = true;
            }
        }

        // Audio Only or Fallback
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: false,
        });
        localStream.value = stream;
        isCameraOff.value = true; // Force camera off state UI

        console.log("[Call] Local media acquired:", {
            audio: stream.getAudioTracks().length > 0 ? "YES" : "NO",
            video: "NO (Audio-only mode)",
        });

        return stream;
    } catch (e: any) {
        console.error("[Call] Media acquisition failed:", e);
        error.value = "Microphone access denied.";
        callState.value = "error";
        return null;
    }
}

// ============================================================================
// SDP Munging
// ============================================================================

function mungeSdp(sdp: string): string {
    if (!sdp) return sdp;
    try {
        // Bit-Perfect Peace Normalizer:
        // 1. Removes ONLY the bugged max-message-size line.
        // 2. Preserves the original dialect, order, and spacing of all other lines.
        // 3. Ensures clean CRLF line endings to satisfy strict parsers.
        return (
            sdp
                .split(/\r?\n/)
                .map((l) => l.trim())
                .filter(
                    (l) => l.length > 0 && !l.includes("a=max-message-size:"),
                )
                .join("\r\n") + "\r\n"
        );
    } catch (e) {
        console.warn("[Call] SDP munging failed, fallback to raw", e);
        return sdp;
    }
}

function trace(area: string, message: string, data?: any) {
    const timestamp = new Date().toISOString().split("T")[1].split(".")[0];
    console.info(`[RTC-TRACE][${timestamp}][${area}] ${message}`, data || "");
}

// ============================================================================
// WebRTC (Mesh)
// ============================================================================

async function joinCall() {
    console.log("[Call] User clicked JOIN");

    const stream = await acquireMedia();
    if (!stream) return;

    hasJoined.value = true;
    stopRingtone();

    if (!callData.value) return;

    try {
        // 0. Fetch ICE credentials (TURN/STUN) for NAT traversal
        try {
            const turnData = await videoCallService.getTurnCredentials(
                callData.value.chatId,
            );
            iceServers.value = turnData.ice_servers;
            console.log(
                "[Call] ICE Servers configured:",
                iceServers.value.length,
            );
        } catch (e) {
            console.warn(
                "[Call] Failed to fetch TURN credentials, using defaults",
                e,
            );
        }

        // 1. Tell API we are joining
        const joinResponse = await videoCallService.joinCall(
            callData.value.chatId,
            callData.value.callId,
        );

        console.log("[Call] Joined. Response:", joinResponse);
        const {
            participants: currentParticipants,
            mode,
            app_id,
        } = joinResponse;

        callMode.value = mode || "mesh";
        sfuAppId.value = app_id || null;

        // 2. Normalize and initialize participants list
        const selfId = callData.value?.selfPublicId?.toLowerCase();
        participants.value = currentParticipants.map((p: any) => {
            const pId = (p.public_id || p.publicId || "").toLowerCase();
            const isSelf = pId === selfId;
            return {
                publicId: pId,
                name: isSelf ? "Me" : p.name,
                avatar: p.avatar_thumb_url || p.avatar,
                isSelf,
            };
        });

        // 3. Connect to existing participants
        const others = participants.value.filter((p) => !p.isSelf);

        if (callMode.value === "mesh") {
            console.log("[Call] Initializing MESH mode");
            for (const p of others) {
                // DETERMINISTIC: Only the user with the "Higher" string ID initiates.
                // This prevents "Glare" where both sides send an offer at the same time.
                const isInitiator = selfId > p.publicId;
                createPeer(p.publicId, isInitiator, stream);
            }
            isTransportReady.value = true;
        } else {
            console.log("[Call] Initializing SFU mode via Cloudflare");
            await joinSFU(stream);
            isTransportReady.value = true;
        }

        // 4. Set state
        callState.value = "connected"; // We are "in" the call room
        hasJoined.value = true;
        postToParent({ type: "state", state: "connected" });
        startDurationTimer();
        stopRingtone();

        // 5. Replay pending signals
        // a) From sessionStorage (signals received before popup opened)
        if (
            callData.value.pendingSignals &&
            callData.value.pendingSignals.length > 0
        ) {
            console.log(
                `[Call] Replaying ${callData.value.pendingSignals.length} signals from sessionStorage`,
            );
            for (const sig of callData.value.pendingSignals) {
                handleSignal(sig);
            }
        }

        // b) From runtime buffer (signals received while in lobby)
        if (pendingSignalsBuffer.value.length > 0) {
            console.log(
                `[Call] Replaying ${pendingSignalsBuffer.value.length} signals from runtime buffer`,
            );
            const signals = [...pendingSignalsBuffer.value];
            pendingSignalsBuffer.value = []; // Clear before processing to avoid loops
            for (const sig of signals) {
                handleSignal(sig);
            }
        }

        // 6. Start local audio analysis for "talking indicator"
        if (localStream.value) {
            startAudioAnalysis(selfId, localStream.value);
        }
    } catch (err) {
        console.error("[Call] Join failed:", err);
        handleCallFailed();
    }
}

function createPeer(
    targetPublicId: string,
    initiator: boolean,
    stream: MediaStream,
) {
    const normalizedTargetId = targetPublicId.toLowerCase();
    if (peers.has(normalizedTargetId)) return;

    console.log(
        `[Call] Creating peer for ${normalizedTargetId} (initiator: ${initiator})`,
    );

    const peer = new Peer({
        initiator,
        stream,
        trickle: true,
        sdpTransform: (sdp) => mungeSdp(sdp),
        config: {
            iceServers:
                iceServers.value.length > 0 ? iceServers.value : undefined,
        },
    });

    // Debug connection state
    // @ts-ignore
    const pc = peer._pc as RTCPeerConnection;
    if (pc) {
        pc.oniceconnectionstatechange = () => {
            console.log(
                `[Call] ICE State for ${normalizedTargetId}: ${pc.iceConnectionState}`,
            );
        };
        pc.onconnectionstatechange = () => {
            console.log(
                `[Call] Connection State for ${normalizedTargetId}: ${pc.connectionState}`,
            );
        };
    }

    peer.on("signal", (signal) => {
        // Targeted signal
        videoCallService.sendSignal(
            callData.value!.chatId,
            callData.value!.callId,
            "signal",
            signal as any,
            normalizedTargetId,
        );
    });

    peer.on("stream", (remoteStream) => {
        console.log(`[Call] Stream from ${normalizedTargetId}`, {
            audio: remoteStream.getAudioTracks().length,
            video: remoteStream.getVideoTracks().length,
            active: remoteStream.active,
        });

        // Detailed track logging
        remoteStream.getTracks().forEach((track) => {
            console.log(
                `[Call] Remote track from ${normalizedTargetId}: ${track.kind} (${track.id}) enabled=${track.enabled}`,
            );
            track.onmute = () =>
                console.warn(
                    `[Call] Remote ${track.kind} track from ${normalizedTargetId} MUTED (data flow stopped)`,
                );
            track.onunmute = () =>
                console.log(
                    `[Call] Remote ${track.kind} track from ${normalizedTargetId} UNMUTED (data flow resumed)`,
                );
        });

        // HEURISTIC: In Mesh, main stream has audio+video, screen share has video ONLY.
        const hasVideo = remoteStream.getVideoTracks().length > 0;
        const hasAudio = remoteStream.getAudioTracks().length > 0;

        if (hasVideo && !hasAudio) {
            console.log(
                `[Call] Mesh: Detected SCREEN stream from ${normalizedTargetId}`,
            );
            store.addRemoteScreenStream(normalizedTargetId, remoteStream);
        } else {
            console.log(
                `[Call] Mesh: Detected MAIN stream from ${normalizedTargetId}`,
            );
            store.addRemoteStream(normalizedTargetId, remoteStream);
            startAudioAnalysis(normalizedTargetId, remoteStream);
        }
    });

    peer.on("error", (err) => {
        console.error(`[Call] Peer error ${normalizedTargetId}:`, err);
    });

    peer.on("close", () => {
        console.log(`[Call] Peer closed ${normalizedTargetId}`);
        peers.delete(normalizedTargetId);
        store.removeRemoteStream(normalizedTargetId);
        store.removeRemoteScreenStream(normalizedTargetId);
    });

    peers.set(normalizedTargetId, peer);
}

// ============================================================================
// Signal Handling
// ============================================================================

const pendingSignalsBuffer = ref<any[]>([]);

async function handleSignal(event: any) {
    const senderId = (
        event.sender_public_id ||
        event.senderPublicId ||
        ""
    ).toLowerCase();
    const targetId = (
        event.target_public_id ||
        event.targetPublicId ||
        ""
    ).toLowerCase();
    const selfId = (callData.value?.selfPublicId || "").toLowerCase();

    if (senderId === selfId) {
        console.log(
            `[Call] Ignoring self-signal from ${senderId} (I am ${selfId})`,
        );
        return;
    }

    // In mesh, signals MUST be targeted or we ignore them for safety
    if (targetId && targetId !== selfId) {
        console.log(
            `[Call] Ignoring signal targeted at ${targetId} (I am ${selfId})`,
        );
        return;
    }

    // WAIT for media and transport readiness (PC + Session ID)
    if (!isTransportReady.value || !hasJoined.value || !localStream.value) {
        trace(
            "SIGNAL",
            `Buffering signal from ${senderId} - joining or transport not ready`,
        );
        pendingSignalsBuffer.value.push(event);
        return;
    }

    const signal = event.signal_data;

    // 1. Robust Deduplication check (full fingerprint)
    // We stringify the WHOLE signal but only keep a rolling hash of the last 1000
    const signalId = JSON.stringify(signal) + senderId;
    if (processedSignals.has(signalId)) return;
    processedSignals.add(signalId);

    // Management: keep set size manageable
    if (processedSignals.size > 500) {
        const first = processedSignals.values().next().value;
        if (first !== undefined) processedSignals.delete(first);
    }

    // Bidirectional Sanitization: clean up both incoming and outgoing SDPs
    // to protect against "Invalid SDP line" errors (max-message-size, sctp-port).
    if (signal.sdp) signal.sdp = mungeSdp(signal.sdp);

    // SFU: Remote Session Registration & Track Pulling
    if (
        signal.type === "sfu-session-ready" ||
        signal.type === "sfu-media-ready"
    ) {
        trace("SIGNAL", `Received ${signal.type} from ${senderId}`, {
            sessionId: signal.sessionId,
            audio: signal.audioMid,
            video: signal.videoMid,
        });

        remoteSfuSessions.set(senderId, signal.sessionId);

        // Persist MIDs for pull Participant tracks if provided
        if (signal.audioMid || signal.videoMid) {
            remoteSfuTracks.set(senderId, {
                audioMid: signal.audioMid,
                videoMid: signal.videoMid,
            });
        }

        pullParticipantTracks(
            senderId,
            signal.sessionId,
            signal.audioMid,
            signal.videoMid,
        );
        return;
    }

    // SFU Screen Share Signaling
    if (signal.type === "sfu-screen-share-started") {
        trace(
            "SIGNAL",
            `Received screen-share-started from ${senderId}`,
            signal,
        );
        pullSFURemoteScreen(senderId, signal.mid, signal.sessionId);
        return;
    }
    if (signal.type === "sfu-screen-share-stopped") {
        trace("SIGNAL", `Received screen-share-stopped from ${senderId}`);
        store.removeRemoteScreenStream(senderId);
        return;
    }

    // Get or Create Peer (MESH ONLY)
    if (callMode.value === "mesh") {
        let peer = peers.get(senderId);

        if (!peer) {
            // Deterministic initiation: prevent both sides from offering at once
            // If we see an offer, we respond.
            // If we see an answer but don't have a peer, something is wrong or we joined late.
            if (signal.type === "offer") {
                console.log(
                    `[Call] Received offer from ${senderId}, creating responder peer`,
                );
                createPeer(senderId, false, localStream.value!);
                peer = peers.get(senderId);
            } else if (signal.type === "ice-candidate") {
                console.warn(
                    `[Call] Received candidate from unknown peer ${senderId}, ignoring`,
                );
                return;
            } else {
                console.warn(
                    `[Call] Received ${signal.type} from unknown peer ${senderId}`,
                );
                return;
            }
        }

        // Handle Glare: if we receive an offer while we are in 'have-local-offer' state
        // @ts-ignore
        const pc = peer._pc as RTCPeerConnection;
        if (signal.type === "offer" && pc && pc.signalingState !== "stable") {
            const isPolite = selfId < senderId;
            if (!isPolite) {
                console.log(
                    `[Call] Glare detected with ${senderId}. We are impolite, ignoring their offer.`,
                );
                return;
            }
            console.log(
                `[Call] Glare detected with ${senderId}. We are polite, rollback and accept their offer.`,
            );
            try {
                await pc.setLocalDescription({ type: "rollback" } as any);
            } catch (e) {
                console.warn("[Call] Rollback failed", e);
            }
        }

        try {
            peer?.signal(signal);
        } catch (e) {
            console.error(`[Call] Error signaling peer ${senderId}:`, e);
        }
    } else {
        trace(
            "SIGNAL",
            `Ignoring MESH signal ${signal.type} from ${senderId} (currently in SFU mode)`,
        );
    }
}

async function pullSFURemoteScreen(
    participantPublicId: string,
    mid: string,
    remoteSessionId?: string,
) {
    if (callMode.value !== "sfu" || !sfuPc) return;

    // AVOID BLOAT
    if (participantTransceivers.get(participantPublicId)?.screenMid === mid) {
        console.log(
            `[SFU] Already have screen share mid ${mid} for ${participantPublicId}`,
        );
        return;
    }

    // Resolve sessionId
    const targetSessionId =
        remoteSessionId || remoteSfuSessions.get(participantPublicId);
    if (!targetSessionId) {
        console.warn(
            `[SFU] Cannot pull screen for ${participantPublicId}: session ID unknown`,
        );
        return;
    }

    // 0. Retry Logic (Non-blocking)
    const currentAttempts =
        (screenPullAttempts.get(participantPublicId) || 0) + 1;
    screenPullAttempts.set(participantPublicId, currentAttempts);

    // Backoff: 1s, 1.5s, 2s, 3s, 5s
    const retryDelays = [1000, 1500, 2000, 3000, 5000];
    if (currentAttempts > retryDelays.length) {
        console.error(
            `[SFU] Failed to pull screen from ${participantPublicId} after ${retryDelays.length} attempts.`,
        );
        screenPullAttempts.delete(participantPublicId);
        return;
    }

    const trackReqs = [
        {
            location: "remote",
            sessionId: targetSessionId,
            trackName: "screen",
            // Removed MID to rely on trackName matching
        },
    ];

    return runInSFUQueue(async () => {
        if (!sfuPc || !sfuSessionId.value) return;

        try {
            console.log(
                `[SFU] Attempt ${currentAttempts}: Handshaking screen for ${participantPublicId}...`,
            );

            // 1. Pre-create transceiver to lock MID (Match Backup Logic)
            let transceiver = sfuPc
                .getTransceivers()
                .find(
                    (t) =>
                        t.direction === "recvonly" &&
                        sfuTransceiverMap.get(t)?.participantId ===
                            participantPublicId &&
                        sfuTransceiverMap.get(t)?.trackName === "screen",
                );

            if (!transceiver) {
                transceiver = sfuPc.addTransceiver("video", {
                    direction: "recvonly",
                });
                sfuTransceiverMap.set(transceiver, {
                    participantId: participantPublicId,
                    trackName: "screen",
                });
            }

            // 2. Request tracks (Using trackName only)
            const tracksRes = await videoCallService.sfuSessionTracks(
                callData.value!.chatId,
                sfuSessionId.value!,
                trackReqs,
                undefined,
            );

            const foundAny =
                Array.isArray(tracksRes.tracks) &&
                tracksRes.tracks.some((t: any) => t.mid && !t.errorCode);

            // Cloudflare Specific Error Handling: not_found_track_error
            // This happens when we ask for a track that isn't fully published yet
            const hasNotFoundError =
                Array.isArray(tracksRes.tracks) &&
                tracksRes.tracks.some(
                    (t: any) =>
                        t.errorCode === "not_found_track_error" ||
                        t.errorCode === "internal_error",
                );

            if (foundAny) {
                console.log(
                    `[SFU] Screen pull success on attempt ${currentAttempts} for ${participantPublicId}`,
                );
                screenPullAttempts.delete(participantPublicId);

                if (tracksRes.sessionDescription) {
                    console.log(
                        `[SFU] Processing Server Offer for screen track from ${participantPublicId}`,
                    );
                    await sfuPc!.setRemoteDescription(
                        new RTCSessionDescription(tracksRes.sessionDescription),
                    );

                    // Mapping: Use MIDs from response logic
                    if (Array.isArray(tracksRes.tracks)) {
                        tracksRes.tracks.forEach((track: any) => {
                            if (track.mid) {
                                midToParticipantMap.set(
                                    track.mid,
                                    `${participantPublicId}:screen`,
                                );
                                const t = sfuPc!
                                    .getTransceivers()
                                    .find((tr) => tr.mid === track.mid);
                                if (t) {
                                    sfuTransceiverMap.set(t, {
                                        participantId: participantPublicId,
                                        trackName: "screen",
                                    });
                                }

                                // Update participant state
                                const existing =
                                    participantTransceivers.get(
                                        participantPublicId,
                                    ) || {};
                                participantTransceivers.set(
                                    participantPublicId,
                                    { ...existing, screenMid: track.mid },
                                );
                            }
                        });
                    }

                    const answer = await sfuPc!.createAnswer();
                    await sfuPc!.setLocalDescription(answer);
                    await videoCallService.sfuSessionRenegotiate(
                        callData.value!.chatId,
                        sfuSessionId.value!,
                        mungeSdp(answer.sdp!),
                        "answer",
                        "PUT",
                    );
                }
            } else {
                console.warn(
                    `[SFU] Screen pull attempt ${currentAttempts} failed (no valid tracks).${
                        hasNotFoundError ? " (Tracks Not Found Yet)" : ""
                    } Rescheduling...`,
                );
                const delay = retryDelays[currentAttempts - 1] || 1000;
                setTimeout(
                    () =>
                        pullSFURemoteScreen(
                            participantPublicId,
                            mid,
                            remoteSessionId,
                        ),
                    delay,
                );
            }
        } catch (err: any) {
            console.error(
                `[SFU] Screen pull attempt ${currentAttempts} failed (exception).`,
                err,
            );
            if (err.response?.status === 406) {
                await handleSFU406Rescue();
            }
            const delay = retryDelays[currentAttempts - 1] || 1000;
            setTimeout(
                () =>
                    pullSFURemoteScreen(
                        participantPublicId,
                        mid,
                        remoteSessionId,
                    ),
                delay,
            );
        }
    });
}

// Modify handleParticipantJoined to support SFU pulling
function handleParticipantJoined(event: any) {
    const publicId = (
        event.participant_public_id ||
        event.participant_publicId ||
        ""
    ).toLowerCase();
    const selfId = (callData.value?.selfPublicId || "").toLowerCase();

    if (publicId === selfId) {
        console.log(`[Call] Ignoring self-join event for ${publicId}`);
        return;
    }

    console.log("[Call] New participant joined:", event);

    // Add to list
    const exists = participants.value.find(
        (p) => p.publicId.toLowerCase() === publicId,
    );
    if (!exists) {
        participants.value.push({
            publicId: publicId,
            name: event.participant_name,
            avatar: event.participant_avatar,
            isSelf: false,
        });
    }

    // MESH: Negotiate with the new person
    if (callMode.value === "mesh") {
        const isInitiator = selfId > publicId;
        if (!peers.has(publicId)) {
            trace(
                "MESH",
                `Initiating mesh peer for ${publicId} (initiator: ${isInitiator})`,
            );
            createPeer(publicId, isInitiator, localStream.value!);
        }
    } else {
        trace(
            "SFU",
            `Participant ${publicId} joined. Ignoring MESH negotiation as we are in SFU mode.`,
        );
    }

    // SFU: Negotiate with the new person
    if (callMode.value === "sfu") {
        // We can't pull yet, we must wait for their 'sfu-session-ready' signal.
        // BUT we should send OUR session ID to the new joiner if we have one.
        if (sfuSessionId.value) {
            const trackObjects = sfuPc
                ?.getTransceivers()
                .filter((t) => t.sender.track)
                .map((t) => ({
                    location: "local",
                    mid: t.mid,
                    trackName: t.sender.track!.kind,
                }));

            const audioMid = trackObjects?.find(
                (t) => t.trackName === "audio",
            )?.mid;
            const videoMid = trackObjects?.find(
                (t) => t.trackName === "video",
            )?.mid;

            console.log(
                `[SFU] Sending our media info to new joiner ${publicId}: audio=${audioMid}, video=${videoMid}`,
            );
            videoCallService
                .sendSignal(
                    callData.value!.chatId,
                    callData.value!.callId,
                    "signal",
                    {
                        type: "sfu-media-ready",
                        sessionId: sfuSessionId.value,
                        audioMid,
                        videoMid,
                    } as any,
                    publicId,
                )
                .catch(() => {});
        }

        // If WE are sharing screen, notify the NEW participant
        if (isScreenSharing.value && sfuScreenMid.value) {
            console.log(
                `[SFU] Notifying new joiner ${publicId} about our active screen share`,
            );
            videoCallService
                .sendSignal(
                    callData.value!.chatId,
                    callData.value!.callId,
                    "signal",
                    {
                        type: "sfu-screen-share-started",
                        mid: sfuScreenMid.value,
                        sessionId: sfuSessionId.value, // Also provide sessionId here just in case
                    },
                    publicId,
                )
                .catch(() => {});
        }
    }
}

function handleParticipantLeft(event: any) {
    const publicId = (
        event.participant_public_id ||
        event.participant_publicId ||
        ""
    ).toLowerCase();
    console.log("[Call] Participant left:", publicId);

    // Remove from list
    participants.value = participants.value.filter(
        (p) => p.publicId !== publicId,
    );

    // Peer cleanup handled by peer.on('close') or explicit destroy
    const peer = peers.get(publicId);
    if (peer) {
        peer.destroy();
        peers.delete(publicId);
        store.removeRemoteStream(publicId);
    }

    // SFU cleanup
    store.removeRemoteScreenStream(publicId);
    audioAnalysers.forEach((_, id) => {
        if (id.startsWith(publicId)) stopAudioAnalysis(id);
    });

    stopAudioAnalysis(publicId);
}

function handleCallEndedEvent(event: any) {
    // This is the global "CallEnded" (force close for everyone) or strict 1:1 end
    // For hybrid group calls, we might not use this much, relying on ParticipantLeft
    console.log("[Call] CallEnded event received");
    callState.value = "ended";
    postToParent({ type: "state", state: "ended", reason: event.reason });
    cleanup();
}

function setupEcho() {
    const echo = startEcho();
    if (!echo || !callData.value) return;

    // Call signaling is on the chat channel (dm.X or group.X)
    // We need to know which one.
    // The previous implementation used dm.X hardcoded.
    // We should infer from chatId? Or pass chatType in callData?
    // callData doesn't have chatType. We can try both or pass it.
    // Let's assume passed in sessionData or we can deduce.
    // Actually, `startCall` in useVideoCall.ts didn't put chatType in sessionStorage.
    // We can assume if remoteUser is generic "Group", it's group?
    // Safer to just subscribe to both prefixes or pass it.
    // I will simply try to subscribe to the channel that matches the ID.
    // Actually, `useVideoCall` knows `chat_type` from the event.
    // Let's fix `startCall` to pass `chatType`.
    // For now, I'll try to guess or use a wildcard approach if I could (Echo doesn't support).
    // Let's update `useVideoCall` to pass `chatType`.

    // Assuming we passed `chatId`... wait, `chatId` is the `public_id`.
    // The channel is `dm.{public_id}` or `group.{public_id}`.
    // I'll try `group.` first if it looks like a group call?
    // Or just subscribe to `private-dm.x` AND `private-group.x`? No, Echo handles prefix.

    // HACK: I will guess based on callData.remoteUser.

    // Better fix: update `useVideoCall.ts` to store `chatType` in sessionStorage.
    // But since I can't do that concurrently effectively without risking race,
    // I will try to subscribe to `dm.{id}`. If it fails (auth), try `group.{id}`.
    // Actually, `Echo` doesn't throw on subscribe.

    // Let's assume for now 1:1 is `dm`.
    // Wait, the `videocall.service` endpoints use `chatId`.
    // The backend broadcasts on `dm` or `group` based on chat type.

    // I'll just assume `dm` for now to match legacy,
    // BUT we must fix this to support groups.
    // I will add `chatType` to `callData` interface and try to read it.
    // If missing, default to `dm`.

    const prefix = callData.value.chatType === "group" ? "group" : "dm";
    const channelName = `${prefix}.${callData.value.chatId}`;

    echoChannel = echo.private(channelName);
    echoChannel
        .listen(".CallSignal", (event: any) => handleSignal(event))
        .listen(".CallParticipantJoined", (event: any) =>
            handleParticipantJoined(event),
        )
        .listen(".CallParticipantLeft", (event: any) =>
            handleParticipantLeft(event),
        )
        .listen(".CallEnded", (event: any) => handleCallEndedEvent(event));
}

// ============================================================================
// Controls
// ============================================================================

function toggleMute() {
    isMuted.value = !isMuted.value;
    localStream.value
        ?.getAudioTracks()
        .forEach((t) => (t.enabled = !isMuted.value));
}

function toggleCamera() {
    isCameraOff.value = !isCameraOff.value;
    localStream.value
        ?.getVideoTracks()
        .forEach((t) => (t.enabled = !isCameraOff.value));
}

function remoteHasVideo(participantId: string): boolean {
    const stream = store.remoteStreams.get(participantId);
    if (!stream) return false;
    return stream.getVideoTracks().length > 0 && stream.getVideoTracks()[0].enabled;
}

async function toggleScreenShare() {
    if (isScreenSharing.value) {
        stopScreenShare();
        return;
    }

    try {
        console.log("[Call] Requesting screen share...");
        const stream = await (navigator.mediaDevices as any).getDisplayMedia({
            video: { cursor: "always" },
            audio: false,
        });

        isScreenSharing.value = true;
        screenStream.value = stream;

        // Listen for user clicking "Stop Sharing" in browser UI
        stream.getVideoTracks()[0].onended = () => {
            console.log("[Call] Screen share ended by user via browser UI");
            stopScreenShare();
        };

        if (callMode.value === "mesh") {
            // Replace tracks for all peers
            const videoTrack = stream.getVideoTracks()[0];
            peers.forEach((peer) => {
                // @ts-ignore
                const pc = peer._pc as RTCPeerConnection;
                const senders = pc.getSenders();
                const videoSender = senders.find(
                    (s: any) => s.track?.kind === "video",
                );

                if (videoSender) {
                    console.log(
                        "[Call] Mesh: Replacing existing video track with screen track",
                    );
                    videoSender.replaceTrack(videoTrack);
                } else {
                    console.log(
                        "[Call] Mesh: Adding new screen track (initially audio-only)",
                    );
                    peer.addTrack(videoTrack, stream); // Mesh: Use separate stream object to avoid ID collisions
                }
            });
        } else {
            // SFU: Add screen track
            await publishSFUScreenTrack(stream);
        }
    } catch (err) {
        console.error("[Call] Screen share failed:", err);
    }
}

function stopScreenShare() {
    if (!isScreenSharing.value) return;

    console.log("[Call] Stopping screen share...");
    screenStream.value?.getTracks().forEach((t) => t.stop());
    screenStream.value = null;
    isScreenSharing.value = false;

    if (callMode.value === "sfu") {
        stopSFUScreenShare();
    }

    // Restore camera track
    if (callMode.value === "mesh") {
        peers.forEach((peer) => {
            // @ts-ignore
            const pc = peer._pc as RTCPeerConnection;
            const senders = pc.getSenders();
            const videoSender = senders.find(
                (s: any) => s.track?.kind === "video",
            );

            if (videoSender) {
                const cameraTrack = localStream.value?.getVideoTracks()[0];
                if (cameraTrack) {
                    console.log("[Call] Mesh: Restoring camera track");
                    videoSender.replaceTrack(cameraTrack);
                } else {
                    console.log(
                        "[Call] Mesh: Removing screen track (no camera fallback)",
                    );
                    peer.removeTrack(videoSender.track!, localStream.value!);
                }
            }
        });
    } else {
        // SFU Handle restore
        if (sfuPc && sfuSessionId.value) {
            const cameraTrack = localStream.value?.getVideoTracks()[0];
            if (cameraTrack) {
                const transceivers = sfuPc.getTransceivers();
                const videoTransceiver = transceivers.find(
                    (t) => t.sender.track?.kind === "video",
                );
                if (videoTransceiver) {
                    console.log("[SFU] Restoring camera track");
                    videoTransceiver.sender.replaceTrack(cameraTrack);
                }
            }
        }
    }
}

// ============================================================================
// Cloudflare SFU Logic
// ============================================================================

let sfuPc: RTCPeerConnection | null = null;

// Directive for volume (sync with Pinia store)
const vVolume = {
    updated: (el: HTMLMediaElement, binding: any) => {
        const publicId = binding.value;
        if (!publicId) return;
        const vol = store.remoteVolumes.get(publicId) ?? 100;
        // Multiply individual volume by global volume (0-1)
        el.volume = (vol / 100) * store.globalVolume;
    },
    mounted: (el: HTMLMediaElement, binding: any) => {
        const publicId = binding.value;
        if (!publicId) return;

        // Sync initial volume (default 100)
        const vol = store.remoteVolumes.get(publicId) ?? 100;
        el.volume = (vol / 100) * store.globalVolume;

        // Watch for store changes
        watch(
            () => store.remoteVolumes.get(publicId),
            (newVol) => {
                el.volume = ((newVol ?? 100) / 100) * store.globalVolume;
            },
        );
        // Also watch for global volume changes
        watch(
            () => store.globalVolume,
            (newGlobalVol) => {
                const currentVol = store.remoteVolumes.get(publicId) ?? 100;
                el.volume = (currentVol / 100) * newGlobalVol;
            },
        );
    },
};

// normalizeSdp has been replaced by mungeSdp for bit-perfect cross-browser compatibility

async function joinSFU(stream: MediaStream) {
    return runInSFUQueue(async () => {
        console.log(
            "[SFU] Initializing RTCPeerConnection for Cloudflare Calls",
        );

        sfuPc = new RTCPeerConnection({
            iceServers:
                iceServers.value.length > 0
                    ? iceServers.value
                    : [{ urls: "stun:stun.cloudflare.com:3478" }],
            bundlePolicy: "max-bundle",
        });

        // 0. Pre-create ICE Promise to avoid race conditions
        // We must start listening immediately, before any async API calls.
        const iceConnectedPromise = new Promise((resolve, reject) => {
            const timeout = setTimeout(
                () => reject("SFU Connect Timeout"),
                15000, // Increased to 15s to allow for Double Tap delay
            );
            // Monitor ICE state
            const checkState = () => {
                if (
                    sfuPc!.iceConnectionState === "connected" ||
                    sfuPc!.iceConnectionState === "completed"
                ) {
                    clearTimeout(timeout);
                    resolve(true);
                }
            };
            
            sfuPc!.oniceconnectionstatechange = checkState;
            // Check immediately in case it's already done (unlikely but safe)
            checkState();
        });

        // 1. Add local senders
        stream.getTracks().forEach((track) => {
            console.log(`[SFU] Adding local ${track.kind} track to session`);
            sfuPc!.addTransceiver(track, { direction: "sendonly" });
        });

        // 2. Initial Offer to establish session and name tracks
        const offer = await sfuPc.createOffer();
        await sfuPc.setLocalDescription(offer);

        const trackObjects = sfuPc
            .getTransceivers()
            .filter((t) => t.sender.track)
            .map((t) => ({
                location: "local",
                mid: t.mid,
                trackName: t.sender.track!.kind, // "audio" or "video"
            }));

        console.log(
            "[SFU] Creating new session via backend proxy...",
            trackObjects,
        );
        const sessionRes = await videoCallService.sfuSessionNew(
            callData.value!.chatId,
            mungeSdp(sfuPc.localDescription!.sdp!),
            trackObjects,
        );

        if (sessionRes.sessionDescription) {
            await sfuPc.setRemoteDescription(
                new RTCSessionDescription(sessionRes.sessionDescription),
            );
        }

        if (sessionRes.sessionId) {
            sfuSessionId.value = sessionRes.sessionId;
            console.log("[SFU] Session established:", sessionRes.sessionId);

            // Double Tap: Explicitly register tracks to ensure they are active
            // Fire-and-forget or await? We should await to ensure state is consistent,
            // BUT we must not block ICE listeners (which we fixed by moving the promise up).
            console.log("[SFU] Explicitly registering tracks via sfuSessionTracks (Double Tap)...");
            try {
                const tracksRes = await videoCallService.sfuSessionTracks(
                    callData.value!.chatId,
                    sfuSessionId.value!,
                    trackObjects,
                    undefined,
                );
                 if (tracksRes.sessionDescription) {
                    console.log("[SFU] Applying Double Tap SDP Answer");
                    await sfuPc!.setRemoteDescription(
                        new RTCSessionDescription(tracksRes.sessionDescription),
                    );
                }
            } catch (e) {
                 console.warn("[SFU] Double Tap track registration warning:", e);
                 // Non-fatal, continue
            }
        }

        // 3. Wait for ICE connection (using the pre-created promise)
        console.log("[SFU] Waiting for ICE connection...");
        await iceConnectedPromise;

        trace("JOIN", "Local tracks published", { trackObjects });

        // 5. SIGNAL READY (Now that tracks are safe and registered)
        if (sfuSessionId.value) {
            const audioMid = trackObjects.find(
                (t) => t.trackName === "audio",
            )?.mid;
            const videoMid = trackObjects.find(
                (t) => t.trackName === "video",
            )?.mid;

            console.log(
                `[SFU] Signaling sfu-media-ready: audio=${audioMid}, video=${videoMid}`,
            );

            videoCallService
                .sendSignal(
                    callData.value!.chatId,
                    callData.value!.callId,
                    "signal",
                    {
                        type: "sfu-media-ready",
                        sessionId: sfuSessionId.value,
                        audioMid,
                        videoMid,
                    } as any,
                )
                .catch(() => {});
        }

        // 4. Handle remote tracks (Subscribing)
        sfuPc.ontrack = (event) => {
            const track = event.track;
            const mid = event.transceiver.mid;
            trace("TRACK", `ontrack: ${track.kind}, mid: ${mid}`, {
                muted: track.muted,
                readyState: track.readyState,
            });

            // Resilient Track Activation: Handle immediate unmute or missing stream container
            const handleTrackActive = () => {
                let participantId = midToParticipantMap.get(mid!);

                // FALLBACK: Use transceiver-to-participant mapping if MID mapping failed (e.g. after rollback)
                if (!participantId) {
                    const assoc = sfuTransceiverMap.get(event.transceiver);
                    if (assoc) {
                        participantId =
                            assoc.trackName === "screen"
                                ? `${assoc.participantId}:screen`
                                : assoc.participantId;
                        console.log(
                            `[SFU] Identified mid ${mid} via transceiver association: ${participantId}`,
                        );
                    }
                }
                trace(
                    "TRACK",
                    `Active: ${track.kind} (${mid}) for ${participantId}`,
                    {
                        streams: event.streams.length,
                    },
                );

                if (participantId) {
                    // FALLBACK: If event.streams is empty, create a new stream from the
                    // track. This happens if Cloudflare sends tracks without grouping them
                    // into streams in the SDP, or if the browser treats them as such.
                    let stream = event.streams[0];
                    if (!stream) {
                         // Check if we already have a stream for this participant
                         // If not, create one.
                         // Actually, store.addRemoteStream can handle individual tracks if we manage the stream object.
                         // For now, let's create a synthetic stream.
                         stream = new MediaStream([track]);
                         console.log(`[SFU] Created synthetic stream for ${participantId} (${track.kind})`);
                    }
                    
                    if (track.kind === "video" && participantId.endsWith(":screen")) {
                        store.addRemoteScreenStream(participantId.replace(":screen", ""), stream);
                    } else {
                         // Main stream (audio/video)
                         // logic to merge tracks? store.addRemoteStream handles it?
                         store.addRemoteStream(participantId, stream);
                         startAudioAnalysis(participantId, stream);
                    }
                } else {
                    console.warn(
                        `[SFU] Could not find participant for mid ${mid}`,
                    );
                    trace("TRACK", "FAILED: Unknown MID", {
                        mid,
                        map: Object.fromEntries(midToParticipantMap),
                    });
                }
            };

            track.onunmute = handleTrackActive;
            if (!track.muted) {
                handleTrackActive();
            }
        };

        // 5. Initial Pull: We wait for 'sfu-session-ready' signals from others.
        // However, if we joined late, we might miss them.
        // The backend join response gives us participants list, but not their SFU IDs.
        // They will re-broadcast to us in handleParticipantJoined when they see us join.
    });
}

/* SFU Gated Rescue: Pulls pending offer from server on 406 */
/* SFU Reset: Force a new session on unrecoverable errors */
/* SFU Reset: Force a new session on unrecoverable errors */
/* SFU Reset: Force a new session on unrecoverable errors */
let isNegotiatingSFU = false;
let sfuGeneration = 0; // Generation counter to invalidate old tasks on reset

async function resetSFUSession() {
    if (isSFUResetting.value) return;
    isSFUResetting.value = true;
    sfuGeneration++; // Invalidate pending queue tasks

    console.log(
        `[SFU] Forcing session reset (Gen ${sfuGeneration}) due to unrecoverable error (406)...`,
    );

    try {
        // 1. Close existing connection
        if (sfuPc) {
            sfuPc.close();
            sfuPc = null;
        }
        sfuSessionId.value = null;

        // 2. Clear track state map
        participantTransceivers.clear();

        // 3. Stabilization delay
        await new Promise((resolve) => setTimeout(resolve, 1000));

        // 4. Re-join SFU
        if (localStream.value) {
            await joinSFU(localStream.value);
        }

        // 5. Re-publish screen if needed
        if (isScreenSharing.value && screenStream.value) {
            await publishSFUScreenTrack(screenStream.value);
        }

        console.log(
            "[SFU] Session reset complete. New Session ID:",
            sfuSessionId.value,
        );
    } catch (e) {
        console.error("[SFU] System reset failed", e);
    } finally {
        isSFUResetting.value = false;
    }
}

let lastSFUReset = 0;

/* SFU Gated Rescue: Force Reset on 406 */
async function handleSFU406Rescue() {
    const now = Date.now();
    if (now - lastSFUReset < 5000) {
        console.warn(
            "[SFU] Flattening 406 rescue loop - refusing to reset again so soon.",
        );
        return false; // Let the error propagate, don't loop
    }
    lastSFUReset = now;

    console.log("[SFU] 406 Answer Expected. Initiating Force Reset...");
    await resetSFUSession();
    return true;
}

async function triggerSFURenegotiation() {
    if (!sfuPc || !sfuSessionId.value || isSFUResetting.value) return;

    // Guard: Don't start a new offer if we are already negotiating
    if ((sfuPc.signalingState as string) !== "stable") {
        console.log(
            `[SFU] Signaling state is ${sfuPc.signalingState}, waiting for stability before renegotiating...`,
        );
        return;
    }

    return runInSFUQueue(async () => {
        if (isSFUResetting.value || sfuPc?.signalingState !== "stable") return;
        console.log("[SFU] Triggering session renegotiation...");
        try {
            const offer = await sfuPc!.createOffer();
            await sfuPc!.setLocalDescription(offer);

            const res = await videoCallService.sfuSessionRenegotiate(
                callData.value!.chatId,
                sfuSessionId.value!,
                mungeSdp(sfuPc!.localDescription!.sdp!),
                "offer",
                "PUT",
            );

            if (res.sessionDescription) {
                await sfuPc!.setRemoteDescription(
                    new RTCSessionDescription(res.sessionDescription),
                );
                console.log(
                    "[SFU] Renegotiation successful (Client-Initiated)",
                );
            }
        } catch (e: any) {
            console.warn("[SFU] Client-initiated renegotiation failed", e);

            if (e.response?.status === 406) {
                if (await handleSFU406Rescue()) {
                    triggerSFURenegotiation();
                    return;
                }
            }

            // Generic rollback for other errors or failed rescue
            if (
                sfuPc &&
                (sfuPc.signalingState as string) === "have-local-offer"
            ) {
                try {
                    await sfuPc.setLocalDescription({
                        type: "rollback",
                    } as any);
                    console.log("[SFU] Rolled back local offer after failure");
                } catch (rollbackErr) {
                    console.warn(
                        "[SFU] Rollback failed after renegotiate error",
                        rollbackErr,
                    );
                }
            }
        }
    });
}

let sfuNegotiationQueue = Promise.resolve();
// SFU Reliability Metrics
const participantPullAttempts = new Map<string, number>();
const screenPullAttempts = new Map<string, number>();
const midToParticipantMap = new Map<string, string>();

async function negotiateSession(
    localDescription: RTCSessionDescriptionInit | null,
) {
    if (!sfuPc || !sfuSessionId.value) return;

    try {
        let sdp = localDescription?.sdp
            ? mungeSdp(localDescription.sdp)
            : undefined;
        let type = localDescription?.type || "offer";

        const res = await videoCallService.sfuSessionRenegotiate(
            callData.value!.chatId,
            sfuSessionId.value!,
            sdp,
            type as any, // Cast to avoid RTCSdpType mismatch if it happens
            "PUT",
        );

        if (res.sessionDescription) {
            await sfuPc.setRemoteDescription(
                new RTCSessionDescription(res.sessionDescription),
            );

            if (res.sessionDescription.type === "offer") {
                const answer = await sfuPc.createAnswer();
                await sfuPc.setLocalDescription(answer);
                await negotiateSession(answer);
            }
        }
    } catch (e) {
        console.error("[SFU] Negotiation failed", e);
        throw e;
    }
}

async function performSFUNegotiation(
    localDescription: RTCSessionDescriptionInit | null,
) {
    return runInSFUQueue(() => negotiateSession(localDescription));
}

async function runInSFUQueue(fn: () => Promise<void>) {
    const currentGen = sfuGeneration;
    sfuNegotiationQueue = sfuNegotiationQueue.then(async () => {
        if (currentGen !== sfuGeneration) {
            console.log(
                `[SFU] Skipping queued task from generation ${currentGen} (current: ${sfuGeneration})`,
            );
            return;
        }

        // Jittered stabilization delay to avoid collisions (406 Answer Expected)
        const jitter = Math.floor(Math.random() * 200);
        await new Promise((r) => setTimeout(r, 200 + jitter));

        isNegotiatingSFU = true;
        try {
            await fn();
        } finally {
            isNegotiatingSFU = false;
        }
    });
    return sfuNegotiationQueue;
}

function startAudioAnalysis(id: string, stream: MediaStream) {
    const normalizedId = id.toLowerCase();
    if (audioAnalysers.has(normalizedId)) stopAudioAnalysis(normalizedId);

    if (stream.getAudioTracks().length === 0) return;

    try {
        const AudioContextClass =
            (window as any).AudioContext || (window as any).webkitAudioContext;
        const context = new AudioContextClass();
        const source = context.createMediaStreamSource(stream);
        const analyser = context.createAnalyser();
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = 0.8;
        source.connect(analyser);

        const dataArray = new Uint8Array(analyser.frequencyBinCount);

        const interval = setInterval(() => {
            analyser.getByteFrequencyData(dataArray);
            let volume = 0;
            for (let i = 0; i < dataArray.length; i++) {
                volume += dataArray[i];
            }
            const average = volume / dataArray.length;

            // Threshold: 15 is usually a good "talking" level. Adjust if too sensitive.
            if (average > 15) {
                talkingParticipants.add(normalizedId);
            } else {
                talkingParticipants.delete(normalizedId);
            }
        }, 100);

        audioAnalysers.set(normalizedId, {
            context,
            source,
            analyser,
            interval,
        });
    } catch (e) {
        console.warn(`[Audio] Analysis failed for ${normalizedId}`, e);
    }
}

function stopAudioAnalysis(id: string) {
    const normalizedId = id.toLowerCase();
    const entry = audioAnalysers.get(normalizedId);
    if (entry) {
        clearInterval(entry.interval);
        entry.context.close().catch(() => {});
        audioAnalysers.delete(normalizedId);
    }
    talkingParticipants.delete(normalizedId);
}

async function pullParticipantTracks(
    participantPublicId: string,
    remoteSessionId?: string,
    remoteAudioMid?: string,
    remoteVideoMid?: string,
) {
    if (!sfuPc || !sfuSessionId.value) return;

    // Resolve sessionId and MIDs from state if not provided (Rescue Path)
    const targetSessionId =
        remoteSessionId || remoteSfuSessions.get(participantPublicId);
    const persistedTracks = remoteSfuTracks.get(participantPublicId);

    const actualAudioMid = remoteAudioMid || persistedTracks?.audioMid;
    const actualVideoMid = remoteVideoMid || persistedTracks?.videoMid;

    if (!targetSessionId) {
        console.warn(
            `[SFU] Cannot pull tracks for ${participantPublicId}: session ID unknown yet`,
        );
        return;
    }

    // AVOID BLOAT
    if (participantTransceivers.has(participantPublicId)) {
        console.log(
            `[SFU] Already have transceivers for ${participantPublicId}, skipping redundant pull`,
        );
        return;
    }

    // 0. Retry Logic (Non-blocking)
    const currentAttempts =
        (participantPullAttempts.get(participantPublicId) || 0) + 1;
    participantPullAttempts.set(participantPublicId, currentAttempts);

    // Initial backoff: 1s, 1.5s, 2s, 3s, 5s
    const retryDelays = [1000, 1500, 2000, 3000, 5000];
    if (currentAttempts > retryDelays.length) {
        console.error(
            `[SFU] Failed to pull tracks for ${participantPublicId} after ${retryDelays.length} attempts. Giving up.`,
        );
        participantPullAttempts.delete(participantPublicId);
        return;
    }

    const trackReqs: any[] = [];
    // Flexible Pull: Request audio/video by NAME.
    // If the remote peer has them, Cloudflare will return them.
    // If not, we catch the error gracefully vs failing on a specific MID mismatch.
    if (actualAudioMid) {
        trackReqs.push({
            location: "remote",
            sessionId: targetSessionId,
            trackName: "audio",
        });
    } else {
        trackReqs.push({
            location: "remote",
            sessionId: targetSessionId,
            trackName: "audio",
        });
    }

    if (actualVideoMid) {
        trackReqs.push({
            location: "remote",
            sessionId: targetSessionId,
            trackName: "video",
        });
    } else {
        trackReqs.push({
            location: "remote",
            sessionId: targetSessionId,
            trackName: "video",
        });
    }

    // 1. QUEUE the handshake
    return runInSFUQueue(async () => {
        if (!sfuPc || !sfuSessionId.value) return;

        try {
            console.log(
                `[SFU] Attempt ${currentAttempts}: Handshaking tracks for ${participantPublicId}...`,
            );
            // 1. Pre-create transceivers to lock MIDs (Hybrid of Backup Logic + Server Flow)
            // This ensures we have a valid Transceiver -> Participant mapping BEFORE negotiation
            let audioTransceiver = sfuPc
                .getTransceivers()
                .find(
                    (t) =>
                        t.direction === "recvonly" &&
                        sfuTransceiverMap.get(t)?.participantId ===
                            participantPublicId &&
                        sfuTransceiverMap.get(t)?.trackName === "audio",
                );
            let videoTransceiver = sfuPc
                .getTransceivers()
                .find(
                    (t) =>
                        t.direction === "recvonly" &&
                        sfuTransceiverMap.get(t)?.participantId ===
                            participantPublicId &&
                        sfuTransceiverMap.get(t)?.trackName === "video",
                );

            if (!audioTransceiver) {
                audioTransceiver = sfuPc.addTransceiver("audio", {
                    direction: "recvonly",
                });
                sfuTransceiverMap.set(audioTransceiver, {
                    participantId: participantPublicId,
                    trackName: "audio",
                });
            }
            if (!videoTransceiver) {
                videoTransceiver = sfuPc.addTransceiver("video", {
                    direction: "recvonly",
                });
                sfuTransceiverMap.set(videoTransceiver, {
                    participantId: participantPublicId,
                    trackName: "video",
                });
            }

            // 2. Update requests with local MIDs
            const trackReqsWithMid = trackReqs.map((req) => {
                if (req.trackName === "audio")
                    return { ...req, mid: audioTransceiver!.mid || undefined };
                if (req.trackName === "video")
                    return { ...req, mid: videoTransceiver!.mid || undefined };
                return req;
            });

            const res = await videoCallService.sfuSessionTracks(
                callData.value!.chatId,
                sfuSessionId.value!,
                trackReqsWithMid,
                undefined, // Server-Initiated: No Client Offer
            );

            const foundAny =
                Array.isArray(res.tracks) &&
                res.tracks.some((t: any) => t.mid && !t.errorCode);

            if (foundAny) {
                console.log(
                    `[SFU] Track pull success on attempt ${currentAttempts} for ${participantPublicId}`,
                );
                participantPullAttempts.delete(participantPublicId);

                if (res.sessionDescription) {
                    if (Array.isArray(res.tracks)) {
                        res.tracks.forEach((track: any) => {
                            if (track.mid) {
                                midToParticipantMap.set(
                                    track.mid,
                                    participantPublicId, // Map MID -> Participant
                                );
                                // Ensure Transceiver Map is also consistent
                                const t = sfuPc!
                                    .getTransceivers()
                                    .find((tr) => tr.mid === track.mid);
                                if (t) {
                                    sfuTransceiverMap.set(t, {
                                        participantId: participantPublicId,
                                        trackName: track.trackName,
                                    });
                                }
                            }
                        });
                    }

                    // SERVER OFFER -> CLIENT ANSWER flow
                    console.log(
                        `[SFU] Processing Server Offer for tracks from ${participantPublicId}`,
                    );

                    await sfuPc!.setRemoteDescription(
                        new RTCSessionDescription(res.sessionDescription),
                    );

                    const answer = await sfuPc!.createAnswer();
                    await sfuPc!.setLocalDescription(answer);

                    await videoCallService.sfuSessionRenegotiate(
                        callData.value!.chatId,
                        sfuSessionId.value!,
                        mungeSdp(answer.sdp!),
                        "answer",
                        "PUT",
                    );

                    participantTransceivers.set(participantPublicId, {
                        audioMid:
                            res.tracks?.find(
                                (t: any) => t.trackName === "audio",
                            )?.mid || "",
                        videoMid:
                            res.tracks?.find(
                                (t: any) => t.trackName === "video",
                            )?.mid || "",
                    });
                }
            } else {
                console.warn(
                    `[SFU] Pull attempt ${currentAttempts} for ${participantPublicId} returned no valid tracks. Rescheduling...`,
                );
                // Clear state just in case
                participantTransceivers.delete(participantPublicId);
                const delay = retryDelays[currentAttempts - 1] || 1000;
                setTimeout(
                    () =>
                        pullParticipantTracks(
                            participantPublicId,
                            remoteSessionId,
                            remoteAudioMid,
                            remoteVideoMid,
                        ),
                    delay,
                );
            }
        } catch (e: any) {
            console.warn(
                `[SFU] Pull attempt ${currentAttempts} failed for ${participantPublicId}`,
                e,
            );
            if (e.response?.status === 406) {
                await handleSFU406Rescue();
            }
        }
    });
}

// (Redundant processSFUScreenShare removed)

async function publishSFUScreenTrack(stream: MediaStream) {
    if (!sfuPc || !sfuSessionId.value) return;

    return runInSFUQueue(async () => {
        const track = stream.getVideoTracks()[0];

        // REUSE or ADD transceiver
        let transceiver = sfuPc!.getTransceivers().find(
            (t) =>
                t.direction === "sendonly" && !t.sender.track && t.mid === null, // Unused transceiver
        );

        if (transceiver) {
            console.log(
                "[SFU] Reusing existing sendonly transceiver for screen",
            );
            await transceiver.sender.replaceTrack(track);
        } else {
            console.log("[SFU] Adding new sendonly transceiver for screen");
            transceiver = sfuPc!.addTransceiver(track, {
                direction: "sendonly",
            });
        }

        try {
            await sfuPc!.setLocalDescription(await sfuPc!.createOffer());
            const res = await videoCallService.sfuSessionTracks(
                callData.value!.chatId,
                sfuSessionId.value!,
                [
                    {
                        location: "local",
                        mid: transceiver.mid,
                        trackName: "screen",
                    },
                ],
                mungeSdp(sfuPc!.localDescription!.sdp!),
            );

            if (res.sessionDescription) {
                await sfuPc!.setRemoteDescription(
                    new RTCSessionDescription(res.sessionDescription),
                );
            }

            // GATED SIGNALING: Wait for ICE to be connected/completed before telling others.
            // This ensures the tracks are actually flowing on Cloudflare's backend.
            await new Promise((resolve) => {
                if (
                    sfuPc!.iceConnectionState === "connected" ||
                    sfuPc!.iceConnectionState === "completed"
                ) {
                    setTimeout(resolve, 500); // Grace period for media start
                    return;
                }
                const check = setInterval(() => {
                    if (
                        sfuPc!.iceConnectionState === "connected" ||
                        sfuPc!.iceConnectionState === "completed"
                    ) {
                        clearInterval(check);
                        setTimeout(resolve, 500);
                    }
                }, 200);
                setTimeout(() => {
                    clearInterval(check);
                    resolve(true);
                }, 5000); // 5s Max Wait
            });

            sfuScreenMid.value = transceiver.mid;
            trace("PUB-SCREEN", "Screen share track published and connected", {
                mid: transceiver.mid,
                res,
            });

            // Signal to others
            const signalData = {
                type: "sfu-screen-share-started",
                mid: transceiver.mid,
                sessionId: sfuSessionId.value,
            };
            videoCallService
                .sendSignal(
                    callData.value!.chatId,
                    callData.value!.callId,
                    "signal",
                    signalData as any,
                )
                .catch(() => {});
        } catch (e: any) {
            console.warn("[SFU] Failed to publish screen track", e);
            if (e.response?.status === 406) {
                if (await handleSFU406Rescue()) {
                    console.log(
                        "[SFU] 406 Rescued during screen publish. Retrying original publish...",
                    );
                    publishSFUScreenTrack(stream);
                }
            } else if (sfuPc!.signalingState === "have-local-offer") {
                await sfuPc!.setLocalDescription({
                    type: "rollback",
                } as any);
            }
        }
    });
}

function stopSFUScreenShare() {
    sfuScreenMid.value = null;
    videoCallService
        .sendSignal(callData.value!.chatId, callData.value!.callId, "signal", {
            type: "sfu-screen-share-stopped",
        })
        .catch(() => {});
}

async function endCall(reason = "hangup") {
    if (callData.value && callState.value !== "ended") {
        videoCallService
            .endCall(
                callData.value.chatId,
                callData.value.callId,
                reason as any,
            )
            .catch(() => {});
    }
    callState.value = "ended";
    postToParent({ type: "state", state: "ended", reason });
    cleanup();
}

function closeWindow() {
    window.close();
}

function handleCallFailed() {
    error.value = "Connection failed";
    callState.value = "ended";
    cleanup();
}

function playRingtone(type: "incoming" | "outgoing") {
    try {
        ringtoneAudio = new Audio(
            type === "incoming"
                ? "/static/sounds/inbound-call.mp3"
                : "/static/sounds/outbound-call.mp3",
        );
        ringtoneAudio.loop = true;
        ringtoneAudio.volume = 0.5;
        ringtoneAudio.play().catch(() => {});
    } catch {}
}

function stopRingtone() {
    if (ringtoneAudio) {
        ringtoneAudio.pause();
        ringtoneAudio = null;
    }
}

function startDurationTimer() {
    if (durationTimer) return;
    callDuration.value = 0;
    durationTimer = setInterval(() => callDuration.value++, 1000);
}

function postToParent(msg: any) {
    broadcastChannel?.postMessage({ ...msg, callId: callData.value?.callId });
}

function setupBroadcastChannel() {
    broadcastChannel = new BroadcastChannel("worksphere-call");
    broadcastChannel.onmessage = (e) => {
        if (e.data?.type === "end-call") endCall("hangup");
    };
}

function cleanup() {
    stopRingtone();
    if (localStream.value) {
        localStream.value.getTracks().forEach((t) => t.stop());
        localStream.value = null;
    }
    if (screenStream.value) {
        screenStream.value.getTracks().forEach((t) => t.stop());
        screenStream.value = null;
    }
    peers.forEach((p) => p.destroy());
    peers.clear();

    // Reset store
    store.reset();

    audioAnalysers.forEach((_, id) => stopAudioAnalysis(id));
    audioAnalysers.clear();
    if (durationTimer) clearInterval(durationTimer);
    stopEcho();
    broadcastChannel?.close();

    remoteSfuSessions.clear();
    participantTransceivers.clear();
    processedSignals.clear();

    if (sfuPc) {
        sfuPc.close();
        sfuPc = null;
    }
}

// ============================================================================
// Lifecycle
// ============================================================================

onMounted(async () => {
    window.addEventListener("beforeunload", () => {
        if (callState.value !== "ended") {
            endCall("hangup");
        }
    });

    const raw = sessionStorage.getItem("callData");
    if (!raw) {
        error.value = "Invalid session.";
        callState.value = "error";
        return;
    }
    try {
        callData.value = JSON.parse(raw);
        sessionStorage.removeItem("callData");

        if (callData.value) {
            console.log("[Call] Initialized with data:", {
                callId: callData.value.callId,
                chatId: callData.value.chatId,
                chatType: (callData.value as any).chatType || "dm",
                callType: callData.value.callType,
                direction: callData.value.direction,
                selfId: callData.value.selfPublicId,
            });
            if (callData.value.chatType === "group") {
                callMode.value = "sfu";
            }
            store.selfPublicId = callData.value.selfPublicId;
        }
    } catch {
        error.value = "Data parse error.";
        callState.value = "error";
        return;
    }

    const data = callData.value!;

    // Add self to participants list initially (conceptual)
    participants.value.push({
        publicId: data.selfPublicId,
        name: "Me",
        avatar: null, // We might not have our own avatar in callData
        isSelf: true,
    });

    document.title = `Call — ${data.chatId}`;
    setupBroadcastChannel();
    setupEcho();

    // If we have pending signals (from the accept buffering), we should apply them
    // AFTER we join. But usually we need to join first to get stats.

    if (data.pendingSignals) {
        // Store them to apply after join?
        // Actually, in mesh, we need to know WHO they are from.
        // The new handleSignal does this. We can just replay them.
    }

    if (data.direction === "incoming" && data.remoteUser) {
        // DM Call with Ringing
        playRingtone("incoming");
    } else if (data.direction === "outgoing" && data.remoteUser) {
        // DM Outgoing
        playRingtone("outgoing");
        callState.value = "ringing";
    }

    // SMART JOIN LOGIC
    // 1. If it's a group call, always show the lobby (user requested)
    // 2. If it's a DM, check if we can autoplay audio. If yes, auto-join.
    const isGroup =
        (data as any).chatType === "group" ||
        data.remoteUser?.publicId === "group";

    if (!isGroup) {
        console.log("[Call] Checking for Smart Join (DM)...");
        // We can't perfectly check for autoplay permission synchronously,
        // but we can check navigator.userActivation or try a silent play.
        // For initiators (outgoing), we usually have activation from the parent window click.
        // For receivers (incoming), if they clicked "Accept", activation may carry over in some browsers.

        const canAutoJoin =
            (navigator as any).userActivation?.isActive ||
            data.direction === "outgoing";

        if (canAutoJoin) {
            console.log("[Call] ⚡ Smart Join triggered: skipping lobby");
            joinCall();
        } else {
            console.log(
                "[Call] Smart Join skipped: User interaction required for audio",
            );
        }
    } else {
        console.log("[Call] Group call detected: Showing lobby as per policy");
    }

    // Initial Output Device Sync
    if (store.selectedOutputDeviceId) {
        applyOutputDevice(store.selectedOutputDeviceId);
    }
});

onBeforeUnmount(() => cleanup());
</script>

<template>
    <div class="call-container" :class="gridClass">
        <div class="call-bg"></div>
        <div class="call-overlay"></div>

        <!-- PERSISTENT AUDIO MIXER (Fixes "Only One Speaker" bug) -->
        <div class="audio-mix" style="display:none; position:absolute; top:-9999px;">
            <audio
                v-for="[publicId, stream] in store.remoteStreams"
                :key="publicId + '-audio-mix'"
                :ref="(el) => { if(el) (el as HTMLMediaElement).srcObject = stream }"
                v-volume="publicId"
                v-sink-id
                autoplay
                playsinline
            />
        </div>

        <!-- HEADER / INFO -->
        <div class="call-header">
            <div class="header-info">
                <span class="status-dot" :class="callState"></span>
                <span class="status-text">{{ stateLabel }}</span>
            </div>
        </div>

        <!-- ERROR STATE -->
        <div v-if="callState === 'error'" class="call-center-content">
            <div class="state-icon error">
                <Icon name="AlertCircle" size="48" />
            </div>
            <p class="state-text">{{ error }}</p>
            <button class="btn-secondary" @click="closeWindow">
                Close Window
            </button>
        </div>

        <!-- ENDED STATE -->
        <div v-else-if="callState === 'ended'" class="call-center-content">
            <div class="state-icon ended">
                <Icon name="PhoneOff" size="48" />
            </div>
            <p class="state-text">Call ended</p>
            <button class="btn-secondary" @click="closeWindow">
                Close Window
            </button>
        </div>

        <!-- JOIN SCREEN -->
        <div v-else-if="!hasJoined" class="lobby-minimalist">
            <div class="lobby-content">
                <div class="avatar-preview">
                    <!-- Avatar Preview -->
                    <div class="preview-circle">
                        <span class="initials">{{ previewRemoteName[0] }}</span>
                    </div>
                </div>
                
                <div class="join-info">
                    <h1 class="join-title">Join</h1>
                    <p class="join-subtitle">With {{ previewRemoteName }}</p>
                </div>

                <div class="lobby-actions-grid">
                    <button class="btn-lobby-action join" @click="joinCall">
                        <Icon name="Phone" size="20" />
                        <span>Join</span>
                    </button>
                    <button class="btn-lobby-action settings" @click="showSettings = true">
                        <Icon name="Settings" size="20" />
                        <span>Settings</span>
                    </button>
                    <button class="btn-lobby-action decline" @click="endCall('declined')">
                        <Icon name="X" size="20" />
                        <span>Decline</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- CONNECTED LAYOUT -->
        <template v-else>
            <!-- SPOTLIGHT LAYOUT (Presentation Mode) -->
            <div v-if="layoutMode === 'spotlight'" class="spotlight-wrapper">
                <!-- Main Stage: Screen Share -->
                <div class="spotlight-stage">
                    <template v-if="isScreenSharing && !!screenStream">
                        <video
                            v-src-object="screenStream"
                            v-sink-id
                            autoplay
                            muted
                            playsinline
                            class="video-element screen-share-video mirror-off"
                        />
                         <div class="participant-info">
                            <Icon name="Monitor" size="14" />
                            <span class="participant-name">You are presenting</span>
                        </div>
                    </template>
                     <template v-else-if="store.remoteScreenStreams.size > 0">
                        <div
                            v-for="[publicId, stream] in store.remoteScreenStreams"
                            :key="publicId + '-screen-spotlight'"
                            class="spotlight-content"
                        >
                            <video
                                v-src-object="stream"
                                v-sink-id
                                autoplay
                                playsinline
                                class="video-element screen-share-video"
                            />
                            <div class="participant-info">
                                <Icon name="Monitor" size="14" />
                                <span class="participant-name">{{
                                    participants.find(p => p.publicId === publicId)?.name || 'Someone'
                                }}'s Screen</span>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Side/Bottom Filmstrip: Participants -->
                <div class="filmstrip">
                     <div
                        v-for="p in participants.filter(p => !p.isSelf || !isScreenSharing)"
                        :key="p.publicId"
                        class="video-cell filmstrip-cell"
                         :class="{
                            'is-talking': talkingParticipants.has(p.publicId.toLowerCase()),
                            'local': p.isSelf
                        }"
                    >

                         
                         <video
                            v-if="
                                (p.isSelf ? localHasVideo && !isCameraOff : store.remoteStreams.get(p.publicId)) &&
                                (!isAudioOnly || (p.isSelf ? false : remoteHasVideo(p.publicId)))
                            "
                            v-src-object="p.isSelf ? localStream : store.remoteStreams.get(p.publicId)"
                            v-sink-id
                             :muted="p.isSelf"
                            autoplay
                            playsinline
                            class="video-element"
                            :class="{ 'mirror-off': p.isSelf && false }" 
                        />
                        <!-- Avatar Fallback -->
                        <div v-else class="avatar-fallback">
                             <div
                                class="avatar-placeholder"
                                :style="{ background: getAvatarColor(p.name) }"
                            >
                                <span class="initials-text">{{ getInitials(p.name) }}</span>
                            </div>
                            <div class="audio-indicator" v-if="!p.isSelf">
                                <Icon name="Mic" size="14" />
                            </div>
                        </div>

                        <div class="participant-info small">
                             <span class="participant-name">{{ p.isSelf ? 'You' : p.name }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STANDARD GRID LAYOUT -->
            <div v-else class="grid-wrapper">
                <!-- Remote Participants -->
                <div
                    v-for="p in participants.filter(
                        (p) => !p.isSelf && p.publicId !== callData?.selfPublicId,
                    )"
                    :key="p.publicId"
                    class="video-cell remote"
                    :class="{
                        'is-talking': talkingParticipants.has(
                            p.publicId.toLowerCase(),
                        ),
                    }"
                >


                    <video
                        v-if="
                            store.remoteStreams.get(p.publicId) &&
                            (!isAudioOnly || remoteHasVideo(p.publicId))
                        "
                        v-src-object="store.remoteStreams.get(p.publicId)"
                        v-volume="p.publicId"
                        v-sink-id
                        autoplay
                        playsinline
                        class="video-element"
                    />
                    <!-- Avatar Fallback -->
                    <div v-else class="avatar-fallback">
                        <div
                            class="avatar-placeholder"
                            :style="{ background: getAvatarColor(p.name) }"
                        >
                             <span class="initials-text">{{ getInitials(p.name) }}</span>
                        </div>
                        <div class="audio-indicator">
                            <Icon name="Mic" size="16" />
                        </div>
                    </div>

                    <div class="participant-info">
                        <span class="participant-name">{{ p.name }}</span>
                        
                        <!-- Status Icons -->
                        <div class="status-icons">
                            <Icon v-if="!remoteHasAudio(p.publicId)" name="MicOff" size="14" class="status-icon-red" />
                            <Icon v-if="!remoteHasVideo(p.publicId)" name="VideoOff" size="14" class="status-icon-red" />
                        </div>

                        <!-- Individual Volume Control -->
                        <div
                            class="volume-control"
                            v-if="store.remoteStreams.has(p.publicId)"
                        >
                            <div class="volume-slider-container">
                                <input
                                    type="range"
                                    min="0"
                                    max="100"
                                    :value="store.remoteVolumes.get(p.publicId) ?? 100"
                                    @input="(e) => store.setRemoteVolume(p.publicId, parseInt((e.target as HTMLInputElement).value))"
                                    class="volume-slider-input"
                                />
                            </div>
                            <button class="volume-btn">
                                <Icon
                                    :name="
                                        (store.remoteVolumes.get(p.publicId) ?? 100) === 0
                                            ? 'VolumeX'
                                            : 'Volume2'
                                    "
                                    size="14"
                                />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 1b. Remote Screen Shares (SFU Mode) -->
                <div
                    v-for="[
                        publicId,
                        screenStream,
                    ] in store.remoteScreenStreams"
                    :key="publicId + '-screen'"
                    class="video-cell remote screen-share"
                    :class="{ expanded: store.remoteScreenStreams.size === 1 }"
                >
                    <video
                        v-src-object="screenStream"
                        v-volume="publicId"
                        v-sink-id
                        autoplay
                        playsinline
                        class="video-element"
                    />
                    <div class="participant-info">
                        <Icon name="Monitor" size="14" />
                        <span class="participant-name"
                            >{{
                                participants.find(
                                    (p) => p.publicId === publicId,
                                )?.name || "Someone"
                            }}'s Screen</span
                        >
                    </div>
                </div>

                <!-- Local Participant (Me) -->
                <div
                    class="video-cell local"
                     :class="{
                        'pip-mode': participants.length >= 2,
                        'audio-mode': isAudioOnly && !isScreenSharing,
                        'is-talking': talkingParticipants.has(callData?.selfPublicId?.toLowerCase() || ''),
                    }"
                >
                    <video
                        v-if="
                            isScreenSharing
                                ? !!screenStream
                                : localHasVideo && !isCameraOff
                        "
                        v-src-object="
                            isScreenSharing ? screenStream : localStream
                        "
                        v-sink-id
                        autoplay
                        muted
                        playsinline
                        class="video-element"
                        :class="{ 'mirror-off': isScreenSharing }"
                    />
                    <div v-else class="avatar-fallback">
                        <div class="avatar-placeholder local" :style="{ background: getAvatarColor('Me') }">
                             <span class="initials-text">Me</span>
                        </div>
                    </div>
                    <div class="participant-info">
                        <span class="participant-name">You</span>
                        <div class="status-icons">
                            <Icon v-if="isMuted" name="MicOff" size="14" class="status-icon-red" />
                            <Icon v-if="isCameraOff" name="VideoOff" size="14" class="status-icon-red" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- CONTROLS -->
            <div class="controls-bar">
                <button
                    class="control-btn"
                    :class="{ off: isMuted }"
                    @click="toggleMute"
                    title="Toggle Mute"
                >
                    <Icon :name="isMuted ? 'MicOff' : 'Mic'" size="24" />
                </button>

                <button
                    v-if="!isAudioOnly"
                    class="control-btn"
                    :class="{ off: isCameraOff }"
                    @click="toggleCamera"
                    title="Toggle Camera"
                >
                    <Icon
                        :name="isCameraOff ? 'VideoOff' : 'Video'"
                        size="24"
                    />
                </button>
                <button
                    class="control-btn"
                    :class="{ off: !isScreenSharing }"
                    @click="toggleScreenShare"
                    title="Share Screen"
                >
                    <Icon name="Monitor" size="24" />
                </button>

                <button
                    class="control-btn"
                    @click="showSettings = true"
                    title="Settings"
                >
                    <Icon name="Settings" size="24" />
                </button>

                <button
                     class="control-btn"
                     @click="toggleChat"
                     title="Chat"
                >
                    <Icon name="MessageSquare" size="24" />
                </button>

                <button
                    class="control-btn hangup"
                    @click="endCall('hangup')"
                    title="End Call"
                >
                    <Icon name="PhoneOff" size="24" />
                </button>
            </div>
            
            <CallSettingsModal 
                :open="showSettings" 
                @update:open="showSettings = $event" 
                @close="showSettings = false"
            />
        </template>
    </div>
</template>

<style scoped>
:root {
    --glass-bg: rgba(255, 255, 255, 0.08);
    --glass-border: rgba(255, 255, 255, 0.15);
    --mesh-gradient:
        radial-gradient(at 0% 0%, #1e1e2e 0px, transparent 50%),
        radial-gradient(at 50% 0%, #182848 0px, transparent 50%),
        radial-gradient(at 100% 0%, #1e1e2e 0px, transparent 50%);
}

.call-container {
    background: #09090b;
    height: 100dvh;
    width: 100vw;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    font-family: "Inter", system-ui, -apple-system, sans-serif;
    color: #fafafa;
}

.call-bg {
    position: absolute;
    inset: 0;
    background-color: #09090b;
    background-image: var(--mesh-gradient);
    z-index: 0;
}

.call-overlay {
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, transparent 0%, rgba(0, 0, 0, 0.6) 100%);
    z-index: 1;
    pointer-events: none;
}

/* Header */
.call-header {
    position: absolute;
    top: env(safe-area-inset-top, 0);
    left: 0;
    right: 0;
    padding: 20px;
    z-index: 100;
    display: flex;
    justify-content: center;
    pointer-events: none;
}

.header-info {
    background: rgba(20, 20, 25, 0.6);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    padding: 8px 16px;
    border-radius: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid var(--glass-border);
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
}

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #71717a;
    transition: all 0.3s ease;
}
.status-dot.connected {
    background: #10b981;
    box-shadow: 0 0 12px rgba(16, 185, 129, 0.6);
    animation: breathing 3s infinite;
}
.status-dot.connecting, .status-dot.ringing {
    background: #3b82f6;
    animation: pulse 1.5s infinite;
}
.status-dot.error { background: #ef4444; }

.status-text {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    font-weight: 600;
}

/* Center Content */
.call-center-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    z-index: 10;
    padding: 40px 24px;
    text-align: center;
}

.state-icon {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 32px;
    border: 1px solid transparent;
}
.state-icon.error {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border-color: rgba(239, 68, 68, 0.2);
}
.state-icon.ended {
    background: rgba(113, 113, 122, 0.1);
    color: #a1a1aa;
    border-color: rgba(113, 113, 122, 0.2);
}

.state-text {
    font-size: 20px;
    font-weight: 600;
    color: white;
    margin-bottom: 32px;
}

/* Lobby Minimalist Redesign */
.lobby-minimalist {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    z-index: 10;
    padding: 60px 24px;
    animation: fadeIn 0.8s cubic-bezier(0.22, 1, 0.36, 1);
}

.lobby-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 32px;
}

.avatar-preview {
    position: relative;
}

.preview-circle {
    width: 160px;
    height: 160px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 56px;
    font-weight: 700;
    color: white;
    box-shadow: 0 20px 50px rgba(99, 102, 241, 0.4);
    border: 4px solid rgba(255, 255, 255, 0.15);
}

.join-info {
    text-align: center;
}

.join-title {
    font-size: 40px;
    font-weight: 800;
    margin-bottom: 8px;
    letter-spacing: -0.02em;
    color: white;
}

.join-subtitle {
    font-size: 20px;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 500;
}

.lobby-actions-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    width: 100%;
    max-width: 520px;
    margin-top: 32px;
}

.btn-lobby-action {
    height: 58px;
    border-radius: 18px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: white;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    white-space: nowrap;
    width: 100%;
    padding: 0 8px; /* Minimal side padding since width is fixed by grid */
}

.btn-lobby-action:hover {
    background: rgba(255, 255, 255, 0.12);
    transform: translateY(-4px);
    border-color: rgba(255, 255, 255, 0.2);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
}

.btn-lobby-action.join {
    background: rgba(16, 185, 129, 0.1);
    border-color: rgba(16, 185, 129, 0.3);
    color: #10b981 !important;
}
.btn-lobby-action.join:hover {
    background: rgba(16, 185, 129, 0.2);
    border-color: rgba(16, 185, 129, 0.5);
    box-shadow: 0 12px 30px rgba(16, 185, 129, 0.2);
}

.btn-lobby-action.settings {
    background: rgba(255, 255, 255, 0.08);
}

.btn-lobby-action.decline {
    background: rgba(239, 68, 68, 0.08);
    border-color: rgba(239, 68, 68, 0.2);
    color: #ef4444 !important;
}
.btn-lobby-action.decline:hover {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.4);
    box-shadow: 0 12px 30px rgba(239, 68, 68, 0.15);
}

/* --- GRID LAYOUT --- */
.grid-wrapper {
    flex: 1;
    display: flex;
    width: 100%;
    height: 100%;
    z-index: 10;
    position: relative;
    padding: 20px;
    padding-bottom: calc(100px + env(safe-area-inset-bottom, 20px));
    gap: 16px;
    justify-content: center;
    align-items: center;
}

.grid-2-2 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-auto-rows: 1fr;
}
.grid-3-3 .grid-wrapper {
     display: grid;
    grid-template-columns: repeat(3, 1fr);
    grid-auto-rows: 1fr;
}

/* --- SPOTLIGHT LAYOUT --- */
.spotlight-wrapper {
    flex: 1;
    display: flex;
    width: 100%;
    height: 100%;
    z-index: 10;
    position: relative;
    padding: 16px;
    padding-bottom: calc(100px + env(safe-area-inset-bottom, 20px));
    gap: 16px;
}

.spotlight-stage {
    flex: 1;
    background: #000;
    border-radius: 16px;
    border: 1px solid var(--glass-border);
    overflow: hidden;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
}

.spotlight-content {
    width: 100%;
    height: 100%;
    position: relative;
}

.screen-share-video {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.filmstrip {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 280px;
    overflow-y: auto;
    padding-right: 4px;
    /* Scrollbar styling */
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.2) transparent;
}

.filmstrip-cell.video-cell {
    width: 100%;
    aspect-ratio: 16/9;
    height: auto;
    flex-shrink: 0;
    border-radius: 12px; /* Softer rounded corners */
}

/* --- VIDEO CELL STYLING --- */
.video-cell {
    position: relative;
    width: 100%;
    height: 100%;
    background: #18181b;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--glass-border);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

.video-cell.is-talking {
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.3);
    z-index: 20;
}

.video-element {
    width: 100%;
    height: 100%;
    object-fit: cover;
    background: #000;
}
.mirror-off { transform: scaleX(1); }

/* Avatar Fallback */
.avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #18181b;
}
.avatar-placeholder {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 700;
    color: white;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

/* PiP for Local (in Grid 1-1) */
.video-cell.local.pip-mode {
    position: absolute;
    bottom: calc(120px + env(safe-area-inset-bottom, 20px));
    right: 20px;
    width: 160px;
    height: 240px; /* Portrait PiP usually better? Or 16:9? Let's check aspect */
    height: 100px;
    height: auto;
    aspect-ratio: 9/16; /* Mobile style vertical pip? or 16/9 */
    aspect-ratio: 16/9; /* Standard */
    width: 200px;
    z-index: 30;
    border: 2px solid rgba(255,255,255,0.2);
    box-shadow: 0 16px 40px rgba(0,0,0,0.6);
}

.participant-info {
    position: absolute;
    bottom: 16px;
    left: 16px;
    background: rgba(9, 9, 11, 0.6);
    backdrop-filter: blur(8px);
    padding: 6px 14px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: white;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid var(--glass-border);
}

/* Controls Bar */
.controls-bar {
    position: absolute;
    bottom: calc(32px + env(safe-area-inset-bottom, 0));
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 20px;
    z-index: 500;
    background: rgba(20, 20, 25, 0.8);
    backdrop-filter: blur(24px);
    padding: 14px 28px;
    border-radius: 40px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--glass-border);
}
.control-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.1);
    background: rgba(255, 255, 255, 0.08);
    color: white;
    font-size: 24px;
    cursor: pointer;
    display:flex; align-items:center; justify-content:center;
    transition: all 0.2s;
}
.control-btn:hover { background: rgba(255,255,255,0.15); transform: scale(1.1); }
.control-btn.off { background: #fafafa; color: #09090b; }
.control-btn.hangup { background: #ef4444; border-color: rgba(255,255,255,0.1); }
.control-btn.hangup:hover { background: #dc2626; }

/* Mobile Overrides */
@media (max-width: 768px) {
    .spotlight-wrapper {
        flex-direction: column;
        padding: 0;
    }
    .spotlight-stage {
        border-radius: 0;
        border: none;
        flex: 1; /* Take mostly all space */
    }
    .filmstrip {
        width: 100%;
        height: 120px;
        flex-direction: row;
        overflow-x: auto;
        overflow-y: hidden;
        padding: 12px;
        background: #09090b;
        border-top: 1px solid var(--glass-border);
        padding-bottom: calc(100px + env(safe-area-inset-bottom, 20px)); /* Lift over controls */
        position: absolute;
        bottom: 0;
        z-index: 400; /* Under controls */
    }
    .filmstrip-cell.video-cell {
        width: 160px;
        height: 100%;
        aspect-ratio: 16/9;
    }
    
    .controls-bar {
        bottom: calc(20px + env(safe-area-inset-bottom, 0));
        width: calc(100% - 40px);
        max-width: 400px;
        padding: 10px 20px;
        justify-content: space-evenly;
    }
    .control-btn { width: 48px; height: 48px; }

    .grid-wrapper { padding: 12px; padding-bottom: 120px; }
    .grid-2-2 .grid-wrapper, .grid-3-3 .grid-wrapper { grid-template-columns: 1fr; }
}

@keyframes breathing { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.1); opacity: 0.8; } }
@keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.avatar-placeholder {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: auto; /* Firefox centering fix */
    font-size: 40px;
    font-weight: 800;
    color: white;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.4);
    border: 2px solid rgba(255, 255, 255, 0.1);
}

.avatar-img {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.4);
}

.audio-indicator {
    position: absolute;
    bottom: 20px;
    right: 20px;
    background: #10b981;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    border: 2px solid #09090b;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.hidden-audio {
    position: fixed;
    top: -100px;
    left: -100px;
    width: 1px;
    height: 1px;
    opacity: 0.01;
    pointer-events: none;
}

/* Volume Control Styles */
.volume-control {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: 8px;
    pointer-events: auto;
}

.volume-slider-container {
    width: 0;
    overflow: hidden;
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
}

.volume-control:hover .volume-slider-container {
    width: 80px;
    margin-right: 8px;
}

.volume-slider-input {
    width: 80px;
    height: 4px;
    -webkit-appearance: none;
    appearance: none;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 2px;
    outline: none;
    cursor: pointer;
}

.volume-slider-input::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    background: #fafafa;
    border-radius: 50%;
    box-shadow: 0 0 8px rgba(0, 0, 0, 0.5);
}

.volume-btn {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.8);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2px;
    transition: color 0.2s;
}

.volume-btn:hover {
    color: white;
}
</style>
