import { videoCallService } from "@/services/videocall.service";

type CallMode = "mesh" | "sfu";

interface CallDataLike {
    chatId: string;
    callId: string;
}

interface LocalSfuMids {
    audioMid?: string;
    videoMid?: string;
}

export interface CallSfuSignalManagerOptions {
    getCallMode: () => CallMode;
    getCallData: () => CallDataLike | null;
    getPeerConnection: () => RTCPeerConnection | null;
    getSessionId: () => string | null;
    isScreenSharing: () => boolean;
    getScreenMid: () => string | null;
    mediaInfoThrottleMs?: number;
    midRetryDelayMs?: number;
    maxMidRetries?: number;
}

export class CallSfuSignalManager {
    private readonly mediaInfoRequestAt = new Map<string, number>();

    constructor(private readonly options: CallSfuSignalManagerOptions) {}

    sendTargetedSignal(
        type: string,
        payload: Record<string, unknown>,
        targetPublicId: string,
    ): void {
        const callData = this.options.getCallData();
        if (!callData) return;

        videoCallService
            .sendSignal(
                callData.chatId,
                callData.callId,
                "signal",
                { type, ...(payload || {}) },
                targetPublicId,
            )
            .catch((error) =>
                console.warn(
                    `[Call] Failed to send targeted signal "${type}" to ${targetPublicId}`,
                    error,
                ),
            );
    }

    rebroadcastSfuMediaToJoiner(joinerPublicId: string, retryCount = 0): void {
        const sfuPc = this.options.getPeerConnection();
        const sfuSessionId = this.options.getSessionId();

        if (
            this.options.getCallMode() !== "sfu" ||
            !sfuSessionId ||
            !this.options.getCallData() ||
            !sfuPc
        ) {
            return;
        }

        const { audioMid, videoMid } = this.getLocalSfuMids();
        const maxRetries = this.options.maxMidRetries ?? 3;
        const retryDelayMs = this.options.midRetryDelayMs ?? 2000;
        const hasAudioMid = audioMid !== undefined && audioMid !== null && audioMid !== "";
        const hasVideoMid = videoMid !== undefined && videoMid !== null && videoMid !== "";

        if (!hasAudioMid && !hasVideoMid && retryCount < maxRetries) {
            console.log(
                `[SFU] No local MIDs ready for ${joinerPublicId} yet (attempt ${retryCount + 1}/${maxRetries}), retrying...`,
            );
            setTimeout(
                () => this.rebroadcastSfuMediaToJoiner(joinerPublicId, retryCount + 1),
                retryDelayMs,
            );
            return;
        }

        console.log(
            `[SFU] Sending our media info to ${joinerPublicId}: audio=${audioMid}, video=${videoMid}`,
        );
        this.sendTargetedSignal(
            "sfu-media-ready",
            {
                sessionId: sfuSessionId,
                audioMid,
                videoMid,
            },
            joinerPublicId,
        );

        if (this.options.isScreenSharing()) {
            const screenMid = this.options.getScreenMid();
            if (screenMid) {
                this.sendTargetedSignal(
                    "sfu-screen-share-started",
                    {
                        mid: screenMid,
                        sessionId: sfuSessionId,
                    },
                    joinerPublicId,
                );
            }
        }
    }

    requestRemoteMediaInfo(participantPublicId: string, force = false): void {
        const now = Date.now();
        const throttleMs = this.options.mediaInfoThrottleMs ?? 10000;
        const lastAt = this.mediaInfoRequestAt.get(participantPublicId) || 0;
        if (!force && now - lastAt < throttleMs) return;

        this.mediaInfoRequestAt.set(participantPublicId, now);
        this.sendTargetedSignal("request-media-info", {}, participantPublicId);
    }

    clearRequestTimestamp(participantPublicId: string): void {
        this.mediaInfoRequestAt.delete(participantPublicId);
    }

    clearRequestTimestamps(): void {
        this.mediaInfoRequestAt.clear();
    }

    private getLocalSfuMids(): LocalSfuMids {
        const sfuPc = this.options.getPeerConnection();
        if (!sfuPc) {
            return {
                audioMid: undefined,
                videoMid: undefined,
            };
        }

        const trackObjects = sfuPc
            .getTransceivers()
            .filter(
                (transceiver) =>
                    transceiver.mid !== null &&
                    !!transceiver.sender.track &&
                    (transceiver.sender.track.kind === "audio" ||
                        transceiver.sender.track.kind === "video"),
            )
            .map((transceiver) => ({
                mid: transceiver.mid!,
                trackName: transceiver.sender.track!.kind,
            }));

        return {
            audioMid: trackObjects.find((track) => track.trackName === "audio")
                ?.mid,
            videoMid: trackObjects.find((track) => track.trackName === "video")
                ?.mid,
        };
    }
}
