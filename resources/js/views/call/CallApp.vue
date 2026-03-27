<script setup lang="ts">
/**
 * CallApp.vue — Hybrid Call Architecture (SFU + Mesh)
 * - Group Calls: Utilizing Cloudflare SFU (Selective Forwarding Unit) for high scalability and performance.
 * - 1:1 Calls: Supports Peer-to-Peer Mesh (Mesh Topology) with failover to SFU if needed.
 */
import "webrtc-adapter";
import {
    ref,
    computed,
    onMounted,
    onUnmounted,
    onBeforeUnmount,
    watch,
    reactive,
    triggerRef,
} from "vue";
import Peer from "simple-peer";
import { videoCallService } from "@/services/videocall.service";
import CallDevSimulationTool from "./components/CallDevSimulationTool.vue";
import { useVideoCallStore, type Participant } from "@/stores/videocall";
import { toast } from "vue-sonner";
import { useChatStore } from "@/stores/chat";
import { useAuthStore } from "@/stores/auth";
import { Icon, Avatar } from "@/components/ui";
import CallSettingsModal from "./components/CallSettingsModal.vue";
import { useChat } from "@/composables/useChat";
import CallChatList from "./components/CallChatList.vue";
import ChatComposer from "../chat/components/chat/ChatComposer.vue";
import NetworkHealthIndicator from "./components/NetworkHealthIndicator.vue";
import { Tooltip } from "@/components/ui";

import { useBackgroundBlur } from "@/composables/useBackgroundBlur";
import MediaViewer from "@/components/tools/MediaViewer.vue";
import { CallOrchestrator } from "./orchestrator/CallOrchestrator";
import { CallSignalingManager } from "./orchestrator/managers/CallSignalingManager";
import {
    acquireMicrophoneTrack,
    acquireCameraTrack,
    applyOutputDevice as applyOutputDeviceThroughManager,
} from "./orchestrator/managers/CallMediaDeviceManager";
import { CallSfuSessionManager } from "./orchestrator/managers/CallSfuSessionManager";
import { CallSfuMediaManager } from "./orchestrator/managers/CallSfuMediaManager";
import { CallSfuSyncManager } from "./orchestrator/managers/CallSfuSyncManager";
import { CallSfuSignalManager } from "./orchestrator/managers/CallSfuSignalManager";
import { CallMeshManager } from "./orchestrator/managers/CallMeshManager";
import { CallSignalRuntimeManager } from "./orchestrator/managers/CallSignalRuntimeManager";

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
const isJoining = ref(false);
const permissionBlocked = ref(false);
const permissionErrorMessage = ref<string | null>(null);
const windowWidth = ref(window.innerWidth);
const isMobile = computed(() => {
    const isMobileUA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
        navigator.userAgent,
    );
    return isMobileUA || windowWidth.value <= 768;
});

const handleResize = () => {
    windowWidth.value = window.innerWidth;
};

onMounted(() => {
    window.addEventListener("resize", handleResize);
    window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
    window.removeEventListener("resize", handleResize);
    window.removeEventListener('keydown', handleGlobalKeydown);
});

const error = ref<string | null>(null);
const finalReason = ref<string | null>(null);
const ringingRemoteUser = ref<any>(null);
const store = useVideoCallStore();
const chatStore = useChatStore();
const authStore = useAuthStore();
// const route = useRoute(); // Not available in standalone app

// Chat Integration
const showSidebar = ref(false); // Default closed
const sidebarTab = ref<"people" | "chat">("people");
const {
    activeChat,
    activeMessages,
    messageInput,
    messagesContainerRef,
    isLoadingMore: isChatLoadingMore,
    isSending,
    replyingTo,
    pendingFiles,
    typingIndicator,

    selectChat,
    sendMessage,
    loadMoreMessages,
    setReplyTo,
    addFiles,
    removeFile,
    handleInputChange,
    sendGif,
} = useChat({ autoFetch: true });

// Auto-Close Logic
watch(callState, (newState) => {
    if (newState === "ended") {
        const reason = finalReason.value ?? "hangup";
        const delay = ["no_answer", "timeout", "busy"].includes(reason) ? 5000 : 2000;
        
        console.log(`[CallApp] Call ended (${reason}). Closing window in ${delay}ms...`);
        setTimeout(() => {
            window.close();
        }, delay);
    }
});

watch(
    () => store.error,
    (newError) => {
        if (newError) {
            console.warn(
                "[CallApp] Critical error detected. Closing window in 5s...",
                newError,
            );
            setTimeout(() => {
                window.close();
            }, 5000);
        }
    },
);

// Media Operation Queue to prevent race conditions during track swaps
let mediaQueue = Promise.resolve();
async function runInQueue<T>(op: () => Promise<T>): Promise<T> {
    const next = mediaQueue.then(op);
    mediaQueue = next.then(() => {}, () => {}); // Catch both success and failure
    return next;
}

const isGroupCall = computed(() => {
    return activeChat.value?.type === "group" || participants.value.length > 2;
});

const handleMessageReply = (message: any) => {
    setReplyTo(message);
};

const toggleSidebar = (tab?: "people" | "chat") => {
    if (tab) {
        if (showSidebar.value && sidebarTab.value === tab) {
            showSidebar.value = false; // Close if clicking same tab
        } else {
            showSidebar.value = true;
            sidebarTab.value = tab;
        }
    } else {
        showSidebar.value = !showSidebar.value;
    }
};

// Media
const localStream = ref<MediaStream | null>(null);
const isMuted = ref(true); // Default to Mic Off as requested
const isCameraOff = ref(true); // Default to Video Off as requested
const isAudioOnly = computed(() => callData.value?.callType === "audio");

// Background Blur
const backgroundBlur = useBackgroundBlur();
const originalVideoTrack = ref<MediaStreamTrack | null>(null);


// Network Stats
const networkStats = reactive({
    bitrate: 0,
    packetLoss: 0,
    rtt: 0,
    score: -1, // -1=Unknown, 0=Good, 1=Fair, 2=Poor
});
const participantStats = reactive(
    new Map<
        string,
        { bitrate: number; packetLoss: number; rtt: number; score: number }
    >(),
);
// Signaling-based remote media state (cross-browser reliable)
const remoteMediaState = reactive(new Map<string, { muted: boolean; cameraOff: boolean }>());
const remoteCameraLoadingState = reactive(new Map<string, boolean>());
const sfuScreenMid = ref<string | null>(null);
const lastInboundPacketsLost = reactive(new Map<string, number>());
const lastInboundPacketsReceived = reactive(new Map<string, number>());
const lastInboundBytes = reactive(new Map<string, number>());
let smoothedRtt = 0;
let rttStaleCount = 0;
const POOR_CONNECTION_THRESHOLD = 3;
const poorConnectionTimer = reactive(new Map<string, number>());

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
const chatListRef = ref<any>(null);

// Heartbeat Management
async function runHeartbeatTick() {
    if (!callData.value) return;

    try {
        await videoCallService.sendHeartbeat(
            callData.value.chatId,
            callData.value.callId,
        );
    } catch (e) {
        console.warn("[CallApp] Heartbeat failed:", e);
    }
}

const callOrchestrator = new CallOrchestrator({
    shouldHeartbeat: () =>
        callState.value === "connected" && !!callData.value,
    heartbeat: runHeartbeatTick,
    pollNetworkStats: updateNetworkStats,
    onWarn: (message, error) => console.warn(`[CallOrchestrator] ${message}`, error),
});

const callSignalingManager = new CallSignalingManager({
    getCallData: () =>
        callData.value
            ? {
                  callId: callData.value.callId,
                  chatId: callData.value.chatId,
                  chatType: callData.value.chatType,
              }
            : null,
    onCallSignal: (event) => handleSignal(event),
    onParticipantJoined: (event) => handleParticipantJoined(event),
    onParticipantLeft: (event) => handleParticipantLeft(event),
    onCallEnded: (event) => handleCallEndedEvent(event),
});

const sfuSessionManager = new CallSfuSessionManager();
let sfuSyncManager: CallSfuSyncManager | null = null;
let sfuSignalManager: CallSfuSignalManager | null = null;
let meshManager: CallMeshManager | null = null;
const signalRuntimeManager = new CallSignalRuntimeManager();
let outgoingNoAnswerTimer: ReturnType<typeof setTimeout> | null = null;
const OUTGOING_NO_ANSWER_MS = 45000;
const meshRecoveryAttempts = reactive(
    new Map<string, { count: number; windowStartAt: number }>(),
);
const meshRecoveryTimers = new Map<string, ReturnType<typeof setTimeout>>();
const MESH_RECOVERY_MAX_ATTEMPTS = 4;
const MESH_RECOVERY_WINDOW_MS = 30000;
const MESH_RECOVERY_BASE_DELAY_MS = 300;

watch(callState, () => {
    callOrchestrator.syncHeartbeat();
});

watch(
    [callState, () => callData.value?.direction],
    ([state, direction]) => {
        if (outgoingNoAnswerTimer) {
            clearTimeout(outgoingNoAnswerTimer);
            outgoingNoAnswerTimer = null;
        }

        // Local fallback in case parent-side timer is unavailable.
        if (state === "ringing" && direction === "outgoing") {
            outgoingNoAnswerTimer = setTimeout(() => {
                if (
                    callState.value === "ringing" &&
                    callData.value?.direction === "outgoing"
                ) {
                    toast.info("Call was not answered");
                    endCall("no_answer");
                }
            }, OUTGOING_NO_ANSWER_MS);
        }
    },
    { immediate: true },
);

// Link the child's scroll container to useChat composable
watch(
    () => chatListRef.value?.container,
    (newVal) => {
        if (newVal) messagesContainerRef.value = newVal;
    },
    { immediate: true },
);

// Mark as read when messages arrive and chat is visible
watch(
    [
        () => activeMessages.value.length,
        () => showSidebar.value,
        () => sidebarTab.value,
    ],
    ([_, visible, tab]) => {
        if (visible && tab === "chat" && activeChat.value?.public_id) {
            chatStore.markAsRead(activeChat.value.public_id);
        }
    },
);
const participantTransceivers = new Map<
    string,
    { audioMid?: string; videoMid?: string; screenMid?: string }
>();

function updateRemoteMediaStateFromStream(
    participantId: string,
    stream: MediaStream,
) {
    const pid = participantId.toLowerCase();
    const currentState = remoteMediaState.get(pid) || {
        muted: false,
        cameraOff: true,
    };

    const hasAudioTrack = stream.getAudioTracks().length > 0;
    const hasLiveAudio = stream
        .getAudioTracks()
        .some((track) => track.readyState === "live" && !track.muted);
    const hasVideoTrack =
        stream.getVideoTracks().length > 0 &&
        stream.getVideoTracks().some((track) => track.readyState === "live");

    const prevCameraOff = !!currentState.cameraOff;
    const nextCameraOff = hasVideoTrack ? false : currentState.cameraOff;

    remoteMediaState.set(pid, {
        muted: hasAudioTrack ? (hasLiveAudio ? false : currentState.muted) : true,
        cameraOff: nextCameraOff,
    });

    if (prevCameraOff !== nextCameraOff) {
        console.log(`[Call] Media state transition for ${pid}: cameraOff=${prevCameraOff} -> ${nextCameraOff}`);
        remoteCameraLoadingState.set(pid, true);
        setTimeout(() => {
            remoteCameraLoadingState.set(pid, false);
        }, 900);
    }
}

function bindRemoteTrackLifecycle(participantId: string, stream: MediaStream) {
    const pid = participantId.toLowerCase();
    const sync = () => updateRemoteMediaStateFromStream(pid, stream);

    stream.getVideoTracks().forEach((track) => {
        track.onunmute = () => {
            console.log(`[Call] Remote video track from ${pid} UNMUTED`);
            sync();
        };
        track.onended = () => {
            console.log(`[Call] Remote video track from ${pid} ENDED`);
            sync();
        };
    });

    stream.getAudioTracks().forEach((track) => {
        track.onunmute = () => sync();
        track.onended = () => sync();
    });
}

function clearMeshRecovery(participantId: string) {
    const pid = participantId.toLowerCase();
    const timer = meshRecoveryTimers.get(pid);
    if (timer) {
        clearTimeout(timer);
        meshRecoveryTimers.delete(pid);
    }
    meshRecoveryAttempts.delete(pid);
}

function canRecoverMeshPeer(participantId: string): boolean {
    const pid = participantId.toLowerCase();
    const selfId = (callData.value?.selfPublicId || "").toLowerCase();
    if (!pid || pid === selfId) return false;
    if (callMode.value !== "mesh") return false;
    if (!hasJoined.value || !isTransportReady.value) return false;
    if (
        callState.value === "initializing" ||
        callState.value === "ended" ||
        callState.value === "error"
    ) {
        return false;
    }

    return participants.value.some((p) => p.publicId.toLowerCase() === pid);
}

function scheduleMeshPeerRecovery(
    participantId: string,
    reason = "peer-closed",
) {
    const pid = participantId.toLowerCase();
    if (!canRecoverMeshPeer(pid)) return;
    if (peers.has(pid)) return;

    const existingTimer = meshRecoveryTimers.get(pid);
    if (existingTimer) {
        clearTimeout(existingTimer);
        meshRecoveryTimers.delete(pid);
    }

    const now = Date.now();
    const existingAttempt = meshRecoveryAttempts.get(pid);
    const attempt =
        !existingAttempt ||
        now - existingAttempt.windowStartAt > MESH_RECOVERY_WINDOW_MS
            ? { count: 0, windowStartAt: now }
            : existingAttempt;

    if (attempt.count >= MESH_RECOVERY_MAX_ATTEMPTS) {
        console.warn(
            `[Call] Mesh recovery capped for ${pid} (${attempt.count}/${MESH_RECOVERY_MAX_ATTEMPTS})`,
        );
        return;
    }

    attempt.count += 1;
    meshRecoveryAttempts.set(pid, attempt);
    const delay = Math.min(1500, MESH_RECOVERY_BASE_DELAY_MS * attempt.count);

    const timer = setTimeout(() => {
        meshRecoveryTimers.delete(pid);
        if (!canRecoverMeshPeer(pid) || peers.has(pid)) {
            return;
        }

        const selfId = (callData.value?.selfPublicId || "").toLowerCase();
        const isInitiator = selfId > pid;
        console.log(
            `[Call] Recovering peer ${pid} (reason: ${reason}, attempt ${attempt.count}/${MESH_RECOVERY_MAX_ATTEMPTS}, initiator: ${isInitiator})`,
        );
        meshManager?.createPeer(
            pid,
            isInitiator,
            getMeshPeerSeedStream(),
        );
    }, delay);

    meshRecoveryTimers.set(pid, timer);
}

const sfuMediaManager = new CallSfuMediaManager({
    getPeerConnection: () => sfuPc,
    setPeerConnection: (peerConnection) => {
        sfuPc = peerConnection;
    },
    getSessionId: () => sfuSessionId.value,
    setSessionId: (sessionId) => {
        sfuSessionId.value = sessionId;
    },
    getCallData: () =>
        callData.value
            ? { chatId: callData.value.chatId, callId: callData.value.callId }
            : null,
    getIceServers: () => iceServers.value,
    getParticipants: () => participants.value,
    getRemoteSfuSessions: () => remoteSfuSessions,
    getRemoteSfuTracks: () => remoteSfuTracks,
    participantTransceivers,
    sfuSessionManager,
    mungeSdp,
    trace,
    onRemoteMainStream: (participantId, stream) => {
        store.addRemoteStream(participantId, stream);
        startAudioAnalysis(participantId, stream);
        bindRemoteTrackLifecycle(participantId, stream);
        updateRemoteMediaStateFromStream(participantId, stream);
        markCallConnected();
    },
    onRemoteScreenStream: (participantId, stream) => {
        store.addRemoteScreenStream(participantId, stream);
    },
    onRemoteTrackInactive: (participantId, track) => {
        sfuSyncManager?.handleRemoteTrackInactive(participantId, track);
    },
    flushPendingTracks,
    onHandle406Rescue: handleSFU406Rescue,
    setScreenMid: (mid) => {
        sfuScreenMid.value = mid;
    },
    onParticipantPullExhausted: ({ participantId }) => {
        requestRemoteMediaInfo(participantId, true);
    },
});

/**
 * Update Network Stats
 */
async function updateNetworkStats() {
    // 1. Global Stats (Local OUTBOUND or first Peer)
    let mainPc: RTCPeerConnection | null = null;
    if (callMode.value === "sfu" && sfuPc) mainPc = sfuPc;
    else if (peers.size > 0)
        mainPc = (Array.from(peers.values())[0] as any)?._pc;

    if (mainPc) {
        try {
            const stats = await mainPc.getStats();
            let rttUpdated = false;
            stats.forEach((report) => {
                if (
                    report.type === "candidate-pair" &&
                    report.state === "succeeded"
                ) {
                    const rawRtt = report.currentRoundTripTime || 0;
                    if (rawRtt > 0) {
                        // HEURISTIC: Firefox bug (Bug 1981042) reports RTT in ms instead of s.
                        networkStats.rtt = rawRtt > 1.0 ? rawRtt : rawRtt * 1000;
                        rttUpdated = true;
                    }
                }
            });

            // Stale RTT guard: if RTT hasn't been updated for 5+ polls (~15s), zero it out
            if (!rttUpdated && networkStats.rtt > 0) {
                rttStaleCount++;
            } else {
                rttStaleCount = 0;
            }
            const effectiveRtt = rttStaleCount >= 5 ? 0 : networkStats.rtt;

            // RTT Smoothing
            if (effectiveRtt > 0) {
                if (smoothedRtt === 0) smoothedRtt = effectiveRtt;
                else smoothedRtt = (smoothedRtt * 0.7) + (effectiveRtt * 0.3);
            }
        } catch (e) {}
    }

    // 2. Per-Participant Stats (INBOUND)
    if (callMode.value === "sfu" && sfuPc) {
        try {
            const stats = await sfuPc.getStats();
            const currentParticipantPackets = new Map<string, { lost: number, received: number, bytes: number }>();
            
            stats.forEach((report) => {
                if (report.type === "inbound-rtp") {
                    let pId: string | null = null;
                    for (const [id, mids] of participantTransceivers.entries()) {
                        if (mids.audioMid === report.mid || mids.videoMid === report.mid) {
                            pId = id;
                            break;
                        }
                    }

                    if (pId) {
                        const pIdLower = pId.toLowerCase();
                        const pData = currentParticipantPackets.get(pIdLower) || { lost: 0, received: 0, bytes: 0 };
                        pData.lost += report.packetsLost || 0;
                        pData.received += report.packetsReceived || 0;
                        pData.bytes += report.bytesReceived || 0;
                        currentParticipantPackets.set(pIdLower, pData);
                    }
                }
            });

            // Process aggregated participant data
            for (const [pIdLower, pData] of currentParticipantPackets.entries()) {
                const current = participantStats.get(pIdLower) || { bitrate: 0, packetLoss: 0, rtt: 0, score: 0 };
                
                let deltaLost = pData.lost - (lastInboundPacketsLost.get(pIdLower) || 0);
                let deltaReceived = pData.received - (lastInboundPacketsReceived.get(pIdLower) || 0);
                let deltaBytes = pData.bytes - (lastInboundBytes.get(pIdLower) || 0);

                if (deltaLost < 0 || deltaReceived < 0 || deltaBytes < 0) {
                    deltaLost = pData.lost;
                    deltaReceived = pData.received;
                    deltaBytes = pData.bytes;
                }

                const deltaTotal = deltaLost + deltaReceived;
                const lossPercent = deltaTotal > 0 ? (deltaLost / deltaTotal) * 100 : 0;
                
                lastInboundPacketsLost.set(pIdLower, pData.lost);
                lastInboundPacketsReceived.set(pIdLower, pData.received);
                lastInboundBytes.set(pIdLower, pData.bytes);

                current.packetLoss = lossPercent;
                current.bitrate = (deltaBytes * 8) / 5000; // kbps assuming 5s interval
                current.rtt = smoothedRtt;

                // RTT-primary scoring: if RTT < 100ms, connection is good
                if (smoothedRtt > 0 && smoothedRtt < 100) {
                    current.score = 0;
                } else if (smoothedRtt >= 500 || (lossPercent > 10 && smoothedRtt >= 200)) {
                    current.score = 2;
                } else if (smoothedRtt >= 200 || (lossPercent > 5 && smoothedRtt >= 100)) {
                    current.score = 1;
                } else {
                    current.score = 0;
                }

                participantStats.set(pIdLower, current);
            }
        } catch (e) {}
    } else {
        // MESH Mode
        for (const [pId, peer] of peers.entries()) {
            try {
                const pc = (peer as any)._pc as RTCPeerConnection;
                const stats = await pc.getStats();
                const pIdLower = pId.toLowerCase();
                const current = { bitrate: 0, packetLoss: 0, rtt: 0, score: 0 };

                stats.forEach((report) => {
                    if (
                        report.type === "inbound-rtp" &&
                        report.kind === "audio"
                    ) {
                        const lost = report.packetsLost || 0;
                        const received = report.packetsReceived || 0;
                        
                        // INTERVAL-BASED LOSS (Mesh)
                        const lastLost = lastInboundPacketsLost.get(pIdLower) || 0;
                        const lastReceived = lastInboundPacketsReceived.get(pIdLower) || 0;
                        const deltaLost = lost - lastLost;
                        const deltaReceived = received - lastReceived;
                        const deltaTotal = deltaLost + deltaReceived;
                        
                        current.packetLoss = deltaTotal > 0 ? (deltaLost / deltaTotal) * 100 : 0;
                        
                        lastInboundPacketsLost.set(pIdLower, lost);
                        lastInboundPacketsReceived.set(pIdLower, received);
                    }
                    if (
                        report.type === "candidate-pair" &&
                        report.state === "succeeded"
                    ) {
                        const rawRtt = report.currentRoundTripTime || 0;
                        current.rtt = rawRtt > 1.0 ? rawRtt : rawRtt * 1000;
                    }
                });

                // RTT-primary scoring for Mesh
                if (current.rtt > 0 && current.rtt < 100) {
                    current.score = 0;
                } else if (current.rtt >= 500 || (current.packetLoss > 10 && current.rtt >= 200)) {
                    current.score = 2;
                } else if (current.rtt >= 200 || (current.packetLoss > 5 && current.rtt >= 100)) {
                    current.score = 1;
                } else {
                    current.score = 0;
                }

                if (current.score === 2) {
                    // Poor connection: reduce sender bitrate to help
                    limitSenderBitrate(150000); // 150kbps
                } else if (current.score === 0) {
                    limitSenderBitrate(1500000); // 1.5Mbps
                }

                participantStats.set(pIdLower, current);
            } catch (e) {}
        }
    }

    const activeRemoteIds = participants.value
        .filter((participant) => !participant.isSelf)
        .map((participant) => participant.publicId.toLowerCase());
    let aggregateBitrate = 0;
    let aggregatePacketLoss = 0;
    let aggregateRtt = 0;
    let aggregateCount = 0;
    let worstScore = -1;

    for (const participantId of activeRemoteIds) {
        const stats = participantStats.get(participantId);
        if (!stats) continue;
        aggregateBitrate += stats.bitrate;
        aggregatePacketLoss += stats.packetLoss;
        aggregateRtt += stats.rtt;
        aggregateCount += 1;
        worstScore = Math.max(worstScore, stats.score);
    }

    if (aggregateCount === 0) {
        networkStats.bitrate = 0;
        networkStats.packetLoss = 0;
        
        // Firefox / Alone-in-call Fallback: use RTT to SFU
        if (smoothedRtt > 0) {
            networkStats.rtt = smoothedRtt;
            if (smoothedRtt < 100) networkStats.score = 0;
            else if (smoothedRtt >= 200) networkStats.score = 2;
            else networkStats.score = 1;
        } else {
            networkStats.score = -1;
        }
        return;
    }

    networkStats.bitrate = aggregateBitrate / aggregateCount;
    networkStats.packetLoss = aggregatePacketLoss / aggregateCount;
    networkStats.rtt = aggregateRtt / aggregateCount;
    networkStats.score = worstScore;
}

// Adaptive Bitrate: Limit local sender bandwidth if network is struggling
async function limitSenderBitrate(maxBitrate: number) {
    if (!sfuPc) return;
    const senders = sfuPc.getSenders();
    for (const sender of senders) {
        if (sender.track?.kind === "video") {
            const params = sender.getParameters();
            if (params.encodings && params.encodings.length > 0) {
                // Adjust all active encodings to fit within the budget
                params.encodings.forEach((e) => {
                    e.maxBitrate = Math.min(
                        e.maxBitrate || 1500000,
                        maxBitrate,
                    );
                });
                try {
                    await sender.setParameters(params);
                    console.log(
                        `[Adaptation] Limited sender bitrate to ${maxBitrate} bps`,
                    );
                } catch (e) {
                    console.warn(
                        "[Adaptation] Failed to set sender parameters",
                        e,
                    );
                }
            }
        }
    }
}

function applyIceServersToActiveConnections() {
    if (!iceServers.value.length) return;

    if (sfuPc) {
        try {
            const cfg = sfuPc.getConfiguration();
            sfuPc.setConfiguration({
                ...cfg,
                iceServers: iceServers.value,
            });
            console.log("[Call] Updated SFU peer ICE configuration");
        } catch (e) {
            console.warn("[Call] Failed to update SFU ICE configuration", e);
        }
    }

    peers.forEach((peer, participantId) => {
        const pc = (peer as any)?._pc as RTCPeerConnection | undefined;
        if (!pc) return;

        try {
            const cfg = pc.getConfiguration();
            pc.setConfiguration({
                ...cfg,
                iceServers: iceServers.value,
            });
        } catch (e) {
            console.warn(
                `[Call] Failed to update peer ICE configuration for ${participantId}`,
                e,
            );
        }
    });
}

// Participants & Peers
const participants = ref<Participant[]>([]);
const peers = new Map<string, Peer.Instance>();
const iceServers = ref<RTCIceServer[]>([]);
let turnRefreshInterval: ReturnType<typeof setInterval> | null = null;

sfuSignalManager = new CallSfuSignalManager({
    getCallMode: () => callMode.value,
    getCallData: () =>
        callData.value
            ? { chatId: callData.value.chatId, callId: callData.value.callId }
            : null,
    getPeerConnection: () => sfuPc,
    getSessionId: () => sfuSessionId.value,
    mediaInfoThrottleMs: 10000,
    midRetryDelayMs: 2000,
    maxMidRetries: 3,
});

sfuSyncManager = new CallSfuSyncManager({
    getCallMode: () => callMode.value,
    getHasJoined: () => hasJoined.value,
    getIsTransportReady: () => isTransportReady.value,
    getParticipants: () => participants.value,
    getRemoteSessionId: (participantId) => remoteSfuSessions.get(participantId),
    getParticipantMids: (participantId) =>
        participantTransceivers.get(participantId),
    getRemoteMainStream: (participantId) => store.remoteStreams.get(participantId),
    getRemoteScreenStream: (participantId) =>
        store.remoteScreenStreams.get(participantId),
    requestRemoteMediaInfo: (participantId, force) =>
        sfuSignalManager?.requestRemoteMediaInfo(participantId, force),
    pullParticipantTracks: (
        participantId,
        remoteSessionId,
        audioMid,
        videoMid,
        screenMid,
        mediaType,
    ) =>
        pullParticipantTracks(
            participantId,
            remoteSessionId,
            audioMid,
            videoMid,
            screenMid,
            mediaType,
        ),
    pullRemoteScreen: (participantId, screenMid, remoteSessionId) =>
        pullRemoteScreen(participantId, screenMid, remoteSessionId),
    isVisible: (participantId) =>
        visibleParticipants.value.has(participantId.toLowerCase()),
    getRemoteMediaState: (participantId) => remoteMediaState.get(participantId),
    setRemoteMediaState: (participantId, state) =>
        remoteMediaState.set(participantId, state),
    upsertMainStream: (participantId, stream) =>
        store.addRemoteStream(participantId, stream),
    removeMainStream: (participantId) => store.removeRemoteStream(participantId),
    upsertScreenStream: (participantId, stream) =>
        store.addRemoteScreenStream(participantId, stream),
    removeScreenStream: (participantId) =>
        store.removeRemoteScreenStream(participantId),
    stopAudioAnalysis: (participantId) => stopAudioAnalysis(participantId),
    intervalMs: 15000,
    log: (message) => console.log(`[SFU] ${message}`),
});

meshManager = new CallMeshManager({
    getPeers: () => peers,
    getCallData: () =>
        callData.value
            ? { chatId: callData.value.chatId, callId: callData.value.callId }
            : null,
    getIceServers: () => iceServers.value,
    shouldReserveVideoMLine: () => true,
    mungeSdp,
    onMainStream: (participantId, stream) => {
        clearMeshRecovery(participantId);
        store.addRemoteStream(participantId, stream);
        startAudioAnalysis(participantId, stream);
        bindRemoteTrackLifecycle(participantId, stream);
        updateRemoteMediaStateFromStream(participantId, stream);
        markCallConnected();
    },
    onScreenStream: (participantId, stream) => {
        store.addRemoteScreenStream(participantId, stream);
    },
    onPeerClosed: (participantId) => {
        store.removeRemoteStream(participantId);
        store.removeRemoteScreenStream(participantId);
        remoteCameraLoadingState.delete(participantId.toLowerCase());
        remoteMediaState.delete(participantId.toLowerCase());
        scheduleMeshPeerRecovery(participantId);
    },
});

// Voice Activity Detection
const talkingParticipants = reactive(new Set<string>());
const isControlsCollapsed = ref(false);


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
    updated: (el: HTMLMediaElement, binding: any) => {
        if (el.srcObject !== binding.value) {
            el.srcObject = binding.value;
            if (binding.value instanceof MediaStream) {
                setupStreamListeners(el, binding.value);
            }
        }
    },
    mounted: (el: HTMLMediaElement, binding: any) => {
        el.srcObject = binding.value;
        if (binding.value instanceof MediaStream) {
            setupStreamListeners(el, binding.value);
        }
    },
};

function setupStreamListeners(el: HTMLMediaElement, stream: MediaStream) {
    let timeout: any = null;
    const refresh = () => {
        if (timeout) clearTimeout(timeout);
        timeout = setTimeout(() => {
            if (el.srcObject === stream) {
                const tracks = stream.getTracks();
                const liveTracks = tracks.filter(t => t.readyState === 'live');
                console.log(`[Call][UI] Refreshing media for stream ${stream.id} (tracks: ${tracks.length}, live: ${liveTracks.length})`);
                
                // Only "brute force" if we have tracks but no playback
                if (liveTracks.length > 0) {
                    // Try play first
                    el.play().catch(() => {
                        // If play fails, force a re-bind
                        el.srcObject = null;
                        el.srcObject = stream;
                        el.play().catch(() => {});
                    });
                }
            }
            timeout = null;
        }, 100);
    };
    stream.onaddtrack = refresh;
    stream.onremovetrack = refresh;
    stream.addEventListener("tracks-updated", refresh);
}

// Helper: Apply Output Device (setSinkId) to all media elements
// This is critical for "Speaker Control" (sc) to work.
async function applyOutputDevice(deviceId: string | null) {
    await applyOutputDeviceThroughManager(deviceId, null);
}

// Watch for output device changes and apply
watch(
    () => store.selectedOutputDeviceId,
    (newId) => {
        applyOutputDevice(newId);
    },
);

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
    },
};

// UI Refs
// Timers & Channels
let durationTimer: ReturnType<typeof setInterval> | null = null;
let connectedFallbackTimer: ReturnType<typeof setTimeout> | null = null;
const callDuration = ref(0);
let broadcastChannel: BroadcastChannel | null = null;

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

function markCallConnected() {
    if (
        callState.value === "connected" ||
        callState.value === "ended" ||
        callState.value === "error"
    ) {
        return;
    }
    callState.value = "connected";
    postToParent({ type: "state", state: "connected" });
    startDurationTimer();
    if (connectedFallbackTimer) {
        clearTimeout(connectedFallbackTimer);
        connectedFallbackTimer = null;
    }
}

function scheduleConnectedFallback() {
    if (connectedFallbackTimer) {
        clearTimeout(connectedFallbackTimer);
    }
    connectedFallbackTimer = setTimeout(() => {
        if (participants.value.some((participant) => !participant.isSelf)) {
            markCallConnected();
        }
    }, 4000);
}

// Settings Modal
const showSettings = ref(false);

const formattedDuration = computed(() => {
    const mins = Math.floor(callDuration.value / 60);
    const secs = callDuration.value % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
});

// UI Polish: Dynamic Layout Mode
// UI Polish: Dynamic Layout Mode
const layoutMode = computed(() => {
    // 1. 1:1 Mode: Exactly 2 participants
    if (!isGroupCall.value) {
        return "one-to-one";
    }

    // 2. Grid Mode: Group calls (3+ participants)
    return "grid";
});

const filmstripParticipants = computed(() => {
    const mocks = Array.from(store.mockParticipants.values());
    const real = participants.value;
    return [...real, ...mocks];
});

// Pagination Logic
const currentPage = ref(0);
const pageSize = computed(() => (isMobile.value ? 9 : 12));

const paginatedParticipants = computed(() => {
    const all = filmstripParticipants.value;
    // For 1:1 or 2 participants, we don't paginate (PiP handles it)
    if (all.length <= 2) return all;
    
    const start = currentPage.value * pageSize.value;
    return all.slice(start, start + pageSize.value);
});

const totalPages = computed(() => Math.ceil(filmstripParticipants.value.length / pageSize.value));

const paginatedNonSelfParticipants = computed(() => {
    return paginatedParticipants.value.filter((p) => !p.isSelf);
});

const visibleParticipants = computed(() => {
    const ids = new Set<string>();
    paginatedParticipants.value.forEach((p) => {
        ids.add(p.publicId.toLowerCase());
    });
    return ids;
});

watch(visibleParticipants, (newIds) => {
    if (callMode.value === "sfu" && isTransportReady.value) {
        sfuMediaManager.syncRemoteVideoVisibility(newIds);
    }
});

// Reset page if it goes out of bounds
watch(totalPages, (newTotal) => {
    if (currentPage.value >= newTotal && newTotal > 0) {
        currentPage.value = newTotal - 1;
    }
});

const gridClass = computed(() => {
    if (layoutMode.value === "spotlight") return "layout-spotlight";
    if (layoutMode.value === "one-to-one") return "layout-1-1";

    // Standard Grid Logic
    const count = paginatedParticipants.value.length;
    
    if (count <= 1) return "grid-1-1";
    if (count === 2) return "grid-1-1"; // PiP logic handles this
    if (count <= 4) return "grid-2-2";
    if (count <= 9) return "grid-3-3";
    return "grid-4-3"; // 4 cols, 3 rows for 12 participants
});


function sendSignal(type: string, payload: any) {
    callSignalingManager.sendAppSignal(type, payload || {});
}

function rebroadcastSfuMediaToJoiner(joinerPublicId: string) {
    sfuSignalManager?.rebroadcastSfuMediaToJoiner(joinerPublicId);
    sendSignal("media-state", {
        muted: isMuted.value,
        cameraOff: isCameraOff.value,
    });
}

function requestRemoteMediaInfo(participantPublicId: string, force = false) {
    sfuSignalManager?.requestRemoteMediaInfo(participantPublicId, force);
}

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

// Helper: Check for Remote Audio (Mute State) — uses signaling state
function remoteHasAudio(publicId: string) {
    const state = remoteMediaState.get(publicId.toLowerCase());
    if (state) return !state.muted;
    // Fallback to track check if no signal received yet
    const stream = store.remoteStreams.get(publicId);
    if (!stream) return false;
    const tracks = stream.getAudioTracks();
    return tracks.length > 0 && tracks[0].enabled;
}

const toggleChat = () => {
    console.log("[Call] Toggle Chat not implemented yet.");
    // alert("Chat coming soon!"); // visible feedback
};

const previewRemoteName = computed(() => {
    if (callData.value?.remoteUser) return callData.value?.remoteUser.name;
    return "Group Call";
});

// Watch Video Effect Changes
watch(
    [
        () => store.videoEffect,
        () => store.backgroundImage,
        () => store.autoFraming,
    ],
    async ([effect, bgImage, framing]) => {
        console.log("[Call] Video effect or framing changed:", {
            effect,
            framing,
            hasImage: !!bgImage,
        });
        if (!localStream.value) return;

        const currentVideoTrack = localStream.value.getVideoTracks()[0];

        // If we don't have the original track yet, try to get it
        if (
            !originalVideoTrack.value &&
            currentVideoTrack &&
            (effect === "blur" || effect === "image")
        ) {
            originalVideoTrack.value = currentVideoTrack;
        }

        if (!originalVideoTrack.value) return;

        try {
            let newTrack: MediaStreamTrack;

            if (effect === "blur" || effect === "image") {
                newTrack = await backgroundBlur.startVideoEffect(
                    originalVideoTrack.value,
                    effect,
                    bgImage || undefined,
                    framing,
                );
                console.log(
                    `[Call] ${effect} track received:`,
                    newTrack?.id,
                    "enabled:",
                    newTrack?.enabled,
                );
            } else {
                backgroundBlur.stopProcessing();
                newTrack = originalVideoTrack.value;
            }

            // Replace in Local Stream
            const oldTrack = localStream.value.getVideoTracks()[0];
            console.log("[Call] Track swap:", {
                oldId: oldTrack?.id,
                newId: newTrack?.id,
                same: oldTrack?.id === newTrack?.id,
            });
            if (oldTrack && oldTrack.id !== newTrack.id) {
                localStream.value.removeTrack(oldTrack);
                localStream.value.addTrack(newTrack);

                // Restore enabled state
                newTrack.enabled = !isCameraOff.value;

                // Replace in Peer Connections (Mesh)
                let meshReplaceCount = 0;
                peers.forEach((peer) => {
                    // @ts-ignore
                    const pc = peer._pc as RTCPeerConnection;
                    if (!pc) return;
                    const sender = pc
                        .getSenders()
                        .find((s: any) => s.track?.kind === "video");
                    if (sender) {
                        sender.replaceTrack(newTrack);
                        meshReplaceCount++;
                    }
                });
                console.log(
                    `[Call] Replaced video track in ${meshReplaceCount} mesh peer(s)`,
                );

                // Replace in SFU
                if (sfuPc) {
                    const senders = sfuPc.getSenders();
                    const videoSender = senders.find(
                        (s) =>
                            s.track?.kind === "video" ||
                            (s.track === null && s.dtmf === null),
                    ); // Fallback to find the video sender/transceiver

                    // More robust check: check transceiver mid or direction if track is missing
                    // cloudflare calls usually sets up 1 audio 1 video.

                    if (videoSender) {
                        console.log(
                            "[Call] Found SFU Video Sender:",
                            videoSender.track?.id,
                        );
                        videoSender
                            .replaceTrack(newTrack)
                            .then(() =>
                                console.log(
                                    "[Call] Successfully replaced video track in SFU",
                                ),
                            )
                            .catch((err) =>
                                console.error(
                                    "[Call] Failed to replace SFU track:",
                                    err,
                                ),
                            );
                    } else {
                        console.warn(
                            "[Call] Could not find SFU Video Sender to replace track",
                        );
                        // Try finding via transceivers
                        const transceivers = sfuPc.getTransceivers();
                        const videoTransceiver = transceivers.find(
                            (t) =>
                                t.sender.track?.kind === "video" ||
                                t.receiver.track?.kind === "video",
                        );
                        if (videoTransceiver) {
                            console.log(
                                "[Call] Found SFU Video Transceiver, replacing sender track...",
                            );
                            videoTransceiver.sender
                                .replaceTrack(newTrack)
                                .catch((e) =>
                                    console.error(
                                        "[Call] Transceiver replace failed:",
                                        e,
                                    ),
                                );
                        }
                    }
                }
            }
        } catch (e) {
            console.error("Failed to apply video effect", e);
            // Reset effect so the user gets their regular video feed
            if (effect === "blur") {
                store.setVideoEffect("none");
            }
        }
    },
);

// ============================================================================
// Watchers
// ============================================================================

// Local stream handling is now unified via v-src-object directive or ref in template

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
    if (isJoining.value || hasJoined.value) return;
    isJoining.value = true;
    console.log("[Call] Starting call session");

    try {
        callState.value = "initializing";
        permissionBlocked.value = false;
        permissionErrorMessage.value = null;
        const outboundStream = ensureLocalStreamContainer();

        callState.value = "connecting";

        if (!callData.value) return;

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
            applyIceServersToActiveConnections();

            // Refresh TURN credentials before expiry and push them into live PCs.
            turnRefreshInterval = setInterval(
                async () => {
                    try {
                        const freshTurn =
                            await videoCallService.getTurnCredentials(
                                callData.value!.chatId,
                            );
                        iceServers.value = freshTurn.ice_servers;
                        applyIceServersToActiveConnections();
                        console.log("[Call] TURN credentials refreshed");
                        // Trigger ICE restart if SFU connection is active
                        if (sfuPc && sfuSessionId.value) {
                            attemptSfuIceRestart();
                        }
                    } catch (e) {
                        console.warn(
                            "[Call] Failed to refresh TURN credentials:",
                            e,
                        );
                    }
                },
                45 * 60 * 1000,
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
                meshManager?.createPeer(
                    p.publicId,
                    isInitiator,
                    getMeshPeerSeedStream(),
                );
            }
            isTransportReady.value = true;
            sfuSyncManager?.stopHealthCheck();
        } else {
            console.log("[Call] Initializing SFU mode via Cloudflare");
            await joinSFU(outboundStream);
            isTransportReady.value = true;
            sfuSyncManager?.startHealthCheck();
        }

        // 4. Set state
        hasJoined.value = true;
        sendSignal("media-state", {
            muted: isMuted.value,
            cameraOff: isCameraOff.value,
        });

        if (callData.value.direction === "outgoing" && others.length === 0) {
            callState.value = "ringing";
            ringingRemoteUser.value = callData.value.remoteUser;
            console.log(
                "[Call] Outgoing call started: maintaining ringing state",
            );
        } else {
            callState.value = "connecting";
            scheduleConnectedFallback();
        }

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
        if (signalRuntimeManager.getPendingCount() > 0) {
            console.log(
                `[Call] Replaying ${signalRuntimeManager.getPendingCount()} signals from runtime buffer`,
            );
            const signals = signalRuntimeManager.drainPendingSignals();
            for (const sig of signals) {
                handleSignal(sig);
            }
        }

        // 6. Start local audio analysis for "talking indicator"
        if (localStream.value && localStream.value.getAudioTracks().length > 0) {
            startAudioAnalysis(selfId, localStream.value);
        }

        // Lazy media model:
        // transport joins first; physical devices are only requested when their toggles are ON.
        if (!isMuted.value) {
            await ensureLocalAudioTrack();
        }
        if (!isAudioOnly.value && !isCameraOff.value) {
            await enableCameraTrack();
        }
    } catch (err) {
        console.error("[Call] Join failed:", err);
        handleCallFailed();
    } finally {
        isJoining.value = false;
    }
}

async function retryPermissionAndJoin() {
    if (isJoining.value || hasJoined.value) return;
    error.value = null;
    permissionBlocked.value = false;
    permissionErrorMessage.value = null;
    await joinCall();
}

// ============================================================================
// Signal Handling
// ============================================================================

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
    const eventCallId = event.call_id || event.callId;
    const currentCallId = callData.value?.callId;

    // Verification: ensure this signal belongs to the current call session
    if (eventCallId && currentCallId && eventCallId !== currentCallId) {
        console.log(
            `[Call] Ignoring signal for different call ID: ${eventCallId} (current: ${currentCallId})`,
        );
        return;
    }

    if (senderId === selfId) {
        console.log(
            `[Call] Ignoring self-signal from ${senderId} (I am ${selfId})`,
        );
        return;
    }

    // In mesh, signals MUST be targeted or we ignore them for safety
    // EXCEPTION: "hand-toggle" is a broadcast signal that might be inadvertently targeted or should be allowed anyway
    const signalType = event.signal_data?.type;
    if (
        targetId &&
        targetId !== selfId &&
        signalType !== "hand-toggle" &&
        signalType !== "media-state"
    ) {
        console.log(
            `[Call] Ignoring signal targeted at ${targetId} (I am ${selfId})`,
        );
        return;
    }


    // Media State Signaling - Process early, cross-browser mute/camera state
    if (signalType === "media-state") {
        const ms = event.signal_data;
        const pid = senderId.toLowerCase();
        const prevState = remoteMediaState.get(pid)?.cameraOff;
        const newState = !!ms.cameraOff;

        remoteMediaState.set(pid, {
            muted: !!ms.muted,
            cameraOff: newState,
        } as any);

        if (prevState !== undefined && prevState !== newState) {
            remoteCameraLoadingState.set(pid, true);
            setTimeout(() => {
                remoteCameraLoadingState.set(pid, false);
            }, 900);
        }

        if (
            callMode.value === "sfu" &&
            hasJoined.value &&
            isTransportReady.value &&
            (!ms.cameraOff || !ms.muted)
        ) {
            const remoteSessionId = remoteSfuSessions.get(senderId);
            const remoteMids = remoteSfuTracks.get(senderId);
            if (!remoteSessionId || (!remoteMids?.audioMid && !remoteMids?.videoMid)) {
                requestRemoteMediaInfo(senderId, true);
            }
            if (remoteSessionId && (remoteMids?.audioMid || remoteMids?.videoMid)) {
                const isVisible = visibleParticipants.value.has(pid);
                pullParticipantTracks(
                    senderId,
                    remoteSessionId,
                    remoteMids?.audioMid,
                    remoteMids?.videoMid,
                    undefined, // screenMid
                    isVisible ? "all" : "audio",
                );
            }
        }
        return;
    }

    // Resync handshake: remote asked us to rebroadcast our latest SFU media metadata.
    if (signalType === "request-media-info") {
        if (callMode.value === "sfu") {
            rebroadcastSfuMediaToJoiner(senderId);
        }
        return;
    }

    // WAIT for transport/session readiness.
    // Mesh can process signaling even before local media is attached (late track sync will catch up).
    const waitingForTransport = !isTransportReady.value || !hasJoined.value;
    const shouldBufferSignal = waitingForTransport;

    if (shouldBufferSignal) {
        const isCandidateSignal =
            signalType === "candidate" || signalType === "ice-candidate";
        signalRuntimeManager.enqueuePendingSignal(event);
        if (isCandidateSignal) {
            const pendingCount = signalRuntimeManager.getPendingCount();
            if (pendingCount % 25 === 0) {
                trace(
                    "SIGNAL",
                    `Buffering candidate burst from ${senderId} while transport is not ready (pending=${pendingCount})`,
                );
            }
        } else {
            trace(
                "SIGNAL",
                `Buffering ${signalType || "signal"} from ${senderId} - ${
                    waitingForTransport
                        ? "joining or transport not ready"
                        : "not ready"
                }`,
            );
        }
        return;
    }

    const signal = event.signal_data;

    // 1. Robust Deduplication check (full fingerprint)
    // We stringify the WHOLE signal but only keep a rolling hash of the last 1000
    if (!signalRuntimeManager.markIfNewSignal(senderId, signal)) return;

    // Bidirectional Sanitization: clean up both incoming and outgoing SDPs
    // to protect against "Invalid SDP line" errors (max-message-size, sctp-port).
    if (signal.sdp) signal.sdp = mungeSdp(signal.sdp);

    // SFU: Remote Session Registration & Track Pulling
    if (
        signal.type === "sfu-session-ready" ||
        signal.type === "sfu-media-ready"
    ) {
        const signalAudioMid = (signal.audioMid ?? signal.audio)?.toString();
        const signalVideoMid = (signal.videoMid ?? signal.video)?.toString();

        trace("SIGNAL", `Received ${signal.type} from ${senderId}`, {
            sessionId: signal.sessionId,
            audio: signalAudioMid,
            video: signalVideoMid,
        });

        const previousSessionId = remoteSfuSessions.get(senderId);
        const previousMids = remoteSfuTracks.get(senderId);
        const previousFingerprint = [
            previousSessionId || "",
            previousMids?.audioMid || "",
            previousMids?.videoMid || "",
        ].join("|");
        const incomingFingerprint = [
            signal.sessionId || "",
            signalAudioMid || "",
            signalVideoMid || "",
        ].join("|");

        const existingStream = store.remoteStreams.get(senderId);
        const hasLiveRemoteMedia = !!existingStream?.getTracks().some(
            (track) =>
                (track.kind === "audio" || track.kind === "video") &&
                track.readyState === "live",
        );
        const existingTransceivers = participantTransceivers.get(senderId);
        const hasKnownPulledMids =
            !!existingTransceivers?.audioMid || !!existingTransceivers?.videoMid;

        if (
            incomingFingerprint === previousFingerprint &&
            hasKnownPulledMids &&
            hasLiveRemoteMedia
        ) {
            trace(
                "SIGNAL",
                `Skipping duplicate ${signal.type} for ${senderId} (already synced)`,
            );
            return;
        }

        remoteSfuSessions.set(senderId, signal.sessionId);

        // Persist MIDs for pull Participant tracks if provided
        if (
            (signalAudioMid !== undefined && signalAudioMid !== "") ||
            (signalVideoMid !== undefined && signalVideoMid !== "")
        ) {
            remoteSfuTracks.set(senderId, {
                audioMid: signalAudioMid,
                videoMid: signalVideoMid,
            });
        }

        // Only pull if the remote actually has tracks published
        const hasAnyRemoteMid = 
            (signalAudioMid !== undefined && signalAudioMid !== "") ||
            (signalVideoMid !== undefined && signalVideoMid !== "");

        if (hasAnyRemoteMid) {
            const isVisible = visibleParticipants.value.has(
                senderId.toLowerCase(),
            );
            pullParticipantTracks(
                senderId,
                signal.sessionId,
                signalAudioMid,
                signalVideoMid,
                undefined, // screenMid
                isVisible ? "all" : "audio",
            );
        } else {
            trace(
                "SIGNAL",
                `Remote ${senderId} has no tracks yet (hollow session), deferring pull until they publish`,
            );
            // Request their media info so they re-signal when ready
            requestRemoteMediaInfo(senderId, true);
        }
        return;
    }


    // Get or Create Peer (MESH ONLY)
    if (callMode.value === "mesh") {
        await meshManager?.handleSignal({
            senderId,
            selfId,
            signal,
            localStream: localStream.value,
        });
    } else {
        trace(
            "SIGNAL",
            `Ignoring MESH signal ${signal.type} from ${senderId} (currently in SFU mode)`,
        );
    }
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

        // Transition from Ringing to Connected when a remote participant joins
        if (callState.value === "ringing") {
            console.log(
                "[Call] First participant joined, transitioning to connected",
            );
            markCallConnected();
        } else if (callState.value === "connecting") {
            scheduleConnectedFallback();
        }
    }

    if (exists) {
        return;
    }

    // MESH: Negotiate with the new person
    if (callMode.value === "mesh") {
        if (!hasJoined.value || !isTransportReady.value) {
            trace(
                "MESH",
                `Deferring peer init for ${publicId} until join/transport is ready`,
            );
            return;
        }
        const isInitiator = selfId > publicId;
        if (!peers.has(publicId)) {
            trace(
                "MESH",
                `Initiating mesh peer for ${publicId} (initiator: ${isInitiator})`,
            );
            meshManager?.createPeer(
                publicId,
                isInitiator,
                getMeshPeerSeedStream(),
            );
        }
    } else {
        trace(
            "SFU",
            `Participant ${publicId} joined. Ignoring MESH negotiation as we are in SFU mode.`,
        );
    }

    // SFU: Negotiate with the new person
    if (callMode.value === "sfu") {
        rebroadcastSfuMediaToJoiner(publicId);
        // If they joined while signaling race conditions happen, ask them for media info too.
        setTimeout(() => {
            if (!remoteSfuSessions.has(publicId)) {
                requestRemoteMediaInfo(publicId);
            }
        }, 2500);
    }
}

function handleParticipantLeft(event: any) {
    const publicId = (
        event.participant_public_id ||
        event.participant_publicId ||
        ""
    ).toLowerCase();
    const reason = event.reason || "hangup";
    console.log(`[Call] Participant left: ${publicId}, reason: ${reason}`);

    // Remove from list
    participants.value = participants.value.filter(
        (p) => p.publicId.toLowerCase() !== publicId,
    );
    clearMeshRecovery(publicId);
    remoteCameraLoadingState.delete(publicId.toLowerCase());
    remoteMediaState.delete(publicId);
    remoteSfuSessions.delete(publicId);
    remoteSfuTracks.delete(publicId);
    participantTransceivers.delete(publicId);
    sfuSignalManager?.clearRequestTimestamp(publicId);

    // Peer cleanup handled by peer.on('close') or explicit destroy
    const peer = peers.get(publicId);
    if (peer) {
        meshManager?.destroyPeer(publicId);
        store.removeRemoteStream(publicId);
    }

    // SFU cleanup
    store.removeRemoteScreenStream(publicId);
    audioAnalysers.forEach((_, id) => {
        if (id.startsWith(publicId)) stopAudioAnalysis(id);
    });

    stopAudioAnalysis(publicId);

    // Auto-end logic
    const nonSelfParticipants = participants.value.filter((p) => !p.isSelf);

    // In a 1:1 call (DM), if the other side leaves/declines/is busy, we end the call
    const isDM = callData.value?.chatType === "dm";
    const isTarget =
        isDM &&
        (publicId === callData.value?.remoteUser?.publicId?.toLowerCase() ||
            nonSelfParticipants.length === 0);

    if (
        isTarget &&
        (callState.value === "connected" ||
            callState.value === "ringing" ||
            callState.value === "connecting")
    ) {
        console.log(`[Call] Remote participant left (${reason}). Auto-ending.`);

        if (reason === "busy") toast.error("User is busy");
        else if (reason === "declined") toast.info("Call declined");
        else if (reason === "no_answer" || reason === "timeout")
            toast.info("Call was not answered");

        // Notify backend and parent before closing
        endCall(reason as any);
        return;
    }

    // Bug 4: Auto-end if we're the only one left in general (Group calls)
    if (nonSelfParticipants.length === 0 && callState.value === "connected") {
        console.log("[Call] Everyone left, auto-ending call");
        endCall("hangup");
    }
}

function handleCallEndedEvent(event: any) {
    const eventCallId = event.call_id || event.callId;
    const currentCallId = callData.value?.callId;

    if (eventCallId && currentCallId && eventCallId !== currentCallId) {
        console.log(
            `[Call] Ignoring CallEnded for different call ID: ${eventCallId}`,
        );
        return;
    }

    // Bug 3: Skip if we triggered the end (our own hangup already handles cleanup)
    const selfId = callData.value?.selfPublicId?.toLowerCase();
    if (event.ender_public_id?.toLowerCase() === selfId) {
        console.log("[Call] Ignoring CallEnded from self");
        return;
    }

    console.log("[Call] CallEnded event received from another participant");
    callState.value = "ended";
    postToParent({ type: "state", state: "ended", reason: event.reason });
    cleanup();
}

function setupEcho() {
    callSignalingManager.setupEcho();
}

// ============================================================================
// Controls
// ============================================================================

const isCameraTogglePending = ref(false);
const isMicTogglePending = ref(false);

function ensureLocalStreamContainer(): MediaStream {
    if (!localStream.value) {
        localStream.value = new MediaStream();
    }
    return localStream.value;
}

function getMeshPeerSeedStream(): MediaStream | null {
    const combined = new MediaStream();
    
    // Add primary camera/mic tracks
    if (localStream.value) {
        localStream.value.getTracks().forEach(t => {
            if (t.readyState === "live") combined.addTrack(t);
        });
    }



    return combined.getTracks().length > 0 ? combined : null;
}

function findMeshAudioSender(
    pc: RTCPeerConnection,
): RTCRtpSender | undefined {
    return pc.getSenders().find((sender) => {
        if (sender.track?.kind === "audio") return true;
        return pc
            .getTransceivers()
            .some(
                (transceiver) =>
                    transceiver.sender === sender &&
                    transceiver.receiver.track.kind === "audio",
            );
    });
}

function findMeshVideoSender(
    pc: RTCPeerConnection,
    slotIndex: number = 0, // 0 for camera, 1 for screen
): RTCRtpSender | undefined {
    const videoTransceivers = pc
        .getTransceivers()
        .filter((t) => t.receiver.track.kind === "video");
    return videoTransceivers[slotIndex]?.sender;
}

async function publishLocalCameraTrack(track: MediaStreamTrack) {
    if (callMode.value === "sfu") {
        return sfuMediaManager.replaceLocalTrack("video", track);
    }


    const replaceOps: Promise<void>[] = [];
    peers.forEach((peer) => {
        const pc = (peer as any)?._pc as RTCPeerConnection | undefined;
        if (!pc) return;

        const sender = findMeshVideoSender(pc, 0);
        if (sender) {
            replaceOps.push(
                runInQueue(async () => {
                    try {
                        await sender.replaceTrack(track);
                        console.log(`[Call][MESH] Swap TRACK to LIVE success (Camera) id=${track.id} label=${track.label}`);
                    } catch (err) {
                        console.error("[Call][MESH] Swap TRACK to LIVE failed (Camera):", err);
                    }
                })
            );
            return;
        }

        if (localStream.value) {
            try {
                peer.addTrack(track, localStream.value);
            } catch (error) {
                const fallbackSender = findMeshVideoSender(pc, 0);
                if (fallbackSender) {
                    replaceOps.push(
                        fallbackSender
                            .replaceTrack(track)
                            .catch(() => {}) as Promise<void>,
                    );
                } else {
                    console.warn(
                        "[Call] Failed to add camera track to mesh peer",
                        error,
                    );
                }
            }
        }
    });

    if (replaceOps.length > 0) {
        await Promise.allSettled(replaceOps);
    }

    return true;
}

async function publishLocalAudioTrack(track: MediaStreamTrack) {
    if (callMode.value === "sfu") {
        return sfuMediaManager.replaceLocalTrack("audio", track);
    }

    const replaceOps: Promise<void>[] = [];
    peers.forEach((peer) => {
        const pc = (peer as any)?._pc as RTCPeerConnection | undefined;
        if (!pc) return;

        const sender = findMeshAudioSender(pc);
        if (sender) {
            replaceOps.push(
                runInQueue(async () => {
                    try {
                        await sender.replaceTrack(track);
                        console.log(`[Call][MESH] Swap TRACK to LIVE success (Audio) id=${track.id}`);
                    } catch (err) {
                        console.error("[Call][MESH] Swap TRACK to LIVE failed (Audio):", err);
                    }
                })
            );
        } else if (localStream.value) {
            try {
                peer.addTrack(track, localStream.value);
            } catch (error) {
                console.warn("[Call] Failed to add microphone track to mesh peer", error);
            }
        }
    });

    if (replaceOps.length > 0) {
        await Promise.allSettled(replaceOps);
    }

    return true;
}

async function unpublishLocalCameraTrack() {
    if (callMode.value === "sfu") {
        return sfuMediaManager.replaceLocalTrack("video", null);
    }

    const replaceOps: Promise<void>[] = [];
    peers.forEach((peer) => {
        const pc = (peer as any)?._pc as RTCPeerConnection | undefined;
        if (!pc) return;

        const sender = findMeshVideoSender(pc, 0);
        if (sender) {
            replaceOps.push(
                runInQueue(async () => {
                    try {
                        await sender.replaceTrack(null);
                        console.log("[Call][MESH] Swap TRACK to NULL success (Camera)");
                    } catch (err) {
                        console.error("[Call][MESH] Swap TRACK to NULL failed (Camera):", err);
                    }
                })
            );
        }
    });

    if (replaceOps.length > 0) {
        await Promise.allSettled(replaceOps);
    }

    return true;
}

async function ensureLocalAudioTrack(): Promise<boolean> {
    const stream = ensureLocalStreamContainer();
    let audioTrack = stream
        .getAudioTracks()
        .find((track) => track.readyState === "live");

    if (!audioTrack) {
        const acquired = await acquireMicrophoneTrack();
        if (!acquired.track) {
            permissionBlocked.value = false;
            permissionErrorMessage.value = acquired.errorMessage || null;
            toast.error(acquired.errorMessage || "Could not access microphone.");
            return false;
        }

        stream.getAudioTracks().forEach((track) => {
            stream.removeTrack(track);
            try {
                track.stop();
            } catch {}
        });
        stream.addTrack(acquired.track);
        audioTrack = acquired.track;
        triggerRef(localStream);
    }

    audioTrack.enabled = !isMuted.value;

    if (hasJoined.value && isTransportReady.value) {
        const published = await publishLocalAudioTrack(audioTrack);
        if (!published) {
            toast.error("Could not publish microphone stream. Please try again.");
            return false;
        }
    }

    return true;
}

async function enableCameraTrack(): Promise<boolean> {
    if (isAudioOnly.value) return true;

    const stream = ensureLocalStreamContainer();
    const existingTrack = stream
        .getVideoTracks()
        .find((track) => track.readyState === "live");

    if (existingTrack) {
        existingTrack.enabled = true;
        if (hasJoined.value && isTransportReady.value) {
            const republished = await publishLocalCameraTrack(existingTrack);
            if (!republished) {
                toast.error("Could not start camera stream. Please try again.");
                return false;
            }
        }
        isCameraOff.value = false;
        return true;
    }

    const acquired = await acquireCameraTrack({
        videoEffect: store.videoEffect,
        selectedVideoDeviceId: store.selectedVideoDeviceId,
        backgroundImage: store.backgroundImage,
        startVideoEffect: (track, effect, backgroundImage) =>
            backgroundBlur.startVideoEffect(track, effect, backgroundImage),
    });

    if (!acquired.track) {
        toast.error(acquired.errorMessage || "Could not access camera.");
        return false;
    }

    stream.getVideoTracks().forEach((track) => {
        stream.removeTrack(track);
        try {
            track.stop();
        } catch {}
    });

    stream.addTrack(acquired.track);
    triggerRef(localStream); // Force Vue reactivity for localHasVideo
    originalVideoTrack.value = acquired.originalTrack;

    if (hasJoined.value && isTransportReady.value) {
        const published = await publishLocalCameraTrack(acquired.track);
        if (!published) {
            stream.removeTrack(acquired.track);
            triggerRef(localStream);
            try {
                acquired.track.stop();
            } catch {}

            if (acquired.originalTrack && acquired.originalTrack !== acquired.track) {
                try {
                    acquired.originalTrack.stop();
                } catch {}
            }

            originalVideoTrack.value = null;
            isCameraOff.value = true;
            toast.error("Could not start camera stream. Please try again.");
            return false;
        }
    }

    isCameraOff.value = false;
    return true;
}

async function disableCameraTrack() {
    isCameraOff.value = true;

    if (hasJoined.value && isTransportReady.value) {
        const unpublished = await unpublishLocalCameraTrack();
        if (!unpublished) {
            toast.error("Could not stop camera stream cleanly.");
        }
    }

    localStream.value?.getVideoTracks().forEach((track) => {
        localStream.value?.removeTrack(track);
        try {
            track.stop();
        } catch {}
    });
    if (localStream.value) triggerRef(localStream);

    if (originalVideoTrack.value) {
        try {
            originalVideoTrack.value.stop();
        } catch {}
        originalVideoTrack.value = null;
    }
    backgroundBlur.stopProcessing();
}

async function toggleMute() {
    if (isMicTogglePending.value) return;
    isMicTogglePending.value = true;

    try {
        if (isMuted.value) {
            permissionBlocked.value = false;
            permissionErrorMessage.value = null;
            isMuted.value = false;
            const acquired = await ensureLocalAudioTrack();
            if (!acquired) {
                isMuted.value = true;
                return;
            }
        } else {
            isMuted.value = true;
            localStream.value?.getAudioTracks().forEach((track) => {
                track.enabled = false;
            });
        }

        if (hasJoined.value) {
            // Broadcast state to all peers for cross-browser compatibility
            sendSignal("media-state", {
                muted: isMuted.value,
                cameraOff: isCameraOff.value,
            });
        }
    } finally {
        isMicTogglePending.value = false;
    }
}

async function toggleCamera() {
    if (isCameraTogglePending.value) return;
    isCameraTogglePending.value = true;

    try {
        permissionBlocked.value = false;
        permissionErrorMessage.value = null;

        if (isCameraOff.value) {
            await enableCameraTrack();
        } else {
            await disableCameraTrack();
        }

        if (hasJoined.value) {
            sendSignal("media-state", {
                muted: isMuted.value,
                cameraOff: isCameraOff.value,
            });
        }
    } finally {
        isCameraTogglePending.value = false;
    }
}

function remoteHasVideo(participantId: string): boolean {
    const pid = participantId.toLowerCase();
    const stream = store.remoteStreams.get(participantId);
    
    // 1. Check authoritative signaling state first.
    // If the remote participant signaled their camera is OFF, we MUST NOT show video,
    // even if a stale track is still technically "live" in the stream container.
    const state = remoteMediaState.get(pid);
    if (state && state.cameraOff) return false;

    if (!stream) return false;
    const tracks = stream.getVideoTracks();
    if (tracks.length === 0) return false;

    const hasLiveVideoTrack = tracks.some((track) => track.readyState === "live");
    if (hasLiveVideoTrack) return true;

    // 2. Final fallback if no signal has arrived yet.
    return tracks[0].enabled && !tracks[0].muted;
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
    return sfuMediaManager.joinSession(stream);
}

async function resetSFUSession() {
    if (isSFUResetting.value) return;
    isSFUResetting.value = true;
    const newGeneration = sfuSessionManager.bumpGeneration();

    console.log(
        `[SFU] Forcing session reset (Gen ${newGeneration}) due to unrecoverable error (406)...`,
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
        sfuSessionManager.clearRuntimeState();

        // 3. Stabilization delay
        await new Promise((resolve) => setTimeout(resolve, 1000));

        // 4. Re-join SFU
        if (localStream.value) {
            await joinSFU(localStream.value);
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

/* SFU Gated Rescue: Force Reset on 406 */
async function handleSFU406Rescue() {
    if (!sfuSessionManager.tryEnterResetWindow(5000)) {
        console.warn(
            "[SFU] Flattening 406 rescue loop - refusing to reset again so soon.",
        );
        return false; // Let the error propagate, don't loop
    }

    console.log("[SFU] 406 Answer Expected. Initiating Force Reset...");
    await resetSFUSession();
    return true;
}

/**
 * CF Issue 1: Flush any buffered track events that arrived before MID mapping was ready.
 */
function flushPendingTracks() {
    sfuSessionManager.flushPendingTracks({
        resolveParticipantId: (evt) => {
            const mapped = sfuSessionManager.getParticipantByMid(evt.mid);
            if (mapped) return mapped;

            const assoc = sfuSessionManager.getTransceiverAssociation(
                evt.transceiver,
            );
            if (!assoc) return null;

            return assoc.trackName === "screen"
                ? `${assoc.participantId}:screen`
                : assoc.participantId;
        },
        onResolved: (evt, participantId) => {
            let stream = evt.streams[0];
            if (!stream) {
                stream = new MediaStream([evt.track]);
            }
            if (
                evt.track.kind === "video" &&
                participantId.endsWith(":screen")
            ) {
                store.addRemoteScreenStream(
                    participantId.replace(":screen", ""),
                    stream,
                );
            } else {
                store.addRemoteStream(participantId, stream);
                startAudioAnalysis(participantId, stream);
                markCallConnected();
            }
            console.log(
                `[SFU] Flushed buffered track (mid: ${evt.mid}) → ${participantId}`,
            );
        },
        onStats: ({ processed, unresolved }) => {
            console.log(
                `[SFU] Flushing ${processed} buffered track event(s)`,
            );
            if (unresolved > 0) {
                console.warn(
                    `[SFU] ${unresolved} track(s) still unresolved after flush`,
                );
            }
        },
    });
}

/**
 * CF Issue 2: Attempt ICE restart on the SFU peer connection.
 */
async function attemptSfuIceRestart() {
    await sfuMediaManager.attemptIceRestart();
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
    remoteScreenMid?: string,
    mediaType: "audio" | "video" | "all" = "all",
) {
    return sfuMediaManager.pullParticipantTracks(
        participantPublicId,
        remoteSessionId,
        remoteAudioMid,
        remoteVideoMid,
        undefined, // generation
        mediaType,
    );
}

async function pullRemoteScreen(
    participantPublicId: string,
    screenMid: string,
    remoteSessionId: string,
) {
    return sfuMediaManager.pullRemoteScreen(
        participantPublicId,
        screenMid,
        remoteSessionId,
    );
}

// (Redundant processSFUScreenShare removed)

async function publishSFUScreenTrack(stream: MediaStream) {
    return sfuMediaManager.publishScreenTrack(stream);
}

function stopSFUScreenShare() {
    sfuMediaManager.stopScreenShareSignal();
}

async function endCall(reason = "hangup") {
    finalReason.value = reason;
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

function startDurationTimer() {
    if (durationTimer) return;
    callDuration.value = 0;
    durationTimer = setInterval(() => callDuration.value++, 1000);
}

const showDevSim = ref(false);

const handleGlobalKeydown = (e: KeyboardEvent) => {
    // Ctrl + Shift + D
    if (e.ctrlKey && e.shiftKey && e.code === 'KeyD') {
        e.preventDefault();
        console.log('[DevSim] Toggling simulation tool...');
        showDevSim.value = !showDevSim.value;
    }
};



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
    callOrchestrator.stop();
    if (localStream.value) {
        localStream.value.getTracks().forEach((t) => t.stop());
        localStream.value = null;
    }
    peers.forEach((p) => p.destroy());
    peers.clear();
    meshRecoveryTimers.forEach((timer) => clearTimeout(timer));
    meshRecoveryTimers.clear();
    meshRecoveryAttempts.clear();

    // Reset store
    store.reset();

    audioAnalysers.forEach((_, id) => stopAudioAnalysis(id));
    audioAnalysers.clear();
    if (durationTimer) clearInterval(durationTimer);
    if (connectedFallbackTimer) {
        clearTimeout(connectedFallbackTimer);
        connectedFallbackTimer = null;
    }
    if (outgoingNoAnswerTimer) {
        clearTimeout(outgoingNoAnswerTimer);
        outgoingNoAnswerTimer = null;
    }
    callSignalingManager.teardown();
    broadcastChannel?.close();

    // Background Blur Cleanup
    if (originalVideoTrack.value) {
        originalVideoTrack.value.stop();
        originalVideoTrack.value = null;
    }
    backgroundBlur.stopProcessing();

    remoteSfuSessions.clear();
    remoteMediaState.clear();
    participantTransceivers.clear();
    signalRuntimeManager.clear();

    if (sfuPc) {
        sfuPc.close();
        sfuPc = null;
    }
    if (turnRefreshInterval) {
        clearInterval(turnRefreshInterval);
        turnRefreshInterval = null;
    }
    sfuSyncManager?.stopHealthCheck();
    sfuSignalManager?.clearRequestTimestamps();
    remoteSfuTracks.clear();
    sfuSessionManager.cleanup();
}

// ============================================================================
// Lifecycle
// ============================================================================

async function initializeCall() {
    window.addEventListener("beforeunload", (event) => {
        if (
            callState.value !== "ended" &&
            callState.value !== "idle" &&
            callState.value !== "error"
        ) {
            event.preventDefault();
            event.returnValue =
                "A call is currently active. Reloading or leaving this page will disconnect the call.";
            endCall("hangup");
            return event.returnValue;
        }
    });

    const raw = sessionStorage.getItem("callData");
    if (!raw) {
        error.value = "Invalid session.";
        callState.value = "error";
        return;
    }
    let parsedData;
    try {
        parsedData = JSON.parse(raw);
    } catch (e) {
        console.error("[Call] Failed to parse callData", e);
        error.value = "Data parse error.";
        callState.value = "error";
        return;
    }

    callData.value = parsedData;
    sessionStorage.removeItem("callData");

    if (callData.value) {
        console.log("[Call] Initialized with data:", {
            callId: callData.value.callId,
            chatId: callData.value.chatId,
            chatType: (callData.value as any).chatType || "dm",
            direction: callData.value.direction,
            selfId: callData.value.selfPublicId,
        });
        if (callData.value.chatType === "group") {
            callMode.value = "sfu";
        }
        // Sec 4: Override selfPublicId from authenticated session (don't trust sessionStorage)
        if (authStore.user?.public_id) {
            callData.value.selfPublicId = authStore.user.public_id;
        }
        store.selfPublicId = callData.value.selfPublicId;

        // Initialize Chat
        if (callData.value.chatId) {
            console.log("[Call] Initializing Chat for:", callData.value.chatId);
            // Fetch the chat details using a separate try-catch so it doesn't kill the call
            try {
                await chatStore.refreshChat(callData.value.chatId);
            } catch (err) {
                console.warn(
                    "[Call] Failed to refresh chat, proceeding...",
                    err,
                );
            }
            // We don't await this to not block the call join flow
            selectChat(callData.value.chatId);
        }
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

    if (data.direction === "outgoing" && data.remoteUser) {
        // Outgoing call state only; ringtone is handled by main app.
        callState.value = "ringing";
    }

    console.log("[Call] Auto-launching call flow from main app handoff");
    void joinCall();

    // Initial Output Device Sync
    if (store.selectedOutputDeviceId) {
        applyOutputDevice(store.selectedOutputDeviceId);
    }
}

onMounted(async () => {
    // 1. Ensure we have the user (for public_id)
    if (!authStore.user) {
        console.log("[CallApp] User not found, fetching...");
        await authStore.fetchUser();
    }

    // 2. Initialize Call Logic (includes chat initialization from callData)
    await initializeCall();

    // 3. Runtime workers (heartbeat + network polling)
    callOrchestrator.start();
});

onBeforeUnmount(() => cleanup());
</script>

<template>
    <div class="call-container" :class="gridClass" :style="{ '--video-fit': store.videoFitMode }">
        <div class="call-bg"></div>
        <div class="call-overlay"></div>

        <!-- PERSISTENT AUDIO MIXER (Fixes "Only One Speaker" bug) -->
        <div
            class="audio-mix"
            style="display: none; position: absolute; top: -9999px"
        >
            <audio
                v-for="[publicId, stream] in store.remoteStreams"
                :key="publicId + '-audio-mix'"
                :ref="
                    (el) => {
                        if (el) (el as HTMLMediaElement).srcObject = stream;
                    }
                "
                v-volume="publicId"
                v-sink-id
                autoplay
                playsinline
            />
        </div>

        <!-- TOP VIGNETTE -->
        <div class="top-vignette" v-if="callState === 'connected'"></div>


        <!-- POOR CONNECTION ALERT -->
        <transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-4"
        >
            <div
                v-if="callState === 'connected' && networkStats.score >= 2"
                class="fixed bottom-32 left-1/2 -translate-x-1/2 z-100 bg-red-600/90 backdrop-blur-md text-white px-5 py-2.5 rounded-full shadow-2xl flex items-center gap-3 text-sm font-semibold border border-red-500/50"
            >
                <Icon name="AlertTriangle" size="18" class="animate-pulse" />
                <span
                    >Your connection is unstable. Call quality may be
                    affected.</span
                >
            </div>
        </transition>

        <!-- HARDWARE CONSTRAINT ALERT (Background Blur) -->
        <transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-4"
        >
            <div
                v-if="backgroundBlur.error.value"
                class="fixed top-24 left-1/2 -translate-x-1/2 z-100 bg-amber-600/95 backdrop-blur-md text-white px-5 py-2.5 rounded-xl shadow-2xl flex items-center gap-3 text-sm font-medium border border-amber-400/30 max-w-[90vw] text-center"
            >
                <Icon name="ZapOff" size="18" />
                <span>{{ backgroundBlur.error.value }}</span>
                <button
                    @click="backgroundBlur.error.value = null"
                    class="ml-2 hover:bg-white/20 p-1 rounded-full transition-colors"
                >
                    <Icon name="X" size="14" />
                </button>
            </div>
        </transition>

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
        <template v-else-if="callState === 'ended' || (callState === 'idle' && finalReason)">
            <div class="calling-screen ended-screen">
                <!-- Dynamic background -->
                <div v-if="ringingRemoteUser?.avatar" class="calling-bg" :style="{ backgroundImage: `url(${ringingRemoteUser.avatar})` }"></div>
                <div class="calling-overlay"></div>

                <div class="calling-card glass-card animate-in fade-in zoom-in duration-500">
                    <div class="calling-avatar-container">
                        <template v-if="ringingRemoteUser?.avatar">
                            <img :src="ringingRemoteUser.avatar" class="calling-avatar grayscale opacity-50" />
                        </template>
                        <template v-else>
                            <div class="calling-avatar-placeholder grayscale opacity-50">
                                {{ ringingRemoteUser?.name?.charAt(0) || '?' }}
                            </div>
                        </template>
                    </div>

                    <div class="calling-info">
                        <h2 class="calling-name">{{ ringingRemoteUser?.name || 'User' }}</h2>
                        <p class="calling-status" :class="{
                            'text-amber-400': finalReason === 'busy',
                            'text-red-400': finalReason === 'no_answer' || finalReason === 'timeout',
                            'text-zinc-400': finalReason === 'declined' || finalReason === 'ended'
                        }">
                            <span v-if="finalReason === 'busy'">User is busy</span>
                            <span v-else-if="finalReason === 'no_answer' || finalReason === 'timeout'">No answer</span>
                            <span v-else-if="finalReason === 'declined'">Call declined</span>
                            <span v-else>Call ended</span>
                        </p>
                    </div>

                    <div class="calling-actions">
                        <button @click="closeWindow" class="btn-close-mini">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- RINGING STATE -->
        <template v-else-if="callState === 'ringing'">
            <div class="calling-screen">
                <!-- Dynamic background -->
                <div v-if="ringingRemoteUser?.avatar" class="calling-bg" :style="{ backgroundImage: `url(${ringingRemoteUser.avatar})` }"></div>
                <div class="calling-overlay"></div>

                <div class="calling-card glass-card animate-in fade-in zoom-in duration-700">
                    <div class="calling-avatar-container">
                        <!-- Multi-layered ripples -->
                        <div class="ripple ripple-outer"></div>
                        <div class="ripple ripple-inner"></div>
                        
                        <template v-if="ringingRemoteUser?.avatar">
                            <img :src="ringingRemoteUser.avatar" class="calling-avatar" />
                        </template>
                        <template v-else>
                            <div class="calling-avatar-placeholder">
                                {{ ringingRemoteUser?.name?.charAt(0) || '?' }}
                            </div>
                        </template>
                    </div>

                    <div class="calling-info">
                        <h2 class="calling-name">{{ ringingRemoteUser?.name || 'Calling...' }}</h2>
                        <p class="calling-status animate-pulse">Waiting for answer...</p>
                    </div>

                    <div class="calling-actions">
                        <button @click="endCall" class="btn-hangup-large">
                            <Icon name="PhoneOff" size="28" />
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- PERMISSION RECOVERY -->
        <div v-else-if="permissionBlocked" class="lobby-minimalist">
            <div class="lobby-content">
                <div class="avatar-preview">
                    <div class="preview-circle">
                        <span class="initials">{{ previewRemoteName[0] }}</span>
                    </div>
                </div>

                <div class="join-info">
                    <h1 class="join-title">Microphone access required</h1>
                    <p class="join-subtitle">
                        {{
                            permissionErrorMessage ||
                            "Allow microphone access to continue this call."
                        }}
                    </p>
                </div>

                <div class="lobby-actions-grid">
                    <button
                        class="btn-lobby-action join"
                        @click="retryPermissionAndJoin"
                        :disabled="isJoining"
                    >
                        <Icon v-if="!isJoining" name="RefreshCw" size="20" />
                        <Icon
                            v-else
                            name="Loader"
                            size="20"
                            class="animate-spin"
                        />
                        <span>
                            {{
                                isJoining
                                    ? "Retrying..."
                                    : "Retry Permission"
                            }}
                        </span>
                    </button>

                    <button
                        class="btn-lobby-action decline"
                        @click="endCall('failed')"
                    >
                        <Icon name="X" size="20" />
                        <span>End Call</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- AUTO LAUNCH STATE -->
        <div v-else-if="!hasJoined" class="call-connecting-state">
            <div class="call-connecting-content">
                <div class="call-loading-ring"></div>
                <h2 class="call-connecting-title">Preparing call...</h2>
                <p class="call-connecting-subtitle">
                    Requesting microphone access and connecting call media
                </p>
            </div>
        </div>

        <!-- HANDSHAKE STATE -->
        <transition
            v-else-if="callState === 'connecting'"
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div class="call-connecting-state">
                <div class="call-connecting-content">
                    <div class="call-loading-ring"></div>
                    <h2 class="call-connecting-title">Entering Call...</h2>
                    <p class="call-connecting-subtitle">
                        Setting up secure connection and synchronizing media
                    </p>
                </div>
            </div>
        </transition>

        <!-- CONNECTED LAYOUT -->
        <template v-else>
            <div
                class="call-stage-container"
                :class="{
                    'sidebar-open': showSidebar,
                    'layout-spotlight': layoutMode === 'spotlight',
                }"
            >


                <!-- MAIN STAGE -->
                <div class="main-stage">
                    <!-- FILMSTRIP LAYOUT (Spotlight Mode) -->
                    <div v-if="layoutMode === 'spotlight'" class="filmstrip-layout">
                        <div class="filmstrip-container">
                            <div
                                v-for="p in filmstripParticipants"
                                :key="p.publicId"
                                class="video-cell filmstrip-cell"
                                :class="{
                                    'is-talking': talkingParticipants.has(
                                        p.publicId.toLowerCase(),
                                    ),
                                    local: p.isSelf,
                                }"
                            >
                                <video
                                    v-if="
                                        p.isSelf
                                            ? localHasVideo && !isCameraOff
                                            : store.remoteStreams.get(
                                                  p.publicId,
                                              ) && remoteHasVideo(p.publicId)
                                    "
                                    v-src-object="
                                        p.isSelf
                                            ? localStream
                                            : store.remoteStreams.get(
                                                  p.publicId,
                                              )
                                    "
                                    v-sink-id
                                    :muted="p.isSelf"
                                    autoplay
                                    playsinline
                                    class="video-element"
                                    :class="{ 'mirror-off': p.isSelf && false }"
                                />
                                <!-- Avatar Fallback -->
                                <div v-else class="avatar-fallback">
                                    <Avatar
                                        :src="p.avatar"
                                        :alt="p.name"
                                        :size="80"
                                        class="absolute z-0 w-20 h-20 text-3xl font-bold rounded-full overflow-hidden shrink-0 pointer-events-none ring-4 ring-black/10"
                                    />
                                    <div
                                        class="audio-indicator"
                                        v-if="!p.isSelf"
                                    >
                                        <Icon name="Mic" size="14" />
                                    </div>
                                </div>

                                <!-- Loading Overlay -->
                                <div v-if="p.isSelf ? isCameraTogglePending : remoteCameraLoadingState.get(p.publicId.toLowerCase())" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-zinc-900/80 backdrop-blur-md rounded-2xl transition-all duration-300">
                                    <Icon name="loader" class="animate-spin text-white mb-2" size="24" />
                                    <span class="text-white text-xs font-medium tracking-wide">Updating video...</span>
                                </div>

                                <div class="participant-info compact-pill">
                                    <span class="participant-name">{{
                                        p.isSelf ? "You" : p.name
                                    }}</span>

                                    <div class="toolbox-divider-vertical"></div>

                                    <div class="status-row-compact">
                                        <NetworkHealthIndicator
                                            v-if="
                                                !p.isSelf &&
                                                participantStats.has(
                                                    p.publicId.toLowerCase(),
                                                )
                                            "
                                            v-bind="
                                                participantStats.get(
                                                    p.publicId.toLowerCase(),
                                                )
                                            "
                                            compact
                                        />
                                        <div class="status-icons">
                                            <Icon
                                                v-if="
                                                    p.isSelf
                                                        ? isMuted
                                                        : !remoteHasAudio(
                                                              p.publicId,
                                                          )
                                                "
                                                name="MicOff"
                                                size="12"
                                                class="text-red-500"
                                            />
                                            <Icon
                                                v-if="
                                                    p.isSelf
                                                        ? isCameraOff
                                                        : !remoteHasVideo(
                                                              p.publicId,
                                                          )
                                                "
                                                name="VideoOff"
                                                size="12"
                                                class="text-red-500 ml-1"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="spotlight-container">
                            <!-- In spotlight mode, the spotlight content would go here.
                                 Since screen sharing is removed, this might be simpler now. -->
                        </div>
                    </div>

                    <!-- STANDARD GRID / 1:1 LAYOUT -->
                    <div v-else class="grid-wrapper" :class="gridClass">
                        <!-- Remote & Mock Participants -->
                        <div
                            v-for="p in paginatedNonSelfParticipants"
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
                                    remoteHasVideo(p.publicId)
                                "
                                v-src-object="
                                    store.remoteStreams.get(p.publicId)
                                "
                                v-volume="p.publicId"
                                v-sink-id
                                autoplay
                                playsinline
                                class="video-element"
                            />
                            <!-- Avatar Fallback -->
                            <div v-else class="avatar-fallback">
                                <Avatar
                                    :src="p.avatar"
                                    :alt="p.name"
                                    :size="isMobile ? 64 : 120"
                                    class="avatar-image-element absolute z-0 text-4xl font-bold rounded-full overflow-hidden shrink-0 pointer-events-none ring-4 ring-black/10 shadow-2xl"
                                />
                                <div class="audio-indicator">
                                    <Icon name="Mic" size="16" />
                                </div>
                            </div>

                            <!-- Loading Overlay -->
                            <div v-if="remoteCameraLoadingState.get(p.publicId.toLowerCase())" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-zinc-900/80 backdrop-blur-md rounded-2xl transition-all duration-300">
                                <Icon name="loader" class="animate-spin text-white mb-2" size="28" />
                                <span class="text-white text-sm font-medium tracking-wide">Updating video...</span>
                            </div>

                            <div class="participant-info compact-pill">
                                <span class="participant-name">{{
                                    p.name
                                }}</span>
                                <div
                                    v-if="participantStats.has(p.publicId.toLowerCase()) || !remoteHasAudio(p.publicId) || !remoteHasVideo(p.publicId)"
                                    class="toolbox-divider-vertical"
                                ></div>

                                <div class="status-row-compact">
                                    <NetworkHealthIndicator
                                        v-if="
                                            participantStats.has(
                                                p.publicId.toLowerCase(),
                                            )
                                        "
                                        v-bind="
                                            participantStats.get(
                                                p.publicId.toLowerCase(),
                                            )
                                        "
                                        compact
                                    />
                                    <div class="status-icons">
                                        <Icon
                                            v-if="!remoteHasAudio(p.publicId)"
                                            name="MicOff"
                                            size="12"
                                            class="status-icon-red"
                                        />
                                        <Icon
                                            v-if="!remoteHasVideo(p.publicId)"
                                            name="VideoOff"
                                            size="12"
                                            class="status-icon-red"
                                        />
                                    </div>

                                    <!-- Individual Volume Control -->
                                    <div
                                        class="volume-control"
                                        v-if="
                                            store.remoteStreams.has(p.publicId)
                                        "
                                    >
                                        <div class="volume-slider-container">
                                            <input
                                                type="range"
                                                min="0"
                                                max="100"
                                                :value="
                                                    store.remoteVolumes.get(
                                                        p.publicId,
                                                    ) ?? 100
                                                "
                                                @input="
                                                    (e) =>
                                                        store.setRemoteVolume(
                                                            p.publicId,
                                                            parseInt(
                                                                (
                                                                    e.target as HTMLInputElement
                                                                ).value,
                                                            ),
                                                        )
                                                "
                                                class="volume-slider-input"
                                            />
                                        </div>
                                        <button class="volume-btn">
                                            <Icon
                                                :name="
                                                    (store.remoteVolumes.get(
                                                        p.publicId,
                                                    ) ?? 100) === 0
                                                        ? 'VolumeX'
                                                        : 'Volume2'
                                                "
                                                size="14"
                                            />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Local Participant (Me) -->
                        <div
                            class="video-cell local"
                            :class="{
                                'pip-mode': participants.length >= 2,
                                'audio-mode': isAudioOnly,
                                'is-talking': talkingParticipants.has(
                                    callData?.selfPublicId?.toLowerCase() || '',
                                ),
                            }"
                        >
                            <video
                                v-if="localHasVideo && !isCameraOff"
                                v-src-object="localStream"
                                v-sink-id
                                autoplay
                                muted
                                playsinline
                                class="video-element"
                            />
                            <div v-else class="avatar-fallback flex items-center justify-center h-full w-full bg-zinc-900">
                                <Avatar
                                    :src="authStore.user?.avatar_url"
                                    :name="authStore.user?.name || 'Me'"
                                    size="lg"
                                    class="ring-4 ring-zinc-800/50 shadow-2xl scale-125"
                                />
                            </div>
                            <div class="participant-info compact-pill">
                                <span class="participant-name">You</span>
                            </div>
                        </div>
                    </div>

                    <!-- PAGINATION NAVIGATION -->
                    <div
                        v-if="totalPages > 1"
                        class="pagination-nav"
                    >
                        <button
                            class="pagination-btn"
                            :disabled="currentPage === 0"
                            @click="currentPage--"
                            title="Previous Page"
                        >
                            <Icon name="ChevronLeft" size="18" />
                        </button>
                        
                        <div class="pagination-dots">
                            <button
                                v-for="i in totalPages"
                                :key="i"
                                class="pagination-dot"
                                :class="{ active: currentPage === i - 1 }"
                                @click="currentPage = i - 1"
                                :title="`Page ${i}`"
                            ></button>
                        </div>

                        <button
                            class="pagination-btn"
                            :disabled="currentPage === totalPages - 1"
                            @click="currentPage++"
                            title="Next Page"
                        >
                            <Icon name="ChevronRight" size="18" />
                        </button>
                                    </div>
                <!-- Close Main Stage -->

                <!-- REDESIGNED BOTTOM BAR (Meeting Style) -->
                <footer class="app-bottom-bar">
                    <!-- Left: Info -->
                    <!-- Left: Info -->
                    <div class="bar-section bar-section--left">
                        <div class="call-info-pill">
                            <span class="info-time">{{ formattedDuration }}</span>
                            <div class="toolbox-divider-vertical mx-3"></div>
                            <div class="flex items-center gap-2">
                                <NetworkHealthIndicator
                                    v-if="callState === 'connected'"
                                    v-bind="networkStats"
                                    compact
                                />
                                <span class="info-label text-[10px] font-bold uppercase tracking-wider opacity-60">Connected</span>
                            </div>
                        </div>
                    </div>

                    <!-- Center: Controls -->
                    <div class="bar-section bar-section--center">
                        <button
                            class="ctrl-btn"
                            :class="{ 'ctrl-btn--off': isMuted }"
                            @click="toggleMute"
                            title="Toggle Mute"
                        >
                            <Icon
                                :name="isMuted ? 'MicOff' : 'Mic'"
                                size="20"
                            />
                        </button>

                        <button
                            v-if="!isAudioOnly"
                            class="ctrl-btn"
                            :class="{ 'ctrl-btn--off': isCameraOff, 'opacity-50': isCameraTogglePending }"
                            @click="toggleCamera"
                            :disabled="isCameraTogglePending"
                            title="Toggle Camera"
                        >
                            <Icon
                                v-if="isCameraTogglePending"
                                name="Loader"
                                size="20"
                                class="animate-spin"
                            />
                            <Icon
                                v-else
                                :name="isCameraOff ? 'VideoOff' : 'Video'"
                                size="20"
                            />
                        </button>

                        <button
                            class="ctrl-btn"
                            @click="showSettings = true"
                            title="Settings"
                        >
                            <Icon name="Settings" size="20" />
                        </button>

                        <button
                            class="ctrl-btn"
                            :class="{
                                'ctrl-btn--active':
                                    showSidebar && sidebarTab === 'chat',
                            }"
                            @click="toggleSidebar('chat')"
                            title="Chat"
                        >
                            <Icon name="MessageSquare" size="20" />
                        </button>

                        <button
                            class="ctrl-btn ctrl-btn--hangup"
                            @click="endCall('hangup')"
                            title="End Call"
                        >
                            <Icon name="PhoneOff" size="20" />
                        </button>
                    </div>

                    <!-- Right: Utility -->
                    <div class="bar-section bar-section--right">
                        <button
                            class="ctrl-btn"
                            :class="{ 'ctrl-btn--active': showSidebar && sidebarTab === 'people' }"
                            @click="toggleSidebar('people')"
                            title="Participants"
                        >
                            <Icon name="Users" size="20" />
                        </button>
                        <button
                            v-if="isMobile"
                            class="ctrl-btn"
                            @click="showDevSim = true"
                            title="Simulation"
                        >
                            <Icon name="FlaskConical" size="20" />
                        </button>
                    </div>
                </footer>
            </div>
            <!-- Close Main Stage -->

                <!-- CALL SIDEBAR -->
                <div class="call-sidebar" :class="{ open: showSidebar }">
                    <div class="sidebar-header">
                        <button
                            :class="{ active: sidebarTab === 'people' }"
                            @click="sidebarTab = 'people'"
                            title="Participants"
                        >
                            <Icon name="Users" size="20" />
                        </button>
                        <button
                            :class="{ active: sidebarTab === 'chat' }"
                            @click="sidebarTab = 'chat'"
                            title="Chat"
                        >
                            <Icon name="MessageSquare" size="20" />
                        </button>
                        <button
                            class="close-btn"
                            @click="showSidebar = false"
                            title="Close Sidebar"
                        >
                            <Icon name="X" size="20" />
                        </button>
                    </div>

                    <div class="sidebar-content">
                        <!-- PEOPLE TAB -->
                        <div
                            v-if="sidebarTab === 'people'"
                            class="people-list overflow-y-auto"
                        >
                            <div
                                v-for="p in participants"
                                :key="p.publicId"
                                class="participant-item"
                            >
                                <Avatar
                                    :src="p.avatar"
                                    :alt="p.name"
                                    :size="32"
                                    class="shrink-0 mr-1"
                                />
                                <span>{{ p.isSelf ? "You" : p.name }}</span>
                                <div
                                    class="status-icons"
                                    style="
                                        margin-left: auto;
                                        display: flex;
                                        gap: 6px;
                                        align-items: center;
                                    "
                                >

                                    <Icon
                                        v-if="
                                            p.isSelf
                                                ? isMuted
                                                : !remoteHasAudio(p.publicId)
                                        "
                                        name="MicOff"
                                        size="14"
                                        class="text-red-500"
                                    />
                                    <Icon
                                        v-if="
                                            p.isSelf
                                                ? isCameraOff
                                                : !remoteHasVideo(p.publicId)
                                        "
                                        name="VideoOff"
                                        size="14"
                                        class="text-red-500"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- CHAT TAB -->
                        <div
                            v-if="sidebarTab === 'chat'"
                            class="chat-panel h-full flex flex-col"
                        >
                            <template v-if="activeChat">
                                <!-- Messages -->
                                <div
                                    class="flex-1 min-h-0 relative flex flex-col overflow-hidden"
                                >
                                    <CallChatList
                                        v-if="activeChat?.public_id"
                                        ref="chatListRef"
                                        :chat-id="activeChat.public_id"
                                        :messages="activeMessages"
                                        :is-loading-more="isChatLoadingMore"
                                        @fetch-older="loadMoreMessages"
                                        @reply="handleMessageReply"
                                        @jump="
                                            (id) =>
                                                chatListRef?.scrollToMessage(id)
                                        "
                                    />
                                    <div
                                        v-else
                                        class="h-full flex items-center justify-center text-(--text-tertiary)"
                                    >
                                        Select a chat to start messaging
                                    </div>

                                    <!-- Typing Indicator -->
                                    <Transition
                                        enter-active-class="transition duration-200 ease-out"
                                        enter-from-class="transform translate-y-2 opacity-0"
                                        enter-to-class="transform translate-y-0 opacity-100"
                                        leave-active-class="transition duration-150 ease-in"
                                        leave-from-class="transform translate-y-0 opacity-100"
                                        leave-to-class="transform translate-y-2 opacity-0"
                                    >
                                        <div
                                            v-if="typingIndicator"
                                            class="absolute bottom-2 left-4 z-20 flex items-center gap-2 bg-(--surface-elevated)/90 backdrop-blur-sm px-3 py-1.5 rounded-full border border-(--border-subtle) shadow-sm"
                                        >
                                            <div class="flex gap-1">
                                                <span
                                                    class="w-1 h-1 bg-(--interactive-primary) rounded-full animate-bounce"
                                                ></span>
                                                <span
                                                    class="w-1 h-1 bg-(--interactive-primary) rounded-full animate-bounce [animation-delay:0.2s]"
                                                ></span>
                                                <span
                                                    class="w-1 h-1 bg-(--interactive-primary) rounded-full animate-bounce [animation-delay:0.4s]"
                                                ></span>
                                            </div>
                                            <span
                                                class="text-[10px] font-medium text-(--text-secondary)"
                                                >{{ typingIndicator }}</span
                                            >
                                        </div>
                                    </Transition>
                                </div>

                                <!-- Composer -->
                                <ChatComposer
                                    v-if="activeChat?.public_id"
                                    v-model="messageInput"
                                    :chat-id="activeChat?.public_id"
                                    :reply-to="replyingTo"
                                    :pending-files="pendingFiles"
                                    :sending="isSending"
                                    :is-mobile="false"
                                    compact
                                    hide-audio
                                    class="shrink-0 z-10"
                                    @send="
                                        () => {
                                            console.log(
                                                '[CallChat] Sending message...',
                                                messageInput,
                                            );
                                            sendMessage();
                                        }
                                    "
                                    @typing="handleInputChange"
                                    @add-files="addFiles"
                                    @remove-file="removeFile"
                                    @cancel-reply="replyingTo = null"
                                    @send-gif="sendGif"
                                />
                            </template>
                            <div
                                v-else
                                class="flex flex-col items-center justify-center h-full text-zinc-500 gap-2"
                            >
                                <Icon
                                    name="Loader"
                                    size="24"
                                    class="animate-spin"
                                />
                                <span class="text-sm">Loading chat...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Close Container -->

            <CallSettingsModal
                :open="showSettings"
                @update:open="showSettings = $event"
                @close="showSettings = false"
            />
        </template>

        <!-- DEV SIM TOOL -->
        <CallDevSimulationTool v-model:show="showDevSim" />

        <MediaViewer />
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

/* Top Vignette */
.top-vignette {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 180px;
    background: linear-gradient(to bottom, rgba(0, 0, 0, 0.6) 0%, transparent 100%);
    pointer-events: none;
    z-index: 50;
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

.call-connecting-state {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    z-index: 12;
    padding: 40px 24px;
    text-align: center;
    background: radial-gradient(
        circle at 50% 35%,
        rgba(59, 130, 246, 0.16),
        rgba(11, 17, 34, 0.72) 55%,
        rgba(6, 10, 20, 0.92) 100%
    );
}

.call-connecting-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    max-width: 380px;
}

.call-loading-ring {
    width: 56px;
    height: 56px;
    border: 4px solid rgba(255, 255, 255, 0.18);
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: call-spin 0.9s linear infinite;
}

.call-connecting-title {
    margin: 0;
    color: #fff;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.02em;
}

.call-connecting-subtitle {
    margin: 0;
    color: rgba(255, 255, 255, 0.72);
    font-size: 14px;
    line-height: 1.45;
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

@keyframes call-spin {
    to {
        transform: rotate(360deg);
    }
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
.grid-4-3 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    grid-auto-rows: 1fr;
}

/* Pagination Nav */
.pagination-nav {
    position: absolute;
    bottom: 112px; /* Raised further to avoid overlap */
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(15, 15, 20, 0.6);
    backdrop-filter: blur(16px);
    padding: 8px 20px;
    border-radius: 100px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    z-index: 100;
    pointer-events: auto;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

.pagination-btn {
    background: none;
    border: none;
    color: white;
    padding: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.7;
    transition: all 0.2s;
}

.pagination-btn:hover:not(:disabled) {
    opacity: 1;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
}

.pagination-btn:disabled {
    opacity: 0.2;
    cursor: not-allowed;
}

.pagination-dots {
    display: flex;
    gap: 6px;
}

.pagination-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    border: none;
    padding: 0;
    cursor: pointer;
    transition: all 0.2s;
}

.pagination-dot.active {
    background: white;
    width: 14px;
    border-radius: 10px;
}

/* --- SPOTLIGHT LAYOUT --- */
/* --- SPOTLIGHT LAYOUT --- */
.spotlight-wrapper {
    flex: 1;
    display: flex;
    width: 100%;
    height: 100%;
    z-index: 10;
    position: relative;
    padding: 16px;
    padding-bottom: calc(
        20px + env(safe-area-inset-bottom, 20px)
    ); /* Reduced from 100px */
    gap: 16px;
}
/* ... skipped spotlight-stage ... */

/* Controls Bar */
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
    transition: all 0.1s linear;
    background: rgba(20, 20, 25, 0.8);
    backdrop-filter: blur(24px);
    padding: 14px 28px;
    cursor: grab;
    user-select: none;
    scale: 0.8;
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
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
}

.spotlight-content {
    width: 100%;
    height: 100%;
    position: relative;
}

.screen-share-video {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
    background: #000;
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
    scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
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
    transition:
        transform 0.3s ease,
        border-color 0.3s ease,
        box-shadow 0.3s ease;
}

.video-cell.is-talking {
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.3);
    z-index: 20;
}

.video-element {
    width: 100%;
    height: 100%;
    object-fit: var(--video-fit, cover);
    background: #000;
}
.mirror-off {
    transform: scaleX(1);
}

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
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
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
    border: 2px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.6);
}

/* Unified Header Toolbox */
.call-header-toolbox {
    position: absolute;
    top: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(20, 20, 25, 0.75);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 24px;
    height: 44px;
    display: flex;
    align-items: center;
    padding: 0 16px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    z-index: 100;
    pointer-events: auto;
    white-space: nowrap;
}

.toolbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: white;
}

.toolbox-divider {
    width: 1px;
    height: 20px;
    background: rgba(255, 255, 255, 0.1);
    margin: 0 16px;
}

.local-status-pill {
    display: flex;
    align-items: center;
    gap: 10px;
}

.pill-label {
    text-transform: uppercase;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: rgba(255, 255, 255, 0.5);
}

.pill-icons {
    display: flex;
    align-items: center;
    gap: 6px;
}

.timer {
    font-variant-numeric: tabular-nums;
}

/* Redesigned Bottom Bar (Meeting Style) */
.app-bottom-bar {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 80px;
    background: rgba(9, 9, 11, 0.85);
    backdrop-filter: blur(24px);
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    padding: 0 24px;
    z-index: 500;
}

.bar-section {
    flex: 1;
    display: flex;
    align-items: center;
}

.bar-section--left {
    justify-content: flex-start;
}

.bar-section--center {
    justify-content: center;
    gap: 12px;
}

.bar-section--right {
    justify-content: flex-end;
    gap: 12px;
}

.call-info-pill {
    background: rgba(255, 255, 255, 0.05);
    padding: 10px 18px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.info-time {
    font-weight: 700;
    font-size: 15px;
    letter-spacing: -0.01em;
}

.ctrl-btn {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.ctrl-btn:hover {
    background: rgba(255, 255, 255, 0.12);
    transform: translateY(-2px);
    border-color: rgba(255, 255, 255, 0.2);
}

.ctrl-btn--off {
    background: white;
    color: #09090b;
}

.ctrl-btn--off:hover {
    background: rgba(255, 255, 255, 0.9);
}

.ctrl-btn--active {
    background: #10b981;
    color: white;
    border-color: rgba(16, 185, 129, 0.4);
}

.ctrl-btn--hangup {
    background: #ef4444;
    border-color: rgba(239, 68, 68, 0.2);
}

.ctrl-btn--hangup:hover {
    background: #dc2626;
    box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .call-header-toolbox {
        top: 16px;
        padding: 0 12px;
        height: 40px;
        max-width: 90vw;
    }

    .toolbox-divider {
        margin: 0 10px;
    }

    .toolbox-item {
        font-size: 12px;
    }

    .pill-label {
        display: none; /* Hide label on very small screens to save space */
    }

    .main-stage {
        padding: 8px;
    }

    /* Prevent PIP overlap on mobile */
    .video-cell.local {
        bottom: 84px !important;
        right: 12px !important;
        width: 120px !important;
        height: 160px !important;
    }

    .video-cell {
        border-radius: 12px !important;
    }

    .participant-info.compact-pill {
        bottom: 6px !important;
        left: 6px !important;
        padding: 3px 8px !important;
        font-size: 10px !important;
        gap: 6px !important;
    }

    .toolbox-divider-vertical {
        height: 10px !important;
        margin: 0 !important;
    }

    .status-row-compact {
        gap: 4px !important;
    }
}

.participant-info.compact-pill {
    position: absolute;
    bottom: 12px;
    left: 12px;
    background: rgba(9, 9, 11, 0.75);
    backdrop-filter: blur(16px);
    padding: 5px 10px;
    border-radius: 12px;
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 10px;
    color: white;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
    pointer-events: auto;
    max-width: calc(100% - 24px);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.participant-info.compact-pill:hover {
    background: rgba(9, 9, 11, 0.9);
    transform: translateY(-2px);
}

.toolbox-divider-vertical {
    width: 1px;
    height: 14px;
    background: rgba(255, 255, 255, 0.15);
}

.status-row-compact {
    display: flex;
    align-items: center;
    gap: 8px;
}

.participant-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 120px;
}

.status-icons {
    display: flex;
    align-items: center;
}

.status-icon-red {
    color: #ef4444;
}

.status-icon-yellow {
    color: #f59e0b;
}

/* Controls Bar */
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
    transition: all 0.1s linear; /* Faster transition for dragging, or remove entirely for drag */
    background: rgba(20, 20, 25, 0.8);
    backdrop-filter: blur(24px);
    padding: 14px 28px;
    cursor: grab;
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
    bottom: calc(20px + env(safe-area-inset-bottom, 0));
}


.call-stage-container {
    display: flex;
    width: 100%;
    height: 100%;
    overflow: hidden;
    position: relative;
}

.main-stage {
    flex: 1;
    height: 100%;
    position: relative;
    transition: margin-right 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    display: flex; /* Ensure children fill */
    flex-direction: column;
}

.sidebar-open .main-stage {
    margin-right: 320px;
}

.call-sidebar {
    position: absolute;
    top: 0;
    right: 0;
    width: 320px;
    height: 100%;
    background: #18181b; /* or var(--surface-primary) */
    border-left: 1px solid var(--glass-border);
    transform: translateX(100%);
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    display: flex;
    flex-direction: column;
    z-index: 600;
}

.call-sidebar.open {
    transform: translateX(0);
}

.sidebar-header {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    gap: 8px;
}

.sidebar-header button {
    flex: 1;
    padding: 10px;
    border-radius: 8px;
    background: transparent;
    color: #a1a1aa;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    justify-content: center;
}

.sidebar-header button:hover {
    background: rgba(255, 255, 255, 0.05);
    color: white;
}

.sidebar-header button.active {
    background: rgba(255, 255, 255, 0.1);
    color: #10b981;
}

.sidebar-header button.close-btn {
    flex: 0 0 32px;
    margin-left: auto;
}

.sidebar-content {
    flex: 1;
    overflow: hidden; /* Prevent sidebar from scrolling the whole chat */
    display: flex;
    flex-direction: column;
}

.people-list {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.participant-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    border-radius: 8px;
    transition: background 0.2s;
}
.participant-item:hover {
    background: rgba(255, 255, 255, 0.05);
}

.participant-item .avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: white;
}

.participant-item span {
    font-size: 14px;
    font-weight: 500;
    color: white;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.chat-panel {
    display: flex;
    flex-direction: column;
    height: 100%;
    /* Polished: Seamless integration */
    background: transparent;
    padding: 0;
    border: none;
    box-shadow: none;
}



@media (max-width: 768px) {
    .app-bottom-bar {
        height: 72px;
        padding: 0 12px;
    }

    .bar-section--left {
        display: none; /* Hide call info on mobile footer, it's in header */
    }

    .bar-section--center {
        gap: 8px;
    }

    .ctrl-btn {
        width: 40px;
        height: 40px;
        border-radius: 10px;
    }

    .grid-wrapper {
        padding: 8px;
        gap: 6px; /* Even tighter gap */
        padding-bottom: 80px;
    }

    .participant-info.compact-pill {
        bottom: 4px !important;
        left: 4px !important;
        padding: 2px 6px !important; /* Smaller vertical padding */
        font-size: 9px !important; /* Smaller font */
        gap: 4px !important;
        border-radius: 14px !important;
    }

    .avatar-image-element {
        width: 52px !important;
        height: 52px !important;
        font-size: 20px !important;
    }

    .avatar-fallback Avatar {
        scale: 0.7;
    }
    
    .pagination-nav {
        bottom: 96px; /* Raised further for mobile footer */
        scale: 0.8;
    }

    .video-cell.local.pip-mode {
        bottom: 160px !important; /* Adjusted for footer */
    }

    /* Mobile Sidebar Overrides */
    .sidebar-open .main-stage {
        margin-right: 0; /* Do not push main stage on mobile */
    }

    .call-sidebar {
        width: 100%; /* Take full width on mobile */
        border-left: none; /* Remove border when full width */
    }
}

/* --- CALLING SCREEN PREMUM UI --- */
.calling-screen {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #09090b;
    z-index: 1000;
    overflow: hidden;
}

.calling-bg {
    position: absolute;
    inset: -20px;
    background-size: cover;
    background-position: center;
    filter: blur(60px) brightness(0.3);
    z-index: 1;
    transform: scale(1.1);
}

.calling-overlay {
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, transparent 0%, rgba(0,0,0,0.4) 100%);
    z-index: 2;
}

.glass-card {
    background: rgba(255, 255, 255, 0.03);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 32px 64px -12px rgba(0, 0, 0, 0.5);
}

.calling-card {
    position: relative;
    z-index: 10;
    width: 320px;
    padding: 48px 32px;
    border-radius: 48px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.calling-avatar-container {
    position: relative;
    width: 140px;
    height: 140px;
    margin-bottom: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.calling-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 5;
}

.calling-avatar-placeholder {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: white;
    font-size: 48px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 4px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 5;
}

.ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(59, 130, 246, 0.15);
    z-index: 1;
    animation: calling-ripple 3s infinite cubic-bezier(0.4, 0, 0.2, 1);
}

.ripple-outer {
    width: 200%;
    height: 200%;
}

.ripple-inner {
    width: 140%;
    height: 140%;
    animation-delay: 1s;
}

@keyframes calling-ripple {
    0% {
        transform: scale(0.5);
        opacity: 0;
    }
    50% {
        opacity: 0.5;
    }
    100% {
        transform: scale(1.2);
        opacity: 0;
    }
}

.calling-info {
    margin-bottom: 48px;
}

.calling-name {
    font-size: 24px;
    font-weight: 700;
    color: white;
    margin-bottom: 8px;
    letter-spacing: -0.01em;
}

.calling-status {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 500;
}

.btn-hangup-large {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: #ef4444;
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-shadow: 0 16px 32px rgba(239, 68, 68, 0.3);
}

.btn-hangup-large:hover {
    transform: scale(1.15);
    background: #dc2626;
    box-shadow: 0 20px 40px rgba(239, 68, 68, 0.4);
}

.btn-close-mini {
    padding: 10px 24px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: white;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-close-mini:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.2);
}

.ended-screen .calling-avatar-container {
    filter: grayscale(1);
}

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
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 2px;
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
