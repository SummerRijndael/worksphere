import Peer from "simple-peer";
import { videoCallService } from "@/services/videocall.service";

interface CallDataLike {
    chatId: string;
    callId: string;
}

interface HandleMeshSignalParams {
    senderId: string;
    selfId: string;
    signal: any;
    localStream: MediaStream | null;
}

export interface CallMeshManagerOptions {
    getPeers: () => Map<string, Peer.Instance>;
    getCallData: () => CallDataLike | null;
    getIceServers: () => RTCIceServer[];
    shouldReserveVideoMLine?: () => boolean;
    mungeSdp: (sdp: string) => string;
    onMainStream: (participantId: string, stream: MediaStream) => void;
    onScreenStream: (participantId: string, stream: MediaStream) => void;
    onPeerClosed: (participantId: string) => void;
}

export class CallMeshManager {
    constructor(private readonly options: CallMeshManagerOptions) {}
    private pendingSignalsBySender = new Map<string, any[]>();

    private isLikelyScreenShareTrack(track: MediaStreamTrack | null): boolean {
        if (!track) return false;
        const label = (track.label || "").toLowerCase();
        // Exclude common camera labels to be safe
        if (label.includes("primary") || label.includes("camera") || label.includes("facetime")) return false;
        return /(screen|display|window|monitor|tab|share)/.test(label);
    }

    private isInvalidIceSignal(signal: any): boolean {
        if (!signal || typeof signal !== "object") return false;
        if (!("candidate" in signal)) return false;

        const candidate = signal.candidate;
        if (!candidate || typeof candidate !== "object") {
            return true;
        }

        const candidateLine =
            typeof candidate.candidate === "string"
                ? candidate.candidate.trim()
                : "";
        const hasMid =
            candidate.sdpMid !== undefined && candidate.sdpMid !== null;
        const hasMLineIndex =
            candidate.sdpMLineIndex !== undefined &&
            candidate.sdpMLineIndex !== null;

        // Keep end-of-candidates markers if they include line metadata.
        return candidateLine.length === 0 && !hasMid && !hasMLineIndex;
    }

    private isCandidateSignal(signal: any): boolean {
        const type = String(signal?.type || "").toLowerCase();
        return type === "candidate" || type === "ice-candidate";
    }

    private queuePendingSignal(senderId: string, signal: any): void {
        const queue = this.pendingSignalsBySender.get(senderId) || [];
        queue.push(signal);
        // Keep a small rolling queue to prevent unbounded growth on noisy links.
        if (queue.length > 40) {
            queue.shift();
        }
        this.pendingSignalsBySender.set(senderId, queue);
    }

    private flushPendingSignals(senderId: string, peer: Peer.Instance | null): void {
        if (!peer) return;
        const queue = this.pendingSignalsBySender.get(senderId);
        if (!queue || queue.length === 0) return;

        this.pendingSignalsBySender.delete(senderId);
        for (const signal of queue) {
            try {
                peer.signal(signal);
            } catch (error) {
                console.warn(
                    `[Call] Failed replaying buffered signal from ${senderId}`,
                    error,
                );
            }
        }
    }

    private hasTransceiverForKind(
        pc: RTCPeerConnection,
        kind: "audio" | "video",
    ): boolean {
        return pc
            .getTransceivers()
            .some((transceiver) => transceiver.receiver.track.kind === kind);
    }

    private ensureStableMediaTransceivers(
        peer: Peer.Instance,
        pc: RTCPeerConnection,
        stream: MediaStream | null,
    ): void {
        try {
            const reserveVideoMLine =
                this.options.shouldReserveVideoMLine?.() ?? false;
            
            // 1. Ensure Audio Transceiver (Slot 0)
            if (!this.hasTransceiverForKind(pc, "audio")) {
                try {
                    peer.addTransceiver("audio", { direction: "sendrecv" });
                } catch {
                    pc.addTransceiver("audio", { direction: "sendrecv" });
                }
            }

            // 2. Ensure Video Transceivers (Slot 1: Camera, Slot 2: Screen)
            if (reserveVideoMLine) {
                const videoTransceiverCount = pc
                    .getTransceivers()
                    .filter((t) => t.receiver.track.kind === "video").length;

                // We want exactly 2 video slots for maximum stability (Camera + Screen)
                for (let i = videoTransceiverCount; i < 2; i++) {
                    try {
                        (peer as any).addTransceiver("video", {
                            direction: "sendrecv",
                        });
                    } catch {
                        pc.addTransceiver("video", {
                            direction: "sendrecv",
                        });
                    }
                }
            }
        } catch (error) {
            console.warn("[Call] Failed to reserve mesh transceivers", error);
        }
    }

    private getVideoTransceiverIndex(
        pc: RTCPeerConnection,
        track: MediaStreamTrack,
        receiver?: RTCRtpReceiver,
    ): number {
        const videoTransceivers = pc
            .getTransceivers()
            .filter((t) => t.receiver.track.kind === "video");
        
        // Priority 1: Match receiver object (most accurate for ontrack)
        if (receiver) {
            const idx = videoTransceivers.findIndex((t) => t.receiver === receiver);
            if (idx !== -1) return idx;
        }

        // Priority 2: Match track reference
        return videoTransceivers.findIndex(
            (t) => t.receiver.track === track || t.sender.track === track,
        );
    }

    private getRemotePersistentStream(
        participantId: string,
        isScreen: boolean,
    ): MediaStream {
        const pid = participantId.toLowerCase();
        if (!this.remoteStreams.has(pid)) {
            this.remoteStreams.set(pid, {
                main: new MediaStream(),
                screen: new MediaStream(),
            });
        }
        const set = this.remoteStreams.get(pid)!;
        return isScreen ? set.screen : set.main;
    }

    private findSenderForKind(
        pc: RTCPeerConnection,
        kind: "audio" | "video",
    ): RTCRtpSender | undefined {
        const byTrack = pc.getSenders().find((sender) => sender.track?.kind === kind);
        if (byTrack) return byTrack;

        const byTransceiver = pc
            .getTransceivers()
            .find((transceiver) => transceiver.receiver.track.kind === kind);
        return byTransceiver?.sender;
    }

    private syncLocalTracksToPeer(peer: Peer.Instance, stream: MediaStream): void {
        // @ts-ignore
        const pc = peer._pc as RTCPeerConnection | undefined;
        if (!pc) return;

        const videoTransceivers = pc
            .getTransceivers()
            .filter((t) => t.receiver.track.kind === "video");

        stream.getTracks().forEach((track) => {
            if (track.kind === "audio") {
                const sender = this.findSenderForKind(pc, "audio");
                if (sender) {
                    if (sender.track?.id === track.id) return;
                    sender.replaceTrack(track).catch(() => {});
                } else {
                    try {
                        peer.addTrack(track, stream);
                    } catch {}
                }
                return;
            }

            if (track.kind === "video") {
                const isScreen = this.isLikelyScreenShareTrack(track);
                // Set content hint for better quality/stability
                if ("contentHint" in track) {
                    (track as any).contentHint = isScreen ? "detail" : "motion";
                }
                const slotIndex = isScreen ? 1 : 0;
                const transceiver = videoTransceivers[slotIndex];

                if (transceiver?.sender) {
                    const sender = transceiver.sender;
                    if (sender.track?.id === track.id) {
                        console.log(`[Call][MESH] Track ${track.id} already assigned to slot ${slotIndex}`);
                        return;
                    }

                    sender
                        .replaceTrack(track)
                        .then(() => {
                            console.log(
                                `[Call][MESH] Successfully swapped track to live in slot ${slotIndex}: ${track.id} (${track.label})`,
                            );
                            // Verify stream validity
                            if (track.readyState !== "live") {
                                console.warn(`[Call][MESH] WARNING: Swapped track ${track.id} is in state: ${track.readyState}`);
                            }
                        })
                        .catch((err) => {
                            console.error(
                                `[Call][MESH] Failed to swap track in slot ${slotIndex}:`,
                                err,
                            );
                        });
                } else {
                    console.warn(`[Call][MESH] No video transceiver found for slot ${slotIndex} on ${peer.id}`);
                    // Fallback to generic addTrack if slots aren't ready
                    try {
                        peer.addTrack(track, stream);
                    } catch {}
                }
            }
        });
    }

    createPeer(
        targetPublicId: string,
        initiator: boolean,
        stream: MediaStream | null,
    ): Peer.Instance | null {
        const peers = this.options.getPeers();
        const normalizedTargetId = targetPublicId.toLowerCase();
        if (peers.has(normalizedTargetId)) {
            const existingPeer = peers.get(normalizedTargetId) || null;
            if (existingPeer && stream) {
                this.syncLocalTracksToPeer(existingPeer, stream);
            }
            return existingPeer;
        }

        console.log(
            `[Call] Creating peer for ${normalizedTargetId} (initiator: ${initiator})`,
        );

        const hasLiveOutboundTracks = !!stream
            ? stream.getTracks().some((track) => track.readyState === "live")
            : false;
        const peerOptions: Peer.Options = {
            initiator,
            trickle: true,
            sdpTransform: (sdp) => this.options.mungeSdp(sdp),
            config: {
                iceServers:
                    this.options.getIceServers().length > 0
                        ? this.options.getIceServers()
                        : undefined,
            },
        };
        // We do NOT pass stream to constructor. 
        // We use the Stable Transceiver Strategy: slots first, tracks second.
        const peer = new Peer(peerOptions);

        // @ts-ignore
        const pc = peer._pc as RTCPeerConnection;
        if (pc) {
            this.ensureStableMediaTransceivers(peer, pc, stream);
            pc.oniceconnectionstatechange = () => {
                console.log(
                    `[Call] ICE State for ${normalizedTargetId}: ${pc.iceConnectionState}`,
                );
            };
            pc.onconnectionstatechange = () => {
                console.log(
                    `[Call] Connection State for ${normalizedTargetId}: ${pc.connectionState}`,
                );
                if (pc.connectionState === "failed") {
                    console.warn(`[Call] Mesh connection FAILED for ${normalizedTargetId}. This usually happens when STUN/TURN fails or signaling is lost.`);
                }
            };
            if (initiator) {
                setTimeout(() => {
                    // Safety net: if initial offer did not start for any reason,
                    // force a negotiation kick so camera/screen toggles are not blocked.
                    if ((peer as any).destroyed) return;
                    const activePc = (peer as any)?._pc as
                        | RTCPeerConnection
                        | undefined;
                    if (!activePc) return;
                    if (
                        activePc.signalingState === "stable" &&
                        !activePc.localDescription
                    ) {
                        console.warn(
                            `[Call] Mesh offer watchdog: forcing negotiation for ${normalizedTargetId}`,
                        );
                        try {
                            (peer as any).negotiate?.();
                        } catch (error) {
                            console.warn(
                                `[Call] Mesh offer watchdog failed for ${normalizedTargetId}`,
                                error,
                            );
                        }
                    }
                }, 1500);
            }
        }

        this.setupPeerEvents(peer, normalizedTargetId);

        // Populate the slots immediately if we have tracks
        if (stream) {
            this.syncLocalTracksToPeer(peer, stream);
        }

        peers.set(normalizedTargetId, peer);
        return peer;
    }

    private peerTrackSubscriptions: Map<string, () => void> = new Map();
    private remoteStreams: Map<string, { main: MediaStream; screen: MediaStream }> =
        new Map();

    private setupPeerEvents(
        peer: Peer.Instance,
        normalizedTargetId: string,
    ): void {
        const pc = (peer as any)._pc as RTCPeerConnection;
        if (!pc) {
            console.warn(`[Call] No RTCPeerConnection found for ${normalizedTargetId}`);
            return;
        }
        const peers = this.options.getPeers();

        peer.on("signal", (signal) => {
            const callData = this.options.getCallData();
            if (!callData) return;
            if (this.isInvalidIceSignal(signal)) {
                console.warn(
                    `[Call] Dropping invalid outgoing ICE signal for ${normalizedTargetId}`,
                );
                return;
            }

            videoCallService
                .sendSignal(
                    callData.chatId,
                    callData.callId,
                    "signal",
                    signal as any,
                    normalizedTargetId,
                )
                .catch((error) =>
                    console.warn(
                        `[Call] Failed to send mesh signal to ${normalizedTargetId}`,
                        error,
                    ),
                );
        });

        const onTrack = (event: RTCTrackEvent) => {
            const track = event.track;
            this.handleRemoteTrack(normalizedTargetId, track, pc, event.receiver);
        };

        pc.addEventListener("track", onTrack);

        // simple-peer wrappers as secondary safety
        peer.on("track", (track: MediaStreamTrack) => {
            this.handleRemoteTrack(normalizedTargetId, track, pc);
        });

        peer.on("stream", (remoteStream) => {
            console.log(`[Call][MESH] Stream event from ${normalizedTargetId} active=${remoteStream.active} tracks=${remoteStream.getTracks().length}`);
            remoteStream.getTracks().forEach(track => {
                this.handleRemoteTrack(normalizedTargetId, track, pc);
            });
        });

        peer.on("error", (error) => {
            console.error(`[Call] Peer error ${normalizedTargetId}:`, error);
        });

        peer.on("close", () => {
            console.log(`[Call] Peer closed ${normalizedTargetId}`);
            peers.delete(normalizedTargetId);
            this.options.onPeerClosed(normalizedTargetId);
        });
    }

    private handleRemoteTrack(
        participantId: string,
        track: MediaStreamTrack,
        pc: RTCPeerConnection,
        receiver?: RTCRtpReceiver,
    ): void {
        const slotIndex = this.getVideoTransceiverIndex(pc, track, receiver);
        const pid = participantId.toLowerCase();

        // Audio always goes to main stream
        const isScreen = slotIndex === 1;
        const stream = this.getRemotePersistentStream(pid, isScreen);

        if (!stream.getTracks().some((existing) => existing.id === track.id)) {
            console.log(
                `[Call][MESH] Processing track from ${pid}: ${track.kind} (slot: ${slotIndex}) id=${track.id}`,
            );
            
            // Clean up existing tracks of same kind to avoid buildup
            if (track.kind === "video") {
                stream.getVideoTracks().forEach((t) => stream.removeTrack(t));
            } else if (track.kind === "audio") {
                stream.getAudioTracks().forEach((t) => stream.removeTrack(t));
            }
            stream.addTrack(track);

            // Nudge UI since MediaStream.addTrack is not reactive
            stream.dispatchEvent(new CustomEvent("tracks-updated"));

            // Track Lifecycle Monitoring
            track.onended = () => {
                console.log(`[Call][MESH] Remote track ENDED from ${pid}: ${track.kind} id=${track.id}`);
            };
            track.onmute = () => {
                console.log(`[Call][MESH] Remote track MUTED from ${pid}: ${track.kind} id=${track.id}`);
            };
            track.onunmute = () => {
                console.log(`[Call][MESH] Remote track UNMUTED from ${pid}: ${track.kind} id=${track.id}`);
            };

            // Notify UI immediately
            if (isScreen) {
                this.options.onScreenStream(pid, stream);
            } else {
                this.options.onMainStream(pid, stream);
            }
        }
    }

    destroyPeer(participantPublicId: string): void {
        const peers = this.options.getPeers();
        const normalizedTargetId = participantPublicId.toLowerCase();
        const peer = peers.get(normalizedTargetId);
        if (!peer) return;

        peer.destroy();
        peers.delete(normalizedTargetId);
        this.pendingSignalsBySender.delete(normalizedTargetId);
    }

    async handleSignal(params: HandleMeshSignalParams): Promise<void> {
        const peers = this.options.getPeers();
        const normalizedSenderId = params.senderId.toLowerCase();
        const shouldInitiate = params.selfId > normalizedSenderId;
        if (this.isInvalidIceSignal(params.signal)) {
            console.warn(
                `[Call] Dropping invalid incoming ICE signal from ${normalizedSenderId}`,
            );
            return;
        }
        let peer: Peer.Instance | null | undefined =
            peers.get(normalizedSenderId);
        let createdFromOffer = false;

        if (!peer) {
            if (params.signal.type === "offer") {
                console.log(
                    `[Call] Received offer from ${normalizedSenderId}, creating responder peer`,
                );
                peer = this.createPeer(
                    normalizedSenderId,
                    false,
                    params.localStream,
                );
                createdFromOffer = true;
            } else if (this.isCandidateSignal(params.signal)) {
                if (shouldInitiate) {
                    // We are the designated initiator. Unknown candidates here are usually stale
                    // from a closed peer/old negotiation and should not be buffered.
                    this.pendingSignalsBySender.delete(normalizedSenderId);
                    console.warn(
                        `[Call] Dropping stale candidate from unknown peer ${normalizedSenderId} (initiator side)`,
                    );
                    return;
                }
                this.queuePendingSignal(normalizedSenderId, params.signal);
                return;
            } else if (params.signal.type === "answer") {
                if (shouldInitiate) {
                    this.pendingSignalsBySender.delete(normalizedSenderId);
                    console.warn(
                        `[Call] Dropping stale answer from unknown peer ${normalizedSenderId} (peer likely restarted)`,
                    );
                    return;
                }
                console.warn(
                    `[Call] Received answer from unknown peer ${normalizedSenderId}`,
                );
                return;
            } else {
                console.warn(
                    `[Call] Received ${params.signal.type} from unknown peer ${normalizedSenderId}`,
                );
                return;
            }
        }

        // @ts-ignore
        const pc = peer?._pc as RTCPeerConnection;
        if (params.signal.type === "offer" && pc && pc.signalingState !== "stable") {
            const isPolite = params.selfId < normalizedSenderId;
            if (!isPolite) {
                console.log(
                    `[Call] Glare detected with ${normalizedSenderId}. We are impolite, ignoring their offer.`,
                );
                return;
            }
            console.log(
                `[Call] Glare detected with ${normalizedSenderId}. We are polite, rollback and accept their offer.`,
            );
            await pc.setLocalDescription({ type: "rollback" } as any).catch((error) => {
                console.warn("[Call] Rollback failed", error);
            });
        }

        try {
            peer?.signal(params.signal);
            if (createdFromOffer && peer) {
                this.flushPendingSignals(normalizedSenderId, peer);
            }
        } catch (error) {
            console.error(
                `[Call] Error signaling peer ${normalizedSenderId}:`,
                error,
            );
        }
    }
}
