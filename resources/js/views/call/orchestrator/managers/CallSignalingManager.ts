import { startEcho, stopEcho } from "@/echo";
import { videoCallService } from "@/services/videocall.service";

type ChatType = "dm" | "group";

export interface CallSignalingData {
    callId: string;
    chatId: string;
    chatType?: ChatType;
}

export interface CallSignalingManagerOptions {
    getCallData: () => CallSignalingData | null;
    onCallSignal: (event: any) => void;
    onParticipantJoined: (event: any) => void;
    onParticipantLeft: (event: any) => void;
    onCallEnded: (event: any) => void;
}

export class CallSignalingManager {
    private echoChannel: any = null;

    constructor(private readonly options: CallSignalingManagerOptions) {}

    setupEcho(): void {
        const echo = startEcho();
        const callData = this.options.getCallData();
        if (!echo || !callData) return;

        const prefix = callData.chatType === "group" ? "group" : "dm";
        const channelName = `${prefix}.${callData.chatId}`;

        this.echoChannel = echo.private(channelName);
        this.echoChannel
            .listen(".CallSignal", this.options.onCallSignal)
            .listen(".CallParticipantJoined", this.options.onParticipantJoined)
            .listen(".CallParticipantLeft", this.options.onParticipantLeft)
            .listen(".CallEnded", this.options.onCallEnded);
    }

    sendAppSignal(type: string, payload: Record<string, unknown>): void {
        const callData = this.options.getCallData();
        if (!callData) return;

        videoCallService
            .sendSignal(callData.chatId, callData.callId, "signal", {
                type,
                ...payload,
            })
            .catch((error) => console.warn("Failed to send signal", error));
    }

    teardown(): void {
        this.echoChannel = null;
        stopEcho();
    }
}
