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
    localStream: MediaStream;
}

export interface CallMeshManagerOptions {
    getPeers: () => Map<string, Peer.Instance>;
    getCallData: () => CallDataLike | null;
    getIceServers: () => RTCIceServer[];
    mungeSdp: (sdp: string) => string;
    onMainStream: (participantId: string, stream: MediaStream) => void;
    onScreenStream: (participantId: string, stream: MediaStream) => void;
    onPeerClosed: (participantId: string) => void;
}

export class CallMeshManager {
    constructor(private readonly options: CallMeshManagerOptions) {}

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

    createPeer(
        targetPublicId: string,
        initiator: boolean,
        stream: MediaStream,
    ): Peer.Instance | null {
        const peers = this.options.getPeers();
        const normalizedTargetId = targetPublicId.toLowerCase();
        if (peers.has(normalizedTargetId)) {
            return peers.get(normalizedTargetId) || null;
        }

        console.log(
            `[Call] Creating peer for ${normalizedTargetId} (initiator: ${initiator})`,
        );

        const peer = new Peer({
            initiator,
            stream,
            trickle: true,
            sdpTransform: (sdp) => this.options.mungeSdp(sdp),
            config: {
                iceServers:
                    this.options.getIceServers().length > 0
                        ? this.options.getIceServers()
                        : undefined,
            },
        });

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
            const callData = this.options.getCallData();
            if (!callData) return;
            if (this.isInvalidIceSignal(signal)) {
                console.warn(
                    `[Call] Dropping invalid outgoing ICE signal for ${normalizedTargetId}`,
                );
                return;
            }
            videoCallService.sendSignal(
                callData.chatId,
                callData.callId,
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

            const hasVideo = remoteStream.getVideoTracks().length > 0;
            const hasAudio = remoteStream.getAudioTracks().length > 0;

            if (hasVideo && !hasAudio) {
                console.log(
                    `[Call] Mesh: Detected SCREEN stream from ${normalizedTargetId}`,
                );
                this.options.onScreenStream(normalizedTargetId, remoteStream);
                return;
            }

            console.log(
                `[Call] Mesh: Detected MAIN stream from ${normalizedTargetId}`,
            );
            this.options.onMainStream(normalizedTargetId, remoteStream);
        });

        peer.on("error", (error) => {
            console.error(`[Call] Peer error ${normalizedTargetId}:`, error);
        });

        peer.on("close", () => {
            console.log(`[Call] Peer closed ${normalizedTargetId}`);
            peers.delete(normalizedTargetId);
            this.options.onPeerClosed(normalizedTargetId);
        });

        peers.set(normalizedTargetId, peer);
        return peer;
    }

    destroyPeer(participantPublicId: string): void {
        const peers = this.options.getPeers();
        const normalizedTargetId = participantPublicId.toLowerCase();
        const peer = peers.get(normalizedTargetId);
        if (!peer) return;

        peer.destroy();
        peers.delete(normalizedTargetId);
    }

    async handleSignal(params: HandleMeshSignalParams): Promise<void> {
        const peers = this.options.getPeers();
        const normalizedSenderId = params.senderId.toLowerCase();
        if (this.isInvalidIceSignal(params.signal)) {
            console.warn(
                `[Call] Dropping invalid incoming ICE signal from ${normalizedSenderId}`,
            );
            return;
        }
        let peer = peers.get(normalizedSenderId);

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
            } else if (params.signal.type === "ice-candidate") {
                console.warn(
                    `[Call] Received candidate from unknown peer ${normalizedSenderId}, ignoring`,
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
        } catch (error) {
            console.error(
                `[Call] Error signaling peer ${normalizedSenderId}:`,
                error,
            );
        }
    }
}
