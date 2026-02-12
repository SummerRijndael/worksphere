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
    nextTick,
    reactive,
} from "vue";
import Peer from "simple-peer";
import * as sdpTransform from "sdp-transform";
import { startEcho, stopEcho } from "@/echo";
import { videoCallService } from "@/services/videocall.service";
import { useVideoCallStore } from "@/stores/videocall";
import { Icon } from "@/components/ui";

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
const isCameraOff = ref(false);
const videoFallback = ref(false);
const isAudioOnly = computed(() => callData.value?.callType === "audio");

// Screen Sharing
const isScreenSharing = ref(false);
const screenStream = ref<MediaStream | null>(null);
const sfuScreenMid = ref<string | null>(null);

// Hybrid Mode
const callMode = ref<"mesh" | "sfu">("mesh");
const sfuSessionId = ref<string | null>(null);
const sfuAppId = ref<string | null>(null);
const remoteSfuSessions = reactive(new Map<string, string>());
const participantTransceivers = new Map<
    string,
    { audioMid?: string; videoMid?: string; screenMid?: string }
>();

// Participants & Peers
const participants = ref<Participant[]>([]);
const peers = new Map<string, Peer.Instance>();
const iceServers = ref<RTCIceServer[]>([]);
const processedSignals = new Set<string>(); // To prevent duplicate signal processing

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

// UI Refs
const localVideoRef = ref<HTMLVideoElement | null>(null);

// Timers & Channels
let durationTimer: ReturnType<typeof setInterval> | null = null;
const callDuration = ref(0);
let echoChannel: any = null;
let broadcastChannel: BroadcastChannel | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;
let ringtoneTimeout: ReturnType<typeof setTimeout> | null = null;

// ============================================================================
// Computed
// ============================================================================

const isVideoCall = computed(() => callData.value?.callType === "video");

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

const formattedDuration = computed(() => {
    const mins = Math.floor(callDuration.value / 60);
    const secs = callDuration.value % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
});

const gridClass = computed(() => {
    // Only count participants effectively shown in the grid (Self + Remotes)
    const count =
        participants.value.filter(
            (p) => !p.isSelf && p.publicId !== callData.value?.selfPublicId,
        ).length + 1;
    if (count <= 2) return "grid-1-1";
    if (count <= 4) return "grid-2-2";
    return "grid-3-2";
});

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
                .filter((l) => l.length > 0 && !l.includes("a=max-message-size:"))
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
        } else {
            console.log("[Call] Initializing SFU mode via Cloudflare");
            await joinSFU(stream);
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
            signal,
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

    if (senderId === selfId) return;

    // In mesh, signals MUST be targeted or we ignore them for safety
    if (targetId && targetId !== selfId) return;

    // WAIT for media if we are in the process of joining
    if (!hasJoined.value || !localStream.value) {
        console.log(
            `[Call] Buffering signal from ${senderId} - joining or media not ready`,
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
    if (signal.type === "sfu-session-ready") {
        trace(
            "SIGNAL",
            `Received sfu-session-ready from ${senderId}`,
            signal.sessionId,
        );
        remoteSfuSessions.set(senderId, signal.sessionId);
        pullParticipantTracks(senderId, signal.sessionId);
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

    // Resolve sessionId
    const targetSessionId =
        remoteSessionId || remoteSfuSessions.get(participantPublicId);
    if (!targetSessionId) {
        console.warn(
            `[SFU] Cannot pull screen for ${participantPublicId}: session ID unknown`,
        );
        return;
    }

    console.log(
        `[SFU] Processing remote screen share from ${participantPublicId} (mid: ${mid}) using session ${targetSessionId}`,
    );

    sfuNegotiationQueue = sfuNegotiationQueue.then(async () => {
        try {
            // Check if we already have this screen Mid (INSIDE QUEUE to prevent races)
            if (
                participantTransceivers.get(participantPublicId)?.screenMid ===
                mid
            ) {
                console.log(
                    `[SFU] Already have screen share mid ${mid} for ${participantPublicId}`,
                );
                return;
            }

            const transceiver = sfuPc!.addTransceiver("video", {
                direction: "recvonly",
            });

            await sfuPc!.setLocalDescription(await sfuPc!.createOffer());

            // Map MIDs IMMEDIATELY after setLocalDescription
            if (transceiver.mid) {
                trace("PULL-SCREEN", `Mapping mid ${transceiver.mid} to ${participantPublicId}:screen`);
                midToParticipantMap.set(
                    transceiver.mid,
                    `${participantPublicId}:screen`,
                );

                // Save to tracking
                const existing =
                    participantTransceivers.get(participantPublicId) || {};
                participantTransceivers.set(participantPublicId, {
                    ...existing,
                    screenMid: mid,
                });
            }

            const res = await videoCallService.sfuSessionTracks(
                callData.value!.chatId,
                sfuSessionId.value!,
                [
                    {
                        location: "remote",
                        sessionId: targetSessionId,
                        trackName: "screen",
                        mid: transceiver.mid,
                    },
                ],
                mungeSdp(sfuPc!.localDescription!.sdp!),
            );

            trace("PULL-SCREEN", `Response for ${participantPublicId}`, res);

            if (res.sessionDescription) {
                await sfuPc!.setRemoteDescription(
                    new RTCSessionDescription(res.sessionDescription),
                );
            }
        } catch (e) {
            console.error("[SFU] Failed to pull screen track", e);
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

    if (publicId === selfId) return;

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
            trace("MESH", `Initiating mesh peer for ${publicId} (initiator: ${isInitiator})`);
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
            console.log(
                `[SFU] Sending our session ID ${sfuSessionId.value} to new joiner ${publicId}`,
            );
            videoCallService
                .sendSignal(
                    callData.value!.chatId,
                    callData.value!.callId,
                    "signal",
                    {
                        type: "sfu-session-ready",
                        sessionId: sfuSessionId.value,
                    },
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
    return stream.getVideoTracks().length > 0;
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

// normalizeSdp has been replaced by mungeSdp for bit-perfect cross-browser compatibility

async function joinSFU(stream: MediaStream) {
    console.log("[SFU] Initializing RTCPeerConnection for Cloudflare Calls");

    sfuPc = new RTCPeerConnection({
        iceServers:
            iceServers.value.length > 0
                ? iceServers.value
                : [{ urls: "stun:stun.cloudflare.com:3478" }],
        bundlePolicy: "max-bundle",
    });

    // 1. Add local senders
    stream.getTracks().forEach((track) => {
        console.log(`[SFU] Adding local ${track.kind} track to session`);
        sfuPc!.addTransceiver(track, { direction: "sendonly" });
    });

    // 2. Initial Offer to establish session
    const offer = await sfuPc.createOffer();
    await sfuPc.setLocalDescription(offer);

    console.log("[SFU] Creating new session via backend proxy...");
    const sessionRes = await videoCallService.sfuSessionNew(
        callData.value!.chatId,
        mungeSdp(offer.sdp!),
    );

    await sfuPc.setRemoteDescription(
        new RTCSessionDescription(sessionRes.sessionDescription),
    );

    if (sessionRes.sessionId) {
        sfuSessionId.value = sessionRes.sessionId;
        console.log("[SFU] Session established:", sessionRes.sessionId);
    }

    // 3. Wait for ICE connection
    await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject("SFU Connect Timeout"), 10000);
        sfuPc!.oniceconnectionstatechange = () => {
            if (
                sfuPc!.iceConnectionState === "connected" ||
                sfuPc!.iceConnectionState === "completed"
            ) {
                clearTimeout(timeout);
                resolve(true);
            }
        };
    });

    // 4. Register local tracks (Cloudflare needs to know track IDs)
    const trackObjects = sfuPc
        .getTransceivers()
        .filter((t: any) => t.sender.track)
        .map((t: any) => ({
            location: "local",
            mid: t.mid,
            trackName: t.sender.track!.kind, // Use stable names (audio/video)
        }));

    await sfuPc.setLocalDescription(await sfuPc.createOffer());
    const tracksRes = await videoCallService.sfuSessionTracks(
        callData.value!.chatId,
        sfuSessionId.value!,
        trackObjects,
        mungeSdp(sfuPc.localDescription!.sdp!),
    );

    trace("JOIN", "Local tracks published", {
        trackObjects,
        res: tracksRes,
    });

    if (tracksRes.sessionDescription) {
        await sfuPc.setRemoteDescription(
            new RTCSessionDescription(tracksRes.sessionDescription),
        );
    } else {
        console.error(
            "[SFU] Failed to register local tracks: API response missing sessionDescription",
            tracksRes,
        );
    }

    // 5. SIGNAL READY (Now that tracks are safe and registered)
    if (sfuSessionId.value) {
        console.log("[SFU] Signaling sfu-session-ready to all peers");
        videoCallService
            .sendSignal(callData.value!.chatId, callData.value!.callId, "signal", {
                type: "sfu-session-ready",
                sessionId: sfuSessionId.value,
            })
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
            const participantId = midToParticipantMap.get(mid!);
            trace("TRACK", `Active: ${track.kind} (${mid}) for ${participantId}`, {
                streams: event.streams.length,
            });

            if (participantId) {
                // FALLBACK: If event.streams is empty, create a new stream from the track
                const remoteStream =
                    event.streams[0] || new MediaStream([track]);

                if (participantId.endsWith(":screen")) {
                    const realId = participantId.replace(":screen", "");
                    store.addRemoteScreenStream(realId, remoteStream);
                } else {
                    store.addRemoteStream(participantId, remoteStream);
                    startAudioAnalysis(participantId, remoteStream);
                }
            } else {
                console.warn(`[SFU] Could not find participant for mid ${mid}`);
                trace("TRACK", "FAILED: Unknown MID", { mid, map: Object.fromEntries(midToParticipantMap) });
            }
        };

        if (track.muted === false) {
            handleTrackActive();
        } else {
            track.onunmute = handleTrackActive;
        }
    };

    // 5. Initial Pull: We wait for 'sfu-session-ready' signals from others.
    // However, if we joined late, we might miss them.
    // The backend join response gives us participants list, but not their SFU IDs.
    // They will re-broadcast to us in handleParticipantJoined when they see us join.
}

let sfuNegotiationQueue = Promise.resolve();

const midToParticipantMap = new Map<string, string>();

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
) {
    if (!sfuPc || !sfuSessionId.value) return;

    // Resolve sessionId
    const targetSessionId =
        remoteSessionId || remoteSfuSessions.get(participantPublicId);
    if (!targetSessionId) {
        console.warn(
            `[SFU] Cannot pull tracks for ${participantPublicId}: session ID unknown yet`,
        );
        return;
    }

    // Chain to the queue to ensure sequential negotiation
    sfuNegotiationQueue = sfuNegotiationQueue.then(async () => {
        try {
            // AVOID BLOAT: Check if we already have transceivers for this participant (INSIDE QUEUE)
            if (participantTransceivers.has(participantPublicId)) {
                console.log(
                    `[SFU] Already have transceivers for ${participantPublicId}, skipping redundant pull`,
                );
                return;
            }

            console.log(
                `[SFU] Processing track pull for participant: ${participantPublicId} using session ${targetSessionId}`,
            );

            // 1. Add recvonly transceivers BEFORE creating the offer
            const audioTransceiver = sfuPc!.addTransceiver("audio", {
                direction: "recvonly",
            });
            const videoTransceiver = sfuPc!.addTransceiver("video", {
                direction: "recvonly",
            });

            await sfuPc!.setLocalDescription(await sfuPc!.createOffer());

            // 2. Map MIDs IMMEDIATELY after setLocalDescription
            if (audioTransceiver.mid) {
                console.log(
                    `[SFU] Mapping mid ${audioTransceiver.mid} to ${participantPublicId} (audio)`,
                );
                midToParticipantMap.set(
                    audioTransceiver.mid,
                    participantPublicId,
                );
            }
            if (videoTransceiver.mid) {
                console.log(
                    `[SFU] Mapping mid ${videoTransceiver.mid} to ${participantPublicId} (video)`,
                );
                midToParticipantMap.set(
                    videoTransceiver.mid,
                    participantPublicId,
                );
            }

            // Save transceivers to tracking
            participantTransceivers.set(participantPublicId, {
                audioMid: audioTransceiver.mid!,
                videoMid: videoTransceiver.mid!,
            });

            trace("PULL", `Requesting tracks for ${participantPublicId}`, {
                audioMid: audioTransceiver.mid,
                videoMid: videoTransceiver.mid,
            });

            const res = await videoCallService.sfuSessionTracks(
                callData.value!.chatId,
                sfuSessionId.value!,
                [
                    {
                        location: "remote",
                        sessionId: targetSessionId,
                        trackName: "audio", // Stable name
                        mid: audioTransceiver.mid,
                    },
                    {
                        location: "remote",
                        sessionId: targetSessionId,
                        trackName: "video", // Stable name
                        mid: videoTransceiver.mid,
                    },
                ],
                mungeSdp(sfuPc!.localDescription!.sdp!),
            );

            trace("PULL", `Response for ${participantPublicId}`, res);

            if (res.sessionDescription) {
                await sfuPc!.setRemoteDescription(
                    new RTCSessionDescription(res.sessionDescription),
                );
            } else {
                console.log(
                    "[SFU] Pull tracks request accepted, waiting for signaling matching",
                    res,
                );
            }
        } catch (e) {
            console.warn(
                `[SFU] Failed to pull tracks for ${participantPublicId}`,
                e,
            );
            // On failure, remove from tracking so it can be retried if needed
            participantTransceivers.delete(participantPublicId);
        }
    });

    return sfuNegotiationQueue;
}

// (Redundant processSFUScreenShare removed)

async function publishSFUScreenTrack(stream: MediaStream) {
    if (!sfuPc || !sfuSessionId.value) return;

    const track = stream.getVideoTracks()[0];
    const transceiver = sfuPc.addTransceiver(track, { direction: "sendonly" });

    await sfuPc.setLocalDescription(await sfuPc.createOffer());
    const res = await videoCallService.sfuSessionTracks(
        callData.value!.chatId,
        sfuSessionId.value!,
        [{ location: "local", mid: transceiver.mid, trackName: "screen" }],
        mungeSdp(sfuPc.localDescription!.sdp!),
    );

    if (res.sessionDescription) {
        await sfuPc.setRemoteDescription(
            new RTCSessionDescription(res.sessionDescription),
        );
        sfuScreenMid.value = transceiver.mid;
        trace("PUB-SCREEN", "Screen share track published", { mid: transceiver.mid, res });

        // Signal to others
        const signalData = {
            type: "sfu-screen-share-started",
            mid: transceiver.mid,
            sessionId: sfuSessionId.value, // Include session ID
        };
        const targetPublicId = undefined; // Or derive if needed
        videoCallService
            .sendSignal(
                callData.value.chatId,
                callData.value.callId,
                "signal",
                signalData as any,
                targetPublicId,
            )
            .catch(() => {});
    } else {
        console.error(
            "[SFU] Failed to publish screen track: API response missing sessionDescription",
            res,
        );
    }
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
            .endCall(callData.value.chatId, callData.value.callId, reason)
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

        console.log("[Call] Initialized with data:", {
            callId: callData.value.callId,
            chatId: callData.value.chatId,
            chatType: (callData.value as any).chatType || "dm",
            callType: callData.value.callType,
            direction: callData.value.direction,
            selfId: callData.value.selfPublicId,
        });
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
});

onBeforeUnmount(() => cleanup());
</script>

<template>
    <div class="call-container" :class="gridClass">
        <div class="call-bg"></div>
        <div class="call-overlay"></div>

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
        <div v-else-if="!hasJoined" class="join-screen call-center-content">
            <div class="avatar-preview">
                <!-- Avatar Preview -->
                <div class="preview-circle">
                    <span class="initials">{{ previewRemoteName[0] }}</span>
                </div>
            </div>
            <h1 class="join-title">Join Call</h1>
            <p class="join-subtitle">With {{ previewRemoteName }}</p>

            <div class="join-actions">
                <button class="btn-join" @click="joinCall">
                    <Icon name="Phone" size="20" />
                    <span>Join Now</span>
                </button>
                <button class="btn-decline" @click="endCall('declined')">
                    <Icon name="X" size="20" />
                    <span>Decline</span>
                </button>
            </div>
        </div>

        <!-- CONNECTED GRID -->
        <template v-else>
            <div class="grid-wrapper">
                <!-- 1. Remote Participants -->
                <div
                    v-for="p in participants.filter(
                        (p) =>
                            !p.isSelf && p.publicId !== callData?.selfPublicId,
                    )"
                    :key="p.publicId"
                    class="video-cell remote"
                    :class="{
                        'is-talking': talkingParticipants.has(
                            p.publicId.toLowerCase(),
                        ),
                    }"
                >
                    <!-- Audio playback (always audible, but hidden/tiny) -->
                    <audio
                        v-if="store.remoteStreams.get(p.publicId)"
                        v-src-object="store.remoteStreams.get(p.publicId)"
                        autoplay
                        playsinline
                        class="hidden-audio"
                    />

                    <video
                        v-if="
                            store.remoteStreams.get(p.publicId) &&
                            (!isAudioOnly || remoteHasVideo(p.publicId))
                        "
                        v-src-object="store.remoteStreams.get(p.publicId)"
                        autoplay
                        playsinline
                        class="video-element"
                    />
                    <!-- Avatar Fallback (Audio Only or No Video) -->
                    <div v-else class="avatar-fallback">
                        <img
                            v-if="p.avatar"
                            :src="p.avatar"
                            class="avatar-img"
                        />
                        <div
                            v-else
                            class="avatar-placeholder"
                            :style="{ backgroundColor: 'var(--avatar-bg)' }"
                        >
                            {{ p.name[0] }}
                        </div>
                        <div class="audio-indicator">
                            <Icon name="Mic" size="16" />
                        </div>
                    </div>

                    <div class="participant-info">
                        <span class="participant-name">{{ p.name }}</span>
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

                <!-- 2. Local Participant (Me) -->
                <div
                    class="video-cell local"
                    :class="{
                        'pip-mode': participants.length >= 2,
                        'audio-mode': isAudioOnly && !isScreenSharing,
                        'is-talking': talkingParticipants.has(
                            callData?.selfPublicId?.toLowerCase() || '',
                        ),
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
                        autoplay
                        muted
                        playsinline
                        class="video-element"
                        :class="{ 'mirror-off': isScreenSharing }"
                    />
                    <div v-else class="avatar-fallback">
                        <div class="avatar-placeholder local">
                            <span>Me</span>
                        </div>
                    </div>
                    <div class="participant-info">
                        <span class="participant-name">You</span>
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
                    class="control-btn hangup"
                    @click="endCall('hangup')"
                    title="End Call"
                >
                    <Icon name="PhoneOff" size="24" />
                </button>
            </div>
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
    font-family:
        "Inter",
        system-ui,
        -apple-system,
        sans-serif;
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
    background: radial-gradient(
        circle at center,
        transparent 0%,
        rgba(0, 0, 0, 0.6) 100%
    );
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
.status-dot.connecting,
.status-dot.ringing {
    background: #3b82f6;
    animation: pulse 1.5s infinite;
}
.status-dot.error {
    background: #ef4444;
}

.status-text {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.02em;
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
    opacity: 0.9;
}

/* Join Screen / Lobby */
.join-screen {
    animation: fadeIn 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}

.avatar-preview {
    margin-bottom: 32px;
    position: relative;
}

.preview-circle {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
    font-weight: 700;
    color: white;
    box-shadow: 0 12px 40px rgba(99, 102, 241, 0.4);
    border: 4px solid rgba(255, 255, 255, 0.1);
    animation: float 6s ease-in-out infinite;
}

.join-title {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 12px;
    letter-spacing: -0.02em;
}

.join-subtitle {
    font-size: 18px;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 48px;
}

.join-actions {
    display: flex;
    gap: 20px;
    width: 100%;
    max-width: 400px;
}

.btn-join,
.btn-decline,
.btn-secondary {
    flex: 1;
    padding: 16px 24px;
    border-radius: 18px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.btn-join {
    background: #10b981;
    color: white;
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);
}
.btn-join:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 0 12px 32px rgba(16, 185, 129, 0.4);
}
.btn-join:active {
    transform: translateY(0) scale(0.98);
}

.btn-decline {
    background: rgba(255, 255, 255, 0.05);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
}
.btn-decline:hover {
    background: rgba(239, 68, 68, 0.1);
    transform: translateY(-4px);
}

/* Grid & Video Cells */
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
    animation: cellAppear 0.5s cubic-bezier(0.22, 1, 0.36, 1);
    transition:
        box-shadow 0.3s ease,
        border-color 0.3s ease,
        transform 0.3s ease;
}

.video-cell.is-talking {
    border-color: #10b981;
    box-shadow: 0 0 24px rgba(16, 185, 129, 0.4);
    transform: scale(1.01);
    z-index: 20;
}

.video-cell.screen-share {
    flex: 2;
    min-width: 400px;
    background: #000;
}

.video-cell.screen-share.expanded {
    flex: 1 1 100%;
    height: auto;
    aspect-ratio: 16/9;
    max-height: 70vh;
}

.video-element {
    width: 100%;
    height: 100%;
    object-fit: cover;
    background: #000;
    transition: filter 0.3s ease;
}

/* Local PiP Mode */
.video-cell.local.pip-mode {
    position: absolute;
    bottom: calc(120px + env(safe-area-inset-bottom, 20px));
    right: 20px;
    width: 120px;
    height: 180px;
    z-index: 30;
    border-radius: 20px;
    border: 20px; /* actually border-width is handled by border below */
    border: 2px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.6);
}

.grid-1-1 .video-cell.remote {
    position: absolute;
    inset: 0;
    border-radius: 0;
    border: none;
    z-index: 5;
}

/* Ensure screen share sits on top of participant avatar in 1:1 */
.grid-1-1 .video-cell.remote.screen-share {
    z-index: 10;
}

/* Participant Info Overlay */
.participant-info {
    position: absolute;
    bottom: 16px;
    left: 16px;
    background: rgba(9, 9, 11, 0.5);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    padding: 6px 14px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: white;
    font-size: 13px;
    font-weight: 600;
    pointer-events: none;
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
    background: rgba(20, 20, 25, 0.7);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    padding: 14px 28px;
    border-radius: 40px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--glass-border);
    transition:
        transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
        bottom 0.3s ease;
}

.control-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, 0.08);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.2, 0, 0, 1);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.control-btn:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: scale(1.1) translateY(-2px);
    border-color: rgba(255, 255, 255, 0.3);
}

.control-btn:active {
    transform: scale(0.95);
}

.control-btn.off {
    background: #fafafa;
    color: #09090b;
}

.control-btn.hangup {
    background: #ef4444;
    color: white;
    border-color: rgba(255, 255, 255, 0.1);
}
.control-btn.hangup:hover {
    background: #dc2626;
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.4);
}

/* Animations */
@keyframes breathing {
    0%,
    100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.1);
        opacity: 0.8;
    }
}

@keyframes float {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes cellAppear {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* Grid Layouts */
.grid-2-2 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-auto-rows: 1fr;
    min-height: 0;
}

.grid-3-2 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    grid-auto-rows: 1fr;
    min-height: 0;
}

/* Mobile Overrides */
@media (max-width: 640px) {
    .grid-wrapper {
        padding: 12px;
        padding-bottom: calc(120px + env(safe-area-inset-bottom, 24px));
        gap: 12px;
    }

    .grid-2-2 .grid-wrapper,
    .grid-3-2 .grid-wrapper {
        grid-template-columns: 1fr;
    }

    .controls-bar {
        width: calc(100% - 32px);
        max-width: 380px;
        padding: 12px 16px;
        gap: 12px;
    }

    .control-btn {
        width: 54px;
        height: 54px;
    }

    .join-title {
        font-size: 28px;
    }
    .join-actions {
        flex-direction: column;
        gap: 12px;
        width: 100%;
    }

    .btn-join,
    .btn-decline {
        width: 100%;
        border-radius: 16px;
    }

    .grid-1-1 .video-cell.local.pip-mode {
        width: 100px;
        height: 150px;
        bottom: calc(110px + env(safe-area-inset-bottom, 20px));
        right: 12px;
    }
}

/* Avatar Fallbacks */
.avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #09090b;
}

.avatar-placeholder {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
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
    position: absolute;
    width: 0;
    height: 0;
    opacity: 0;
    pointer-events: none;
}
</style>
```
